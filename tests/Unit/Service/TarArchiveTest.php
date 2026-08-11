<?php
// tests/Unit/Service/TarArchiveTest.php

namespace Tests\Unit\Service;

use App\Service\TarArchive;
use PHPUnit\Framework\TestCase;

/**
 * Prüft App\Service\TarArchive (#233) als echte Rundreise: mit dem eigenen
 * ustar-Schreiber archivieren und mit dem System-`tar` (GNU tar/bsdtar)
 * auflisten und entpacken - die aussagekräftigste Prüfung für ein
 * selbstgeschriebenes Archivformat, da sie die Kompatibilität mit dem
 * Werkzeug belegt, mit dem ein Betreiber das Backup im Ernstfall tatsächlich
 * entpackt. Ohne `tar`-Binary werden die betroffenen Fälle übersprungen.
 */
class TarArchiveTest extends TestCase {

    private string $workDir;

    protected function setUp(): void {
        $this->workDir = sys_get_temp_dir() . '/tar_archive_test_' . uniqid();
        mkdir($this->workDir);
    }

    protected function tearDown(): void {
        $this->removeTree($this->workDir);
    }

    private function removeTree(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function requireSystemTar(): void {
        exec('command -v tar 2>/dev/null', $output, $exitCode);
        if ($exitCode !== 0) {
            $this->markTestSkipped('Kein tar-Binary im PATH - Kompatibilitätsprüfung nicht möglich.');
        }
    }

    /**
     * @return array<string, string> Beispielbaum Archivname => Inhalt
     */
    private function createSampleTree(string $baseDir): array {
        $files = [
            'uploads/.htaccess' => "Deny from all\n",
            'uploads/branding/logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
            // > 1 Block und kein Vielfaches von 512 - prüft das Block-Padding.
            // Bewusst ASCII-Name: `tar -tf` escaped Nicht-ASCII-Namen je nach
            // Locale (C vs. UTF-8) unterschiedlich, das würde den
            // Listing-Vergleich umgebungsabhängig machen.
            'uploads/horses/hengst-gross.jpg' => random_bytes(2000),
            'uploads/horses/klein.txt' => 'x',
        ];
        foreach ($files as $name => $content) {
            $path = $baseDir . '/' . substr($name, strlen('uploads/'));
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents($path, $content);
        }
        // Leeres Verzeichnis: bekommt bewusst keinen Archiv-Eintrag.
        mkdir($baseDir . '/leer');
        return $files;
    }

    public function testRoundTripWithSystemTarRestoresAllFilesByteIdentically(): void {
        $this->requireSystemTar();

        $sourceDir = $this->workDir . '/quelle';
        mkdir($sourceDir);
        $expected = $this->createSampleTree($sourceDir);

        $archivePath = $this->workDir . '/archiv.tar';
        $archive = TarArchive::create($archivePath);
        $archive->addDirectoryTree($sourceDir, 'uploads');
        $archive->close();

        // Auflisten mit dem System-tar: alle Einträge vorhanden, keine weiteren.
        exec('tar -tf ' . escapeshellarg($archivePath) . ' 2>&1', $listing, $exitCode);
        $this->assertSame(0, $exitCode, 'System-tar konnte das Archiv nicht lesen: ' . implode("\n", $listing));
        sort($listing);
        $expectedNames = array_keys($expected);
        sort($expectedNames);
        $this->assertSame($expectedNames, $listing);

        // Entpacken und byte-identisch vergleichen.
        $extractDir = $this->workDir . '/entpackt';
        mkdir($extractDir);
        exec('tar -xf ' . escapeshellarg($archivePath) . ' -C ' . escapeshellarg($extractDir) . ' 2>&1', $out, $exitCode);
        $this->assertSame(0, $exitCode, 'System-tar konnte das Archiv nicht entpacken: ' . implode("\n", $out));
        foreach ($expected as $name => $content) {
            $this->assertSame($content, file_get_contents($extractDir . '/' . $name), "Inhalt von {$name} weicht ab");
        }
    }

    public function testGzipArchiveIsReadableBySystemTar(): void {
        $this->requireSystemTar();
        if (!function_exists('gzopen')) {
            $this->markTestSkipped('zlib-Extension fehlt.');
        }

        $archivePath = $this->workDir . '/archiv.tar.gz';
        $archive = TarArchive::create($archivePath); // gzip aus der Endung abgeleitet
        $archive->addString('uploads/hinweis.txt', "aus dem Speicher\n");
        $archive->close();

        // gzip-Magic-Bytes: Datei ist wirklich komprimiert, nicht nur so benannt.
        $this->assertSame("\x1f\x8b", substr((string)file_get_contents($archivePath), 0, 2));

        $extractDir = $this->workDir . '/entpackt';
        mkdir($extractDir);
        exec('tar -xzf ' . escapeshellarg($archivePath) . ' -C ' . escapeshellarg($extractDir) . ' 2>&1', $out, $exitCode);
        $this->assertSame(0, $exitCode, 'System-tar konnte das gzip-Archiv nicht entpacken: ' . implode("\n", $out));
        $this->assertSame("aus dem Speicher\n", file_get_contents($extractDir . '/uploads/hinweis.txt'));
    }

    public function testLongPathsUseUstarPrefixAndSurviveRoundTrip(): void {
        $this->requireSystemTar();

        // > 100 Zeichen Gesamtpfad, aber ustar-tauglich (name <= 100, prefix <= 155).
        $longDir = 'uploads/' . str_repeat('galerie-verzeichnis-', 5); // 108 Zeichen prefix
        $name = $longDir . '/bild.jpg';
        $this->assertGreaterThan(100, strlen($name));

        $archivePath = $this->workDir . '/archiv.tar';
        $archive = TarArchive::create($archivePath);
        $archive->addString($name, 'inhalt');
        $archive->close();

        $extractDir = $this->workDir . '/entpackt';
        mkdir($extractDir);
        exec('tar -xf ' . escapeshellarg($archivePath) . ' -C ' . escapeshellarg($extractDir) . ' 2>&1', $out, $exitCode);
        $this->assertSame(0, $exitCode, 'System-tar konnte das Archiv nicht entpacken: ' . implode("\n", $out));
        $this->assertSame('inhalt', file_get_contents($extractDir . '/' . $name));
    }

    public function testPathTooLongForUstarThrows(): void {
        $archivePath = $this->workDir . '/archiv.tar';
        $archive = TarArchive::create($archivePath);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Pfad zu lang');
            // Ein einzelnes Pfadsegment > 100 Zeichen lässt sich in ustar
            // nicht ablegen (prefix trennt nur an '/').
            $archive->addString('uploads/' . str_repeat('x', 150), 'inhalt');
        } finally {
            $archive->close();
        }
    }

    public function testSymlinksAreSkipped(): void {
        $this->requireSystemTar();

        $sourceDir = $this->workDir . '/quelle';
        mkdir($sourceDir);
        file_put_contents($sourceDir . '/echt.txt', 'echt');
        if (!@symlink('/etc/passwd', $sourceDir . '/link.txt')) {
            $this->markTestSkipped('Symlinks auf diesem Dateisystem nicht verfügbar.');
        }

        $archivePath = $this->workDir . '/archiv.tar';
        $archive = TarArchive::create($archivePath);
        $archive->addDirectoryTree($sourceDir, 'uploads');
        $archive->close();

        exec('tar -tf ' . escapeshellarg($archivePath) . ' 2>&1', $listing, $exitCode);
        $this->assertSame(0, $exitCode);
        $this->assertSame(['uploads/echt.txt'], $listing);
    }
}
