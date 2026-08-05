<?php
// tests/Functional/DigestSettingsTest.php

namespace Tests\Functional;

/**
 * HTTP-Funktionstests für die E-Mail-Digest-Verwaltung (#52,
 * src/Controllers/AdminController.php: digestSettings()/
 * updateDigestSettings()/testDigest()): Admin-Pflicht, CSRF-Schutz und dass
 * gespeicherte Einstellungen auf der Seite wieder auftauchen. Der manuelle
 * "Jetzt prüfen"-Button meldet in dieser Testumgebung ohne offene
 * Match-Vorschläge/Papierkorb-Einträge und ohne konfigurierten SMTP stets
 * "nichts zu berichten" (siehe tests/Integration/DigestServiceTest.php für
 * die eigentliche Zähl-/Versandlogik).
 */
class DigestSettingsTest extends FunctionalTestCase {

    public function testDigestSettingsPageRequiresAdmin(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $editor = $this->createAndLoginEditor($admin, "digesttester{$unique}", "digest-test-{$unique}@example.com");

        $response = $editor->get('/admin/digest');
        $this->assertSame(403, $response->statusCode);
    }

    public function testDigestSettingsPageIsReachableForAdmin(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->get('/admin/digest');
        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('E-Mail-Digest', $response->body);
    }

    public function testUpdateDigestSettingsRequiresCsrfToken(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->post('/admin/digest', ['digest_enabled' => '1']);
        $this->assertSame(403, $response->statusCode);
    }

    public function testSavedSettingsAreReflectedOnTheSettingsPage(): void {
        $admin = $this->authenticatedClient();

        $formPage = $admin->get('/admin/digest');
        $response = $admin->post('/admin/digest', [
            'csrf_token' => $formPage->formField('csrf_token') ?? '',
            'digest_enabled' => '1',
            'digest_interval_hours' => '8',
        ]);
        $this->assertSame('/admin/digest?success=1', $response->location());

        $settingsPage = $admin->get('/admin/digest');
        $this->assertSame('8', $settingsPage->formField('digest_interval_hours'));
    }

    public function testTestDigestRequiresCsrfToken(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->post('/admin/digest/test', []);
        $this->assertSame(403, $response->statusCode);
    }

    public function testTestDigestWithNothingToReportRedirectsAsSkipped(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->post('/admin/digest/test', [
            'csrf_token' => $this->currentCsrfToken($admin),
        ]);

        $this->assertSame('/admin/digest?success=digest_skipped', $response->location());
    }
}
