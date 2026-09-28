<?php
// tests/Functional/KontoUebernahmeTest.php

namespace Tests\Functional;

use App\Database;
use App\Security\EmailSecondFactor;

/**
 * Einen eigenen Faktor anhängen, ohne den vorhandenen zu kennen (Audit M15).
 *
 * DER ANGRIFF. Ein Kollege kennt das Passwort eines Kontos mit
 * Authentikator-App (mitgelesen) und nutzt einen unbeaufsichtigten
 * Arbeitsplatz. Bis hierher genügte ihm die Sitzung, um einen eigenen
 * Passkey zu registrieren oder den Mailcode an die Adresse des Kontos
 * einzuschalten - und sich danach von überall ohne den TOTP-Code anzumelden.
 * Die Schranke aus #112 galt nur für die App selbst.
 *
 * Die Zeremonie braucht einen Authenticator und lässt sich hier nicht
 * durchspielen (siehe tests/Integration/PasskeysTest.php). Geprüft wird die
 * Schranke DAVOR: Ohne frische Bestätigung entstehen weder Optionen noch
 * eine Challenge.
 */
class KontoUebernahmeTest extends FunctionalTestCase {

    private const PASSWORT = 'EditorTestNeu456!';

    /** @var array<int, string> */
    private array $aufraeumen = [];

    protected function tearDown(): void {
        $stmt = Database::getInstance()->prepare("DELETE FROM users WHERE username = ?");
        foreach ($this->aufraeumen as $name) {
            $stmt->execute([$name]);
        }
        $this->aufraeumen = [];
        parent::tearDown();
    }

    /** @return array{0: \Tests\Support\HttpClient, 1: string, 2: string, 3: string} client, username, email, secret */
    private function totpEditor(string $prefix): array {
        $admin = $this->authenticatedClient();
        $u = uniqid();
        $name = "{$prefix}{$u}";
        $email = "{$prefix}-{$u}@example.com";
        $this->aufraeumen[] = $name;
        $client = $this->createAndLoginEditor($admin, $name, $email);
        $this->assertNotNull($this->lastEditorTotpSecret);
        return [$client, $name, $email, (string)$this->lastEditorTotpSecret];
    }

    // ---- Passkey hinzufügen ---------------------------------------------

    public function testOhneBestaetigungGibtEsKeineRegistrierungsOptionen(): void {
        [$client] = $this->totpEditor('kuopt');
        $token = $this->editorCsrfToken($client);

        $optionen = $client->post('/passkeys/optionen', ['csrf_token' => $token]);
        $this->assertSame(403, $optionen->statusCode, "Body: {$optionen->body}");
        $daten = json_decode($optionen->body, true);
        $this->assertIsArray($daten);
        $this->assertFalse($daten['ok']);
        $this->assertSame('/2fa/reauth?fuer=passkeys', $daten['stepup'] ?? null);
        $this->assertStringNotContainsString('challenge', $optionen->body);

        $abschluss = $client->post('/passkeys/registrieren', ['csrf_token' => $token, 'antwort' => '{}']);
        $this->assertSame(403, $abschluss->statusCode, 'Auch der Abschluss verlangt die Bestätigung (doppelter Boden).');
    }

    public function testNachDerBestaetigungGibtEsOptionen(): void {
        [$client, , $email, $secret] = $this->totpEditor('kustep');

        $this->stepUpMitTotp($client, $email, self::PASSWORT, $secret, 'passkeys');

        $optionen = $client->post('/passkeys/optionen', ['csrf_token' => $this->editorCsrfToken($client)]);
        $this->assertSame(200, $optionen->statusCode, "Body: {$optionen->body}");
        $this->assertArrayHasKey('challenge', json_decode($optionen->body, true) ?? []);

        // Der Abschluss kommt jetzt an der Schranke vorbei und scheitert erst
        // an der (hier nicht nachstellbaren) Zeremonie - 400 statt 403.
        $abschluss = $client->post('/passkeys/registrieren', [
            'csrf_token' => $this->editorCsrfToken($client),
            'antwort' => '{}',
        ]);
        $this->assertSame(400, $abschluss->statusCode, "Body: {$abschluss->body}");
    }

