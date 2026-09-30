<?php
// tests/Integration/ExternalBackupTest.php

namespace Tests\Integration;

use App\Database;
use App\Security\Crypto;
use App\Service\BackupService;
use App\Service\DbLock;
use App\Service\Scheduler;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeS3Server;
use Tests\Support\FakeWebDavServer;

/**
 * Prüft App\Service\BackupService (#59) end-to-end: DB-Dump erzeugen,
 * gzip-komprimieren, gegen den lokalen Fake-S3-Server hochladen und die
 * Aufbewahrungsrotation anwenden - inkl. Scheduler-Registrierung
 * (App\Service\Scheduler, #67) und Status-Protokollierung in der
 * `settings`-Tabelle.
 *
 * Dateiname bewusst nicht mit "B" beginnend (z. B. "BackupServiceTest.php"):
 * würde alphabetisch vor DatabaseTest.php laufen und dessen Anforderung
 * brechen, der erste Aufrufer von App\Database::getInstance() im gesamten
 * PHPUnit-Prozess zu sein (siehe Klassendoc dort sowie
 * tests/Integration/DumpAndRestoreTest.php für dieselbe Problematik).
 */
class ExternalBackupTest extends TestCase {

    private static PDO $db;

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

        $schemaFile = __DIR__ . '/../../database/schema.sql';
        try {
            $setupPdo->exec(file_get_contents($schemaFile));
        } catch (PDOException $e) {
            // Ignorieren, analog zu SetupController::provision()
        }

        self::$db = Database::getInstance();

