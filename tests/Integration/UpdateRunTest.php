<?php
// tests/Integration/UpdateRunTest.php

namespace Tests\Integration;

use App\Database;
use App\Security\Crypto;
use App\Service\AddonUpdateService;
use App\Service\Maintenance;
use App\Service\Scheduler;
use App\Service\UpdateService;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeReleaseServer;
use Tests\Support\FakeS3Server;

/**
 * Fährt App\Service\UpdateService::performUpdate() vollständig durch - mit
 * echtem Pflicht-Backup gegen den Fake-S3-Server, echtem Download des
 * Release-Zips, echter SHA256-Prüfsummenprüfung und echtem Anwenden des
 * Archivs. Bis #290 gab es dafür keinen einzigen Test: UpdateAdminTest deckt
 * nur die Ablehnungsfälle ab (fehlendes CSRF-Token, fehlendes Backup), der
 * Erfolgspfad und alles danach liefen ungeprüft.
 *
 * Nichts davon ist gemockt - übersteuert werden nur zwei Dinge, für die es im
 * Projekt bereits etablierte Test-Nähte gibt: die Release-Liste
 * (UPDATE_RELEASES_URL, siehe UpdateService::releasesUrl()) und das
 * Zielverzeichnis (UpdateService::overrideBaseDirForTests(), Muster:
 * BackupService::overrideUploadsDirForTests()). Ohne Letzteres würde der Test
 * den Codebaum des Arbeitsverzeichnisses überschreiben.
 *
 * Dateiname bewusst nicht mit "B"/"Da" beginnend: würde alphabetisch vor
 * DatabaseTest.php laufen und dessen Anforderung brechen, der erste Aufrufer
 * von App\Database::getInstance() im Prozess zu sein (siehe Klassendoc dort).
 */
class UpdateRunTest extends TestCase {

    private static PDO $db;

    /** @var array<int, string> */
    private array $tempDirs = [];

    public static function setUpBeforeClass(): void {
        if (!defined('DB_HOST')) {
            self::markTestSkipped('Keine Test-Datenbank konfiguriert (DB_HOST fehlt) - siehe tests/bootstrap.php.');
        }

        $setupPdo = new PDO(
            "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $setupPdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        foreach ($setupPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $setupPdo->exec("DROP TABLE IF EXISTS `$table`");
        }
        $setupPdo->exec("SET FOREIGN_KEY_CHECKS = 1");

        try {
            $setupPdo->exec(file_get_contents(__DIR__ . '/../../database/schema.sql'));
        } catch (PDOException $e) {
            // Ignorieren, analog zu SetupController::provision()
        }

        // Der Eintrag des offiziellen Addon-Repos entsteht erst in der
        // Migration, nicht in schema.sql - und Database::getInstance() stößt
        // sie nur beim ERSTEN Verbindungsaufbau im Prozess an. Lief vorher
        // schon eine andere Integrations-Testklasse, ist die Verbindung
        // längst aufgebaut und der Seed fehlte nach dem Schema-Neuaufbau
        // (allein lief die Klasse deshalb grün, im Gesamtlauf rot). Explizit
        // aufrufen ist genau der Weg, für den SchemaMigrator::run() gedacht
        // ist - siehe dessen Klassendoc ("z. B. nach einem Restore").
        \App\Service\SchemaMigrator::run($setupPdo);

        self::$db = Database::getInstance();

        FakeS3Server::ensureStarted();
        FakeReleaseServer::ensureStarted();
    }

    protected function setUp(): void {
        Scheduler::resetForTests();
        self::$db->exec("DELETE FROM settings WHERE setting_key LIKE 'backup_%' OR setting_key LIKE 'update_%' OR setting_key LIKE 'cron_last_run__%'");
        self::$db->exec("DELETE FROM plugins");
        self::$db->exec("DELETE FROM audit_logs");
        self::$db->exec("DELETE FROM users WHERE username LIKE 'update-admin-%'");
        $this->insertAdminUser('update-admin@example.com');
        // Katalog-Cache frisch vorbelegen: runCheckAndNotify() beginnt mit
        // refreshOfficialCatalog(), und dessen Download kennt anders als die
        // Release-Liste keine Übersteuerung (fester Host in
        // GithubAddonRepository::downloadTarball()). Ohne diese Zeile riefe
        // die Integrations-Suite bei jedem Lauf live api.github.com ab - je
        // nach Egress mit 20 s Blockade pro Test und abhängig vom Ratelimit.
        self::$db->exec("UPDATE addon_repos SET cached_catalog_json = '[]', cached_at = NOW() WHERE is_official = 1");
        foreach (glob(FakeS3Server::storageDir() . '/*') ?: [] as $file) {
            unlink($file);
        }
        FakeReleaseServer::clear();
    }

