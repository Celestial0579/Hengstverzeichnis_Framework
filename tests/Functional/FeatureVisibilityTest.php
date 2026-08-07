<?php
// tests/Functional/FeatureVisibilityTest.php

namespace Tests\Functional;

/**
 * HTTP-Funktionstests für die admin-konfigurierbare Sichtbarkeit von
 * Zusatzfunktionen (Issue #57, siehe App\Permission\FeatureRegistry/
 * FeatureGate): Ein Plugin registriert eine Zusatzfunktion, der Admin schaltet
 * sie zwischen "Öffentlich" und "Nur für Gruppen mit Leseberechtigung" um,
 * und die Leseberechtigung wird pro Gruppe über die bestehende
 * Berechtigungsmatrix vergeben.
 *
 * Nutzt das Referenz-Plugin aus docs/examples/demo-plugin (Feature
 * 'demo-premium', öffentliche Route /plugin/demo-plugin/premium), das für die
 * Testdauer in das gitignorete plugins/-Verzeichnis kopiert und über den
 * echten HTTP-Endpunkt aktiviert wird.
 */
class FeatureVisibilityTest extends FunctionalTestCase {

    private const PLUGIN_SRC = __DIR__ . '/../../docs/examples/demo-plugin';
    private const PLUGIN_DEST = __DIR__ . '/../../plugins/demo-plugin';
    private const PREMIUM_URL = '/plugin/demo-plugin/premium';

    protected function tearDown(): void {
        self::removePluginDir();
        parent::tearDown();
    }

    private static function installPluginFixture(): void {
        self::removePluginDir();
        mkdir(self::PLUGIN_DEST, 0777, true);
        mkdir(self::PLUGIN_DEST . '/lang', 0777, true);
        foreach (['Plugin.php', 'plugin.json'] as $file) {
            copy(self::PLUGIN_SRC . '/' . $file, self::PLUGIN_DEST . '/' . $file);
        }
        foreach (glob(self::PLUGIN_SRC . '/lang/*.php') ?: [] as $langFile) {
            copy($langFile, self::PLUGIN_DEST . '/lang/' . basename($langFile));
        }
    }

    private static function removePluginDir(): void {
        if (!is_dir(self::PLUGIN_DEST)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::PLUGIN_DEST, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir(self::PLUGIN_DEST);
    }

    public function testVisibilityTogglesAndGroupReadPermission(): void {
        $admin = $this->authenticatedClient();
        self::installPluginFixture();

        // Plugin über den echten HTTP-Endpunkt aktivieren.
        $toggleResponse = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => 'demo-plugin',
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggleResponse->location());

        try {
            // 1. Default 'members': anonyme Besucher sehen die Funktion nicht,
            //    der Admin (Admin-Bypass) schon.
            $anonymous = $this->newClient();
            $this->assertSame(403, $anonymous->get(self::PREMIUM_URL)->statusCode);
            $this->assertSame(200, $admin->get(self::PREMIUM_URL)->statusCode);

            // Die Funktion erscheint in den Systemeinstellungen.
            $settingsPage = $admin->get('/admin/system-settings');
            $this->assertStringContainsString('Demo-Premium-Bereich', $settingsPage->body);

            // 2. Admin schaltet auf 'Öffentlich' -> anonyme Besucher sehen sie.
            $this->saveVisibility($admin, 'public');
            $this->assertSame(200, $anonymous->get(self::PREMIUM_URL)->statusCode);

            // 3. Zurück auf 'members': Editor ohne Leseberechtigung sieht sie
            //    nicht, nach Vergabe der Leseberechtigung an seine eigene
            //    Gruppe schon.
            $this->saveVisibility($admin, 'members');
            $this->assertSame(403, $anonymous->get(self::PREMIUM_URL)->statusCode);

            $unique = uniqid();
            $groupId = $this->createCustomGroup($admin, "Premium-Tester {$unique}");
            $member = $this->createAndLoginEditor($admin, "premium{$unique}", "premium-{$unique}@example.com", [$groupId]);

            $this->assertSame(403, $member->get(self::PREMIUM_URL)->statusCode, 'Ohne Leseberechtigung kein Zugriff');

            $this->setGroupPermissions($admin, $groupId, ['feature_demo-premium' => ['read']]);

            $this->assertSame(200, $member->get(self::PREMIUM_URL)->statusCode, 'Mit Gruppen-Leseberechtigung Zugriff');
            // Anonyme Besucher bleiben trotzdem ausgeschlossen.
            $this->assertSame(403, $anonymous->get(self::PREMIUM_URL)->statusCode);
        } finally {
            $admin->post('/admin/plugins/toggle', [
                'csrf_token' => $this->currentCsrfToken($admin),
                'slug' => 'demo-plugin',
                'enable' => '0',
            ]);
        }
    }

