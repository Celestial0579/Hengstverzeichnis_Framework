<?php
// src/Service/DatabaseDumper.php

namespace App\Service;

use App\Database;
use PDO;

/**
 * Class DatabaseDumper
 *
 * Reine-PHP-Alternative zu `mysqldump` (#59): erzeugt einen vollständig
 * wiederherstellbaren SQL-Dump über PDO, ohne auf ein `mysqldump`-Client-
 * Binary im Container/Hosting angewiesen zu sein (im mitgelieferten
 * Dockerfile nicht installiert, auf klassischem Webhosting oft nicht
 * verfügbar oder `shell_exec` gesperrt) - passend zur "keine externen
 * Abhängigkeiten"-Philosophie des Kerns.
 *
 * Zwei APIs (#231):
 * - dumpTo(callable $write): streamend, konstanter Speicherbedarf - der Dump
 *   wird statement-/zeilenweise als Chunks an den Callback übergeben, ohne
 *   je als Gesamtstring im Speicher zu liegen. Für große Bestände (externe
 *   Backups, Datenmigrations-Addon) der richtige Weg.
 * - dump(): string - dünner Wrapper um dumpTo() für Rückwärtskompatibilität;
 *   sammelt alle Chunks in einem String. Byte-identisch zum bisherigen
 *   Verhalten.
 */
final class DatabaseDumper {

    /**
     * Erzeugt einen vollständigen SQL-Dump (Schema + Daten) aller Tabellen
     * der aktuellen Datenbank als einen String. Dünner Wrapper um dumpTo() -
     * für große Bestände die streamende API bevorzugen (#231).
     */
    public static function dump(): string {
        $buffer = '';
        self::dumpTo(function (string $chunk) use (&$buffer): void {
            $buffer .= $chunk;
        });
        return $buffer;
    }

    /**
     * Streamende Variante (#231): erzeugt denselben Dump wie dump(), übergibt
     * ihn aber statement-/zeilenweise als Chunks an $write, statt ihn als
     * Gesamtstring aufzubauen. Die Daten-SELECTs laufen dabei unbuffered
     * (Pdo\Mysql::ATTR_USE_BUFFERED_QUERY=false), damit auch der MySQL-Client
     * nicht die komplette Tabelle in den Speicher zieht - der Speicherbedarf
     * bleibt so unabhängig von der Instanzgröße konstant.
     *
     * Tabellen werden vor dem Neuanlegen gelöscht (`DROP TABLE IF EXISTS`)
     * und Fremdschlüssel-Prüfungen für die Dauer des Imports deaktiviert,
     * damit die Wiederherstellungsreihenfolge unabhängig von
     * Fremdschlüssel-Abhängigkeiten funktioniert.
     *
     * TABELLENAUSWAHL (#342). $tables = null heißt weiterhin "alles" - das ist
     * der Bestandsaufruf und muss es bleiben, weil das automatische Backup
     * (#59) genau das will: eine vollständige Sicherung, aus der sich die
     * Instanz wiederherstellen lässt.
     *
     * Wozu die Auswahl dann? Für den Export im Datenmigrations-Addon
     * (Addons#121). Der nimmt heute zwangsläufig ALLES mit - also auch
     * `users` mit den Passwort-Hashes, den 2FA-Geheimnissen und den
     * Backup-Codes, dazu `api_keys`. Wer nur seine Pferde und Kontakte zu
     * einer anderen Instanz tragen will, verschickt damit die
     * Anmeldedaten seines Vereins gleich mit, ohne es zu merken.
     *
     * Die Auswahl ist eine POSITIVLISTE und wird gegen die tatsächlich
     * vorhandenen Tabellen abgeglichen: Ein Name, den es nicht gibt, wird
     * still übergangen (eine Instanz ohne ein bestimmtes Addon hat dessen
     * Tabellen nicht), und ein Name, der nicht aus SHOW TABLES stammt, kommt
     * gar nicht erst in die Abfrage - der Tabellenname geht unquotiert in
     * SQL, und ein durchgereichter Aufrufwert wäre genau die Stelle, an der
     * das eines Tages jemand ausnutzt.
     *
     * @param callable(string): void $write  Erhält den Dump in Chunks
     *                                       (typisch: eine SQL-Anweisung samt
     *                                       abschließendem Zeilenumbruch).
     * @param string[]|null          $tables Positivliste der zu sichernden
     *                                       Tabellen; null = alle.
     */
    public static function dumpTo(callable $write, ?array $tables = null): void {
        $pdo = Database::getInstance();
        $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();

        // SHOW TABLES vollständig einlesen, BEVOR unten unbuffered gearbeitet
        // wird - die Tabellenliste ist klein, die Daten sind es nicht.
        $vorhanden = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

        if ($tables === null) {
            $tables = $vorhanden;
            $auswahlHinweis = '';
        } else {
            // Schnittmenge, Reihenfolge aus SHOW TABLES (stabile Dumps).
            $gewuenscht = array_flip(array_map('strval', $tables));
            $tables = array_values(array_filter(
                $vorhanden,
                static fn($t) => isset($gewuenscht[$t])
            ));
            $auswahlHinweis = sprintf(
                "-- Auswahl (#342): %d von %d Tabellen - dies ist KEINE vollständige Sicherung.\n",
                count($tables),
                count($vorhanden)
            );
        }

        $write('-- Automatisches Backup (#59) - ' . gmdate('Y-m-d H:i:s') . " UTC\n");
        $write('-- Datenbank: ' . $dbName . "\n");
        // Der Hinweis steht bewusst IM Dump: Wer eine Teilsicherung Monate
        // später vor sich hat, sieht sonst eine gültige .sql-Datei und hält
        // sie für ein Backup.
        $write($auswahlHinweis);
        $write("SET FOREIGN_KEY_CHECKS=0;\n");
        $write("SET NAMES utf8mb4;\n\n");

        $wasBuffered = $pdo->getAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY);
        $pdo->setAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, false);
        try {
            foreach ($tables as $table) {
                self::dumpTableTo($pdo, $table, $write);
            }
        } finally {
            // Die Verbindung ist ein App-weites Singleton - den Puffer-Modus
            // für alle nachfolgenden Nutzer wiederherstellen.
            $pdo->setAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, (bool)$wasBuffered);
        }

        $write('SET FOREIGN_KEY_CHECKS=1;');
    }

    /**
     * @param callable(string): void $write
     */
    private static function dumpTableTo(PDO $pdo, string $table, callable $write): void {
        $quotedTable = '`' . str_replace('`', '``', $table) . '`';

        $createQuery = $pdo->query("SHOW CREATE TABLE {$quotedTable}");
        $createStmt = $createQuery->fetch();
        $createQuery->closeCursor(); // Pflicht im unbuffered Modus vor der nächsten Query
        $createSql = $createStmt['Create Table'] ?? $createStmt[1] ?? '';

        $write("-- Tabelle: {$table}\n");
        $write("DROP TABLE IF EXISTS {$quotedTable};\n");
        $write("{$createSql};\n");

        $stmt = $pdo->query("SELECT * FROM {$quotedTable}");
        $columns = null;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($columns === null) {
                $columns = array_map(fn($col) => '`' . str_replace('`', '``', $col) . '`', array_keys($row));
            }
            $values = array_map(
                fn($value) => $value === null ? 'NULL' : $pdo->quote((string)$value),
                array_values($row)
            );
            $write("INSERT INTO {$quotedTable} (" . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n");
        }
        $stmt->closeCursor();

        $write("\n");
    }
}
