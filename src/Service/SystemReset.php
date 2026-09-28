<?php
// src/Service/SystemReset.php

namespace App\Service;

use App\Plugin\PluginDataRegistry;

/**
 * Welche Tabellen ein Werksreset leert - gemeinsam für beide Reset-Wege,
 * AdminController::resetSystem() (Oberfläche) und database/reset.php (CLI).
 *
 * Die Liste stand früher an beiden Stellen getrennt und lief auseinander:
 * #118 ergänzte user_groups nur im Controller, das CLI-Skript ließ die
 * Zuordnungen stehen (#451). Weil TRUNCATE users den AUTO_INCREMENT
 * zurückstellte, erbten danach neu angelegte Konten die Gruppen der alten
 * Benutzer mit derselben id - bis hin zu Administratorrechten.
 *
 * WARUM DELETE STATT TRUNCATE (Audit N79). TRUNCATE setzt den
 * AUTO_INCREMENT zurück, jede Kennung wurde nach dem Reset erneut vergeben.
 * Alles, was irgendwo eine alte Kennung festhielt - eine offene Sitzung,
 * eine Addon-Tabelle, ein Audit-Eintrag -, zeigte danach auf einen neuen,
 * fremden Datensatz. DELETE lässt den Zähler stehen (MariaDB ≥ 10.2.4
 * speichert ihn dauerhaft), neue Benutzer, Pferde und Kontakte bekommen
 * Nummern oberhalb der alten. Sitzungen schützt zusätzlich die neue
 * Installationsepoche (App\Service\InstallEpoch), die truncateAll() am Ende
 * würfelt.
 *
 * Geleert werden:
 * - die Kerntabellen aus TABLES,
 * - ALLE Addon-Tabellen (Präfix PluginDataRegistry::PRAEFIX) - auch die
 *   deinstallierter Addons mit "Daten behalten" und die von Addons ohne
 *   Datenregister. Verkaufsanzeigen, Deckanfragen mit Adressen Dritter usw.
 *   hängten sich sonst an neue Pferde und Konten mit denselben Kennungen.
 * - der #336-Altbestand (LEGACY_TABLES), sofern vorhanden.
 *
 * Nicht geleert werden bewusst audit_logs (bleibt über Resets erhalten),
 * groups und group_permissions (die Gruppen-IDs bleiben stabil), plugins
 * (installierte Addons samt Aktivierung) und addon_repos (Addon-Quellen).
 * Hochgeladene Dateien bleiben auf dem Server.
 */
final class SystemReset {

    /**
     * Kerntabellen. Reihenfolge ist egal, gelöscht wird unter
     * FOREIGN_KEY_CHECKS = 0.
     *
     * Die Abhängigkeitsregel gilt unverändert auch für DELETE: Ohne
     * Fremdschlüsselprüfung feuert auch DELETE kein ON DELETE CASCADE.
     *
     * - contact_id_map MUSS mit contacts zusammen geleert werden (#336): Sie
     *   bildet alte Personen-/Stationskennungen auf Kontakte ab. Bliebe sie
     *   stehen, landeten die alten Adressen /person?id= und /station?id= nach
     *   dem Reset bei einem fremden Kontakt.
     * - Alles, was per user_id an users hängt, MUSS mit users zusammen geleert
     *   werden (#118, #451): verwaiste Zeilen gehörten sonst einem späteren
     *   Konto mit derselben id - Gruppen (user_groups), API-Schlüssel
     *   (api_keys) und Passkeys (user_passkeys).
     * - Dasselbe gilt für horse_media an horses und für match_labels, das
     *   Pferde- und Kontaktkennungen ohne Fremdschlüssel paarweise speichert.
     * - login_attempts enthält Anmeldekennungen der alten Konten (Audit N79);
     *   bestehende Sperren entfallen damit.
     *
     * SystemResetTest leitet aus database/schema.sql ab, dass jede Tabelle
     * mit einem Fremdschlüssel auf eine geleerte Tabelle selbst hier steht.
     */
    public const TABLES = [
        'horse_persons',
        'horse_registrations',
        'horse_media',
        'match_labels',
        'password_resets',
        'gdpr_requests',
        'horses',
        'contact_id_map',
        'contacts',
        'user_groups',
        'api_keys',
        'user_passkeys',
        'email_2fa_codes',
        'login_attempts',
        'users',
        'settings',
    ];

    /**
     * Stillgelegter Altbestand der Kontaktlisten-Umstellung (#336). Nach
     * einem Reset ist der Rückweg wertlos - database/rollback-336.php bricht
     * ohnehin ab, weil settings.migration_336_rechte_vorher fehlt -, die
     * Tabellen enthielten aber weiter Namen und Adressen.
     */
    public const LEGACY_TABLES = ['persons_pre_contacts', 'breeding_stations_pre_contacts'];

    /**
     * Was ein Reset in DIESER Datenbank leeren würde.
     *
     * Quelle ist information_schema, gefiltert wird in PHP per
     * str_starts_with() - genau wie PluginDataRegistry das Präfix prüft.
     * Kein LIKE 'plugin\_%': Unter sql_mode NO_BACKSLASH_ESCAPES bedeutet der
     * Backslash dort etwas anderes. Die Tabelle `plugins` passt nicht, weil
     * das Präfix den Unterstrich verlangt.
     *
     * Jeder Name muss zusätzlich /^[a-z0-9_]+\z/ genügen, denn er wird in
     * ein DELETE interpoliert. Andere landen in `uebersprungen`.
     *
     * @return array{kern: list<string>, addons: list<string>, altbestand: list<string>, uebersprungen: list<string>}
     */
    public static function tabellen(\PDO $db): array {
        $vorhanden = $db->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $vorhanden = array_map('strval', $vorhanden);
        sort($vorhanden);

        $ergebnis = ['kern' => self::TABLES, 'addons' => [], 'altbestand' => [], 'uebersprungen' => []];
        foreach ($vorhanden as $name) {
            $istAddon = str_starts_with($name, PluginDataRegistry::PRAEFIX);
            $istAltbestand = in_array($name, self::LEGACY_TABLES, true);
            if (!$istAddon && !$istAltbestand) {
                continue;
            }
            if (preg_match('/^[a-z0-9_]+\z/', $name) !== 1) {
                $ergebnis['uebersprungen'][] = $name;
                continue;
            }
            $ergebnis[$istAddon ? 'addons' : 'altbestand'][] = $name;
        }
        return $ergebnis;
    }

    /**
     * Leert Kern-, Addon- und Altbestandstabellen und würfelt danach eine
     * neue Installationsepoche - damit endet jede bestehende Anmeldung.
     *
     * Der Name bleibt aus Kompatibilität, geleert wird per DELETE (siehe
     * Klassenkommentar).
     *
     * @return list<string> die geleerten Tabellen
     */
    public static function truncateAll(\PDO $db): array {
        $tabellen = self::tabellen($db);
        $alle = array_merge($tabellen['kern'], $tabellen['addons'], $tabellen['altbestand']);

        $db->exec("SET FOREIGN_KEY_CHECKS = 0;");
        try {
            foreach ($alle as $table) {
                $db->exec("DELETE FROM `{$table}`;");
            }
        } finally {
            $db->exec("SET FOREIGN_KEY_CHECKS = 1;");
        }

        // Nach dem Leeren, denn settings gehört selbst zu den geleerten
        // Tabellen. Scheitert das, fehlt die Zeile: Der Datenbankwert ist
        // dann '' und passt zu keiner Alt-Sitzung - fail-closed.
        InstallEpoch::renew($db);

        return $alle;
    }
}
