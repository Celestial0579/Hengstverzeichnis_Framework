<?php
// tests/Integration/PluginDeinstallationTest.php

namespace Tests\Integration;

use App\Database;
use App\Plugin\PluginManager;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeS3Server;

/**
 * Deinstallation eines Addons gegen eine echte Test-Datenbank und echte
 * Verzeichnisse unter plugins/ (Audit N15, N62).
 *
 * - uninstallHookPruefung(): Wann darf bei der Deinstallation Code des Addons
 *   laufen? Nur, wenn er der freigegebene ist - bei aktivierten Addons über
 *   die Ladeprüfung im selben Request, bei deaktivierten über den exakt
 *   gleichen SHA-256. Eine erhöhte Version mit Release-Herkunft genügt bei
 *   deaktivierten Addons ausdrücklich NICHT (Umgehungsversuch).
 * - setEnabled(false) lässt die Freigabe-Baseline stehen.
 * - Deinstallieren entfernt plugins/<slug> symlinksicher und die plugins-Zeile.
 *
 * Jeder Test benutzt einen eigenen Slug: Die Plugin-Klasse wird per
 * require_once geladen und lässt sich in einem Prozess nur einmal deklarieren.
 */
class PluginDeinstallationTest extends TestCase {

    private const PRAEFIX = 'phpunit-deinst-';

    private static PDO $db;

    /** @var string[] angelegte Pfade (Addons, Symlinks, Temp-Ziele) */
    private array $aufraeumen = [];

    /** @var string[] angelegte Slugs */
    private array $slugs = [];

    /** @var string[] angelegte Tabellen */
    private array $zusatzTabellen = [];

    public static function setUpBeforeClass(): void {
        if (!defined('DB_HOST')) {
            self::markTestSkipped('Keine Test-Datenbank konfiguriert (DB_HOST fehlt) - siehe tests/bootstrap.php.');
        }
        if (!defined('CORE_VERSION')) {
            define('CORE_VERSION', '0.4.0');
        }
        $property = new \ReflectionProperty(Database::class, 'instance');
        $property->setValue(null, null);
        self::$db = Database::getInstance();
    }

    protected function tearDown(): void {
        \App\Service\BackupService::overrideAddonDirsForTests(null);
        self::$db->exec("DELETE FROM settings WHERE setting_key LIKE 'backup\\_%'");
        foreach ($this->zusatzTabellen as $tabelle) {
            self::$db->exec('DROP TABLE IF EXISTS `' . $tabelle . '`');
        }
        foreach ($this->slugs as $slug) {
            self::$db->prepare('DELETE FROM plugins WHERE slug = ?')->execute([$slug]);
        }
        foreach (array_reverse($this->aufraeumen) as $pfad) {
            self::weg($pfad);
        }
        foreach (glob(self::pluginsDir() . '/' . self::PRAEFIX . '*') ?: [] as $rest) {
            self::weg($rest);
        }
        $property = new \ReflectionProperty(PluginManager::class, 'instance');
        $property->setValue(null, null);
    }

    // ---- uninstallHookPruefung() (Audit N15) ---------------------------

    public function testDisabledWithMatchingHashMayRunHook(): void {
        $slug = $this->addon('a');
        $this->frisch()->setEnabled($slug, true);
        $this->frisch()->setEnabled($slug, false);

        $pruefung = $this->frisch()->uninstallHookPruefung($slug);

        $this->assertTrue($pruefung['laeuft'], $pruefung['grund']);
    }

    public function testDisabledChangedWithVersionBumpAndReleaseSourceMayNotRunHook(): void {
        $slug = $this->addon('b');
        $this->frisch()->setEnabled($slug, true);
        $this->frisch()->setEnabled($slug, false);

        // Der Umgehungsversuch: Code ändern UND Version erhöhen UND eine
        // Release-Herkunft eintragen. Beim Laden würde #212 das akzeptieren -
        // ein deaktiviertes Addon wird aber nie geladen, und die
        // Deinstallation darf nicht mehr Vertrauen gewähren als das Laden.
        file_put_contents($this->dir($slug) . '/Plugin.php', "\n// untergeschoben\n", FILE_APPEND);
        $this->manifest($slug, '1.1.0');
        self::$db->prepare("UPDATE plugins SET source = 'Celestial0579/Hengstverzeichnis_Addons@v1.1.0' WHERE slug = ?")->execute([$slug]);

        $pruefung = $this->frisch()->uninstallHookPruefung($slug);

        $this->assertFalse($pruefung['laeuft']);
        $this->assertStringContainsString('verändert', $pruefung['grund']);
    }

