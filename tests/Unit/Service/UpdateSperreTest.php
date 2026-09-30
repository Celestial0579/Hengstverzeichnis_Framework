<?php
// tests/Unit/Service/UpdateSperreTest.php

namespace Tests\Unit\Service;

use App\Service\UpdateLaeuftBereits;
use App\Service\UpdateSperre;
use PHPUnit\Framework\TestCase;

/**
 * Die Update-Sperre (Audit M44): exklusiv zwischen Prozessen, reentrant im
 * selben Prozess - und zwar auch über Pfadvarianten derselben Wurzel, sonst
 * sperrt sich ein Prozess selbst aus (Linux behandelt zwei fopen() derselben
 * Datei im selben Prozess als Konkurrenten).
 */
class UpdateSperreTest extends TestCase {

    private string $wurzel;

    /** @var array<int, resource> */
    private array $prozesse = [];

    protected function setUp(): void {
        $this->wurzel = sys_get_temp_dir() . '/' . uniqid('hengst_sperre_');
        mkdir($this->wurzel, 0755, true);
    }

    protected function tearDown(): void {
        foreach ($this->prozesse as $prozess) {
            @proc_terminate($prozess, 9);
            @proc_close($prozess);
        }
        UpdateSperre::resetForTests();
        @unlink($this->wurzel . '/var/update.lock');
        @rmdir($this->wurzel . '/var');
        @rmdir($this->wurzel);
    }

    public function testFehlendesVarWirdAngelegt(): void {
        UpdateSperre::erwerben($this->wurzel, 'Test');
        $this->assertFileExists($this->wurzel . '/var/update.lock');
        UpdateSperre::freigeben($this->wurzel);
    }

    public function testReentrantAuchMitPfadvarianten(): void {
        UpdateSperre::erwerben($this->wurzel, 'aussen');
        UpdateSperre::erwerben($this->wurzel . '/', 'innen 1');
        UpdateSperre::erwerben($this->wurzel . '/./', 'innen 2');
        $this->assertTrue(UpdateSperre::versuchen($this->wurzel, 'innen 3'));

        // Drei Freigaben lassen die Sperre stehen, erst die vierte löst sie.
        UpdateSperre::freigeben($this->wurzel);
        UpdateSperre::freigeben($this->wurzel . '/');
        UpdateSperre::freigeben($this->wurzel);
        $this->assertFalse($this->fremderFlockGelingt(), 'Nach drei von vier Freigaben ist die Sperre noch gehalten');

        UpdateSperre::freigeben($this->wurzel);
        $this->assertTrue($this->fremderFlockGelingt(), 'Nach der letzten Freigabe ist sie frei');
        $this->assertTrue(UpdateSperre::istFrei($this->wurzel));
    }

    public function testFreigabeImFinallyAuchNachAusnahme(): void {
        try {
            UpdateSperre::erwerben($this->wurzel, 'Test');
            try {
                throw new \RuntimeException('Fehlschlag mitten im Lauf');
            } finally {
                UpdateSperre::freigeben($this->wurzel);
            }
        } catch (\RuntimeException) {
        }
        $this->assertTrue(UpdateSperre::istFrei($this->wurzel));
    }

    public function testEigenerFlockImSelbenProzessGiltAlsBelegt(): void {
        @mkdir($this->wurzel . '/var');
        $griff = fopen($this->wurzel . '/var/update.lock', 'c+');
        $this->assertTrue(flock($griff, LOCK_EX));
        try {
            $this->assertFalse(UpdateSperre::versuchen($this->wurzel, 'zweiter'));
            $this->expectException(UpdateLaeuftBereits::class);
            UpdateSperre::erwerben($this->wurzel, 'zweiter');
        } finally {
            flock($griff, LOCK_UN);
            fclose($griff);
        }
    }

    public function testKindprozessHaeltSperreUndNachSigkillIstSieFrei(): void {
        $prozess = $this->kindHaeltSperre();

        $this->assertFalse(UpdateSperre::istFrei($this->wurzel));
        try {
            UpdateSperre::erwerben($this->wurzel, 'Zweiter Lauf');
            $this->fail('Ein zweiter Lauf muss abgewiesen werden');
        } catch (UpdateLaeuftBereits $e) {
            $this->assertStringContainsString('läuft bereits', $e->getMessage());
            $this->assertStringContainsString('Test', $e->getMessage(), 'Die Meldung nennt den Zweck des anderen Laufs');
        }

        proc_terminate($prozess, 9);
        proc_close($prozess);
        $this->prozesse = [];

        $this->assertTrue(UpdateSperre::istFrei($this->wurzel), 'Der Kernel gibt die Sperre beim Prozessende frei');
        UpdateSperre::erwerben($this->wurzel, 'Jetzt');
        UpdateSperre::freigeben($this->wurzel);
    }

    /** @return resource */
    private function kindHaeltSperre() {
        $prozess = proc_open(
            [PHP_BINARY, __DIR__ . '/../../Support/update-abbruch-kind.php', 'sperre', $this->wurzel],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($prozess);
        $this->prozesse[] = $prozess;
        $this->assertSame("BEREIT\n", fgets($pipes[1]));
        return $prozess;
    }

    private function fremderFlockGelingt(): bool {
        $griff = fopen($this->wurzel . '/var/update.lock', 'r');
        try {
            if (flock($griff, LOCK_EX | LOCK_NB)) {
                flock($griff, LOCK_UN);
                return true;
            }
            return false;
        } finally {
            fclose($griff);
        }
    }
}
