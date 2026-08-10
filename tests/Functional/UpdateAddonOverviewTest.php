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
    private const ADDON_FIXTURE = 'releases-fixture-addon-overview-addon-releases.json';
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
                // Addon-Releases-Liste ebenfalls als Fixture vom geteilten
                // Server - der Verweigerungsfall (#212) braucht eine leere,
                // aber ERREICHBARE Liste, damit gezielt "kein Release zur
                // Linie" geprüft wird und nicht ein Netzfehler.
                'ADDON_RELEASES_URL' => \Tests\Support\PhpBuiltInServer::baseUrl() . '/' . self::ADDON_FIXTURE,
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
        @unlink(__DIR__ . '/../../public/' . self::ADDON_FIXTURE);
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

    public function testAddonUpdateRequiresCsrfToken(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->post('/admin/updates/addon', ['slug' => 'irrelevant']);
        $this->assertSame(403, $response->statusCode);
    }

    /**
     * Fremd-Quellen und manuell kopierte Addons (keine plugins.source-Zeile
     * auf das offizielle Repo) lehnt der Server ab, BEVOR irgendein
     * Netzwerkzugriff passiert (#197, Stufe 2).
     */
    public function testAddonUpdateRefusesNonOfficialSource(): void {
        $admin = $this->authenticatedClient();
        $this->createPluginDir([]);

        $response = $admin->post('/admin/updates/addon', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
        ]);

        $location = (string)$response->location();
        $this->assertStringStartsWith('/admin/updates?addon_error=', $location);
        $this->assertStringContainsString('offiziellen', urldecode($location));
    }

    /**
     * Verweigerungsfall aus #212: Ein offizielles Addon MIT source-Pin, aber
     * die Releases-Liste der Kern-Linie ist leer - das Update muss mit einer
     * sprechenden Meldung abgelehnt werden, statt (wie früher) auf den
     * veränderlichen Branch-HEAD des Addons-Repos zurückzufallen. Läuft
     * gegen die Fixture-Instanz, deren ADDON_RELEASES_URL eine leere, aber
     * erreichbare Liste liefert - so ist sichergestellt, dass wirklich der
     * "kein Release"-Zweig greift und kein Netz-/GitHub-Zugriff passiert.
     */
    public function testAddonUpdateRefusesWhenNoReleaseForCoreLineExists(): void {
        $this->createPluginDir([]);

        // source auf das offizielle Repo pinnen (wie es der Store beim
        // Installieren täte) - erst damit ist das Addon überhaupt
        // update-berechtigt und der Release-Check wird erreicht.
        $db = Database::getInstance();
        $official = $db->query("SELECT owner, repo FROM addon_repos WHERE is_official = 1 LIMIT 1")->fetch();
        $this->assertNotFalse($official, 'Seed des offiziellen Addon-Repos muss vorhanden sein');
        $stmt = $db->prepare(
            "INSERT INTO plugins (slug, source) VALUES (?, ?) ON DUPLICATE KEY UPDATE source = VALUES(source)"
        );
        $stmt->execute([self::SLUG, "{$official['owner']}/{$official['repo']}"]);

        // Leere, gültige Releases-Liste: kein Release zu KEINER Linie.
        file_put_contents(__DIR__ . '/../../public/' . self::ADDON_FIXTURE, '[]');

        $admin = $this->fixtureInstanceAdmin();
        $response = $admin->post('/admin/updates/addon', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
        ]);

        $location = (string)$response->location();
        $this->assertStringStartsWith('/admin/updates?addon_error=', $location);
        $this->assertStringContainsString('kein Addon-Release', urldecode($location));
        // Und der installierte Stand ist unangetastet geblieben.
        $manifest = json_decode((string)file_get_contents($this->pluginDir() . '/plugin.json'), true);
        $this->assertSame('1.0.0', $manifest['version']);
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
            // Seit Stufe 2 Pflicht - Basis-Fixture bewusst weit in der
            // Zukunft, damit die Fälle unten den jeweils relevanten Aspekt
            // testen (der Zielversions-Fall überschreibt mit '0.3').
            'core_supported_max' => '9.9',
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
            'core_supported_max' => '9.9',
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
