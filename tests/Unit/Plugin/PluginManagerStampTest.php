<?php
// tests/Unit/Plugin/PluginManagerStampTest.php

namespace Tests\Unit\Plugin;

use App\Plugin\PluginManager;
use PHPUnit\Framework\TestCase;

/**
 * Reiner Unit-Test (ohne DB/HTTP) für die Stempel-/Fingerabdruck-Logik des
 * PluginManagers (#224) und die Release-Herkunfts-Prüfung (#212):
 *
 * - computeDirStamp(): der billige Verzeichnis-Stempel (max(filemtime):
 *   Dateianzahl:Gesamtgröße) muss deterministisch sein und auf JEDE der drei
 *   Komponenten reagieren - er ist der Vorfilter, der den SHA-256 über alle
 *   Plugin-Dateien im Normalbetrieb einspart.
 * - fingerprintOf()/dirStampOf(): lazy + memoisiert. Die Memoisierung ist
 *   kein Detail, sondern der eigentliche Fix: Vor #224 wurde der SHA-256 in
 *   discoverPlugins() für jedes Plugin bei jedem Request eager berechnet.
 * - isReleaseTagSource(): nur 'owner/repo@vX.Y.z' darf einen Versionswechsel
 *   automatisch akzeptiert bekommen - Branch-Refs und manuelle Installationen
 *   (source NULL) sind fail-closed freigabepflichtig.
 *
 * Die privaten Methoden werden per Reflection aufgerufen; die Instanz entsteht
 * über newInstanceWithoutConstructor(), weil der Konstruktor privat ist
 * (Singleton) und für diese Methoden kein Zustand aus dem Konstruktor nötig ist.
 * Das DB-gebundene Zusammenspiel (Kurzschluss beim Boot, Nachschreiben des
 * Stempels, Reapproval-Entscheidungen) prüft tests/Integration/PluginManagerStampTest.php.
 */
class PluginManagerStampTest extends TestCase {

    private string $fixtureDir;

    protected function setUp(): void {
        $this->fixtureDir = sys_get_temp_dir() . '/hv-stamp-test-' . bin2hex(random_bytes(6));
        mkdir($this->fixtureDir . '/sub', 0777, true);
        file_put_contents($this->fixtureDir . '/plugin.json', '{"slug":"stamp-fixture"}');
        file_put_contents($this->fixtureDir . '/sub/data.txt', 'abcdef');
    }

    protected function tearDown(): void {
        $this->removeDir($this->fixtureDir);
    }

    private function removeDir(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }

    private function manager(): PluginManager {
        return (new \ReflectionClass(PluginManager::class))->newInstanceWithoutConstructor();
    }

    private function invoke(PluginManager $manager, string $method, mixed ...$args): mixed {
        return (new \ReflectionMethod(PluginManager::class, $method))->invoke($manager, ...$args);
    }

    /** Hinterlegt einen discovered-Eintrag, wie ihn discoverPlugins() anlegen würde. */
    private function seedDiscovered(PluginManager $manager, string $slug, ?string $error = null): void {
        $property = new \ReflectionProperty(PluginManager::class, 'discovered');
        $property->setValue($manager, [
            $slug => [
                'slug' => $slug,
                'dir' => $this->fixtureDir,
                'manifest' => ['slug' => $slug, 'name' => 'Fixture', 'version' => '1.0.0'],
                'error' => $error,
                'compatible' => $error === null,
                'incompatible_reason' => null,
                'fingerprint' => null,
                'dir_stamp' => null,
            ],
        ]);
    }

    // ---- computeDirStamp() ---------------------------------------------

    public function testDirStampIsDeterministicAndHasExpectedShape(): void {
        $manager = $this->manager();
        $stamp = $this->invoke($manager, 'computeDirStamp', $this->fixtureDir);

        // Format max(filemtime):Dateianzahl:Summe(filesize) - 2 Dateien mit
        // zusammen 26 + 6 Bytes (siehe setUp()).
        $expectedBytes = strlen('{"slug":"stamp-fixture"}') + strlen('abcdef');
        $this->assertMatchesRegularExpression('/^\d+:2:' . $expectedBytes . '$/', $stamp);

        // Zweiter Aufruf ohne Änderung am Verzeichnis: identischer Stempel.
        $this->assertSame($stamp, $this->invoke($manager, 'computeDirStamp', $this->fixtureDir));
    }

