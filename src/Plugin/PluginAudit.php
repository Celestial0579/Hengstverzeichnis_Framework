<?php
// src/Plugin/PluginAudit.php

namespace App\Plugin;

use App\Service\AuditLogger;

/**
 * Class PluginAudit
 *
 * Der schmale Weg für Addons, eine schreibende Aktion zu protokollieren (#352).
 *
 * WOZU EINE EIGENE KLASSE, WENN ES AuditLogger::log() SCHON GIBT.
 *
 * Weil `log()` drei Freiheiten lässt, die ein Addon nicht haben sollte, und
 * das Ergebnis nachweislich schlecht war: Von acht Addons, die schreiben,
 * protokollierten drei (Addons#134) - und das Löschen eines
 * Gesundheitsdokuments, also der heikelste Bestand im Verzeichnis, lief
 * spurlos. Wer nicht weiß, dass es die Pflicht gibt, und keinen offensichtlich
 * richtigen Aufruf vor sich hat, lässt es weg.
 *
 * Diese Klasse nimmt genau die Entscheidungen ab, die niemand je anders
 * treffen sollte:
 *
 * 1. **Die Kategorie ist der Slug.** Nicht "general", nicht "plugins", nicht
 *    ein selbst ausgedachter Name. Der Filter auf /admin/audit-log speist sich
 *    aus `SELECT DISTINCT category`; sobald jedes Addon unter seinem Slug
 *    schreibt, ist "zeig mir alles, was die Gesundheitstests getan haben" eine
 *    Auswahl im Aufklappmenü statt einer Volltextsuche.
 *
 * 2. **Der Slug wird geprüft.** Ein Addon kann nicht unter dem Namen eines
 *    anderen protokollieren. `AuditLogger::log()` nimmt jede Zeichenkette als
 *    Kategorie - ein Protokoll, in dem ein Eintrag über seinen Urheber lügen
 *    kann, ist als Nachweis wertlos. Ist der Slug nicht der eines geladenen
 *    Addons, landet der Eintrag unter `plugin:unbekannt` samt dem behaupteten
 *    Namen in den Details. Verworfen wird er NICHT: Ein unterdrückter
 *    Protokolleintrag ist schlimmer als ein falsch einsortierter.
 *
 * 3. **Der Bezug gehört in die Aktion.** "Dokument gelöscht" sagt hinterher
 *    niemandem etwas. Deshalb nimmt diese Methode den Bezug (Pferd, Kontakt,
 *    Datensatz) als eigenes Argument entgegen und hängt ihn an - man muss
 *    nicht daran denken, man wird danach gefragt.
 *
 * WAS NICHT HINEINGEHÖRT: Personenbezogene Inhalte. Das Protokoll wird
 * dauerhaft gespeichert und von keiner Löschfrist erfasst (siehe
 * `audit_logs`); eine E-Mail-Adresse, die dort landet, überlebt jede
 * DSGVO-Löschung des zugehörigen Kontakts. Namen von Datensätzen sind in
 * Ordnung, Inhalte von Kontaktfeldern nicht.
 */
final class PluginAudit {

    private function __construct() {}

    /**
     * Protokolliert eine schreibende Aktion eines Addons.
     *
     *     PluginAudit::log('gesundheitstests', 'Dokument gelöscht', 'Pferd #42', 'Röntgenbefund 2024');
     *
     * @param string      $slug    Slug des Addons - muss der eigene sein.
     * @param string      $aktion  Was getan wurde, aus Sicht eines Menschen:
     *                             "Anzeige veröffentlicht", "Dokument gelöscht".
     *                             Kein Verb im Imperativ, keine Methodennamen.
     * @param string|null $bezug   Worauf es sich bezieht: "Pferd #42",
     *                             "Kontakt #7". Ohne Bezug ist ein Eintrag
     *                             später nicht mehr zuzuordnen.
     * @param string|null $details Weitere Angaben, sofern sie ohne
     *                             personenbezogene Inhalte auskommen.
     */
    public static function log(string $slug, string $aktion, ?string $bezug = null, ?string $details = null): void {
        $kategorie = self::kategorieFuer($slug);

        $zusatz = [];
        if ($bezug !== null && trim($bezug) !== '') {
            $zusatz[] = trim($bezug);
        }
        if ($details !== null && trim($details) !== '') {
            $zusatz[] = trim($details);
        }
        if ($kategorie !== $slug) {
            // Der behauptete Name geht nicht verloren - er ist ja gerade das
            // Interessante an so einem Eintrag.
            $zusatz[] = 'behaupteter Slug: ' . $slug;
        }

        AuditLogger::log($aktion, $kategorie, $zusatz === [] ? null : implode(' - ', $zusatz));
    }

    /**
     * Die Kategorie für einen Slug: der Slug selbst, wenn er zu einem
     * tatsächlich entdeckten Addon gehört, sonst `plugin:unbekannt`.
     *
     * Geprüft wird gegen die ENTDECKTEN Addons, nicht gegen die aktivierten:
     * Ein `uninstall()` (#338) läuft, während das Addon gerade deaktiviert
     * wird, und genau dessen Aufräumarbeiten sind protokollierenswert.
     */
    private static function kategorieFuer(string $slug): string {
        try {
            $bekannt = PluginManager::getInstance()->getDiscoveredPlugins();
        } catch (\Throwable $e) {
            // Kein gebooteter PluginManager (CLI, Test) - dann ist der Slug
            // nicht prüfbar, und ein Eintrag ist trotzdem besser als keiner.
            return $slug;
        }

        // getDiscoveredPlugins() ist nach Slug indiziert - siehe
        // PluginManager::setEnabled(), das genauso prüft.
        return isset($bekannt[$slug]) ? $slug : 'plugin:unbekannt';
    }
}
