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
        // Ungültige Linie / leere Liste: nichts wählen (automatische Updates
        // verweigern dann, siehe resolveAutoUpdateRef; nur der manuelle
        // Store-Install darf auf den Branch-Stand zurückfallen, #212).
        $this->assertNull(GithubAddonRepository::selectBestReleaseTagForCoreLine($releases, '0.4.0'));
        $this->assertNull(GithubAddonRepository::selectBestReleaseTagForCoreLine([], '0.4'));
    }

    // ---- resolveAutoUpdateRef ------------------------------------------

    /**
     * Kern von #212: Ohne Release-Tag zur Kern-Linie darf ein AUTOMATISCHES
     * Update keinen Bezugspunkt liefern - früher fiel der Code hier auf den
     * konfigurierten Ref/Branch-HEAD zurück (für das offizielle Repo per
     * Seed NULL, also auf den veränderlichen Default-Branch) und spielte
     * damit ungeprüften Code ein. Die sprechende Meldung muss den Grund
     * nennen ("kein Addon-Release"), damit der Admin sie von einem
     * Netz-/Downloadfehler unterscheiden kann.
     */
    public function testResolveAutoUpdateRefRefusesWithoutReleaseTag(): void {
        $resolved = AddonUpdateService::resolveAutoUpdateRef(null, '0.4');

        $this->assertNull($resolved['ref']);
        $this->assertIsString($resolved['error']);
        $this->assertStringContainsString('kein Addon-Release', $resolved['error']);
        $this->assertStringContainsString('0.4', $resolved['error']);
        $this->assertStringContainsString('nicht zulässig', $resolved['error']);
    }

    public function testResolveAutoUpdateRefUsesReleaseTagWhenPresent(): void {
        $resolved = AddonUpdateService::resolveAutoUpdateRef('v0.4.2', '0.4');

        $this->assertSame('v0.4.2', $resolved['ref']);
        $this->assertNull($resolved['error']);
    }

    // ---- summarizeFailures ---------------------------------------------

    /**
     * Der häufigste Fall (#290): Fehlt der Release-Tag zur Ziel-Linie,
     * scheitern ALLE Addons mit demselben Text. Die Update-Seite soll den
     * Grund einmal nennen, aber alle betroffenen Slugs auflisten.
     */
    public function testSummarizeFailuresDeduplicatesIdenticalReasons(): void {
        $summary = AddonUpdateService::summarizeFailures([
            ['slug' => 'addon-a', 'ok' => false, 'error' => 'Kein Addon-Release zur Linie 0.6.'],
            ['slug' => 'addon-b', 'ok' => false, 'error' => 'Kein Addon-Release zur Linie 0.6.'],
        ]);

        $this->assertSame(['Kein Addon-Release zur Linie 0.6.'], $summary['reasons']);
        $this->assertSame(['addon-a', 'addon-b'], $summary['slugs']);
    }

    /**
     * Unterschiedliche Ursachen dürfen NICHT zu einer verschmelzen - sonst
     * behebt der Betreiber die eine und wundert sich über das bleibende
     * Problem der anderen.
     */
    public function testSummarizeFailuresKeepsDistinctReasonsApart(): void {
        $summary = AddonUpdateService::summarizeFailures([
            ['slug' => 'addon-a', 'ok' => true, 'error' => null],
            ['slug' => 'addon-b', 'ok' => false, 'error' => 'Kein Addon-Release zur Linie 0.6.'],
            ['slug' => 'addon-c', 'ok' => false, 'error' => "Addon 'addon-c' ist im bezogenen Stand nicht (mehr) enthalten."],
        ]);

        $this->assertCount(2, $summary['reasons']);
        $this->assertContains('Kein Addon-Release zur Linie 0.6.', $summary['reasons']);
        $this->assertSame(['addon-b', 'addon-c'], $summary['slugs']);
    }

    public function testSummarizeFailuresIsEmptyWhenEverythingSucceeded(): void {
        $summary = AddonUpdateService::summarizeFailures([
            ['slug' => 'addon-a', 'ok' => true, 'error' => null],
            ['slug' => 'addon-b', 'ok' => true, 'error' => null],
        ]);

        $this->assertSame([], $summary['reasons']);
        $this->assertSame([], $summary['slugs']);
    }

    public function testSummarizeFailuresHandlesEmptyResultList(): void {
        $summary = AddonUpdateService::summarizeFailures([]);

        $this->assertSame([], $summary['reasons']);
        $this->assertSame([], $summary['slugs']);
    }

    /**
     * Ein Fehlschlag ohne Text darf nicht als leerer Aufzählungspunkt in der
     * Oberfläche landen - dann stünde dort ein Spiegelstrich ohne Aussage.
     */
    public function testSummarizeFailuresSubstitutesMissingErrorText(): void {
        $summary = AddonUpdateService::summarizeFailures([
            ['slug' => 'addon-a', 'ok' => false, 'error' => null],
        ]);

        $this->assertSame(['Unbekannter Fehler.'], $summary['reasons']);
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
