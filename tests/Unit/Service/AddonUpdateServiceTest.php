<?php
// tests/Unit/Service/AddonUpdateServiceTest.php

namespace Tests\Unit\Service;

use App\Service\AddonUpdateService;
use App\Service\GithubAddonRepository;
use PHPUnit\Framework\TestCase;

/**
 * Netzwerkfreie Unit-Tests für das Addon-Autoupdate (#197, Stufe 2):
 * Release-Tag-Auswahl je Kern-Linie und die Kernlogik
 * updateAddonFromTarball() gegen lokal gebaute Tarballs - Muster wie
 * GithubAddonRepositoryTest (dort steht auch der Tar-Builder, hier bewusst
 * über PharData-freie Hilfsfunktionen dupliziert klein gehalten).
 */
class AddonUpdateServiceTest extends TestCase {

    /** @var array<int, string> */
    private array $cleanupDirs = [];

    protected function tearDown(): void {
        foreach ($this->cleanupDirs as $dir) {
            $this->removeTree($dir);
        }
        $this->cleanupDirs = [];
    }

    // ---- coreLine ------------------------------------------------------

    public function testCoreLineExtraction(): void {
        $this->assertSame('0.4', AddonUpdateService::coreLine('0.4.2'));
        $this->assertSame('0.3', AddonUpdateService::coreLine('v0.3.0'));
        $this->assertSame('1.12', AddonUpdateService::coreLine('1.12.9'));
        $this->assertNull(AddonUpdateService::coreLine('0.4'));
        $this->assertNull(AddonUpdateService::coreLine('unsinn'));
        $this->assertNull(AddonUpdateService::coreLine(''));
    }

    // ---- Release-Tag-Auswahl ------------------------------------------

    public function testSelectBestReleaseTagPicksHighestPatchOfMatchingLine(): void {
        $releases = [
            ['tag_name' => 'v0.4.0', 'draft' => false, 'prerelease' => false],
            ['tag_name' => 'v0.4.2', 'draft' => false, 'prerelease' => false],
            ['tag_name' => 'v0.4.1', 'draft' => false, 'prerelease' => false],
            ['tag_name' => 'v0.5.0', 'draft' => false, 'prerelease' => false],
            ['tag_name' => 'v0.3.7', 'draft' => false, 'prerelease' => false],
        ];
        $this->assertSame('v0.4.2', GithubAddonRepository::selectBestReleaseTagForCoreLine($releases, '0.4'));
        $this->assertSame('v0.5.0', GithubAddonRepository::selectBestReleaseTagForCoreLine($releases, '0.5'));
        $this->assertNull(GithubAddonRepository::selectBestReleaseTagForCoreLine($releases, '0.6'));
    }

    public function testSelectBestReleaseTagSkipsDraftsPrereleasesAndForeignTags(): void {
        $releases = [
            ['tag_name' => 'v0.4.9', 'draft' => true, 'prerelease' => false],
            ['tag_name' => 'v0.4.8', 'draft' => false, 'prerelease' => true],
            ['tag_name' => 'release-0.4', 'draft' => false, 'prerelease' => false],
            ['tag_name' => 'v0.4.0-beta.1', 'draft' => false, 'prerelease' => false],
            ['tag_name' => '0.4.3', 'draft' => false, 'prerelease' => false], // ohne v: gültig
            'kein-array',
        ];
        $this->assertSame('0.4.3', GithubAddonRepository::selectBestReleaseTagForCoreLine($releases, '0.4'));
        // Ungültige Linie / leere Liste: nichts wählen (Aufrufer fällt auf
        // den Branch-Stand zurück).
        $this->assertNull(GithubAddonRepository::selectBestReleaseTagForCoreLine($releases, '0.4.0'));
        $this->assertNull(GithubAddonRepository::selectBestReleaseTagForCoreLine([], '0.4'));
    }

    // ---- updateAddonFromTarball ---------------------------------------

