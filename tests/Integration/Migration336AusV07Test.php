<?php
// tests/Integration/Migration336AusV07Test.php

namespace Tests\Integration;

use App\Helper\HorseImagePath;
use App\Service\SchemaMigrator;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\AltbestandV072;
use Tests\Support\WegwerfDatenbank;

/**
 * Die #336-Umstellung aus einem echten v0.7.2-Bestand (Audit M41, M42).
 *
 * M41: Unter ONLY_FULL_GROUP_BY (MySQL-8-Voreinstellung) scheiterte die
 * Dublettenabfrage NACH dem Commit der Übernahme - Daten festgeschrieben,
 * Marker nicht, jeder weitere Lauf warf. M42: Verwaiste
 * Deckstationsverweise aus v0.7 ließen den Umhänge-Schritt dauerhaft werfen.
 * Beide Hänger heilen sich jetzt selbst, und die Folgeschäden aus der
 * Hängezeit (Spiegel des neuen Codes, neue API-Schlüssel) werden nicht
 * verschlimmert.
 *
 * Jeder Test baut sich eine eigene Wegwerf-Datenbank aus der Fixture.
 */
class Migration336AusV07Test extends TestCase {

    private static function db(): string {
        return WegwerfDatenbank::name('migration_336');
    }

    private string $bilder;

    protected function setUp(): void {
        if (!defined('DB_HOST')) {
            $this->markTestSkipped('Keine Test-Datenbank konfiguriert (DB_HOST fehlt) - siehe tests/bootstrap.php.');
        }
        // #366/#339 dürfen nicht in die echte Ablage des Checkouts greifen.
        $this->bilder = sys_get_temp_dir() . '/' . uniqid('hengst_m336_');
        HorseImagePath::overrideForTests($this->bilder . '/neu', $this->bilder . '/alt');
        HorseImagePath::overrideGalerieLegacyDirsForTests([]);
        SchemaMigrator::setzeSperreWartezeitFuerTests(-1);
    }

    protected function tearDown(): void {
        HorseImagePath::overrideForTests(null, null);
        HorseImagePath::overrideGalerieLegacyDirsForTests(null);
        if (is_dir($this->bilder . '/neu')) {
            @rmdir($this->bilder . '/neu');
        }
        @rmdir($this->bilder);
        if (defined('DB_HOST')) {
            AltbestandV072::entfernen(self::db());
        }
    }

    private static function stationKontakt(PDO $pdo, int $alteId): int {
        $stmt = $pdo->prepare("SELECT contact_id FROM contact_id_map WHERE old_type = 'station' AND old_id = ?");
        $stmt->execute([$alteId]);
        return (int)$stmt->fetchColumn();
    }

    private static function enthaelt(array $schritte, string $text): bool {
        foreach ($schritte as $z) {
            if (str_contains((string)$z, $text)) {
                return true;
            }
        }
        return false;
    }

    // ---------------------------------------------------------------- M41

    public function testUebernahmeLaeuftUnterOnlyFullGroupBy(): void {
        $pdo = AltbestandV072::anlegen(self::db(), 'ONLY_FULL_GROUP_BY');
        $pdo->exec("INSERT INTO persons (id, name) VALUES (1, 'Hans Muster'), (2, ' hans muster')");
        $pdo->exec("INSERT INTO breeding_stations (id, name) VALUES (1, 'Station Eins')");
        $pdo->exec("INSERT INTO horses (id, name, breeding_station_id) VALUES (10, 'Hengst A', 1)");
        $pdo->exec("INSERT INTO horse_persons (horse_id, person_id, role) VALUES (10, 1, 'owner')");

        $schritte = SchemaMigrator::run($pdo);

        $this->assertSame(SchemaMigrator::SCHEMA_VERSION, SchemaMigrator::storedVersion($pdo));
        $this->assertNotNull(AltbestandV072::einstellung($pdo, 'migration_336_contacts_uebernahme'));
        $this->assertTrue(self::enthaelt($schritte, 'namensgleiche Kontaktpaare'), implode("\n", $schritte));
        $this->assertFalse(AltbestandV072::spalteExistiert($pdo, 'horse_persons', 'person_id'));
        $this->assertSame(1, (int)$pdo->query("SELECT contact_id FROM horse_persons WHERE horse_id = 10")->fetchColumn());
        $this->assertNull(SchemaMigrator::status($pdo));
    }

