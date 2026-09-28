<?php
// tests/Unit/Helper/AtomicFileTest.php

namespace Tests\Unit\Helper;

use App\Helper\AtomicFile;
use PHPUnit\Framework\TestCase;

/**
 * Atomares Schreiben geheimnistragender Dateien (Audit N56).
 *
 * Kern ist testNichtBeschreibbaresVerzeichnis(): Das Original bleibt
 * byte-identisch, und die Datei landet NICHT still im System-Temp-Verzeichnis
 * (die tempnam()-Falle). phpunit.xml setzt failOnNotice/failOnWarning - ein
 * Notice oder eine Warning ließe diese Tests also scheitern.
 */
class AtomicFileTest extends TestCase {

    private string $dir;

    protected function setUp(): void {
        $this->dir = sys_get_temp_dir() . '/atomicfile-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void {
        @chmod($this->dir, 0700);
        foreach (array_merge(glob($this->dir . '/{,.}*', GLOB_BRACE) ?: []) as $eintrag) {
            $name = basename($eintrag);
            if ($name === '.' || $name === '..') {
                continue;
            }
            is_dir($eintrag) ? @rmdir($eintrag) : @unlink($eintrag);
        }
        @rmdir($this->dir);
    }

    /** @return string[] */
    private function tempReste(): array {
        return glob($this->dir . '/.*.tmp') ?: [];
    }

    public function testLegtDieDateiMitInhaltUndRechten0600An(): void {
        $ziel = $this->dir . '/db_config.php';

        $this->assertTrue(AtomicFile::write($ziel, "<?php return ['app_key' => 'x'];\n"));

        $this->assertSame("<?php return ['app_key' => 'x'];\n", file_get_contents($ziel));
        clearstatcache();
        $this->assertSame(0600, fileperms($ziel) & 0777);
        $this->assertSame([], $this->tempReste());
    }

    public function testUeberschreibenErsetztDenInhaltOhneTempReste(): void {
        $ziel = $this->dir . '/db_config.php';
        file_put_contents($ziel, str_repeat('alt', 1000));

        $this->assertTrue(AtomicFile::write($ziel, 'neu'));

        $this->assertSame('neu', file_get_contents($ziel));
        $this->assertSame([], $this->tempReste());
    }

    public function testAndereRechteAufWunsch(): void {
        $ziel = $this->dir . '/datei.txt';
        $this->assertTrue(AtomicFile::write($ziel, 'x', 0640));
        clearstatcache();
        $this->assertSame(0640, fileperms($ziel) & 0777);
    }

    public function testNichtBeschreibbaresVerzeichnis(): void {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Als root greifen Verzeichnisrechte nicht.');
        }
        $ziel = $this->dir . '/db_config.php';
        file_put_contents($ziel, 'ORIGINAL');
        chmod($this->dir, 0500);

        $inhalt = 'GEHEIM-' . bin2hex(random_bytes(8));
        $vorher = glob(sys_get_temp_dir() . '/*') ?: [];

        $this->assertFalse(AtomicFile::write($ziel, $inhalt));

        chmod($this->dir, 0700);
        $this->assertSame('ORIGINAL', file_get_contents($ziel), 'Das Original muss byte-identisch bleiben.');
        $this->assertSame([], $this->tempReste());
        foreach (array_diff(glob(sys_get_temp_dir() . '/*') ?: [], $vorher) as $neu) {
            if (is_file($neu)) {
                $this->assertStringNotContainsString($inhalt, (string)@file_get_contents($neu), 'Kein Ausweichen ins System-Temp-Verzeichnis.');
            }
        }
    }

    public function testZielIstEinVerzeichnis(): void {
        $ziel = $this->dir . '/unterordner';
        mkdir($ziel);
        file_put_contents($ziel . '/drin', 'x');

        $this->assertFalse(AtomicFile::write($ziel, 'inhalt'));

        $this->assertDirectoryExists($ziel);
        $this->assertSame([], $this->tempReste());
    }

    public function testVerzeichnisFehlt(): void {
        $this->assertFalse(AtomicFile::write($this->dir . '/gibt-es-nicht/db_config.php', 'x'));
    }
}
