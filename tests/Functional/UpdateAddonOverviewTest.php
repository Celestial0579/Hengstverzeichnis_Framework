<?php
// tests/Functional/UpdateAddonOverviewTest.php

namespace Tests\Functional;

use App\Database;
use App\Security\Totp;
use Tests\Support\AuxiliaryServer;
use Tests\Support\HttpClient;

/**
 * HTTP-Funktionstests für "Addons mitdenken" auf der Update-Seite (#197,
 * Stufe 1): Addon-Übersicht (installiert vs. Katalog), Dashboard-Badge und
 * die Warnung vor einem Kern-Update, dessen ZIELversion ein aktives Addon
 * nicht unterstützt.
 *
 * Für den Zielversions-Fall startet die Klasse eine zweite App-Instanz,
 * deren UPDATE_RELEASES_URL auf ein statisches Release-Fixture zeigt, das
 * sie selbst ausliefert (public/, von php -S direkt serviert) - die
 * Release-"Prüfung" läuft damit komplett ohne GitHub/Netz.
 */
class UpdateAddonOverviewTest extends FunctionalTestCase {

    private const APP_PORT = 8771;
    private const FIXTURE = 'releases-fixture-addon-overview.json';
    private const ADDON_FIXTURE = 'releases-fixture-addon-overview-addon-releases.json';
    private const SLUG = 'update-overview-testaddon';