    public function testHaengendeUebernahmeHeiltSichOhneVerdopplung(): void {
        $pdo = AltbestandV072::anlegen(self::db());
        $pdo->exec("INSERT INTO persons (id, name) VALUES (1, 'Anna'), (2, 'Bert')");
        $pdo->exec("INSERT INTO breeding_stations (id, name) VALUES (1, 'Gestüt Nord')");
        $pdo->exec("INSERT INTO horses (id, name, breeding_station_id) VALUES (10, 'Hengst A', 1)");
        $pdo->exec("INSERT INTO horse_persons (horse_id, person_id, role) VALUES (10, 2, 'owner')");
        // Zustand, den der Kern bis zum Fix unter MySQL 8 hinterließ.
        $zuordnung = AltbestandV072::haengezustand($pdo, false);
        $vorher = (int)$pdo->query("SELECT COUNT(*) FROM contacts")->fetchColumn();

        $schritte = SchemaMigrator::run($pdo);

        $this->assertTrue(self::enthaelt($schritte, 'Marker nachgetragen'), implode("\n", $schritte));
        $this->assertSame($vorher, (int)$pdo->query("SELECT COUNT(*) FROM contacts")->fetchColumn());
        $this->assertSame(SchemaMigrator::SCHEMA_VERSION, SchemaMigrator::storedVersion($pdo));
        $this->assertSame($zuordnung[1], (int)$pdo->query("SELECT breeding_station_id FROM horses WHERE id = 10")->fetchColumn());
        $this->assertSame(2, (int)$pdo->query("SELECT contact_id FROM horse_persons WHERE horse_id = 10")->fetchColumn());
    }

