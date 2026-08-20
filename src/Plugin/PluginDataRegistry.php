<?php
// src/Plugin/PluginDataRegistry.php

namespace App\Plugin;

use App\Database;
use PDO;

/**
 * Class PluginDataRegistry
 *
 * Was gehört einem Addon? (#338)
 *
 * DAS PROBLEM. Ein deinstalliertes Addon verschwand bis v0.7 aus dem
 * Verzeichnis - und liess alles liegen, was es je angelegt hatte: Tabellen,
 * hochgeladene Dateien, Einstellungen. Darunter Kontaktanfragen mit Name und
 * E-Mail-Adresse. Der Betreiber klickt "deaktivieren", das Addon ist weg, und
 * er nimmt an, die Daten seien es auch. Sie sind es nicht, und niemand sagt
 * es ihm - das ist keine Schlamperei, sondern eine Auskunftspflicht, die
 * unbemerkt verletzt wird.
 *
 * DIE LÖSUNG IST NICHT "EINFACH LÖSCHEN". Ein Addon vorübergehend zu
 * deaktivieren, um einen Fehler einzugrenzen, ist ein normaler Vorgang - wenn
 * dabei die Daten verschwänden, träute sich das niemand mehr. Deshalb sind
 * DEAKTIVIEREN und DEINSTALLIEREN zwei verschiedene Dinge, und nur das
 * zweite fragt nach den Daten.
 *
 * DAS REGISTER. Ein Addon erklärt in seiner `plugin.json`, was ihm gehört:
 *
 *     "owns": {
 *         "tables":      ["plugin_galerie_media"],
 *         "directories": ["storage/plugin_galerie"],
 *         "settings":    ["plugin_galerie_max_size"]
 *     }
 *
 * Wozu deklarativ statt einer `uninstall()`-Methode? Weil der Betreiber VOR
 * dem Löschen sehen soll, was verschwindet - und dafür muss man es aufzählen
 * können, ohne Code des Addons auszuführen. Eine Methode könnte man nur
 * aufrufen und hoffen. Eine `uninstall()`-Methode gibt es trotzdem
 * zusätzlich, für alles, was sich nicht aufzählen lässt (siehe
 * PluginManager::runUninstallHook()).
 *
 * SICHERHEITSGRENZEN. Ein Manifest ist eine Datei im Addon-Verzeichnis; die
 * Angaben darin sind eine Behauptung des Addons, keine Wahrheit. Deshalb:
 *
 * - Tabellennamen müssen mit `plugin_` beginnen. Ohne diese Regel trüge ein
 *   Addon `"tables": ["users"]` ein, und die Deinstallation nähme die
 *   Benutzerkonten mit.
 * - Verzeichnisse müssen innerhalb der Installation liegen und dürfen weder
 *   `public/uploads` noch `plugins` noch `config` sein oder enthalten.
 *   Geprüft wird über realpath(), nicht über die Zeichenkette - sonst käme
 *   man mit einem Symlink oder `../` heraus.
 * - Einstellungsschlüssel müssen mit `plugin_` beginnen, aus demselben Grund
 *   wie die Tabellen.
 *
 * Was diese Prüfungen nicht durchlässt, wird nicht gelöscht und dem Betreiber
 * als Auffälligkeit gemeldet - schweigend zu übergehen wäre schlimmer, denn
 * dann bliebe es liegen und niemand wüsste davon.
 */
final class PluginDataRegistry {

    /** Pflicht-Präfix für Tabellen und Einstellungsschlüssel eines Addons. */
    private const PRAEFIX = 'plugin_';

    /**
     * Verzeichnisse, die ein Addon niemals als "seins" beanspruchen darf -
     * auch nicht als Elternverzeichnis. `public/uploads` und `plugins` stehen
     * nicht zufällig auch in UpdateService::PROTECTED_PATHS: Das sind die
     * Stellen, an denen die Instanz das hält, was nicht aus dem Release kommt.
     */
    private const TABU = ['public/uploads', 'plugins', 'config', 'storage/logs', '.git'];

    private function __construct() {}