    /**
     * Supply-Chain-Schranke (#129, siehe PluginManager::loadEnabledPlugins()):
     * Werden die Dateien eines aktivierten Plugins verändert, OHNE die Version
     * in plugin.json zu erhöhen, darf das Plugin fail-closed nicht mehr geladen
     * werden (Route -> 404), bis ein Admin es erneut freigibt. Ein erneutes
     * Aktivieren (Re-Toggle) speichert die neue Baseline (content_hash) und
     * macht das Plugin wieder nutzbar.
     */
    public function testModifiedPluginRequiresReapprovalAndReToggleRestoresIt(): void {
        $admin = $this->authenticatedClient();
        self::installPluginFixture();

        $toggleResponse = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => 'demo-plugin',
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggleResponse->location());

        try {
            // Baseline: Plugin ist aktiv, Route erreichbar (Admin-Bypass der
            // Feature-Sichtbarkeit).
            $this->assertSame(200, $admin->get(self::PREMIUM_URL)->statusCode);

            // Plugin-Code verändern, Version in plugin.json UNVERÄNDERT lassen.
            file_put_contents(self::PLUGIN_DEST . '/Plugin.php', "\n// nachträglich veränderter Code\n", FILE_APPEND);

            // Fail-closed: das Plugin wird nicht mehr geladen -> Route liefert 404.
            $this->assertSame(
                404,
                $admin->get(self::PREMIUM_URL)->statusCode,
                'Verändertes Plugin ohne Versionserhöhung darf nicht mehr geladen werden'
            );

            // Die Plugin-Verwaltung zeigt den Wiederfreigabe-Hinweis, die
            // Aktivierung selbst ist NICHT verloren gegangen.
            $pluginsPage = $admin->get('/admin/plugins');
            $this->assertStringContainsString('erneute Freigabe nötig', $pluginsPage->body);

            // Erneute Freigabe per Re-Toggle: speichert die neue Baseline.
            $reapproveResponse = $admin->post('/admin/plugins/toggle', [
                'csrf_token' => $this->currentCsrfToken($admin),
                'slug' => 'demo-plugin',
                'enable' => '1',
            ]);
            $this->assertSame('/admin/plugins?success=1', $reapproveResponse->location());

            $this->assertSame(
                200,
                $admin->get(self::PREMIUM_URL)->statusCode,
                'Nach erneuter Freigabe muss das Plugin wieder geladen werden'
            );
        } finally {
            $admin->post('/admin/plugins/toggle', [
                'csrf_token' => $this->currentCsrfToken($admin),
                'slug' => 'demo-plugin',
                'enable' => '0',
            ]);
        }
    }

    private function saveVisibility(\Tests\Support\HttpClient $admin, string $visibility): void {
        $page = $admin->get('/admin/system-settings');
        $response = $admin->post('/admin/system-settings', [
            'csrf_token' => $page->formField('csrf_token') ?? '',
            'base_url' => '',
            'language' => 'de',
            'feature_visibility' => ['demo-premium' => $visibility],
        ]);
        $this->assertStringContainsString('/admin/system-settings?success=1', (string)$response->location());
    }

    private function createCustomGroup(\Tests\Support\HttpClient $admin, string $name): int {
        $groupsPage = $admin->get('/admin/groups');
        $response = $admin->post('/admin/groups/create', [
            'csrf_token' => $groupsPage->formField('csrf_token') ?? '',
            'name' => $name,
        ]);
        $location = (string)$response->location();
        preg_match('/group=(\d+)/', $location, $matches);
        $this->assertNotEmpty($matches, "Konnte neue Gruppen-ID nicht aus Redirect '{$location}' ermitteln, Body: {$response->body}");
        return (int)$matches[1];
    }
}
