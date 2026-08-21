<?php
// tests/Functional/PluginUninstallTest.php

namespace Tests\Functional;

use PDO;
use Tests\Support\HttpClient;

/**
 * HTTP-Funktionstests für die Addon-Deinstallation (#338, Lücke gefunden als
 * #373).
 *
 * ANLASS. Controller, Ansicht und Datenregister waren gebaut und geprüft - die
 * beiden Routen in public/index.php fehlten aber. Damit lieferte
 * /admin/plugins/uninstall in JEDER Fassung 404: Der einzige Weg im Kern, auf
 * Knopfdruck Addon-Nutzdaten zu entfernen, war tot ausgeliefert. Kein Test
 * berührte den Pfad; ein einziger hätte den 404 in der ersten Zeile gemeldet.
 *
 * Deshalb prüft dieser Test zuerst schlicht, dass es die Routen GIBT - und
 * danach die drei Hürden, die zwischen dem Klick und dem DROP TABLE stehen:
 * Admin-Pflicht, CSRF und der von Hand abgetippte Slug.
 */
class PluginUninstallTest extends FunctionalTestCase {

    private const SLUG = 'uninstall-fixture';
    private const PLUGIN_DEST = __DIR__ . '/../../plugins/' . self::SLUG;
    private const TABELLE = 'plugin_uninstall_fixture_daten';
    private const EINSTELLUNG = 'plugin_uninstall_fixture_option';

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        self::installPluginFixture();
    }

    public static function tearDownAfterClass(): void {
        self::removePluginDir();
        parent::tearDownAfterClass();
    }

    /**
     * Admin-Sitzung UND die Fixture-Daten.
     *
     * Die Reihenfolge ist nicht beliebig: Das Schema entsteht erst, wenn der
     * Testserver die Ersteinrichtung durchlaufen hat - also beim ersten
     * HTTP-Aufruf. Ein Seeding in setUp() liefe gegen eine leere Datenbank
     * ("Table 'settings' doesn't exist").
     */
    private function adminMitFixtureDaten(): HttpClient {
        $admin = $this->authenticatedClient();
        $this->seedPluginData();
        return $admin;
    }

    // ---- Die Routen gibt es überhaupt (#373) ---------------------------

    public function testUninstallFormIsRoutedAndNotA404(): void {
        $admin = $this->adminMitFixtureDaten();

        $antwort = $admin->get('/admin/plugins/uninstall?slug=' . self::SLUG);

        $this->assertNotSame(404, $antwort->statusCode, 'GET /admin/plugins/uninstall ist nicht geroutet');
        $this->assertSame(200, $antwort->statusCode);
        $this->assertStringContainsString(self::TABELLE, $antwort->body, 'Die Vorschau muss die betroffene Tabelle nennen');
    }

    public function testUninstallPostIsRoutedAndNotA404(): void {
        $admin = $this->adminMitFixtureDaten();

        // Ohne CSRF-Token: Die Antwort muss 403 sein - also die Prüfung des
        // Controllers, NICHT der 404 eines fehlenden Eintrags im Router.
        $antwort = $admin->post('/admin/plugins/uninstall', ['slug' => self::SLUG]);

        $this->assertNotSame(404, $antwort->statusCode, 'POST /admin/plugins/uninstall ist nicht geroutet');
        $this->assertSame(403, $antwort->statusCode);
    }

    // ---- Die drei Hürden vor dem Löschen -------------------------------

    /**
     * Beide Routen gehören Administratoren - durchgesetzt im Konstruktor des
     * PluginControllers.
     *
     * Das Token stammt bewusst aus einer Seite, die dieser Benutzer sehen
     * darf. Mit einem leeren Token prüfte der POST nur den CSRF-Zweig, der
     * VOR der Admin-Pflicht antwortet: Nähme jemand das requireAdmin() heraus,
     * bliebe dieser Test grün, obwohl der Weg zum DROP TABLE dann für jeden
     * Redakteur offenstünde.
     */
    public function testUninstallRequiresAdminOnBothRoutes(): void {
        $admin = $this->adminMitFixtureDaten();
        $u = uniqid();
        $redakteur = $this->createAndLoginEditor($admin, "uninst{$u}", "uninst-{$u}@example.com");

        $this->assertSame(403, $redakteur->get('/admin/plugins/uninstall?slug=' . self::SLUG)->statusCode);
        $this->assertSame(403, $redakteur->post('/admin/plugins/uninstall', [
            'csrf_token' => $this->editorCsrfToken($redakteur),
            'slug' => self::SLUG,
            'daten' => 'loeschen',
            'bestaetigung' => self::SLUG,
        ])->statusCode);

        $this->assertTrue($this->tabelleExistiert(), 'Ein abgelehnter Aufruf darf nichts gelöscht haben');
    }

    public function testUnknownSlugIsRejectedBeforeAnythingHappens(): void {
        $admin = $this->adminMitFixtureDaten();

        $antwort = $admin->post('/admin/plugins/uninstall', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => 'dieses-addon-gibt-es-nicht',
            'daten' => 'loeschen',
            'bestaetigung' => 'dieses-addon-gibt-es-nicht',
        ]);

        $this->assertSame('/admin/plugins?error=unknown_plugin', $antwort->location());
        $this->assertTrue($this->tabelleExistiert());
    }

    /**
     * Der abgetippte Slug ist keine Schikane: Ein Häkchen setzt man
     * versehentlich, einen Namen tippt man nicht versehentlich ab - und
     * anders als beim Deaktivieren gibt es hier kein Zurück.
     */
    public function testWrongConfirmationKeepsTheData(): void {
        $admin = $this->adminMitFixtureDaten();

        $antwort = $admin->post('/admin/plugins/uninstall', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'daten' => 'loeschen',
            'bestaetigung' => substr(self::SLUG, 0, -1),   // ein Zeichen zu wenig
        ]);

        $this->assertStringContainsString('error=bestaetigung', (string)$antwort->location());
        $this->assertTrue($this->tabelleExistiert(), 'Bei falscher Bestätigung darf nichts gelöscht werden');
    }

    /**
     * "Daten behalten" ist der Standardweg: Das Addon verschwindet aus der
     * Übersicht, die Tabellen bleiben stehen. Bis v0.7 gab es diese Frage gar
     * nicht - ein Addon verschwand und liess seine Daten liegen, darunter
     * Kontaktanfragen mit Namen und E-Mail-Adressen.
     */
    public function testKeepingDataLeavesTheTableAlone(): void {
        $admin = $this->adminMitFixtureDaten();

        $antwort = $admin->post('/admin/plugins/uninstall', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'daten' => 'behalten',
        ]);

        $this->assertStringContainsString('uninstalled=', (string)$antwort->location());
        $this->assertTrue($this->tabelleExistiert(), '"Daten behalten" darf die Tabelle nicht anfassen');
        $this->assertTrue($this->einstellungExistiert(), '"Daten behalten" darf die Einstellung nicht anfassen');
    }

    /**
     * Und der scharfe Weg: korrekt abgetippt, Tabelle und Einstellung sind
     * weg. Das ist die einzige Stelle im Kern, an der auf Knopfdruck
     * Nutzdaten unwiederbringlich verschwinden.
     */
    public function testTypedConfirmationRemovesTablesAndSettings(): void {
        $admin = $this->adminMitFixtureDaten();

        $antwort = $admin->post('/admin/plugins/uninstall', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'daten' => 'loeschen',
            'bestaetigung' => self::SLUG,
        ]);

        $this->assertStringContainsString('uninstalled=', (string)$antwort->location());
        $this->assertFalse($this->tabelleExistiert(), 'Die Tabelle des Addons muss weg sein');
        $this->assertFalse($this->einstellungExistiert(), 'Die Einstellung des Addons muss weg sein');
    }

    // ---- Hilfsmittel ---------------------------------------------------

    private function db(): PDO {
        return \App\Database::getInstance();
    }

    private function seedPluginData(): void {
        $db = $this->db();
        $db->exec('CREATE TABLE IF NOT EXISTS `' . self::TABELLE . '` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `wert` VARCHAR(50) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $db->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES (?, '1')
             ON DUPLICATE KEY UPDATE setting_value = '1'"
        )->execute([self::EINSTELLUNG]);
    }

    private function tabelleExistiert(): bool {
        $stmt = $this->db()->query("SHOW TABLES LIKE '" . self::TABELLE . "'");
        return $stmt !== false && $stmt->rowCount() > 0;
    }

    private function einstellungExistiert(): bool {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM settings WHERE setting_key = ?');
        $stmt->execute([self::EINSTELLUNG]);
        return (int)$stmt->fetchColumn() > 0;
    }


    private static function installPluginFixture(): void {
        self::removePluginDir();
        mkdir(self::PLUGIN_DEST, 0777, true);

        file_put_contents(self::PLUGIN_DEST . '/plugin.json', json_encode([
            'slug' => self::SLUG,
            'name' => 'Deinstallations-Fixture (Test)',
            'version' => '1.0.0',
            'core_compatibility' => '>=0.1.0-beta.1',
            'core_supported_max' => '9.9',
            'description' => 'Erklärt eine eigene Tabelle und eine Einstellung, damit die Deinstallation (#338/#373) etwas zu löschen hat.',
            'author' => 'tests/Functional/PluginUninstallTest',
            'entry' => 'Plugin.php',
            'owns' => [
                'tables' => [self::TABELLE],
                'settings' => [self::EINSTELLUNG],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        file_put_contents(self::PLUGIN_DEST . '/Plugin.php', <<<'PHP'
<?php
// Test-Fixture für die Addon-Deinstallation (#373). Registriert bewusst nichts -
// geprüft wird der Weg über das deklarative Register in plugin.json.

namespace Plugin\UninstallFixture;

use App\Plugin\HookManager;

class Plugin {
    public function register(HookManager $hooks): void {}
}
PHP);
    }

    private static function removePluginDir(): void {
        if (!is_dir(self::PLUGIN_DEST)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::PLUGIN_DEST, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir(self::PLUGIN_DEST);
    }
}
