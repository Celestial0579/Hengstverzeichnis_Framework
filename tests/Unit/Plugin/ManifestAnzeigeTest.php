<?php
// tests/Unit/Plugin/ManifestAnzeigeTest.php

namespace Tests\Unit\Plugin;

use App\Plugin\PluginManager;
use PHPUnit\Framework\TestCase;

/**
 * Manifestfelder, die kein Text sind (Audit N84): validateManifest() weist
 * description/author ab, die kein String sind, und discoverPlugins()
 * speichert ein für die Anzeige normalisiertes Manifest - vorher legte
 * z. B. `"description": {"de": …}` /admin/plugins per TypeError lahm.
 */
class ManifestAnzeigeTest extends TestCase {

    /** @return array<string, mixed> */
    private function gueltig(array $zusatz = []): array {
        return $zusatz + [
            'slug' => 'demo',
            'name' => 'Demo',
            'version' => '1.0.0',
            'core_compatibility' => '>=0.1.0',
            'core_supported_max' => '9.9',
        ];
    }

    private function validiere(mixed $manifest): ?string {
        $manager = (new \ReflectionClass(PluginManager::class))->newInstanceWithoutConstructor();
        return (new \ReflectionMethod(PluginManager::class, 'validateManifest'))->invoke($manager, $manifest, 'demo');
    }

    /** @return array<mixed> */
    private function normalisiere(array $manifest): array {
        return (new \ReflectionMethod(PluginManager::class, 'anzeigeFelderNormalisieren'))->invoke(null, $manifest);
    }

    public function testDescriptionAlsObjektMachtManifestUngueltig(): void {
        $fehler = $this->validiere($this->gueltig(['description' => ['de' => 'Hallo', 'en' => 'Hello']]));
        $this->assertIsString($fehler);
        $this->assertStringContainsString('description', $fehler);
    }

    public function testAuthorAlsZahlMachtManifestUngueltig(): void {
        $fehler = $this->validiere($this->gueltig(['author' => 123]));
        $this->assertIsString($fehler);
        $this->assertStringContainsString('author', $fehler);
    }

    public function testFehlendeOderLeereAnzeigefelderBleibenErlaubt(): void {
        $this->assertNull($this->validiere($this->gueltig()));
        $this->assertNull($this->validiere($this->gueltig(['description' => null, 'author' => null])));
        $this->assertNull($this->validiere($this->gueltig(['description' => 'Text', 'author' => 'Jemand'])));
    }

    public function testNormalisierungEntferntNichtTextUndBehaeltSemantischeFelder(): void {
        $roh = [
            'slug' => 'demo',
            'name' => ['x'],
            'description' => ['de' => 'Hallo'],
            'author' => 'Jemand',
            'version' => '1.0.0',
            'core_compatibility' => 7,
            'hooks' => ['a', ['b'], 3],
            'permissions' => 'keine liste',
            'entry' => 'Plugin.php',
            'owns' => ['tables' => ['demo_tabelle'], 'settings' => ['demo_']],
            'routes' => [['GET', '/x']],
        ];

        $anzeige = $this->normalisiere($roh);

        $this->assertArrayNotHasKey('name', $anzeige);
        $this->assertArrayNotHasKey('description', $anzeige);
        $this->assertArrayNotHasKey('core_compatibility', $anzeige);
        $this->assertArrayNotHasKey('permissions', $anzeige);
        $this->assertSame(['a'], $anzeige['hooks']);
        $this->assertSame('Jemand', $anzeige['author']);
        $this->assertSame('1.0.0', $anzeige['version']);
        $this->assertSame('demo', $anzeige['slug']);
        $this->assertSame($roh['entry'], $anzeige['entry']);
        $this->assertSame($roh['owns'], $anzeige['owns']);
        $this->assertSame($roh['routes'], $anzeige['routes']);
    }

    public function testGueltigesManifestBleibtUnveraendert(): void {
        $roh = $this->gueltig(['description' => 'Text', 'author' => 'Jemand', 'hooks' => ['a', 'b'], 'permissions' => ['demo.view']]);
        $this->assertSame($roh, $this->normalisiere($roh));
    }
}
