<?php
// tests/Functional/FaktorweicheLoginTest.php

namespace Tests\Functional;

use App\Security\EmailSecondFactor;

/**
 * Die zentrale Faktorweiche nach dem Passwort (Audit N42) und "Abbrechen" auf
 * der Passkey-Seite (Audit N86) - über HTTP.
 *
 * Bis hierher prüfte loginSubmit() nur auf TOTP und schickte jedes andere
 * Konto zum Mailcode - auch eines, dessen einziger Faktor ein Passkey war.
 * Das stellte einen Code für einen gar nicht aktivierten Faktor aus, der
 * dann abgelehnt wurde: eine Sackgasse.
 *
 * Passkeys werden per DB angelegt (wie in tests/Integration/PasskeysTest.php);
 * die Zeremonie selbst lässt sich ohne Authenticator nicht durchspielen. Der
 * Testserver läuft auf 127.0.0.1 - das gilt dem Browser und
 * Passkeys::verfuegbar() als sicherer Kontext.
 */
class FaktorweicheLoginTest extends FunctionalTestCase {

    private const TESTCODE = '424242';
    private const PASSWORT = 'FaktorweicheNeu456!';

    /** @var array<int, string> */
    private array $aufraeumen = [];

    protected function tearDown(): void {
        if ($this->aufraeumen !== []) {
            $db = \App\Database::getInstance();
            $stmt = $db->prepare("DELETE FROM users WHERE username = ?");
            foreach ($this->aufraeumen as $name) {
                $stmt->execute([$name]);
            }
            $this->aufraeumen = [];
        }
        parent::tearDown();
    }

    /**
     * Konto ohne 2FA-Zwang, Erstanmeldung samt Passwortwechsel erledigt.
     *
     * @return array{0: string, 1: int} Benutzername, ID
     */
    private function konto(string $praefix): array {
        $unique = uniqid();
        $admin = $this->authenticatedClient();
        $groupId = $this->createGroupWithoutTwoFa($admin, "Faktorweiche {$praefix} {$unique}");

        $username = "{$praefix}{$unique}";
        $erst = 'FaktorweicheTest123!';
        $createForm = $admin->get('/admin/users/create');
        $angelegt = $admin->post('/admin/users/store', [
            'csrf_token' => $createForm->formField('csrf_token') ?? '',
            'username' => $username,
            'email' => "{$username}@example.com",
            'password' => $erst,
            'groups' => [(string)$groupId],
        ]);
        $this->assertSame('/admin/users?success=created', $angelegt->location(), "Body: {$angelegt->body}");
        $this->aufraeumen[] = $username;

        $client = $this->newClient();
        $login = $client->post('/login', [
            'csrf_token' => $client->get('/login')->formField('csrf_token') ?? '',
            'kennung' => $username,
            'password' => $erst,
        ]);
        $this->assertSame('/force-password-change', $login->location(), "Body: {$login->body}");
        $gewechselt = $client->post('/force-password-change', [
            'csrf_token' => $client->get('/force-password-change')->formField('csrf_token') ?? '',
            'current_password' => $erst,
            'password' => self::PASSWORT,
            'password_confirm' => self::PASSWORT,
        ]);
        $this->assertSame('/admin?password_changed=1', $gewechselt->location());

        $stmt = \App\Database::getInstance()->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        return [$username, (int)$stmt->fetchColumn()];
    }

    /** @return string Die rohe Credential-ID */
    private function passkeyAnlegen(int $userId): string {
        $roh = 'FW-TEST-' . bin2hex(random_bytes(8));
        \App\Database::getInstance()->prepare(
            "INSERT INTO user_passkeys (user_id, credential_id, credential, label, sign_count, created_at)
             VALUES (?, ?, '{\"test\":true}', 'FW-TEST', 0, NOW())"
        )->execute([$userId, base64_encode($roh)]);
        return $roh;
    }

    private function mailcodeEinschalten(int $userId): void {
        \App\Database::getInstance()->prepare("UPDATE users SET email_2fa_enabled = 1 WHERE id = ?")->execute([$userId]);
    }

