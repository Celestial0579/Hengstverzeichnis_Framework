<?php
// tests/Functional/BackupSettingsTest.php

namespace Tests\Functional;

/**
 * HTTP-Funktionstests für die Backup-Verwaltung (#59,
 * src/Controllers/AdminController.php: backupSettings()/
 * updateBackupSettings()/testBackup()): Admin-Pflicht, CSRF-Schutz und dass
 * gespeicherte Einstellungen auf der Seite wieder auftauchen. Ein
 * tatsächlicher S3-Upload gegen einen echten/simulierten Speicher ist nicht
 * Teil dieses Tests (siehe tests/Integration/ExternalBackupTest.php, das
 * App\Service\BackupService direkt gegen den lokalen Fake-S3-Server prüft) -
 * ohne konfigurierten Endpunkt schlägt der manuelle "Jetzt sichern"-Button
 * hier daher erwartungsgemäß fehl.
 */
class BackupSettingsTest extends FunctionalTestCase {

    public function testBackupSettingsPageRequiresAdmin(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $editor = $this->createAndLoginEditor($admin, "backuptester{$unique}", "backup-test-{$unique}@example.com");

        $response = $editor->get('/admin/backups');
        $this->assertSame(403, $response->statusCode);
    }

    public function testBackupSettingsPageIsReachableForAdmin(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->get('/admin/backups');
        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('Backups', $response->body);
    }

    public function testUpdateBackupSettingsRequiresCsrfToken(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->post('/admin/backups', ['backup_s3_bucket' => 'irrelevant']);
        $this->assertSame(403, $response->statusCode);
    }

    public function testSavedSettingsAreReflectedOnTheSettingsPage(): void {
        $admin = $this->authenticatedClient();

        $formPage = $admin->get('/admin/backups');
        $response = $admin->post('/admin/backups', [
            'csrf_token' => $formPage->formField('csrf_token') ?? '',
            'backup_enabled' => '1',
            'backup_include_uploads' => '1',
            'backup_s3_endpoint' => 'fake-endpoint.example.com',
            'backup_s3_region' => 'eu-central-1',
            'backup_s3_bucket' => 'functional-test-bucket',
            'backup_s3_access_key' => 'FUNCTIONALTESTKEY',
            'backup_s3_secret_key' => 'super-secret-value',
            'backup_s3_path_style' => '1',
            'backup_interval_hours' => '12',
            'backup_retention_count' => '7',
        ]);
        $this->assertSame('/admin/backups?success=1', $response->location());

        $settingsPage = $admin->get('/admin/backups');
        $this->assertStringContainsString('fake-endpoint.example.com', $settingsPage->body);
        $this->assertStringContainsString('functional-test-bucket', $settingsPage->body);
        $this->assertStringContainsString('FUNCTIONALTESTKEY', $settingsPage->body);
        // Secret Key selbst wird nie im Klartext ausgegeben, nur der Platzhalter.
        $this->assertStringNotContainsString('super-secret-value', $settingsPage->body);
        $this->assertStringContainsString('unverändert', $settingsPage->body);
        // Opt-in "Uploads mitsichern" (#233) wird gespeichert und angehakt angezeigt.
        $this->assertStringContainsString('name="backup_include_uploads" value="1" checked', $settingsPage->body);
    }

    public function testUploadsOptionDefaultsToUncheckedAndCanBeDisabledAgain(): void {
        $admin = $this->authenticatedClient();

        $formPage = $admin->get('/admin/backups');
        // Checkbox nicht mitsenden = deaktivieren (HTML-Checkbox-Semantik).
        $admin->post('/admin/backups', [
            'csrf_token' => $formPage->formField('csrf_token') ?? '',
            'backup_enabled' => '0',
        ]);

        $settingsPage = $admin->get('/admin/backups');
        $this->assertStringContainsString('name="backup_include_uploads"', $settingsPage->body);
        $this->assertStringNotContainsString('name="backup_include_uploads" value="1" checked', $settingsPage->body);
    }

