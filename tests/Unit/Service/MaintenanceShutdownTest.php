<?php
// tests/Unit/Service/MaintenanceShutdownTest.php

namespace Tests\Unit\Service;

use App\Service\Maintenance;
use PHPUnit\Framework\TestCase;

/**
 * Der Wartungsmodus nach dem Ende des setzenden Prozesses (Audit N72) - in
 * Kindprozessen (tests/Support/update-abbruch-kind.php), weil sich ein Fatal
 * Error, exit() oder SIGKILL nur dort nachstellen lassen.
 *
 * Vorher blieb der Marker nach einem Fatal Error liegen, und unter PHP-FPM
 * hielt die PID-Prüfung ihn für belegt: Der Worker lebt ja weiter.
 */
class MaintenanceShutdownTest extends TestCase {

    protected function setUp(): void {
        Maintenance::resetForTests();
    }

    protected function tearDown(): void {
        Maintenance::resetForTests();
    }

    public function testZeitlimitFatalRaeumtDenMarkerAuf(): void {
        [$code, $ausgabe] = $this->kind('zeitlimit');

        $this->assertNotSame(0, $code, $ausgabe);
        $this->assertStringContainsString('Maximum execution time', $ausgabe);
        $this->assertFalse(Maintenance::isActive(), 'Der Marker fällt beim Prozessende: ' . $ausgabe);
    }

    public function testExitRaeumtDenMarkerAuf(): void {
        [$code] = $this->kind('exit');

        $this->assertSame(3, $code);
        $this->assertFalse(Maintenance::isActive());
    }

    public function testEnableDauerhaftUeberlebtDasProzessende(): void {
        [$code] = $this->kind('dauerhaft');

        $this->assertSame(0, $code);
        $this->assertTrue(Maintenance::isActive());
        $this->assertNull(Maintenance::info()['token']);
        $this->assertFalse(Maintenance::isStale());
    }

    /** SIGKILL: Kein Shutdown läuft - der Marker bleibt, gilt aber sofort als verwaist. */
    public function testSigkillLaesstMarkerAlsVerwaistZurueck(): void {
        $prozess = proc_open(
            [PHP_BINARY, __DIR__ . '/../../Support/update-abbruch-kind.php', 'wartung', 'kill'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertSame("BEREIT\n", fgets($pipes[1]));
        $this->assertTrue(Maintenance::isActive());
        $this->assertFalse(Maintenance::isStale(), 'Solange das Kind lebt, hält es die Inhaber-Sperre');

        proc_terminate($prozess, 9);
        proc_close($prozess);

        $this->assertTrue(Maintenance::isActive());
        $this->assertTrue(Maintenance::isStale());
    }

    /**
     * Die Shutdown-Funktion eines Dritten bricht mit einem Fatal Error ab,
     * bevor das Aufräumen dran ist. Der Marker liegt dann zwar noch - aber
     * die Inhaber-Sperre ist mit dem Prozess gefallen, der nächste Request
     * räumt ihn weg.
     */
    public function testFatalInFremderShutdownFunktionHinterlaesstVerwaistenMarker(): void {
        [$code, $ausgabe] = $this->kind('fremder_fatal');

        $this->assertNotSame(0, $code, $ausgabe);
        $this->assertTrue(Maintenance::isActive(), 'Das Aufräumen kam nicht mehr dran');
        $this->assertTrue(Maintenance::isStale(), 'Die Inhaber-Sperre ist mit dem Prozess gefallen');
    }

    /** @return array{0: int, 1: string} */
    private function kind(string $art): array {
        $prozess = proc_open(
            [PHP_BINARY, __DIR__ . '/../../Support/update-abbruch-kind.php', 'wartung', $art],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($prozess);
        $ausgabe = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        return [proc_close($prozess), $ausgabe];
    }
}
