<?php
// tests/Functional/SessionInvalidationTest.php

namespace Tests\Functional;

/**
 * HTTP-Funktionstests für die Session-Invalidierung bei Passwortänderung
 * (Issue #113, siehe users.session_version und BaseController::checkAuth()):
 * Ändert ein Admin das Passwort eines Benutzers, werden dessen bestehende
 * Sessions beim nächsten Request beendet - eine von einem Angreifer gehaltene
 * Alt-Session überlebt den Passwort-Reset des Opfers nicht. Die auslösende
 * Session selbst (z. B. Admin ändert das eigene Passwort) bleibt angemeldet.
 */
class SessionInvalidationTest extends FunctionalTestCase {

    public function testAdminPasswordChangeEndsExistingUserSessions(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $editor = $this->createAndLoginEditor($admin, "sessioninv{$unique}", "session-inv-{$unique}@example.com");

        // Editor-Session funktioniert.
        $before = $editor->get('/admin');
        $this->assertSame(200, $before->statusCode);

        // Benutzer-ID des Editors aus der Admin-Benutzerliste ermitteln.
        $usersPage = $admin->get('/admin/users?search=' . urlencode("sessioninv{$unique}"));
        preg_match('/\/admin\/users\/edit\?id=(\d+)/', $usersPage->body, $matches);
        $this->assertNotEmpty($matches, 'Konnte Editor-ID nicht aus /admin/users ermitteln');
        $editorId = (int)$matches[1];

        // Admin setzt ein neues Passwort für den Editor.
        $editPage = $admin->get('/admin/users/edit?id=' . $editorId);
        $updateResponse = $admin->post('/admin/users/update', [
            'csrf_token' => $editPage->formField('csrf_token') ?? '',
            'id' => (string)$editorId,
            'username' => "sessioninv{$unique}",
            'email' => "session-inv-{$unique}@example.com",
            'password' => 'NeuGesetzt789!',
        ]);
        $this->assertSame('/admin/users?success=updated', $updateResponse->location());

        // Die bestehende Editor-Session ist jetzt ungültig.
        $after = $editor->get('/admin');
        $this->assertSame('/login?error=session_expired', $after->location());

        // Die Admin-Session (Auslöser der Änderung, aber anderes Konto) lebt weiter.
        $adminStillAlive = $admin->get('/admin');
        $this->assertSame(200, $adminStillAlive->statusCode);
    }
}
