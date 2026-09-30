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
 *   sammelt alle Chunks in einem String. Byte-identisch zu dumpTo().
 *
 * Konsistenz und Zeitzone (Audit N66): Der Dump liest alle Tabellen aus einem
 * gemeinsamen Stand (START TRANSACTION WITH CONSISTENT SNAPSHOT, wie
 * `mysqldump --single-transaction`) und in der Sitzungszeitzone UTC. Er
 * setzt beim Einspielen selbst UTC und stellt am Ende die vorherige
 * Zeitzone der einspielenden Sitzung wieder her - siehe ZEITZONE_*.
 * Während des Dumps hält die Lesetransaktion Metadaten-Sperren auf die
 * gelesenen Tabellen: Gleichzeitige Schemaänderungen (etwa eine
 * Addon-Installation) warten bis zum Dump-Ende.
 */
final class DatabaseDumper {

    /*
     * Die drei Zeitzonen-Anweisungen des Dumps (Audit N66), exakt so im Dump.
     * Das Addon datenmigration führt sie wörtlich in seiner
     * Anweisungs-Positivliste (DumpPruefer::SET_ZEILEN) - wer sie ändert,
     * muss das Addon nachziehen, sonst lehnt dessen Import neue Dumps ab.
     */

    /** Kopf: Zeitzone der einspielenden Sitzung merken. */
    public const ZEITZONE_MERKEN = 'SET @hv_dump_zeitzone = @@SESSION.time_zone;';

    /** Kopf: Die Zeitstempel im Dump stehen in UTC. */
    public const ZEITZONE_UTC = "SET time_zone = '+00:00';";

    /** Fuß (letzte Zeile, ohne abschließenden Zeilenumbruch): zurücksetzen. */
    public const ZEITZONE_ZURUECK = 'SET time_zone = @hv_dump_zeitzone;';

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
     * Gelesen wird in einer eigenen Lesetransaktion mit konsistentem Snapshot
     * und in UTC (Audit N66, siehe Klassendoc). Danach sind Transaktion und
     * Sitzungszeitzone der Verbindung wieder wie vorher; eine bereits offene
     * Transaktion des Aufrufers bleibt offen.
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

        // Zeitzone (Audit N66): SELECT * gibt TIMESTAMP-Spalten in der
        // Sitzungszeitzone aus - die App setzt dort den PHP-Versatz, im
        // Sommer also +02:00, ohne dass der Dump das vermerkt. Deshalb in UTC
        // lesen und das im Dump-Kopf festhalten. Scheitert das SET, bricht
        // der Dump ab: Ein Dump in falscher Zeitzone mit UTC-Kopf wäre
        // schlimmer als keiner. DATETIME-Spalten sind davon nicht betroffen.
        $alteZeitzone = (string)$pdo->query('SELECT @@SESSION.time_zone')->fetchColumn();
        $pdo->exec("SET time_zone = '+00:00'");

        // Ein gemeinsamer Stand für alle Tabellen (Audit N66). Eine offene
        // Transaktion des Aufrufers wird nicht angetastet (kein implizites
        // COMMIT); inTransaction() erkennt bei pdo_mysql auch per exec()
        // gestartete Transaktionen.
        $eigeneTransaktion = false;
        $wasBuffered = null;
        try {
            if (!$pdo->inTransaction()) {
                // Gilt nur für die nächste Transaktion - schützt vor einem
                // global eingestellten READ COMMITTED beim Hoster.
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
                $eigeneTransaktion = true;
            }

            // Erst NACH dem Snapshot-Beginn: Sonst könnte eine zwischen
            // SHOW TABLES und Snapshot angelegte Tabelle fehlen oder eine
            // gelöschte angefragt werden. Vollständig eingelesen, BEVOR unten
            // unbuffered gearbeitet wird - die Tabellenliste ist klein.
            $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
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
            $write("-- Zeitstempel (TIMESTAMP) in UTC\n");
            $write(self::ZEITZONE_MERKEN . "\n");
            $write(self::ZEITZONE_UTC . "\n");
            $write("SET FOREIGN_KEY_CHECKS=0;\n");
            $write("SET NAMES utf8mb4;\n\n");

            $wasBuffered = $pdo->getAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY);
            $pdo->setAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, false);
            foreach ($tables as $table) {
                self::dumpTableTo($pdo, $table, $write);
            }

            // Letzte Zeile wie bisher ohne abschließenden Zeilenumbruch.
            $write("SET FOREIGN_KEY_CHECKS=1;\n" . self::ZEITZONE_ZURUECK);
        } finally {
            // Aufräumen in fester Reihenfolge, jeder Schritt für sich: Ein
            // Fehler hier darf die eigentliche Ursache nicht verdecken.
            if ($wasBuffered !== null) {
                try {
                    // Die Verbindung ist ein App-weites Singleton - den
                    // Puffer-Modus für alle nachfolgenden Nutzer wiederherstellen.
                    $pdo->setAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, (bool)$wasBuffered);
                } catch (\Throwable $e) {
                    error_log('DatabaseDumper: Puffer-Modus nicht wiederhergestellt: ' . $e->getMessage());
                }
            }
            if ($eigeneTransaktion) {
                // exec statt $pdo->commit(): Die Transaktion wurde per exec
                // gestartet. Es wurde nur gelesen - COMMIT oder ROLLBACK ist
                // gleichwertig, Hauptsache sie endet.
                try {
                    $pdo->exec('COMMIT');
                } catch (\Throwable $e) {
                    try {
                        $pdo->exec('ROLLBACK');
                    } catch (\Throwable $e2) {
                        error_log('DatabaseDumper: Lesetransaktion nicht beendet: ' . $e2->getMessage());
                    }
                }
            }
            try {
                $pdo->prepare('SET time_zone = ?')->execute([$alteZeitzone]);
            } catch (\Throwable $e) {
                error_log('DatabaseDumper: Sitzungszeitzone nicht wiederhergestellt: ' . $e->getMessage());
            }
        }
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
        try {
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
        } finally {
            // Auch nach einem Schreibfehler mitten in der Tabelle (Audit N65):
            // Eine offene unbuffered-Abfrage blockierte sonst die
            // Singleton-Verbindung, und COMMIT sowie das Statusschreiben
            // danach scheiterten mit 2014.
            $stmt->closeCursor();
        }

        $write("\n");
    }
}
