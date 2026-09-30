<?php
// tests/Functional/PluginInstallAfterUpdateTest.php

namespace Tests\Functional;

use PDO;
use Tests\Support\HttpClient;

/**
 * install() nach einem Addon-Update läuft mit dem NEUEN Code (Audit M35).
 *
 * Früher rief der AddonUpdateService install() im Update-Request auf - dort
 * war aber die alte Plugin-Klasse schon geladen, require_once lud die neue
 * nicht nach. Neue Tabellen einer Addon-Version entstanden deshalb erst nach
 * einem Deaktivieren und Wiederaktivieren von Hand.
 *
 * Jetzt führt der erste Request, der die neue Version lädt und übernimmt,
 * install() aus - genau einmal. Geprüft über eine echte Prozessgrenze
 * (php -S): Der Testprozess tauscht das Addon-Verzeichnis wie ein Update aus,
 * der nächste HTTP-Aufruf ist "der erste Request danach".
 */
class PluginInstallAfterUpdateTest extends FunctionalTestCase {

    private const SLUG = 'lzfix-fixture';
    private const PLUGIN_DEST = __DIR__ . '/../../plugins/' . self::SLUG;
    private const TABELLE_A = 'plugin_lzfix_a';
    private const TABELLE_B = 'plugin_lzfix_b';

    protected function setUp(): void {
        parent::setUp();
        self::schreibeVersion('1.0.0');
    }

    protected function tearDown(): void {
        self::removeTree(self::PLUGIN_DEST);
        try {
            $db = \App\Database::getInstance();
            $db->prepare('DELETE FROM plugins WHERE slug = ?')->execute([self::SLUG]);
            $db->exec('DROP TABLE IF EXISTS `' . self::TABELLE_A . '`');
            $db->exec('DROP TABLE IF EXISTS `' . self::TABELLE_B . '`');
        } catch (\Throwable $e) {
            // Schema evtl. noch nicht angelegt.
        }
        parent::tearDown();
    }

    public function testFirstRequestAfterReleaseUpdateRunsNewInstallExactlyOnce(): void {
        $admin = $this->vorbereitet();
        $this->toggle($admin, true);
        $this->assertTrue($this->tabelleExistiert(self::TABELLE_A), 'install() der Version 1.0.0 muss bei der Aktivierung laufen');

        $this->updateEinspielen();

        $admin->get('/admin/plugins');

        $this->assertTrue($this->tabelleExistiert(self::TABELLE_B), 'install() der NEUEN Version muss im ersten Request danach laufen');
        $this->assertSame(1, $this->zeilenB(), 'install() darf genau einmal laufen');
        $this->assertSame('1.1.0', $this->installierteVersion());

        $admin->get('/admin/plugins');
        $this->newClient()->get('/');
        $this->assertSame(1, $this->zeilenB(), 'Weitere Requests dürfen install() nicht erneut ausführen');
    }

    public function testDeactivatedAddonSetsUpOnNextActivation(): void {
        $admin = $this->vorbereitet();
        $this->toggle($admin, true);
        $this->toggle($admin, false);

        $this->updateEinspielen();
        $admin->get('/admin/plugins');

        $this->assertFalse($this->tabelleExistiert(self::TABELLE_B), 'Ein deaktiviertes Addon darf nach dem Update nicht eingerichtet werden');

        $this->toggle($admin, true);

        $this->assertTrue($this->tabelleExistiert(self::TABELLE_B), 'Die Aktivierung richtet die neue Version ein');
        $this->assertSame(1, $this->zeilenB());
    }

    // ---- Hilfsmittel ---------------------------------------------------

    private function vorbereitet(): HttpClient {
        $admin = $this->authenticatedClient();
        $db = $this->db();
        $db->prepare('DELETE FROM plugins WHERE slug = ?')->execute([self::SLUG]);
        $db->exec('DROP TABLE IF EXISTS `' . self::TABELLE_A . '`');
        $db->exec('DROP TABLE IF EXISTS `' . self::TABELLE_B . '`');
        return $admin;
    }

    /** Wie ein Update aus einem Release-Tag: Code tauschen, Herkunft pinnen. */
    private function updateEinspielen(): void {
        self::schreibeVersion('1.1.0');
        $this->db()->prepare("UPDATE plugins SET source = 'Celestial0579/Hengstverzeichnis_Addons@v1.1.0' WHERE slug = ?")
            ->execute([self::SLUG]);
    }

    private function toggle(HttpClient $admin, bool $an): void {
        $antwort = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => $an ? '1' : '',
        ]);
        $this->assertSame('/admin/plugins?success=1', $antwort->location());
    }

    private function db(): PDO {
        return \App\Database::getInstance();
    }

    private function tabelleExistiert(string $tabelle): bool {
        $stmt = $this->db()->query("SHOW TABLES LIKE '" . $tabelle . "'");
        return $stmt !== false && $stmt->rowCount() > 0;
    }

    private function zeilenB(): int {
        return (int)$this->db()->query('SELECT COUNT(*) FROM `' . self::TABELLE_B . '`')->fetchColumn();
    }

    private function installierteVersion(): ?string {
        $stmt = $this->db()->prepare('SELECT installed_version FROM plugins WHERE slug = ?');
        $stmt->execute([self::SLUG]);
        $wert = $stmt->fetchColumn();
        return $wert === false ? null : (string)$wert;
    }

    private static function schreibeVersion(string $version): void {
        self::removeTree(self::PLUGIN_DEST);
        mkdir(self::PLUGIN_DEST, 0777, true);

        file_put_contents(self::PLUGIN_DEST . '/plugin.json', json_encode([
            'slug' => self::SLUG,
            'name' => 'Lebenszyklus-Fixture (Test)',
            'version' => $version,
            'core_compatibility' => '>=0.1.0-beta.1',
            'core_supported_max' => '9.9',
            'author' => 'tests/Functional/PluginInstallAfterUpdateTest',
            'entry' => 'Plugin.php',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $install = $version === '1.0.0'
            ? "\\App\\Database::getInstance()->exec('CREATE TABLE IF NOT EXISTS `" . self::TABELLE_A . "` (id INT AUTO_INCREMENT PRIMARY KEY)');"
            : "\$db = \\App\\Database::getInstance();\n"
              . "        \$db->exec('CREATE TABLE IF NOT EXISTS `" . self::TABELLE_B . "` (id INT AUTO_INCREMENT PRIMARY KEY)');\n"
              . "        \$db->exec('INSERT INTO `" . self::TABELLE_B . "` () VALUES ()');";

        file_put_contents(self::PLUGIN_DEST . '/Plugin.php', <<<PHP
<?php
// Test-Fixture für install() nach einem Update (Audit M35), Version {$version}.

namespace Plugin\LzfixFixture;

use App\Plugin\HookManager;

class Plugin {
    public function register(HookManager \$hooks): void {}

    public function install(): void {
        {$install}
    }
}
PHP);
    }

    private static function removeTree(string $pfad): void {
        if (is_link($pfad)) {
            unlink($pfad);
            return;
        }
        if (!is_dir($pfad)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pfad, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            ($item->isDir() && !$item->isLink()) ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($pfad);
    }
}
