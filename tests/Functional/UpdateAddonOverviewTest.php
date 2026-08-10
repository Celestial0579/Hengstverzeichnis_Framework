<?php
// tests/Functional/UpdateAddonOverviewTest.php

namespace Tests\Functional;

use App\Database;
use App\Security\Totp;
use Tests\Support\AuxiliaryServer;
use Tests\Support\HttpClient;

/**
 * HTTP-Funktionstests für "Addons mitdenken" auf der Update-Seite (#197,
 * Stufe 1): Addon-Übersicht (installiert vs. Katalog), Dashboard-Badge und
 * die Warnung vor einem Kern-Update, dessen ZIELversion ein aktives Addon
 * nicht unterstützt.
 *
 * Für den Zielversions-Fall startet die Klasse eine zweite App-Instanz,
 * deren UPDATE_RELEASES_URL auf ein statisches Release-Fixture zeigt, das
 * sie selbst ausliefert (public/, von php -S direkt serviert) - die
 * Release-"Prüfung" läuft damit komplett ohne GitHub/Netz.
 */
class UpdateAddonOverviewTest extends FunctionalTestCase {

    private const APP_PORT = 8771;
    private const FIXTURE = 'releases-fixture-addon-overview.json';
    private const SLUG = 'update-overview-testaddon';