    private function codezeilen(int $userId): int {
        $stmt = \App\Database::getInstance()->prepare("SELECT COUNT(*) FROM email_2fa_codes WHERE user_id = ?");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    private function anmelden(\Tests\Support\HttpClient $client, string $username): \Tests\Support\HttpResponse {
        return $client->post('/login', [
            'csrf_token' => $client->get('/login')->formField('csrf_token') ?? '',
            'kennung' => $username,
            'password' => self::PASSWORT,
        ]);
    }

    public function testPasskeyOnlyKontoLandetAufDerPasskeySeite(): void {
        [$username, $id] = $this->konto('pkonly');
        $roh = $this->passkeyAnlegen($id);

        $client = $this->newClient();
        $login = $this->anmelden($client, $username);

        $this->assertSame('/login/passkey', $login->location(), "Body: {$login->body}");
        $this->assertSame(0, $this->codezeilen($id), 'Für einen Passkey-Faktor darf kein Mailcode entstehen.');

        $seite = $client->get('/login/passkey');
        $this->assertSame(200, $seite->statusCode);
        $this->assertStringContainsString('Mit Passkey anmelden', $seite->body);

        // Deckt zugleich M36 im Anmeldeweg ab: Die Optionen entstehen, statt
        // mit HTTP 500 abzustürzen.
        $optionen = $client->post('/login/passkey/optionen', ['csrf_token' => $seite->formField('csrf_token') ?? '']);
        $this->assertSame(200, $optionen->statusCode, "Body: {$optionen->body}");
        $this->assertStringContainsString('"challenge"', $optionen->body);
        $this->assertStringContainsString(rtrim(strtr(base64_encode($roh), '+/', '-_'), '='), $optionen->body);
    }

    public function testPasskeyUndMailcodeBietetPasskeyZuerst(): void {
        [$username, $id] = $this->konto('pkmail');
        $this->mailcodeEinschalten($id);
        $this->passkeyAnlegen($id);

        $client = $this->newClient();
        $login = $this->anmelden($client, $username);
        $this->assertSame('/login/passkey', $login->location(), "Body: {$login->body}");
        $this->assertSame(0, $this->codezeilen($id), 'Der Mailcode kommt erst auf Knopfdruck.');

        $seite = $client->get('/login/passkey');
        $this->assertStringContainsString('action="/login/2fa/email/senden"', $seite->body);
        $this->assertStringNotContainsString('href="/login/2fa/email"', $seite->body);

        $senden = $client->post('/login/2fa/email/senden', ['csrf_token' => $seite->formField('csrf_token') ?? '']);
        $this->assertStringStartsWith('/login/2fa/email', (string)$senden->location());
        $this->assertSame(1, $this->codezeilen($id));

        \App\Database::getInstance()->prepare(
            "UPDATE email_2fa_codes SET code_hash = ?, attempts = 0, expires_at = NOW() + INTERVAL 10 MINUTE
             WHERE user_id = ? AND purpose = ?"
        )->execute([password_hash(self::TESTCODE, PASSWORD_DEFAULT), $id, EmailSecondFactor::PURPOSE_LOGIN]);

        $fertig = $client->post('/login/2fa/email', [
            'csrf_token' => $client->get('/login/2fa/email')->formField('csrf_token') ?? '',
            'code' => self::TESTCODE,
        ]);
        $this->assertSame('/admin', $fertig->location(), "Body: {$fertig->body}");
    }

    /** Regressionsschutz: Mail-only bekommt den Code weiterhin sofort. */
    public function testMailOnlyBekommtDenCodeSofort(): void {
        [$username, $id] = $this->konto('mailonly');
        $this->mailcodeEinschalten($id);

        $login = $this->anmelden($this->newClient(), $username);

        $this->assertStringStartsWith('/login/2fa/email', (string)$login->location(), "Body: {$login->body}");
        $this->assertSame(1, $this->codezeilen($id));
    }

    /** TOTP-only bleibt bei /login/2fa - authenticatedClient() sichert das zu. */
    public function testTotpOnlyBleibtBeiDerApp(): void {
        $this->assertSame(200, $this->authenticatedClient()->get('/admin')->statusCode);
    }

    public function testAbbrechenAufDerPasskeySeiteBeendetDenAnmeldevorgang(): void {
        [$username, $id] = $this->konto('pkabbruch');
        $this->passkeyAnlegen($id);

        $client = $this->newClient();
        $this->assertSame('/login/passkey', $this->anmelden($client, $username)->location());

        $seite = $client->get('/login/passkey');
        $this->assertStringContainsString('<form action="/logout" method="POST"', $seite->body);
        $this->assertStringNotContainsString('href="/logout"', $seite->body);

        $abbruch = $client->post('/logout', ['csrf_token' => $seite->formField('csrf_token') ?? '']);
        $this->assertSame(302, $abbruch->statusCode);
        $this->assertSame('/login', $abbruch->location());

        $this->assertSame('/login', $client->get('/login/passkey')->location(), 'Der Anmeldevorgang muss weg sein.');

        // Gegenprobe: Eine fertige Sitzung meldet sich weiterhin zur Startseite ab.
        $admin = $this->authenticatedClient();
        $abmelden = $admin->post('/logout', ['csrf_token' => $this->currentCsrfToken($admin)]);
        $this->assertSame('/', $abmelden->location());
    }
}