    /**
     * Liest das Register eines Addons und prüft es.
     *
     * @param array  $manifest Der Inhalt der plugin.json
     * @param string $wurzel   Installationswurzel (Basis für die Pfadprüfung)
     *
     * @return array{tables:string[], directories:string[], settings:string[], abgelehnt:string[]}
     *         `abgelehnt` enthält die Einträge, die eine Prüfung nicht bestanden
     *         haben, samt Grund - sie gehören in die Anzeige.
     */
    public static function fuer(array $manifest, string $wurzel): array {
        $owns = $manifest['owns'] ?? [];
        $ergebnis = ['tables' => [], 'directories' => [], 'settings' => [], 'abgelehnt' => []];
        if (!is_array($owns)) {
            $ergebnis['abgelehnt'][] = '"owns" im Manifest ist kein Objekt - Register ignoriert.';
            return $ergebnis;
        }

        foreach (self::liste($owns, 'tables') as $tabelle) {
            if (!str_starts_with($tabelle, self::PRAEFIX)) {
                $ergebnis['abgelehnt'][] = sprintf(
                    'Tabelle "%s": Addon-Tabellen müssen mit "%s" beginnen.',
                    $tabelle,
                    self::PRAEFIX
                );
                continue;
            }
            $ergebnis['tables'][] = $tabelle;
        }

        foreach (self::liste($owns, 'settings') as $schluessel) {
            if (!str_starts_with($schluessel, self::PRAEFIX)) {
                $ergebnis['abgelehnt'][] = sprintf(
                    'Einstellung "%s": Addon-Einstellungen müssen mit "%s" beginnen.',
                    $schluessel,
                    self::PRAEFIX
                );
                continue;
            }
            $ergebnis['settings'][] = $schluessel;
        }

        $wurzelEcht = realpath($wurzel);
        foreach (self::liste($owns, 'directories') as $verzeichnis) {
            $pfad = $wurzel . '/' . ltrim($verzeichnis, '/');
            $echt = realpath($pfad);
            if ($echt === false) {
                // Gibt es nicht (mehr) - kein Fehler, nur nichts zu tun.
                continue;
            }
            if ($wurzelEcht === false || !str_starts_with($echt . '/', $wurzelEcht . '/')) {
                $ergebnis['abgelehnt'][] = sprintf(
                    'Verzeichnis "%s": liegt ausserhalb der Installation.',
                    $verzeichnis
                );
                continue;
            }
            if (self::istTabu($echt, $wurzelEcht)) {
                $ergebnis['abgelehnt'][] = sprintf(
                    'Verzeichnis "%s": geschützter Ort der Installation.',
                    $verzeichnis
                );
                continue;
            }
            $ergebnis['directories'][] = $echt;
        }

        return $ergebnis;
    }

    /**
     * Was würde das Löschen tatsächlich treffen? Zeilenzahlen und Dateizahlen,
     * damit die Rückfrage an den Betreiber eine Zahl nennen kann statt eines
     * Tabellennamens. "3 Tabellen werden gelöscht" ist keine Information;
     * "1.284 Kontaktanfragen werden gelöscht" ist eine.
     *
     * @return array{tables:array<string,int>, directories:array<string,int>, settings:string[], abgelehnt:string[]}
     */
    public static function vorschau(array $register): array {
        $vorschau = [
            'tables' => [],
            'directories' => [],
            'settings' => $register['settings'],
            'abgelehnt' => $register['abgelehnt'],
        ];

        try {
            $pdo = Database::getInstance();
            foreach ($register['tables'] as $tabelle) {
                try {
                    $da = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($tabelle));
                    if (!$da || $da->rowCount() === 0) {
                        continue;
                    }
                    // Tabellenname stammt aus dem geprüften Register (Präfix
                    // erzwungen) UND ist gerade über SHOW TABLES bestätigt -
                    // er kann nicht mehr frei gewählt sein.
                    $anzahl = (int)$pdo->query(
                        'SELECT COUNT(*) FROM `' . str_replace('`', '``', $tabelle) . '`'
                    )->fetchColumn();
                    $vorschau['tables'][$tabelle] = $anzahl;
                } catch (\Throwable $e) {
                    // Einzelne Tabelle nicht lesbar - der Rest bleibt zählbar.
                }
            }
        } catch (\Throwable $e) {
            // Keine Datenbank (CLI/Test) - dann eben ohne Zahlen.
        }

        foreach ($register['directories'] as $verzeichnis) {
            $vorschau['directories'][$verzeichnis] = self::zaehleDateien($verzeichnis);
        }

        return $vorschau;
    }

    /** @return string[] */
    private static function liste(array $owns, string $schluessel): array {
        $roh = $owns[$schluessel] ?? [];
        if (!is_array($roh)) {
            return [];
        }
        $sauber = [];
        foreach ($roh as $eintrag) {
            if (is_string($eintrag) && trim($eintrag) !== '') {
                $sauber[] = trim($eintrag);
            }
        }
        return array_values(array_unique($sauber));
    }

    private static function istTabu(string $echterPfad, string $wurzel): bool {
        foreach (self::TABU as $tabu) {
            $tabuEcht = realpath($wurzel . '/' . $tabu);
            if ($tabuEcht === false) {
                continue;
            }
            // Gleich ODER darin ODER darüber: Ein Addon, das "storage"
            // beansprucht, nähme storage/logs mit.
            if ($echterPfad === $tabuEcht
                || str_starts_with($echterPfad . '/', $tabuEcht . '/')
                || str_starts_with($tabuEcht . '/', $echterPfad . '/')) {
                return true;
            }
        }
        return false;
    }

    private static function zaehleDateien(string $verzeichnis): int {
        if (!is_dir($verzeichnis)) {
            return 0;
        }
        $anzahl = 0;
        $lauf = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($verzeichnis, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($lauf as $eintrag) {
            if ($eintrag->isFile()) {
                $anzahl++;
            }
        }
        return $anzahl;
    }
}
