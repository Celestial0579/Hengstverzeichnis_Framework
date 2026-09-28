<?php
// tests/Functional/StepUpFaktorTest.php

namespace Tests\Functional;

use App\Database;
use App\Security\EmailSecondFactor;
use App\Security\RateLimiter;

/**
 * Die Bestätigung richtet sich nach dem Faktor, den das Konto HAT (Audit N10).
 *
 * DER BEFUND. Die Bestätigung kannte nur „TOTP, sonst Mailcode". Ein Konto,
 * dessen einziger Faktor ein Passkey ist, bestätigte deshalb mit einem
 * Mailcode an seine Adresse - der Postfachzugang ersetzte den
 * phishing-resistenten Faktor, für zehn neue Backup-Codes oder ein neues
 * TOTP-Secret. Und ohne Adresse kam es gar nicht an neue Backup-Codes.
 *
 * Die Passkey-Zeremonie selbst ist hier nicht nachstellbar; geprüft werden
 * die Schranken der beiden neuen Endpunkte und die Felder der Seite.
 */
class StepUpFaktorTest extends FunctionalTestCase {

    private const TESTCODE = '424242';

    /** @var array<int, string> */
    private array $aufraeumen = [];

    /** @var array<int, int> */
    private array $toepfe = [];

    protected function tearDown(): void {
        foreach ($this->toepfe as $id) {
            RateLimiter::clearAttempts((string)$id, '2fa');
            RateLimiter::clearAttempts((string)$id, EmailSecondFactor::RESEND_LIMITER_TYPE);
        }
        $this->toepfe = [];
        $stmt = Database::getInstance()->prepare("DELETE FROM users WHERE username = ?");
        foreach ($this->aufraeumen as $name) {
            $stmt->execute([$name]);
        }
        $this->aufraeumen = [];
        parent::tearDown();
    }

    /** @return array{client: \Tests\Support\HttpClient, username: string, email: string, passwort: string, id: int} */
    private function passkeyKonto(string $prefix): array {
        $konto = $this->angemeldetOhneFaktor($this->authenticatedClient(), $prefix);
        $this->aufraeumen[] = $konto['username'];
        $this->toepfe[] = $konto['id'];
        self::legeTestPasskeyAn($konto['id']);
        return $konto;
    }

    /** @return array{0: \Tests\Support\HttpClient, 1: int, 2: string, 3: string} client, id, email, secret */
    private function totpKonto(string $prefix): array {
        $u = uniqid();
        $name = "{$prefix}{$u}";
        $email = "{$prefix}-{$u}@example.com";
        $this->aufraeumen[] = $name;
        $client = $this->createAndLoginEditor($this->authenticatedClient(), $name, $email);
        $id = self::kontoIdNachName($name);
        $this->toepfe[] = $id;
        return [$client, $id, $email, (string)$this->lastEditorTotpSecret];
    }

