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
}
