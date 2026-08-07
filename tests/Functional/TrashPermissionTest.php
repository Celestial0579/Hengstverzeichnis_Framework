<?php
// tests/Functional/TrashPermissionTest.php

namespace Tests\Functional;

use App\Database;

/**
 * HTTP-Funktionstests für die Papierkorb-Zugriffsschranken (#127,
 * TrashController::authorizeForType()):
 *  - Benutzerkonten (type=user) sind ausschließlich Administratoren vorbehalten -
 *    ein Nicht-Admin mit horses.delete darf ein gelöschtes Benutzerkonto NICHT
 *    wiederherstellen (sonst wäre das ein Kontoübernahme-Pfad, da checkAuth()
 *    über users.deleted_at sperrt).
 *  - Ohne <modul>.delete gibt es für den jeweiligen Typ ein 403.
 *  - Nicht-Admins dürfen erst nach Ablauf der 30-Tage-Aufbewahrung endgültig löschen.
 *  - Ein manipulierter type-Wert ist ein No-Op (Redirect zurück zum Papierkorb).
 */
class TrashPermissionTest extends FunctionalTestCase {

    private function db(): \PDO {
        return Database::getInstance();
    }

    /**
     * CSRF-Token einer Nicht-Admin-Sitzung: das Token ist pro Session fest
     * (Router::generateCsrfToken()), daher genügt irgendeine für den Benutzer
     * erreichbare Seite mit einem unbedingt gerenderten Formular - die
     * öffentliche DSGVO-Seite rendert immer eines.
     */
    private function csrfTokenFor(\Tests\Support\HttpClient $client): string {
        return $client->get('/dsgvo')->formField('csrf_token') ?? '';
    }

    private function createCustomGroup(\Tests\Support\HttpClient $admin, string $name): int {
        $groupsPage = $admin->get('/admin/groups');
        $response = $admin->post('/admin/groups/create', [
            'csrf_token' => $groupsPage->formField('csrf_token') ?? '',
            'name' => $name,
        ]);
        preg_match('/group=(\d+)/', (string)$response->location(), $matches);
        $this->assertNotEmpty($matches, "Konnte neue Gruppen-ID nicht ermitteln, Body: {$response->body}");
        return (int)$matches[1];
    }

    public function testNonAdminCannotRestoreDeletedUserAccount(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $db = $this->db();

        // Editor mit horses.view + horses.delete über eine eigene Gruppe.
        $groupId = $this->createCustomGroup($admin, "Trash-Tester {$unique}");
        $this->setGroupPermissions($admin, $groupId, ['horses' => ['view', 'delete']]);
        $editor = $this->createAndLoginEditor($admin, "trashtester{$unique}", "trash-{$unique}@example.com", [$groupId]);

        // Admin legt ein Opfer-Konto an und verschiebt es in den Papierkorb.
        $createForm = $admin->get('/admin/users/create');
        $admin->post('/admin/users/store', [
            'csrf_token' => $createForm->formField('csrf_token') ?? '',
            'username' => "trashvictim{$unique}",
            'email' => "trash-victim-{$unique}@example.com",
            'password' => 'VictimTest123!',
        ]);
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute(["trashvictim{$unique}"]);
        $victimId = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $victimId);

