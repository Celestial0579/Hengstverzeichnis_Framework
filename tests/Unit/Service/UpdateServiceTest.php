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

    // ---- Reichweite der unbeaufsichtigten Installation (#290) -----------

    /**
     * Standard 'patch_only': Innerhalb der Minor-Linie sagt das
     * Versionsschema Kompatibilität zu - dort darf ohne Aufsicht
     * aktualisiert werden.
     */
    public function testAutoInstallAllowsPatchWithinSameLine(): void {
        $this->assertTrue(UpdateService::isEligibleForAutoInstall('0.5.2', '0.5.3', 'patch_only'));
        $this->assertTrue(UpdateService::isEligibleForAutoInstall('0.5.2', '0.5.10', 'patch_only'));
    }

    /**
     * Ein Minor-Sprung kann laut CHANGELOG-Konvention Breaking Changes
     * enthalten (solange 0.y.z) - der bleibt dem bewussten Klick vorbehalten.
     */
    public function testAutoInstallRefusesMinorAndMajorJumpsInPatchOnlyScope(): void {
        $this->assertFalse(UpdateService::isEligibleForAutoInstall('0.5.2', '0.6.0', 'patch_only'));
        $this->assertFalse(UpdateService::isEligibleForAutoInstall('0.5.2', '1.0.0', 'patch_only'));
    }

    public function testAutoInstallAllowsAnyNewerVersionInAnyScope(): void {
        $this->assertTrue(UpdateService::isEligibleForAutoInstall('0.5.2', '0.6.0', 'any'));
        $this->assertTrue(UpdateService::isEligibleForAutoInstall('0.5.2', '1.0.0', 'any'));
    }

    /**
     * Downgrade-Sperre gilt auch hier - selbst mit 'any' darf nie eine
     * ältere oder gleiche Version eingespielt werden.
     */
    public function testAutoInstallNeverDowngrades(): void {
        $this->assertFalse(UpdateService::isEligibleForAutoInstall('0.5.2', '0.5.1', 'any'));
        $this->assertFalse(UpdateService::isEligibleForAutoInstall('0.5.2', '0.5.2', 'any'));
        $this->assertFalse(UpdateService::isEligibleForAutoInstall('0.5.2', '0.4.9', 'patch_only'));
    }

    /** Ein unbekannter Wert darf nie die weitere Reichweite bedeuten. */
    public function testUnknownScopeFallsBackToPatchOnly(): void {
        $this->assertSame('patch_only', UpdateService::normalizeAutoScope('unsinn'));
        $this->assertFalse(UpdateService::isEligibleForAutoInstall('0.5.2', '0.6.0', 'unsinn'));
    }

    /** Das führende "v" der Release-Tags darf den Linienvergleich nicht stören. */
    public function testAutoInstallNormalizesVersionPrefix(): void {
        $this->assertTrue(UpdateService::isEligibleForAutoInstall('0.5.2', 'v0.5.3', 'patch_only'));
    }

    // ---- Nur neue Funde melden (#290) ----------------------------------

    public function testCoreFindingIsNewOnlyOnce(): void {
        $first = UpdateService::computeNewFindings(null, '0.6.0', [], []);
        $this->assertTrue($first['coreIsNew']);

        $second = UpdateService::computeNewFindings('0.6.0', '0.6.0', [], []);
        $this->assertFalse($second['coreIsNew'], 'Dieselbe Version darf nicht erneut gemeldet werden');

        $third = UpdateService::computeNewFindings('0.6.0', '0.6.1', [], []);
        $this->assertTrue($third['coreIsNew'], 'Eine neuere Version ist wieder ein neuer Fund');
    }

    public function testNoCoreUpdateMeansNoFinding(): void {
        $findings = UpdateService::computeNewFindings('0.6.0', null, [], []);
        $this->assertFalse($findings['coreIsNew']);
    }

    public function testAddonFindingIsNewOnlyOnce(): void {
        $current = [['slug' => 'deckanfrage', 'version' => '1.2.0']];

        $first = UpdateService::computeNewFindings(null, null, [], $current);
        $this->assertSame([['slug' => 'deckanfrage', 'version' => '1.2.0']], $first['newAddons']);
        $this->assertSame(['deckanfrage' => '1.2.0'], $first['nextNotifiedAddons']);

        $second = UpdateService::computeNewFindings(null, null, $first['nextNotifiedAddons'], $current);
        $this->assertSame([], $second['newAddons']);
    }

    /**
     * Erscheint für dasselbe Addon eine neuere Version, ist das wieder ein
     * neuer Fund - sonst bliebe der zweite Sprung unbemerkt.
     */
    public function testNewerAddonVersionCountsAsNewFindingAgain(): void {
        $findings = UpdateService::computeNewFindings(
            null,
            null,
            ['deckanfrage' => '1.2.0'],
            [['slug' => 'deckanfrage', 'version' => '1.3.0']]
        );

        $this->assertSame([['slug' => 'deckanfrage', 'version' => '1.3.0']], $findings['newAddons']);
    }

    /**
     * Ein erledigtes (oder deinstalliertes) Addon fällt aus dem Merkzettel,
     * weil der Stand vollständig neu aufgebaut wird. Sonst wüchse er
     * unbegrenzt, und ein später erneut auftauchendes Update derselben
     * Version würde fälschlich als "schon gemeldet" verschluckt.
     */
    public function testResolvedAddonDropsOutOfRememberedState(): void {
        $findings = UpdateService::computeNewFindings(
            null,
            null,
            ['deckanfrage' => '1.2.0', 'altes-addon' => '0.9.0'],
            [['slug' => 'deckanfrage', 'version' => '1.2.0']]
        );

        $this->assertSame(['deckanfrage' => '1.2.0'], $findings['nextNotifiedAddons']);
        $this->assertSame([], $findings['newAddons']);
    }

    /** Kern und Addons werden unabhängig voneinander bewertet. */
    public function testCoreAndAddonFindingsAreIndependent(): void {
        $findings = UpdateService::computeNewFindings(
            '0.6.0',
            '0.6.0',
            [],
            [['slug' => 'deckanfrage', 'version' => '1.2.0']]
        );

        $this->assertFalse($findings['coreIsNew']);
        $this->assertCount(1, $findings['newAddons']);
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
     * Ein Release darf ein neues Verzeichnis IN einem neuen Verzeichnis
     * mitbringen (#365). Die Vorabprüfung sah das früher als "nicht anlegbar"
     * an, weil is_writable() für einen noch nicht existierenden Elternordner
     * false liefert - das Update brach ab, bevor eine einzige Datei angefasst
     * wurde. copyTree() legt den Baum per mkdir(recursive) selbst an.
     *
     * Der Fall ist real: storage/logs/.gitkeep kam in ec659dd dazu, und das
     * Release-Archiv schließt storage/ nicht aus - eine Installation aus
     * v0.5.0 oder älter hat das Verzeichnis nicht.
     */
    public function testApplyUpdateArchiveCreatesNestedNewDirectories(): void {
        $target = $this->makeTempDir('hengst_target_');

        $zipPath = $this->makeZip([
            'hengstverzeichnis-framework-9.9.9/index.php' => 'NEUE VERSION',
            'hengstverzeichnis-framework-9.9.9/storage/logs/.gitkeep' => '',
            'hengstverzeichnis-framework-9.9.9/storage/tief/tiefer/datei.txt' => 'X',
        ]);

        try {
            $copied = UpdateService::applyUpdateArchive($zipPath, $target);
        } finally {
            @unlink($zipPath);
        }

        $this->assertSame(3, $copied);
        $this->assertDirectoryExists($target . '/storage/logs');
        $this->assertFileExists($target . '/storage/logs/.gitkeep');
        $this->assertSame('X', file_get_contents($target . '/storage/tief/tiefer/datei.txt'));
    }

    /**
     * Bricht das Kopieren mitten im Baum ab, muss die Installation auf dem
     * Stand VOR dem Update stehen - vorher blieb ein Mischstand aus zwei
     * Versionen zurück, und genau der wird als Nächstes ausgeführt.
     *
     * Der Abbruch wird über eine Kollision erzwungen: Im Ziel liegt unter dem
     * Namen, den das Archiv für eine Datei verwendet, ein Verzeichnis - dort
     * scheitert jedes copy(). Bewusst NICHT über entzogene Schreibrechte:
     * Läuft die Suite als root (Container, CI), überfährt der Prozess
     * chmod 0444 einfach, und der Test prüfte nichts.
     *
     * Zwei Mechanismen greifen hier nacheinander, und der Test prüft das
     * Ergebnis, nicht welcher von beiden es war: die Vorabprüfung, die den
     * Konflikt findet, bevor die erste Datei angefasst wird, und das
     * Rückrollen aus dem Journal für alles, was sie nicht vorhersehen kann
     * (volle Platte, entzogene Rechte mitten im Lauf).
     */
    public function testApplyUpdateArchiveRollsBackWhenItCannotFinish(): void {
        $target = $this->makeTempDir('hengst_target_');
        mkdir($target . '/src/Kollision.php', 0755, true);
        file_put_contents($target . '/index.php', 'ALTE VERSION');
        file_put_contents($target . '/src/Bestand.php', 'ALTE DATEI');

        $zipPath = $this->makeZip([
            'hengstverzeichnis-framework-9.9.9/index.php' => 'NEUE VERSION',
            'hengstverzeichnis-framework-9.9.9/src/Bestand.php' => 'NEUE DATEI',
            'hengstverzeichnis-framework-9.9.9/src/Kollision.php' => 'GEHT NICHT',
        ]);

        $thrown = null;
        try {
            UpdateService::applyUpdateArchive($zipPath, $target);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        } finally {
            @unlink($zipPath);
        }

        $this->assertNotNull($thrown, 'Das Update muss mit einer Ausnahme abbrechen');

        // Die Installation steht auf dem Stand von vorher - nichts
        // halb Aktualisiertes, nichts Neues liegengeblieben.
        $this->assertSame('ALTE VERSION', file_get_contents($target . '/index.php'));
        $this->assertSame('ALTE DATEI', file_get_contents($target . '/src/Bestand.php'));
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
