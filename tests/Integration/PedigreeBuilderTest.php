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
 * Blattknoten). Seit #119 endet der Baum strikt bei maxDepth (keine
 * Platzhalter mehr jenseits der letzten Generation), und seit #131 bricht
 * ein Zyklus in Altdaten (Pferd als eigener Vorfahre) den Ast sauber ab.
 */
class PedigreeBuilderTest extends TestCase {

    protected function setUp(): void {
        // Die Memoisierung lebt jetzt über den einzelnen build()-Aufruf hinaus
        // (Performance: Detailseite und Addons bauen im selben Request
        // mehrere Bäume über dieselben Vorfahren). Tests legen zwischen den
        // Fällen neue Pferde an und müssen sie deshalb ausdrücklich verwerfen.
        \App\Service\PedigreeBuilder::resetCache();
    }

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
            'is_published' => 0,
            'sire_id' => null, 'sire_name' => null, 'sire_ueln' => null,
            'dam_id' => null, 'dam_name' => null, 'dam_ueln' => null,
        ], $overrides);

        $stmt = self::$db->prepare("
            INSERT INTO horses (name, ueln, is_published, sire_id, sire_name, sire_ueln, dam_id, dam_name, dam_ueln)
            VALUES (:name, :ueln, :is_published, :sire_id, :sire_name, :sire_ueln, :dam_id, :dam_name, :dam_ueln)
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

    public function testUnmatchedAncestorProducesPlaceholderWithinMaxDepth(): void {
        $foalId = $this->insertHorse([
            'name' => 'Waisen-Fohlen',
            'sire_name' => 'Unbekannter Hengst',
            'sire_ueln' => 'DE000NOTFOUND9',
        ]);

        // maxDepth = 2: der unauffindbare Vater erscheint als synthetischer
        // Platzhalter auf Ebene 2 (innerhalb der angefragten Tiefe).
        $tree = PedigreeBuilder::build($foalId, 2);

        $this->assertNull($tree['sire']['id']);
        $this->assertTrue($tree['sire']['is_placeholder']);
        $this->assertSame(2, $tree['sire']['depth']);
        $this->assertSame('Unbekannter Hengst', $tree['sire']['name']);
    }

    public function testNoPlaceholderBeyondMaxDepth(): void {
        $foalId = $this->insertHorse([
            'name' => 'MaxDepth-Fohlen',
            'sire_name' => 'Unbekannter Hengst',
            'sire_ueln' => 'DE000NOTFOUND8',
        ]);

        // maxDepth = 1: Seit #119 endet der Baum strikt bei maxDepth - der
        // frühere Platzhalter auf Ebene maxDepth+1 (samt immer verworfenem
        // DB-Lookup pro Freitext-Elternteil) entfällt.
        $tree = PedigreeBuilder::build($foalId, 1);

        $this->assertNull($tree['sire']);
        $this->assertNull($tree['dam']);
    }

    public function testCycleInLegacyDataDoesNotShowHorseAsOwnAncestor(): void {
        // Zyklus in Altdaten simulieren: Pferd ist sein eigener Vater (#131).
        $horseId = $this->insertHorse(['name' => 'Zyklus-Pferd']);
        self::$db->exec("UPDATE horses SET sire_id = {$horseId} WHERE id = {$horseId}");

        $tree = PedigreeBuilder::build($horseId, 5);

        $this->assertSame('Zyklus-Pferd', $tree['name']);
        $this->assertNull($tree['sire'], 'Ein Pferd darf nie als sein eigener Vorfahre erscheinen');
    }

    public function testTwoNodeCycleTerminates(): void {
        // A ist Vater von B, B ist (fehlerhaft) Vater von A - der Baum muss
        // terminieren und darf A nicht erneut unter sich selbst zeigen (#131).
        $a = $this->insertHorse(['name' => 'Zyklus-A']);
        $b = $this->insertHorse(['name' => 'Zyklus-B', 'sire_id' => $a]);
        self::$db->exec("UPDATE horses SET sire_id = {$b} WHERE id = {$a}");

        $tree = PedigreeBuilder::build($a, 6);

        $this->assertSame('Zyklus-A', $tree['name']);
        $this->assertSame('Zyklus-B', $tree['sire']['name']);
        $this->assertNull($tree['sire']['sire'], 'Der Zyklus zurück zu A muss abgebrochen werden');
    }

    public function testPublishedOnlyHidesUnpublishedLinkedAncestor(): void {
        $sireId = $this->insertHorse(['name' => 'Unveröff-Vater', 'is_published' => 0]);
        $damId = $this->insertHorse(['name' => 'Veröff-Mutter', 'is_published' => 1]);
        $foalId = $this->insertHorse([
            'name' => 'Publ-Fohlen',
            'is_published' => 1,
            'sire_id' => $sireId,
            'dam_id' => $damId,
        ]);

        // publishedOnly=true: der unveröffentlichte, verknüpfte Vater darf gar nicht
        // erscheinen (leerer Ast), damit aus unveröffentlichten Daten (auch abgeleiteten
        // wie einem Inzuchtkoeffizienten) nichts hergeleitet werden kann.
        $tree = PedigreeBuilder::build($foalId, 2, true);
        $this->assertNull($tree['sire'], 'Unveröffentlichter verknüpfter Vater darf im publishedOnly-Modus nicht erscheinen');
        $this->assertSame('Veröff-Mutter', $tree['dam']['name']);

        // Ohne publishedOnly (Backend-Standard): voller Baum inkl. unveröffentlichtem Vater.
        $full = PedigreeBuilder::build($foalId, 2, false);
        $this->assertSame('Unveröff-Vater', $full['sire']['name']);
    }

    public function testPublishedOnlyUnlinkedUnpublishedAncestorUsesFreetextPlaceholder(): void {
        // Ein per UELN grundsätzlich auffindbarer, aber unveröffentlichter Vater darf
        // im publishedOnly-Modus NICHT über seinen DB-Datensatz aufgelöst werden -
        // stattdessen bleibt nur der im Kind gespeicherte Freitext als Platzhalter.
        $this->insertHorse(['name' => 'Geheim-Vater', 'ueln' => 'DE000SECRET01', 'is_published' => 0]);
        $foalId = $this->insertHorse([
            'name' => 'Freitext-Fohlen',
            'is_published' => 1,
            'sire_ueln' => 'DE000SECRET01',
            'sire_name' => 'Vatername laut Papier',
        ]);

        $tree = PedigreeBuilder::build($foalId, 2, true);
        $this->assertNull($tree['sire']['id'], 'Der unveröffentlichte DB-Datensatz darf nicht aufgelöst werden');
        $this->assertTrue($tree['sire']['is_placeholder']);
        $this->assertSame('Vatername laut Papier', $tree['sire']['name']);
    }

    /**
     * Audit N73: Im publishedOnly-Modus fiel die Suche bei unveröffentlichtem
     * UELN-Treffer auf den NAMEN zurück und setzte einen gleichnamigen, aber
     * anderen Hengst samt dessen Ahnen in den öffentlichen Baum. Beide Modi
     * lösen jetzt dasselbe Pferd auf; der Veröffentlichungsstatus entscheidet
     * nur noch über Verlinkung oder Platzhalter.
     */
    public function testPublishedOnlyZeigtPlatzhalterStattGleichnamigemPferdMitAndererUeln(): void {
        $name = 'Rune ' . uniqid();
        $x = 'DE000N73X' . random_int(1000, 9999);
        $runeX = $this->insertHorse(['name' => $name, 'ueln' => $x, 'is_published' => 0]);
        $runeY = $this->insertHorse(['name' => $name, 'ueln' => 'DE000N73Y' . random_int(1000, 9999), 'is_published' => 1]);
        $foalId = $this->insertHorse(['name' => 'N73-Fohlen-a', 'is_published' => 1, 'sire_name' => $name, 'sire_ueln' => $x]);

        $oeffentlich = PedigreeBuilder::build($foalId, 2, true);
        $this->assertNull($oeffentlich['sire']['id'], 'Der gleichnamige Hengst mit anderer UELN darf nicht eingesetzt werden');
        $this->assertNotSame($runeY, $oeffentlich['sire']['id']);
        $this->assertTrue($oeffentlich['sire']['is_placeholder']);
        $this->assertSame($x, $oeffentlich['sire']['ueln']);

        $admin = PedigreeBuilder::build($foalId, 2, false);
        $this->assertSame($runeX, $admin['sire']['id'], 'Der Admin-Baum verlinkt das Pferd mit der hinterlegten UELN');
    }

    /** Audit N73: Ein Namensvetter mit einer anderen UELN widerspricht der Angabe. */
    public function testNamensFallbackIgnoriertTrefferMitWidersprechenderUeln(): void {
        $name = 'Rune ' . uniqid();
        $this->insertHorse(['name' => $name, 'ueln' => 'DE000N73B' . random_int(1000, 9999), 'is_published' => 1]);
        $foalId = $this->insertHorse([
            'name' => 'N73-Fohlen-b', 'is_published' => 1,
            'sire_name' => $name, 'sire_ueln' => 'DE000N73Z' . random_int(1000, 9999),
        ]);

        foreach ([true, false] as $publishedOnly) {
            PedigreeBuilder::resetCache();
            $tree = PedigreeBuilder::build($foalId, 2, $publishedOnly);
            $this->assertNull($tree['sire']['id'], 'publishedOnly=' . var_export($publishedOnly, true));
            $this->assertTrue($tree['sire']['is_placeholder']);
            $this->assertSame($name, $tree['sire']['name']);
        }
    }

    /** Audit N73: Ohne eigene UELN widerspricht der Kandidat nicht - Verlinkung wie bisher. */
    public function testNamensFallbackGreiftBeiLeererUelnDesKandidaten(): void {
        $name = 'Rune ' . uniqid();
        $kandidat = $this->insertHorse(['name' => $name, 'ueln' => null, 'is_published' => 1]);
        $leer = $this->insertHorse(['name' => 'Leer ' . uniqid(), 'ueln' => '', 'is_published' => 1]);
        $foalId = $this->insertHorse([
            'name' => 'N73-Fohlen-c', 'is_published' => 1,
            'sire_name' => $name, 'sire_ueln' => 'DE000N73C' . random_int(1000, 9999),
        ]);

        foreach ([true, false] as $publishedOnly) {
            PedigreeBuilder::resetCache();
            $tree = PedigreeBuilder::build($foalId, 2, $publishedOnly);
            $this->assertSame($kandidat, $tree['sire']['id'], 'publishedOnly=' . var_export($publishedOnly, true));
            $this->assertArrayNotHasKey('is_placeholder', $tree['sire']);
        }

        // Auch eine leere Zeichenkette in ueln gilt als "keine UELN".
        self::$db->prepare('UPDATE horses SET name = ? WHERE id = ?')->execute([$name . ' leer', $leer]);
        $foal2 = $this->insertHorse([
            'name' => 'N73-Fohlen-c2', 'is_published' => 1,
            'dam_name' => $name . ' leer', 'dam_ueln' => 'DE000N73D' . random_int(1000, 9999),
        ]);
        PedigreeBuilder::resetCache();
        $this->assertSame($leer, PedigreeBuilder::build($foal2, 2, true)['dam']['id']);
    }

    /**
     * Audit N73: Ein mehrdeutiger Name wurde bisher aufs Geratewohl (ohne
     * ORDER BY) aufgelöst. Jetzt: Platzhalter in beiden Modi - auch wenn nur
     * einer der beiden Kandidaten veröffentlicht ist, sonst hinge das Ergebnis
     * vom Modus ab.
     */
    public function testMehrdeutigerNameErgibtPlatzhalter(): void {
        $name = 'Rune ' . uniqid();
        $this->insertHorse(['name' => $name, 'is_published' => 1]);
        $this->insertHorse(['name' => $name, 'is_published' => 0]);
        $foalId = $this->insertHorse(['name' => 'N73-Fohlen-d', 'is_published' => 1, 'dam_name' => $name]);

        foreach ([true, false] as $publishedOnly) {
            PedigreeBuilder::resetCache();
            $tree = PedigreeBuilder::build($foalId, 2, $publishedOnly);
            $this->assertNull($tree['dam']['id'], 'publishedOnly=' . var_export($publishedOnly, true));
            $this->assertTrue($tree['dam']['is_placeholder']);
            $this->assertSame($name, $tree['dam']['name']);
        }
    }

    /**
     * Audit N73: Mehrere Pferde mit derselben Nummer (ueln, foreign_ueln,
     * horse_registrations) - es gilt deterministisch die kleinste ID, wie
     * rememberSmallestId im Spiegel des Addons anpaarungs-empfehlung.
     */
    public function testUelnTrefferMitKleinsterIdGewinnt(): void {
        $nummer = 'DE000N73E' . random_int(1000, 9999);
        $erstes = $this->insertHorse(['name' => 'Nummer-A ' . uniqid(), 'is_published' => 1]);
        self::$db->prepare('INSERT INTO horse_registrations (horse_id, registration_number) VALUES (?, ?)')
            ->execute([$erstes, $nummer]);
        $this->insertHorse(['name' => 'Nummer-B ' . uniqid(), 'ueln' => $nummer, 'is_published' => 1]);
        $foalId = $this->insertHorse(['name' => 'N73-Fohlen-e', 'is_published' => 1, 'sire_ueln' => $nummer]);

        $this->assertSame($erstes, PedigreeBuilder::build($foalId, 2, true)['sire']['id']);
    }

    public function testUnknownHorseIdReturnsNull(): void {
        $this->assertNull(PedigreeBuilder::build(999999999, 4));
    }

    public function testNullHorseIdReturnsNull(): void {
        $this->assertNull(PedigreeBuilder::build(null, 4));
    }
}
