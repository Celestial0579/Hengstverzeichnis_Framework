<?php
// tests/Integration/PluginManagerStampTest.php

namespace Tests\Integration;

use App\Database;
use App\Plugin\PluginManager;
use PDO;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

/**
 * Integrationstest für das Zusammenspiel von Verzeichnis-Stempel (#224),
 * Release-gebundenem Auto-Accept bei Versionswechseln (#212) und dem
 * install()-Hook (Addons#75) gegen eine echte Test-Datenbank und ein echtes
 * Wegwerf-Plugin unter plugins/. Braucht wie tests/Integration/DatabaseTest.php
 * eine per Umgebungsvariable konfigurierte Test-DB (siehe tests/bootstrap.php).
 *
 * Die Testmethoden bilden über #[Depends] bewusst EINE Erzählung ab (Aktivieren
 * -> Kurzschluss -> Stempel-Abweichung -> Code-Austausch -> Versionswechsel),
 * weil jeder Schritt auf dem persistierten Zustand des vorherigen aufbaut -
 * genau wie aufeinanderfolgende HTTP-Requests einer echten Installation.
 * "Neuer Request" heißt hier: PluginManager-Singleton zurücksetzen und boot()
 * erneut laufen lassen (PHP ist share-nothing, siehe resetPluginManager()).
 */
class PluginManagerStampTest extends TestCase {

    private const SLUG = 'phpunit-stamp-fixture';

    private static string $pluginDir;

    private static PDO $db;

    public static function setUpBeforeClass(): void {
        if (!defined('DB_HOST')) {
            self::markTestSkipped('Keine Test-Datenbank konfiguriert (DB_HOST fehlt) - siehe tests/bootstrap.php.');
        }

        // PluginManager::discoverPlugins() prüft Manifeste gegen CORE_VERSION -
        // im Test-Prozess ist config/config.php bewusst nicht geladen (siehe
        // tests/bootstrap.php), daher hier definieren, falls noch nicht geschehen.
        if (!defined('CORE_VERSION')) {
            define('CORE_VERSION', '0.4.0');
        }

        // Frischer Verbindungsaufbau erzwingen: Vorherige Integrationstests
        // (DigestServiceTest & Co.) setzen die Test-DB auf database/schema.sql
        // zurück und lassen den Database-Singleton stehen - erst der Reset lässt
        // getInstance() die versionierte Migration wirklich laufen, die
        // plugins.dir_stamp/source nachzieht (siehe App\Service\SchemaMigrator;
        // schema.sql seedet bewusst kein schema_version, der Stand ist nach dem
        // Re-Import also 0 und die - idempotente - Migration läuft genau einmal).
        $property = new \ReflectionProperty(Database::class, 'instance');
        $property->setValue(null, null);
        self::$db = Database::getInstance();

        self::$pluginDir = __DIR__ . '/../../plugins/' . self::SLUG;
        self::removeFixture();
        mkdir(self::$pluginDir, 0777, true);
        self::writeManifest('1.0.0');
        file_put_contents(self::$pluginDir . '/data.txt', 'urspruenglicher inhalt');
        // Die Plugin-Klasse zählt install()-Aufrufe in einer statischen
        // Eigenschaft mit - ein Marker IM Plugin-Verzeichnis würde den
        // Verzeichnis-Stempel/Fingerabdruck verändern und die Messung stören.
        file_put_contents(self::$pluginDir . '/Plugin.php', <<<'PHP'
<?php
namespace Plugin\PhpunitStampFixture;

class Plugin {
    public static int $installCalls = 0;

    public function install(): void {
        self::$installCalls++;
    }
}
PHP);

        self::$db->prepare("DELETE FROM plugins WHERE slug = ?")->execute([self::SLUG]);
    }

    public static function tearDownAfterClass(): void {
        if (!defined('DB_HOST')) {
            return;
        }
        self::$db->prepare("DELETE FROM plugins WHERE slug = ?")->execute([self::SLUG]);
        self::removeFixture();
        // Nachfolgende Testklassen sollen nicht mit einem auf das (inzwischen
        // gelöschte) Fixture gebooteten PluginManager weiterarbeiten.
        self::resetPluginManager();
    }