    public function testUpdateAddonFromTarballReplacesInstalledVersion(): void {
        $pluginsDir = $this->makeWorkDir();
        $this->writeInstalledPlugin($pluginsDir, 'demo-addon', '1.0.0');
        $tarPath = $this->buildAddonTarball('demo-addon', '1.1.0', '>=0.3.0', '9.9');

        $result = AddonUpdateService::updateAddonFromTarball('demo-addon', $tarPath, $pluginsDir, '0.3.0');

        $this->assertTrue($result['ok'], (string)$result['error']);
        $this->assertSame('1.0.0', $result['from']);
        $this->assertSame('1.1.0', $result['to']);
        $manifest = json_decode((string)file_get_contents($pluginsDir . '/demo-addon/plugin.json'), true);
        $this->assertSame('1.1.0', $manifest['version']);
    }

    public function testUpdateAddonFromTarballRefusesIncompatibleNewState(): void {
        $pluginsDir = $this->makeWorkDir();
        $this->writeInstalledPlugin($pluginsDir, 'demo-addon', '1.0.0');
        // Neuer Stand unterstützt höchstens Kern 0.3 - gegen 0.4.0 muss das
        // Update verweigert werden, statt ein funktionierendes Addon durch
        // ein inkompatibles zu ersetzen.
        $tarPath = $this->buildAddonTarball('demo-addon', '1.1.0', '>=0.3.0', '0.3');

        $result = AddonUpdateService::updateAddonFromTarball('demo-addon', $tarPath, $pluginsDir, '0.4.0');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('verweigert', (string)$result['error']);
        $manifest = json_decode((string)file_get_contents($pluginsDir . '/demo-addon/plugin.json'), true);
        $this->assertSame('1.0.0', $manifest['version'], 'Installierter Stand muss unangetastet bleiben');
    }

    public function testUpdateAddonFromTarballFailsWhenAddonMissingFromTarball(): void {
        $pluginsDir = $this->makeWorkDir();
        $this->writeInstalledPlugin($pluginsDir, 'verschwunden', '1.0.0');
        $tarPath = $this->buildAddonTarball('anderes-addon', '1.0.0', '>=0.3.0', '9.9');

        $result = AddonUpdateService::updateAddonFromTarball('verschwunden', $tarPath, $pluginsDir, '0.3.0');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('nicht (mehr) enthalten', (string)$result['error']);
    }

    // ---- Helfer --------------------------------------------------------

    private function makeWorkDir(): string {
        $dir = sys_get_temp_dir() . '/hengst_addonupdate_test_' . bin2hex(random_bytes(6));
        mkdir($dir, 0755, true);
        $this->cleanupDirs[] = $dir;
        return $dir;
    }

    private function writeInstalledPlugin(string $pluginsDir, string $slug, string $version): void {
        mkdir($pluginsDir . '/' . $slug, 0755, true);
        file_put_contents($pluginsDir . '/' . $slug . '/plugin.json', json_encode([
            'slug' => $slug,
            'name' => 'Installiert',
            'version' => $version,
            'core_compatibility' => '>=0.1.0-beta.1',
            'core_supported_max' => '9.9',
        ]));
        file_put_contents($pluginsDir . '/' . $slug . '/Plugin.php', "<?php\nclass Plugin {}\n");
    }

    private function buildAddonTarball(string $slug, string $version, string $compat, string $max): string {
        $work = $this->makeWorkDir();
        $stage = $work . '/stage/testrepo-main/plugins/' . $slug;
        mkdir($stage, 0755, true);
        file_put_contents($stage . '/plugin.json', json_encode([
            'slug' => $slug,
            'name' => 'Katalog-Stand',
            'version' => $version,
            'core_compatibility' => $compat,
            'core_supported_max' => $max,
        ]));
        file_put_contents($stage . '/Plugin.php', "<?php\nclass Plugin {}\n");

        $tarFile = $work . '/archive.tar';
        $phar = new \PharData($tarFile);
        $phar->buildFromDirectory($work . '/stage');
        $phar->compress(\Phar::GZ);
        unset($phar);
        \PharData::unlinkArchive($tarFile);

        return $tarFile . '.gz';
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
