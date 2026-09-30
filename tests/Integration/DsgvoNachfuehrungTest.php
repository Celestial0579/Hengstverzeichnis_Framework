<?php
// tests/Integration/DsgvoNachfuehrungTest.php

namespace Tests\Integration;

use App\Helper\HorseImagePath;
use App\Service\AuditLogger;
use App\Service\MitgliedsstatusAltbestand;
use App\Service\SchemaMigrator;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\AltbestandV072;
use Tests\Support\WegwerfDatenbank;

/**
 * Der einmalige Update-Schritt dsgvo_nachfuehrung (SCHEMA_VERSION 28, Audit
 * M11, M23, N45, N17; Entscheidung D18: gezielt rückwirkend) auf einem
 * Bestand, der aus v0.7.2 migriert wurde und danach DSGVO-Aktionen im alten
 * Stil gesehen hat - also mit echten Alttabellen, contact_id_map und
 * Protokolleinträgen in den Altformaten.
 */
class DsgvoNachfuehrungTest extends TestCase {

    private static function db(): string {
        return WegwerfDatenbank::name('dsgvo_nachfuehrung');
    }

    private PDO $pdo;
    private string $bilder;
    /** Kontakt-ID der früheren Station 1 bzw. 2. */
    private int $station1;
    private int $station2;