    protected function tearDown(): void {
        UpdateService::overrideBaseDirForTests(null);
        putenv('UPDATE_RELEASES_URL');
        putenv('ADDON_RELEASES_URL');
        // Ein hängen gebliebener Marker würde jeden folgenden Test (und eine
        // lokale Entwicklungsinstanz im selben Arbeitsverzeichnis) mit 503
        // lahmlegen - deshalb hier bedingungslos aufräumen.
        Maintenance::disable();
        foreach ($this->tempDirs as $dir) {
            $this->removeTree($dir);
        }
        $this->tempDirs = [];
    }

    /**
     * Der vollständige Erfolgspfad: Backup läuft, Zip wird geladen, die
     * Prüfsumme stimmt, die Dateien landen im Ziel - und der Wartungsmodus
     * ist danach wieder aus.
     */
    public function testPerformUpdateAppliesArchiveAndRunsBackupFirst(): void {
        $this->configureBackup();
        $target = $this->makeTempDir();
        UpdateService::overrideBaseDirForTests($target);
        $this->publishRelease('9.9.9', ['neue-datei.txt' => 'Inhalt aus dem Release']);

        $result = UpdateService::performUpdate();

        $this->assertSame('9.9.9', $result['to']);
        $this->assertGreaterThan(0, $result['files']);
        $this->assertSame(
            'Inhalt aus dem Release',
            file_get_contents($target . '/neue-datei.txt'),
            'Das Release-Archiv muss tatsächlich ins Zielverzeichnis kopiert worden sein'
        );

        // Pflicht-Backup (#59/#85): Ohne hochgeladenen Dump wäre das Update
        // ohne Sicherung gelaufen - genau das, was die Reihenfolge verhindert.
        $this->assertNotEmpty(
            glob(FakeS3Server::storageDir() . '/test-bucket__backups~*.sql.gz'),
            'Vor dem Update muss ein Backup hochgeladen worden sein'
        );

        $this->assertFalse(Maintenance::isActive(), 'Nach dem Update darf kein Wartungsmodus zurückbleiben');
    }

    /**
     * Kern von #290 (Bug B) auf Service-Ebene: Fehlt im Addons-Repo ein
     * Release zur neuen Kern-Linie, verweigert die Addon-Phase - und der
     * Grund muss als KLARTEXT in der Ergebnisliste stehen, nicht bloß als
     * Fehlschlag gezählt werden. Genau daraus baut UpdateController::run()
     * die Meldung auf der Update-Seite.
     */
    public function testAddonPhaseReportsPlainTextReasonWhenNoAddonReleaseExists(): void {
        $this->configureBackup();
        UpdateService::overrideBaseDirForTests($this->makeTempDir());
        $this->publishRelease('9.9.9');
        $slug = $this->installOfficialAddon();

        // Erreichbare, aber leere Releases-Liste: kein Release zu KEINER
        // Linie - so greift der Verweigerungszweig und nicht ein Netzfehler.
        putenv('ADDON_RELEASES_URL=' . FakeReleaseServer::putFile('addon-releases.json', '[]'));

        $result = UpdateService::performUpdate();

        $this->assertCount(1, $result['addons']);
        $this->assertSame($slug, $result['addons'][0]['slug']);
        $this->assertFalse($result['addons'][0]['ok']);
        $this->assertStringContainsString('kein Addon-Release', (string)$result['addons'][0]['error']);

        // Und die Zusammenfassung, die der Controller in die URL schreibt.
        $summary = AddonUpdateService::summarizeFailures($result['addons']);
        $this->assertCount(1, $summary['reasons']);
        $this->assertStringContainsString('kein Addon-Release', $summary['reasons'][0]);
        $this->assertSame([$slug], $summary['slugs']);
    }

