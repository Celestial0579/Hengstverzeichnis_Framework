<?php
// tests/Unit/Plugin/PluginPageTest.php

namespace Tests\Unit\Plugin;

use App\Database;
use App\I18n\Translator;
use App\Plugin\PluginPage;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests für App\Plugin\PluginPage (Addons#66): Plugin-Seiten rendern
 * im zentralen Haupt-Layout. Läuft ohne Datenbank - loadSettings() fällt
 * dann auf die Setup-Defaults zurück, genau wie BaseController.
 *
 * Ausnahme: Der Test zur deaktivierten Sprache (#220) braucht echte
 * Settings mit `active_locales` und injiziert dafür eine private
 * In-Memory-SQLite-Datenbank in den Database-Singleton (dasselbe Muster
 * wie tests/Unit/Security/ApiKeyTest::useInMemoryDatabase()).
 */
class PluginPageTest extends TestCase {

    protected function setUp(): void {
        Translator::resetForTests();
    }

    protected function tearDown(): void {
        Translator::resetForTests();
        unset($_SESSION);
        unset($_GET['lang']);
        // Injizierte SQLite-Attrappe entfernen, damit kein anderer Test
        // versehentlich gegen sie läuft.
        $property = new \ReflectionProperty(Database::class, 'instance');
        $property->setValue(null, null);
    }

    /**
     * Injiziert eine In-Memory-SQLite-Datenbank mit genau den übergebenen
     * Settings in den Database-Singleton, damit PluginPage::loadSettings()
     * statt der Setup-Defaults einen konfigurierten Betreiber-Stand sieht.
     *
     * @param array<string, string> $settings
     */
    private function useSettingsDatabase(array $settings): void {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL)");
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
        foreach ($settings as $key => $value) {
            $stmt->execute([$key, $value]);
        }

        $property = new \ReflectionProperty(Database::class, 'instance');
        $property->setValue(null, $pdo);
    }

    private function renderToString(string $title, string $content): string {
        ob_start();
        try {
            PluginPage::render($title, $content);
        } finally {
            $html = (string)ob_get_clean();
        }
        return $html;
    }

    public function testRenderWrapsContentInFullLayout(): void {
        $html = $this->renderToString('Testseite', '<div class="card" id="plugin-inhalt">Hallo Layout!</div>');

        // Vollständiges Dokument mit Framework-Gerüst ...
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('/css/style.css', $html);
        $this->assertStringContainsString('<header>', $html);
        $this->assertStringContainsString('<footer>', $html);
        $this->assertStringContainsString('theme-toggle', $html, 'Theme-Umschalter muss auf Plugin-Seiten verfügbar sein');
        // ... Markenfarben-Injektion aus den Einstellungen ...
        $this->assertStringContainsString('--primary-color:', $html);
        // ... und der Plugin-Inhalt unescaped in <main>.
        $this->assertStringContainsString('<div class="card" id="plugin-inhalt">Hallo Layout!</div>', $html);
    }

    public function testRenderEscapesTitle(): void {
        $html = $this->renderToString('Böse <script> & Zeichen', '<p>x</p>');

        $this->assertStringContainsString('<title>Böse &lt;script&gt; &amp; Zeichen</title>', $html);
        $this->assertStringNotContainsString('<title>Böse <script>', $html);
    }

    public function testRenderInitializesLocaleFromLangParameter(): void {
        $_GET['lang'] = 'en';
        $html = $this->renderToString('Locale-Test', '<p>x</p>');

        $this->assertStringContainsString('lang="en"', $html);
        $this->assertSame('en', Translator::getLocale());
    }

    public function testRenderIgnoresLangParameterOfDeactivatedLocale(): void {
        // #220: Der Betreiber hat pl deaktiviert (active_locales ohne pl).
        // Die alte PluginPage-Kopie der Locale-Auswahl prüfte nur gegen die
        // VERFÜGBAREN Sprachen und hätte ?lang=pl übernommen.
        $this->useSettingsDatabase([
            'site_name' => 'Testbetrieb',
            'language' => 'de',
            'active_locales' => 'en',
        ]);
        $_GET['lang'] = 'pl';

        $html = $this->renderToString('Locale-Test', '<p>x</p>');

        $this->assertStringContainsString('lang="de"', $html);
        $this->assertSame('de', Translator::getLocale());
        $this->assertArrayNotHasKey('locale', $_SESSION ?? [], 'Eine deaktivierte ?lang=-Wahl darf nicht in die Session.');
    }

    public function testRenderCleansStaleSessionLocaleOfDeactivatedLanguage(): void {
        // #220, Schritt 3 des Fehlerszenarios: Die Session hält noch eine
        // Sprache, die der Betreiber inzwischen deaktiviert hat - die
        // Plugin-Seite muss in der Standardsprache rendern UND die
        // veraltete Session-Wahl entfernen.
        $this->useSettingsDatabase([
            'site_name' => 'Testbetrieb',
            'language' => 'de',
            'active_locales' => 'en',
        ]);
        $_SESSION['locale'] = 'pl';

        $html = $this->renderToString('Locale-Test', '<p>x</p>');

        $this->assertStringContainsString('lang="de"', $html);
        $this->assertSame('de', Translator::getLocale());
        $this->assertArrayNotHasKey('locale', $_SESSION, 'Die inaktiv gewordene Session-Wahl muss bereinigt werden.');
    }
}
