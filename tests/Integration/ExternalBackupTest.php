<?php
// tests/Integration/ExternalBackupTest.php

namespace Tests\Integration;

use App\Database;
use App\Security\Crypto;
use App\Service\BackupService;
use App\Service\Scheduler;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeS3Server;

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

    protected function tearDown(): void {
        BackupService::overrideUploadsDirForTests(null);
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

        $this->assertSame($tempFilesBefore, glob(sys_get_temp_dir() . '/hv-backup-*'), 'Temp-Dateien des fehlgeschlagenen Laufs wurden nicht aufgeräumt');
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
}