    /**
     * Ein Archiv, das die Prüfsumme besteht (sie wird ja über genau diese
     * Bytes gebildet), aber kein gültiges Zip ist: Das Anwenden bricht ab -
     * und der Wartungsmodus muss trotzdem enden. Bliebe der Marker liegen,
     * stünde die Installation dauerhaft auf 503; dass ein vorhandener Marker
     * jeden Request sperrt, hält tests/Functional/MaintenanceModeTest fest.
     */
    public function testMaintenanceModeIsLiftedWhenApplyingFails(): void {
        $this->configureBackup();
        UpdateService::overrideBaseDirForTests($this->makeTempDir());
        $this->publishBrokenRelease('9.9.9');

        try {
            UpdateService::performUpdate();
            $this->fail('Ein unlesbares Archiv muss das Update abbrechen');
        } catch (\RuntimeException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertFalse(
            Maintenance::isActive(),
            'Nach einem abgebrochenen Update darf der Wartungsmodus nicht aktiv bleiben'
        );
    }

    /**
     * Reihenfolge-Zusicherung aus #85: Ohne konfiguriertes Backup wird gar
     * nicht erst geprüft oder geladen - der Abbruch kommt vor jedem
     * Netzwerkzugriff, und der Wartungsmodus wird nie aktiviert.
     */
    public function testPerformUpdateRefusesWithoutConfiguredBackup(): void {
        UpdateService::overrideBaseDirForTests($this->makeTempDir());
        $this->publishRelease('9.9.9');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Backup/');

        try {
            UpdateService::performUpdate();
        } finally {
            $this->assertFalse(Maintenance::isActive());
        }
    }

    /**
     * Ein Release ohne SHA256SUMS.txt darf nicht eingespielt werden: Ohne
     * Prüfsumme ist nicht feststellbar, ob das Archiv unversehrt ist - und
     * sein Inhalt läuft danach als PHP.
     */
    public function testPerformUpdateRefusesReleaseWithoutChecksums(): void {
        $this->configureBackup();
        $target = $this->makeTempDir();
        UpdateService::overrideBaseDirForTests($target);
        $this->publishRelease('9.9.9', ['neue-datei.txt' => 'x'], includeChecksums: false);

        try {
            UpdateService::performUpdate();
            $this->fail('Ohne Prüfsummendatei darf nicht aktualisiert werden');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('SHA256SUMS.txt', $e->getMessage());
        }

        $this->assertFileDoesNotExist($target . '/neue-datei.txt');
        $this->assertFalse(Maintenance::isActive());
    }

    // ---- Update-Automatik (#290, zweite Stufe aus #85) -----------------

    /**
     * Die PRÜFUNG läuft immer - auch ohne aktivierte Automatik will ein
     * Betreiber über verfügbare Versionen informiert werden.
     */
    /**
     * Ohne jede Konfiguration passiert nichts - dieselbe Zusicherung wie bei
     * BackupService/DigestService. Sonst begänne jede Bestandsinstallation
     * nach einem Update ungefragt, GitHub abzurufen und Mails zu verschicken.
     */
    public function testNothingIsRegisteredWithoutOptIn(): void {
        UpdateService::registerScheduledTask();

        $this->assertSame([], Scheduler::registeredTasks());
    }

    /** Benachrichtigen lässt sich ohne automatische Installation. */
    public function testNotifyTaskIsRegisteredOnItsOwn(): void {
        $this->setSetting('update_notify', '1');

        UpdateService::registerScheduledTask();

        $names = array_column(Scheduler::registeredTasks(), 'name');
        $this->assertContains('update.check', $names);
        $this->assertNotContains('update.auto_install', $names, 'Ohne Opt-in darf nicht installiert werden');
    }

    /**
     * Automatisch installieren ohne zu benachrichtigen wäre ein stiller
     * Codeaustausch - die Aufgabe wird dann gar nicht erst registriert.
     */
    public function testAutoInstallRequiresNotifyToBeEnabled(): void {
        $this->setSetting('update_auto_install', '1');

        UpdateService::registerScheduledTask();

        $this->assertSame([], Scheduler::registeredTasks());
    }

    public function testAutoInstallTaskIsRegisteredOnlyWhenEnabled(): void {
        $this->setSetting('update_notify', '1');
        $this->setSetting('update_auto_install', '1');

        UpdateService::registerScheduledTask();

        $tasks = [];
        foreach (Scheduler::registeredTasks() as $task) {
            $tasks[$task['name']] = $task['intervalSeconds'];
        }

        $this->assertArrayHasKey('update.auto_install', $tasks);
        $this->assertSame(3 * 3600, $tasks['update.check'], 'Prüfung alle drei Stunden');
        $this->assertSame(24 * 3600, $tasks['update.auto_install'], 'Installation höchstens einmal täglich');
    }

    /**
     * Kern der Nutzeranforderung: melden, aber nur EINMAL je Fund. Der zweite
     * Lauf gegen dieselbe Release-Liste darf nichts Neues mehr finden.
     */
    /**
     * Kern der Nutzeranforderung: melden, aber nur EINMAL je Fund. Der
     * bereits gemeldete Stand wird direkt gesetzt, weil sich ein
     * erfolgreicher Versand ohne SMTP-Server nicht nachstellen lässt - die
     * Dedup-Entscheidung läuft dabei durch den echten Pfad.
     */
    public function testAlreadyReportedVersionIsNotReportedAgain(): void {
        $this->setSetting('update_last_notified_version', '9.9.9');
        $this->publishRelease('9.9.9');

        UpdateService::runCheckAndNotify();

        $this->assertSame(0, $this->countAuditEntries('Update verfügbar gemeldet'));
        $this->assertSame('9.9.9', $this->getSetting('update_last_notified_version'));
    }

    /** Eine neuere Version ist wieder ein Fund und wird erneut aufgegriffen. */
    public function testNewerVersionIsReportedAgain(): void {
        $this->setSetting('update_last_notified_version', '9.9.9');
        $this->publishRelease('9.9.10');

        UpdateService::runCheckAndNotify();

        $this->assertSame(1, $this->countAuditEntries('Update verfügbar gemeldet'));
    }

    /**
     * Eine nicht erreichbare Release-Quelle ist "keine Aussage", kein
     * Fehlschlag: nichts melden, nichts fortschreiben, beim nächsten Lauf
     * erneut versuchen. Sonst stünde bei jeder Netzstörung alle drei Stunden
     * eine Fehlermeldung im Protokoll.
     */
    public function testUnreachableReleaseSourceReportsNothingAndKeepsState(): void {
        putenv('UPDATE_RELEASES_URL=http://127.0.0.1:9/releases.json');

        UpdateService::runCheckAndNotify();

        $this->assertSame(0, $this->countAuditEntries('Update verfügbar gemeldet'));
        $this->assertNull($this->getSetting('update_last_notified_version'));
    }

    /**
     * Gibt es kein Admin-Konto mit E-Mail-Adresse, darf der Fund nicht
     * lautlos verschwinden: Die Meldung wandert ins Audit-Log, damit
     * überhaupt nachvollziehbar bleibt, warum keine Mail kam.
     */
    public function testMissingRecipientsAreRecordedInsteadOfSilentlyDropped(): void {
        self::$db->exec("DELETE FROM user_groups");
        $this->publishRelease('9.9.9');

        UpdateService::runCheckAndNotify();

        $this->assertSame(1, $this->countAuditEntries('Update verfügbar, aber kein Empfänger'));
        $this->assertSame(0, $this->countAuditEntries('Update verfügbar gemeldet'));

        // Entscheidend: Der Fund darf NICHT als gemeldet gelten, solange
        // niemand ihn bekommen hat - sonst wäre er endgültig verloren.
        $this->assertNull(
            $this->getSetting('update_last_notified_version'),
            'Ohne Empfänger darf die Version nicht als gemeldet vermerkt werden'
        );
    }

    /**
     * Dasselbe für den häufigeren Fall: Empfänger vorhanden, aber der
     * Mailversand scheitert (kein SMTP konfiguriert -> Mailer::send() liefert
     * kontrolliert false). Ohne diese Zusicherung wäre ein Ausfallfenster des
     * Mailservers endgültig: Der Fund stünde als erledigt im Merkzettel und
     * käme auch nach Behebung nie wieder.
     */
    public function testFindingStaysOpenWhenNoMailCouldBeSent(): void {
        $this->publishRelease('9.9.9');

        UpdateService::runCheckAndNotify();

        $this->assertNull(
            $this->getSetting('update_last_notified_version'),
            'Ein nicht zugestellter Fund muss offen bleiben'
        );

        // Sobald der Versand wieder klappt, wird derselbe Fund erneut
        // aufgegriffen - hier nachgestellt über einen zweiten Lauf, der
        // wieder als "neu" wertet und einen Meldeversuch protokolliert.
        UpdateService::runCheckAndNotify();
        $this->assertSame(2, $this->countAuditEntries('Update verfügbar gemeldet'));
    }

    /**
     * Gegenprobe zum Fall oben: Ohne neuen Fund wird der Merkzettel sehr wohl
     * fortgeschrieben - ein verschwundenes Addon-Update muss herausfallen,
     * sonst gälte es beim Wiederauftauchen fälschlich als bekannt.
     */
    public function testRememberedStateIsPrunedWhenNothingIsNew(): void {
        $this->setSetting('update_last_notified_addons', '{"verschwundenes-addon":"1.0.0"}');
        // Keine Release-Fixture: kein Kern-Update, keine Addon-Updates.
        putenv('UPDATE_RELEASES_URL=' . FakeReleaseServer::putFile('leer.json', '[]'));

        UpdateService::runCheckAndNotify();

        $this->assertSame('{}', $this->getSetting('update_last_notified_addons'));
    }

    /**
     * Und der Gegenbeweis zur Dedup-Logik auf Addon-Ebene: Ein bereits
     * gemeldetes Addon-Update taucht nicht erneut als Fund auf, bleibt aber
     * im Merkzettel stehen, solange es verfügbar ist.
     */
    public function testAlreadyReportedAddonStaysInRememberedState(): void {
        $this->seedOfficialCatalogWithAddonUpdate('1.5.0');
        $slug = $this->installOfficialAddon();
        $this->setSetting('update_last_notified_addons', json_encode([$slug => '1.5.0']));
        putenv('UPDATE_RELEASES_URL=' . FakeReleaseServer::putFile('leer.json', '[]'));

        UpdateService::runCheckAndNotify();

        $this->assertSame(0, $this->countAuditEntries('Update verfügbar gemeldet'));
        $this->assertSame(
            json_encode([$slug => '1.5.0']),
            $this->getSetting('update_last_notified_addons')
        );
    }

    /**
     * Das Gate aus der Reichweiten-Einstellung: Ein Minor-Sprung wird bei
     * 'patch_only' NICHT eingespielt - nachgewiesen daran, dass im
     * Zielverzeichnis nichts landet.
     */
    public function testAutoInstallSkipsVersionOutsideConfiguredScope(): void {
        $this->configureBackup();
        $this->setSetting('update_notify', '1');
        $this->setSetting('update_auto_install', '1');
        $this->setSetting('update_auto_install_scope', 'patch_only');
        $target = $this->makeTempDir();
        UpdateService::overrideBaseDirForTests($target);

        // Die laufende Version ist CORE_VERSION; ein Sprung auf 99.0.0 liegt
        // garantiert außerhalb derselben Minor-Linie.
        $this->publishRelease('99.0.0', ['neue-datei.txt' => 'darf nicht ankommen']);

        UpdateService::runAutoInstallIfEligible();

        $this->assertFileDoesNotExist($target . '/neue-datei.txt');
        $this->assertSame(1, $this->countAuditEntries('Automatisches Update übersprungen'));
    }

    /**
     * Ohne Opt-in passiert auch dann nichts, wenn die Aufgabe direkt
     * aufgerufen wird.
     *
     * DIE REICHWEITE MUSS HIER AUSDRÜCKLICH AUF 'any' STEHEN. Ohne sie greift
     * der Standard `patch_only`, und die veröffentlichte 9.9.9 liegt dann
     * ohnehin ausserhalb der laufenden Kern-Linie - der Abbruch käme also von
     * der Reichweiten-Hürde, nicht vom fehlenden Opt-in. Der Test wäre grün
     * geblieben, wenn man die Opt-in-Prüfung am Anfang von
     * runAutoInstallIfEligible() ersatzlos streicht; genau die soll er aber
     * halten. Mit 'any' ist das fehlende Opt-in die einzige verbleibende
     * Schranke.
     */
    public function testAutoInstallDoesNothingWithoutOptIn(): void {
        $this->configureBackup();
        $this->setSetting('update_auto_install_scope', 'any');
        $target = $this->makeTempDir();
        UpdateService::overrideBaseDirForTests($target);
        $this->publishRelease('9.9.9', ['neue-datei.txt' => 'darf nicht ankommen']);

        UpdateService::runAutoInstallIfEligible();

        $this->assertFileDoesNotExist($target . '/neue-datei.txt');
    }

    /**
     * Der scharfe Fall: aktivierte Automatik, Version innerhalb der
     * Reichweite - das Update wird unbeaufsichtigt eingespielt, mit Backup
     * davor und ohne zurückbleibenden Wartungsmodus.
     */
    public function testAutoInstallAppliesEligibleUpdate(): void {
        $this->configureBackup();
        $this->setSetting('update_notify', '1');
        $this->setSetting('update_auto_install', '1');
        $this->setSetting('update_auto_install_scope', 'any');
        $target = $this->makeTempDir();
        UpdateService::overrideBaseDirForTests($target);
        $this->publishRelease('99.0.0', ['neue-datei.txt' => 'automatisch eingespielt']);

        UpdateService::runAutoInstallIfEligible();

        $this->assertSame('automatisch eingespielt', file_get_contents($target . '/neue-datei.txt'));
        $this->assertNotEmpty(
            glob(FakeS3Server::storageDir() . '/test-bucket__backups~*.sql.gz'),
            'Auch der unbeaufsichtigte Lauf muss zuvor sichern'
        );
        $this->assertFalse(Maintenance::isActive());
        $this->assertSame(1, $this->countAuditEntries('Automatisches Update eingespielt'));
    }

    /**
     * Scheitert der unbeaufsichtigte Lauf, muss er das melden UND den Fehler
     * weiterreichen, damit Scheduler::runDue() ihn zentral protokolliert -
     * ein still verschluckter Fehlschlag sähe aus wie ein erfolgreicher Lauf.
     */
    public function testFailedAutoInstallIsLoggedAndRethrown(): void {
        $this->configureBackup();
        $this->setSetting('update_notify', '1');
        $this->setSetting('update_auto_install', '1');
        $this->setSetting('update_auto_install_scope', 'any');
        UpdateService::overrideBaseDirForTests($this->makeTempDir());
        $this->publishBrokenRelease('99.0.0');

        try {
            UpdateService::runAutoInstallIfEligible();
            $this->fail('Ein fehlgeschlagenes Update muss den Fehler weiterreichen');
        } catch (\RuntimeException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertSame(1, $this->countAuditEntries('Automatisches Update fehlgeschlagen'));
        $this->assertFalse(Maintenance::isActive());
    }

    // ---- Helfer --------------------------------------------------------

    /**
     * Empfänger der Update-Meldungen sind die tatsächlichen Admin-Konten
     * (Gruppe `admin`), nicht die DSGVO-Adresse - siehe
     * UpdateService::adminRecipients().
     */
    private function insertAdminUser(string $email): void {
        $stmt = self::$db->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, 'x')");
        $stmt->execute(['update-admin-' . uniqid(), $email]);
        $userId = (int)self::$db->lastInsertId();

        $adminGroupId = self::$db->query("SELECT id FROM `groups` WHERE slug = 'admin'")->fetchColumn();
        $stmt = self::$db->prepare("INSERT IGNORE INTO user_groups (user_id, group_id) VALUES (?, ?)");
        $stmt->execute([$userId, $adminGroupId]);
    }

    /**
     * Legt einen Katalog-Cache an, der fuer das Testaddon eine neuere Version
     * ausweist - Grundlage dafuer, dass AddonOverview::rows() ein offenes
     * Addon-Update meldet.
     */
    private function seedOfficialCatalogWithAddonUpdate(string $version): void {
        $catalog = json_encode([[
            'slug' => 'update-run-testaddon',
            'name' => 'Update-Lauf Testaddon',
            'version' => $version,
            'core_compatibility' => '>=0.1.0-beta.1',
            'core_supported_max' => '9.9',
            'description' => '',
            'author' => '',
            'hooks' => [],
        ]]);
        $stmt = self::$db->prepare(
            "UPDATE addon_repos SET cached_catalog_json = ?, cached_at = NOW() WHERE is_official = 1"
        );
        $stmt->execute([$catalog]);
    }

    private function setSetting(string $key, string $value): void {
        $stmt = self::$db->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?"
        );
        $stmt->execute([$key, $value, $value]);
    }

