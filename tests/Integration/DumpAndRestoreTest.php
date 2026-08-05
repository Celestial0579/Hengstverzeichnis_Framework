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

    private const TARGET_DB = 'hengst_dumper_restore_target';

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

        $stmt = self::$source->prepare("INSERT INTO persons (name, contact_info) VALUES (?, ?)");
        $stmt->execute(['Müller, Anna "die Schnelle"', "Zeile1\nZeile2"]);

        // Zweite, komplett leere Datenbank als Restore-Ziel.
        $adminDsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4";
        self::$adminPdo = new PDO($adminDsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        self::$adminPdo->exec("DROP DATABASE IF EXISTS `" . self::TARGET_DB . "`");
        self::$adminPdo->exec("CREATE DATABASE `" . self::TARGET_DB . "` CHARACTER SET utf8mb4");
    }

    public static function tearDownAfterClass(): void {
        if (isset(self::$adminPdo)) {
            self::$adminPdo->exec("DROP DATABASE IF EXISTS `" . self::TARGET_DB . "`");
        }
    }

    public function testDumpCanBeFullyRestoredIntoAFreshDatabase(): void {
        $sql = DatabaseDumper::dump();

        $this->assertStringContainsString('DROP TABLE IF EXISTS `settings`', $sql);
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('INSERT INTO `settings`', $sql);

        $target = new PDO(
            "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . self::TARGET_DB . ";charset=utf8mb4",
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

        $restoredPersonName = $target->query("SELECT name, contact_info FROM persons LIMIT 1")->fetch();
        $this->assertSame('Müller, Anna "die Schnelle"', $restoredPersonName['name']);
        $this->assertSame("Zeile1\nZeile2", $restoredPersonName['contact_info']);
    }
}
