<?php
// tests/Functional/ForcePasswordChangeGuardTest.php

namespace Tests\Functional;

use App\Security\Totp;
use Tests\Support\HttpClient;

/**
 * /force-password-change war die einzige Backend-Route ohne
 * BaseController::checkAuth().
 *
 * Das machte sie zum Rückweg aus der Session-Invalidierung (#113): Eine
 * Sitzung, die nach einem Admin-Passwort-Reset überall sonst mit
 * `session_expired` hinausflog, konnte hier ein neues Passwort setzen - und
 * schrieb sich anschließend die frische `session_version` selbst in die
 * Session (siehe AuthController::processForcePasswordChange()). Die
 * Invalidierung war damit rückgängig gemacht: Der Angreifer hatte das Konto
 * dauerhaft übernommen, während der rechtmäßige Besitzer mit dem gerade vom
 * Admin gesetzten Passwort ausgesperrt war.
 *
 * Zusätzlich geprüft: Der Wechsel verlangt jetzt das bisherige Passwort.
 */
class ForcePasswordChangeGuardTest extends FunctionalTestCase {

    /**
     * Legt einen Benutzer an und führt ihn per HTTP bis GENAU auf die Seite
     * des erzwungenen Passwortwechsels - also in den Zustand, um den es hier
     * geht (must_change_password gesetzt, 2FA schon eingerichtet).
     *
     * @return array{0: HttpClient, 1: int} Client und Benutzer-ID
     */
    private function userWaitingForPasswordChange(
        HttpClient $admin,
        string $username,
        string $email,
        string $password
    ): array {
        $createForm = $admin->get('/admin/users/create');
        $created = $admin->post('/admin/users/store', [
            'csrf_token' => $createForm->formField('csrf_token') ?? '',
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'groups' => [],
        ]);
        self::assertSame('/admin/users?success=created', $created->location(), "Body: {$created->body}");

        $usersPage = $admin->get('/admin/users?search=' . urlencode($username));
        preg_match('/\/admin\/users\/edit\?id=(\d+)/', $usersPage->body, $matches);
        self::assertNotEmpty($matches, 'Konnte Benutzer-ID nicht aus /admin/users ermitteln');
        $userId = (int)$matches[1];

        $client = $this->newClient();
        $loginPage = $client->get('/login');
        $login = $client->post('/login', [
            'csrf_token' => $loginPage->formField('csrf_token') ?? '',
            'email' => $email,
            'password' => $password,
        ]);
        // Konto ohne Gruppen: 2FA ist Pflicht (fail-safe in userRequires2fa()).
        self::assertSame('/2fa/setup', $login->location(), "Body: {$login->body}");

        $setupPage = $client->get('/2fa/setup');
        $secret = self::extractTotpSecret($setupPage);
        self::assertNotNull($secret);
        $enable = $client->post('/2fa/enable', [
            'csrf_token' => $setupPage->formField('csrf_token') ?? '',
            'confirm_backup' => '1',
            'totp_code' => Totp::getCode($secret),
        ]);
        self::assertSame('/force-password-change', $enable->location(), "Body: {$enable->body}");

        return [$client, $userId];
    }

    public function testInvalidatedSessionCannotSetANewPassword(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $email = "forceguard-{$unique}@example.com";
        $username = "forceguard{$unique}";
        $firstPassword = 'ErstesPasswort123!';

        [$client, $userId] = $this->userWaitingForPasswordChange($admin, $username, $email, $firstPassword);

        $forcePage = $client->get('/force-password-change');
        $this->assertSame(200, $forcePage->statusCode, 'Die Seite des erzwungenen Wechsels ist erreichbar');
        $csrf = $forcePage->formField('csrf_token') ?? '';
        $this->assertNotSame('', $csrf);

        // Der rechtmäßige Vorgang: Der Admin setzt das Passwort neu und
        // beendet damit alle bestehenden Sitzungen des Kontos (#113).
        $editPage = $admin->get('/admin/users/edit?id=' . $userId);
        $adminSetPassword = 'VomAdminGesetzt789!';
        $update = $admin->post('/admin/users/update', [
            'csrf_token' => $editPage->formField('csrf_token') ?? '',
            'id' => (string)$userId,
            'username' => $username,
            'email' => $email,
            'password' => $adminSetPassword,
        ]);
        $this->assertSame('/admin/users?success=updated', $update->location(), "Body: {$update->body}");

        // Der Angriff: Die invalidierte Sitzung setzt trotzdem ein eigenes
        // Passwort - mit gültigem CSRF-Token, den sie vorher geholt hat.
        $attackerPassword = 'AngreiferWaehltSelbst999!';
        $attempt = $client->post('/force-password-change', [
            'csrf_token' => $csrf,
            'current_password' => $firstPassword,
            'password' => $attackerPassword,
            'password_confirm' => $attackerPassword,
        ]);

        $this->assertSame(
            '/login?error=session_expired',
            $attempt->location(),
            "/force-password-change muss dieselbe Sitzungsprüfung anwenden wie jede andere geschützte Route, Body: {$attempt->body}"
        );

        // Gegenprobe am Verhalten, nicht nur am Statuscode: Das vom Admin
        // gesetzte Passwort gilt, das des Angreifers nicht.
        $probe = $this->newClient();
        $probePage = $probe->get('/login');
        $withAttackerPassword = $probe->post('/login', [
            'csrf_token' => $probePage->formField('csrf_token') ?? '',
            'email' => $email,
            'password' => $attackerPassword,
        ]);
        $this->assertNull(
            $withAttackerPassword->location(),
            'Das vom Angreifer gewählte Passwort darf nicht gültig sein'
        );

        $probe2 = $this->newClient();
        $probe2Page = $probe2->get('/login');
        $withAdminPassword = $probe2->post('/login', [
            'csrf_token' => $probe2Page->formField('csrf_token') ?? '',
            'email' => $email,
            'password' => $adminSetPassword,
        ]);
        $this->assertSame(
            '/login/2fa',
            $withAdminPassword->location(),
            "Das vom Admin gesetzte Passwort muss gelten, Body: {$withAdminPassword->body}"
        );
    }

    public function testWrongCurrentPasswordIsRejected(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $email = "forcecurrent-{$unique}@example.com";
        $password = 'CurrentPruef123!';

        [$client] = $this->userWaitingForPasswordChange($admin, "forcecurrent{$unique}", $email, $password);

        $forcePage = $client->get('/force-password-change');
        $csrf = $forcePage->formField('csrf_token') ?? '';
        $this->assertStringContainsString(
            'current_password',
            $forcePage->body,
            'Das Formular muss nach dem bisherigen Passwort fragen'
        );

        $wrong = $client->post('/force-password-change', [
            'csrf_token' => $csrf,
            'current_password' => 'das-ist-es-nicht',
            'password' => 'NeuesPasswort456!',
            'password_confirm' => 'NeuesPasswort456!',
        ]);
        $this->assertSame(200, $wrong->statusCode);
        $this->assertStringContainsString('bisherige Passwort', $wrong->body);

        // Mit dem richtigen bisherigen Passwort gelingt der Wechsel.
        $ok = $client->post('/force-password-change', [
            'csrf_token' => $csrf,
            'current_password' => $password,
            'password' => 'NeuesPasswort456!',
            'password_confirm' => 'NeuesPasswort456!',
        ]);
        $this->assertSame('/admin?password_changed=1', $ok->location(), "Body: {$ok->body}");
    }
}