    private static function removeFixture(): void {
        if (!is_dir(self::$pluginDir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::$pluginDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir(self::$pluginDir);
    }

    private static function writeManifest(string $version): void {
        file_put_contents(self::$pluginDir . '/plugin.json', json_encode([
            'slug' => self::SLUG,
            'name' => 'PHPUnit Stempel-Fixture',
            'version' => $version,
            'core_compatibility' => '>=0.0.1',
            'core_supported_max' => '99.99',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Simuliert den Bootstrap eines NEUEN Requests: Singleton verwerfen,
     * frisch booten (discoverPlugins + loadEnabledStates + loadEnabledPlugins).
     */
    private static function bootFreshManager(): PluginManager {
        self::resetPluginManager();
        $manager = PluginManager::getInstance();
        $manager->boot();
        return $manager;
    }

    private static function resetPluginManager(): void {
        $property = new \ReflectionProperty(PluginManager::class, 'instance');
        $property->setValue(null, null);
    }

    /** @return array<string, mixed> */
    private function pluginRow(): array {
        $stmt = self::$db->prepare("SELECT enabled, installed_version, content_hash, dir_stamp, source FROM plugins WHERE slug = ?");
        $stmt->execute([self::SLUG]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row, 'plugins-Zeile für das Fixture fehlt');
        return $row;
    }

    private function installCalls(): int {
        return \Plugin\PhpunitStampFixture\Plugin::$installCalls;
    }

    public function testActivationStoresBaselineAndRunsInstallHook(): void {
        $manager = self::bootFreshManager();
        $this->assertArrayHasKey(self::SLUG, $manager->getDiscoveredPlugins(), 'Fixture-Plugin wurde nicht entdeckt');

        $manager->setEnabled(self::SLUG, true);

        // Addons#75: Die Aktivierung ruft den install()-Hook genau einmal auf.
        $this->assertSame(1, $this->installCalls());

        $row = $this->pluginRow();
        $this->assertSame(1, (int)$row['enabled']);
        $this->assertSame('1.0.0', $row['installed_version']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string)$row['content_hash']);
        // #224: Der Verzeichnis-Stempel gehört mit zur Freigabe-Baseline.
        $this->assertMatchesRegularExpression('/^\d+:\d+:\d+$/', (string)$row['dir_stamp']);
    }

    #[Depends('testActivationStoresBaselineAndRunsInstallHook')]
    public function testMatchingStampSkipsSha256OnNextBoot(): void {
        $manager = self::bootFreshManager();

        $this->assertTrue($manager->isEnabled(self::SLUG));
        $this->assertFalse($manager->needsReapproval(self::SLUG));
        // Kern von #224: Bei übereinstimmendem Stempel wird der SHA-256 über
        // alle Plugin-Dateien NIE berechnet - der lazy Fingerabdruck bleibt null.
        $this->assertNull(
            $manager->getDiscoveredPlugins()[self::SLUG]['fingerprint'],
            'Fingerabdruck wurde trotz übereinstimmendem Verzeichnis-Stempel berechnet'
        );
        // Der reguläre Boot (register()) ruft install() NICHT auf.
        $this->assertSame(1, $this->installCalls());
    }

    #[Depends('testMatchingStampSkipsSha256OnNextBoot')]
    public function testStampMismatchWithIdenticalContentRehashesAndHealsStamp(): void {
        // Abweichender Stempel bei identischem Inhalt - wie nach einem frisch
        // entpackten Deployment (neue mtimes) oder bei einer Bestandszeile von
        // vor der dir_stamp-Spalte.
        self::$db->prepare("UPDATE plugins SET dir_stamp = '0:0:0' WHERE slug = ?")->execute([self::SLUG]);

        $manager = self::bootFreshManager();

        $this->assertFalse($manager->needsReapproval(self::SLUG), 'Identischer Inhalt darf trotz Stempel-Abweichung keine Re-Freigabe verlangen');
        // Der volle Hash MUSSTE diesmal laufen (fail-closed-Rückfall) ...
        $this->assertNotNull($manager->getDiscoveredPlugins()[self::SLUG]['fingerprint']);
        // ... und der echte Stempel wurde als neue Baseline nachgeschrieben,
        // damit der nächste Request wieder ohne SHA-256 auskommt.
        $row = $this->pluginRow();
        $this->assertSame($manager->getDiscoveredPlugins()[self::SLUG]['dir_stamp'], $row['dir_stamp']);
        $this->assertNotSame('0:0:0', $row['dir_stamp']);
    }

    #[Depends('testStampMismatchWithIdenticalContentRehashesAndHealsStamp')]
    public function testChangedContentWithSameVersionRequiresReapproval(): void {
        // Code-Austausch ohne Versionswechsel: anderer Inhalt UND andere Länge,
        // damit sicher auch der Stempel abweicht und der Hash-Vergleich greift.
        file_put_contents(self::$pluginDir . '/data.txt', 'heimlich ausgetauschter inhalt (laenger)');

        $manager = self::bootFreshManager();
        $this->assertTrue($manager->needsReapproval(self::SLUG), 'Ausgetauschter Code bei gleicher Version muss fail-closed zur Re-Freigabe führen');

        // Bewusste Re-Freigabe durch den Admin: setEnabled() setzt die neue
        // Baseline und ruft install() erneut auf (idempotenter Hook).
        $manager->setEnabled(self::SLUG, true);
        $this->assertSame(2, $this->installCalls());

        $manager = self::bootFreshManager();
        $this->assertFalse($manager->needsReapproval(self::SLUG));
    }

    #[Depends('testChangedContentWithSameVersionRequiresReapproval')]
    public function testVersionBumpWithoutReleaseSourceRequiresReapproval(): void {
        // #212: Versionswechsel OHNE belegte Release-Herkunft (source ist NULL -
        // manuell kopiertes Plugin) darf nicht mehr automatisch akzeptiert werden.
        self::writeManifest('1.1.0');

        $manager = self::bootFreshManager();
        $this->assertTrue($manager->needsReapproval(self::SLUG), 'Versionswechsel ohne Release-Herkunft muss fail-closed zur Re-Freigabe führen');

        // Nicht-destruktive Garantie: Die Freigabe-Baseline bleibt unangetastet.
        $this->assertSame('1.0.0', $this->pluginRow()['installed_version']);

        // Auch ein Branch-Stand ist keine Release-Herkunft - main-HEAD ist mutabel.
        self::$db->prepare("UPDATE plugins SET source = 'Celestial0579/Hengstverzeichnis_Addons@main' WHERE slug = ?")->execute([self::SLUG]);
        $manager = self::bootFreshManager();
        $this->assertTrue($manager->needsReapproval(self::SLUG), 'Versionswechsel aus einem Branch-Stand muss fail-closed zur Re-Freigabe führen');
        $this->assertSame('1.0.0', $this->pluginRow()['installed_version']);
    }

    #[Depends('testVersionBumpWithoutReleaseSourceRequiresReapproval')]
    public function testVersionBumpFromReleaseTagIsAutoAccepted(): void {
        // Mit Release-Tag-Herkunft (unveränderlicher Stand) greift der bisherige
        // Auto-Accept: Update verliert seine Aktivierung nicht.
        self::$db->prepare("UPDATE plugins SET source = 'Celestial0579/Hengstverzeichnis_Addons@v0.4.1' WHERE slug = ?")->execute([self::SLUG]);

        $manager = self::bootFreshManager();

        $this->assertFalse($manager->needsReapproval(self::SLUG));
        $row = $this->pluginRow();
        $this->assertSame('1.1.0', $row['installed_version']);
        // Baseline wandert vollständig mit: neuer Fingerabdruck + neuer Stempel.
        $discovered = $manager->getDiscoveredPlugins()[self::SLUG];
        $this->assertSame($discovered['fingerprint'], $row['content_hash']);
        $this->assertSame($discovered['dir_stamp'], $row['dir_stamp']);
    }

    #[Depends('testVersionBumpFromReleaseTagIsAutoAccepted')]
    public function testRunInstallHookIsPubliclyCallable(): void {
        // Öffentlicher Einstiegspunkt für den AddonUpdateService (Addons#75):
        // nach einem eingespielten Update erneut install() ausführen.
        $before = $this->installCalls();
        PluginManager::getInstance()->runInstallHook(self::SLUG);
        $this->assertSame($before + 1, $this->installCalls());
    }
}
