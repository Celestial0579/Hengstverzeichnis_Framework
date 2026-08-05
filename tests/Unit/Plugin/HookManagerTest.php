<?php
// tests/Unit/Plugin/HookManagerTest.php

namespace Tests\Unit\Plugin;

use App\Plugin\HookManager;
use PHPUnit\Framework\TestCase;

/**
 * Reiner Unit-Test ohne DB/HTTP für App\Plugin\HookManager::applyFilters() -
 * insbesondere den Anhängen zusätzlicher Trailing-Argumente an einen
 * bestehenden Filter-Hook (siehe horse.detail_sections' viertem Parameter,
 * $pedigree). Belegt die beiden Kernannahmen dieser Erweiterung:
 * (1) ein neu registrierter Callback empfängt den zusätzlichen Wert,
 * (2) ein bestehender Callback, dessen Signatur den zusätzlichen Parameter
 * NICHT deklariert, läuft unverändert weiter (PHP ignoriert überzählige
 * positionelle Argumente) - kein Functional-/Plugin-Loading-Test nötig, da
 * plugins/ in der Testumgebung ohnehin leer ist (siehe PluginAdminTest.php).
 */
class HookManagerTest extends TestCase {

    public function testTrailingArgIsForwardedToCallback(): void {
        $hooks = new HookManager();
        $received = null;

        $hooks->addFilter('test.hook', function (array $value, array $horse, array $persons, ?array $extra) use (&$received) {
            $received = $extra;
            return $value;
        });

        $hooks->applyFilters('test.hook', [], ['id' => 1], [], ['depth' => 1]);

        $this->assertSame(['depth' => 1], $received);
    }

    public function testCallbackWithoutTrailingParamStillRuns(): void {
        $hooks = new HookManager();
        $called = false;

        // Simuliert eine "alte" Callback-Signatur wie das Referenz-Plugin
        // (docs/examples/demo-plugin), das nur 3 Parameter deklariert.
        $hooks->addFilter('test.hook', function (array $sections, array $horse, array $persons) use (&$called) {
            $called = true;
            $sections[] = 'ok';
            return $sections;
        });

        $result = $hooks->applyFilters('test.hook', [], ['id' => 1], [], ['depth' => 1]);

        $this->assertTrue($called);
        $this->assertSame(['ok'], $result);
    }
}
