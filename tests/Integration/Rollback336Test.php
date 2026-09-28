<?php
// tests/Integration/Rollback336Test.php

namespace Tests\Integration;

use App\Helper\HorseImagePath;
use App\Service\Maintenance;
use App\Service\SchemaMigrator;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\AltbestandV072;
use Tests\Support\WegwerfDatenbank;

/**
 * Der Rückweg database/rollback-336.php (Audit N39, N40), als echter
 * CLI-Aufruf gegen eine Wegwerf-Datenbank, die aus v0.7.2 migriert wurde.
 *
 * N39: Ohne Wartungsmarker machte der nächste Request den Rückweg sofort
 * rückgängig. N40: Zuordnungen "über Kreuz" (Stationskontakt als Besitzer,
 * Personenkontakt als Deckstation) gingen still verloren.
 *
 * ACHTUNG: Das Skript setzt den Marker im echten var/ des Checkouts - der
 * tearDown entfernt ihn unbedingt, sonst liefe die Functional-Suite in 503.
 */
class Rollback336Test extends TestCase {

    private static function db(): string {
        return WegwerfDatenbank::name('rollback_336');
    }

    private PDO $pdo;
    private string $bilder;

    protected function setUp(): void {
        if (!defined('DB_HOST')) {
            $this->markTestSkipped('Keine Test-Datenbank konfiguriert (DB_HOST fehlt) - siehe tests/bootstrap.php.');
        }
        if (Maintenance::isActive()) {
            $this->markTestSkipped('var/wartung.lock liegt bereits - fremden Marker nicht überschreiben.');
        }
        $this->bilder = sys_get_temp_dir() . '/' . uniqid('hengst_rb336_');
        HorseImagePath::overrideForTests($this->bilder . '/neu', $this->bilder . '/alt');
        HorseImagePath::overrideGalerieLegacyDirsForTests([]);

        $this->pdo = AltbestandV072::anlegen(self::db());
        $this->pdo->exec("INSERT INTO persons (id, name) VALUES (1, 'Anna'), (2, 'Max')");
        $this->pdo->exec("INSERT INTO breeding_stations (id, name) VALUES (1, 'Gestüt Nord')");
        $this->pdo->exec("INSERT INTO horses (id, name, breeding_station_id) VALUES (10, 'Stute B', 1), (11, 'Hengst C', NULL)");
        $this->pdo->exec("INSERT INTO horse_persons (horse_id, person_id, role) VALUES (10, 1, 'owner')");
        SchemaMigrator::run($this->pdo);
        $this->assertSame(SchemaMigrator::SCHEMA_VERSION, SchemaMigrator::storedVersion($this->pdo));
    }

    protected function tearDown(): void {
        try {
            Maintenance::disable();
            HorseImagePath::overrideForTests(null, null);
            HorseImagePath::overrideGalerieLegacyDirsForTests(null);
            @rmdir($this->bilder . '/neu');
            @rmdir($this->bilder);
        } finally {
            if (defined('DB_HOST')) {
                AltbestandV072::entfernen(self::db());
            }
        }
    }

    private function stationskontakt(): int {
        return (int)$this->pdo->query("SELECT contact_id FROM contact_id_map WHERE old_type = 'station' AND old_id = 1")->fetchColumn();
    }

    /** Zuordnungen, die v0.7 nicht abbilden kann. */
    private function ueberKreuz(): void {
        $station = $this->stationskontakt();
        // A: Kontakt mit Stationsherkunft als Besitzer.
        $this->pdo->exec("INSERT INTO horse_persons (horse_id, contact_id, role) VALUES (11, {$station}, 'owner')");
        // B: Kontakt mit Personenherkunft als Deckstation einer Züchterzeile.
        $this->pdo->exec("INSERT INTO horse_persons (horse_id, role, station_contact_id) VALUES (11, 'breeder', 2)");
        // Pferd mit Personenkontakt als Deckstation.
        $this->pdo->exec("UPDATE horses SET breeding_station_id = 1 WHERE id = 11");
    }

    /** @return array{0: int, 1: string} */
    private function rueckweg(string ...$argumente): array {
        return SchemaMigratorLaufTest::cli('database/rollback-336.php', $argumente, self::db());
    }

    public function testNurPruefenZaehltZuordnungenOhneRueckschreibziel(): void {
        $this->ueberKreuz();
        [$code, $aus] = $this->rueckweg();
        $this->assertSame(0, $code, $aus);
        $this->assertStringContainsString('Zuordnungen ohne Rückschreibziel: 1/1/1', $aus);
        $this->assertTrue(AltbestandV072::tabelleExistiert($this->pdo, 'contacts'));
        $this->assertFalse(Maintenance::isActive());
    }

