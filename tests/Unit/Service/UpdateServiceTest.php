<?php
// tests/Unit/Service/UpdateServiceTest.php

namespace Tests\Unit\Service;

use App\Service\UpdateService;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests für App\Service\UpdateService (#85): Versionsvergleich sowie
 * das netzwerkfreie Anwenden eines Release-Zips (inkl. Schutz lokaler
 * Konfigurations-/Datenpfade und des git-archive-Präfix-Layouts).
 */
class UpdateServiceTest extends TestCase {

    private array $cleanupDirs = [];

    protected function tearDown(): void {
        foreach ($this->cleanupDirs as $dir) {
            $this->removeTree($dir);
        }
        $this->cleanupDirs = [];
    }

    public function testIsNewerComparesNormalizedVersions(): void {
        $this->assertTrue(UpdateService::isNewer('v0.2.0', '0.1.0-beta.1'));
        $this->assertTrue(UpdateService::isNewer('0.1.0', '0.1.0-beta.1'));
        $this->assertFalse(UpdateService::isNewer('0.1.0-beta.1', '0.1.0-beta.1'));
        $this->assertFalse(UpdateService::isNewer('v0.1.0-beta.1', '0.2.0'));
        $this->assertTrue(UpdateService::isNewer('1.0.0', '0.9.9'));
    }

    public function testNormalizeChannelFallsBackToStable(): void {
        $this->assertSame('stable', UpdateService::normalizeChannel('stable'));
        $this->assertSame('beta', UpdateService::normalizeChannel('beta'));
        $this->assertSame('stable', UpdateService::normalizeChannel(''));
        $this->assertSame('stable', UpdateService::normalizeChannel('nightly'));
    }

    public function testSelectBestReleaseSkipsPrereleasesOnStableChannel(): void {
        $releases = [
            ['tag_name' => 'v0.3.0-beta.1', 'prerelease' => true],
            ['tag_name' => 'v0.2.0', 'prerelease' => false],
        ];

        $best = UpdateService::selectBestRelease($releases, false, '0.1.0');
        $this->assertSame('v0.2.0', $best['tag_name']);
    }

    public function testSelectBestReleaseIncludesPrereleasesWithBetaOptIn(): void {
        $releases = [
            ['tag_name' => 'v0.2.0', 'prerelease' => false],
            ['tag_name' => 'v0.3.0-beta.1', 'prerelease' => true],
        ];

        $best = UpdateService::selectBestRelease($releases, true, '0.1.0');
        $this->assertSame('v0.3.0-beta.1', $best['tag_name']);
    }

    public function testSelectBestReleaseNeverOffersDowngradeOrSameVersion(): void {
        $releases = [
            ['tag_name' => 'v0.2.0', 'prerelease' => false],
            ['tag_name' => 'v0.3.0-beta.2', 'prerelease' => true],
        ];

        // Installierte Beta ist neuer als alles Verfügbare (typischer Fall
        // nach Wechsel von Beta zurück auf Stabil): kein Kandidat, statt
        // Downgrade auf das ältere stabile Release.
        $this->assertNull(UpdateService::selectBestRelease($releases, false, '0.3.0-beta.1'));

        // Gleiche Version ist ebenfalls nie ein Kandidat.
        $this->assertNull(UpdateService::selectBestRelease($releases, true, '0.3.0-beta.2'));

        // Nur mit Beta-Opt-in ist die strikt neuere Beta ein Kandidat.
        $best = UpdateService::selectBestRelease($releases, true, '0.3.0-beta.1');
        $this->assertSame('v0.3.0-beta.2', $best['tag_name']);
    }

    public function testSelectBestReleaseIgnoresDraftsAndInvalidEntries(): void {
        $releases = [
            ['tag_name' => 'v9.9.9', 'draft' => true],
            ['tag_name' => ''],
            'kein-array',
            ['tag_name' => 'v0.2.0'],
        ];

        $best = UpdateService::selectBestRelease($releases, true, '0.1.0');
        $this->assertSame('v0.2.0', $best['tag_name']);
    }

    public function testApplyUpdateArchiveCopiesFilesButProtectsLocalPaths(): void {
        // Zielinstallation mit lokaler Konfiguration und Uploads simulieren.
        $target = $this->makeTempDir('hengst_target_');
        mkdir($target . '/config', 0755, true);
        mkdir($target . '/public/uploads', 0755, true);
        file_put_contents($target . '/config/db_config.php', 'LOKALE KONFIG');
        file_put_contents($target . '/public/uploads/foto.jpg', 'LOKALES FOTO');
        file_put_contents($target . '/index.php', 'ALTE VERSION');

        // Release-Zip im git-archive-Layout (mit Präfix-Verzeichnis) bauen -
        // enthält bösartigerweise auch Dateien in geschützten Pfaden, die ein
        // Update nie anfassen darf. Erzeugt über PharData, da die Testumgebung
        // (wie manche Minimal-Hosting-Umgebungen) keine "zip"-Erweiterung hat -
        // genau der Fall, den der PharData-Fallback des UpdateService abdeckt.
        $zipPath = $this->makeZip([
            'hengstverzeichnis-framework-9.9.9/index.php' => 'NEUE VERSION',
            'hengstverzeichnis-framework-9.9.9/src/Neu.php' => '<?php // neu',
            'hengstverzeichnis-framework-9.9.9/config/db_config.php' => 'ANGRIFF',
            'hengstverzeichnis-framework-9.9.9/public/uploads/boese.php' => 'ANGRIFF',
            'hengstverzeichnis-framework-9.9.9/.env' => 'ANGRIFF',
        ]);

        try {
            $copied = UpdateService::applyUpdateArchive($zipPath, $target);
        } finally {
            @unlink($zipPath);
        }

        // Reguläre Dateien wurden aktualisiert/ergänzt ...
        $this->assertSame('NEUE VERSION', file_get_contents($target . '/index.php'));
        $this->assertSame('<?php // neu', file_get_contents($target . '/src/Neu.php'));
        $this->assertSame(2, $copied);

        // ... geschützte Pfade blieben unangetastet.
        $this->assertSame('LOKALE KONFIG', file_get_contents($target . '/config/db_config.php'));
        $this->assertSame('LOKALES FOTO', file_get_contents($target . '/public/uploads/foto.jpg'));
        $this->assertFileDoesNotExist($target . '/public/uploads/boese.php');
        $this->assertFileDoesNotExist($target . '/.env');
    }

    public function testApplyUpdateArchiveWithoutPrefixDirectory(): void {
        $target = $this->makeTempDir('hengst_target_');

        $zipPath = $this->makeZip([
            'a.txt' => 'A',
            'sub/b.txt' => 'B',
        ]);

        try {
            $copied = UpdateService::applyUpdateArchive($zipPath, $target);
        } finally {
            @unlink($zipPath);
        }

        $this->assertSame(2, $copied);
        $this->assertSame('A', file_get_contents($target . '/a.txt'));
        $this->assertSame('B', file_get_contents($target . '/sub/b.txt'));
    }

    /**
     * @param array<string, string> $files Pfad im Archiv => Inhalt
     */
    private function makeZip(array $files): string {
        $zipPath = rtrim(sys_get_temp_dir(), '/') . '/' . uniqid('hengst_zip_') . '.zip';
        $zip = new \PharData($zipPath);
        foreach ($files as $path => $content) {
            $zip->addFromString($path, $content);
        }
        return $zipPath;
    }

    private function makeTempDir(string $prefix): string {
        $dir = rtrim(sys_get_temp_dir(), '/') . '/' . uniqid($prefix);
        mkdir($dir, 0755, true);
        $this->cleanupDirs[] = $dir;
        return $dir;
    }

    private function removeTree(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