    public function testDirStampChangesOnMtimeSizeAndFileCount(): void {
        $manager = $this->manager();
        $stamp = $this->invoke($manager, 'computeDirStamp', $this->fixtureDir);

        // Geänderte mtime bei identischem Inhalt (z. B. frisch entpacktes
        // Deployment) - genau der Fall, der später den vollen Hash-Vergleich
        // plus Stempel-Nachschreiben auslöst.
        touch($this->fixtureDir . '/sub/data.txt', time() + 3600);
        $afterTouch = $this->invoke($manager, 'computeDirStamp', $this->fixtureDir);
        $this->assertNotSame($stamp, $afterTouch, 'Stempel muss auf eine geänderte mtime reagieren');

        // Geänderte Gesamtgröße (Datei wächst), mtime konstant halten, damit
        // wirklich die Größen-Komponente greift.
        $mtime = (int)filemtime($this->fixtureDir . '/sub/data.txt');
        file_put_contents($this->fixtureDir . '/sub/data.txt', 'abcdefgh');
        touch($this->fixtureDir . '/sub/data.txt', $mtime);
        clearstatcache();
        $afterGrow = $this->invoke($manager, 'computeDirStamp', $this->fixtureDir);
        $this->assertNotSame($afterTouch, $afterGrow, 'Stempel muss auf eine geänderte Gesamtgröße reagieren');

        // Zusätzliche Datei mit 0 Bytes und alter mtime: weder max(mtime) noch
        // Gesamtgröße ändern sich - die Dateianzahl-Komponente muss es fangen.
        touch($this->fixtureDir . '/empty.txt', $mtime - 3600);
        clearstatcache();
        $afterAdd = $this->invoke($manager, 'computeDirStamp', $this->fixtureDir);
        $this->assertNotSame($afterGrow, $afterAdd, 'Stempel muss auf eine geänderte Dateianzahl reagieren');
    }

    // ---- fingerprintOf()/dirStampOf(): lazy + memoisiert ----------------

    public function testFingerprintOfIsMemoizedPerRequest(): void {
        $manager = $this->manager();
        $this->seedDiscovered($manager, 'stamp-fixture');

        $first = $this->invoke($manager, 'fingerprintOf', 'stamp-fixture');
        $this->assertSame(
            $this->invoke($manager, 'computeFingerprint', $this->fixtureDir),
            $first,
            'fingerprintOf() muss den echten SHA-256-Fingerabdruck des Verzeichnisses liefern'
        );

        // Nach einer Datei-Änderung bleibt der memoisierte Wert stehen - der
        // Fingerabdruck gilt pro Request als stabil (Beleg der Lazy-Memoisierung:
        // es wird nicht erneut gerechnet).
        file_put_contents($this->fixtureDir . '/sub/data.txt', 'GEAENDERT');
        $this->assertSame($first, $this->invoke($manager, 'fingerprintOf', 'stamp-fixture'));
    }

    public function testDirStampOfIsMemoizedPerRequest(): void {
        $manager = $this->manager();
        $this->seedDiscovered($manager, 'stamp-fixture');

        $first = $this->invoke($manager, 'dirStampOf', 'stamp-fixture');
        $this->assertNotNull($first);

        touch($this->fixtureDir . '/sub/data.txt', time() + 3600);
        clearstatcache();
        $this->assertSame($first, $this->invoke($manager, 'dirStampOf', 'stamp-fixture'));
    }

    public function testFingerprintAndStampStayNullForInvalidManifests(): void {
        $manager = $this->manager();
        $this->seedDiscovered($manager, 'stamp-fixture', 'plugin.json ist kein gültiges JSON-Objekt.');

        // Verhalten identisch zur früheren eager Berechnung: Plugins mit
        // Manifest-Fehler bekommen nie einen Fingerabdruck/Stempel - sie werden
        // ohnehin nie geladen, und setEnabled() speichert für sie NULL.
        $this->assertNull($this->invoke($manager, 'fingerprintOf', 'stamp-fixture'));
        $this->assertNull($this->invoke($manager, 'dirStampOf', 'stamp-fixture'));
    }

    public function testFingerprintOfUnknownSlugIsNull(): void {
        $manager = $this->manager();
        $this->seedDiscovered($manager, 'stamp-fixture');

        $this->assertNull($this->invoke($manager, 'fingerprintOf', 'gibt-es-nicht'));
        $this->assertNull($this->invoke($manager, 'dirStampOf', 'gibt-es-nicht'));
    }

    // ---- isReleaseTagSource() (#212) ------------------------------------

    public function testReleaseTagSourcesAreRecognized(): void {
        $this->assertTrue(PluginManager::isReleaseTagSource('Celestial0579/Hengstverzeichnis_Addons@v0.4.1'));
        $this->assertTrue(PluginManager::isReleaseTagSource('owner/repo@v1.2.3'));
        $this->assertTrue(PluginManager::isReleaseTagSource('o.w-n_er/re.po-1@v10.20.30'));
    }

    public function testNonReleaseSourcesAreRejected(): void {
        // Manuell kopiert (Store hat nie geschrieben) bzw. leere Herkunft.
        $this->assertFalse(PluginManager::isReleaseTagSource(null));
        $this->assertFalse(PluginManager::isReleaseTagSource(''));
        // Branch-Stände sind mutabel - genau der Angriffsweg aus #212.
        $this->assertFalse(PluginManager::isReleaseTagSource('owner/repo@main'));
        $this->assertFalse(PluginManager::isReleaseTagSource('owner/repo'));
        // Unvollständige oder verkleidete Tags.
        $this->assertFalse(PluginManager::isReleaseTagSource('owner/repo@v1.2'));
        $this->assertFalse(PluginManager::isReleaseTagSource('owner/repo@1.2.3'));
        $this->assertFalse(PluginManager::isReleaseTagSource('owner/repo@v1.2.3-rc.1'));
        $this->assertFalse(PluginManager::isReleaseTagSource('owner/repo@v1.2.3/extra'));
        $this->assertFalse(PluginManager::isReleaseTagSource('@v1.2.3'));
    }
}