    public function testNeverApprovedMayNotRunHook(): void {
        $slug = $this->addon('c');
        // Nie aktiviert: keine Zeile.
        $this->assertSame(['laeuft' => false, 'grund' => 'nie freigegeben'], $this->frisch()->uninstallHookPruefung($slug));

        // Zeile ohne Fingerabdruck (etwa vor diesem Release deaktiviert).
        self::$db->prepare("INSERT INTO plugins (slug, enabled, installed_version, content_hash) VALUES (?, 0, '1.0.0', NULL)")->execute([$slug]);
        $this->assertSame(['laeuft' => false, 'grund' => 'nie freigegeben'], $this->frisch()->uninstallHookPruefung($slug));
    }

    public function testIncompatibleManifestMayNotRunHook(): void {
        $slug = $this->addon('d');
        $this->frisch()->setEnabled($slug, true);
        $this->frisch()->setEnabled($slug, false);
        $this->manifest($slug, '1.0.0', '>=99.0.0');

        $pruefung = $this->frisch()->uninstallHookPruefung($slug);

        $this->assertFalse($pruefung['laeuft']);
        $this->assertStringContainsString('kompatibel', $pruefung['grund']);
    }

    public function testEnabledAndLoadedMayRunHook(): void {
        $slug = $this->addon('e');
        $this->frisch()->setEnabled($slug, true);

        $manager = $this->frisch();
        $this->assertTrue($manager->isEnabled($slug));

        $pruefung = $manager->uninstallHookPruefung($slug);
        $this->assertTrue($pruefung['laeuft'], $pruefung['grund']);
    }

    public function testEnabledButNeedsReapprovalMayNotRunHook(): void {
        $slug = $this->addon('f');
        $this->frisch()->setEnabled($slug, true);
        file_put_contents($this->dir($slug) . '/Plugin.php', "\n// veraendert\n", FILE_APPEND);

        $manager = $this->frisch();
        $this->assertTrue($manager->needsReapproval($slug));

        $pruefung = $manager->uninstallHookPruefung($slug);
        $this->assertFalse($pruefung['laeuft']);
    }

    public function testDisablingKeepsTheApprovalBaseline(): void {
        $slug = $this->addon('g');
        $this->frisch()->setEnabled($slug, true);
        $vorher = $this->zeile($slug);

        $this->frisch()->setEnabled($slug, false);
        $nachher = $this->zeile($slug);

        $this->assertSame(0, (int)$nachher['enabled']);
        $this->assertNull($nachher['activated_at']);
        $this->assertSame($vorher['content_hash'], $nachher['content_hash']);
        $this->assertSame($vorher['dir_stamp'], $nachher['dir_stamp']);
        $this->assertSame($vorher['installed_version'], $nachher['installed_version']);
        $this->assertNotNull($nachher['content_hash']);
    }

    public function testUninstallWithoutApprovalDoesNotLoadAddonCode(): void {
        $slug = $this->addon('h');

        $protokoll = $this->frisch()->uninstall($slug, true);

        $this->assertFalse(class_exists('Plugin\\PhpunitDeinstH\\Plugin', false), 'Die Plugin-Datei eines nie freigegebenen Addons darf nicht geladen werden');
        $this->assertNotEmpty(array_filter($protokoll, fn($e) => str_contains($e, 'NICHT ausgeführt (nie freigegeben)')));
    }

    // ---- Code und Zeile entfernen (Audit N62) --------------------------

    public function testUninstallRemovesCodeAndRow(): void {
        $slug = $this->addon('i');
        $this->frisch()->setEnabled($slug, true);

        $protokoll = $this->frisch()->uninstall($slug, false);

        $this->assertDirectoryDoesNotExist($this->dir($slug));
        $this->assertSame(0, $this->zeilen($slug));
        $this->assertContains('Addon-Code (plugins/' . $slug . ') entfernt.', $protokoll);
        $this->assertContains('Verwaltungseintrag entfernt - das Addon wird nicht mehr automatisch aktualisiert.', $protokoll);
        $this->assertSame([], glob(self::pluginsDir() . '/' . $slug . '.entfernt-*') ?: [], 'Kein beiseitegelegter Rest darf liegen bleiben');
    }

