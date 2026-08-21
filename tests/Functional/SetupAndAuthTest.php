<?php
// tests/Functional/SetupAndAuthTest.php

namespace Tests\Functional;

/**
 * HTTP-Funktionstests für Ersteinrichtung, Login+2FA, CSRF-Schutz und die
 * SSRF-Härtung der Stamm-URL (siehe Issue #54 und PR #62). Deckt genau die
 * Flows ab, die sich wegen durchgängiger header()+exit;-Aufrufe in den
 * Controllern nicht in-process testen lassen (siehe FunctionalTestCase).
 */
class SetupAndAuthTest extends FunctionalTestCase {

    public function testPublicCatalogIsReachableWithoutLogin(): void {
        $response = $this->newClient()->get('/katalog');

        $this->assertSame(200, $response->statusCode);
        // Beschriftung "Verzeichnis" statt "Hengstkatalog" (#170): der Katalog
        // führt alle Pferde, nicht nur Hengste.
        $this->assertStringContainsString('Verzeichnis', $response->body);
    }

    public function testLoginAndMandatory2faGrantsAdminAccess(): void {
        $client = $this->authenticatedClient();

        $dashboard = $client->get('/admin');

        $this->assertSame(200, $dashboard->statusCode);
        $this->assertStringContainsString('Admin Dashboard', $dashboard->body);
    }

    public function testLoginWithWrongPasswordIsRejected(): void {
        $client = $this->newClient();
        $loginPage = $client->get('/login');

        $response = $client->post('/login', [
            'csrf_token' => $loginPage->formField('csrf_token') ?? '',
            'kennung' => self::$adminEmail ?? 'irrelevant@example.com',
            'password' => 'definitiv-das-falsche-passwort',
        ]);

        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('Ungültige Zugangsdaten', $response->body);
        $this->assertNull($response->location());
    }

    public function testPostWithoutCsrfTokenIsRejected(): void {
        $client = $this->authenticatedClient();

        $response = $client->post('/admin/system-settings', [
            'base_url' => 'https://example.com',
        ]);

        $this->assertSame(403, $response->statusCode);
    }

    /**
     * Automatisierte Version des manuellen Browser-Tests aus PR #62: private/
     * loopback Hosts werden als Stamm-URL abgelehnt, öffentliche Domains akzeptiert.
     */
    public function testBaseUrlRejectsPrivateAndLoopbackHosts(): void {
        $client = $this->authenticatedClient();
        $settingsPage = $client->get('/admin/system-settings');
        $csrfToken = $settingsPage->formField('csrf_token') ?? '';

        foreach (['http://127.0.0.1', 'http://localhost', 'http://10.0.0.5'] as $blockedUrl) {
            $response = $client->post('/admin/system-settings', [
                'csrf_token' => $csrfToken,
                'base_url' => $blockedUrl,
            ]);

            // updateSystemSettings() rendert bei Erfolg/Fehler nicht inline, sondern
            // leitet immer per Redirect auf /admin/system-settings weiter (siehe
            // AdminController::updateSystemSettings()) - die Fehlermeldung selbst
            // steht erst auf der Zielseite des Redirects, hier reicht die Prüfung
            // des Location-Headers.
            $this->assertSame(302, $response->statusCode, "Erwartete Weiterleitung für {$blockedUrl}");
            $this->assertStringContainsString(
                'error=invalid_base_url',
                (string)$response->location(),
                "Erwartete Ablehnung für {$blockedUrl}"
            );
        }
    }

    /**
     * Die Stamm-URL muss ihr Protokoll mitbringen. Frueher ergaenzte der
     * Controller ein fehlendes "https://" selbst und pruefte danach eine
     * Zeichenkette, die er zur Haelfte selbst gebaut hatte; zulaessige
     * Protokolle waren nur als Nebenwirkung dieser Praefix-Logik begrenzt.
     * Beides ist entfallen - der Test haelt fest, dass die Grenze jetzt
     * ausdruecklich gezogen wird und nicht wieder still aufweicht.
     */
    public function testBaseUrlRequiresExplicitHttpScheme(): void {
        $client = $this->authenticatedClient();
        $settingsPage = $client->get('/admin/system-settings');
        $csrfToken = $settingsPage->formField('csrf_token') ?? '';

        $abzulehnen = [
            'hengstverzeichnis.example.com',          // ohne Protokoll
            'ftp://hengstverzeichnis.example.com',    // FILTER_VALIDATE_URL laesst das durch
            'javascript://hengstverzeichnis.example.com',
        ];

        foreach ($abzulehnen as $eingabe) {
            $response = $client->post('/admin/system-settings', [
                'csrf_token' => $csrfToken,
                'base_url' => $eingabe,
            ]);

            $this->assertSame(302, $response->statusCode, "Erwartete Weiterleitung für {$eingabe}");
            $this->assertStringContainsString(
                'error=invalid_base_url',
                (string)$response->location(),
                "Erwartete Ablehnung für {$eingabe}"
            );
        }
    }

    /**
     * Gegenprobe zum vorigen Test: http bleibt zulaessig, wird aber weiterhin
     * als unverschluesselt gemeldet. Die Warnung haengt jetzt am geprueften
     * Schema statt an einem str_starts_with auf der Eingabe.
     */
    public function testBaseUrlAcceptsHttpButWarns(): void {
        $client = $this->authenticatedClient();
        $settingsPage = $client->get('/admin/system-settings');

        $response = $client->post('/admin/system-settings', [
            'csrf_token' => $settingsPage->formField('csrf_token') ?? '',
            'base_url' => 'http://hengstverzeichnis.example.com',
        ]);

        $this->assertSame(302, $response->statusCode);
        $location = (string)$response->location();
        $this->assertStringContainsString('success=1', $location);
        $this->assertStringContainsString('warning=http_unencrypted', $location);
    }

    public function testBaseUrlAcceptsPublicDomain(): void {
        $client = $this->authenticatedClient();
        $settingsPage = $client->get('/admin/system-settings');

        $response = $client->post('/admin/system-settings', [
            'csrf_token' => $settingsPage->formField('csrf_token') ?? '',
            'base_url' => 'https://hengstverzeichnis.example.com',
        ]);

        $this->assertSame(302, $response->statusCode);
        $location = (string)$response->location();
        $this->assertStringContainsString('success=1', $location);
        $this->assertStringNotContainsString('error=', $location);
    }
}
