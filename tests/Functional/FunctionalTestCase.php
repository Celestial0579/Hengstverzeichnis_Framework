<?php
// tests/Functional/FunctionalTestCase.php

namespace Tests\Functional;

use App\Security\Totp;
use PHPUnit\Framework\TestCase;
use Tests\Support\HttpClient;
use Tests\Support\PhpBuiltInServer;

/**
 * Basisklasse für HTTP-getriebene Funktionstests (siehe docs/development.md,
 * Abschnitt "Tests"). Startet einen php -S Subprozess und provisioniert die
 * App darüber genau einmal pro PHPUnit-Prozess (Setup-Wizard-Autoprovisionierung
 * per Umgebungsvariable, siehe SetupController::envAdminCredentials()) - alle
 * Testklassen, die von hier erben, teilen sich denselben Admin-Account.
 */
abstract class FunctionalTestCase extends TestCase {

    protected static ?string $adminEmail = null;
    protected static ?string $adminPassword = null;
    protected static ?string $totpSecret = null;

    public static function setUpBeforeClass(): void {
        PhpBuiltInServer::ensureStarted();
    }

    protected function newClient(): HttpClient {
        return new HttpClient(PhpBuiltInServer::baseUrl());
    }

    /**
     * Liefert einen frischen, aber bereits vollständig eingeloggten Client
     * (Passwort-Login + 2FA-Verifikation über den echten HTTP-Flow).
     */
    protected function authenticatedClient(): HttpClient {
        self::ensureProvisioned();
        $client = $this->newClient();

        $loginPage = $client->get('/login');
        $loginResponse = $client->post('/login', [
            'csrf_token' => $loginPage->formField('csrf_token') ?? '',
            'email' => self::$adminEmail,
            'password' => self::$adminPassword,
        ]);
        self::assertSame(
            '/login/2fa',
            $loginResponse->location(),
            "Login sollte zur 2FA-Verifikation weiterleiten, Body: {$loginResponse->body}"
        );

        $verifyPage = $client->get('/login/2fa');
        $verifyResponse = $client->post('/login/2fa', [
            'csrf_token' => $verifyPage->formField('csrf_token') ?? '',
            'totp_code' => Totp::getCode(self::$totpSecret),
        ]);
        self::assertSame(
            302,
            $verifyResponse->statusCode,
            "2FA-Verifikation beim Login sollte erfolgreich sein, Body: {$verifyResponse->body}"
        );

        return $client;
    }

    /**
     * Führt die vollautomatische Ersteinrichtung durch (GET /setup mit den
     * ADMIN_-, SITE_NAME- und DB_-Umgebungsvariablen, siehe
     * tests/Support/PhpBuiltInServer.php und
     * .github/workflows/tests.yml) genau einmal pro Prozess durch und schließt
     * die verpflichtende 2FA-Einrichtung ab.
     */
    private static function ensureProvisioned(): void {
        if (self::$adminEmail !== null) {
            return;
        }

        self::$adminEmail = getenv('ADMIN_EMAIL') ?: 'functional-test-admin@example.com';
        self::$adminPassword = getenv('ADMIN_PASSWORD') ?: 'FunctionalTest123!';

        $client = new HttpClient(PhpBuiltInServer::baseUrl());

        $setupResponse = $client->get('/setup');
        self::assertSame(
            '/2fa/setup',
            $setupResponse->location(),
            "Automatische Ersteinrichtung sollte zu /2fa/setup weiterleiten - Umgebungsvariablen " .
            "(ADMIN_EMAIL/ADMIN_USERNAME/ADMIN_PASSWORD/SITE_NAME/APP_KEY/DB_*) korrekt gesetzt? " .
            "Body: {$setupResponse->body}"
        );

        $setupPage = $client->get('/2fa/setup');
        $secret = $setupPage->formField('totp_secret');
        self::assertNotNull($secret, 'Konnte totp_secret nicht aus /2fa/setup extrahieren');
        self::$totpSecret = $secret;

        $enableResponse = $client->post('/2fa/enable', [
            'csrf_token' => $setupPage->formField('csrf_token') ?? '',
            'totp_secret' => $secret,
            'backup_codes' => $setupPage->formField('backup_codes') ?? '[]',
            'confirm_backup' => '1',
            'totp_code' => Totp::getCode($secret),
        ]);
        self::assertSame(
            302,
            $enableResponse->statusCode,
            "2FA-Aktivierung während der Ersteinrichtung fehlgeschlagen, Body: {$enableResponse->body}"
        );
    }
}
