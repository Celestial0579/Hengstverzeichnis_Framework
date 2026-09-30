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
     * Audit N14: Eine gesperrte Sitzung verliert die Mitgliederfunktion
     * sofort - die Premium-Seite ist eine öffentliche Route ohne
     * checkAuth(), FeatureGate las vorher nur die rohe user_id.
     */
    public function testGesperrteSitzungVerliertMitgliederfunktion(): void {
        $admin = $this->authenticatedClient();
        self::installPluginFixture();
        $toggleResponse = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => 'demo-plugin',
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggleResponse->location());

        try {
            $this->saveVisibility($admin, 'members');
            $unique = uniqid();
            $groupId = $this->createCustomGroup($admin, "Premium gesperrt {$unique}");
            $this->setGroupPermissions($admin, $groupId, ['feature_demo-premium' => ['read']]);
            $db = \App\Database::getInstance();

            foreach (['session_version = session_version + 1', 'deactivated_at = NOW()'] as $i => $sperre) {
                $email = "premium-gesperrt{$i}-{$unique}@example.com";
                $member = $this->createAndLoginEditor($admin, "premiumgesperrt{$i}{$unique}", $email, [$groupId]);
                $this->assertSame(200, $member->get(self::PREMIUM_URL)->statusCode, "Vorbedingung ({$sperre}): Mitglied sieht die Funktion");

                $db->prepare("UPDATE users SET {$sperre} WHERE email = ?")->execute([$email]);

                $this->assertSame(403, $member->get(self::PREMIUM_URL)->statusCode, "Nach {$sperre} keine Mitgliederfunktion mehr");
                $this->assertStringStartsWith('/login', (string)$member->get('/admin')->location());
            }
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

    /**
     * Audit N74: Ein Funktionsschlüssel mit 45 Zeichen sprengte
     * `settings.setting_key` (feature_visibility__ + 45 = 65 Zeichen) und
     * `group_permissions.module` (feature_ + 45 = 53). Im Strict-Mode
     * scheiterten das Speichern der Systemeinstellungen und der
     * Gruppenrechte. Jetzt werden beide Schlüssel gehasht.
     */
    public function testLangerFunktionsschluesselLaesstSichSpeichern(): void {
        $admin = $this->authenticatedClient();
        $key = 'ein-sehr-langer-funktionsschluessel-aus-addon';
        $this->assertSame(45, strlen($key));
        self::installLongKeyPlugin($key);

        $toggle = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::LONG_SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggle->location(), "Aktivieren fehlgeschlagen: {$toggle->body}");

        $settingKey = \App\Permission\FeatureRegistry::settingKey($key);
        $module = \App\Permission\FeatureRegistry::permissionModule($key);
        $this->assertLessThanOrEqual(50, strlen($settingKey));
        $this->assertLessThanOrEqual(50, strlen($module));
        $unique = uniqid();
        $groupId = null;

        try {
            $page = $admin->get('/admin/system-settings');
            $response = $admin->post('/admin/system-settings', [
                'csrf_token' => $page->formField('csrf_token') ?? '',
                'base_url' => '',
                'language' => 'de',
                'feature_visibility' => [$key => 'public'],
            ]);
            $this->assertStringContainsString('/admin/system-settings?success=1', (string)$response->location(), $response->body);

            // Die Auswahl wird wieder angezeigt.
            $settingsPage = $admin->get('/admin/system-settings');
            $this->assertMatchesRegularExpression(
                '#name="feature_visibility\[' . preg_quote($key, '#') . '\]".*?<option value="public" selected#s',
                $settingsPage->body
            );
            // ... und wirkt: anonyme Besucher sehen die Funktion.
            $this->assertSame(200, $this->newClient()->get('/plugin/' . self::LONG_SLUG . '/seite')->statusCode);

            // Gruppenrecht für das (gehashte) Modul speichern.
            $groupId = $this->createCustomGroup($admin, "Langer-Schluessel {$unique}");
            $this->setGroupPermissions($admin, $groupId, [$module => ['read']]);
            $stmt = \App\Database::getInstance()->prepare("SELECT COUNT(*) FROM group_permissions WHERE group_id = ? AND module = ? AND action = 'read'");
            $stmt->execute([$groupId, $module]);
            $this->assertSame(1, (int)$stmt->fetchColumn());
        } finally {
            $admin->post('/admin/plugins/toggle', [
                'csrf_token' => $this->currentCsrfToken($admin),
                'slug' => self::LONG_SLUG,
                'enable' => '0',
            ]);
            try {
                $db = \App\Database::getInstance();
                $db->prepare("DELETE FROM settings WHERE setting_key = ?")->execute([$settingKey]);
                $db->prepare("DELETE FROM plugins WHERE slug = ?")->execute([self::LONG_SLUG]);
                if ($groupId !== null) {
                    $db->prepare("DELETE FROM group_permissions WHERE group_id = ?")->execute([$groupId]);
                }
            } catch (\Throwable $e) {
                // DB weg = nichts zu bereinigen
            }
            foreach (glob(self::LONG_DEST . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir(self::LONG_DEST);
        }
    }

    private const LONG_SLUG = 'langer-schluessel-test';
    private const LONG_DEST = __DIR__ . '/../../plugins/langer-schluessel-test';

    private static function installLongKeyPlugin(string $key): void {
        @mkdir(self::LONG_DEST, 0755, true);
        file_put_contents(self::LONG_DEST . '/plugin.json', json_encode([
            'slug' => self::LONG_SLUG,
            'name' => 'Langer-Schlüssel-Test',
            'version' => '1.0.0',
            'core_compatibility' => '>=0.1.0-beta.1',
            'core_supported_max' => '9.9',
            'description' => 'Registriert eine Zusatzfunktion mit 45 Zeichen langem Schlüssel.',
            'author' => 'Tests',
            'hooks' => [],
            'entry' => 'Plugin.php',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $code = <<<'PHP'
<?php
namespace Plugin\LangerSchluesselTest;

class Plugin {

    private const KEY = '__KEY__';

    public function register($hooks): void {
    }

    public function features(): array {
        return [['key' => self::KEY, 'label' => 'Funktion mit langem Schlüssel', 'default_visibility' => 'members']];
    }

    public function routes(): array {
        return [['method' => 'GET', 'path' => '/seite', 'callback' => [self::class, 'seite']]];
    }

    public static function seite(): void {
        if (!\App\Permission\FeatureGate::isVisible(self::KEY)) {
            http_response_code(403);
            echo 'gesperrt';
            return;
        }
        echo 'LANGER-SCHLUESSEL-OK';
    }
}
PHP;
        file_put_contents(self::LONG_DEST . '/Plugin.php', str_replace('__KEY__', $key, $code));
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