        FakeS3Server::ensureStarted();
    }

    protected function setUp(): void {
        Scheduler::resetForTests();
        self::$db->exec("DELETE FROM settings WHERE setting_key LIKE 'backup_%' OR setting_key LIKE 'cron_last_run__%'");
        foreach (glob(FakeS3Server::storageDir() . '/*') as $file) {
            unlink($file);
        }
    }

    /** @var array<int, string> Wegwerf-Verzeichnisse dieses Tests */
    private array $wegwerfVerzeichnisse = [];

    protected function tearDown(): void {
        BackupService::overrideUploadsDirForTests(null);
        BackupService::overrideLockWaitForTests(null);
        DbLock::simulateUnavailableForTests(false);
        foreach ($this->wegwerfVerzeichnisse as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    private function setting(string $key): string|false {
        $stmt = self::$db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        return $stmt->fetchColumn();
    }

    /**
     * Legt ein Wegwerf-Uploads-Verzeichnis mit Beispieldateien an und biegt
     * BackupService darauf um (statt auf das echte public/uploads des
     * Arbeitsverzeichnisses).
     *
     * @return array<string, string> Archivname => erwarteter Inhalt
     */
    private function prepareFakeUploadsDir(): array {
        $dir = sys_get_temp_dir() . '/backup_uploads_' . uniqid();
        mkdir($dir . '/horses', 0777, true);
        $this->wegwerfVerzeichnisse[] = $dir;
        $files = [
            'uploads/.htaccess' => "Deny from all\n",
            'uploads/horses/hengst.jpg' => random_bytes(1500),
        ];
        file_put_contents($dir . '/.htaccess', $files['uploads/.htaccess']);
        file_put_contents($dir . '/horses/hengst.jpg', $files['uploads/horses/hengst.jpg']);
        BackupService::overrideUploadsDirForTests($dir);
        return $files;
    }

    private function configureBackup(array $overrides = []): void {
        $settings = array_merge([
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
        ], $overrides);

        $stmt = self::$db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        foreach ($settings as $key => $value) {
            $stmt->execute([$key, $value, $value]);
        }
    }

    public function testIsNotConfiguredWithoutSettings(): void {
        $this->assertFalse(BackupService::isConfigured([]));
    }

    public function testRegisterScheduledTaskIsNoOpWhenDisabled(): void {
        $this->configureBackup(['backup_enabled' => '0']);

        BackupService::registerScheduledTask();

        $this->assertSame([], Scheduler::registeredTasks());
    }

    public function testRegisterScheduledTaskRegistersWhenEnabled(): void {
        $this->configureBackup(['backup_interval_hours' => '6']);

        BackupService::registerScheduledTask();

        $tasks = Scheduler::registeredTasks();
        $this->assertCount(1, $tasks);
        $this->assertSame('backup.external', $tasks[0]['name']);
        $this->assertSame(6 * 3600, $tasks[0]['intervalSeconds']);
    }

    /**
     * Audit N67: Ein gespeicherter Riesenwert ließ `$stunden * 3600`
     * überlaufen, register() warf bei jedem Request. Jetzt wird beim Lesen
     * auf ein Jahr geklemmt.
     */
    public function testHugeStoredIntervalIsClampedToOneYear(): void {
        $this->configureBackup(['backup_interval_hours' => '9999999999999999']);

        BackupService::registerScheduledTask();

        $tasks = Scheduler::registeredTasks();
        $this->assertCount(1, $tasks);
        $this->assertSame(31536000, $tasks[0]['intervalSeconds']);
    }

    public function testRunUploadsGzippedDumpAndRecordsSuccessStatus(): void {
        $this->configureBackup();

        BackupService::run();

        $files = glob(FakeS3Server::storageDir() . '/test-bucket__backups~*.sql.gz');
        $this->assertCount(1, $files, 'Es sollte genau eine .sql.gz-Datei im Fake-S3-Speicher liegen');

        $dumpContent = gzdecode(file_get_contents($files[0]));
        $this->assertStringContainsString('CREATE TABLE', $dumpContent);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `settings`', $dumpContent);

        $status = self::$db->query("SELECT setting_value FROM settings WHERE setting_key = 'backup_last_status'")->fetchColumn();
        $this->assertSame('ok', $status);

        $lastRunAt = self::$db->query("SELECT setting_value FROM settings WHERE setting_key = 'backup_last_run_at'")->fetchColumn();
        $this->assertNotFalse($lastRunAt);
        $this->assertGreaterThan(0, (int)$lastRunAt);

        // Ohne Opt-in (#233) wird KEIN Uploads-Archiv hochgeladen.
        $this->assertSame([], glob(FakeS3Server::storageDir() . '/test-bucket__backups~uploads-*'));
    }

    /**
     * Opt-in "Uploads mitsichern" (#233): zusätzlich zum SQL-Dump landet ein
     * tar.gz-Archiv des Uploads-Verzeichnisses am Ziel, und es lässt sich
     * (per System-tar) byte-identisch wieder entpacken - die eigentliche
     * Katastrophen-Wiederherstellungs-Garantie.
     */
    public function testRunWithUploadsOptionUploadsRestorableTarArchive(): void {
        $expectedFiles = $this->prepareFakeUploadsDir();
        $this->configureBackup(['backup_include_uploads' => '1']);

        BackupService::run();

        $dumps = glob(FakeS3Server::storageDir() . '/test-bucket__backups~backup-*.sql.gz');
        $this->assertCount(1, $dumps, 'Der SQL-Dump muss weiterhin hochgeladen werden');
        $this->assertStringContainsString('CREATE TABLE', (string)gzdecode((string)file_get_contents($dumps[0])));

        $archives = glob(FakeS3Server::storageDir() . '/test-bucket__backups~uploads-*.tar.gz');
        $this->assertCount(1, $archives, 'Es sollte genau ein Uploads-Archiv im Fake-S3-Speicher liegen');

        $status = self::$db->query("SELECT setting_value FROM settings WHERE setting_key = 'backup_last_status'")->fetchColumn();
        $this->assertSame('ok', $status);

        exec('command -v tar 2>/dev/null', $out, $tarMissing);
        if ($tarMissing !== 0) {
            $this->markTestIncomplete('Kein tar-Binary im PATH - Entpack-Gegenprobe übersprungen.');
        }
        $extractDir = sys_get_temp_dir() . '/backup_uploads_extract_' . uniqid();
        mkdir($extractDir);
        try {
            exec('tar -xzf ' . escapeshellarg($archives[0]) . ' -C ' . escapeshellarg($extractDir) . ' 2>&1', $tarOut, $exitCode);
            $this->assertSame(0, $exitCode, 'System-tar konnte das Uploads-Archiv nicht entpacken: ' . implode("\n", $tarOut));
            foreach ($expectedFiles as $name => $content) {
                $this->assertSame($content, file_get_contents($extractDir . '/' . $name), "Inhalt von {$name} weicht nach dem Entpacken ab");
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($extractDir));
        }
    }

    /**
     * Zielausfall bei aktivierter Uploads-Option: der Lauf endet als Fehler
     * (Status 'error'), und die streamend aufgebauten Temp-Dateien (#231:
     * Dump- und Archiv-Zwischendateien) bleiben nicht liegen - sonst würde
     * jeder fehlgeschlagene nächtliche Lauf das Temp-Verzeichnis um die
     * Größe von Dump + Uploads-Archiv wachsen lassen.
     */
    public function testRunWithUploadsOptionCleansUpTempFilesOnFailure(): void {
        $this->prepareFakeUploadsDir();
        $this->configureBackup([
            'backup_include_uploads' => '1',
            'backup_s3_endpoint' => '127.0.0.1:1',
        ]);

        $tempFilesBefore = glob(sys_get_temp_dir() . '/hv-backup-*');

        try {
            BackupService::run();
            $this->fail('Erwartete RuntimeException bei nicht erreichbarem Backup-Ziel.');
        } catch (\RuntimeException $e) {
            $status = self::$db->query("SELECT setting_value FROM settings WHERE setting_key = 'backup_last_status'")->fetchColumn();
            $this->assertSame('error', $status);
        }

        // array_diff statt Gleichheit: Der Lauf darf verwaiste Fremddateien
        // (älter als 24 h, Audit M37) entfernen.
        $this->assertSame([], array_values(array_diff(glob(sys_get_temp_dir() . '/hv-backup-*'), $tempFilesBefore)), 'Temp-Dateien des fehlgeschlagenen Laufs wurden nicht aufgeräumt');
    }

    public function testRunThrowsAndRecordsErrorStatusWhenUploadFails(): void {
        // Falscher Bucket-Endpunkt (nichts hört auf diesem Port) simuliert einen
        // Upload-Fehlschlag (z. B. Netzwerkproblem/falsche Zugangsdaten).
        $this->configureBackup(['backup_s3_endpoint' => '127.0.0.1:1']);

        $this->expectException(\RuntimeException::class);

        try {
            BackupService::run();
        } finally {
            $status = self::$db->query("SELECT setting_value FROM settings WHERE setting_key = 'backup_last_status'")->fetchColumn();
            $this->assertSame('error', $status);
        }
    }

    public function testRunAppliesRetentionRotationKeepingOnlyNewestBackups(): void {
        $this->configureBackup(['backup_retention_count' => '2']);

        // Drei vorab "gealterte" Backups simulieren (chronologisch sortierbare
        // Schlüssel, älteste zuerst) - direkt über den Fake-Speicher angelegt,
        // damit der Test nicht auf reale Zeitverzögerungen zwischen echten
        // BackupService::run()-Aufrufen warten muss.
        foreach (['backup-2020-01-01_000000.sql.gz', 'backup-2021-01-01_000000.sql.gz', 'backup-2022-01-01_000000.sql.gz'] as $existingKey) {
            file_put_contents(FakeS3Server::storageDir() . '/test-bucket__backups~' . $existingKey, 'altes-backup');
        }

        BackupService::run();

        $remainingKeys = array_map(
            fn($path) => str_replace('~', '/', substr(basename($path), strlen('test-bucket__'))),
            glob(FakeS3Server::storageDir() . '/test-bucket__backups~*')
        );
        sort($remainingKeys);

        // Die zwei ältesten simulierten Backups müssen rotiert (gelöscht) sein,
        // das neueste simulierte sowie das gerade frisch hochgeladene bleiben (retention_count=2).
        $this->assertNotContains('backups/backup-2020-01-01_000000.sql.gz', $remainingKeys);
        $this->assertNotContains('backups/backup-2021-01-01_000000.sql.gz', $remainingKeys);
        $this->assertContains('backups/backup-2022-01-01_000000.sql.gz', $remainingKeys);
        $this->assertCount(2, $remainingKeys);
    }

    /**
     * Die Rotation zählt je Backup-Art getrennt (#233): SQL-Dumps und
     * Uploads-Archive halten jeweils für sich die konfigurierte Anzahl -
     * sonst würde ein Lauf mit beiden Objekten die effektive
     * Dump-Aufbewahrung halbieren.
     */
    public function testRetentionRotatesDumpsAndUploadsArchivesIndependently(): void {
        $this->prepareFakeUploadsDir();
        $this->configureBackup(['backup_include_uploads' => '1', 'backup_retention_count' => '2']);

        foreach ([
            'backup-2020-01-01_000000.sql.gz',
            'backup-2021-01-01_000000.sql.gz',
            'uploads-2020-01-01_000000.tar.gz',
            'uploads-2021-01-01_000000.tar.gz',
        ] as $existingKey) {
            file_put_contents(FakeS3Server::storageDir() . '/test-bucket__backups~' . $existingKey, 'altes-backup');
        }

        BackupService::run();

        $remainingKeys = array_map(
            fn($path) => str_replace('~', '/', substr(basename($path), strlen('test-bucket__'))),
            glob(FakeS3Server::storageDir() . '/test-bucket__backups~*')
        );
        sort($remainingKeys);

        // Je Art: das jeweils älteste simulierte Objekt rotiert, das neuere
        // simulierte und das frisch hochgeladene bleiben (2 + 2 = 4 gesamt).
        $this->assertNotContains('backups/backup-2020-01-01_000000.sql.gz', $remainingKeys);
        $this->assertNotContains('backups/uploads-2020-01-01_000000.tar.gz', $remainingKeys);
        $this->assertContains('backups/backup-2021-01-01_000000.sql.gz', $remainingKeys);
        $this->assertContains('backups/uploads-2021-01-01_000000.tar.gz', $remainingKeys);
        $this->assertCount(4, $remainingKeys);
    }

    /**
     * Audit M37: Die Sicherung hebt das Zeitlimit selbst auf und läuft
     * weiter, wenn der Browser auflegt - an jedem Einstiegspunkt, weil
     * run() es selbst tut.
     */
    public function testRunHebtZeitlimitAufUndIgnoriertAbbruch(): void {
        $this->configureBackup();
        set_time_limit(300);
        ignore_user_abort(false);
        try {
            BackupService::run();
            $this->assertSame('0', ini_get('max_execution_time'));
            $this->assertSame(1, ignore_user_abort());
        } finally {
            set_time_limit(0);
            ignore_user_abort(false);
        }
    }

    /**
     * Audit M37: Nach einem Kill (SIGKILL, request_terminate_timeout) läuft
     * keine Shutdown-Funktion. Verwaiste Zwischendateien älter als 24 h
     * räumt der nächste Lauf weg, frische (ein paralleler Lauf) bleiben.
     */
    public function testRunEntferntVerwaisteTempDateien(): void {
        $this->configureBackup();
        $alt = sys_get_temp_dir() . '/hv-backup-sql-test' . uniqid();
        $frisch = sys_get_temp_dir() . '/hv-backup-uploads-test' . uniqid();
        $fremd = sys_get_temp_dir() . '/hv-backup-fremd-test' . uniqid();
        foreach ([$alt, $frisch, $fremd] as $pfad) {
            file_put_contents($pfad, 'x');
        }
        touch($alt, time() - 2 * 86400);
        touch($fremd, time() - 2 * 86400);
        try {
            BackupService::run();
            $this->assertFileDoesNotExist($alt);
            $this->assertFileExists($frisch);
            $this->assertFileExists($fremd, 'Nur die eigenen Präfixe werden aufgeräumt');
        } finally {
            foreach ([$alt, $frisch, $fremd] as $pfad) {
                @unlink($pfad);
            }
        }
    }

    /**
     * Audit N65: Scheitert nur das Uploads-Archiv, bleibt der Dump
     * gesichert, und der Status sagt das ('partial'). Vorher wurde der
     * fertige Dump verworfen, und /admin/backups zeigte den alten Erfolg.
     */
    public function testUploadsFehlerSichertDumpUndMeldetTeilfehler(): void {
        $this->prepareFakeUploadsDir();
        $dir = end($this->wegwerfVerzeichnisse);
        // "uploads/horses/<124 Zeichen>" - der Teil nach dem letzten '/' ist
        // für ustar zu lang, TarArchive wirft deterministisch.
        file_put_contents($dir . '/horses/' . str_repeat('h', 120) . '.jpg', 'bild');
        $this->configureBackup(['backup_include_uploads' => '1']);
        $tempFilesBefore = glob(sys_get_temp_dir() . '/hv-backup-*');

        try {
            BackupService::run();
            $this->fail('Erwartete RuntimeException beim Uploads-Archiv.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Uploads-Archiv', $e->getMessage());
        }

        $dumps = glob(FakeS3Server::storageDir() . '/test-bucket__backups~backup-*.sql.gz');
        $this->assertCount(1, $dumps, 'Der Dump muss trotz Archivfehler am Ziel liegen');
        $this->assertStringContainsString('CREATE TABLE', (string)gzdecode((string)file_get_contents($dumps[0])));
        $this->assertSame([], glob(FakeS3Server::storageDir() . '/test-bucket__backups~uploads-*'));

        $this->assertSame('partial', $this->setting('backup_last_status'));
        $this->assertStringContainsString('Pfad zu lang', (string)$this->setting('backup_last_error'));
        $this->assertSame([], array_values(array_diff(glob(sys_get_temp_dir() . '/hv-backup-*'), $tempFilesBefore)));
    }

    /**
     * Audit N65: Ein Fehler beim Erstellen des Dumps landet im Status - und
     * die Verbindung ist danach sauber (keine offene Transaktion, alte
     * Zeitzone), sonst würde nicht einmal der Status gespeichert.
     */
    public function testDumpFehlerWirdAlsStatusErfasst(): void {
        $this->configureBackup();
        self::$db->exec("UPDATE settings SET setting_value = '0' WHERE setting_key = 'backup_last_run_at'");
        $zeitzoneVorher = self::$db->query('SELECT @@SESSION.time_zone')->fetchColumn();
        self::$db->exec('CREATE TABLE hv_test_basis (id INT)');
        self::$db->exec('CREATE VIEW hv_test_kaputt AS SELECT id FROM hv_test_basis');
        self::$db->exec('DROP TABLE hv_test_basis');
        try {
            try {
                BackupService::run();
                $this->fail('Erwartete Ausnahme bei ungültiger View.');
            } catch (\RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }

            $this->assertFalse(self::$db->inTransaction());
            $this->assertSame($zeitzoneVorher, self::$db->query('SELECT @@SESSION.time_zone')->fetchColumn());
            $this->assertSame('error', $this->setting('backup_last_status'));
            $this->assertGreaterThan(0, (int)$this->setting('backup_last_run_at'));
            $this->assertNotSame('', (string)$this->setting('backup_last_error'));
            $this->assertSame([], glob(FakeS3Server::storageDir() . '/test-bucket__backups~*'));
        } finally {
            self::$db->exec('DROP VIEW IF EXISTS hv_test_kaputt');
            self::$db->exec('DROP TABLE IF EXISTS hv_test_basis');
        }
    }

    /**
     * Eine Sicherung zur Zeit: Hält eine andere Verbindung die Sperre, bricht
     * run() mit klarer Meldung ab, statt parallel denselben Schlüssel zu
     * beschreiben.
     */
    public function testLaufendeSicherungBlockiertZweitenLauf(): void {
        $this->configureBackup();
        $andere = new PDO(
            "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $stmt = $andere->prepare('SELECT GET_LOCK(?, 0)');
        $stmt->execute([DbLock::lockName('backup:run')]);
        $this->assertSame(1, (int)$stmt->fetchColumn());
        BackupService::overrideLockWaitForTests(0);
        try {
            BackupService::run();
            $this->fail('Erwartete RuntimeException bei laufender Sicherung.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('läuft bereits eine Sicherung', $e->getMessage());
        } finally {
            $andere->prepare('SELECT RELEASE_LOCK(?)')->execute([DbLock::lockName('backup:run')]);
        }
        $this->assertSame([], glob(FakeS3Server::storageDir() . '/test-bucket__backups~*'));

        // Danach läuft die Sicherung wieder.
        BackupService::run();
        $this->assertSame('ok', $this->setting('backup_last_status'));
    }

    private function configureWebDavBackup(): string {
        FakeWebDavServer::ensureStarted();
        $ziel = 'backup-ziel-' . uniqid();
        $this->configureBackup([
            'backup_target' => BackupService::TARGET_WEBDAV,
            'backup_webdav_url' => FakeWebDavServer::baseUrl() . '/' . $ziel,
            'backup_webdav_user' => 'testuser',
            'backup_webdav_pass' => Crypto::encrypt('testpass'),
        ]);
        return FakeWebDavServer::storageDir() . '/' . $ziel . '/backups';
    }

    /**
     * Audit N65: Ein abgebrochener WebDAV-Upload hinterlässt ein angefangenes
     * Objekt. Es würde in der Rotation als neuestes zählen und ein intaktes
     * verdrängen - der Lauf räumt seinen eigenen Schlüssel deshalb weg.
     */
    public function testAbgebrochenerWebDavUploadWirdAmZielEntfernt(): void {
        $ordner = $this->configureWebDavBackup();
        $schalter = FakeWebDavServer::storageDir() . '/.put-fails';
        touch($schalter);
        try {
            try {
                BackupService::run();
                $this->fail('Erwartete RuntimeException beim Upload.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('PUT', $e->getMessage());
            }
            $this->assertSame([], glob($ordner . '/backup-*'), 'Das angefangene Objekt muss entfernt sein');
            $this->assertSame('error', $this->setting('backup_last_status'));

            // Ohne verfügbare Sperre lässt sich ein paralleler Lauf mit
            // demselben Schlüssel nicht ausschließen - dann wird am Ziel
            // bewusst nichts gelöscht.
            DbLock::simulateUnavailableForTests(true);
            try {
                BackupService::run();
                $this->fail('Erwartete RuntimeException beim Upload.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('PUT', $e->getMessage());
            }
            $this->assertCount(1, glob($ordner . '/backup-*'));
        } finally {
            @unlink($schalter);
        }
    }

    /**
     * Audit N82 im Zusammenspiel: Mit einer prozentkodierten WebDAV-Adresse
     * rotiert die Sicherung wieder.
     */
    public function testWebDavRotationMitProzentkodierterAdresse(): void {
        FakeWebDavServer::ensureStarted();
        $ziel = 'max%40verein.de/Meine%20Backups-' . uniqid();
        $this->configureBackup([
            'backup_target' => BackupService::TARGET_WEBDAV,
            'backup_webdav_url' => FakeWebDavServer::baseUrl() . '/' . $ziel,
            'backup_webdav_user' => 'testuser',
            'backup_webdav_pass' => Crypto::encrypt('testpass'),
            'backup_retention_count' => '1',
        ]);
        $ordner = FakeWebDavServer::storageDir() . '/' . $ziel . '/backups';
        mkdir($ordner, 0777, true);
        file_put_contents($ordner . '/backup-2020-01-01_000000.sql.gz', 'alt');

        BackupService::run();

        $this->assertFileDoesNotExist($ordner . '/backup-2020-01-01_000000.sql.gz');
        $this->assertCount(1, glob($ordner . '/backup-*'));
        $this->assertSame('ok', $this->setting('backup_last_status'));
    }
}
