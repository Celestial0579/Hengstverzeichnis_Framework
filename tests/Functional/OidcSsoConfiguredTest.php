<?php
// tests/Functional/OidcSsoConfiguredTest.php

namespace Tests\Functional;

use App\Security\Totp;
use Tests\Support\AuxiliaryServer;
use Tests\Support\HttpClient;

/**
 * HTTP-Funktionstests für den GENERISCHEN OIDC-Modus des SSO-Logins
 * (OIDC_ISSUER_URL + Discovery, siehe EntraSsoController) - End-zu-End gegen
 * einen Fake-Identity-Provider im Authentik-Pfadschema
 * (tests/Support/fake_oidc_idp.php).
 *
 * Der geteilte Testserver (PhpBuiltInServer) läuft bewusst OHNE
 * SSO-Konfiguration - die bestehenden "nicht konfiguriert => 404"-Tests in
 * EntraSsoTest bleiben dadurch unverändert gültig. Diese Klasse startet
 * deshalb zwei eigene Server: den Fake-IdP und eine zweite App-Instanz mit
 * gesetzten OIDC_*-Variablen (gleiche Datenbank, gleicher Code).
 *
 * Möglich ohne echten Provider, weil das dokumentierte Trust-Modell keine
 * JWT-Signatur prüft, sondern sich auf den serverseitigen TLS-Kanal zum
 * issuer-geprüften Token-Endpunkt verlässt (App\Security\OidcIdToken) -
 * der Fake-IdP stellt ein unsigniertes Token mit korrekten Claims aus, und
 * genau dieser Pfad wird hier durchlaufen.
 *
 * Seit Audit N9 führt der Callback über die Faktorweiche des Passwort-Logins
 * (AuthController::nachErstemFaktor()); eine dritte Instanz (TRUST_PORT)
 * läuft mit OIDC_TRUST_IDP_MFA=1. Seit Audit M19 verlangt der generische
 * Modus `email_verified: true` - der Fake-IdP setzt ihn standardmäßig.
 */
class OidcSsoConfiguredTest extends FunctionalTestCase {

    private const IDP_PORT = 8768;
    private const APP_PORT = 8769;
    private const TRUST_PORT = 8773;
    private const CLIENT_ID = 'hv-functional-test-client';

    private static ?AuxiliaryServer $idp = null;
    private static ?AuxiliaryServer $app = null;
    private static ?AuxiliaryServer $trustApp = null;

    /** @var array<int, int> Von diesem Test angelegte Konten */
    private array $angelegt = [];

    /** @var array<int, int> Von diesem Test angelegte Gruppen */
    private array $gruppen = [];

    private static function idpBase(): string {
        return 'http://127.0.0.1:' . self::IDP_PORT;
    }

    private static function issuer(): string {
        // Authentik-Schema inkl. trailing slash - genau die Form, an der eine
        // zu lasche Issuer-Prüfung scheitern würde.
        return self::idpBase() . '/application/o/test/';
    }

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        // Dieselbe Ableitung wie FunctionalTestCase::ensureProvisioned() -
        // der Fake-IdP muss die E-Mail des existierenden Admin-Kontos
        // ausstellen, bevor die Provisionierung gelaufen ist.
        $adminEmail = getenv('ADMIN_EMAIL') ?: 'functional-test-admin@example.com';

        self::$idp = new AuxiliaryServer(
            self::IDP_PORT,
            null,
            __DIR__ . '/../Support/fake_oidc_idp.php',
            [
                'FAKE_OIDC_ISSUER' => self::issuer(),
                'FAKE_OIDC_BASE' => self::idpBase(),
                'FAKE_OIDC_CLIENT_ID' => self::CLIENT_ID,
                'FAKE_OIDC_EMAIL' => $adminEmail,
            ]
        );
        self::$idp->start();

        self::$app = new AuxiliaryServer(
            self::APP_PORT,
            __DIR__ . '/../../public',
            null,
            [
                'OIDC_ISSUER_URL' => self::issuer(),
                'OIDC_CLIENT_ID' => self::CLIENT_ID,
                'OIDC_CLIENT_SECRET' => 'hv-functional-test-secret',
                'OIDC_PROVIDER_LABEL' => 'Authentik',
                'APP_URL' => 'http://127.0.0.1:' . self::APP_PORT,
            ]
        );
        self::$app->start();

