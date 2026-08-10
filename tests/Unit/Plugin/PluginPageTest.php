<?php
// tests/Unit/Plugin/PluginPageTest.php

namespace Tests\Unit\Plugin;

use App\I18n\Translator;
use App\Plugin\PluginPage;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests für App\Plugin\PluginPage (Addons#66): Plugin-Seiten rendern
 * im zentralen Haupt-Layout. Läuft ohne Datenbank - loadSettings() fällt
 * dann auf die Setup-Defaults zurück, genau wie BaseController.
 */
class PluginPageTest extends TestCase {

    protected function setUp(): void {
        Translator::resetForTests();
    }

    protected function tearDown(): void {
        Translator::resetForTests();
        unset($_SESSION);
        unset($_GET['lang']);
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
}