    public function testSymlinkedAddonOnlyLosesTheLink(): void {
        $slug = self::PRAEFIX . 'j';
        $this->slugs[] = $slug;
        $ziel = sys_get_temp_dir() . '/hv-deinst-ziel-' . bin2hex(random_bytes(4));
        $this->aufraeumen[] = $ziel;
        mkdir($ziel);
        $this->dateienSchreiben($ziel, $slug, '1.0.0');
        file_put_contents($ziel . '/daten.txt', 'bleibt');
        $link = $this->dir($slug);
        symlink($ziel, $link);
        $this->aufraeumen[] = $link;

        $manager = $this->frisch();
        $this->assertArrayHasKey($slug, $manager->getDiscoveredPlugins());

        $protokoll = $manager->uninstall($slug, false);

        $this->assertFalse(is_link($link) || file_exists($link), 'Die Verknüpfung muss weg sein');
        $this->assertFileExists($ziel . '/plugin.json', 'Das Ziel der Verknüpfung darf nicht angefasst werden');
        $this->assertFileExists($ziel . '/Plugin.php');
        $this->assertSame('bleibt', file_get_contents($ziel . '/daten.txt'));
        $this->assertNotEmpty(array_filter($protokoll, fn($e) => str_contains($e, 'Verknüpfung')));
        $this->assertSame([], glob(self::pluginsDir() . '/' . $slug . '.entfernt-*') ?: []);
    }

    public function testInnerSymlinkIsNotFollowed(): void {
        $slug = $this->addon('k');
        $extern = sys_get_temp_dir() . '/hv-deinst-extern-' . bin2hex(random_bytes(4));
        $this->aufraeumen[] = $extern;
        mkdir($extern);
        file_put_contents($extern . '/wichtig.txt', 'nicht loeschen');
        symlink($extern, $this->dir($slug) . '/extern');

        $this->frisch()->uninstall($slug, false);

        $this->assertDirectoryDoesNotExist($this->dir($slug));
        $this->assertFileExists($extern . '/wichtig.txt', 'Ein Symlink im Addon-Verzeichnis darf nicht verfolgt werden');
    }

    public function testWithoutWritePermissionWarnsAndStillRemovesRow(): void {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Als root greifen fehlende Schreibrechte nicht.');
        }
        $slug = $this->addon('l');
        $this->frisch()->setEnabled($slug, true);
        $plugins = self::pluginsDir();
        $modus = fileperms($plugins) & 0777;
        chmod($plugins, 0555);
        try {
            $protokoll = $this->frisch()->uninstall($slug, false);
        } finally {
            chmod($plugins, $modus);
        }

