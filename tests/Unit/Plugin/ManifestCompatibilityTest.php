<?php
// tests/Unit/Plugin/ManifestCompatibilityTest.php

namespace Tests\Unit\Plugin;

use App\Plugin\PluginManager;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests für die parametrisierte Kompatibilitätsprüfung (#197):
 * Untergrenze (core_compatibility, Ein-Operator-Format) und Obergrenze
 * (core_supported_max, "Major.Minor") gegen eine BELIEBIGE Kern-Version -
 * die Update-Seite prüft damit gegen die Zielversion eines anstehenden
 * Kern-Updates, nicht nur gegen die laufende.
 */
class ManifestCompatibilityTest extends TestCase {

    public function testConstraintSatisfiedSingleOperatorForms(): void {
        $this->assertTrue(PluginManager::constraintSatisfied('>=0.3.0', '0.3.0'));
        $this->assertTrue(PluginManager::constraintSatisfied('>=0.1.0-beta.1', '0.3.0'));
        $this->assertTrue(PluginManager::constraintSatisfied('0.3.0', '0.3.0')); // ohne Operator: exakt
        $this->assertFalse(PluginManager::constraintSatisfied('>=0.4.0', '0.3.0'));
        $this->assertFalse(PluginManager::constraintSatisfied('<0.3.0', '0.3.0'));
    }

    public function testConstraintSatisfiedRejectsRangesFailClosed(): void {
        // Bereichs-Syntax ist bewusst KEIN gültiges Format - sie muss
        // fail-closed ablehnen, nie stillschweigend passen (#197).
        $this->assertFalse(PluginManager::constraintSatisfied('>=0.3.0, <0.4.0', '0.3.5'));
        $this->assertFalse(PluginManager::constraintSatisfied('', '0.3.0'));
        $this->assertFalse(PluginManager::constraintSatisfied('>=0.3.0', ''));
    }

    public function testManifestSupportsWithoutUpperBound(): void {
        $manifest = ['core_compatibility' => '>=0.3.0'];
        $this->assertTrue(PluginManager::manifestSupports($manifest, '0.3.0'));
        // Ohne Obergrenze bleibt jede neuere Version erlaubt (Stufe 1;
        // die Pflicht kommt mit dem Addon-Autoupdate).
        $this->assertTrue(PluginManager::manifestSupports($manifest, '9.9.9'));
        $this->assertFalse(PluginManager::manifestSupports($manifest, '0.2.0'));
    }

    public function testManifestSupportsEnforcesUpperBound(): void {
        $manifest = ['core_compatibility' => '>=0.3.0', 'core_supported_max' => '0.4'];

        $this->assertTrue(PluginManager::manifestSupports($manifest, '0.3.0'));
        $this->assertTrue(PluginManager::manifestSupports($manifest, '0.4.0'));
        $this->assertTrue(PluginManager::manifestSupports($manifest, '0.4.17'));
        // Erst die NÄCHSTE Linie reißt die Obergrenze.
        $this->assertFalse(PluginManager::manifestSupports($manifest, '0.5.0'));
        $this->assertFalse(PluginManager::manifestSupports($manifest, '1.0.0'));
    }

    public function testIncompatibilityReasonNamesTheCause(): void {
        $lower = PluginManager::incompatibilityReason(['core_compatibility' => '>=0.4.0'], '0.3.0');
        $this->assertNotNull($lower);
        $this->assertStringContainsString('>=0.4.0', $lower);
        $this->assertStringContainsString('0.3.0', $lower);

        $upper = PluginManager::incompatibilityReason(
            ['core_compatibility' => '>=0.3.0', 'core_supported_max' => '0.3'],
            '0.4.0'
        );
        $this->assertNotNull($upper);
        $this->assertStringContainsString('höchstens', $upper);
        $this->assertStringContainsString('0.3', $upper);

        $this->assertNull(PluginManager::incompatibilityReason(
            ['core_compatibility' => '>=0.3.0', 'core_supported_max' => '0.4'],
            '0.4.2'
        ));

        $this->assertNotNull(PluginManager::incompatibilityReason(['core_compatibility' => '>=0.1.0'], ''));
    }
}
