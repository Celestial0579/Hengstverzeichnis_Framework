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
 *
 * Dazu (Audit N15, N51, N62):
 * - Der uninstall()-Hook läuft nur für freigegebenen, unveränderten Code.
 *   Die Fixture-Plugin.php schreibt beim Laden und in uninstall() je einen
 *   Marker ins Audit-Log (Kategorie plugin-test) - daran sieht der Test, ob
 *   Addon-Code lief.
 * - Deinstallieren entfernt plugins/<slug> und die plugins-Zeile. Das Fixture
 *   wird deshalb in setUp() jedes Mal neu angelegt.
 * - Das Protokoll erscheint einmalig und escaped unter /admin/plugins.
 */
class PluginUninstallTest extends FunctionalTestCase {

    private const SLUG = 'uninstall-fixture';
    private const PLUGIN_DEST = __DIR__ . '/../../plugins/' . self::SLUG;
    private const TABELLE = 'plugin_uninstall_fixture_daten';
    private const EINSTELLUNG = 'plugin_uninstall_fixture_option';
    /** Trägt den Namen des Addons, steht aber nicht in seinem Register (Audit M30). */
    private const UNREGISTRIERT = 'plugin_uninstall_fixture_x';

    private const MARKER_GELADEN = 'uninstall-fixture: Datei geladen';
    private const MARKER_UNINSTALL = 'uninstall-fixture: uninstall() lief';

    /**
     * Das Fixture liegt vor JEDEM Test frisch da - jede erfolgreiche
     * Deinstallation entfernt es jetzt (Audit N62). Reine Dateisystem-
     * Operation, also auch vor der Ersteinrichtung unkritisch.
     */
    protected function setUp(): void {
        parent::setUp();
        self::installPluginFixture();
    }

    protected function tearDown(): void {
        self::removePluginDir();
        foreach (glob(dirname(self::PLUGIN_DEST) . '/' . self::SLUG . '.entfernt-*') ?: [] as $rest) {
            self::removeTree($rest);
        }
        try {
            $db = \App\Database::getInstance();
            $db->prepare('DELETE FROM plugins WHERE slug = ?')->execute([self::SLUG]);
            $db->exec("DELETE FROM audit_logs WHERE category = 'plugin-test'");
            $db->exec('DROP TABLE IF EXISTS `' . self::UNREGISTRIERT . '`');
        } catch (\Throwable $e) {
            // Schema evtl. noch nicht angelegt (erster Test vor der Ersteinrichtung).
        }
        parent::tearDown();
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
        $this->db()->prepare('DELETE FROM plugins WHERE slug = ?')->execute([self::SLUG]);
        $this->markerZuruecksetzen();
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

        // Audit N62: Code und Verwaltungseintrag sind trotzdem weg.
        $this->assertCodeUndZeileEntfernt($admin);
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

        $this->assertCodeUndZeileEntfernt($admin);
    }

    // ---- Wann läuft Code des Addons? (Audit N15) -----------------------

    /**
     * (a) + (f): Ein nie aktiviertes Addon. Weder die Rückfrage noch das
     * Löschen darf seine Plugin-Datei laden; das Register wird trotzdem
     * abgearbeitet.
     */
    public function testNeverActivatedAddonRunsNoCode(): void {
        $admin = $this->adminMitFixtureDaten();

        $formular = $admin->get('/admin/plugins/uninstall?slug=' . self::SLUG);
        $this->assertSame(200, $formular->statusCode);
        $this->assertStringContainsString('Es wird kein Code des Addons ausgeführt', $formular->body);
        $this->assertStringContainsString('nie freigegeben', $formular->body);
        $this->assertSame(0, $this->marker(self::MARKER_GELADEN), 'Die Rückfrage darf keinen Addon-Code laden');

        $this->loeschen($admin);

        $this->assertSame(0, $this->marker(self::MARKER_GELADEN), 'Ein nie aktiviertes Addon darf beim Löschen nicht geladen werden');
        $this->assertSame(0, $this->marker(self::MARKER_UNINSTALL));
        $this->assertFalse($this->tabelleExistiert(), 'Das Register wird trotzdem abgearbeitet');
        $this->assertStringContainsString('NICHT ausgeführt', $admin->get('/admin/plugins')->body);
    }