    protected function setUp(): void {
        if (!defined('DB_HOST')) {
            $this->markTestSkipped('Keine Test-Datenbank konfiguriert (DB_HOST fehlt) - siehe tests/bootstrap.php.');
        }
        $this->bilder = sys_get_temp_dir() . '/' . uniqid('hengst_dsgvo_');
        HorseImagePath::overrideForTests($this->bilder . '/neu', $this->bilder . '/alt');
        HorseImagePath::overrideGalerieLegacyDirsForTests([]);
        SchemaMigrator::setzeSperreWartezeitFuerTests(-1);

        $this->pdo = AltbestandV072::anlegen(self::db());
        $this->pdo->exec("INSERT INTO persons (id, name, email, phone, membership_status) VALUES
            (1, 'Anna Alt', 'anna@example.com', '0170 1', 'Mitglied'),
            (2, 'Max Muster', 'max@example.com', NULL, 'Mitglied'),
            (3, 'Cora Dritte', NULL, NULL, 'Mitglied')");
        $this->pdo->exec("INSERT INTO breeding_stations (id, name, email) VALUES
            (1, 'Gestüt Nord', 'nord@example.com'), (2, 'Hof Süd', NULL)");
        $this->pdo->exec("INSERT INTO horses (id, name, breeding_station_id, breeding_station) VALUES
            (10, 'Hengst A', 1, 'Gestüt Nord'), (11, 'Stute B', NULL, NULL), (12, 'Stute C', NULL, 'Gestüt Nord')");
        $this->pdo->exec("INSERT INTO audit_logs (username, action, category, details) VALUES
            ('alt', 'Person angelegt', 'persons', 'Person ID 1: Anna Alt'),
            ('alt', 'Person angelegt', 'persons', 'Person ID 3: Cora Dritte'),
            ('alt', 'Deckstation angelegt', 'breeding_stations', 'Deckstation ID 1: Gestüt Nord'),
            ('alt', 'Deckstation angelegt', 'breeding_stations', 'Deckstation ID 2: Hof Süd')");
        SchemaMigrator::run($this->pdo);
        $this->assertSame(SchemaMigrator::SCHEMA_VERSION, SchemaMigrator::storedVersion($this->pdo));

        $this->station1 = (int)$this->wert("SELECT contact_id FROM contact_id_map WHERE old_type = 'station' AND old_id = 1");
        $this->station2 = (int)$this->wert("SELECT contact_id FROM contact_id_map WHERE old_type = 'station' AND old_id = 2");
        $this->alteDsgvoAktionen();
    }

    protected function tearDown(): void {
        HorseImagePath::overrideForTests(null, null);
        HorseImagePath::overrideGalerieLegacyDirsForTests(null);
        @rmdir($this->bilder . '/neu');
        @rmdir($this->bilder);
        if (defined('DB_HOST')) {
            AltbestandV072::entfernen(self::db());
        }
    }

    private function wert(string $sql, array $werte = []): mixed {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($werte);
        return $stmt->fetchColumn();
    }

    private function protokoll(string $kategorie, string $details, string $aktion = 'Test'): int {
        $this->pdo->prepare("INSERT INTO audit_logs (username, action, category, details) VALUES ('alt', ?, ?, ?)")
            ->execute([$aktion, $kategorie, $details]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Was die Kerne vor dem Fix hinterließen:
     *  - Kontakt 1 anonymisiert (nur contacts), mit Kopie am Pferd, Dubletten-
     *    Notiz und Klarnamen im Protokoll,
     *  - Station 1 per DSGVO gelöscht (Karte per CASCADE weg, Kopie an Pferd
     *    12 neben einer anderen Station),
     *  - Station 2 und Kontakt 2 gewöhnlich aus dem Papierkorb gelöscht,
     *  - Kontakt 3 unberührt,
     *  - Mail-Protokoll mit Code und Adresse.
     */
    private function alteDsgvoAktionen(): void {
        $s1 = $this->station1;
        $this->protokoll('contacts', 'Kontakt ID 1: Anna Neu', 'Kontakt aktualisiert');
        $this->protokoll('contacts', "Kontakt ID {$s1}: Gestüt Nord", 'Kontakt aktualisiert');
        $this->protokoll('contacts', 'Kontakt ID 3: Cora Dritte', 'Kontakt aktualisiert');
        $this->pdo->exec("UPDATE horses SET breeding_station_id = 1, breeding_station = 'Anna Neu' WHERE id = 11");
        $this->pdo->exec("UPDATE horses SET breeding_station_id = 3 WHERE id = 12");
        $this->pdo->exec("INSERT INTO match_labels (kind, left_id, right_id, label, note) VALUES ('contact', 1, 3, 'different', 'Anna ist Coras Schwester')");
        $this->protokoll('matches', 'contact 1/3: different (Anna ist Coras Schwester)', 'Dubletten-Entscheidung gesetzt');

        $this->pdo->exec("UPDATE contacts SET name = 'Anonymisierte Person (#1)', email = NULL, phone = NULL WHERE id = 1");
        $this->protokoll('gdpr', 'Person ID 1, Anfrage ID 4', 'DSGVO: Person anonymisiert');

        $this->pdo->exec("DELETE FROM contacts WHERE id = {$s1}");
        $this->protokoll('gdpr', "Person ID {$s1}", 'DSGVO: Person endgültig gelöscht');

        $this->pdo->exec("DELETE FROM contacts WHERE id IN (2, {$this->station2})");
        $this->protokoll('trash', 'Typ: contact, ID: 2', 'Element endgültig gelöscht');

        $this->protokoll('email', 'Empfänger: max@verein.de, Betreff: Anmeldecode 483920 - Verband', 'E-Mail versendet (SMTP)');
    }

    private function zurueckAufVersion26(): void {
        $this->pdo->exec("UPDATE settings SET setting_value = '26' WHERE setting_key = 'schema_version'");
        $this->pdo->exec("DELETE FROM settings WHERE setting_key = 'migration_dsgvo_nachfuehrung'");
    }

    /** @return array<string, mixed> Momentaufnahme aller betroffenen Tabellen. */
    private function stand(): array {
        $stand = [];
        foreach (['contacts', 'persons_pre_contacts', 'breeding_stations_pre_contacts', 'horses', 'horse_persons', 'match_labels', 'audit_logs'] as $t) {
            $stand[$t] = $this->pdo->query("SELECT * FROM `{$t}` ORDER BY 1, 2")->fetchAll(PDO::FETCH_ASSOC);
        }
        return $stand;
    }

    public function testNachfuehrungBereinigtGezieltUndIstIdempotent(): void {
        $offenVorher = MitgliedsstatusAltbestand::offen($this->pdo);
        $this->zurueckAufVersion26();

        $schritte = SchemaMigrator::run($this->pdo);

        $this->assertSame(SchemaMigrator::SCHEMA_VERSION, SchemaMigrator::storedVersion($this->pdo));
        $this->assertGreaterThanOrEqual(28, SchemaMigrator::SCHEMA_VERSION, 'Der Schritt kam mit 28; spätere Pakete erhöhen weiter');
        $this->assertNotNull(AltbestandV072::einstellung($this->pdo, 'migration_dsgvo_nachfuehrung'));
        $alle = implode("\n", $schritte);
        $this->assertStringContainsString('2 früher anonymisierte bzw. gelöschte Kontakt(e) nachgezogen', $alle);
        $this->assertStringContainsString('Altkopie(n) (#336) endgültig gelöschter Kontakte entfernt', $alle);
        $this->assertStringContainsString('1 Eintrag/Einträgen des Mail-Protokolls maskiert', $alle);

        // Anonymisierter Kontakt 1: Kopie am Pferd, Notiz, Altkopie, Protokoll.
        $this->assertSame('Anonymisierte Person (#1)', $this->wert('SELECT breeding_station FROM horses WHERE id = 11'));
        $this->assertNull($this->wert("SELECT note FROM match_labels WHERE kind = 'contact' AND left_id = 1"));
        $alt1 = $this->pdo->query('SELECT * FROM persons_pre_contacts WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($alt1, 'id bleibt stehen');
        $this->assertSame('Anonymisierte Person (#1)', $alt1['name']);
        $this->assertNull($alt1['email']);
        $this->assertNull($alt1['membership_status'], 'Der Status eines DSGVO-Falls wird genullt (Audit N78)');

        // Per DSGVO gelöschte Station 1: Altkopie weg, Merge-Rest am Pferd 12
        // zeigt die verknüpfte Station, Protokoll pseudonymisiert. Die Kopie an
        // Pferd 10 (Verknüpfung schon NULL) bleibt - nicht von Freitext zu
        // unterscheiden, der CHANGELOG nennt eine Prüfabfrage.
        $this->assertSame(0, (int)$this->wert('SELECT COUNT(*) FROM breeding_stations_pre_contacts WHERE id = 1'));
        $this->assertSame('Cora Dritte', $this->wert('SELECT breeding_station FROM horses WHERE id = 12'));
        $this->assertSame('Gestüt Nord', $this->wert('SELECT breeding_station FROM horses WHERE id = 10'));

        foreach (['Anna Alt', 'Anna Neu', 'Anna ist Coras', 'Gestüt Nord', 'max@verein.de', '483920'] as $weg) {
            $this->assertSame(
                0,
                (int)$this->wert('SELECT COUNT(*) FROM audit_logs WHERE details LIKE ?', ['%' . $weg . '%']),
                "'{$weg}' steht noch im Protokoll"
            );
        }
        $this->assertSame(1, (int)$this->wert('SELECT COUNT(*) FROM audit_logs WHERE details = ?', ['Person ID 1: ' . AuditLogger::ENTFERNT]));
        $this->assertSame(1, (int)$this->wert('SELECT COUNT(*) FROM audit_logs WHERE details = ?', ['Deckstation ID 1: ' . AuditLogger::ENTFERNT]));

        // Gewöhnlich gelöschte Kontakte: Altkopien entfernt, Protokoll bleibt.
        $this->assertSame(0, (int)$this->wert('SELECT COUNT(*) FROM persons_pre_contacts WHERE id = 2'));
        $this->assertSame(0, (int)$this->wert('SELECT COUNT(*) FROM breeding_stations_pre_contacts WHERE id = 2'));
        $this->assertSame(1, (int)$this->wert("SELECT COUNT(*) FROM audit_logs WHERE details = 'Deckstation ID 2: Hof Süd'"));

        // Unberührt: Kontakt 3 samt Altkopie und Mitgliedsstatus (Vertrag N78).
        $this->assertSame('Mitglied', $this->wert('SELECT membership_status FROM persons_pre_contacts WHERE id = 3'));
        $this->assertSame('Cora Dritte', $this->wert('SELECT name FROM persons_pre_contacts WHERE id = 3'));
        $this->assertSame(1, (int)$this->wert("SELECT COUNT(*) FROM audit_logs WHERE details = 'Kontakt ID 3: Cora Dritte'"));
        $this->assertSame(1, (int)$this->wert("SELECT COUNT(*) FROM audit_logs WHERE details = 'Person ID 3: Cora Dritte'"));
        $this->assertSame($offenVorher, MitgliedsstatusAltbestand::offen($this->pdo), 'Was das Addon noch übernehmen kann, bleibt');
        $this->assertSame(1, $offenVorher);

        // Zweiter Lauf: nichts mehr zu tun.
        $vorher = $this->stand();
        $this->zurueckAufVersion26();
        $zweiter = SchemaMigrator::run($this->pdo);
        $this->assertSame(['settings.schema_version auf ' . SchemaMigrator::SCHEMA_VERSION . ' gesetzt (vorher 26)'], $zweiter);
        $this->assertEquals($vorher, $this->stand());
    }

    public function testFehlerMittenImSchrittHinterlaesstKeineTeilaenderung(): void {
        $this->pdo->exec(
            "CREATE TRIGGER dsgvo_test_sperre BEFORE DELETE ON breeding_stations_pre_contacts
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Testsperre'"
        );
        $this->zurueckAufVersion26();
        $vorher = $this->stand();

        try {
            SchemaMigrator::run($this->pdo);
            $this->fail('Der Schritt hätte werfen müssen.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('dsgvo_nachfuehrung', $e->getMessage());
        }

        $this->assertEquals($vorher, $this->stand(), 'Rollback: keine Teiländerung');
        $this->assertNull(AltbestandV072::einstellung($this->pdo, 'migration_dsgvo_nachfuehrung'));
        $this->assertSame(26, SchemaMigrator::storedVersion($this->pdo));
    }
}
