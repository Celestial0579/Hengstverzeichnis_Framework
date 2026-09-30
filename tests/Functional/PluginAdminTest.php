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

        // admin_plugins.php rendert das (einzige) csrf_token-Feld der Seite nur
        // pro gefundenem Plugin - ohne jedes Plugin unter plugins/ (wie in einer
        // frischen CI-Umgebung, siehe .gitignore) gäbe es dort gar kein Formular
        // zum Auslesen. Token daher über currentCsrfToken() von einer Seite mit
        // unbedingt gerendertem Formular holen (siehe dortiger Kommentar).
        $response = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
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

    // ---- Manifestfelder, die kein Text sind (Audit N84) ----------------

    private const FIXTURE_OBJEKT = 'manifest-objekt-fixture';
    private const FIXTURE_NAME = 'manifest-name-fixture';

    protected function tearDown(): void {
        foreach ([self::FIXTURE_OBJEKT, self::FIXTURE_NAME] as $slug) {
            $dir = __DIR__ . '/../../plugins/' . $slug;
            foreach (glob($dir . '/*') ?: [] as $datei) {
                @unlink($datei);
            }
            @rmdir($dir);
        }
        parent::tearDown();
    }

    /** @param array<string, mixed> $manifest */
    private function legeManifestAn(string $slug, array $manifest): void {
        $dir = __DIR__ . '/../../plugins/' . $slug;
        @mkdir($dir, 0777, true);
        file_put_contents($dir . '/plugin.json', json_encode($manifest + [
            'slug' => $slug,
            'version' => '1.0.0',
            'core_compatibility' => '>=0.0.1',
            'core_supported_max' => '99.99',
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * Vorher warf htmlspecialchars() auf ein Array einen TypeError, und die
     * ganze Plugin-Verwaltung lieferte HTTP 500. Jetzt erscheinen beide
     * Addons als "Ungültiges Manifest", und die Seiten bleiben bedienbar.
     */
    public function testPluginsPageSurvivesNonStringManifestFields(): void {
        $this->legeManifestAn(self::FIXTURE_OBJEKT, [
            'name' => 'Objekt-Beschreibung',
            'description' => ['de' => 'Hallo', 'en' => 'Hello'],
            'hooks' => ['horse.saved', ['verschachtelt'], 3],
        ]);
        $this->legeManifestAn(self::FIXTURE_NAME, [
            'name' => ['de' => 'Name als Objekt'],
        ]);

        $admin = $this->authenticatedClient();
        $response = $admin->get('/admin/plugins');

        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString(self::FIXTURE_OBJEKT, $response->body);
        $this->assertStringContainsString(self::FIXTURE_NAME, $response->body);
        $this->assertStringContainsString('Ungültiges Manifest', $response->body);

        foreach ([self::FIXTURE_OBJEKT, self::FIXTURE_NAME] as $slug) {
            $seite = $admin->get('/admin/plugins/uninstall?slug=' . $slug);
            $this->assertSame(200, $seite->statusCode, "Deinstallationsseite für {$slug}");
        }
    }
}