    private function getSetting(string $key): ?string {
        $stmt = self::$db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function countAuditEntries(string $action): int {
        $stmt = self::$db->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = ?");
        $stmt->execute([$action]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Ein Addon, dessen Funktion in den Kern gewandert ist, wird beim Update
     * DEAKTIVIERT und sein Verzeichnis entfernt (#339).
     *
     * WARUM DAS EINE AUSNAHME BRAUCHT UND EINEN TEST. `plugins` steht unter
     * PROTECTED_PATHS - ein Update fasst Addon-Verzeichnisse nie an. Genau
     * eine Lage braucht die Ausnahme: wenn der Kern uebernimmt, was das Addon
     * tat. Bliebe `galerie` daneben aktiv, gaebe es zwei Pflegeoberflaechen
     * fuer dieselben Daten und zwei Vorstellungen davon, welches Bild das
     * Hauptbild ist.
     *
     * Die Liste stand seit v0.8 im Code, war dokumentiert - und wurde
     * nirgends gelesen. Dieser Test ist der Nachweis, dass die Mechanik
     * tatsaechlich laeuft.
     */
    public function testSupersededAddonIsDeactivatedAndRemovedOnUpdate(): void {
        $this->configureBackup();
        $target = $this->makeTempDir();
        UpdateService::overrideBaseDirForTests($target);

        // Zwei Addons im Zielverzeichnis: eines abgeloest, eines nicht.
        mkdir($target . '/plugins/galerie', 0777, true);
        mkdir($target . '/plugins/merkliste', 0777, true);
        file_put_contents($target . '/plugins/galerie/Plugin.php', '<?php // alt');
        file_put_contents($target . '/plugins/merkliste/Plugin.php', '<?php // bleibt');

        $stmt = self::$db->prepare('INSERT INTO plugins (slug, enabled, installed_version) VALUES (?, 1, ?)');
        $stmt->execute(['galerie', '1.4.0']);
        $stmt->execute(['merkliste', '1.0.0']);

        // Das Archiv traegt die neue CORE_VERSION - aus IHR entscheidet sich,
        // ob die Ablösung schon gilt. Die laufende Konstante gehoert noch zum
        // alten Stand.
        $this->publishRelease('99.0.0', [
            'README-update.txt' => 'x',
            'config/config.php' => "<?php\ndefine('CORE_VERSION', '99.0.0');\n",
        ]);

        UpdateService::performUpdate();

        $this->assertDirectoryDoesNotExist(
            $target . '/plugins/galerie',
            'Das abgeloeste Addon muss samt Verzeichnis verschwinden.'
        );
        $this->assertDirectoryExists(
            $target . '/plugins/merkliste',
            'Jedes andere Addon bleibt unangetastet - `plugins` ist geschuetzt.'
        );

        $stmt = self::$db->prepare('SELECT enabled FROM plugins WHERE slug = ?');
        $stmt->execute(['galerie']);
        $this->assertSame(0, (int)$stmt->fetchColumn(), 'Und es ist deaktiviert, nicht bloss weg.');
        $stmt->execute(['merkliste']);
        $this->assertSame(1, (int)$stmt->fetchColumn());

        $this->assertSame(
            1,
            (int)self::$db->query(
                "SELECT COUNT(*) FROM audit_logs WHERE action = 'Abgeloestes Addon entfernt'"
            )->fetchColumn(),
            'Ein Eingriff in fremden Code gehoert ins Protokoll.'
        );
    }

    /**
     * Die Gegenrichtung: Ein Update auf eine Fassung VOR der Abloesung darf
     * das Addon nicht mitnehmen.
     */
    public function testSupersededAddonSurvivesAnUpdateBelowItsCutoff(): void {
        $this->configureBackup();
        $target = $this->makeTempDir();
        UpdateService::overrideBaseDirForTests($target);

        mkdir($target . '/plugins/galerie', 0777, true);
        file_put_contents($target . '/plugins/galerie/Plugin.php', '<?php // alt');
        self::$db->prepare('INSERT INTO plugins (slug, enabled, installed_version) VALUES (?, 1, ?)')
            ->execute(['galerie', '1.4.0']);

        // Neuer als die laufende Fassung, aber aelter als die Abloesung.
        $abVersion = UpdateService::abgeloesteAddons()['galerie'];
        $this->assertSame('0.9.0', $abVersion, 'Der Test haengt an dieser Grenze.');

        $this->publishRelease('99.0.0', [
            'README-update.txt' => 'x',
            'config/config.php' => "<?php\ndefine('CORE_VERSION', '0.8.9');\n",
        ]);

        UpdateService::performUpdate();

        $this->assertDirectoryExists($target . '/plugins/galerie');
        $stmt = self::$db->prepare('SELECT enabled FROM plugins WHERE slug = ?');
        $stmt->execute(['galerie']);
        $this->assertSame(1, (int)$stmt->fetchColumn());
    }

    private function configureBackup(): void {
        $settings = [
            'backup_enabled' => '1',
            'backup_s3_endpoint' => FakeS3Server::endpoint(),
            'backup_s3_region' => 'us-east-1',
            'backup_s3_bucket' => 'test-bucket',
            'backup_s3_access_key' => 'AKIDEXAMPLE',
            'backup_s3_secret_key' => Crypto::encrypt('test-secret'),
            'backup_s3_path_style' => '1',
            'backup_s3_use_https' => '0',
            'backup_interval_hours' => '24',
            'backup_retention_count' => '14',
        ];

        $stmt = self::$db->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?"
        );
        foreach ($settings as $key => $value) {
            $stmt->execute([$key, $value, $value]);
        }
    }

    /**
     * Baut ein Release-Zip im Layout des Release-Workflows (ein einzelnes
     * Wurzelverzeichnis, wie `git archive --prefix` es erzeugt), legt die
     * passende SHA256SUMS.txt daneben und veröffentlicht eine Release-Liste,
     * die auf beides zeigt.
     *
     * @param array<string, string> $files Pfad im Archiv => Inhalt
     */
    private function publishRelease(string $version, array $files = ['README-update.txt' => 'x'], bool $includeChecksums = true): void {
        $zipName = 'hengstverzeichnis-framework-' . $version . '.zip';
        $zipPath = $this->makeTempDir() . '/' . $zipName;

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException('Test-Zip konnte nicht angelegt werden.');
        }
        $zip->addEmptyDir('hengstverzeichnis-framework-' . $version);
        foreach ($files as $path => $contents) {
            $zip->addFromString('hengstverzeichnis-framework-' . $version . '/' . $path, $contents);
        }
        $zip->close();

        $this->publishArchive($version, $zipName, (string)file_get_contents($zipPath), $includeChecksums);
    }

    /**
     * Wie publishRelease(), aber der Archiv-Inhalt ist kein gültiges Zip. Die
     * Prüfsumme passt trotzdem - der Fehlschlag entsteht also erst beim
     * Entpacken, also innerhalb des Wartungsfensters.
     */
    private function publishBrokenRelease(string $version): void {
        $this->publishArchive(
            $version,
            'hengstverzeichnis-framework-' . $version . '.zip',
            'das ist kein zip, sondern Text',
            true
        );
    }

    private function publishArchive(string $version, string $zipName, string $zipBytes, bool $includeChecksums): void {
        $zipUrl = FakeReleaseServer::putFile($zipName, $zipBytes);

        $assets = [['name' => $zipName, 'browser_download_url' => $zipUrl]];
        if ($includeChecksums) {
            $checksumsUrl = FakeReleaseServer::putFile(
                'SHA256SUMS.txt',
                hash('sha256', $zipBytes) . '  ' . $zipName . "\n"
            );
            $assets[] = ['name' => 'SHA256SUMS.txt', 'browser_download_url' => $checksumsUrl];
        }

        $releasesUrl = FakeReleaseServer::putFile('releases.json', (string)json_encode([[
            'tag_name' => 'v' . $version,
            'draft' => false,
            'prerelease' => false,
            'html_url' => 'https://example.invalid/releases/v' . $version,
            'assets' => $assets,
        ]]));

        putenv('UPDATE_RELEASES_URL=' . $releasesUrl);
    }

    /**
     * Legt ein Addon an, das aus dem offiziellen Repo zu stammen scheint -
     * erst damit betrachtet die Addon-Phase es überhaupt als mitzuziehen.
     * Das Verzeichnis muss im echten plugins/ liegen, weil PluginManager von
     * dort entdeckt (dasselbe Vorgehen wie UpdateAddonOverviewTest).
     */
    private function installOfficialAddon(): string {
        $slug = 'update-run-testaddon';
        $dir = dirname(__DIR__, 2) . '/plugins/' . $slug;
        @mkdir($dir, 0755, true);
        file_put_contents($dir . '/plugin.json', (string)json_encode([
            'slug' => $slug,
            'name' => 'Update-Lauf Testaddon',
            'version' => '1.0.0',
            'core_compatibility' => '>=0.1.0-beta.1',
            'core_supported_max' => '9.9',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents(
            $dir . '/Plugin.php',
            "<?php\nnamespace Plugin\\UpdateRunTestaddon;\nclass Plugin { public function register(\$hooks): void {} }\n"
        );
        $this->tempDirs[] = $dir;

        $official = self::$db->query("SELECT owner, repo FROM addon_repos WHERE is_official = 1 LIMIT 1")->fetch();
        $this->assertNotFalse($official, 'Seed des offiziellen Addon-Repos muss vorhanden sein');
        $stmt = self::$db->prepare(
            "INSERT INTO plugins (slug, source) VALUES (?, ?) ON DUPLICATE KEY UPDATE source = VALUES(source)"
        );
        $stmt->execute([$slug, "{$official['owner']}/{$official['repo']}"]);

        // Die Addon-Phase zieht nur mit, was PluginManager auch entdeckt hat -
        // gefüllt wird das erst durch boot(), das sonst der Request-Bootstrap
        // übernimmt (public/index.php). Ohne diesen Aufruf bliebe die
        // Ergebnisliste leer und der Test grün, ohne etwas geprüft zu haben.
        \App\Plugin\PluginManager::getInstance()->boot();
        $this->assertArrayHasKey(
            $slug,
            \App\Plugin\PluginManager::getInstance()->getDiscoveredPlugins(),
            'Testaddon muss von PluginManager entdeckt worden sein'
        );

        return $slug;
    }

    private function makeTempDir(): string {
        $dir = sys_get_temp_dir() . '/update_run_' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;
        return $dir;
    }

    private function removeTree(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
