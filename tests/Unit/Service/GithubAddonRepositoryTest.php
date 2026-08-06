<?php
// tests/Unit/Service/GithubAddonRepositoryTest.php

namespace Tests\Unit\Service;

use App\Service\GithubAddonRepository;
use PHPUnit\Framework\TestCase;

/**
 * Prüft App\Service\GithubAddonRepository rein lokal (kein echter
 * Netzwerkzugriff): parseOwnerRepo() gegen verschiedene Eingabeformate sowie
 * scanTarballFile()/installFromTarballFile() gegen selbst gebaute .tar.gz-
 * Fixtures - inklusive absichtlich bösartiger Archive (Pfad-Traversal via
 * "../"-Eintrag, Symlink-Eintrag), um die in verifyExtractedTreeIsSafe()
 * dokumentierte Sicherheitsgrenze tatsächlich zu verifizieren, statt sie nur
 * zu behaupten. Die Test-Tarballs werden per Low-Level-Tar-Header-Konstruktion
 * gebaut (nicht über PharData::buildFromDirectory()), weil PharData selbst
 * beim SCHREIBEN bereits ".."-Pfade ablehnen würde - ein damit gebautes
 * Archiv könnte den zu prüfenden Angriffsvektor gar nicht erst enthalten.
 */
class GithubAddonRepositoryTest extends TestCase {

    /** @var array<int, string> Im Test angelegte Pfade (Dateien/Verzeichnisse), werden in tearDown() aufgeräumt. */
    private array $cleanupPaths = [];

    protected function tearDown(): void {
        foreach (array_reverse($this->cleanupPaths) as $path) {
            $this->removePath($path);
        }
        $this->cleanupPaths = [];
    }

    private function removePath(string $path): void {
        if (is_dir($path) && !is_link($path)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            @unlink($path);
        }
    }

    // ---- parseOwnerRepo() ----------------------------------------------

    public function testParseOwnerRepoAcceptsFullGithubUrl(): void {
        $result = GithubAddonRepository::parseOwnerRepo('https://github.com/Celestial0579/Hengstverzeichnis_Addons');
        $this->assertSame(['owner' => 'Celestial0579', 'repo' => 'Hengstverzeichnis_Addons'], $result);
    }

    public function testParseOwnerRepoStripsDotGitSuffixAndTrailingSlash(): void {
        $result = GithubAddonRepository::parseOwnerRepo('https://github.com/Celestial0579/Hengstverzeichnis_Addons.git/');
        $this->assertSame(['owner' => 'Celestial0579', 'repo' => 'Hengstverzeichnis_Addons'], $result);
    }

    public function testParseOwnerRepoAcceptsShortForm(): void {
        $result = GithubAddonRepository::parseOwnerRepo('Celestial0579/Hengstverzeichnis_Addons');
        $this->assertSame(['owner' => 'Celestial0579', 'repo' => 'Hengstverzeichnis_Addons'], $result);
    }

    public function testParseOwnerRepoRejectsInputWithoutSlash(): void {
        $this->assertNull(GithubAddonRepository::parseOwnerRepo('not a repo'));
    }

    public function testParseOwnerRepoRejectsExtraPathSegments(): void {
        $this->assertNull(GithubAddonRepository::parseOwnerRepo('owner/repo/extra'));
    }

    public function testParseOwnerRepoRejectsInvalidCharacters(): void {
        $this->assertNull(GithubAddonRepository::parseOwnerRepo('owner/../etc'));
    }

    // ---- scanTarballFile() / installFromTarballFile() ------------------

