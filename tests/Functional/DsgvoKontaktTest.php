<?php
// tests/Functional/DsgvoKontaktTest.php

namespace Tests\Functional;

use App\Database;
use App\Service\AuditLogger;

/**
 * DSGVO-Vollständigkeit am Kontakt über die echten Routen (Audit M11, N45).
 *
 * M11: HorseController::saveHorsePersons() kopiert den Namen der Deckstation
 * nach horses.breeding_station. Nach dem Löschen ging breeding_station_id per
 * SET NULL weg, und die Kopie galt ab da als öffentlicher Freitext - auf der
 * Detailseite, in /api/horses, auch bei nie veröffentlichten Kontakten. Nach
 * einer Anonymisierung stand der alte Name klein unter dem Anonymnamen.
 *
 * N45: Klarnamen und Dubletten-Notizen im Audit-Log überlebten jede
 * DSGVO-Aktion.
 */
class DsgvoKontaktTest extends FunctionalTestCase {

    use ApiKeyHelper;

    /** @var int[] */
    private array $horseIds = [];
    /** @var int[] */
    private array $contactIds = [];
    /** @var int[] */
    private array $requestIds = [];

    protected function tearDown(): void {
        $db = Database::getInstance();
        foreach ($this->horseIds as $id) {
            $db->prepare("DELETE FROM horses WHERE id = ?")->execute([$id]);
        }
        foreach ($this->contactIds as $id) {
            $db->prepare("DELETE FROM contacts WHERE id = ?")->execute([$id]);
            // match_labels hat keinen Fremdschlüssel - andere Tests zählen die Tabelle.
            $db->prepare("DELETE FROM match_labels WHERE kind = 'contact' AND (left_id = ? OR right_id = ?)")->execute([$id, $id]);
        }
        foreach ($this->requestIds as $id) {
            $db->prepare("DELETE FROM gdpr_requests WHERE id = ?")->execute([$id]);
        }
        parent::tearDown();
    }

    private function db(): \PDO {
        return Database::getInstance();
    }

    private function wert(string $sql, array $werte = []): mixed {
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($werte);
        return $stmt->fetchColumn();
    }

    private function kontakt(string $name, int $veroeffentlicht): int {
        $this->db()->prepare("INSERT INTO contacts (name, is_published, email) VALUES (?, ?, ?)")
            ->execute([$name, $veroeffentlicht, 'kontakt-' . uniqid() . '@example.com']);
        $id = (int)$this->db()->lastInsertId();
        $this->contactIds[] = $id;
        return $id;
    }

    private function anfrage(string $name): int {
        $this->db()->prepare(
            "INSERT INTO gdpr_requests (name, email, request_type, message, status) VALUES (?, ?, 'deletion', 'Bitte löschen', 'pending')"
        )->execute([$name, 'anfrage-' . uniqid() . '@example.com']);
        $id = (int)$this->db()->lastInsertId();
        $this->requestIds[] = $id;
        return $id;
    }