    /** (b) + (f): aktiviert und unverändert - der Hook läuft. */
    public function testActivatedUnchangedAddonRunsUninstallHook(): void {
        $admin = $this->adminMitFixtureDaten();
        $this->aktivieren($admin, true);

        $formular = $admin->get('/admin/plugins/uninstall?slug=' . self::SLUG);
        $this->assertStringContainsString('wird Code des Addons ausgeführt', $formular->body);

        $this->loeschen($admin);

        $this->assertSame(1, $this->marker(self::MARKER_UNINSTALL), 'uninstall() eines freigegebenen Addons muss laufen');
    }

    /** (c): aktiviert, danach Code bei gleicher Version geändert - kein Hook. */
    public function testActivatedThenChangedAddonRunsNoHook(): void {
        $admin = $this->adminMitFixtureDaten();
        $this->aktivieren($admin, true);
        file_put_contents(self::PLUGIN_DEST . '/Plugin.php', "\n// nachträglich geändert\n", FILE_APPEND);
        $this->markerZuruecksetzen();

        $formular = $admin->get('/admin/plugins/uninstall?slug=' . self::SLUG);
        $this->assertStringContainsString('Es wird kein Code des Addons ausgeführt', $formular->body);

        $this->loeschen($admin);

        $this->assertSame(0, $this->marker(self::MARKER_UNINSTALL), 'Veränderter Code darf bei der Deinstallation nicht laufen');
        $this->assertSame(0, $this->marker(self::MARKER_GELADEN), 'Veränderter Code darf gar nicht geladen werden');
    }

    /** (d): aktiviert, deaktiviert, unverändert - die Baseline trägt, der Hook läuft. */
    public function testDeactivatedUnchangedAddonRunsUninstallHook(): void {
        $admin = $this->adminMitFixtureDaten();
        $this->aktivieren($admin, true);
        $this->aktivieren($admin, false);

        $formular = $admin->get('/admin/plugins/uninstall?slug=' . self::SLUG);
        $this->assertStringContainsString('wird Code des Addons ausgeführt', $formular->body);

        $this->loeschen($admin);

        $this->assertSame(1, $this->marker(self::MARKER_UNINSTALL));
    }

    /**
     * (e): der Umgehungsversuch. Deaktiviert, dann Code geändert UND Version
     * erhöht UND Release-Herkunft eingetragen - beim Laden griffe die
     * #212-Übernahme, bei der Deinstallation eines deaktivierten Addons nicht.
     */
    public function testDeactivatedChangedAddonWithVersionBumpRunsNoHook(): void {
        $admin = $this->adminMitFixtureDaten();
        $this->aktivieren($admin, true);
        $this->aktivieren($admin, false);

        file_put_contents(self::PLUGIN_DEST . '/Plugin.php', "\n// untergeschoben\n", FILE_APPEND);
        self::writeManifest('9.9.9');
        $this->db()->prepare("UPDATE plugins SET source = 'Celestial0579/Hengstverzeichnis_Addons@v9.9.9' WHERE slug = ?")
            ->execute([self::SLUG]);
        $this->markerZuruecksetzen();

        $formular = $admin->get('/admin/plugins/uninstall?slug=' . self::SLUG);
        $this->assertStringContainsString('Es wird kein Code des Addons ausgeführt', $formular->body);

        $this->loeschen($admin);

        $this->assertSame(0, $this->marker(self::MARKER_UNINSTALL), 'Eine erhöhte Version darf bei deaktivierten Addons keinen Code freischalten');
        $this->assertSame(0, $this->marker(self::MARKER_GELADEN));
    }

    // ---- Das Protokoll (Audit N51) -------------------------------------

    public function testProtocolIsShownOnceAfterUninstall(): void {
        $admin = $this->adminMitFixtureDaten();
        $this->loeschen($admin);

        $erste = $admin->get('/admin/plugins')->body;
        $this->assertStringContainsString('deinstalliert', $erste);
        $this->assertStringContainsString('Tabelle ' . self::TABELLE . ' entfernt.', $erste);
        $this->assertStringContainsString('HINWEIS: Keine Sicherung eingerichtet', $erste);
        $this->assertStringContainsString('Addon-Code (plugins/' . self::SLUG . ') entfernt.', $erste);

        $zweite = $admin->get('/admin/plugins')->body;
        $this->assertStringNotContainsString('Tabelle ' . self::TABELLE . ' entfernt.', $zweite, 'Das Protokoll erscheint nur einmal');
    }

    public function testKeepingDataShowsProtocol(): void {
        $admin = $this->adminMitFixtureDaten();
        $admin->post('/admin/plugins/uninstall', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'daten' => 'behalten',
        ]);