    public function testScanTarballFileFindsValidMultiPluginManifest(): void {
        $tarPath = $this->buildTarGz([
            $this->tarDirEntry('testrepo-main/'),
            $this->tarDirEntry('testrepo-main/plugins/'),
            $this->tarDirEntry('testrepo-main/plugins/demo-addon/'),
            $this->tarFileEntry('testrepo-main/plugins/demo-addon/plugin.json', json_encode([
                'slug' => 'demo-addon',
                'name' => 'Demo Addon',
                'version' => '1.0.0',
                'core_compatibility' => '>=0.1.0-beta.1',
                'description' => 'Test-Plugin',
            ])),
            $this->tarFileEntry('testrepo-main/plugins/demo-addon/Plugin.php', "<?php\nnamespace Plugin\\DemoAddon;\nclass Plugin {}\n"),
        ]);

        $result = GithubAddonRepository::scanTarballFile($tarPath);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertCount(1, $result['plugins']);
        $this->assertSame('demo-addon', $result['plugins'][0]['slug']);
        $this->assertSame('1.0.0', $result['plugins'][0]['version']);
    }

    public function testScanTarballFileIgnoresPluginWithMismatchedSlug(): void {
        $tarPath = $this->buildTarGz([
            $this->tarDirEntry('testrepo-main/plugins/demo-addon/'),
            $this->tarFileEntry('testrepo-main/plugins/demo-addon/plugin.json', json_encode([
                'slug' => 'different-slug',
                'name' => 'Demo Addon',
                'version' => '1.0.0',
                'core_compatibility' => '>=0.1.0-beta.1',
            ])),
        ]);

        $result = GithubAddonRepository::scanTarballFile($tarPath);

        $this->assertTrue($result['ok']);
        $this->assertCount(0, $result['plugins']);
    }

    /**
     * PharData::extractTo() normalisiert ".."-Segmente und führende "/" in
     * Eintragsnamen bereits selbst auf einen Pfad relativ zum Zielverzeichnis
     * zurück (verifiziert durch manuelle Prüfung mit PharData direkt: ein
     * Eintrag "plugins/../../../../tmp/x" landet z. B. unter "$destDir/tmp/x",
     * nie außerhalb) - ein Klassiker der von PharData bereits vor unserer
     * eigenen verifyExtractedTreeIsSafe()-Prüfung abgefangen wird. Dieser Test
     * verifiziert genau diese Containment-Eigenschaft (kein Escape aus dem
     * temporären Arbeitsverzeichnis), nicht dass der Katalog-Scan den
     * Eintrag ablehnt - ein durch die Traversal-Segmente entstandenes
     * zusätzliches Wurzelverzeichnis kann dazu führen, dass findRepoRoot()
     * die eigentlichen Plugins nicht mehr findet (sicherer, aber
     * unvollständiger Fehlschlag - kein Sicherheitsproblem, da nichts
     * außerhalb landet).
     */
    public function testScanTarballFileNeverExtractsOutsideTempDirOnTraversalAttempt(): void {
        $tarPath = $this->buildTarGz([
            $this->tarDirEntry('testrepo-main/plugins/evil/'),
            $this->tarFileEntry('testrepo-main/plugins/evil/plugin.json', json_encode([
                'slug' => 'evil',
                'name' => 'Evil',
                'version' => '1.0.0',
                'core_compatibility' => '>=0.1.0-beta.1',
            ])),
            // Klassischer Zip-Slip-Versuch: sieht wie ein normaler Unterpfad aus,
            // enthält aber ".."-Segmente in Richtung Dateisystem-Wurzel.
            $this->tarFileEntry('testrepo-main/plugins/../../../../tmp/hengst_addon_test_escape.txt', 'pwned'),
        ]);

        GithubAddonRepository::scanTarballFile($tarPath);

        $this->assertFileDoesNotExist('/tmp/hengst_addon_test_escape.txt');
    }

