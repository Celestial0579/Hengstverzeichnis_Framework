<?php
// tests/Unit/Service/BackupTempAufraeumenTest.php

namespace Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Audit M37: Brach die Sicherung mit einem Fatal Error ab (Zeit- oder
 * Speicherlimit), übersprang PHP das finally, und die Zwischendateien
 * blieben in /tmp liegen - bei jedem Abbruch in Größe des Dumps bzw.
 * Archivs. BackupService::tempFile() meldet die Datei deshalb bei einer
 * Shutdown-Funktion an. Geprüft in einem eigenen PHP-Prozess, der ohne
 * Aufräumen endet - per exit und per echtem Fatal Error. Braucht keine
 * Datenbank.
 */
class BackupTempAufraeumenTest extends TestCase {

    /**
     * @return array{0: int, 1: string} Exit-Code und Ausgabe
     */
    private function subprozess(string $danach): array {
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $code = 'require ' . var_export($autoload, true) . ';'
            . '$m = new ReflectionMethod(App\Service\BackupService::class, "tempFile");'
            . '$pfad = $m->invoke(null, "hv-backup-sql-");'
            . 'file_put_contents($pfad, "halbfertig");'
            . 'fwrite(STDOUT, $pfad . "\n");'
            . $danach;
        $cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -r ' . escapeshellarg($code) . ' 2>/dev/null';
        exec($cmd, $ausgabe, $exitCode);
        return [$exitCode, trim($ausgabe[0] ?? '')];
    }

    public function testTempDateiVerschwindetNachExitOhneAufraeumen(): void {
        [$exitCode, $pfad] = $this->subprozess('exit(3);');

        $this->assertSame(3, $exitCode);
        $this->assertStringContainsString('hv-backup-sql-', $pfad);
        $this->assertFileDoesNotExist($pfad);
    }

    public function testTempDateiVerschwindetNachFatalError(): void {
        [$exitCode, $pfad] = $this->subprozess(
            'ini_set("memory_limit", "32M"); $x = str_repeat("x", 64 * 1024 * 1024); echo strlen($x);'
        );

        $this->assertSame(255, $exitCode, 'Der Subprozess sollte mit einem Fatal Error enden');
        $this->assertStringContainsString('hv-backup-sql-', $pfad);
        $this->assertFileDoesNotExist($pfad);
    }
}
