<?php
// tests/Functional/UpdateAdminTest.php

namespace Tests\Functional;

/**
 * HTTP-Funktionstests für die Update-Verwaltung (#85, siehe UpdateController/
 * UpdateService): Admin-Pflicht, CSRF-Schutz und die zentrale
 * Sicherheits-Leitplanke, dass ein Update ohne konfiguriertes Backup
 * abgelehnt wird, BEVOR irgendein Netzwerkzugriff oder Dateizugriff passiert.
 * Der vollständige Update-Durchlauf (Download + Anwenden) ist bewusst nicht
 * Teil der Functional-Suite (er würde die laufende Testinstallation
 * überschreiben) - die Anwendungslogik ist in UpdateServiceTest (Unit)
 * netzwerkfrei abgedeckt.
 */
class UpdateAdminTest extends FunctionalTestCase {

    public function testUpdatesPageRequiresAdmin(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $editor = $this->createAndLoginEditor($admin, "updater{$unique}", "update-test-{$unique}@example.com");

        $this->assertSame(403, $editor->get('/admin/updates')->statusCode);
    }

    public function testUpdatesPageShowsVersionAndBackupWarning(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->get('/admin/updates');
        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('Installierte Version', $response->body);
        // In der Testumgebung sind keine Backups konfiguriert.
        $this->assertStringContainsString('Automatische Backups sind nicht konfiguriert', $response->body);
    }

    public function testRunRequiresCsrfToken(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->post('/admin/updates/run', []);
        $this->assertSame(403, $response->statusCode);
    }

    public function testChannelSelectionIsShownAndPersisted(): void {
        $admin = $this->authenticatedClient();

        // Default: Kanal Stabil, Auswahlfeld mit beiden Optionen vorhanden.
        $page = $admin->get('/admin/updates');
        $this->assertStringContainsString('Update-Kanal', $page->body);
        $this->assertStringContainsString('Beta (Vorabversionen einbeziehen)', $page->body);
        $this->assertStringContainsString('Kanal: Stabil', $page->body);
        $this->assertStringContainsString('Downgrade findet niemals statt', $page->body);

        // Beta-Opt-in speichern (Redirect führt zur Release-Prüfung; hier wird
        // bewusst nicht gefolgt, um keinen Netzwerkzugriff im Test auszulösen).
        $save = $admin->post('/admin/updates/channel', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'update_channel' => 'beta',
        ]);
        $this->assertSame('/admin/updates?check=1&channel_saved=1', $save->location());

        $afterSave = $admin->get('/admin/updates');
        $this->assertStringContainsString('Kanal: Beta', $afterSave->body);

        // Unbekannte Werte fallen serverseitig auf Stabil zurück.
        $reset = $admin->post('/admin/updates/channel', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'update_channel' => 'nightly-kaputt',
        ]);
        $this->assertSame('/admin/updates?check=1&channel_saved=1', $reset->location());
        $this->assertStringContainsString('Kanal: Stabil', $admin->get('/admin/updates')->body);
    }

    public function testChannelSaveRequiresCsrfToken(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->post('/admin/updates/channel', ['update_channel' => 'beta']);
        $this->assertSame(403, $response->statusCode);
    }

    public function testRunIsRejectedWithoutConfiguredBackup(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->post('/admin/updates/run', [
            'csrf_token' => $this->currentCsrfToken($admin),
        ]);

        $location = (string)$response->location();
        $this->assertStringStartsWith('/admin/updates?error=', $location);
        $this->assertStringContainsString('Backups', urldecode($location));
    }
}
