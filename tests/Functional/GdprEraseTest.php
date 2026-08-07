<?php
// tests/Functional/GdprEraseTest.php

namespace Tests\Functional;

use App\Database;

/**
 * HTTP-Funktionstests für die DSGVO-Verarbeitung (#135): Löschung und
 * Anonymisierung einer Person über /admin/gdpr müssen (a) nur die Person
 * treffen (verknüpfte Pferde bleiben erhalten, nur die horse_persons-Zeilen
 * verschwinden per ON DELETE CASCADE), (b) den Antrag auf "processed" setzen,
 * (c) einen Audit-Log-Eintrag hinterlassen und (d) Nicht-Admins mit 403
 * abweisen (requireAdmin() im GdprController-Konstruktor).
 */
class GdprEraseTest extends FunctionalTestCase {

    private function db(): \PDO {
        return Database::getInstance();
    }

    /**
     * Legt Person + verknüpftes Pferd direkt in der DB an und reicht über den
     * echten öffentlichen HTTP-Flow (/dsgvo) einen Löschantrag ein.
     *
     * @return array{personId: int, horseId: int, requestId: int}
     */
    private function createPersonWithHorseAndRequest(string $personName, string $email): array {
        $db = $this->db();

        $stmt = $db->prepare("INSERT INTO persons (name, contact_info, is_published) VALUES (?, 'Musterweg 3, Tel. 0170-1234567', 1)");
        $stmt->execute([$personName]);
        $personId = (int)$db->lastInsertId();

        $stmt = $db->prepare("INSERT INTO horses (name, is_published) VALUES (?, 1)");
        $stmt->execute(['DSGVO-Testpferd ' . uniqid()]);
        $horseId = (int)$db->lastInsertId();

        $stmt = $db->prepare("INSERT INTO horse_persons (horse_id, person_id, role) VALUES (?, ?, 'owner')");
        $stmt->execute([$horseId, $personId]);

        $public = $this->newClient();
        $dsgvoPage = $public->get('/dsgvo');
        $submitResponse = $public->post('/dsgvo', [
            'csrf_token' => $dsgvoPage->formField('csrf_token') ?? '',
            'name' => $personName,
            'email' => $email,
            'request_type' => 'deletion',
            'message' => 'Bitte alle meine Daten löschen.',
        ]);
        $this->assertSame('/dsgvo?success=1', $submitResponse->location(), "DSGVO-Antrag konnte nicht eingereicht werden, Body: {$submitResponse->body}");

        $stmt = $db->prepare("SELECT id FROM gdpr_requests WHERE email = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$email]);
        $requestId = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $requestId, 'DSGVO-Antrag wurde nicht in gdpr_requests gespeichert');

        return ['personId' => $personId, 'horseId' => $horseId, 'requestId' => $requestId];
    }

    public function testDeletePersonRemovesOnlyPersonAndMarksRequestProcessed(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $fixture = $this->createPersonWithHorseAndRequest("DSGVO Löschperson {$unique}", "gdpr-delete-{$unique}@example.com");
        $db = $this->db();

        // Die Anfrage taucht in der Admin-Übersicht mit der gefundenen Person auf.
        $overview = $admin->get('/admin/gdpr');
        $this->assertStringContainsString("DSGVO Löschperson {$unique}", $overview->body);

        $deleteResponse = $admin->post('/admin/gdpr/delete-person', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'person_id' => (string)$fixture['personId'],
            'request_id' => (string)$fixture['requestId'],
        ]);
        $this->assertSame(
            '/admin/gdpr?success=deleted&person_id=' . $fixture['personId'],
            $deleteResponse->location(),
            "Löschung fehlgeschlagen, Body: {$deleteResponse->body}"
        );

        // Person weg, Pferd bleibt, Verknüpfung per Cascade entfernt.
        $stmt = $db->prepare("SELECT COUNT(*) FROM persons WHERE id = ?");
        $stmt->execute([$fixture['personId']]);
        $this->assertSame(0, (int)$stmt->fetchColumn(), 'Person hätte gelöscht werden müssen');

        $stmt = $db->prepare("SELECT COUNT(*) FROM horses WHERE id = ?");
        $stmt->execute([$fixture['horseId']]);
        $this->assertSame(1, (int)$stmt->fetchColumn(), 'Das verknüpfte Pferd darf NICHT mitgelöscht werden');