        self::$trustApp = new AuxiliaryServer(
            self::TRUST_PORT,
            __DIR__ . '/../../public',
            null,
            [
                'OIDC_ISSUER_URL' => self::issuer(),
                'OIDC_CLIENT_ID' => self::CLIENT_ID,
                'OIDC_CLIENT_SECRET' => 'hv-functional-test-secret',
                'OIDC_PROVIDER_LABEL' => 'Authentik',
                'OIDC_TRUST_IDP_MFA' => '1',
                'APP_URL' => 'http://127.0.0.1:' . self::TRUST_PORT,
            ]
        );
        self::$trustApp->start();
    }

    protected function tearDown(): void {
        if ($this->angelegt !== []) {
            $stmt = \App\Database::getInstance()->prepare("DELETE FROM users WHERE id = ?");
            foreach ($this->angelegt as $id) {
                $stmt->execute([$id]);
            }
            $this->angelegt = [];
        }
        if ($this->gruppen !== []) {
            $stmt = \App\Database::getInstance()->prepare("DELETE FROM `groups` WHERE id = ?");
            foreach ($this->gruppen as $id) {
                $stmt->execute([$id]);
            }
            $this->gruppen = [];
        }
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void {
        self::$idp?->stop();
        self::$app?->stop();
        self::$trustApp?->stop();
        self::$idp = null;
        self::$app = null;
        self::$trustApp = null;
        parent::tearDownAfterClass();
    }

    private function ssoClient(): HttpClient {
        return new HttpClient(self::$app->baseUrl());
    }

    /**
     * Durchläuft den vollständigen Code-Flow für eine BELIEBIGE Identität und
     * liefert die Callback-Antwort: Redirect zum Authorize-Endpunkt holen, den
     * echten state übernehmen und den Callback mit einem Code aufrufen, der die
     * gewünschte E-Mail trägt (Konvention "email:<adresse>", siehe
     * tests/Support/fake_oidc_idp.php). Der Fake-IdP stellt dann genau diese
     * Adresse im Token aus - die Abweisungsfälle (#216) brauchen so keine
     * eigene IdP-Instanz je Testfall.
     */
    private function ssoCallbackForEmail(HttpClient $client, string $email): \Tests\Support\HttpResponse {
        $redirect = $client->get('/auth/entra');
        parse_str((string)parse_url($redirect->location() ?? '', PHP_URL_QUERY), $query);
        $state = (string)($query['state'] ?? '');
        $this->assertNotSame('', $state, 'Flow muss einen state liefern.');
        return $client->get('/auth/entra/callback?code=' . urlencode('email:' . $email) . '&state=' . urlencode($state));
    }

    /**
     * Legt ein lokales Konto direkt in der Datenbank an (beide Server nutzen
     * dieselbe DB wie der PHPUnit-Prozess, siehe Klassen-Docblock und
     * FunctionalTestCase::resetTotpReplayGuard()). Direkter DB-Zugriff, weil
     * die getesteten Konto-Zustände über die Oberfläche nicht (unverifiziert:
     * entsteht nur per Selfservice-Registrierung samt Mailversand) oder nur
     * umständlich (soft-gelöscht) herstellbar sind.
     */
    private function insertLocalUser(string $email, ?string $verificationToken, bool $softDeleted): void {
        $db = \App\Database::getInstance();
        $stmt = $db->prepare(
            "INSERT INTO users (username, email, password_hash, email_verification_token, deleted_at) " .
            'VALUES (?, ?, ?, ?, ' . ($softDeleted ? 'NOW()' : 'NULL') . ')'
        );
        $stmt->execute([
            'sso-test-' . bin2hex(random_bytes(6)),
            $email,
            password_hash('SsoTestIrrelevant123!', PASSWORD_DEFAULT),
            $verificationToken,
        ]);
    }

    public function testLoginPageShowsProviderLabelAndFullCodeFlowSignsIn(): void {
        // Provisionierung sicherstellen (Admin-Konto existiert) - läuft gegen
        // den geteilten Server, dieselbe Datenbank.
        $this->authenticatedClient();

        $client = $this->ssoClient();

        // 1. Login-Seite zeigt den generischen Button mit konfiguriertem Label.
        $loginPage = $client->get('/login');
        $this->assertSame(200, $loginPage->statusCode);
        $this->assertStringContainsString('Mit Authentik anmelden', $loginPage->body);
        $this->assertStringContainsString('href="/auth/entra"', $loginPage->body);

        // 2. Redirect zum Authorize-Endpunkt aus dem Discovery-Dokument,
        //    mit allen Pflichtparametern.
        $redirect = $client->get('/auth/entra');
        $location = $redirect->location() ?? '';
        $this->assertStringStartsWith(
            self::idpBase() . '/application/o/authorize/?',
            $location,
            'Redirect muss zum per Discovery ermittelten Authorize-Endpunkt führen.'
        );
        parse_str((string)parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame(self::CLIENT_ID, $query['client_id'] ?? null);
        $this->assertSame('code', $query['response_type'] ?? null);
        $this->assertSame('http://127.0.0.1:' . self::APP_PORT . '/auth/entra/callback', $query['redirect_uri'] ?? null);
        $this->assertSame('openid profile email', $query['scope'] ?? null);
        $state = (string)($query['state'] ?? '');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $state);

        // 3. Callback mit gültigem state: Code-Tausch gegen den Fake-IdP,
        //    Anmeldung des bestehenden lokalen Kontos.
        //    Der Admin hat TOTP - seit Audit N9 verlangt die Faktorweiche
        //    ihn auch nach SSO, und bis dahin gibt es keine Sitzung.
        $callback = $client->get('/auth/entra/callback?code=fake-auth-code&state=' . urlencode($state));
        $this->assertSame(
            '/login/2fa',
            $callback->location(),
            "SSO-Callback muss zum lokalen zweiten Faktor führen. Body: {$callback->body}"
        );
        $this->assertSame('/login', $client->get('/admin')->location(), 'Vor dem zweiten Faktor keine Sitzung.');

        // 4. Nach dem TOTP-Code ist die Session etabliert.
        $this->totpBestaetigen($client);
        $admin = $client->get('/admin');
        $this->assertSame(200, $admin->statusCode);

        // 5. Der Audit-Eintrag kennzeichnet den SSO-Login.
        $detail = $this->letzterLoginEintrag($this->adminId());
        $this->assertStringContainsString('per SSO (Authentik', $detail);
        $this->assertStringContainsString('iss=' . self::issuer(), $detail);
        $this->assertStringContainsString('sub=fake-', $detail);
        $this->assertStringContainsString('zweiter Faktor: lokal verlangt', $detail);
    }

    public function testTamperedStateIsRejected(): void {
        $client = $this->ssoClient();

        $redirect = $client->get('/auth/entra');
        $location = $redirect->location() ?? '';
        parse_str((string)parse_url($location, PHP_URL_QUERY), $query);
        $this->assertNotSame('', (string)($query['state'] ?? ''), 'Flow muss einen state liefern.');

        $callback = $client->get('/auth/entra/callback?code=fake-auth-code&state=' . str_repeat('0', 32));
        $this->assertNull($callback->location(), 'Manipulierter state darf nicht anmelden.');
        $this->assertStringContainsString('state', $callback->body);

        // Kein Login: Admin-Bereich bleibt zu.
        $admin = $client->get('/admin');
        $this->assertSame('/login', $admin->location());
    }

    /**
     * Zentrale Leitplanke des SSO-Logins (Klassen-Docblock EntraSsoController):
     * SSO meldet ausschließlich BESTEHENDE lokale Konten an - eine Identität
     * ohne lokales Konto wird abgewiesen, kein Auto-Provisioning. Ohne diesen
     * Test bliebe ein Wegfall der Abweisung (z. B. beim Umbau des Callbacks)
     * grün, weil der Erfolgsfall-Test immer mit der Admin-E-Mail arbeitet.
     */
    public function testIdentityWithoutLocalAccountIsRejected(): void {
        // Provisionierung sicherstellen (Schema + Admin-Konto existieren) -
        // läuft gegen den geteilten Server, dieselbe Datenbank.
        $this->authenticatedClient();

        $client = $this->ssoClient();
        $unknownEmail = 'niemand-' . bin2hex(random_bytes(6)) . '@example.org';

        $callback = $this->ssoCallbackForEmail($client, $unknownEmail);
        $this->assertNull(
            $callback->location(),
            "Identität ohne lokales Konto darf nicht angemeldet werden. Body: {$callback->body}"
        );
        $this->assertStringContainsString('Konto', $callback->body);

        // Keine Session etabliert: Admin-Bereich bleibt zu.
        $this->assertSame('/login', $client->get('/admin')->location());
    }

    /**
     * Ein per Selfservice registriertes, noch nicht verifiziertes Konto
     * (email_verification_token gesetzt, siehe #83) darf sich auch per SSO
     * nicht anmelden - sonst umginge SSO die Verifizierungspflicht der
     * Registrierung (EntraSsoController, Prüfung nach dem Konto-Lookup).
     */
    public function testUnverifiedLocalAccountCannotSignInViaSso(): void {
        $this->authenticatedClient();

        $email = 'sso-unverifiziert-' . bin2hex(random_bytes(6)) . '@example.org';
        $this->insertLocalUser($email, bin2hex(random_bytes(32)), false);

        $client = $this->ssoClient();
        $callback = $this->ssoCallbackForEmail($client, $email);
        $this->assertNull(
            $callback->location(),
            "Unverifiziertes Konto darf sich nicht per SSO anmelden. Body: {$callback->body}"
        );
        // Fehlermeldung auth.email_not_verified ("... bestätigen Sie zunächst
        // Ihre E-Mail-Adresse ...") - nicht die generische sso_failed-Meldung.
        $this->assertStringContainsString('bestätigen', $callback->body);

        $this->assertSame('/login', $client->get('/admin')->location());
    }

    /**
     * Ein soft-gelöschtes Konto (users.deleted_at gesetzt) darf sich nicht per
     * SSO anmelden - deckt das "deleted_at IS NULL" der Konto-Abfrage im
     * Callback ab. Es wird wie eine unbekannte Identität behandelt (gleiche
     * sso_no_account-Meldung), nicht als gesperrtes Konto ausgewiesen.
     */
    public function testSoftDeletedAccountCannotSignInViaSso(): void {
        $this->authenticatedClient();

        $email = 'sso-geloescht-' . bin2hex(random_bytes(6)) . '@example.org';
        $this->insertLocalUser($email, null, true);

        $client = $this->ssoClient();
        $callback = $this->ssoCallbackForEmail($client, $email);
        $this->assertNull(
            $callback->location(),
            "Soft-gelöschtes Konto darf sich nicht per SSO anmelden. Body: {$callback->body}"
        );
        $this->assertStringContainsString('Konto', $callback->body);

        $this->assertSame('/login', $client->get('/admin')->location());
    }

    // ------------------------------------------------------------------
    // Hilfen für Audit M19 / N9
    // ------------------------------------------------------------------

    private static function adminEmailAdresse(): string {
        return (string)(self::$adminEmail ?? (getenv('ADMIN_EMAIL') ?: 'functional-test-admin@example.com'));
    }

    private function adminId(): int {
        $stmt = \App\Database::getInstance()->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([self::adminEmailAdresse()]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Code-Flow mit beliebigen Claims (Konvention "claims:<base64url-JSON>"
     * des Fake-IdP; null entfernt einen Standardclaim).
     *
     * @param array<string, mixed> $claims
     */
    private function ssoCallbackMitClaims(HttpClient $client, array $claims): \Tests\Support\HttpResponse {
        $redirect = $client->get('/auth/entra');
        parse_str((string)parse_url($redirect->location() ?? '', PHP_URL_QUERY), $query);
        $state = (string)($query['state'] ?? '');
        $this->assertNotSame('', $state, 'Flow muss einen state liefern.');
        $code = 'claims:' . rtrim(strtr(base64_encode((string)json_encode($claims)), '+/', '-_'), '=');
        return $client->get('/auth/entra/callback?code=' . urlencode($code) . '&state=' . urlencode($state));
    }

    private function totpBestaetigen(HttpClient $client): void {
        self::resetTotpReplayGuard(self::adminEmailAdresse());
        $seite = $client->get('/login/2fa');
        $antwort = $client->post('/login/2fa', [
            'csrf_token' => $seite->formField('csrf_token') ?? '',
            'totp_code' => Totp::getCode((string)self::$totpSecret),
        ]);
        $this->assertSame(
            '/admin',
            $antwort->location(),
            "Nach dem lokalen Faktor geht es zum Standardziel. Body: {$antwort->body}"
        );
        self::resetTotpReplayGuard(self::adminEmailAdresse());
    }

    private function letzterLoginEintrag(int $userId): string {
        $stmt = \App\Database::getInstance()->prepare(
            "SELECT details FROM audit_logs WHERE action = 'Benutzer eingeloggt' AND user_id = ? ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$userId]);
        return (string)$stmt->fetchColumn();
    }

    private function letzterEintrag(string $aktion): string {
        $stmt = \App\Database::getInstance()->prepare(
            "SELECT details FROM audit_logs WHERE action = ? ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$aktion]);
        return (string)$stmt->fetchColumn();
    }

    private function hoechsteAuditId(): int {
        return (int)\App\Database::getInstance()->query("SELECT COALESCE(MAX(id), 0) FROM audit_logs")->fetchColumn();
    }

    /**
     * Aktives, bestätigtes Konto direkt in der Datenbank - optional in einer
     * eigenen Gruppe ohne 2FA-Pflicht.
     *
     * @return array{id: int, email: string, username: string, passwort: string}
     */
    private function kontoAnlegen(string $praefix, bool $gruppeOhnePflicht): array {
        $db = \App\Database::getInstance();
        $unique = bin2hex(random_bytes(5));
        $username = "sso-{$praefix}-{$unique}";
        $email = "{$username}@example.org";
        $passwort = 'SsoFaktorTest123!';
        $db->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)")
            ->execute([$username, $email, password_hash($passwort, PASSWORD_DEFAULT)]);
        $id = (int)$db->lastInsertId();
        $this->angelegt[] = $id;

        if ($gruppeOhnePflicht) {
            $db->prepare("INSERT INTO `groups` (slug, name, require_2fa) VALUES (?, ?, 0)")
                ->execute(["sso-ohne-pflicht-{$unique}", "SSO ohne Pflicht {$unique}"]);
            $gruppe = (int)$db->lastInsertId();
            $this->gruppen[] = $gruppe;
            $db->prepare("INSERT INTO user_groups (user_id, group_id) VALUES (?, ?)")->execute([$id, $gruppe]);
        }

        return ['id' => $id, 'email' => $email, 'username' => $username, 'passwort' => $passwort];
    }

    // ------------------------------------------------------------------
    // Audit M19: keine Übernahme über preferred_username
    // ------------------------------------------------------------------

    /**
     * Keycloak/Authentik: Ein IdP-Konto ohne Adresse, dessen frei wählbarer
     * Benutzername die Adresse des Administrators ist. Bis Audit M19 wurde
     * es als Administrator angemeldet.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function ohneBestaetigteAdresse(): array {
        return [
            'email leer, email_verified=false' => [['email' => '', 'email_verified' => false]],
            'email fehlt, email_verified=false' => [['email' => null, 'email_verified' => false]],
            'email fehlt, email_verified fehlt' => [['email' => null, 'email_verified' => null]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ohneBestaetigteAdresse')]
    public function testPreferredUsernameMeldetImGenerischenModusNiemandenAn(array $claims): void {
        $this->authenticatedClient();
        $admin = self::adminEmailAdresse();
        $vorher = $this->hoechsteAuditId();

        $client = $this->ssoClient();
        $callback = $this->ssoCallbackMitClaims($client, $claims + ['preferred_username' => $admin]);

        $this->assertNull($callback->location(), "Darf nicht anmelden. Body: {$callback->body}");
        $this->assertStringContainsString('keine verwendbare E-Mail-Adresse', $callback->body);
        $this->assertSame('/login', $client->get('/admin')->location());

        $stmt = \App\Database::getInstance()->prepare(
            "SELECT details FROM audit_logs WHERE id > ? AND action = 'SSO-Login abgewiesen' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$vorher]);
        $detail = (string)$stmt->fetchColumn();
        $this->assertStringContainsString('Modus=generisch', $detail);
        $this->assertStringNotContainsString($admin, $detail, 'Der Audit-Grund nennt keine Adresse.');
    }

    /** Ohne email_verified gilt im generischen Modus keine Adresse (fail-closed). */
    public function testAdresseOhneEmailVerifiedWirdAbgewiesen(): void {
        $this->authenticatedClient();
        $client = $this->ssoClient();
        $callback = $this->ssoCallbackMitClaims($client, ['email_verified' => null]);

        $this->assertNull($callback->location(), "Darf nicht anmelden. Body: {$callback->body}");
        $this->assertStringContainsString('email_verified fehlt', $this->letzterEintrag('SSO-Login abgewiesen'));
        $this->assertSame('/login', $client->get('/admin')->location());
    }

    // ------------------------------------------------------------------
    // Audit N9: SSO durchläuft die Faktorweiche
    // ------------------------------------------------------------------

    public function testKontoOhneFaktorUndOhnePflichtIstDirektAngemeldet(): void {
        $this->authenticatedClient();
        $konto = $this->kontoAnlegen('frei', true);

        $client = $this->ssoClient();
        $callback = $this->ssoCallbackForEmail($client, $konto['email']);
        $this->assertSame('/admin?sso=entra', $callback->location(), "Body: {$callback->body}");
        $this->assertStringContainsString('zweiter Faktor: nicht verlangt', $this->letzterLoginEintrag($konto['id']));
    }

    /**
     * Ohne Gruppe gilt die 2FA-Pflicht (#84, fail-safe). Die Einrichtung ist
     * für das laufende Login ohne Passwort erreichbar - reine SSO-Nutzer
     * werden also nicht ausgesperrt.
     */
    public function testKontoOhneGruppeRichtetTotpEin(): void {
        $this->authenticatedClient();
        $konto = $this->kontoAnlegen('ohnegruppe', false);

        $client = $this->ssoClient();
        $callback = $this->ssoCallbackForEmail($client, $konto['email']);
        $this->assertSame('/2fa/setup', $callback->location(), "Body: {$callback->body}");
        $this->assertSame(200, $client->get('/2fa/setup')->statusCode);
        $this->assertSame('/login', $client->get('/admin')->location());
    }

    public function testMailcodeKontoBekommtDenAnmeldecode(): void {
        $this->authenticatedClient();
        $konto = $this->kontoAnlegen('mailcode', true);
        $db = \App\Database::getInstance();
        $db->prepare("UPDATE users SET email_2fa_enabled = 1 WHERE id = ?")->execute([$konto['id']]);

        $client = $this->ssoClient();
        $callback = $this->ssoCallbackForEmail($client, $konto['email']);
        $this->assertStringStartsWith('/login/2fa/email', (string)$callback->location(), "Body: {$callback->body}");

        $stmt = $db->prepare("SELECT COUNT(*) FROM email_2fa_codes WHERE user_id = ?");
        $stmt->execute([$konto['id']]);
        $this->assertSame(1, (int)$stmt->fetchColumn(), 'Die Weiche muss einen Anmeldecode ausstellen.');
        $this->assertSame('/login', $client->get('/admin')->location());
    }

    public function testPasskeyKontoLandetAufDerPasskeySeite(): void {
        $this->authenticatedClient();
        $konto = $this->kontoAnlegen('passkey', true);
        self::legeTestPasskeyAn($konto['id']);

        $client = $this->ssoClient();
        $callback = $this->ssoCallbackForEmail($client, $konto['email']);
        $this->assertSame('/login/passkey', $callback->location(), "Body: {$callback->body}");
        $this->assertSame(200, $client->get('/login/passkey')->statusCode);
        $this->assertSame('/login', $client->get('/admin')->location());
    }

    /**
     * Ein abgebrochener SSO-Versuch darf keinen späteren Passwort-Login als
     * SSO etikettieren - weder eines anderen Kontos noch desselben.
     */
    public function testAbgebrochenesSsoEtikettiertKeinenPasswortLogin(): void {
        $this->authenticatedClient();
        $client = $this->ssoClient();

        // SSO als Admin bis zur TOTP-Seite, dann abbrechen.
        $this->assertSame('/login/2fa', $this->ssoCallbackForEmail($client, self::adminEmailAdresse())->location());

        // (1) Passwort-Login eines ANDEREN Kontos in derselben Sitzung.
        $konto = $this->kontoAnlegen('passwort', true);
        $login = $client->post('/login', [
            'csrf_token' => $client->get('/login')->formField('csrf_token') ?? '',
            'kennung' => $konto['username'],
            'password' => $konto['passwort'],
        ]);
        $this->assertSame('/admin', $login->location(), "Body: {$login->body}");
        $this->assertSame('Erfolgreich angemeldet', $this->letzterLoginEintrag($konto['id']));

        // (2) SSO als Admin erneut abbrechen, dann Passwort-Login DESSELBEN Kontos.
        $client = $this->ssoClient();
        $this->assertSame('/login/2fa', $this->ssoCallbackForEmail($client, self::adminEmailAdresse())->location());
        self::resetTotpReplayGuard(self::adminEmailAdresse());
        $login = $client->post('/login', [
            'csrf_token' => $client->get('/login')->formField('csrf_token') ?? '',
            'kennung' => self::adminEmailAdresse(),
            'password' => (string)self::$adminPassword,
        ]);
        $this->assertSame('/login/2fa', $login->location());
        $this->totpBestaetigen($client);
        $this->assertStringNotContainsString('SSO', $this->letzterLoginEintrag($this->adminId()));
    }

    /** Eine bestehende Anmeldung endet, sobald ein SSO-Login für ein anderes Konto beginnt. */
    public function testSsoRaeumtEineBestehendeFremdeSitzungAuf(): void {
        $this->authenticatedClient();
        $konto = $this->kontoAnlegen('bestehend', true);

        $client = $this->ssoClient();
        $this->assertSame('/admin?sso=entra', $this->ssoCallbackForEmail($client, $konto['email'])->location());
        $this->assertSame(200, $client->get('/admin')->statusCode);

        $this->assertSame('/login/2fa', $this->ssoCallbackForEmail($client, self::adminEmailAdresse())->location());
        $this->assertSame('/login', $client->get('/admin')->location(), 'Die Sitzung von Konto A muss weg sein.');
    }

    // ------------------------------------------------------------------
    // OIDC_TRUST_IDP_MFA
    // ------------------------------------------------------------------

    private function trustClient(): HttpClient {
        return new HttpClient(self::$trustApp->baseUrl());
    }

    public function testIdpMfaMitNachweisErsetztDenLokalenFaktor(): void {
        $this->authenticatedClient();
        $client = $this->trustClient();

        $callback = $this->ssoCallbackMitClaims($client, ['amr' => ['pwd', 'mfa']]);
        $this->assertSame('/admin?sso=entra', $callback->location(), "Body: {$callback->body}");
        $this->assertSame(200, $client->get('/admin')->statusCode);
        $this->assertStringContainsString(
            'zweiter Faktor: beim IdP nachgewiesen (amr=mfa)',
            $this->letzterLoginEintrag($this->adminId())
        );
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function ohneMfaNachweis(): array {
        return [
            'amr nur pwd' => [['amr' => ['pwd']]],
            'amr fehlt' => [[]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ohneMfaNachweis')]
    public function testIdpMfaOhneNachweisVerlangtDenLokalenFaktor(array $claims): void {
        $this->authenticatedClient();
        $vorher = $this->hoechsteAuditId();
        $client = $this->trustClient();

        $callback = $this->ssoCallbackMitClaims($client, $claims);
        $this->assertSame('/login/2fa', $callback->location(), "Body: {$callback->body}");
        $this->assertSame('/login', $client->get('/admin')->location());

        $stmt = \App\Database::getInstance()->prepare(
            "SELECT COUNT(*) FROM audit_logs WHERE id > ? AND action = 'SSO: IdP-MFA nicht nachgewiesen'"
        );
        $stmt->execute([$vorher]);
        $this->assertSame(1, (int)$stmt->fetchColumn());
    }
}
