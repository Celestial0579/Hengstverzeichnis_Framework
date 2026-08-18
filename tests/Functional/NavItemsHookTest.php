<?php
// tests/Functional/NavItemsHookTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Der Erweiterungspunkt `layout.nav_items`: ein Addon legt einen eigenen
 * Menüpunkt in die öffentliche Navigation.
 *
 * Anlass ist die Zucht-Suche
 * ([Addons #107](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/107)):
 * Gewünscht war "neben den Pferden ein Menüpunkt Zucht". Bis hierhin hatte der
 * Kern keinen Hook dafür - die Navigation war fest verdrahtet, und ein Addon
 * konnte sich nur über eine Dashboard-Kachel und Textlinks auf Detailseiten
 * behelfen. Das ist kein Menüpunkt.
 *
 * Der zweite Teil des Tests ist der wichtigere: Die Navigation steht auf JEDER
 * öffentlichen Seite. Ein Addon, das dort `javascript:` oder eine fremde
 * Domain unterbringen kann, hätte einen Hebel auf die ganze Seite. Geprüft
 * wird deshalb nicht nur, dass ein gültiger Eintrag ankommt, sondern auch,
 * dass die ungültigen es nicht tun.
 *
 * Wie in PersonStationHookTest wird dafür ein Wegwerf-Plugin in das
 * gitignorete `plugins/`-Verzeichnis geschrieben.
 */
class NavItemsHookTest extends FunctionalTestCase {

    private const SLUG = 'navtest-addon';
    private const PLUGIN_DEST = __DIR__ . '/../../plugins/navtest-addon';

    protected function tearDown(): void {
        foreach (glob(self::PLUGIN_DEST . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir(self::PLUGIN_DEST);
        try {
            Database::getInstance()->prepare("DELETE FROM plugins WHERE slug = ?")->execute([self::SLUG]);
        } catch (\Throwable $e) {
            // DB weg = nichts zu bereinigen
        }
        parent::tearDown();
    }

    public function testAddonCanAddAMenuEntryAndBadUrlsAreDropped(): void {
        $admin = $this->authenticatedClient();
        $guest = $this->newClient();

        // Vor der Aktivierung steht der Menüpunkt nirgends.
        $this->assertStringNotContainsString('NAV-ZUCHT-MARKER', $guest->get('/katalog')->body);

        $this->installNavPlugin();
        $toggle = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggle->location(), "Aktivieren fehlgeschlagen: {$toggle->body}");

        // 1. Der gültige Eintrag steht in der Navigation - und zwar auf jeder
        //    öffentlichen Seite, nicht nur auf einer.
        foreach (['/', '/katalog'] as $pfad) {
            $seite = $guest->get($pfad);
            $this->assertSame(200, $seite->statusCode, "Seite {$pfad} nicht erreichbar.");
            $this->assertStringContainsString(
                'NAV-ZUCHT-MARKER',
                $seite->body,
                "Der Menüpunkt des Addons fehlt auf {$pfad}."
            );
            $this->assertStringContainsString('href="/plugin/navtest-addon"', $seite->body);
        }

        // 2. Auf der eigenen Seite ist der Eintrag als aktiv markiert - sonst
        //    verschwände die Markierung ausgerechnet beim Draufklicken.
        $navSeite = $guest->get('/plugin/navtest-addon');
        $this->assertSame(200, $navSeite->statusCode);
        $this->assertMatchesRegularExpression(
            '/href="\/plugin\/navtest-addon"\s+class="nav-link active"/',
            $navSeite->body,
            'Der eigene Menüpunkt muss auf der eigenen Seite als aktiv markiert sein.'
        );

        // 3. Was NICHT durchkommen darf. Alle vier Einträge liefert dasselbe
        //    Plugin über denselben Hook mit; sie stehen also nicht neben-,
        //    sondern miteinander im HTML - was fehlt, wurde verworfen.
        $katalog = $guest->get('/katalog')->body;
        $this->assertStringNotContainsString('javascript:', $katalog, 'javascript:-URL darf nicht in die Navigation.');
        $this->assertStringNotContainsString('NAV-JS-MARKER', $katalog);
        $this->assertStringNotContainsString('NAV-FREMD-MARKER', $katalog, 'Absolute Fremd-URL darf nicht in die Navigation.');
        $this->assertStringNotContainsString('NAV-PROTOKOLLREL-MARKER', $katalog, 'Protokollrelative URL (//host) darf nicht durchkommen.');
        $this->assertStringNotContainsString('NAV-RAUS-MARKER', $katalog, 'Pfad mit .. darf nicht durchkommen.');

        // 4. Abschalten nimmt den Menüpunkt wieder mit.
        $off = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '0',
        ]);
        $this->assertSame('/admin/plugins?success=1', $off->location());
        $this->assertStringNotContainsString('NAV-ZUCHT-MARKER', $this->newClient()->get('/katalog')->body);
    }

    private function installNavPlugin(): void {
        @mkdir(self::PLUGIN_DEST, 0755, true);
        file_put_contents(self::PLUGIN_DEST . '/plugin.json', json_encode([
            'slug' => self::SLUG,
            'name' => 'Nav-Test-Addon',
            'version' => '1.0.0',
            'core_compatibility' => '>=0.1.0-beta.1',
            'core_supported_max' => '9.9',
            'description' => 'Prüft den Erweiterungspunkt der öffentlichen Navigation.',
            'author' => 'Tests',
            'hooks' => ['layout.nav_items'],
            'entry' => 'Plugin.php',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        file_put_contents(self::PLUGIN_DEST . '/Plugin.php', <<<'PHP'
<?php
namespace Plugin\NavtestAddon;

class Plugin {
    public function register($hooks): void {
        $hooks->addFilter('layout.nav_items', function (array $items): array {
            $items[] = ['url' => '/plugin/navtest-addon', 'label' => 'NAV-ZUCHT-MARKER', 'icon' => '🧬'];
            $items[] = ['url' => 'javascript:alert(1)', 'label' => 'NAV-JS-MARKER'];
            $items[] = ['url' => 'https://fremd.example/x', 'label' => 'NAV-FREMD-MARKER'];
            $items[] = ['url' => '//fremd.example/x', 'label' => 'NAV-PROTOKOLLREL-MARKER'];
            $items[] = ['url' => '/../raus', 'label' => 'NAV-RAUS-MARKER'];
            return $items;
        });
    }

    public function routes(): array {
        return [
            ['method' => 'GET', 'path' => '/', 'callback' => [Seite::class, 'zeigen']],
        ];
    }
}

class Seite {
    public function zeigen(): void {
        \App\Plugin\PluginPage::render('Nav-Test', '<p>NAV-ZUCHT-SEITE</p>');
    }
}
PHP);
    }
}