        $this->assertDirectoryExists($this->dir($slug));
        $this->assertNotEmpty(array_filter($protokoll, fn($e) => str_starts_with($e, 'WARNUNG: Addon-Code')));
        $this->assertSame(0, $this->zeilen($slug));
    }

    public function testDirectoryDeletionDoesNotFollowTopLevelSymlink(): void {
        // Regressionstest für die Löschroutine, die auch die Verzeichnisse
        // aus dem Datenregister entfernt: is_dir() folgt einem Symlink, der
        // Iterator hätte das ZIEL geleert.
        $ziel = sys_get_temp_dir() . '/hv-deinst-regziel-' . bin2hex(random_bytes(4));
        $link = sys_get_temp_dir() . '/hv-deinst-reglink-' . bin2hex(random_bytes(4));
        $this->aufraeumen[] = $ziel;
        $this->aufraeumen[] = $link;
        mkdir($ziel);
        file_put_contents($ziel . '/datei.txt', 'x');
        symlink($ziel, $link);

        $methode = new \ReflectionMethod(PluginManager::class, 'verzeichnisLoeschen');
        $this->assertTrue($methode->invoke(null, $link));

        $this->assertFalse(is_link($link));
        $this->assertFileExists($ziel . '/datei.txt');
    }

    // ---- Datenregister: Sicherung und unregistrierte Reste (Audit N27, M30)

    /**
     * Deinstallation mit Datenlöschung: Die Ablage aus owns.directories
     * steckt vorher in einem eigenen Objekt addondaten-… (Upload-Option aus),
     * das Protokoll nennt die Zahl, danach ist die Ablage weg. Eine Tabelle
     * mit dem Namen des Addons, die nicht im Register steht, bleibt stehen
     * und wird als "NICHT gelöscht" gemeldet.
     */
    public function testDatenLoeschenSichertAblageVorherUndMeldetUnregistrierteTabelle(): void {
        $slug = $this->addon('m');
        $praefix = 'plugin_phpunit_deinst_m';
        $ablage = dirname(__DIR__, 2) . '/storage/' . $praefix;
        self::weg($ablage);
        mkdir($ablage, 0777, true);
        $this->aufraeumen[] = $ablage;
        file_put_contents($ablage . '/dokument.pdf', 'gesundheitsdaten');
        $this->manifestIn($this->dir($slug), $slug, '1.0.0', '>=0.0.1', [
            'tables' => [$praefix . '_daten'],
            'directories' => ['storage/' . $praefix],
        ]);
        foreach ([$praefix . '_daten', $praefix . '_vergessen'] as $tabelle) {
            $this->zusatzTabellen[] = $tabelle;
            self::$db->exec('CREATE TABLE IF NOT EXISTS `' . $tabelle . '` (id INT PRIMARY KEY) ENGINE=InnoDB');
        }
        $this->sicherungEinrichten();

        $manager = $this->frisch();
        $vorschau = $manager->deinstallationsVorschau($slug);
        $this->assertTrue($vorschau['deklariert']);
        $this->assertSame([$praefix . '_vergessen'], $vorschau['unregistriert']['tables']);
        $this->assertSame([], $vorschau['unregistriert']['directories'], 'Die registrierte Ablage ist kein Rest');

        $protokoll = $manager->uninstall($slug, true);

        $this->assertContains('Sicherung vor dem Löschen ausgeführt (inkl. 1 Addon-Verzeichnis).', $protokoll);
        $archive = glob(FakeS3Server::storageDir() . '/test-bucket__backups~addondaten-*') ?: [];
        $this->assertCount(1, $archive, 'Vor dem Löschen muss die Ablage gesichert sein');
        $this->assertStringContainsString('gesundheitsdaten', (string)gzdecode((string)file_get_contents($archive[0])));
        $this->assertStringContainsString('storage/' . $praefix . '/dokument.pdf', (string)gzdecode((string)file_get_contents($archive[0])));

        $this->assertDirectoryDoesNotExist($ablage);
        $this->assertFalse($this->tabelleDa($praefix . '_daten'));
        $this->assertTrue($this->tabelleDa($praefix . '_vergessen'), 'Ein Namensmuster ist keine Eigentumserklärung - nicht löschen');
        $this->assertContains("NICHT gelöscht (nicht im Datenregister des Addons): Tabelle {$praefix}_vergessen", $protokoll);
    }

    /**
     * Die Vorschau ohne owns: nicht deklariert, und die Tabelle mit dem
     * Namen des Addons erscheint als Rest. Tabellen, die ein ANDERES Addon
     * registriert, gehören nicht dazu.
     */
    public function testVorschauOhneRegisterMeldetResteAberKeineFremdRegistrierten(): void {
        $slug = $this->addon('n');
        $anderer = $this->addon('n-extra');
        $this->manifestIn($this->dir($anderer), $anderer, '1.0.0', '>=0.0.1', ['tables' => ['plugin_phpunit_deinst_n_geteilt']]);
        foreach (['plugin_phpunit_deinst_n', 'plugin_phpunit_deinst_n_geteilt'] as $tabelle) {
            $this->zusatzTabellen[] = $tabelle;
            self::$db->exec('CREATE TABLE IF NOT EXISTS `' . $tabelle . '` (id INT PRIMARY KEY) ENGINE=InnoDB');
        }

        $vorschau = $this->frisch()->deinstallationsVorschau($slug);

        $this->assertFalse($vorschau['deklariert']);
        $this->assertSame(['plugin_phpunit_deinst_n'], $vorschau['unregistriert']['tables']);
    }

    /**
     * Ohne übergebene Verzeichnisse und mit Upload-Option liest die
     * Sicherung die Ablagen selbst aus den Registern der entdeckten Addons -
     * auch eines deaktivierten.
     */
    public function testSicherungNimmtAblagenAllerEntdecktenAddonsAuf(): void {
        $slug = $this->addon('o');
        $ablage = dirname(__DIR__, 2) . '/storage/plugin_phpunit_deinst_o';
        self::weg($ablage);
        mkdir($ablage, 0777, true);
        $this->aufraeumen[] = $ablage;
        file_put_contents($ablage . '/ablage.txt', 'bleibt gesichert');
        $this->manifestIn($this->dir($slug), $slug, '1.0.0', '>=0.0.1', ['directories' => ['storage/plugin_phpunit_deinst_o']]);
        $leer = sys_get_temp_dir() . '/hv-deinst-uploads-' . bin2hex(random_bytes(4));
        mkdir($leer);
        $this->aufraeumen[] = $leer;
        \App\Service\BackupService::overrideUploadsDirForTests($leer);
        $this->sicherungEinrichten(['backup_include_uploads' => '1']);

        $manager = $this->frisch();
        $this->assertFalse($manager->isEnabled($slug));
        try {
            \App\Service\BackupService::run();
        } finally {
            \App\Service\BackupService::overrideUploadsDirForTests(null);
        }

        $archive = glob(FakeS3Server::storageDir() . '/test-bucket__backups~uploads-*') ?: [];
        $this->assertCount(1, $archive);
        $inhalt = (string)gzdecode((string)file_get_contents($archive[0]));
        $this->assertStringContainsString('storage/plugin_phpunit_deinst_o/ablage.txt', $inhalt);
        $this->assertStringContainsString('bleibt gesichert', $inhalt);
    }

    // ---- Hilfsmittel ---------------------------------------------------

    /** @param array<string, string> $zusatz */
    private function sicherungEinrichten(array $zusatz = []): void {
        FakeS3Server::ensureStarted();
        foreach (glob(FakeS3Server::storageDir() . '/*') ?: [] as $datei) {
            @unlink($datei);
        }
        $stmt = self::$db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach (array_merge([
            'backup_enabled' => '1',
            'backup_s3_endpoint' => FakeS3Server::endpoint(),
            'backup_s3_region' => 'us-east-1',
            'backup_s3_bucket' => 'test-bucket',
            'backup_s3_access_key' => 'AKIDEXAMPLE',
            'backup_s3_secret_key' => \App\Security\Crypto::encrypt('test-secret'),
            'backup_s3_path_style' => '1',
            'backup_s3_use_https' => '0',
            'backup_include_uploads' => '0',
        ], $zusatz) as $schluessel => $wert) {
            $stmt->execute([$schluessel, $wert]);
        }
    }

    private function tabelleDa(string $tabelle): bool {
        $stmt = self::$db->query('SHOW TABLES LIKE ' . self::$db->quote($tabelle));
        return $stmt !== false && $stmt->rowCount() > 0;
    }

    private static function pluginsDir(): string {
        return __DIR__ . '/../../plugins';
    }

    private function dir(string $slug): string {
        return self::pluginsDir() . '/' . $slug;
    }

    /** Legt plugins/<praefix><kennung> an und liefert den Slug. */
    private function addon(string $kennung): string {
        $slug = self::PRAEFIX . $kennung;
        $this->slugs[] = $slug;
        $dir = $this->dir($slug);
        self::weg($dir);
        mkdir($dir, 0777, true);
        $this->aufraeumen[] = $dir;
        $this->dateienSchreiben($dir, $slug, '1.0.0');
        self::$db->prepare('DELETE FROM plugins WHERE slug = ?')->execute([$slug]);
        return $slug;
    }

    private function dateienSchreiben(string $dir, string $slug, string $version): void {
        $this->manifestIn($dir, $slug, $version, '>=0.0.1');
        $studly = str_replace(' ', '', ucwords(str_replace('-', ' ', $slug)));
        file_put_contents($dir . '/Plugin.php', "<?php\nnamespace Plugin\\{$studly};\n\nclass Plugin {\n    public function uninstall(): void {}\n}\n");
    }

    private function manifest(string $slug, string $version, string $kern = '>=0.0.1'): void {
        $this->manifestIn($this->dir($slug), $slug, $version, $kern);
    }

    /** @param array<string, mixed>|null $owns */
    private function manifestIn(string $dir, string $slug, string $version, string $kern, ?array $owns = null): void {
        $manifest = [
            'slug' => $slug,
            'name' => 'PHPUnit Deinstallations-Fixture',
            'version' => $version,
            'core_compatibility' => $kern,
            'core_supported_max' => '99.99',
        ];
        if ($owns !== null) {
            $manifest['owns'] = $owns;
        }
        file_put_contents($dir . '/plugin.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** Neuer Request: Singleton verwerfen und frisch booten. */
    private function frisch(): PluginManager {
        $property = new \ReflectionProperty(PluginManager::class, 'instance');
        $property->setValue(null, null);
        $manager = PluginManager::getInstance();
        $manager->boot();
        return $manager;
    }

    /** @return array<string, mixed> */
    private function zeile(string $slug): array {
        $stmt = self::$db->prepare('SELECT enabled, installed_version, content_hash, dir_stamp, activated_at FROM plugins WHERE slug = ?');
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        return $row;
    }

    private function zeilen(string $slug): int {
        $stmt = self::$db->prepare('SELECT COUNT(*) FROM plugins WHERE slug = ?');
        $stmt->execute([$slug]);
        return (int)$stmt->fetchColumn();
    }

    private static function weg(string $pfad): void {
        if (is_link($pfad)) {
            @unlink($pfad);
            return;
        }
        if (!is_dir($pfad)) {
            if (file_exists($pfad)) {
                @unlink($pfad);
            }
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pfad, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            ($item->isDir() && !$item->isLink()) ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($pfad);
    }
}