    /**
     * Testet verifyExtractedTreeIsSafe() (per Reflection, da privat) direkt
     * gegen einen ECHTEN Symlink auf dem Dateisystem, statt sich darauf zu
     * verlassen, dass ein Tar-Symlink-Eintrag von PharData überhaupt als
     * Symlink wiederhergestellt wird (empirisch geprüft: die installierte
     * PharData-Version extrahiert Tar-Symlink-Einträge nicht als echte
     * Dateisystem-Symlinks, sondern ignoriert sie/legt sie als harmlose leere
     * Dateien an - der eigentliche Schutz dieser Klasse soll aber auch dann
     * greifen, wenn eine andere PHP-/Phar-Version das künftig anders
     * handhabt, daher die direkte, von PharData unabhängige Prüfung hier).
     */
    public function testVerifyExtractedTreeIsSafeRejectsRealSymlink(): void {
        $dir = sys_get_temp_dir() . '/hengst_addon_test_symlink_' . bin2hex(random_bytes(8));
        mkdir($dir, 0700, true);
        $this->cleanupPaths[] = $dir;

        $target = sys_get_temp_dir() . '/hengst_addon_test_symlink_target_' . bin2hex(random_bytes(8)) . '.txt';
        file_put_contents($target, 'irrelevant');
        $this->cleanupPaths[] = $target;

        symlink($target, $dir . '/escape-link');

        $method = new \ReflectionMethod(GithubAddonRepository::class, 'verifyExtractedTreeIsSafe');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke(null, $dir));
    }

    public function testInstallFromTarballFileCopiesPluginAndRefusesOverwriteWithoutFlag(): void {
        $tarPath = $this->buildTarGz([
            $this->tarDirEntry('testrepo-main/plugins/demo-addon/'),
            $this->tarFileEntry('testrepo-main/plugins/demo-addon/plugin.json', json_encode([
                'slug' => 'demo-addon',
                'name' => 'Demo Addon',
                'version' => '1.0.0',
                'core_compatibility' => '>=0.1.0-beta.1',
            ])),
            $this->tarFileEntry('testrepo-main/plugins/demo-addon/Plugin.php', "<?php\n// v1\n"),
        ]);

        $pluginsDir = sys_get_temp_dir() . '/hengst_addon_test_plugins_' . bin2hex(random_bytes(8));
        mkdir($pluginsDir, 0755, true);
        $this->cleanupPaths[] = $pluginsDir;

        $result = GithubAddonRepository::installFromTarballFile($tarPath, 'demo-addon', $pluginsDir, false);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame('1.0.0', $result['version']);
        $this->assertFileExists($pluginsDir . '/demo-addon/plugin.json');
        $this->assertFileExists($pluginsDir . '/demo-addon/Plugin.php');
        $this->assertStringContainsString('v1', (string)file_get_contents($pluginsDir . '/demo-addon/Plugin.php'));

        // Ohne overwrite darf ein zweiter Install-Versuch das bestehende Verzeichnis nicht anfassen.
        $secondResult = GithubAddonRepository::installFromTarballFile($tarPath, 'demo-addon', $pluginsDir, false);
        $this->assertFalse($secondResult['ok']);
        $this->assertSame('already_installed', $secondResult['error']);
    }

    public function testInstallFromTarballFileOverwritesWhenRequested(): void {
        $tarV1 = $this->buildTarGz([
            $this->tarDirEntry('testrepo-main/plugins/demo-addon/'),
            $this->tarFileEntry('testrepo-main/plugins/demo-addon/plugin.json', json_encode([
                'slug' => 'demo-addon', 'name' => 'Demo Addon', 'version' => '1.0.0', 'core_compatibility' => '>=0.1.0-beta.1',
            ])),
            $this->tarFileEntry('testrepo-main/plugins/demo-addon/Plugin.php', "<?php\n// v1\n"),
        ]);
        $tarV2 = $this->buildTarGz([
            $this->tarDirEntry('testrepo-main/plugins/demo-addon/'),
            $this->tarFileEntry('testrepo-main/plugins/demo-addon/plugin.json', json_encode([
                'slug' => 'demo-addon', 'name' => 'Demo Addon', 'version' => '2.0.0', 'core_compatibility' => '>=0.1.0-beta.1',
            ])),
            $this->tarFileEntry('testrepo-main/plugins/demo-addon/Plugin.php', "<?php\n// v2\n"),
        ]);

        $pluginsDir = sys_get_temp_dir() . '/hengst_addon_test_plugins_' . bin2hex(random_bytes(8));
        mkdir($pluginsDir, 0755, true);
        $this->cleanupPaths[] = $pluginsDir;

        $first = GithubAddonRepository::installFromTarballFile($tarV1, 'demo-addon', $pluginsDir, false);
        $this->assertTrue($first['ok'], $first['error'] ?? '');

        $second = GithubAddonRepository::installFromTarballFile($tarV2, 'demo-addon', $pluginsDir, true);
        $this->assertTrue($second['ok'], $second['error'] ?? '');
        $this->assertSame('2.0.0', $second['version']);
        $this->assertStringContainsString('v2', (string)file_get_contents($pluginsDir . '/demo-addon/Plugin.php'));
    }

    // ---- Test-Tarball-Konstruktion (bewusst ohne PharData, siehe Klassen-PHPDoc oben) ----

    /**
     * @param array<int, array{name: string, content: string, typeflag: string, linkname: string}> $entries
     */
    private function buildTarGz(array $entries): string {
        $tar = '';
        foreach ($entries as $entry) {
            $content = $entry['content'];
            $size = $entry['typeflag'] === '5' || $entry['typeflag'] === '2' ? 0 : strlen($content);
            $tar .= $this->buildTarHeader($entry['name'], $entry['typeflag'], $size, $entry['linkname']);
            if ($size > 0) {
                $tar .= $content;
                $paddingLength = 512 - ($size % 512);
                if ($paddingLength < 512) {
                    $tar .= str_repeat("\0", $paddingLength);
                }
            }
        }
        $tar .= str_repeat("\0", 1024); // zwei Null-Blöcke markieren das Archivende

        $tarPath = tempnam(sys_get_temp_dir(), 'hengst_addon_test_') . '.tar.gz';
        $this->cleanupPaths[] = $tarPath;

        $gz = gzopen($tarPath, 'wb9');
        gzwrite($gz, $tar);
        gzclose($gz);

        return $tarPath;
    }

    /** @return array{name: string, content: string, typeflag: string, linkname: string} */
    private function tarFileEntry(string $name, string $content): array {
        return ['name' => $name, 'content' => $content, 'typeflag' => '0', 'linkname' => ''];
    }

    /** @return array{name: string, content: string, typeflag: string, linkname: string} */
    private function tarDirEntry(string $name): array {
        return ['name' => rtrim($name, '/') . '/', 'content' => '', 'typeflag' => '5', 'linkname' => ''];
    }

    /** @return array{name: string, content: string, typeflag: string, linkname: string} */
    private function tarSymlinkEntry(string $name, string $linkname): array {
        return ['name' => $name, 'content' => '', 'typeflag' => '2', 'linkname' => $linkname];
    }

    private function buildTarHeader(string $name, string $typeflag, int $size, string $linkname): string {
        $header = str_pad(substr($name, 0, 100), 100, "\0");
        $header .= $this->octalField(0644, 8);
        $header .= $this->octalField(0, 8);
        $header .= $this->octalField(0, 8);
        $header .= $this->octalField($size, 12);
        $header .= $this->octalField(time(), 12);
        $header .= str_repeat(' ', 8); // chksum, wird unten nachträglich berechnet
        $header .= $typeflag;
        $header .= str_pad(substr($linkname, 0, 100), 100, "\0");
        $header .= "ustar\0";
        $header .= "00";
        $header .= str_repeat("\0", 32); // uname
        $header .= str_repeat("\0", 32); // gname
        $header .= $this->octalField(0, 8); // devmajor
        $header .= $this->octalField(0, 8); // devminor
        $header .= str_repeat("\0", 155); // prefix
        $header = str_pad($header, 512, "\0");

        $sum = 0;
        for ($i = 0; $i < 512; $i++) {
            $sum += ord($header[$i]);
        }
        $chksum = str_pad(decoct($sum), 6, '0', STR_PAD_LEFT) . "\0 ";

        return substr($header, 0, 148) . $chksum . substr($header, 156);
    }

    private function octalField(int $value, int $width): string {
        return str_pad(decoct($value), $width - 1, '0', STR_PAD_LEFT) . "\0";
    }
}