        $stmt = $db->prepare("SELECT COUNT(*) FROM horse_persons WHERE person_id = ?");
        $stmt->execute([$fixture['personId']]);
        $this->assertSame(0, (int)$stmt->fetchColumn(), 'horse_persons-Zeilen müssen per ON DELETE CASCADE verschwinden');

        // Antrag als erledigt markiert.
        $stmt = $db->prepare("SELECT status FROM gdpr_requests WHERE id = ?");
        $stmt->execute([$fixture['requestId']]);
        $this->assertSame('processed', $stmt->fetchColumn());

        // Die Löschung hinterlässt eine Audit-Log-Spur (#135).
        $stmt = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'DSGVO: Person endgültig gelöscht' AND details LIKE ?");
        $stmt->execute(['%Person ID ' . $fixture['personId'] . '%']);
        $this->assertGreaterThan(0, (int)$stmt->fetchColumn(), 'DSGVO-Löschung muss im Audit-Log protokolliert werden');
    }

    public function testAnonymizePersonKeepsHorseLinks(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $fixture = $this->createPersonWithHorseAndRequest("DSGVO Anonperson {$unique}", "gdpr-anon-{$unique}@example.com");
        $db = $this->db();

        $anonResponse = $admin->post('/admin/gdpr/anonymize-person', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'person_id' => (string)$fixture['personId'],
            'request_id' => (string)$fixture['requestId'],
        ]);
        $this->assertSame(
            '/admin/gdpr?success=anonymized&person_id=' . $fixture['personId'],
            $anonResponse->location(),
            "Anonymisierung fehlgeschlagen, Body: {$anonResponse->body}"
        );

        $stmt = $db->prepare("SELECT name, contact_info FROM persons WHERE id = ?");
        $stmt->execute([$fixture['personId']]);
        $person = $stmt->fetch();
        $this->assertSame('Anonymisierte Person (#' . $fixture['personId'] . ')', $person['name']);
        $this->assertNull($person['contact_info']);

        // Die Pferd-Verknüpfung bleibt bei der Anonymisierung erhalten.
        $stmt = $db->prepare("SELECT COUNT(*) FROM horse_persons WHERE person_id = ?");
        $stmt->execute([$fixture['personId']]);
        $this->assertSame(1, (int)$stmt->fetchColumn());

        $stmt = $db->prepare("SELECT status FROM gdpr_requests WHERE id = ?");
        $stmt->execute([$fixture['requestId']]);
        $this->assertSame('processed', $stmt->fetchColumn());

        $stmt = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'DSGVO: Person anonymisiert' AND details LIKE ?");
        $stmt->execute(['%Person ID ' . $fixture['personId'] . '%']);
        $this->assertGreaterThan(0, (int)$stmt->fetchColumn(), 'Anonymisierung muss im Audit-Log protokolliert werden');
    }

    public function testNonAdminIsRejectedWithForbidden(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        $db = $this->db();
        $stmt = $db->prepare("INSERT INTO persons (name, is_published) VALUES (?, 1)");
        $stmt->execute(["DSGVO Schutzperson {$unique}"]);
        $personId = (int)$db->lastInsertId();

        // Nicht-Admin ohne jede Gruppenzugehörigkeit: requireAdmin() im
        // Konstruktor muss sowohl die Übersicht als auch die Löschaktion sperren.
        $editor = $this->createAndLoginEditor($admin, "gdprtester{$unique}", "gdpr-editor-{$unique}@example.com", []);

        $this->assertSame(403, $editor->get('/admin/gdpr')->statusCode);

        $deleteResponse = $editor->post('/admin/gdpr/delete-person', [
            'csrf_token' => $editor->get('/dsgvo')->formField('csrf_token') ?? '',
            'person_id' => (string)$personId,
            'request_id' => '0',
        ]);
        $this->assertSame(403, $deleteResponse->statusCode, 'Nicht-Admins dürfen keine DSGVO-Löschung auslösen');

        $stmt = $db->prepare("SELECT COUNT(*) FROM persons WHERE id = ?");
        $stmt->execute([$personId]);
        $this->assertSame(1, (int)$stmt->fetchColumn(), 'Person darf durch den abgewiesenen Request nicht gelöscht sein');
    }
}