    private static ?AuxiliaryServer $app = null;

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        self::$app = new AuxiliaryServer(
            self::APP_PORT,
            __DIR__ . '/../../public',
            null,
            [
                'APP_URL' => 'http://127.0.0.1:' . self::APP_PORT,
                // Das Fixture liefert der GETEILTE Testserver aus, nicht die
                // eigene Instanz: php -S ist ein Single-Worker - eine Instanz,
                // die während eines Requests ihre eigene URL abruft, blockiert
                // sich selbst (beobachtet als 10s-Timeout).
                'UPDATE_RELEASES_URL' => \Tests\Support\PhpBuiltInServer::baseUrl() . '/' . self::FIXTURE,
            ]
        );
        self::$app->start();
    }

    public static function tearDownAfterClass(): void {
        self::$app?->stop();
        self::$app = null;
        parent::tearDownAfterClass();
    }

    protected function tearDown(): void {
        // Aufräumen ist Pflicht: Plugin-Verzeichnis, Aktivierung und
        // Katalog-Cache wirken sonst in andere Tests hinein.
        $this->removePluginDir();
        @unlink(__DIR__ . '/../../public/' . self::FIXTURE);
        try {
            $db = Database::getInstance();
            $db->prepare("DELETE FROM plugins WHERE slug = ?")->execute([self::SLUG]);
            $db->query("UPDATE addon_repos SET cached_catalog_json = NULL, cached_at = NULL WHERE is_official = 1");
        } catch (\Throwable $e) {
            // DB weg = nichts zu bereinigen
        }
        parent::tearDown();
    }

    public function testAddonSectionShowsEmptyStateWithoutAddons(): void {
        $admin = $this->authenticatedClient();

        $page = $admin->get('/admin/updates');
        $this->assertSame(200, $page->statusCode);
        $this->assertStringContainsString('🧩 Addons', $page->body);
        $this->assertStringContainsString('Keine Addons installiert', $page->body);
    }

    public function testAddonRowShowsCatalogUpdateAndDashboardBadgeCounts(): void {
        $admin = $this->authenticatedClient();
        $this->createPluginDir(['core_compatibility' => '>=0.1.0-beta.1']);
        $this->seedOfficialCatalog('1.1.0');

        $page = $admin->get('/admin/updates');
        $this->assertSame(200, $page->statusCode);
        $this->assertStringContainsString(self::SLUG, $page->body);
        $this->assertStringContainsString('<td style="padding: 0.4rem 0.5rem;">1.0.0</td>', $page->body);
        $this->assertStringContainsString('<strong>1.1.0</strong>', $page->body);
        $this->assertStringContainsString('>Update</span>', $page->body);
        $this->assertStringContainsString('Katalog-Stand:', $page->body);

        // Dashboard zählt das offene Addon-Update an der Update-Kachel
        // (Badge-Kommentar wird nur bei Zähler > 0 gerendert).
        $dashboard = $admin->get('/admin');
        $this->assertStringContainsString('Zähler offener ADDON-Updates', $dashboard->body);
        $this->assertMatchesRegularExpression('/Zähler offener ADDON-Updates.*?>\s*1\s*<\/span>/s', $dashboard->body);
    }

    public function testWarnsBeforeCoreUpdateWhoseTargetDisablesAnActiveAddon(): void {
        // Aktives Addon, das höchstens Kern 0.3 unterstützt - das
        // Release-Fixture bietet 9.9.9 an.
        $this->createPluginDir([
            'core_compatibility' => '>=0.1.0-beta.1',
            'core_supported_max' => '0.3',
        ]);
        $this->enablePlugin();
        $this->writeReleasesFixture('9.9.9');

        $admin = $this->fixtureInstanceAdmin();
        $page = $admin->get('/admin/updates?check=1');

        $this->assertSame(200, $page->statusCode);
        $this->assertStringContainsString('Neue Version verfügbar: <strong>9.9.9</strong>', $page->body);
        // Die Warnung steht VOR dem Update-Knopf und nennt Addon + Grund.
        $this->assertStringContainsString('werden folgende aktive Addons deaktiviert', $page->body);
        $this->assertStringContainsString(self::SLUG, $page->body);
        $this->assertStringContainsString('höchstens', $page->body);
        // Und die Tabelle prüft gegen die ZIELversion, nicht nur die laufende.
        $this->assertStringContainsString('kompatibel mit Ziel 9.9.9?', $page->body);
    }

    // ---- Helfer --------------------------------------------------------

    private function pluginDir(): string {
        return __DIR__ . '/../../plugins/' . self::SLUG;
    }

    /** @param array<string, string> $manifestExtra */
    private function createPluginDir(array $manifestExtra): void {
        $dir = $this->pluginDir();
        @mkdir($dir, 0755, true);
        $manifest = array_merge([
            'slug' => self::SLUG,
            'name' => 'Update-Übersicht Testaddon',
            'version' => '1.0.0',
        ], $manifestExtra);
        file_put_contents($dir . '/plugin.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($dir . '/Plugin.php', "<?php\nnamespace Plugin\\UpdateOverviewTestaddon;\nclass Plugin { public function register(\$hooks): void {} }\n");
    }

    private function removePluginDir(): void {
        $dir = $this->pluginDir();
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    private function enablePlugin(): void {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            "INSERT INTO plugins (slug, enabled, installed_version, content_hash)
             VALUES (?, 1, '1.0.0', 'test-hash')
             ON DUPLICATE KEY UPDATE enabled = 1"
        );
        $stmt->execute([self::SLUG]);
    }

    private function seedOfficialCatalog(string $availableVersion): void {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            "UPDATE addon_repos SET cached_catalog_json = ?, cached_at = NOW() WHERE is_official = 1"
        );
        $stmt->execute([json_encode([[
            'slug' => self::SLUG,
            'name' => 'Update-Übersicht Testaddon',
            'version' => $availableVersion,
            'core_compatibility' => '>=0.1.0-beta.1',
            'core_supported_max' => null,
            'description' => '',
            'author' => '',
            'hooks' => [],
        ]])]);
    }

    private function writeReleasesFixture(string $version): void {
        file_put_contents(__DIR__ . '/../../public/' . self::FIXTURE, json_encode([[
            'tag_name' => 'v' . $version,
            'draft' => false,
            'prerelease' => false,
            'html_url' => 'https://example.invalid/releases/v' . $version,
            'assets' => [[
                'name' => 'hengstverzeichnis-framework-' . $version . '.zip',
                'browser_download_url' => 'https://example.invalid/download/' . $version . '.zip',
            ]],
        ]]));
    }

    /**
     * Admin-Login gegen die Fixture-Instanz (gleiche DB/Konten wie der
     * geteilte Server) - Muster aus UpdateInPlaceDisabledTest.
     */
    private function fixtureInstanceAdmin(): HttpClient {
        $this->authenticatedClient();
        self::resetTotpReplayGuard(self::$adminEmail);

        $client = new HttpClient(self::$app->baseUrl());
        $loginPage = $client->get('/login');
        $client->post('/login', [
            'csrf_token' => $loginPage->formField('csrf_token') ?? '',
            'email' => self::$adminEmail,
            'password' => self::$adminPassword,
        ]);
        $verifyPage = $client->get('/login/2fa');
        $verify = $client->post('/login/2fa', [
            'csrf_token' => $verifyPage->formField('csrf_token') ?? '',
            'totp_code' => Totp::getCode(self::$totpSecret),
        ]);
        self::assertSame(302, $verify->statusCode, "2FA-Login gegen die Fixture-Instanz sollte klappen, Body: {$verify->body}");

        return $client;
    }
}
