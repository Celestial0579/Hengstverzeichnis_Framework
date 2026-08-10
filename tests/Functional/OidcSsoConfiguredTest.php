<?php
// tests/Functional/OidcSsoConfiguredTest.php

namespace Tests\Functional;

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
 */
class OidcSsoConfiguredTest extends FunctionalTestCase {

    private const IDP_PORT = 8768;
    private const APP_PORT = 8769;
    private const CLIENT_ID = 'hv-functional-test-client';

    private static ?AuxiliaryServer $idp = null;
    private static ?AuxiliaryServer $app = null;

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
    }

    public static function tearDownAfterClass(): void {
        self::$idp?->stop();
        self::$app?->stop();
        self::$idp = null;
        self::$app = null;
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
        $callback = $client->get('/auth/entra/callback?code=fake-auth-code&state=' . urlencode($state));
        $this->assertSame(
            '/admin?sso=entra',
            $callback->location(),
            "SSO-Callback sollte das lokale Konto anmelden. Body: {$callback->body}"
        );

        // 4. Die Session ist wirklich etabliert: Admin-Seite erreichbar.
        $admin = $client->get('/admin');
        $this->assertSame(200, $admin->statusCode);
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
}