    /** Konten OHNE Faktor bleiben, wie sie waren: Die Sitzung genügt. */
    public function testEinKontoOhneFaktorBrauchtKeineBestaetigung(): void {
        $admin = $this->authenticatedClient();
        $konto = $this->angemeldetOhneFaktor($admin, 'kuohne');
        $this->aufraeumen[] = $konto['username'];

        $optionen = $konto['client']->post('/passkeys/optionen', [
            'csrf_token' => $this->editorCsrfToken($konto['client']),
        ]);
        $this->assertSame(200, $optionen->statusCode, "Body: {$optionen->body}");
        $this->assertArrayHasKey('challenge', json_decode($optionen->body, true) ?? []);
    }

    /**
     * Das Profil zeigt ohne Bestätigung keinen aktiven Registrierknopf,
     * sondern den Weg zur Bestätigung - und danach umgekehrt.
     */
    public function testDasProfilFuehrtZurBestaetigung(): void {
        [$client, , $email, $secret] = $this->totpEditor('kuprofil');

        $vorher = $client->get('/profil')->body;
        $this->assertStringContainsString('/2fa/reauth?fuer=passkeys', $vorher);
        $this->assertStringContainsString('data-passkey-registrieren="stepup"', $vorher);
        $this->assertStringNotContainsString('data-passkey-registrieren="bereit"', $vorher);

        $this->stepUpMitTotp($client, $email, self::PASSWORT, $secret, 'passkeys');

        $nachher = $client->get('/profil')->body;
        $this->assertStringContainsString('data-passkey-registrieren="bereit"', $nachher);
        $this->assertStringNotContainsString('data-passkey-registrieren="stepup"', $nachher);
        $this->assertStringContainsString('Bestätigt bis', $nachher);
    }

    // ---- Passkey entziehen (Entscheidung D06) ----------------------------

    public function testEntziehenVerlangtDieBestaetigung(): void {
        [$client, $name, $email, $secret] = $this->totpEditor('kuentz');
        $id = self::kontoIdNachName($name);
        $passkey = self::legeTestPasskeyAn($id);

        $client->post('/passkeys/entziehen', ['csrf_token' => $this->editorCsrfToken($client), 'id' => (string)$passkey]);
        $this->assertSame(1, $this->passkeys($id), 'Ohne Bestätigung darf nichts entzogen werden.');

        $this->stepUpMitTotp($client, $email, self::PASSWORT, $secret, 'passkeys');
        $antwort = $client->post('/passkeys/entziehen', ['csrf_token' => $this->editorCsrfToken($client), 'id' => (string)$passkey]);
        $this->assertSame('/profil#passkeys', $antwort->location());
        $this->assertSame(0, $this->passkeys($id));
    }

    // ---- Mailcode einschalten ------------------------------------------

    /**
     * Ein TOTP-Konto schaltet den Mailcode nicht mit Passwort und Probecode
     * allein ein - der Probecode geht an die Adresse des Kontos, und die
     * kann der Angreifer gerade mitlesen.
     */
    public function testMailcodeEinschaltenVerlangtBeiVorhandenemFaktorDieBestaetigung(): void {
        [$client, $name] = $this->totpEditor('kumail');
        $id = self::kontoIdNachName($name);

        $client->post('/profil/2fa/email/code', ['csrf_token' => $this->editorCsrfToken($client)]);
        self::bekanntenMailcodeSetzen($id, EmailSecondFactor::PURPOSE_SETUP, '424242');

        $antwort = $client->post('/profil/2fa/email/ein', [
            'csrf_token' => $this->editorCsrfToken($client),
            'current_password' => self::PASSWORT,
            'code' => '424242',
        ]);
        $this->assertSame('/profil?error=stepup_required', $antwort->location());

        $stmt = Database::getInstance()->prepare("SELECT email_2fa_enabled FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $this->assertSame(0, (int)$stmt->fetchColumn());
    }

    private function passkeys(int $userId): int {
        $stmt = Database::getInstance()->prepare("SELECT COUNT(*) FROM user_passkeys WHERE user_id = ?");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }
}