    public function testOhneVerwerfenBrichtDerRueckwegVorJederAenderungAb(): void {
        $this->ueberKreuz();
        [$code, $aus] = $this->rueckweg('--ich-weiss');
        $this->assertSame(1, $code, $aus);
        $this->assertStringContainsString('--zuordnungen-verwerfen', $aus);
        $this->assertTrue(AltbestandV072::tabelleExistiert($this->pdo, 'contacts'));
        $this->assertTrue(AltbestandV072::spalteExistiert($this->pdo, 'horse_persons', 'contact_id'));
        $this->assertFalse(Maintenance::isActive(), 'Vor jeder Änderung: auch kein Wartungsmodus');
    }

    public function testRueckwegSperrtDieMigrationBisDerMarkerFaellt(): void {
        $this->ueberKreuz();
        [$code, $aus] = $this->rueckweg('--ich-weiss', '--zuordnungen-verwerfen');
        $this->assertSame(0, $code, $aus);
        $this->assertStringContainsString('Verworfene Zuordnungen ohne Rückschreibziel: 3', $aus);
        $this->assertStringContainsString('Wartungsmodus bleibt AKTIV', $aus);

        $this->assertTrue(AltbestandV072::tabelleExistiert($this->pdo, 'persons'));
        $this->assertTrue(AltbestandV072::tabelleExistiert($this->pdo, 'breeding_stations'));
        $this->assertFalse(AltbestandV072::tabelleExistiert($this->pdo, 'contacts'));
        $this->assertSame(9, SchemaMigrator::storedVersion($this->pdo));
        $this->assertSame(1, (int)$this->pdo->query("SELECT breeding_station_id FROM horses WHERE id = 10")->fetchColumn());
        $this->assertNull(AltbestandV072::einstellung($this->pdo, 'migration_336_rueckweg_pferde'));

        $info = Maintenance::info();
        $this->assertNotNull($info);
        $this->assertNull($info['pid'], 'Ohne pid verfällt der Marker nie');
        $this->assertTrue($info['migration_gesperrt']);
        $this->assertFalse(Maintenance::isStale());

        try {
            SchemaMigrator::run($this->pdo);
            $this->fail('Solange der Marker liegt, darf #336 nicht erneut laufen.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('gesperrt', $e->getMessage());
        }
    }

    public function testNachDemMarkerHebtDieMigrationErneut(): void {
        [$code, $aus] = $this->rueckweg('--ich-weiss');
        $this->assertSame(0, $code, $aus);
        $this->assertTrue(Maintenance::sperrtMigration());

        Maintenance::disable();
        SchemaMigrator::run($this->pdo);
        $this->assertSame(SchemaMigrator::SCHEMA_VERSION, SchemaMigrator::storedVersion($this->pdo));
        $this->assertTrue(AltbestandV072::tabelleExistiert($this->pdo, 'persons_pre_contacts'));
    }

    public function testPferdeWerdenBeiErneutemAufrufNichtDoppeltUmgerechnet(): void {
        // Zustand nach einem abgebrochenen Vorlauf: Schritt 3 lief schon.
        $this->pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('migration_336_rueckweg_pferde', 'x')");
        $this->pdo->exec("UPDATE horses SET breeding_station_id = 1 WHERE id = 10"); // alte Stations-ID, zugleich Person 1

        [$code, $aus] = $this->rueckweg('--ich-weiss');

        $this->assertSame(0, $code, $aus);
        $this->assertSame(1, (int)$this->pdo->query("SELECT breeding_station_id FROM horses WHERE id = 10")->fetchColumn());
    }

    public function testFremderFremdschluesselAufContactsBrichtVorabAb(): void {
        $this->pdo->exec("CREATE TABLE `plugin_test_ref` (`contact_id` INT NULL, FOREIGN KEY (`contact_id`) REFERENCES `contacts`(`id`))
                          ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        [$code, $aus] = $this->rueckweg('--ich-weiss', '--zuordnungen-verwerfen');

        $this->assertSame(1, $code, $aus);
        $this->assertStringContainsString('plugin_test_ref', $aus);
        $this->assertTrue(AltbestandV072::spalteExistiert($this->pdo, 'horse_persons', 'contact_id'));
        $this->assertTrue(AltbestandV072::tabelleExistiert($this->pdo, 'contact_id_map'));
        $this->assertFalse(Maintenance::isActive());
    }
}
