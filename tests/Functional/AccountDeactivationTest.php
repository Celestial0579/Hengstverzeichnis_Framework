<?php
// tests/Functional/AccountDeactivationTest.php

namespace Tests\Functional;

use App\Database;
use Tests\Support\HttpClient;

/**
 * Sperrwirkung eines deaktivierten Kontos über HTTP (#358).
 *
 * Bis v0.8 gab es den Zustand gar nicht: Was der Code "deaktiviert" nannte,
 * war `deleted_at` - also der Papierkorb. Eine Sperre, die von einer Löschung
 * nicht zu unterscheiden ist, kann man weder begründen noch gezielt aufheben.
 *
 * Geprüft wird beides: dass die Sperre an allen Türen greift, und dass sie
 * sich wieder aufheben lässt.
 */
class AccountDeactivationTest extends FunctionalTestCase {

    private function deaktiviere(string $benutzername): void {
        Database::getInstance()
            ->prepare("UPDATE users SET deactivated_at = NOW(), deactivated_reason = 'test' WHERE username = ?")
            ->execute([$benutzername]);
    }

    private function spalte(string $benutzername, string $spalte) {
        $stmt = Database::getInstance()->prepare("SELECT `{$spalte}` FROM users WHERE username = ?");
        $stmt->execute([$benutzername]);
        return $stmt->fetchColumn();
    }

    /**
     * Die laufende Sitzung endet beim nächsten Aufruf - und mit einer eigenen
     * Meldung, nicht mit der für gelöschte Konten.
     */
    public function testAnActiveSessionEndsAsSoonAsTheAccountIsDeactivated(): void {
        $admin = $this->authenticatedClient();
        $u = uniqid();
        $name = "deakt{$u}";
        $editor = $this->createAndLoginEditor($admin, $name, "deakt-{$u}@example.com");

        $this->assertSame(200, $editor->get('/admin')->statusCode, 'Vorbedingung: die Sitzung trägt');

        $this->deaktiviere($name);

        $antwort = $editor->get('/admin');
        $this->assertSame(302, $antwort->statusCode);
        $this->assertSame(
            '/login?error=account_deactivated',
            $antwort->location(),
            'Deaktiviert braucht eine eigene Meldung - sonst ist sie von einer Löschung nicht zu unterscheiden.'
        );
    }

    /** Und eine neue Anmeldung kommt gar nicht erst zustande. */
    public function testADeactivatedAccountCannotLogInAnymore(): void {
        $admin = $this->authenticatedClient();
        $u = uniqid();
        $name = "deaktlogin{$u}";
        $email = "deaktlogin-{$u}@example.com";
        $this->createAndLoginEditor($admin, $name, $email);
        $this->deaktiviere($name);

        $client = $this->newClient();
        $seite = $client->get('/login');
        $antwort = $client->post('/login', [
            'csrf_token' => $seite->formField('csrf_token') ?? '',
            'email' => $email,
            'password' => 'EditorTestNeu456!',
        ]);

        $this->assertNull($antwort->location(), 'Ein deaktiviertes Konto darf nicht angemeldet werden.');
        $this->assertStringContainsString('Ungültige E-Mail oder Passwort.', $antwort->body,
            'Die Meldung bleibt generisch - die Anmeldemaske darf kein Orakel für Kontozustände werden.');
    }

    /**
     * Der Rückweg per "Passwort vergessen" darf die Sperre nicht aushebeln.
     * Diese Stelle filterte bis #358 GAR NICHT - auch ein gelöschtes Konto
     * bekam einen Reset-Link.
     */
    public function testPasswordResetIsRefusedForADeactivatedAccount(): void {
        $admin = $this->authenticatedClient();
        $u = uniqid();
        $name = "deaktreset{$u}";
        $email = "deaktreset-{$u}@example.com";
        $this->createAndLoginEditor($admin, $name, $email);
        $this->deaktiviere($name);

        $db = Database::getInstance();
        $db->prepare('DELETE FROM password_resets WHERE email = ?')->execute([$email]);

        $seite = $this->newClient()->get('/forgot-password');
        $client = $this->newClient();
        $seite = $client->get('/forgot-password');
        $client->post('/forgot-password', [
            'csrf_token' => $seite->formField('csrf_token') ?? '',
            'email' => $email,
        ]);

        $stmt = $db->prepare('SELECT COUNT(*) FROM password_resets WHERE email = ?');
        $stmt->execute([$email]);
        $this->assertSame(
            0,
            (int)$stmt->fetchColumn(),
            'Für ein deaktiviertes Konto darf kein Reset-Token entstehen.'
        );
    }

    /** Wieder einschalten - und der Fristanker geht mit zurück. */
    public function testAnAdminCanSwitchTheAccountBackOn(): void {
        $admin = $this->authenticatedClient();
        $u = uniqid();
        $name = "deaktwieder{$u}";
        $this->createAndLoginEditor($admin, $name, "deaktwieder-{$u}@example.com");
        $this->deaktiviere($name);
        Database::getInstance()
            ->prepare("UPDATE users SET unprotected_since = '2020-01-01 00:00:00' WHERE username = ?")
            ->execute([$name]);

        $id = (int)$this->spalte($name, 'id');
        $liste = $admin->get('/admin/users');
        $this->assertStringContainsString('Deaktiviert seit', $liste->body, 'Der Zustand muss in der Liste sichtbar sein.');

        $antwort = $admin->post('/admin/users/reactivate', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'id' => (string)$id,
        ]);

        $this->assertSame('/admin/users?success=reactivated', $antwort->location());
        $this->assertNull($this->spalte($name, 'deactivated_at'));
        $this->assertNull(
            $this->spalte($name, 'unprotected_since'),
            'Ohne Zurücksetzen des Ankers deaktivierte der nächste Nachtlauf dasselbe Konto sofort wieder.'
        );
    }

    public function testReactivationRequiresAdminAndCsrf(): void {
        $admin = $this->authenticatedClient();
        $u = uniqid();
        $name = "deaktrecht{$u}";
        $this->createAndLoginEditor($admin, $name, "deaktrecht-{$u}@example.com");
        $this->deaktiviere($name);
        $id = (int)$this->spalte($name, 'id');

        // Ohne CSRF-Token
        $this->assertSame(403, $admin->post('/admin/users/reactivate', ['id' => (string)$id])->statusCode);

        // Als Redakteur - das Token kommt vom Dashboard, das jede angemeldete
        // Sitzung sehen darf; sonst prüfte der POST nur den CSRF-Zweig.
        $u2 = uniqid();
        $redakteur = $this->createAndLoginEditor($admin, "nichtadmin{$u2}", "nichtadmin-{$u2}@example.com");
        $this->assertSame(403, $redakteur->post('/admin/users/reactivate', [
            'csrf_token' => $this->editorCsrfToken($redakteur),
            'id' => (string)$id,
        ])->statusCode);

        $this->assertNotNull($this->spalte($name, 'deactivated_at'), 'Ein abgelehnter Aufruf darf nichts geändert haben.');
    }
}
