<?php
// tests/Functional/GdprAltbestandTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Altkopien aus der Kontaktlisten-Umstellung (#336) in der DSGVO-Bearbeitung
 * (Audit M23).
 *
 * `persons_pre_contacts` und `breeding_stations_pre_contacts` behielten Name,
 * Anschrift, E-Mail und Telefon. Keine DSGVO-Aktion erreichte sie, jede
 * Sicherung trug sie weiter, und der Rückweg hätte sie zurückgeholt. Jetzt
 * werden sie mitgelöscht bzw. mitanonymisiert und bei Auskunftsanfragen
 * angezeigt.
 *
 * Die Alttabellen legt setUp() im v0.7-Schnitt an (mit membership_status und
 * einer NOT-NULL-Textspalte); tearDown() entfernt sie wieder - die Datenbank
 * ist geteilter Zustand der ganzen Suite.
 */
class GdprAltbestandTest extends FunctionalTestCase {

    /** @var int[] */
    private array $contactIds = [];
    /** @var int[] */
    private array $requestIds = [];
    private int $alteStation = 0;

    protected function setUp(): void {
        parent::setUp();
        $db = Database::getInstance();
        foreach (['persons_pre_contacts', 'breeding_stations_pre_contacts'] as $t) {
            $this->assertSame(0, $db->query("SHOW TABLES LIKE '{$t}'")->rowCount(), "{$t} liegt schon da - fremden Zustand nicht überschreiben");
        }
        $db->exec(
            "CREATE TABLE `persons_pre_contacts` (
                `id` INT NOT NULL PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL,
                `street` VARCHAR(150) NULL DEFAULT NULL,
                `email` VARCHAR(100) NULL DEFAULT NULL,
                `membership_status` VARCHAR(100) NULL DEFAULT NULL,
                `kuerzel` VARCHAR(20) NOT NULL DEFAULT '',
                `is_published` TINYINT(1) NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $db->exec(
            "CREATE TABLE `breeding_stations_pre_contacts` (
                `id` INT NOT NULL PRIMARY KEY,
                `name` VARCHAR(150) NOT NULL,
                `email` VARCHAR(100) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->alteStation = 900000 + random_int(1, 99999);
    }

    protected function tearDown(): void {
        $db = Database::getInstance();
        $db->exec('DROP TABLE IF EXISTS `persons_pre_contacts`');
        $db->exec('DROP TABLE IF EXISTS `breeding_stations_pre_contacts`');
        foreach ($this->contactIds as $id) {
            $db->prepare("DELETE FROM contacts WHERE id = ?")->execute([$id]);
        }
        foreach ($this->requestIds as $id) {
            $db->prepare("DELETE FROM gdpr_requests WHERE id = ?")->execute([$id]);
        }
        $db->prepare("DELETE FROM contact_id_map WHERE old_type = 'station' AND old_id = ?")->execute([$this->alteStation]);
        parent::tearDown();
    }

    /**
     * Kontakt mit Personen-Altzeile (ID-treu, mit Karte) und Stations-Altzeile
     * über die Karte.
     */
    private function kontaktMitAltkopien(string $name, bool $papierkorb = false): int {
        $db = Database::getInstance();
        $db->prepare("INSERT INTO contacts (name, is_published, deleted_at) VALUES (?, 1, " . ($papierkorb ? 'NOW()' : 'NULL') . ")")->execute([$name]);
        $id = (int)$db->lastInsertId();
        $this->contactIds[] = $id;
        $db->prepare("INSERT INTO contact_id_map (old_type, old_id, contact_id) VALUES ('person', ?, ?)")->execute([$id, $id]);
        $db->prepare("INSERT INTO contact_id_map (old_type, old_id, contact_id) VALUES ('station', ?, ?)")->execute([$this->alteStation, $id]);
        $db->prepare("INSERT INTO persons_pre_contacts (id, name, street, email, membership_status, kuerzel) VALUES (?, ?, 'Altweg 1', 'alt@example.com', 'Mitglied', 'XY')")
           ->execute([$id, $name . ' (alt)']);
        $db->prepare("INSERT INTO breeding_stations_pre_contacts (id, name, email) VALUES (?, ?, 'hof-alt@example.com')")
           ->execute([$this->alteStation, $name . ' Hof']);
        return $id;
    }

    private function anfrage(string $typ, string $name): int {
        $db = Database::getInstance();
        $db->prepare("INSERT INTO gdpr_requests (name, email, request_type, message, status) VALUES (?, ?, ?, 'Test', 'pending')")
           ->execute([$name, 'alt-' . uniqid() . '@example.com', $typ]);
        $id = (int)$db->lastInsertId();
        $this->requestIds[] = $id;
        return $id;
    }

    private function anzahl(string $tabelle, int $id): int {
        $stmt = Database::getInstance()->prepare("SELECT COUNT(*) FROM `{$tabelle}` WHERE id = ?");
        $stmt->execute([$id]);
        return (int)$stmt->fetchColumn();
    }

    public function testDsgvoDeleteRemovesBothLegacyRows(): void {
        $admin = $this->authenticatedClient();
        $id = $this->kontaktMitAltkopien('Altperson Löschen ' . uniqid());

        $r = $admin->post('/admin/gdpr/delete-person', [
            'csrf_token' => $this->currentCsrfToken($admin), 'person_id' => (string)$id, 'request_id' => '0',
        ]);
        $this->assertSame("/admin/gdpr?success=deleted&person_id={$id}", $r->location(), $r->body);
        $this->assertSame(0, $this->anzahl('persons_pre_contacts', $id));
        $this->assertSame(0, $this->anzahl('breeding_stations_pre_contacts', $this->alteStation));
    }

    public function testTrashPermanentDeleteRemovesTheLegacyRowToo(): void {
        $admin = $this->authenticatedClient();
        $id = $this->kontaktMitAltkopien('Altperson Papierkorb ' . uniqid(), true);

        $r = $admin->post('/admin/trash/permanent-delete', [
            'csrf_token' => $this->currentCsrfToken($admin), 'type' => 'contact', 'id' => (string)$id,
        ]);
        $this->assertSame('/admin/trash?success=purged', $r->location(), $r->body);
        $this->assertSame(0, $this->anzahl('persons_pre_contacts', $id));
        $this->assertSame(0, $this->anzahl('breeding_stations_pre_contacts', $this->alteStation));
    }

    public function testAnonymizeAnonymizesTheLegacyRowSchemaDriven(): void {
        $admin = $this->authenticatedClient();
        $id = $this->kontaktMitAltkopien('Altperson Anonym ' . uniqid());

        $r = $admin->post('/admin/gdpr/anonymize-person', [
            'csrf_token' => $this->currentCsrfToken($admin), 'person_id' => (string)$id, 'request_id' => '0',
        ]);
        $this->assertSame("/admin/gdpr?success=anonymized&person_id={$id}", $r->location(), $r->body);

        $zeile = Database::getInstance()->query("SELECT * FROM persons_pre_contacts WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($zeile, 'Die Zeile und ihre id bleiben (Vertrag mit mitgliedsstatus)');
        $this->assertSame("Anonymisierte Person (#{$id})", $zeile['name']);
        $this->assertNull($zeile['street']);
        $this->assertNull($zeile['email']);
        $this->assertNull($zeile['membership_status']);
        $this->assertSame('', $zeile['kuerzel']);
        $station = Database::getInstance()->query("SELECT * FROM breeding_stations_pre_contacts WHERE id = {$this->alteStation}")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame("Anonymisierte Person (#{$id})", $station['name']);
        $this->assertNull($station['email']);
    }

    public function testInfoRequestShowsTheLegacyCopyToAdminsOnly(): void {
        $admin = $this->authenticatedClient();
        $name = 'Altperson Auskunft ' . uniqid();
        $id = $this->kontaktMitAltkopien($name);
        $this->anfrage('info', $name);
        $loeschAnfrage = $this->anfrage('deletion', $name);

        $seite = $admin->get('/admin/gdpr');
        $this->assertStringContainsString("/admin/gdpr/legacy-copy?id={$id}", $seite->body);
        $this->assertStringContainsString('Altkopie (#336) wird mitbehandelt', $seite->body);
        $this->assertGreaterThan(0, $loeschAnfrage);

        $kopie = $admin->get("/admin/gdpr/legacy-copy?id={$id}");
        $this->assertSame(200, $kopie->statusCode);
        $this->assertStringContainsString('no-store', strtolower((string)$kopie->header('Cache-Control')));
        $this->assertStringContainsString('Altweg 1', $kopie->body);
        $this->assertStringContainsString(htmlspecialchars("{$name} Hof"), $kopie->body);
        $this->assertStringContainsString('persons_pre_contacts', $kopie->body);

        $suche = json_decode($admin->get('/admin/gdpr/search-persons?q=' . urlencode($name))->body, true);
        $this->assertSame([true], array_column($suche, 'has_legacy_copy'));

        $u = uniqid();
        $editor = $this->createAndLoginEditor($admin, "altkopie{$u}", "altkopie-{$u}@example.com", []);
        $this->assertSame(403, $editor->get("/admin/gdpr/legacy-copy?id={$id}")->statusCode);
    }
}