    public function testTestBackupRequiresCsrfToken(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->post('/admin/backups/test', []);
        $this->assertSame(403, $response->statusCode);
    }

    public function testTestBackupWithoutS3EndpointFailsGracefully(): void {
        $admin = $this->authenticatedClient();

        $formPage = $admin->get('/admin/backups');
        $admin->post('/admin/backups', [
            'csrf_token' => $formPage->formField('csrf_token') ?? '',
            'backup_enabled' => '0',
            'backup_s3_endpoint' => '',
            'backup_s3_bucket' => '',
            'backup_s3_access_key' => '',
            'backup_s3_secret_key' => '',
        ]);

        $response = $admin->post('/admin/backups/test', [
            'csrf_token' => $this->currentCsrfToken($admin),
        ]);

        $this->assertSame(302, $response->statusCode);
        $this->assertStringStartsWith('/admin/backups?error=', $response->location());
    }

    /**
     * Audit N67: Ein riesiges Intervall wird beim Speichern auf 8760 h
     * begrenzt. Ein früher gespeicherter Riesenwert heilt beim Lesen - vorher
     * scheiterte JEDER Request beim Anmelden der Cron-Aufgabe, auch die
     * Admin-Seite, auf der er sich hätte korrigieren lassen.
     */
    public function testHugeIntervalIsClampedAndDoesNotBreakTheSite(): void {
        $admin = $this->authenticatedClient();
        $db = \App\Database::getInstance();
        $vorher = [];
        foreach (['backup_enabled', 'backup_interval_hours'] as $key) {
            $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            $vorher[$key] = $stmt->fetchColumn();
        }

        try {
            $formPage = $admin->get('/admin/backups');
            $response = $admin->post('/admin/backups', array_merge([
                'csrf_token' => $formPage->formField('csrf_token') ?? '',
                'backup_enabled' => '1',
                'backup_interval_hours' => '9999999999999999',
            ], [
                'backup_s3_endpoint' => 'fake-endpoint.example.com',
                'backup_s3_region' => 'eu-central-1',
                'backup_s3_bucket' => 'functional-test-bucket',
                'backup_s3_access_key' => 'FUNCTIONALTESTKEY',
                'backup_s3_secret_key' => 'super-secret-value',
                'backup_s3_path_style' => '1',
                'backup_retention_count' => '7',
            ]));
            $this->assertSame('/admin/backups?success=1', $response->location());

            $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'backup_interval_hours'");
            $stmt->execute();
            $this->assertSame('8760', $stmt->fetchColumn());

            foreach (['/', '/admin/backups', '/admin/cron'] as $pfad) {
                $this->assertSame(200, $admin->get($pfad)->statusCode, "{$pfad} nach dem Speichern");
            }
            $this->assertSame('8760', $admin->get('/admin/backups')->formField('backup_interval_hours'));

            // Altbestand aus der Zeit vor der Grenze, direkt in der Datenbank.
            $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'backup_interval_hours'")
                ->execute(['99999999999999999999']);
            $this->assertSame(200, $this->newClient()->get('/')->statusCode, 'Startseite mit Altbestand');
            $cronSeite = $admin->get('/admin/cron');
            $this->assertSame(200, $cronSeite->statusCode);
            // Die Aufgabe ist trotz Altbestand angemeldet - nicht bloss vom
            // Auffangnetz in public/index.php verschluckt.
            $this->assertStringContainsString('backup.external', $cronSeite->body);
            $this->assertSame('8760', $admin->get('/admin/backups')->formField('backup_interval_hours'));
        } finally {
            foreach ($vorher as $key => $value) {
                if ($value === false) {
                    $db->prepare("DELETE FROM settings WHERE setting_key = ?")->execute([$key]);
                } else {
                    $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?")->execute([$value, $key]);
                }
            }
        }
    }
}
