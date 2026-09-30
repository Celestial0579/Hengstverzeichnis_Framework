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
        $stmt = self::$db->prepare("SELECT enabled, installed_version, content_hash, dir_stamp, source, pending_reason, pending_marker FROM plugins WHERE slug = ?");
        $stmt->execute([self::SLUG]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row, 'plugins-Zeile für das Fixture fehlt');
        return $row;
    }

    private function autoUpdateLogs(): int {
        $stmt = self::$db->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'Plugin automatisch aktualisiert' AND details LIKE ?");
        $stmt->execute(['Slug: ' . self::SLUG . ',%']);
        return (int)$stmt->fetchColumn();
    }

    private function auditCount(string $action): int {
        $stmt = self::$db->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = ?");
        $stmt->execute([$action]);
        return (int)$stmt->fetchColumn();
    }

    /** Audit-Einträge einer Aktion für DIESES Fixture (Filter auf den Slug in details). */
    private function slugAuditCount(string $action): int {
        $stmt = self::$db->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = ? AND details LIKE ?");
        $stmt->execute([$action, 'Slug: ' . self::SLUG . '%']);
        return (int)$stmt->fetchColumn();
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
        $baseline = $this->pluginRow();
        $logsVorher = $this->slugAuditCount('Plugin-Code seit Aktivierung geändert');
        file_put_contents(self::$pluginDir . '/data.txt', 'heimlich ausgetauschter inhalt (laenger)');

        $manager = self::bootFreshManager();
        $this->assertTrue($manager->needsReapproval(self::SLUG), 'Ausgetauschter Code bei gleicher Version muss fail-closed zur Re-Freigabe führen');

        // Audit N63: Weitere Requests im selben Wartezustand protokollieren
        // nicht erneut und berechnen den SHA-256 nicht noch einmal.
        for ($i = 2; $i <= 3; $i++) {
            $manager = self::bootFreshManager();
            $this->assertTrue($manager->needsReapproval(self::SLUG), "Boot {$i}: muss weiter auf Freigabe warten");
            $this->assertNull(
                $manager->getDiscoveredPlugins()[self::SLUG]['fingerprint'],
                "Boot {$i}: SHA-256 wurde trotz vermerktem Stand erneut berechnet"
            );
        }
        $this->assertSame($logsVorher + 1, $this->slugAuditCount('Plugin-Code seit Aktivierung geändert'), 'Drei Requests, genau ein Eintrag');

        $row = $this->pluginRow();
        $this->assertSame('code_geaendert', $row['pending_reason']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string)$row['pending_marker']);
        // Nicht-destruktiv: Die Freigabe-Baseline bleibt unangetastet.
        foreach (['enabled', 'installed_version', 'content_hash', 'dir_stamp', 'source'] as $spalte) {
            $this->assertSame($baseline[$spalte], $row[$spalte], "Baseline-Spalte {$spalte} wurde verändert");
        }

        // Ein weiterer, anderer Stand (andere Länge) ist ein neuer Übergang.
        file_put_contents(self::$pluginDir . '/data.txt', 'noch einmal ausgetauscht, wieder mit anderer laenge');
        self::bootFreshManager();
        $manager = self::bootFreshManager();
        $this->assertTrue($manager->needsReapproval(self::SLUG));
        $this->assertSame($logsVorher + 2, $this->slugAuditCount('Plugin-Code seit Aktivierung geändert'), 'Ein neuer Stand erzeugt genau einen weiteren Eintrag');
        $this->assertNotSame($row['pending_marker'], $this->pluginRow()['pending_marker']);

        // Bewusste Re-Freigabe durch den Admin: setEnabled() setzt die neue
        // Baseline und ruft install() erneut auf (idempotenter Hook).
        $manager->setEnabled(self::SLUG, true);
        $this->assertSame(2, $this->installCalls());

        $manager = self::bootFreshManager();
        $this->assertFalse($manager->needsReapproval(self::SLUG));
        // Das erste erfolgreiche Laden löscht den Wartevermerk (Audit N63).
        $row = $this->pluginRow();
        $this->assertNull($row['pending_reason']);
        $this->assertNull($row['pending_marker']);
    }

    #[Depends('testChangedContentWithSameVersionRequiresReapproval')]
    public function testVersionBumpWithoutReleaseSourceRequiresReapproval(): void {
        // #212: Versionswechsel OHNE belegte Release-Herkunft (source ist NULL -
        // manuell kopiertes Plugin) darf nicht mehr automatisch akzeptiert werden.
        $logsVorher = $this->slugAuditCount('Plugin-Update ohne Release-Herkunft');
        self::writeManifest('1.1.0');

        $manager = self::bootFreshManager();
        $this->assertTrue($manager->needsReapproval(self::SLUG), 'Versionswechsel ohne Release-Herkunft muss fail-closed zur Re-Freigabe führen');

        // Audit N63: Der zweite Request protokolliert nicht erneut.
        $manager = self::bootFreshManager();
        $this->assertTrue($manager->needsReapproval(self::SLUG));
        $this->assertSame($logsVorher + 1, $this->slugAuditCount('Plugin-Update ohne Release-Herkunft'), 'Zwei Requests, genau ein Eintrag');
        $this->assertSame('version_ohne_release', $this->pluginRow()['pending_reason']);

        // Ein weiterer Code-Austausch unter derselben, nicht freigegebenen
        // Version ist ein neuer beobachteter Stand und wird protokolliert.
        file_put_contents(self::$pluginDir . '/data.txt', 'code unter 1.1.0 nochmals getauscht');
        $manager = self::bootFreshManager();
        $this->assertTrue($manager->needsReapproval(self::SLUG));
        self::bootFreshManager();
        $this->assertSame($logsVorher + 2, $this->slugAuditCount('Plugin-Update ohne Release-Herkunft'), 'Codeänderung unter derselben Version erzeugt genau einen weiteren Eintrag');

        // Nicht-destruktive Garantie: Die Freigabe-Baseline bleibt unangetastet.
        $this->assertSame('1.0.0', $this->pluginRow()['installed_version']);
        // Audit M35: Ohne Übernahme kein install().
        $this->assertSame(2, $this->installCalls());

        // Auch ein Branch-Stand ist keine Release-Herkunft - main-HEAD ist mutabel.
        self::$db->prepare("UPDATE plugins SET source = 'Celestial0579/Hengstverzeichnis_Addons@main' WHERE slug = ?")->execute([self::SLUG]);
        $manager = self::bootFreshManager();
        $this->assertTrue($manager->needsReapproval(self::SLUG), 'Versionswechsel aus einem Branch-Stand muss fail-closed zur Re-Freigabe führen');
        $this->assertSame('1.0.0', $this->pluginRow()['installed_version']);
        $this->assertSame(2, $this->installCalls(), 'Ein Branch-Stand darf install() nicht auslösen');
        // Andere Herkunft = anderer beobachteter Stand: genau ein weiterer Eintrag.
        self::bootFreshManager();
        $this->assertSame($logsVorher + 3, $this->slugAuditCount('Plugin-Update ohne Release-Herkunft'));
    }

    #[Depends('testVersionBumpWithoutReleaseSourceRequiresReapproval')]
    public function testVersionBumpFromReleaseTagIsAutoAccepted(): void {
        // Mit Release-Tag-Herkunft (unveränderlicher Stand) greift der bisherige
        // Auto-Accept: Update verliert seine Aktivierung nicht.
        self::$db->prepare("UPDATE plugins SET source = 'Celestial0579/Hengstverzeichnis_Addons@v0.4.1' WHERE slug = ?")->execute([self::SLUG]);
        $logsVorher = $this->autoUpdateLogs();

        $manager = self::bootFreshManager();

        // Audit M35: Der Request, der die neue Version übernimmt, führt
        // install() genau einmal aus (mit dem neuen Code, vor register()) und
        // protokolliert genau einmal.
        $this->assertSame(3, $this->installCalls(), 'Die Übernahme eines Release-Updates muss install() genau einmal ausführen');
        $this->assertSame($logsVorher + 1, $this->autoUpdateLogs(), 'Genau ein Audit-Eintrag "Plugin automatisch aktualisiert"');

        $this->assertFalse($manager->needsReapproval(self::SLUG));
        $row = $this->pluginRow();
        $this->assertSame('1.1.0', $row['installed_version']);
        // Baseline wandert vollständig mit: neuer Fingerabdruck + neuer Stempel.
        $discovered = $manager->getDiscoveredPlugins()[self::SLUG];
        $this->assertSame($discovered['fingerprint'], $row['content_hash']);
        $this->assertSame($discovered['dir_stamp'], $row['dir_stamp']);
        // Der Wartevermerk aus dem vorherigen Test ist mit der Übernahme weg (Audit N63).
        $this->assertNull($row['pending_reason']);
        $this->assertNull($row['pending_marker']);
    }

    #[Depends('testVersionBumpFromReleaseTagIsAutoAccepted')]
    public function testSecondBootAfterAcceptedUpdateDoesNotRunInstallAgain(): void {
        $logsVorher = $this->autoUpdateLogs();
        $vorher = $this->installCalls();

        $manager = self::bootFreshManager();

        $this->assertFalse($manager->needsReapproval(self::SLUG));
        $this->assertSame($vorher, $this->installCalls(), 'Nach der Übernahme darf der nächste Request install() nicht erneut ausführen');
        $this->assertSame($logsVorher, $this->autoUpdateLogs());
    }

    #[Depends('testSecondBootAfterAcceptedUpdateDoesNotRunInstallAgain')]
    public function testLostAcceptRaceDoesNotRunInstallNorLog(): void {
        // Ein paralleler Request hat die Übernahme schon geschrieben: Sein
        // Altstand ('1.0.0') stimmt nicht mehr, das bedingte UPDATE trifft
        // keine Zeile - dieser Request darf weder install() ausführen noch
        // protokollieren.
        $manager = self::bootFreshManager();
        $vorher = $this->pluginRow();

        $methode = new \ReflectionMethod(PluginManager::class, 'acceptPluginUpdate');
        $gewonnen = $methode->invoke($manager, self::SLUG, '1.0.0', '1.1.0', str_repeat('a', 64), '1:1:1');

        $this->assertFalse($gewonnen, 'Ein verlorenes Übernahme-Rennen muss false liefern');
        $this->assertSame($vorher, $this->pluginRow(), 'Die Zeile darf unverändert bleiben');
    }

    #[Depends('testLostAcceptRaceDoesNotRunInstallNorLog')]
    public function testRunInstallHookDefersWhenCodeReplacedInSameRequest(): void {
        // Audit M35, Härtung: Wird der Code ersetzt, nachdem das Addon in
        // diesem Request schon geladen wurde, ist noch die alte Klasse aktiv.
        // install() darf dann nicht laufen.
        $manager = self::bootFreshManager();
        $vorher = $this->installCalls();
        $zurueckgestelltVorher = $this->auditCount('Plugin-install() zurückgestellt: ' . self::SLUG);

        $zusatz = self::$pluginDir . '/nachgeschoben.txt';
        file_put_contents($zusatz, 'im selben Request ersetzt');
        try {
            $manager->runInstallHook(self::SLUG);
        } finally {
            unlink($zusatz);
        }

        $this->assertSame($vorher, $this->installCalls(), 'install() darf nicht mit der alten Klasse laufen');
        $this->assertSame($zurueckgestelltVorher + 1, $this->auditCount('Plugin-install() zurückgestellt: ' . self::SLUG));

        // Frischer Request für den folgenden Test.
        self::bootFreshManager();
    }

    #[Depends('testRunInstallHookDefersWhenCodeReplacedInSameRequest')]
    public function testRunInstallHookIsPubliclyCallable(): void {
        // Öffentlicher Einstiegspunkt für den AddonUpdateService (Addons#75):
        // nach einem eingespielten Update erneut install() ausführen.
        $before = $this->installCalls();
        PluginManager::getInstance()->runInstallHook(self::SLUG);
        $this->assertSame($before + 1, $this->installCalls());
    }

    /**
     * Audit N63: Wird der Code auf den freigegebenen Stand zurückgedreht,
     * lädt das Addon wieder, der Vermerk verschwindet, und es entsteht kein
     * weiterer Eintrag. Die neue mtime liegt sicher woanders, damit der
     * Stempel nicht zufällig der Wartemarke des abweichenden Standes gleicht.
     */
    #[Depends('testRunInstallHookIsPubliclyCallable')]
    public function testRevertedCodeClearsPendingState(): void {
        $datei = self::$pluginDir . '/data.txt';
        $original = (string)file_get_contents($datei);
        $logsVorher = $this->slugAuditCount('Plugin-Code seit Aktivierung geändert');

        file_put_contents($datei, $original . ' - mit anderer laenge');
        $manager = self::bootFreshManager();
        $this->assertTrue($manager->needsReapproval(self::SLUG));
        $this->assertSame('code_geaendert', $this->pluginRow()['pending_reason']);
        $this->assertSame($logsVorher + 1, $this->slugAuditCount('Plugin-Code seit Aktivierung geändert'));

        file_put_contents($datei, $original);
        touch($datei, time() + 2);
        clearstatcache();

        $manager = self::bootFreshManager();
        $this->assertFalse($manager->needsReapproval(self::SLUG), 'Zurückgedrehter Code muss wieder laden');
        $row = $this->pluginRow();
        $this->assertNull($row['pending_reason']);
        $this->assertNull($row['pending_marker']);
        $this->assertSame($logsVorher + 1, $this->slugAuditCount('Plugin-Code seit Aktivierung geändert'), 'Kein zusätzlicher Eintrag');
    }

    /**
     * Audit N63: Fehlen die neuen Spalten (Migration gescheitert - Database::
     * ensureSchemaUpToDate() schluckt den Fehler), fällt loadEnabledStates()
     * auf die Abfrage ohne sie zurück. Aktivierte Addons laden weiter.
     */
    #[Depends('testRevertedCodeClearsPendingState')]
    public function testMissingPendingColumnsDoNotDisableAddons(): void {
        self::$db->exec("ALTER TABLE plugins DROP COLUMN pending_marker, DROP COLUMN pending_reason");
        try {
            $manager = self::bootFreshManager();
            $this->assertTrue($manager->isEnabled(self::SLUG));
            $this->assertFalse($manager->needsReapproval(self::SLUG), 'Ohne die Vermerk-Spalten muss das Addon weiter laden');
            $geprueft = (new \ReflectionProperty(PluginManager::class, 'vertrauensgeprueft'))->getValue($manager);
            $this->assertTrue($geprueft[self::SLUG] ?? false, 'Addon wurde ohne die Vermerk-Spalten nicht geladen');
        } finally {
            self::$db->exec("ALTER TABLE plugins ADD COLUMN pending_reason VARCHAR(32) NULL DEFAULT NULL AFTER `source`, ADD COLUMN pending_marker VARCHAR(64) NULL DEFAULT NULL AFTER `pending_reason`");
        }
    }
}
