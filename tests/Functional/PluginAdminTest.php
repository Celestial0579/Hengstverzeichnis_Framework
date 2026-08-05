<?php
// tests/Functional/PluginAdminTest.php

namespace Tests\Functional;

/**
 * HTTP-Funktionstests für die Plugin-Verwaltung (#56,
 * src/Controllers/PluginController.php): Admin-Pflicht, CSRF-Schutz und
 * serverseitige Validierung beim Umschalten eines Plugins (unbekannter Slug
 * wird abgelehnt, bevor App\Plugin\PluginManager::setEnabled() überhaupt
 * aufgerufen wird). Ein echtes Plugin unter plugins/ steht in der Testumgebung
 * bewusst nicht zur Verfügung (siehe .gitignore) - Aktivieren/Deaktivieren
 * eines tatsächlich vorhandenen, kompatiblen Plugins ist daher nicht Teil
 * dieses Tests.
 */
class PluginAdminTest extends FunctionalTestCase {

    public function testPluginsPageRequiresAdmin(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $editor = $this->createAndLoginEditor($admin, "plugintester{$unique}", "plugin-test-{$unique}@example.com");

        $response = $editor->get('/admin/plugins');
        $this->assertSame(403, $response->statusCode);
    }

    public function testPluginsPageIsReachableForAdmin(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->get('/admin/plugins');
        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('Plugins verwalten', $response->body);
    }

    public function testTogglingUnknownPluginIsRejected(): void {
        $admin = $this->authenticatedClient();
        $pluginsPage = $admin->get('/admin/plugins');

        $response = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $pluginsPage->formField('csrf_token') ?? '',
            'slug' => 'dieses-plugin-existiert-nicht',
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?error=unknown_plugin', $response->location());
    }

    public function testTogglePluginRequiresCsrfToken(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->post('/admin/plugins/toggle', [
            'slug' => 'irrelevant',
            'enable' => '1',
        ]);
        $this->assertSame(403, $response->statusCode);
    }
}
