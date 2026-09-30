<?php
// tests/Integration/HorseCsvImporterValidationTest.php

namespace Tests\Integration;

use App\Database;
use App\Service\HorseCsvImporter;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Audit N48: Der UELN-Abgleich des CSV-Imports war byte-genau, der
 * UNIQUE-Index vergleicht dagegen mit utf8mb4_unicode_ci. Eine Zeile
 * 'de 431…' neben dem Bestand 'DE 431…' bestand die Vorprüfung und riss beim
 * Commit den ganzen Import per Rollback mit. Jetzt prüft validateRows() den
 * Bestand in SQL mit der Spalten-Collation und innerhalb der Datei
 * case-insensitiv.
 */
class HorseCsvImporterValidationTest extends TestCase {

    private static PDO $db;

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
        try {
            $setupPdo->exec(file_get_contents(__DIR__ . '/../../database/schema.sql'));
        } catch (PDOException $e) {
            // Ignorieren, analog zu SetupController::provision()
        }

        self::$db = Database::getInstance();
    }

    protected function setUp(): void {
        self::$db->exec("DELETE FROM horses");
    }

    /** @return array<int, array<int, string>> Fehler je Zeile */
    private function fehler(string $csv): array {
        $parsed = HorseCsvImporter::parse($csv);
        $this->assertNull($parsed['error']);
        return array_map(static fn(array $r): array => $r['errors'], HorseCsvImporter::validateRows($parsed, self::$db));
    }

    public function testExistingUelnIsMatchedWithTheColumnCollation(): void {
        $u = strtoupper(substr(md5(uniqid('', true)), 0, 8));
        self::$db->prepare("INSERT INTO horses (name, ueln) VALUES (?, ?)")->execute(['Bestand', "DE 431XY{$u}"]);
        self::$db->prepare("INSERT INTO horses (name, ueln, deleted_at) VALUES (?, ?, NOW())")->execute(['Papierkorb', "NÖ{$u}"]);

        $fehler = $this->fehler("name;ueln\nKlein;" . strtolower("de 431xy{$u}") . "\nAkzent;no{$u}\nFrei;FREI{$u}\n");

        $this->assertCount(1, $fehler[0]);
        $this->assertStringContainsString('bereits einem bestehenden Pferd zugeordnet', $fehler[0][0]);
        $this->assertStringContainsString(strtolower("de 431xy{$u}"), $fehler[0][0], 'Die Meldung zeigt den Originalwert');
        $this->assertCount(1, $fehler[1], 'Akzentvariante gilt unter utf8mb4_unicode_ci als gleich - auch gegen den Papierkorb');
        $this->assertSame([], $fehler[2]);
    }

    public function testDuplicateWithinTheFileIgnoresCase(): void {
        $u = strtoupper(substr(md5(uniqid('', true)), 0, 8));
        $fehler = $this->fehler("name;ueln\nEins;AB{$u}\nZwei;" . strtolower("ab{$u}") . "\n");

        $this->assertSame([], $fehler[0]);
        $this->assertCount(1, $fehler[1]);
        $this->assertStringContainsString('mehrfach', $fehler[1][0]);
        $this->assertStringContainsString('(Zeile 2)', $fehler[1][0]);
    }
}
