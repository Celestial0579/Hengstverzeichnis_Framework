<?php
// tests/Unit/Plugin/DemoPluginThemingTest.php

namespace Tests\Unit\Plugin;

use PHPUnit\Framework\TestCase;

/**
 * Statischer Theming-Lint über das Referenz-Plugin (Addons#66): Das
 * Demo-Plugin ist die Vorlage, aus der neue Addons entstehen - die
 * eigenständigen, unthemebaren HTML-Dokumente seiner früheren Fassung
 * wurden von 14 der 15 offiziellen Addons kopiert. Dieser Test verhindert,
 * dass der "Generator des Defekts" zurückkehrt.
 */
class DemoPluginThemingTest extends TestCase {

    private const DEMO_DIR = __DIR__ . '/../../../docs/examples/demo-plugin';

    /** @return array<int, string> */
    private function demoPhpSources(): array {
        $files = glob(self::DEMO_DIR . '/*.php') ?: [];
        $this->assertNotEmpty($files, 'Demo-Plugin-Quellen nicht gefunden');
        return $files;
    }

    public function testDemoPluginEmitsNoStandaloneDocuments(): void {
        foreach ($this->demoPhpSources() as $file) {
            $source = (string)file_get_contents($file);
            $this->assertStringNotContainsString('<!DOCTYPE', $source, basename($file) . ' gibt wieder ein eigenständiges Dokument aus');
            $this->assertStringNotContainsString('font-family: sans-serif', $source, basename($file) . ' setzt wieder eine eigene Schriftart');
            $this->assertStringNotContainsString('<body', $source, basename($file) . ' baut wieder ein eigenes <body>');
        }
    }

    public function testDemoPluginPagesUseThePluginPageService(): void {
        $pluginSource = (string)file_get_contents(self::DEMO_DIR . '/Plugin.php');
        $this->assertStringContainsString('PluginPage::render', $pluginSource);
    }
}
