<?php
// tests/Unit/Service/MaintenanceTest.php

namespace Tests\Unit\Service;

use App\Service\Maintenance;
use PHPUnit\Framework\TestCase;

/**
 * Wartungsmodus-API (#232, siehe App\Service\Maintenance): Marker-Datei
 * anlegen/prüfen/entfernen - bewusst ohne jede Datenbank, denn genau das ist
 * die Zusicherung der Klasse (der Check muss auch bei halb eingespielter
 * Datenbank funktionieren). Der HTTP-Weg (503 + Retry-After über den frühen
 * Bootstrap-Guard) wird separat in tests/Functional/MaintenanceModeTest
 * über eine echte php -S-Instanz abgesichert.
 *
 * Die Tests arbeiten mit der ECHTEN Marker-Datei des Arbeitsverzeichnisses
 * (var/wartung.lock) - tearDown() räumt sie deshalb auch im Fehlerfall
 * zuverlässig ab, damit kein fehlgeschlagener Testlauf die lokale
 * Entwicklungsinstanz dauerhaft in den Wartungsmodus versetzt.
 */
class MaintenanceTest extends TestCase {

    protected function setUp(): void {
        Maintenance::disable();
    }

    protected function tearDown(): void {
        Maintenance::disable();
    }

    public function testInactiveWithoutMarkerFile(): void {
        $this->assertFalse(Maintenance::isActive());
        $this->assertNull(Maintenance::info());
    }

    public function testEnableCreatesMarkerAndActivates(): void {
        Maintenance::enable('Unit-Test: Datenbank-Restore');

        $this->assertTrue(Maintenance::isActive());
        $this->assertFileExists(Maintenance::lockFile());

        $info = Maintenance::info();
        $this->assertNotNull($info);
        $this->assertSame('Unit-Test: Datenbank-Restore', $info['grund']);
        // 'seit' ist ein ISO-8601-Zeitstempel (date('c')) - parsebar und aktuell.
        $seit = strtotime($info['seit']);
        $this->assertNotFalse($seit);
        $this->assertEqualsWithDelta(time(), $seit, 60);
    }

    public function testDisableRemovesMarker(): void {
        Maintenance::enable('Unit-Test: wird gleich wieder beendet');
        $this->assertTrue(Maintenance::isActive());

        Maintenance::disable();

        $this->assertFalse(Maintenance::isActive());
        $this->assertFileDoesNotExist(Maintenance::lockFile());
    }

    public function testDisableWithoutActiveMarkerIsHarmless(): void {
        // disable() steht in Werkzeugen typischerweise im finally-Block und
        // muss auch laufen dürfen, wenn enable() nie erreicht wurde.
        Maintenance::disable();
        Maintenance::disable();
        $this->assertFalse(Maintenance::isActive());
    }

    public function testRepeatedEnableOverwritesReason(): void {
        Maintenance::enable('Erster Grund');
        Maintenance::enable('Zweiter Grund');

        $info = Maintenance::info();
        $this->assertNotNull($info);
        $this->assertSame('Zweiter Grund', $info['grund']);
    }

    public function testManuallyTouchedEmptyMarkerStillActivates(): void {
        // Ein Betreiber darf den Wartungsmodus auch per `touch var/wartung.lock`
        // setzen - isActive() prüft nur die Existenz, info() liefert dann
        // mangels Inhalt null statt zu scheitern.
        file_put_contents(Maintenance::lockFile(), '');

        $this->assertTrue(Maintenance::isActive());
        $this->assertNull(Maintenance::info());
    }

    public function testGuardIsNoOpUnderCli(): void {
        // Unter CLI (PHPUnit läuft als CLI-Prozess) darf der Guard weder den
        // Prozess beenden noch Ausgaben erzeugen - die Werkzeuge, die den
        // Wartungsmodus setzen, müssen ja weiterarbeiten können.
        Maintenance::enable('Unit-Test: Guard unter CLI');

        $this->expectOutputString('');
        Maintenance::guard();

        // Wären wir hier nicht angekommen, hätte guard() den Prozess beendet.
        $this->assertTrue(Maintenance::isActive());
    }
}