        $this->assertStringContainsString('Daten behalten', $admin->get('/admin/plugins')->body);
    }

    public function testProtocolIsEscaped(): void {
        self::installPluginFixture(['<i>x</i>']);
        $admin = $this->adminMitFixtureDaten();
        $this->loeschen($admin);

        $body = $admin->get('/admin/plugins')->body;
        $this->assertStringContainsString('&lt;i&gt;x&lt;/i&gt;', $body);
        $this->assertStringNotContainsString('<i>x</i>', $body);
        $this->assertStringContainsString('Bitte die markierten Einträge prüfen', $body, 'Ein "NICHT gelöscht" ist eine Warnung');
    }

    public function testFallbackEscapesSlugParameter(): void {
        $admin = $this->authenticatedClient();

        $body = $admin->get('/admin/plugins?uninstalled=' . urlencode('<script>alert(1)</script>'))->body;

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
    }

    // ---- Datenregister-Lücken (Audit M30) ------------------------------

    /**
     * Eine Tabelle mit dem Namen des Addons, die nicht im Register steht:
     * Die Rückfrage warnt, statt "rückstandsfrei" zu versprechen, und nach
     * "Daten löschen" bleibt sie stehen - mit Vermerk im Protokoll.
     */
    public function testUnregistrierteTabelleWirdGemeldetStattRueckstandsfrei(): void {
        $admin = $this->adminMitFixtureDaten();
        $this->db()->exec('CREATE TABLE IF NOT EXISTS `' . self::UNREGISTRIERT . '` (id INT PRIMARY KEY) ENGINE=InnoDB');

        $formular = $admin->get('/admin/plugins/uninstall?slug=' . self::SLUG);
        $this->assertSame(200, $formular->statusCode);
        $this->assertStringContainsString(self::UNREGISTRIERT, $formular->body);
        $this->assertStringContainsString('NICHT entfernt', $formular->body);
        $this->assertStringNotContainsString('rückstandsfrei', $formular->body);

        $this->loeschen($admin);

        $stmt = $this->db()->query("SHOW TABLES LIKE '" . self::UNREGISTRIERT . "'");
        $this->assertSame(1, $stmt->rowCount(), 'Ein Namensmuster ist keine Eigentumserklärung - die Tabelle bleibt');
        $this->assertFalse($this->tabelleExistiert(), 'Die registrierte Tabelle geht trotzdem');
        $log = (string)$this->db()->query(
            "SELECT details FROM audit_logs WHERE action = 'Addon deinstalliert (Daten gelöscht)' ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
        $this->assertStringContainsString('NICHT gelöscht (nicht im Datenregister des Addons): Tabelle ' . self::UNREGISTRIERT, $log);
        $this->assertStringContainsString(
            'NICHT gelöscht (nicht im Datenregister des Addons): Tabelle ' . self::UNREGISTRIERT,
            $admin->get('/admin/plugins')->body
        );
    }

    /**
     * Ohne Register und ohne Treffer: keine absolute Zusage mehr, sondern
     * die eingeschränkte Aussage - und der Radio-Text nennt keine Zahl.
     */
    public function testOhneRegisterKeineZusageRueckstandsfrei(): void {
        self::installPluginFixture([], false);
        $admin = $this->authenticatedClient();
        $this->ohneFixtureTabellen();

        $body = $admin->get('/admin/plugins/uninstall?slug=' . self::SLUG)->body;

        $this->assertStringNotContainsString('rückstandsfrei', $body);
        $this->assertStringContainsString('Das Addon hat kein Datenregister hinterlegt', $body);
        $this->assertStringContainsString('Registrierte Bestandteile: keine', $body);
    }

    /** Ein leeres, aber vorhandenes Register ist eine Aussage des Addons. */
    public function testLeeresRegisterIstEineErklaerung(): void {
        self::installPluginFixture([], true, true);
        $admin = $this->authenticatedClient();
        $this->ohneFixtureTabellen();

        $body = $admin->get('/admin/plugins/uninstall?slug=' . self::SLUG)->body;

        $this->assertStringContainsString('Das Addon erklärt in seinem Datenregister, keine eigenen', $body);
        $this->assertStringNotContainsString('rückstandsfrei', $body);
    }

    // ---- Hilfsmittel ---------------------------------------------------

    /** Frühere Tests (etwa "Daten behalten") lassen die Fixture-Tabelle stehen. */
    private function ohneFixtureTabellen(): void {
        $this->db()->exec('DROP TABLE IF EXISTS `' . self::TABELLE . '`');
        $this->db()->exec('DROP TABLE IF EXISTS `' . self::UNREGISTRIERT . '`');
    }

    private function loeschen(HttpClient $admin): void {
        $antwort = $admin->post('/admin/plugins/uninstall', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'daten' => 'loeschen',
            'bestaetigung' => self::SLUG,
        ]);
        $this->assertStringContainsString('uninstalled=', (string)$antwort->location());
    }

    private function aktivieren(HttpClient $admin, bool $an): void {
        $antwort = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => $an ? '1' : '',
        ]);
        $this->assertSame('/admin/plugins?success=1', $antwort->location());
    }

    private function markerZuruecksetzen(): void {
        $this->db()->exec("DELETE FROM audit_logs WHERE category = 'plugin-test'");
    }

    private function marker(string $aktion): int {
        $stmt = $this->db()->prepare("SELECT COUNT(*) FROM audit_logs WHERE category = 'plugin-test' AND action = ?");
        $stmt->execute([$aktion]);
        return (int)$stmt->fetchColumn();
    }

    private function assertCodeUndZeileEntfernt(HttpClient $admin): void {
        $this->assertDirectoryDoesNotExist(self::PLUGIN_DEST, 'Deinstallieren muss plugins/<slug> entfernen');
        $this->assertSame([], glob(dirname(self::PLUGIN_DEST) . '/' . self::SLUG . '.entfernt-*') ?: []);
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM plugins WHERE slug = ?');
        $stmt->execute([self::SLUG]);
        $this->assertSame(0, (int)$stmt->fetchColumn(), 'Die plugins-Zeile muss entfernt sein');

        // Erster Aufruf zeigt das Protokoll (mit Slug), der zweite die Übersicht.
        $admin->get('/admin/plugins');
        $this->assertStringNotContainsString(self::SLUG, $admin->get('/admin/plugins')->body, 'Das Addon darf nicht mehr in der Übersicht stehen');
    }

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


    /**
     * @param string[] $zusatzTabellen weitere owns.tables-Einträge (etwa ungültige)
     * @param bool $mitRegister false = Manifest ganz ohne "owns"
     * @param bool $leeresRegister true = "owns": {} (erklärt, nichts anzulegen)
     */
    private static function installPluginFixture(array $zusatzTabellen = [], bool $mitRegister = true, bool $leeresRegister = false): void {
        self::removePluginDir();
        mkdir(self::PLUGIN_DEST, 0777, true);

        self::writeManifest('1.0.0', $zusatzTabellen, $mitRegister, $leeresRegister);

        file_put_contents(self::PLUGIN_DEST . '/Plugin.php', <<<'PHP'
<?php
// Test-Fixture für die Addon-Deinstallation (#373, Audit N15). Registriert
// nichts - geprüft wird der Weg über das deklarative Register in plugin.json.
// Die beiden Audit-Marker zeigen, ob Code des Addons lief.

namespace Plugin\UninstallFixture;

use App\Plugin\HookManager;

\App\Service\AuditLogger::log('uninstall-fixture: Datei geladen', 'plugin-test');

class Plugin {
    public function register(HookManager $hooks): void {}

    public function uninstall(): void {
        \App\Service\AuditLogger::log('uninstall-fixture: uninstall() lief', 'plugin-test');
    }
}
PHP);
    }

    /** @param string[] $zusatzTabellen */
    private static function writeManifest(string $version, array $zusatzTabellen = [], bool $mitRegister = true, bool $leeresRegister = false): void {
        $manifest = [
            'slug' => self::SLUG,
            'name' => 'Deinstallations-Fixture (Test)',
            'version' => $version,
            'core_compatibility' => '>=0.1.0-beta.1',
            'core_supported_max' => '9.9',
            'description' => 'Erklärt eine eigene Tabelle und eine Einstellung, damit die Deinstallation (#338/#373) etwas zu löschen hat.',
            'author' => 'tests/Functional/PluginUninstallTest',
            'entry' => 'Plugin.php',
            'owns' => [
                'tables' => array_merge([self::TABELLE], $zusatzTabellen),
                'settings' => [self::EINSTELLUNG],
            ],
        ];
        if (!$mitRegister) {
            unset($manifest['owns']);
        } elseif ($leeresRegister) {
            $manifest['owns'] = new \stdClass();
        }
        file_put_contents(self::PLUGIN_DEST . '/plugin.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private static function removePluginDir(): void {
        self::removeTree(self::PLUGIN_DEST);
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
