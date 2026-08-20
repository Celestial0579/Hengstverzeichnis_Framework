<?php
// tests/Integration/DumpAndRestoreTest.php

namespace Tests\Integration;

use App\Database;
use App\Service\DatabaseDumper;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Prüft App\Service\DatabaseDumper (#59) mit einem echten Rundlauf: Schema +
 * Testdaten in die konfigurierte Test-Datenbank laden, Dump erzeugen, den
 * Dump als SQL-Skript gegen eine ZWEITE, komplett leere Datenbank ausführen
 * und dort verifizieren, dass Tabellenstruktur und Zeilenwerte exakt
 * übereinstimmen - die stärkste realistische Prüfung für ein Dump-/Restore-
 * Werkzeug (ein rein struktureller Test ohne echten Reimport könnte einen
 * syntaktisch ungültigen oder unvollständigen Dump nicht erkennen).
 *
 * Dateiname bewusst NICHT "DatabaseDumperTest.php": App\Database ist ein
 * Singleton, und DatabaseTest.php verlangt (siehe dortiger Klassendoc), der
 * ERSTE Aufrufer von Database::getInstance() im gesamten PHPUnit-Prozess zu
 * sein, um seine eigene Alt-Schema-Migration zu prüfen. PHPUnit durchläuft
 * Testklassen ohne explizite Suite-Konfiguration alphabetisch nach
 * Dateiname - ein Dateiname vor "DatabaseTest.php" (z. B.
 * "DatabaseDumperTest.php") würde durch den eigenen
 * Database::getInstance()-Aufruf hier DatabaseTest.php brechen.
 */
class DumpAndRestoreTest extends TestCase {

    /**
     * Name der Wegwerf-Datenbank. Ueber HV_TEST_DB_PREFIX umstellbar, damit
     * die Suite auch mit einem Datenbank-Benutzer laeuft, der nur auf einem
     * eigenen Namensraum Rechte hat - siehe Tests\Support\WegwerfDatenbank.
     */
    private static function zielDatenbank(): string {
        return \Tests\Support\WegwerfDatenbank::name('dumper_restore_target');
    }

    private static PDO $adminPdo;
    private static PDO $source;

    public static function setUpBeforeClass(): void {
        if (!defined('DB_HOST')) {
            self::markTestSkipped('Keine Test-Datenbank konfiguriert (DB_HOST fehlt) - siehe tests/bootstrap.php.');
        }

        $setupPdo = new PDO(
            "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $setupPdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        foreach ($setupPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $setupPdo->exec("DROP TABLE IF EXISTS `$table`");
        }
        $setupPdo->exec("SET FOREIGN_KEY_CHECKS = 1");

        $schemaFile = __DIR__ . '/../../database/schema.sql';
        try {
            $setupPdo->exec(file_get_contents($schemaFile));
        } catch (PDOException $e) {
            // Ignorieren, analog zu SetupController::provision()
        }

        self::$source = Database::getInstance();

        // Testdaten mit bewusst "fiesen" Escaping-Fällen (Anführungszeichen,
        // Backslashes, NULL-Werte, Umlaute) in die Quell-DB einfügen.
        self::$source->exec("DELETE FROM settings");
        $stmt = self::$source->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
        $stmt->execute(['site_name', "Reiter's Verband \"Süd\" \\ Test"]);
        $stmt->execute(['primary_color', null]);

        $stmt = self::$source->prepare("INSERT INTO contacts (name, contact_info) VALUES (?, ?)");
        $stmt->execute(['Müller, Anna "die Schnelle"', "Zeile1\nZeile2"]);

        // Zweite, komplett leere Datenbank als Restore-Ziel.
        $adminDsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4";
        self::$adminPdo = new PDO($adminDsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        self::$adminPdo->exec("DROP DATABASE IF EXISTS `" . self::zielDatenbank() . "`");
        self::$adminPdo->exec("CREATE DATABASE `" . self::zielDatenbank() . "` CHARACTER SET utf8mb4");
    }

    public static function tearDownAfterClass(): void {
        if (isset(self::$adminPdo)) {
            self::$adminPdo->exec("DROP DATABASE IF EXISTS `" . self::zielDatenbank() . "`");
        }
    }

    public function testDumpCanBeFullyRestoredIntoAFreshDatabase(): void {
        $sql = DatabaseDumper::dump();

        $this->assertStringContainsString('DROP TABLE IF EXISTS `settings`', $sql);
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('INSERT INTO `settings`', $sql);

        $target = new PDO(
            "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . self::zielDatenbank() . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        // Realistischer Restore-Weg: das komplette Dump-Skript als eine
        // mehranweisungsfähige PDO::exec()-Ausführung, wie sie ein Betreiber
        // z. B. per `mysql < dump.sql` durchführen würde.
        $target->exec($sql);

        $sourceTables = self::$source->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $targetTables = $target->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        sort($sourceTables);
        sort($targetTables);
        $this->assertSame($sourceTables, $targetTables, 'Nach dem Restore fehlen oder existieren zusätzliche Tabellen');

        foreach ($sourceTables as $table) {
            $sourceCount = (int)self::$source->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            $targetCount = (int)$target->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            $this->assertSame($sourceCount, $targetCount, "Zeilenanzahl von `$table` weicht nach dem Restore ab");
        }

        // Escaping-kritische Werte exakt (inkl. Anführungszeichen/Backslash/NULL) übernommen?
        $restoredSiteName = $target->query("SELECT setting_value FROM settings WHERE setting_key = 'site_name'")->fetchColumn();
        $this->assertSame("Reiter's Verband \"Süd\" \\ Test", $restoredSiteName);

        $restoredPrimaryColor = $target->query("SELECT setting_value FROM settings WHERE setting_key = 'primary_color'")->fetchColumn();
        $this->assertNull($restoredPrimaryColor);

        $restoredKontaktName = $target->query("SELECT name, contact_info FROM contacts LIMIT 1")->fetch();
        $this->assertSame('Müller, Anna "die Schnelle"', $restoredKontaktName['name']);
        $this->assertSame("Zeile1\nZeile2", $restoredKontaktName['contact_info']);
    }

    /**
     * Die streamende API (#231) muss byte-identisch dasselbe liefern wie
     * dump() - nur eben in Chunks statt als Gesamtstring. Einzige erlaubte
     * Abweichung: die Zeitstempel-Kopfzeile, wenn die beiden Aufrufe über
     * eine Sekundengrenze fallen.
     */
    public function testDumpToProducesByteIdenticalOutputToDump(): void {
        $chunks = [];
        \App\Service\DatabaseDumper::dumpTo(function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        });
        $streamed = implode('', $chunks);

        $full = \App\Service\DatabaseDumper::dump();

        $normalize = fn(string $sql) => preg_replace(
            '/^-- Automatisches Backup \(#59\) - \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} UTC$/m',
            '-- Automatisches Backup (#59) - <ZEITSTEMPEL> UTC',
            $sql
        );
        $this->assertSame($normalize($full), $normalize($streamed));

        // Wirklich gestreamt, nicht ein einzelner Gesamt-Chunk: mindestens
        // Kopfzeilen + je Tabelle mehrere Chunks.
        $this->assertGreaterThan(10, count($chunks));

        // Kein Chunk enthält den kompletten Dump (konstanter Speicher ist das
        // Ziel der API - ein Riesen-Chunk wäre der alte Zustand mit Umweg).
        foreach ($chunks as $chunk) {
            $this->assertLessThan(strlen($streamed), strlen($chunk));
        }
    }

    /**
     * Restore-Rundlauf über die streamende API: die per dumpTo() gelieferten
     * Chunks - so wie BackupService sie in eine Datei schriebe - gegen eine
     * frische Datenbank ausführen und die Escaping-kritischen Werte prüfen.
     */
    public function testDumpToStreamCanBeFullyRestoredIntoAFreshDatabase(): void {
        $dumpFile = tempnam(sys_get_temp_dir(), 'hv-dumpto-test-');
        try {
            $out = fopen($dumpFile, 'wb');
            \App\Service\DatabaseDumper::dumpTo(function (string $chunk) use ($out): void {
                fwrite($out, $chunk);
            });
            fclose($out);

            // Frisches Restore-Ziel (unabhängig von der Reihenfolge der Tests
            // in dieser Klasse).
            self::$adminPdo->exec("DROP DATABASE IF EXISTS `" . self::zielDatenbank() . "`");
            self::$adminPdo->exec("CREATE DATABASE `" . self::zielDatenbank() . "` CHARACTER SET utf8mb4");

            $target = new PDO(
                "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . self::zielDatenbank() . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $target->exec((string)file_get_contents($dumpFile));

            $sourceTables = self::$source->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            $targetTables = $target->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            sort($sourceTables);
            sort($targetTables);
            $this->assertSame($sourceTables, $targetTables, 'Nach dem Restore aus dem Stream fehlen oder existieren zusätzliche Tabellen');

            $restoredSiteName = $target->query("SELECT setting_value FROM settings WHERE setting_key = 'site_name'")->fetchColumn();
            $this->assertSame("Reiter's Verband \"Süd\" \\ Test", $restoredSiteName);

            $restoredPerson = $target->query("SELECT name, contact_info FROM contacts LIMIT 1")->fetch();
            $this->assertSame('Müller, Anna "die Schnelle"', $restoredPerson['name']);
            $this->assertSame("Zeile1\nZeile2", $restoredPerson['contact_info']);
        } finally {
            @unlink($dumpFile);
        }
    }
}