    private function backupCodes(int $id): string {
        $stmt = Database::getInstance()->prepare("SELECT backup_codes FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return (string)$stmt->fetchColumn();
    }

    // ---- (a) Passkey-Konto: der Mailcode ersetzt den Passkey nicht --------

    public function testEinMailcodeBestaetigtKeinPasskeyKonto(): void {
        $konto = $this->passkeyKonto('sfpk');
        $client = $konto['client'];
        $vorher = $this->backupCodes($konto['id']);

        // Der Probecode wird ausgestellt - das Konto darf den Mailcode ja
        // einschalten. Er zählt aber nicht als Nachweis für den Passkey.
        $client->post('/profil/2fa/email/code', ['csrf_token' => $this->editorCsrfToken($client)]);
        self::bekanntenMailcodeSetzen($konto['id'], EmailSecondFactor::PURPOSE_SETUP, self::TESTCODE);

        $backup = $client->post('/profil/backup-codes', [
            'csrf_token' => $this->editorCsrfToken($client),
            'current_password' => $konto['passwort'],
            'email_code' => self::TESTCODE,
        ]);
        $this->assertSame('/profil?error=stepup_required', $backup->location());
        $this->assertSame($vorher, $this->backupCodes($konto['id']));

        $reauth = $client->get('/2fa/reauth?fuer=profil');
        $bestaetigt = $client->post('/2fa/reauth', [
            'csrf_token' => $reauth->formField('csrf_token') ?? $this->editorCsrfToken($client),
            'password' => $konto['passwort'],
            'email_code' => self::TESTCODE,
            'fuer' => 'profil',
        ]);
        $this->assertNull($bestaetigt->location(), 'Ein Mailcode darf den Passkey nicht ersetzen.');
        $this->assertStringNotContainsString('Bestätigt bis', $client->get('/profil')->body);

        // Und die TOTP-Einrichtung bleibt hinter der Bestätigung.
        $setup = $client->get('/2fa/setup');
        $this->assertNull(self::extractTotpSecret($setup), 'Ohne Bestätigung kein neues Secret.');
        $this->assertStringContainsString('data-passkey-stepup', $setup->body);
    }

    /** (b) Probecodes gibt es nur, wo der Mailcode Faktor ist oder werden darf. */
    public function testEinPasskeyAdminBekommtKeinenProbecode(): void {
        $konto = $this->passkeyKonto('sfadm');
        $db = Database::getInstance();
        $admin = (int)$db->query("SELECT id FROM `groups` WHERE slug = 'admin'")->fetchColumn();
        $db->prepare("INSERT INTO user_groups (user_id, group_id) VALUES (?, ?)")->execute([$konto['id'], $admin]);

        $antwort = $konto['client']->post('/profil/2fa/email/code', [
            'csrf_token' => $this->editorCsrfToken($konto['client']),
        ]);
        $this->assertSame('/profil?error=email_factor_not_allowed', $antwort->location());

        $stmt = $db->prepare("SELECT COUNT(*) FROM email_2fa_codes WHERE user_id = ?");
        $stmt->execute([$konto['id']]);
        $this->assertSame(0, (int)$stmt->fetchColumn());
    }

    // ---- (c) Die Felder der Seite folgen der Codeprüfung ------------------

    public function testDieSeiteZeigtGenauDieFelderDieZaehlen(): void {
        $nurPasskey = $this->passkeyKonto('sffeld')['client']->get('/2fa/reauth?fuer=profil')->body;
        $this->assertStringContainsString('data-passkey-stepup', $nurPasskey);
        $this->assertStringNotContainsString('name="email_code"', $nurPasskey);
        $this->assertStringNotContainsString('name="totp_code"', $nurPasskey);

        [$client, $id] = $this->totpKonto('sftotp');
        $nurTotp = $client->get('/2fa/reauth?fuer=profil')->body;
        $this->assertStringContainsString('name="totp_code"', $nurTotp);
        $this->assertStringNotContainsString('data-passkey-stepup', $nurTotp);
        $this->assertStringContainsString('name="fuer" value="profil"', $nurTotp);

        Database::getInstance()->prepare("UPDATE users SET email_2fa_enabled = 1 WHERE id = ?")->execute([$id]);
        $totpUndMail = $client->get('/2fa/reauth?fuer=profil')->body;
        $this->assertStringContainsString('name="totp_code"', $totpUndMail);
        $this->assertStringNotContainsString(
            'name="email_code"',
            $totpUndMail,
            'Ein Mailcodefeld, das nie zählt, darf nicht erscheinen.'
        );

        self::legeTestPasskeyAn($id);
        $mitPasskey = $client->get('/2fa/reauth?fuer=profil')->body;
        $this->assertStringContainsString('name="totp_code"', $mitPasskey);
        $this->assertStringContainsString('data-passkey-stepup', $mitPasskey);
    }

    // ---- (d) Optionen für den Passkey-Step-up ----------------------------

    public function testDieStepUpOptionenHabenIhreSchranken(): void {
        $gast = $this->newClient();
        $token = $this->csrfTokenFrom($gast, '/login');
        $this->assertSame('/login', $gast->post('/2fa/reauth/passkey/optionen', ['csrf_token' => $token])->location());

        [$totp] = $this->totpKonto('sfopt');
        $this->assertSame(403, $totp->post('/2fa/reauth/passkey/optionen', [])->statusCode);
        $this->assertSame(
            400,
            $totp->post('/2fa/reauth/passkey/optionen', ['csrf_token' => $this->editorCsrfToken($totp)])->statusCode,
            'Ein Konto ohne Passkey bekommt keine Challenge.'
        );

        $konto = $this->passkeyKonto('sfoptpk');
        $antwort = $konto['client']->post('/2fa/reauth/passkey/optionen', [
            'csrf_token' => $this->editorCsrfToken($konto['client']),
        ]);
        $this->assertSame(200, $antwort->statusCode, "Body: {$antwort->body}");
        $daten = json_decode($antwort->body, true);
        $this->assertArrayHasKey('challenge', $daten);
        $this->assertCount(1, $daten['allowCredentials'] ?? [], 'Nur die Schlüssel DIESES Kontos.');
    }

    // ---- (e) Prüfen: Passwort gehört dazu, keine Freigabe ohne Zeremonie -

    public function testDerPasskeyStepUpVerlangtPasswortUndZeremonie(): void {
        $konto = $this->passkeyKonto('sfpruef');
        $client = $konto['client'];
        $token = $this->editorCsrfToken($client);

        $falsch = $client->post('/2fa/reauth/passkey', [
            'csrf_token' => $token, 'password' => 'falsch', 'antwort' => '{}', 'fuer' => 'profil',
        ]);
        $this->assertSame(400, $falsch->statusCode);
        $stmt = Database::getInstance()->prepare("SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND type = '2fa'");
        $stmt->execute([(string)$konto['id']]);
        $this->assertSame(1, (int)$stmt->fetchColumn(), 'Ein falsches Passwort muss in der Drossel zählen.');

        $ohneZeremonie = $client->post('/2fa/reauth/passkey', [
            'csrf_token' => $token, 'password' => $konto['passwort'], 'antwort' => '{}', 'fuer' => 'profil',
        ]);
        $this->assertSame(400, $ohneZeremonie->statusCode);
        $this->assertStringNotContainsString('"weiter"', $ohneZeremonie->body);
        $this->assertStringNotContainsString('Bestätigt bis', $client->get('/profil')->body);
    }

    // ---- (f) Rückweg nur über die Liste ----------------------------------

    public function testDerRueckwegFolgtNurDerListe(): void {
        [$client, , $email, $secret] = $this->totpKonto('sfziel');

        foreach (['profil' => '/profil', 'https://evil.example' => '/2fa/setup', '//evil.example' => '/2fa/setup'] as $fuer => $erwartet) {
            self::resetTotpReplayGuard($email);
            $antwort = $client->post('/2fa/reauth', [
                'csrf_token' => $this->editorCsrfToken($client),
                'password' => 'EditorTestNeu456!',
                'totp_code' => \App\Security\Totp::getCode($secret),
                'fuer' => $fuer,
            ]);
            $this->assertSame($erwartet, $antwort->location(), "fuer={$fuer}, Body: {$antwort->body}");
        }
    }

    // ---- (g) Auch der Drosselzweig zeigt nur, was zählt -------------------

    public function testDerDrosselzweigZeigtKeinMailcodefeld(): void {
        $konto = $this->passkeyKonto('sfdrossel');
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::recordAttempt((string)$konto['id'], '2fa');
        }

        $antwort = $konto['client']->post('/2fa/reauth', [
            'csrf_token' => $this->editorCsrfToken($konto['client']),
            'password' => $konto['passwort'],
            'fuer' => 'profil',
        ]);
        $this->assertSame(200, $antwort->statusCode);
        $this->assertStringContainsString('data-passkey-stepup', $antwort->body);
        $this->assertStringNotContainsString('name="email_code"', $antwort->body);
    }

    /** GET /2fa/reauth für ein Konto ohne Faktor führt direkt zum Ziel. */
    public function testOhneFaktorFuehrtDieSeiteDirektZumZiel(): void {
        $konto = $this->angemeldetOhneFaktor($this->authenticatedClient(), 'sfleer');
        $this->aufraeumen[] = $konto['username'];

        $this->assertSame('/profil#email', $konto['client']->get('/2fa/reauth?fuer=email')->location());
        $this->assertSame('/login', $this->newClient()->get('/2fa/reauth?fuer=email')->location());
    }
}