    public function testUnbelegteKontakteWerfenMitStatusUndDrosselung(): void {
        $pdo = AltbestandV072::anlegen(self::db());
        $pdo->exec("INSERT INTO persons (id, name) VALUES (1, 'Anna')");
        $pdo->exec("CREATE TABLE `contacts` (`id` INT AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(150) NOT NULL)
                    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("INSERT INTO contacts (name) VALUES ('Fremd')");

        try {
            SchemaMigrator::run($pdo);
            $this->fail('Ohne Beleg für eine frühere Übernahme darf nichts kopiert werden.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('contacts enthält bereits', $e->getMessage());
        }

        $status = SchemaMigrator::status($pdo);
        $this->assertNotNull($status);
        $this->assertSame('fehler', $status['zustand']);
        $this->assertSame(9, $status['von']);
        $this->assertSame(SchemaMigrator::SCHEMA_VERSION, $status['ziel']);
        $this->assertStringContainsString('336_contacts_uebernahme', implode(' ', $status['meldungen']));
        $this->assertSame(9, SchemaMigrator::storedVersion($pdo));

        // Der implizite Weg wartet die Frist ab, statt bei jedem Request zu werfen.
        $this->assertSame([], SchemaMigrator::run($pdo, true));
        // Ein expliziter Aufruf läuft sofort und scheitert erneut.
        $this->expectException(\RuntimeException::class);
        SchemaMigrator::run($pdo);
    }

    // ---------------------------------------------------------------- M42

    public function testVerwaisterStationsverweisWirdGeleertStattZuHaengen(): void {
        $pdo = AltbestandV072::anlegen(self::db());
        $pdo->exec("INSERT INTO persons (id, name) VALUES (1, 'Hans Privat'), (2, 'Max Mustermann'), (3, 'Eva')");
        $pdo->exec("INSERT INTO breeding_stations (id, name) VALUES (1, 'Station A'), (2, 'Station B'), (3, 'Station C')");
        $pdo->exec("INSERT INTO horses (id, name, breeding_station_id) VALUES (10, 'Hengst A', 3), (11, 'Hengst B', 1)");
        $pdo->exec("INSERT INTO horse_persons (horse_id, role, breeding_station_id, breeding_station_text)
                    VALUES (10, 'breeder', 2, 'Alt')");
        // v0.7: kein Fremdschlüssel auf horse_persons.breeding_station_id,
        // der Papierkorb löschte endgültig.
        $pdo->exec("DELETE FROM breeding_stations WHERE id = 2");

        $schritte = SchemaMigrator::run($pdo);

        $this->assertSame(SchemaMigrator::SCHEMA_VERSION, SchemaMigrator::storedVersion($pdo), implode("\n", $schritte));
        $zeile = $pdo->query("SELECT station_contact_id, breeding_station_text FROM horse_persons WHERE horse_id = 10")->fetch();
        $this->assertNull($zeile['station_contact_id']);
        $this->assertSame('Alt', $zeile['breeding_station_text'], 'Der Freitext bleibt unangetastet');
        $this->assertTrue(self::enthaelt($schritte, '1 Deckstations'), implode("\n", $schritte));
        $this->assertTrue(self::enthaelt($schritte, '1 Altdatensatz gelöscht'), implode("\n", $schritte));

        $this->assertSame(self::stationKontakt($pdo, 3), (int)$pdo->query("SELECT breeding_station_id FROM horses WHERE id = 10")->fetchColumn());
        $this->assertSame(self::stationKontakt($pdo, 1), (int)$pdo->query("SELECT breeding_station_id FROM horses WHERE id = 11")->fetchColumn());

        foreach (['editor', 'public'] as $gruppe) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM group_permissions p JOIN `groups` g ON g.id = p.group_id
                                   WHERE g.slug = ? AND p.module = 'contacts' AND p.action = 'view'");
            $stmt->execute([$gruppe]);
            $this->assertSame(1, (int)$stmt->fetchColumn(), "contacts.view für {$gruppe}");
        }
        $this->assertTrue(AltbestandV072::tabelleExistiert($pdo, 'match_labels'));
        $this->assertTrue(AltbestandV072::tabelleExistiert($pdo, 'persons_pre_contacts'));
    }

    public function testInDerHaengezeitGeloeschterKontaktHaeltDieMigrationNichtAuf(): void {
        $pdo = AltbestandV072::anlegen(self::db());
        $pdo->exec("INSERT INTO persons (id, name) VALUES (1, 'Anna')");
        $pdo->exec("INSERT INTO breeding_stations (id, name) VALUES (1, 'Station A'), (2, 'Station B')");
        $pdo->exec("INSERT INTO horses (id, name) VALUES (10, 'Hengst A')");
        $pdo->exec("INSERT INTO horse_persons (horse_id, role, breeding_station_id) VALUES (10, 'breeder', 1)");
        $zuordnung = AltbestandV072::haengezustand($pdo, true);
        // Papierkorb/30-Tage-Bereinigung in der Hängezeit: Die Map-Zeile fällt
        // per CASCADE, die Altzeile breeding_stations 1 bleibt.
        $pdo->exec("DELETE FROM contacts WHERE id = " . $zuordnung[1]);

        $schritte = SchemaMigrator::run($pdo);

        $this->assertSame(SchemaMigrator::SCHEMA_VERSION, SchemaMigrator::storedVersion($pdo), implode("\n", $schritte));
        $this->assertNull($pdo->query("SELECT station_contact_id FROM horse_persons WHERE horse_id = 10")->fetchColumn());
        $this->assertTrue(self::enthaelt($schritte, '1 Kontakt seit dem Update gelöscht'), implode("\n", $schritte));
    }

    public function testSpiegelDesNeuenCodesWirdNichtUmgeschluesseltUndVerdachtGemeldet(): void {
        $pdo = AltbestandV072::anlegen(self::db());
        $pdo->exec("INSERT INTO persons (id, name) VALUES (1, 'P1'), (2, 'P2'), (3, 'P3'), (4, 'P4'), (5, 'P5')");
        $pdo->exec("INSERT INTO breeding_stations (id, name) VALUES (1, 'S1'), (2, 'S2'), (3, 'S3')");
        $pdo->exec("INSERT INTO horses (id, name, updated_at) VALUES
                    (20, 'Neu gespeichert', NOW()), (21, 'Formular', NOW()), (22, 'Alt', NOW() - INTERVAL 2 DAY)");
        $zuordnung = AltbestandV072::haengezustand($pdo, true);
        $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('migration_336_horse_persons_umhaengen', 'x')");

        // Pferd 20: Der neue Code hat Kontakt 3 (Person P3 - zugleich eine
        // alte Stations-ID) als Deckstation gespeichert, samt horse_persons-
        // Zeile, die nur den neuen Steckplatz trägt.
        $pdo->exec("INSERT INTO horse_persons (horse_id, role, station_contact_id) VALUES (20, 'breeder', 3)");
        $pdo->exec("UPDATE horses SET breeding_station_id = 3, updated_at = NOW() WHERE id = 20");
        // Pferd 21: in der Hängezeit direkt über das Formular gespeichert.
        $pdo->exec("UPDATE horses SET breeding_station_id = 1, updated_at = NOW() WHERE id = 21");
        // Pferd 22: Altbestand, vor der Übernahme zuletzt bearbeitet.
        $pdo->exec("UPDATE horses SET breeding_station_id = 2, updated_at = NOW() - INTERVAL 2 DAY WHERE id = 22");

        $schritte = SchemaMigrator::run($pdo);

        $this->assertSame(SchemaMigrator::SCHEMA_VERSION, SchemaMigrator::storedVersion($pdo), implode("\n", $schritte));
        $this->assertSame(3, (int)$pdo->query("SELECT breeding_station_id FROM horses WHERE id = 20")->fetchColumn(),
            'Ein Spiegel des neuen Codes darf nicht als alte Stations-ID gelesen werden');
        $this->assertSame($zuordnung[2], (int)$pdo->query("SELECT breeding_station_id FROM horses WHERE id = 22")->fetchColumn());

        $meldung = implode("\n", array_filter($schritte, static fn($z) => str_contains((string)$z, 'Deckstation bitte prüfen')));
        $this->assertStringContainsString('#21', $meldung, implode("\n", $schritte));
        $this->assertStringNotContainsString('#22', $meldung);
    }

    public function testNichtZuordenbareAltIdTrifftKeinenPersonenkontakt(): void {
        $pdo = AltbestandV072::anlegen(self::db());
        $pdo->exec("INSERT INTO persons (id, name) VALUES (1, 'P1'), (2, 'Hans Privat'), (3, 'P3')");
        $pdo->exec("INSERT INTO breeding_stations (id, name) VALUES (1, 'S1'), (2, 'S2')");
        $pdo->exec("INSERT INTO horses (id, name, breeding_station_id, updated_at) VALUES (30, 'Hengst', 2, NOW() - INTERVAL 2 DAY)");
        $zuordnung = AltbestandV072::haengezustand($pdo, true);
        // Station 2 hat keinen Map-Eintrag mehr (Kontakt in der Hängezeit
        // gelöscht) - Person 2 hat aber genau diese ID.
        $pdo->exec("DELETE FROM contacts WHERE id = " . $zuordnung[2]);

        SchemaMigrator::run($pdo);

        $this->assertSame(SchemaMigrator::SCHEMA_VERSION, SchemaMigrator::storedVersion($pdo));
        $this->assertNull(
            $pdo->query("SELECT breeding_station_id FROM horses WHERE id = 30")->fetchColumn(),
            'Die alte Stations-ID 2 darf nicht als Personenkontakt 2 stehen bleiben'
        );
    }

    public function testNurBestandsschluesselLaufenAb(): void {
        $pdo = AltbestandV072::anlegen(self::db());
        $pdo->exec("INSERT INTO users (id, username, email, password_hash) VALUES (1, 'admin', 'a@example.com', 'x')");
        // Hängezeit: Die Spalte kam, 340 lief nie. Der Bestandsschlüssel
        // trägt den ALTER-Zeitpunkt, der neue ein ausdrückliches Ablaufdatum.
        $pdo->exec("ALTER TABLE api_keys ADD COLUMN expires_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        $pdo->exec("INSERT INTO api_keys (id, user_id, label, token_hash, token_prefix, created_at, expires_at) VALUES
                    (1, 1, 'Bestand', REPEAT('a', 64), 'hv_a', NOW() - INTERVAL 300 DAY, NOW() + INTERVAL 1 HOUR),
                    (2, 1, 'Neu', REPEAT('b', 64), 'hv_b', NOW() - INTERVAL 1 DAY, NOW() + INTERVAL 30 DAY)");

        SchemaMigrator::run($pdo);

        $this->assertSame(1, (int)$pdo->query("SELECT expires_at = created_at FROM api_keys WHERE id = 1")->fetchColumn());
        $this->assertSame(1, (int)$pdo->query("SELECT expires_at > NOW() + INTERVAL 29 DAY FROM api_keys WHERE id = 2")->fetchColumn());
    }
}