    private static ?AuxiliaryServer $app = null;

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        self::$app = new AuxiliaryServer(
            self::APP_PORT,
            __DIR__ . '/../../public',
            null,
            [
                'APP_URL' => 'http://127.0.0.1:' . self::APP_PORT,
                // Das Fixture liefert der GETEILTE Testserver aus, nicht die
                // eigene Instanz: php -S ist ein Single-Worker - eine Instanz,
                // die während eines Requests ihre eigene URL abruft, blockiert
                // sich selbst (beobachtet als 10s-Timeout).
                'UPDATE_RELEASES_URL' => \Tests\Support\PhpBuiltInServer::baseUrl() . '/' . self::FIXTURE,
                // Addon-Releases-Liste ebenfalls als Fixture vom geteilten
                // Server - der Verweigerungsfall (#212) braucht eine leere,
                // aber ERREICHBARE Liste, damit gezielt "kein Release zur
                // Linie" geprüft wird und nicht ein Netzfehler.
                'ADDON_RELEASES_URL' => \Tests\Support\PhpBuiltInServer::baseUrl() . '/' . self::ADDON_FIXTURE,
            ]
        );
        self::$app->start();
    }

    public static function tearDownAfterClass(): void {
        self::$app?->stop();
        self::$app = null;
        parent::tearDownAfterClass();
    }

    protected function tearDown(): void {
        // Aufräumen ist Pflicht: Plugin-Verzeichnis, Aktivierung und
        // Katalog-Cache wirken sonst in andere Tests hinein.
        $this->removePluginDir();
        @unlink(__DIR__ . '/../../public/' . self::FIXTURE);
        @unlink(__DIR__ . '/../../public/' . self::ADDON_FIXTURE);
        try {
            $db = Database::getInstance();
            $db->prepare("DELETE FROM plugins WHERE slug = ?")->execute([self::SLUG]);
            $db->query("UPDATE addon_repos SET cached_catalog_json = NULL, cached_at = NULL WHERE is_official = 1");
            // Und die Backup-Konfiguration aus backupKonfigurieren(): Die
            // Testdatenbank ist ueber den ganzen PHPUnit-Prozess geteilt, und
            // UpdateAdminTest prueft ausdruecklich den Fall OHNE eingerichtetes
            // Backup - bleibt sie stehen, faellt dort der falsche Zweig.
            $db->query(
                "DELETE FROM settings WHERE setting_key IN ('backup_enabled', 'backup_s3_endpoint',
                 'backup_s3_bucket', 'backup_s3_access_key', 'backup_s3_secret_key')"
            );
        } catch (\Throwable $e) {
            // DB weg = nichts zu bereinigen
        }
        parent::tearDown();
    }

    public function testAddonSectionShowsEmptyStateWithoutAddons(): void {
        $admin = $this->authenticatedClient();

        $page = $admin->get('/admin/updates');
        $this->assertSame(200, $page->statusCode);
        $this->assertStringContainsString('🧩 Addons', $page->body);
        $this->assertStringContainsString('Keine Addons installiert', $page->body);
    }

    public function testAddonRowShowsCatalogUpdateAndDashboardBadgeCounts(): void {
        $admin = $this->authenticatedClient();
        $this->createPluginDir(['core_compatibility' => '>=0.1.0-beta.1']);
        $this->seedOfficialCatalog('1.1.0');

        $cachedAtBefore = $this->officialCachedAt();

        $page = $admin->get('/admin/updates');
        $this->assertSame(200, $page->statusCode);
        $this->assertStringContainsString(self::SLUG, $page->body);
        $this->assertStringContainsString('<td style="padding: 0.4rem 0.5rem;">1.0.0</td>', $page->body);
        $this->assertStringContainsString('<strong>1.1.0</strong>', $page->body);
        $this->assertStringContainsString('>Update</span>', $page->body);
        $this->assertStringContainsString('Katalog-Stand:', $page->body);

        // Die Update-Seite fragt GitHub gar nicht mehr (#319): Der Abruf lädt
        // und entpackt das komplette Repo-Tarball und hing damit vor jedem
        // Seitenaufbau. Er passiert jetzt nur noch im nächtlichen Lauf und auf
        // ausdrücklichen Klick (?refresh=1). Erkennbar daran, dass cached_at
        // unverändert bleibt - ein Abruf würde es auf NOW() setzen.
        $this->assertSame($cachedAtBefore, $this->officialCachedAt());

        // Dashboard zählt das offene Addon-Update an der Update-Kachel
        // (Badge-Kommentar wird nur bei Zähler > 0 gerendert).
        $dashboard = $admin->get('/admin');
        $this->assertStringContainsString('Zähler offener ADDON-Updates', $dashboard->body);
        $this->assertMatchesRegularExpression('/Zähler offener ADDON-Updates.*?>\s*1\s*<\/span>/s', $dashboard->body);
    }

    public function testWarnsBeforeCoreUpdateWhoseTargetDisablesAnActiveAddon(): void {
        // Aktives Addon, das höchstens Kern 0.3 unterstützt - das
        // Release-Fixture bietet 9.9.9 an.
        $this->createPluginDir([
            'core_compatibility' => '>=0.1.0-beta.1',
            'core_supported_max' => '0.3',
        ]);
        $this->enablePlugin();
        $this->writeReleasesFixture('9.9.9');

        $admin = $this->fixtureInstanceAdmin();
        $page = $admin->get('/admin/updates?check=1');

        $this->assertSame(200, $page->statusCode);
        $this->assertStringContainsString('Neue Version verfügbar: <strong>9.9.9</strong>', $page->body);
        // Die Warnung steht VOR dem Update-Knopf und nennt Addon + Grund.
        $this->assertStringContainsString('werden folgende aktive Addons deaktiviert', $page->body);
        $this->assertStringContainsString(self::SLUG, $page->body);
        $this->assertStringContainsString('höchstens', $page->body);
        // Und die Tabelle prüft gegen die ZIELversion, nicht nur die laufende.
        $this->assertStringContainsString('kompatibel mit Ziel 9.9.9?', $page->body);
    }

    /**
     * Traegt eine formal vollstaendige Backup-Konfiguration ein.
     *
     * Es wird nichts gesichert - die Addon-Sperre greift vor
     * performUpdate(). Gebraucht wird nur, dass
     * UpdateService::backupHindernis() zufrieden ist.
     */
    private function backupKonfigurieren(): void {
        $db = \App\Database::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        foreach ([
            'backup_enabled' => '1',
            'backup_s3_endpoint' => 'http://127.0.0.1:1/nicht-benutzt',
            'backup_s3_bucket' => 'test',
            'backup_s3_access_key' => 'AKIDEXAMPLE',
            'backup_s3_secret_key' => \App\Security\Crypto::encrypt('test-secret'),
        ] as $schluessel => $wert) {
            $stmt->execute([$schluessel, $wert]);
        }
    }

    /**
     * Dieselbe Sperre, aber am ENDPUNKT statt in der Ansicht (#375).
     *
     * Der Kommentar an UpdateController::run() begründet ausdrücklich, warum
     * die Prüfung dort steht und nicht nur in der View: "Ein Eingabefeld im
     * Formular ist keine Prüfung - ein direkter POST kommt ohne es aus."
     * Genau dieser direkte POST wurde von keinem Test ausgeführt. Der einzige
     * Test, den der Commit mitbrachte, prüfte die reinen Entscheidungs-
     * funktionen von UpdateService, nie den Controller - und ein leerer
     * Zielversions-String hätte die Sperre lautlos entfallen lassen.
     *
     * Der Fall "richtig abgetippt" ist bewusst NICHT dabei: Er liefe in
     * performUpdate() weiter und würde versuchen, die laufende
     * Testinstallation zu überschreiben. Geprüft wird stattdessen, dass die
     * Sperre GREIFT - dass sie sich öffnen lässt, deckt
     * testTypedTargetVersionPassesTheAddonGate() unten ab.
     */
    public function testDirectPostIsRefusedWhenAnActiveAddonCannotFollow(): void {
        // Das Pflicht-Backup vorkonfigurieren: Seit es VOR der Release-Abfrage
        // geprueft wird, bricht /admin/updates/run sonst schon dort ab, und
        // dieser Test kaeme nie bis zur Addon-Sperre - er wuerde gruen
        // aussehen, wenn die Sperre gar nicht mehr existierte.
        $this->backupKonfigurieren();

        $this->createPluginDir([
            'core_compatibility' => '>=0.1.0-beta.1',
            'core_supported_max' => '0.3',
        ]);
        $this->enablePlugin();
        $this->writeReleasesFixture('9.9.9');

        $admin = $this->fixtureInstanceAdmin();
        $seite = $admin->get('/admin/updates?check=1');
        $token = $seite->formField('csrf_token') ?? '';
        $this->assertNotSame('', $token, 'Ohne Token prüft der POST nur den CSRF-Zweig');

        // 1. Ganz ohne Bestätigungsfeld - der direkte POST aus dem Kommentar.
        $ohne = $admin->post('/admin/updates/run', ['csrf_token' => $token]);
        $this->assertStringContainsString(
            'Nicht aktualisiert',
            urldecode((string)$ohne->location()),
            'Ein POST ohne abgetippte Zielversion muss abgelehnt werden'
        );
        $this->assertStringContainsString(self::SLUG, urldecode((string)$ohne->location()), 'Die Meldung muss das betroffene Addon nennen');

        // 2. Mit falscher Zielversion - ein Feld auszufüllen genügt nicht.
        $falsch = $admin->post('/admin/updates/run', [
            'csrf_token' => $admin->get('/admin/updates')->formField('csrf_token') ?? $token,
            'bestaetigung' => '0.0.0',
        ]);
        $this->assertStringContainsString('Nicht aktualisiert', urldecode((string)$falsch->location()));
    }

    /**
     * Und die Gegenprobe: Mit der korrekt abgetippten Zielversion greift die
     * Addon-Sperre NICHT mehr.
     *
     * Der Lauf endet danach trotzdem mit einem Fehler - das Release-Fixture
     * verweist auf https://example.invalid, der Download scheitert also, und
     * genau deshalb ist der Test hier ungefährlich: Er kommt an der Sperre
     * vorbei, ohne die laufende Installation anzufassen. Geprüft wird die
     * Unterscheidung der beiden Meldungen, nicht der Erfolg.
     */
    public function testTypedTargetVersionPassesTheAddonGate(): void {
        $this->createPluginDir([
            'core_compatibility' => '>=0.1.0-beta.1',
            'core_supported_max' => '0.3',
        ]);
        $this->enablePlugin();
        $this->writeReleasesFixture('9.9.9');

        $admin = $this->fixtureInstanceAdmin();
        $seite = $admin->get('/admin/updates?check=1');

        $antwort = $admin->post('/admin/updates/run', [
            'csrf_token' => $seite->formField('csrf_token') ?? '',
            'bestaetigung' => '9.9.9',
        ]);

        $ziel = urldecode((string)$antwort->location());
        $this->assertStringNotContainsString(
            'Nicht aktualisiert',
            $ziel,
            'Mit korrekt abgetippter Zielversion darf die Addon-Sperre nicht mehr greifen'
        );
        // Der Lauf scheitert danach am unerreichbaren Download - das ist der
        // erwartete Ausgang und der Beleg, dass die Sperre passiert wurde.
        $this->assertStringContainsString('error=', $ziel);
    }

    public function testAddonUpdateRequiresCsrfToken(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->post('/admin/updates/addon', ['slug' => 'irrelevant']);
        $this->assertSame(403, $response->statusCode);
    }

    /**
     * Fremd-Quellen und manuell kopierte Addons (keine plugins.source-Zeile
     * auf das offizielle Repo) lehnt der Server ab, BEVOR irgendein
     * Netzwerkzugriff passiert (#197, Stufe 2).
     */
    public function testAddonUpdateRefusesNonOfficialSource(): void {
        $admin = $this->authenticatedClient();
        $this->createPluginDir([]);

        $response = $admin->post('/admin/updates/addon', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
        ]);

        $location = (string)$response->location();
        $this->assertStringStartsWith('/admin/updates?addon_error=', $location);
        $this->assertStringContainsString('offiziellen', urldecode($location));
    }

    /**
     * Verweigerungsfall aus #212: Ein offizielles Addon MIT source-Pin, aber
     * die Releases-Liste der Kern-Linie ist leer - das Update muss mit einer
     * sprechenden Meldung abgelehnt werden, statt (wie früher) auf den
     * veränderlichen Branch-HEAD des Addons-Repos zurückzufallen. Läuft
     * gegen die Fixture-Instanz, deren ADDON_RELEASES_URL eine leere, aber
     * erreichbare Liste liefert - so ist sichergestellt, dass wirklich der
     * "kein Release"-Zweig greift und kein Netz-/GitHub-Zugriff passiert.
     */
    public function testAddonUpdateRefusesWhenNoReleaseForCoreLineExists(): void {
        $this->createPluginDir([]);

        // source auf das offizielle Repo pinnen (wie es der Store beim
        // Installieren täte) - erst damit ist das Addon überhaupt
        // update-berechtigt und der Release-Check wird erreicht.
        $db = Database::getInstance();
        $official = $db->query("SELECT owner, repo FROM addon_repos WHERE is_official = 1 LIMIT 1")->fetch();
        $this->assertNotFalse($official, 'Seed des offiziellen Addon-Repos muss vorhanden sein');
        $stmt = $db->prepare(
            "INSERT INTO plugins (slug, source) VALUES (?, ?) ON DUPLICATE KEY UPDATE source = VALUES(source)"
        );
        $stmt->execute([self::SLUG, "{$official['owner']}/{$official['repo']}"]);

        // Leere, gültige Releases-Liste: kein Release zu KEINER Linie.
        file_put_contents(__DIR__ . '/../../public/' . self::ADDON_FIXTURE, '[]');

        $admin = $this->fixtureInstanceAdmin();
        $response = $admin->post('/admin/updates/addon', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
        ]);

        $location = (string)$response->location();
        $this->assertStringStartsWith('/admin/updates?addon_error=', $location);
        $this->assertStringContainsString('kein Addon-Release', urldecode($location));
        // Und der installierte Stand ist unangetastet geblieben.
        $manifest = json_decode((string)file_get_contents($this->pluginDir() . '/plugin.json'), true);
        $this->assertSame('1.0.0', $manifest['version']);
    }

    /**
     * Gegenstück zum TTL-Fall oben: Schlägt ein Refresh fehl, muss die Seite
     * trotzdem mit 200 antworten und den (alten) Stand weiter anzeigen - ein
     * alter Katalog ist besser als eine leere Tabelle oder ein Fehler.
     *
     * Seit #319 löst der blosse Seitenaufruf keinen Refresh mehr aus (er lud
     * und entpackte dafür das komplette Repo-Tarball, synchron vor dem ersten
     * Byte HTML). Der Fall wird deshalb über den ausdrücklichen Knopf
     * `?refresh=1` gefahren - dort, wo der Abruf jetzt stattfindet. Ein
     * abgelaufener Cache OHNE diesen Klick darf die Seite ebenso wenig stören,
     * und auch das steht unten.
     *
     * Der Fehlschlag wird bewusst OHNE Netzwerkzugriff erzeugt: Ein
     * syntaktisch ungültiger Owner scheitert schon an der Validierung in
     * GithubAddonRepository (isValidOwnerOrRepo), bevor eine Verbindung
     * aufgebaut wird. Ein Test, der auf ein tatsächlich unerreichbares GitHub
     * angewiesen wäre, würde je nach Umgebung mal laufen und mal minutenlang
     * in einen Timeout hängen.
     */
    public function testUpdatePageStaysUsableWhenStaleCatalogCannotBeRefreshed(): void {
        $admin = $this->authenticatedClient();
        $this->createPluginDir(['core_compatibility' => '>=0.1.0-beta.1']);
        $this->seedOfficialCatalog('1.1.0');
        $this->markOfficialCatalogStale();

        $db = Database::getInstance();
        $original = $db->query("SELECT owner, repo FROM addon_repos WHERE is_official = 1 LIMIT 1")->fetch();
        $this->assertNotFalse($original, 'Seed des offiziellen Addon-Repos muss vorhanden sein');
        $db->exec("UPDATE addon_repos SET owner = 'un gueltig' WHERE is_official = 1");

        try {
            // Ohne Klick: kein Abruf, kein Problem.
            $ohneKlick = $admin->get('/admin/updates');
            $this->assertSame(200, $ohneKlick->statusCode);
            $this->assertStringContainsString('<strong>1.1.0</strong>', $ohneKlick->body);

            $page = $admin->get('/admin/updates?refresh=1');

            $this->assertSame(200, $page->statusCode);
            $this->assertStringContainsString('🧩 Addons', $page->body);
            $this->assertStringContainsString(self::SLUG, $page->body);
            // Der abgelaufene Cache bleibt erhalten und wird weiter angezeigt.
            $this->assertStringContainsString('Katalog-Stand:', $page->body);
            $this->assertStringContainsString('<strong>1.1.0</strong>', $page->body);

            $stmt = $db->query("SELECT cached_catalog_json FROM addon_repos WHERE is_official = 1 LIMIT 1");
            $this->assertStringContainsString(
                '1.1.0',
                (string)$stmt->fetchColumn(),
                'Ein fehlgeschlagener Refresh darf den vorhandenen Cache nicht leeren'
            );
        } finally {
            $stmt = $db->prepare("UPDATE addon_repos SET owner = ?, repo = ? WHERE is_official = 1");
            $stmt->execute([$original['owner'], $original['repo']]);
        }
    }

    // ---- Helfer --------------------------------------------------------

    private function pluginDir(): string {
        return __DIR__ . '/../../plugins/' . self::SLUG;
    }

    /** @param array<string, string> $manifestExtra */
    private function createPluginDir(array $manifestExtra): void {
        $dir = $this->pluginDir();
        @mkdir($dir, 0755, true);
        $manifest = array_merge([
            'slug' => self::SLUG,
            'name' => 'Update-Übersicht Testaddon',
            'version' => '1.0.0',
            // Seit Stufe 2 Pflicht - Basis-Fixture bewusst weit in der
            // Zukunft, damit die Fälle unten den jeweils relevanten Aspekt
            // testen (der Zielversions-Fall überschreibt mit '0.3').
            'core_supported_max' => '9.9',
        ], $manifestExtra);
        file_put_contents($dir . '/plugin.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($dir . '/Plugin.php', "<?php\nnamespace Plugin\\UpdateOverviewTestaddon;\nclass Plugin { public function register(\$hooks): void {} }\n");
    }

    private function removePluginDir(): void {
        $dir = $this->pluginDir();
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    private function enablePlugin(): void {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            "INSERT INTO plugins (slug, enabled, installed_version, content_hash)
             VALUES (?, 1, '1.0.0', 'test-hash')
             ON DUPLICATE KEY UPDATE enabled = 1"
        );
        $stmt->execute([self::SLUG]);
    }

    private function seedOfficialCatalog(string $availableVersion): void {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            "UPDATE addon_repos SET cached_catalog_json = ?, cached_at = NOW() WHERE is_official = 1"
        );
        $stmt->execute([json_encode([[
            'slug' => self::SLUG,
            'name' => 'Update-Übersicht Testaddon',
            'version' => $availableVersion,
            'core_compatibility' => '>=0.1.0-beta.1',
            'core_supported_max' => '9.9',
            'description' => '',
            'author' => '',
            'hooks' => [],
        ]])]);
    }

    private function officialCachedAt(): ?string {
        $value = Database::getInstance()
            ->query("SELECT cached_at FROM addon_repos WHERE is_official = 1 LIMIT 1")
            ->fetchColumn();
        return is_string($value) ? $value : null;
    }

    /** Setzt cached_at über die 15-Minuten-TTL hinaus zurück. */
    private function markOfficialCatalogStale(): void {
        Database::getInstance()->exec(
            "UPDATE addon_repos SET cached_at = DATE_SUB(NOW(), INTERVAL 20 MINUTE) WHERE is_official = 1"
        );
    }

    private function writeReleasesFixture(string $version): void {
        file_put_contents(__DIR__ . '/../../public/' . self::FIXTURE, json_encode([[
            'tag_name' => 'v' . $version,
            'draft' => false,
            'prerelease' => false,
            'html_url' => 'https://example.invalid/releases/v' . $version,
            'assets' => [[
                'name' => 'hengstverzeichnis-framework-' . $version . '.zip',
                'browser_download_url' => 'https://example.invalid/download/' . $version . '.zip',
            ]],
        ]]));
    }

    /**
     * Admin-Login gegen die Fixture-Instanz (gleiche DB/Konten wie der
     * geteilte Server) - Muster aus UpdateInPlaceDisabledTest.
     */
    private function fixtureInstanceAdmin(): HttpClient {
        $this->authenticatedClient();
        self::resetTotpReplayGuard(self::$adminEmail);

        $client = new HttpClient(self::$app->baseUrl());
        $loginPage = $client->get('/login');
        $client->post('/login', [
            'csrf_token' => $loginPage->formField('csrf_token') ?? '',
            'kennung' => self::$adminEmail,
            'password' => self::$adminPassword,
        ]);
        $verifyPage = $client->get('/login/2fa');
        $verify = $client->post('/login/2fa', [
            'csrf_token' => $verifyPage->formField('csrf_token') ?? '',
            'totp_code' => Totp::getCode(self::$totpSecret),
        ]);
        self::assertSame(302, $verify->statusCode, "2FA-Login gegen die Fixture-Instanz sollte klappen, Body: {$verify->body}");

        return $client;
    }
}