        $admin->post('/admin/users/delete', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'id' => (string)$victimId,
        ]);
        $stmt = $db->prepare("SELECT deleted_at FROM users WHERE id = ?");
        $stmt->execute([$victimId]);
        $this->assertNotNull($stmt->fetchColumn(), 'Opfer-Konto sollte im Papierkorb liegen');

        // Der Editor (horses.delete, aber kein Admin) versucht die Wiederherstellung.
        $restoreResponse = $editor->post('/admin/trash/restore', [
            'csrf_token' => $this->csrfTokenFor($editor),
            'type' => 'user',
            'id' => (string)$victimId,
        ]);
        $this->assertSame(403, $restoreResponse->statusCode, 'Benutzerkonten im Papierkorb sind Administratoren vorbehalten');

        // deleted_at ist unverändert gesetzt - das Konto bleibt gesperrt.
        $stmt = $db->prepare("SELECT deleted_at FROM users WHERE id = ?");
        $stmt->execute([$victimId]);
        $this->assertNotNull($stmt->fetchColumn(), 'users.deleted_at darf durch den abgewiesenen Request nicht geleert werden');

        // Der Admin selbst darf wiederherstellen.
        $adminRestore = $admin->post('/admin/trash/restore', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'type' => 'user',
            'id' => (string)$victimId,
        ]);
        $this->assertSame('/admin/trash?success=restored', $adminRestore->location());
        $stmt = $db->prepare("SELECT deleted_at FROM users WHERE id = ?");
        $stmt->execute([$victimId]);
        $this->assertNull($stmt->fetchColumn());
    }

    public function testNonAdminPermanentDeleteRespectsRetentionPeriod(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $db = $this->db();

        $groupId = $this->createCustomGroup($admin, "Trash-Retention {$unique}");
        $this->setGroupPermissions($admin, $groupId, ['horses' => ['view', 'delete']]);
        $editor = $this->createAndLoginEditor($admin, "retention{$unique}", "retention-{$unique}@example.com", [$groupId]);

        // Frisch (vor 2 Tagen) gelöschtes Pferd direkt in der DB anlegen.
        $stmt = $db->prepare("INSERT INTO horses (name, deleted_at) VALUES (?, DATE_SUB(NOW(), INTERVAL 2 DAY))");
        $stmt->execute(["Retention-Pferd {$unique}"]);
        $horseId = (int)$db->lastInsertId();

        // Nicht-Admin: endgültiges Löschen vor Ablauf der 30 Tage wird verweigert.
        $response = $editor->post('/admin/trash/permanent-delete', [
            'csrf_token' => $this->csrfTokenFor($editor),
            'type' => 'horse',
            'id' => (string)$horseId,
        ]);
        $this->assertSame('/admin/trash?error=retention_period_30_days', $response->location());

        $stmt = $db->prepare("SELECT COUNT(*) FROM horses WHERE id = ?");
        $stmt->execute([$horseId]);
        $this->assertSame(1, (int)$stmt->fetchColumn(), 'Das Pferd darf vor Ablauf der Aufbewahrungsfrist nicht endgültig gelöscht sein');

        // Nach Ablauf der Frist (31 Tage) darf derselbe Editor endgültig löschen.
        $db->prepare("UPDATE horses SET deleted_at = DATE_SUB(NOW(), INTERVAL 31 DAY) WHERE id = ?")->execute([$horseId]);
        $response = $editor->post('/admin/trash/permanent-delete', [
            'csrf_token' => $this->csrfTokenFor($editor),
            'type' => 'horse',
            'id' => (string)$horseId,
        ]);
        $this->assertSame('/admin/trash?success=purged', $response->location());
        $stmt = $db->prepare("SELECT COUNT(*) FROM horses WHERE id = ?");
        $stmt->execute([$horseId]);
        $this->assertSame(0, (int)$stmt->fetchColumn());
    }

    public function testWithoutDeletePermissionTrashActionsAreForbidden(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $db = $this->db();

        // Editor mit reiner Leseberechtigung (kein horses.delete).
        $groupId = $this->createCustomGroup($admin, "Trash-ReadOnly {$unique}");
        $this->setGroupPermissions($admin, $groupId, ['horses' => ['view']]);
        $editor = $this->createAndLoginEditor($admin, "readonly{$unique}", "readonly-{$unique}@example.com", [$groupId]);

        $stmt = $db->prepare("INSERT INTO horses (name, deleted_at) VALUES (?, NOW())");
        $stmt->execute(["ReadOnly-Pferd {$unique}"]);
        $horseId = (int)$db->lastInsertId();

        $restoreResponse = $editor->post('/admin/trash/restore', [
            'csrf_token' => $this->csrfTokenFor($editor),
            'type' => 'horse',
            'id' => (string)$horseId,
        ]);
        $this->assertSame(403, $restoreResponse->statusCode, 'Ohne horses.delete keine Papierkorb-Aktion für Pferde');

        $stmt = $db->prepare("SELECT deleted_at FROM horses WHERE id = ?");
        $stmt->execute([$horseId]);
        $this->assertNotNull($stmt->fetchColumn(), 'Das Pferd muss im Papierkorb bleiben');

        // Manipulierter type-Wert: No-Op-Redirect zurück zum Papierkorb statt
        // irgendeiner ungeprüften Aktion.
        $unknownTypeResponse = $editor->post('/admin/trash/restore', [
            'csrf_token' => $this->csrfTokenFor($editor),
            'type' => 'settings',
            'id' => '1',
        ]);
        $this->assertSame('/admin/trash', $unknownTypeResponse->location());

        // Aufräumen, damit der Test-Datensatz nachfolgende Klassen nicht stört.
        $db->prepare("DELETE FROM horses WHERE id = ?")->execute([$horseId]);
    }
}