    /**
     * Veröffentlichtes Pferd, dessen Deckstation über den ECHTEN Speicherweg
     * gesetzt wird - nur so entsteht die Kopie in horses.breeding_station.
     */
    private function pferdMitStation(\Tests\Support\HttpClient $admin, string $name, int $stationId): int {
        $form = $admin->get('/admin/horses/create');
        $admin->post('/admin/horses/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $name,
            'status' => 'active',
            'is_published' => '1',
            'persons' => [
                ['contact_id' => '', 'role' => 'keeper', 'station_contact_id' => (string)$stationId, 'breeding_station_text' => '', 'from_year' => '', 'until_year' => ''],
            ],
        ]);
        $id = (int)$this->wert("SELECT id FROM horses WHERE name = ?", [$name]);
        $this->assertGreaterThan(0, $id, "Pferd '{$name}' wurde nicht angelegt");
        $this->horseIds[] = $id;
        $this->db()->prepare("UPDATE horses SET is_published = 1 WHERE id = ?")->execute([$id]);
        return $id;
    }

    private function gdprAktion(\Tests\Support\HttpClient $admin, string $aktion, int $kontaktId, int $anfrageId): \Tests\Support\HttpResponse {
        return $admin->post('/admin/gdpr/' . $aktion, [
            'csrf_token' => $this->currentCsrfToken($admin),
            'person_id' => (string)$kontaktId,
            'request_id' => (string)$anfrageId,
        ]);
    }

    public function testDeleteRemovesTheStationNameEverywhereEvenForAnUnpublishedContact(): void {
        $admin = $this->authenticatedClient();
        $u = uniqid();
        $name = "Privatstation Lea Heimlich {$u}";
        $stationId = $this->kontakt($name, 0);
        $horseId = $this->pferdMitStation($admin, "DSGVO-Stationspferd {$u}", $stationId);
        $this->assertSame($name, $this->wert("SELECT breeding_station FROM horses WHERE id = ?", [$horseId]), 'Vorbedingung: Kopie am Pferd');

        // Wörtliche Kopie neben der Verknüpfung, dazu echte Pferdehistorie.
        $db = $this->db();
        $db->prepare("INSERT INTO horse_persons (horse_id, role, station_contact_id, breeding_station_text) VALUES (?, 'keeper', ?, ?)")
           ->execute([$horseId, $stationId, $name]);
        $db->prepare("INSERT INTO horse_persons (horse_id, role, station_contact_id, breeding_station_text) VALUES (?, 'keeper', ?, 'Gestüt Musterstadt')")
           ->execute([$horseId, $stationId]);
        // Altformate im Protokoll und eine Dubletten-Notiz mit Namen.
        $andererId = $this->kontakt("Nachbar {$u}", 1);
        [$l, $r] = $stationId < $andererId ? [$stationId, $andererId] : [$andererId, $stationId];
        $db->prepare("INSERT INTO match_labels (kind, left_id, right_id, label, note) VALUES ('contact', ?, ?, 'different', ?)")
           ->execute([$l, $r, "Nicht {$name}"]);
        AuditLogger::log('Kontakt angelegt', 'contacts', "Kontakt ID {$stationId}: {$name}");
        AuditLogger::log('Dubletten-Entscheidung gesetzt', 'matches', "contact {$l}/{$r}: different (Nicht {$name})");
        AuditLogger::log('Kontakt angelegt', 'contacts', "Kontakt ID {$stationId}9: {$name} ist nicht gemeint");

        $anfrageId = $this->anfrage($name);
        $response = $this->gdprAktion($admin, 'delete-person', $stationId, $anfrageId);
        $this->assertSame("/admin/gdpr?success=deleted&person_id={$stationId}", $response->location(), $response->body);

        $this->assertNull($this->wert("SELECT breeding_station FROM horses WHERE id = ?", [$horseId]));
        $this->assertSame(
            ['Gestüt Musterstadt'],
            array_values(array_filter($db->query("SELECT breeding_station_text FROM horse_persons WHERE horse_id = {$horseId}")->fetchAll(\PDO::FETCH_COLUMN))),
            'Nur die wörtliche Kopie fällt, abweichender Freitext bleibt'
        );
        $this->assertSame(0, (int)$this->wert("SELECT COUNT(*) FROM match_labels WHERE kind = 'contact' AND (left_id = ? OR right_id = ?)", [$stationId, $stationId]));

        $seite = $this->newClient()->get('/horse?id=' . $horseId);
        $this->assertSame(200, $seite->statusCode);
        $this->assertStringNotContainsString('Lea Heimlich', $seite->body);
        $token = $this->createApiKey($admin, "DSGVO-Probe {$u}");
        $api = $admin->get('/api/horses?search=' . urlencode("DSGVO-Stationspferd {$u}"), $this->bearer($token));
        $this->assertSame(200, $api->statusCode, $api->body);
        $this->assertStringContainsString("DSGVO-Stationspferd {$u}", $api->body);
        $this->assertStringNotContainsString('Lea Heimlich', $api->body);

        // Protokoll: kein Name mehr, die Einträge selbst bleiben.
        $this->assertSame(0, (int)$this->wert("SELECT COUNT(*) FROM audit_logs WHERE details LIKE ? AND details NOT LIKE ?", ["%Lea Heimlich%", "%ist nicht gemeint%"]));
        $this->assertSame(1, (int)$this->wert("SELECT COUNT(*) FROM audit_logs WHERE details = ?", ["Kontakt ID {$stationId}: " . AuditLogger::ENTFERNT]));
        $this->assertSame(1, (int)$this->wert("SELECT COUNT(*) FROM audit_logs WHERE details = ?", ["contact {$l}/{$r}: different (" . AuditLogger::ENTFERNT . ")"]));
        $this->assertSame(1, (int)$this->wert("SELECT COUNT(*) FROM audit_logs WHERE details LIKE ?", ["Kontakt ID {$stationId}9: %ist nicht gemeint"]), 'Andere Kennung mit gleichem Präfix bleibt');

        $notiz = (string)$this->wert("SELECT admin_notes FROM gdpr_requests WHERE id = ?", [$anfrageId]);
        $this->assertStringStartsWith("Kontakt #{$stationId} gelöscht; bereinigt: 2 Deckstations-Kopie(n), 0 Altkopie(n) (#336), 1 Dubletten-Notiz(en), 2 Protokolleintrag", $notiz);
        $this->assertStringNotContainsString('Lea', $notiz);
        $this->assertSame(1, (int)$this->wert(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'DSGVO: Person endgültig gelöscht' AND details LIKE ?",
            ["Person ID {$stationId}, Anfrage ID {$anfrageId}; bereinigt: %"]
        ));
    }

    public function testAnonymizeReplacesTheCopyAndTheDetailPageShowsNoOldName(): void {
        $admin = $this->authenticatedClient();
        $u = uniqid();
        $name = "Hof Karla Offen {$u}";
        $stationId = $this->kontakt($name, 1);
        $horseId = $this->pferdMitStation($admin, "DSGVO-Anonpferd {$u}", $stationId);

        $response = $this->gdprAktion($admin, 'anonymize-person', $stationId, $this->anfrage($name));
        $this->assertSame("/admin/gdpr?success=anonymized&person_id={$stationId}", $response->location(), $response->body);

        $anon = "Anonymisierte Person (#{$stationId})";
        $this->assertSame($anon, $this->wert("SELECT breeding_station FROM horses WHERE id = ?", [$horseId]));
        $seite = $this->newClient()->get('/horse?id=' . $horseId);
        $this->assertSame(200, $seite->statusCode);
        $this->assertStringContainsString(htmlspecialchars($anon), $seite->body);
        $this->assertStringNotContainsString('Karla Offen', $seite->body);
    }

    /**
     * Merge-Rest: Nach dem Zusammenführen trägt das Pferd die Verknüpfung auf
     * das Ziel, die Kopie aber noch den Namen der Quelle. Richtet sich die
     * DSGVO-Löschung gegen die Quelle im Papierkorb, muss auch diese Kopie
     * weg.
     */
    public function testDeleteOfMergedSourceClearsItsNameCopy(): void {
        $admin = $this->authenticatedClient();
        $u = uniqid();
        $quelleName = "Quellhof Rudi Rest {$u}";
        $zielName = "Zielhof {$u}";
        $quelleId = $this->kontakt($quelleName, 1);
        $zielId = $this->kontakt($zielName, 1);
        $horseId = $this->pferdMitStation($admin, "DSGVO-Mergepferd {$u}", $quelleId);

        $merge = $admin->post('/admin/contacts/merge', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'source_id' => (string)$quelleId,
            'target_id' => (string)$zielId,
        ]);
        $this->assertStringStartsWith('/admin/contacts?success=merged', (string)$merge->location(), $merge->body);
        $this->assertSame($quelleName, $this->wert("SELECT breeding_station FROM horses WHERE id = ?", [$horseId]), 'Vorbedingung: Kopie der Quelle bleibt beim Merge');

        $response = $this->gdprAktion($admin, 'delete-person', $quelleId, $this->anfrage($quelleName));
        $this->assertSame("/admin/gdpr?success=deleted&person_id={$quelleId}", $response->location(), $response->body);

        $this->assertSame($zielName, $this->wert("SELECT breeding_station FROM horses WHERE id = ?", [$horseId]));
        $this->assertSame(0, (int)$this->wert("SELECT COUNT(*) FROM horse_persons WHERE breeding_station_text LIKE ?", ['%Rudi Rest%']));
        $this->assertSame(0, (int)$this->wert("SELECT COUNT(*) FROM horses WHERE breeding_station LIKE ?", ['%Rudi Rest%']));
    }

    public function testUnknownContactLeavesTheRequestOpen(): void {
        $admin = $this->authenticatedClient();
        $anfrageId = $this->anfrage('Niemand ' . uniqid());
        $fehlt = (int)$this->wert("SELECT COALESCE(MAX(id), 0) + 1000 FROM contacts");

        foreach (['delete-person', 'anonymize-person'] as $aktion) {
            $response = $this->gdprAktion($admin, $aktion, $fehlt, $anfrageId);
            $this->assertSame("/admin/gdpr?error=not_found&person_id={$fehlt}", $response->location(), $response->body);
            $this->assertSame('pending', $this->wert("SELECT status FROM gdpr_requests WHERE id = ?", [$anfrageId]));
        }
        $seite = $admin->get("/admin/gdpr?error=not_found&person_id={$fehlt}");
        $this->assertStringContainsString("Kontakt #{$fehlt} wurde nicht gefunden", $seite->body);
        $this->assertSame(0, (int)$this->wert("SELECT COUNT(*) FROM audit_logs WHERE category = 'gdpr' AND details LIKE ?", ["Person ID {$fehlt}%"]));
    }

    /**
     * N45: Anlegen, Ändern, Papierkorb, Zusammenführen und
     * Dubletten-Entscheidungen schreiben nur noch Kennungen ins Protokoll.
     */
    public function testContactAuditEntriesCarryIdsOnly(): void {
        $admin = $this->authenticatedClient();
        $u = uniqid();
        $name = "Protokollprobe Irma {$u}";

        $admin->post('/admin/contacts/store', ['csrf_token' => $this->currentCsrfToken($admin), 'name' => $name]);
        $id = (int)$this->wert("SELECT id FROM contacts WHERE name = ?", [$name]);
        $this->assertGreaterThan(0, $id);
        $this->contactIds[] = $id;
        $admin->post('/admin/contacts/update', ['csrf_token' => $this->currentCsrfToken($admin), 'id' => (string)$id, 'name' => "{$name} B"]);
        $this->assertSame("{$name} B", $this->wert("SELECT name FROM contacts WHERE id = ?", [$id]), 'Vorbedingung: Umbenennung gespeichert');

        $zielId = $this->kontakt("Protokollziel Irma {$u}", 1);
        $label = $admin->post('/admin/matches/label', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'art' => 'contact', 'a' => (string)$id, 'b' => (string)$zielId,
            'label' => 'unclear', 'note' => "Vielleicht {$name}",
        ]);
        $this->assertLessThan(400, $label->statusCode, $label->body);
        $this->assertSame("Vielleicht {$name}", $this->wert("SELECT note FROM match_labels WHERE kind = 'contact' AND left_id = ?", [min($id, $zielId)]));

        $admin->post('/admin/contacts/merge', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'source_id' => (string)$id,
            'target_id' => (string)$zielId,
        ]);
        $weitererId = $this->kontakt("Protokollweg Irma {$u}", 1);
        $admin->post('/admin/contacts/delete', ['csrf_token' => $this->currentCsrfToken($admin), 'id' => (string)$weitererId]);

        $zeilen = $this->db()->query(
            "SELECT action, details FROM audit_logs WHERE category IN ('contacts', 'matches') ORDER BY id DESC LIMIT 20"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $aktionen = array_column($zeilen, 'action');
        foreach (['Kontakt angelegt', 'Kontakt aktualisiert', 'Kontakte zusammengeführt', 'Kontakt in Papierkorb verschoben', 'Dubletten-Entscheidung gesetzt'] as $erwartet) {
            $this->assertContains($erwartet, $aktionen, "Vorbedingung: '{$erwartet}' wurde protokolliert");
        }
        $this->assertSame(0, (int)$this->wert("SELECT COUNT(*) FROM audit_logs WHERE details LIKE ?", ["%Irma%"]));
        $this->assertSame(2, (int)$this->wert("SELECT COUNT(*) FROM audit_logs WHERE details = ?", ["Kontakt ID {$id}"])); // angelegt, aktualisiert
        $this->assertSame(1, (int)$this->wert("SELECT COUNT(*) FROM audit_logs WHERE details LIKE ?", ["Quelle ID {$id} -> Ziel ID {$zielId}: %"]));
        [$l, $r] = $id < $zielId ? [$id, $zielId] : [$zielId, $id];
        $this->assertSame(1, (int)$this->wert("SELECT COUNT(*) FROM audit_logs WHERE details = ?", ["contact {$l}/{$r}: unclear (Notiz hinterlegt)"]));
    }
}
