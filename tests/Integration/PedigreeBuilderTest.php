<?php
// tests/Integration/PedigreeBuilderTest.php

namespace Tests\Integration;

use App\Database;
use App\Service\PedigreeBuilder;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Prüft App\Service\PedigreeBuilder::build() gegen eine echte Test-Datenbank
 * (analog zu DatabaseTest.php) - deckt die drei Vorfahren-Auflösungspfade ab:
 * FK-verknüpft (sire_id/dam_id), Namens-/UELN-Fallback (findParentByUelnOrName)
 * und ein tatsächlich unauffindbarer Vorfahre (synthetischer Platzhalter-
 * Blattknoten inkl. der depth+1-Eigenheit, siehe PedigreeBuilder-Docblock -
 * bewusst als Regressionstest verankert, nicht "reparieren").
 */
class PedigreeBuilderTest extends TestCase {

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

        // Sauberer Start, unabhängig von einem eventuellen Vorlauf anderer Tests.
        $setupPdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        foreach ($setupPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $setupPdo->exec("DROP TABLE IF EXISTS `$table`");
        }
        $setupPdo->exec("SET FOREIGN_KEY_CHECKS = 1");

        // Volles aktuelles Schema laden - wie SetupController::provision() es beim
        // echten Setup-Wizard tut (database/schema.sql enthält u. a. `horses` mit
        // sire_id/dam_id/sire_name/... - Database::ensureSchemaUpToDate() allein
        // legt diese Basistabellen NICHT an, sie migriert nur bereits existierende).
        $schemaFile = __DIR__ . '/../../database/schema.sql';
        try {
            $setupPdo->exec(file_get_contents($schemaFile));
        } catch (PDOException $e) {
            // Ignorieren, analog zu SetupController::provision()
        }

        // Löst Database::ensureSchemaUpToDate() gegen dieselbe DB aus, damit
        // PedigreeBuilder (intern Database::getInstance()) konsistent verbindet.
        self::$db = Database::getInstance();
    }

    private function insertHorse(array $overrides = []): int {
        $data = array_merge([
            'name' => 'Testpferd ' . uniqid(),
            'ueln' => null,
            'sire_id' => null, 'sire_name' => null, 'sire_ueln' => null,
            'dam_id' => null, 'dam_name' => null, 'dam_ueln' => null,
        ], $overrides);

        $stmt = self::$db->prepare("
            INSERT INTO horses (name, ueln, sire_id, sire_name, sire_ueln, dam_id, dam_name, dam_ueln)
            VALUES (:name, :ueln, :sire_id, :sire_name, :sire_ueln, :dam_id, :dam_name, :dam_ueln)
        ");
        $stmt->execute($data);
        return (int)self::$db->lastInsertId();
    }

    public function testFkLinkedSireAndDamAreResolved(): void {
        $sireId = $this->insertHorse(['name' => 'FK-Vater']);
        $damId = $this->insertHorse(['name' => 'FK-Mutter']);
        $foalId = $this->insertHorse(['name' => 'FK-Fohlen', 'sire_id' => $sireId, 'dam_id' => $damId]);

        $tree = PedigreeBuilder::build($foalId, 2);

        $this->assertSame('FK-Fohlen', $tree['name']);
        $this->assertSame(1, $tree['depth']);
        $this->assertSame('FK-Vater', $tree['sire']['name']);
        $this->assertSame(2, $tree['sire']['depth']);
        $this->assertArrayNotHasKey('is_placeholder', $tree['sire']);
        $this->assertSame('FK-Mutter', $tree['dam']['name']);
    }

    public function testUelnFallbackResolvesUnlinkedAncestor(): void {
        $sireId = $this->insertHorse(['name' => 'Fallback-Vater', 'ueln' => 'DE000TESTFB01']);
        $foalId = $this->insertHorse(['name' => 'Fallback-Fohlen', 'sire_ueln' => 'DE000TESTFB01', 'sire_name' => 'Fallback-Vater']);

        $tree = PedigreeBuilder::build($foalId, 2);

        $this->assertSame($sireId, $tree['sire']['id']);
        $this->assertSame('Fallback-Vater', $tree['sire']['name']);
        $this->assertArrayNotHasKey('is_placeholder', $tree['sire']);
    }

    public function testUnmatchedAncestorProducesPlaceholderWithDepthPlusOne(): void {
        $foalId = $this->insertHorse([
            'name' => 'Waisen-Fohlen',
            'sire_name' => 'Unbekannter Hengst',
            'sire_ueln' => 'DE000NOTFOUND9',
        ]);

        // maxDepth = 1: der Platzhalter für den Vater wird auf Ebene
        // currentDepth+1 = 2 erzeugt, obwohl maxDepth nur 1 ist - genau die
        // dokumentierte Ausnahme von der sonstigen $currentDepth > $maxDepth-
        // Abbruchbedingung. Als Regressionstest fixiert.
        $tree = PedigreeBuilder::build($foalId, 1);

        $this->assertNull($tree['sire']['id']);
        $this->assertTrue($tree['sire']['is_placeholder']);
        $this->assertSame(2, $tree['sire']['depth']); // maxDepth(1) + 1
        $this->assertSame('Unbekannter Hengst', $tree['sire']['name']);
    }

    public function testUnknownHorseIdReturnsNull(): void {
        $this->assertNull(PedigreeBuilder::build(999999999, 4));
    }

    public function testNullHorseIdReturnsNull(): void {
        $this->assertNull(PedigreeBuilder::build(null, 4));
    }
}
