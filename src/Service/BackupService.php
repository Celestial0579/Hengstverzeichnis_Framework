<?php
// src/Service/BackupService.php

namespace App\Service;

use App\Database;
use App\Security\Crypto;

/**
 * Class BackupService
 *
 * Automatisierte externe Backups (#59): sichert die Datenbank periodisch an
 * einen S3-kompatiblen Speicher (AWS S3, MinIO, Hetzner Object Storage o. Ä.)
 * - als Kernfunktion, nicht als optionales Plugin. Baut auf der Cron-/
 * Scheduler-Infrastruktur (#67, App\Service\Scheduler) auf.
 *
 * Bewusst NICHT enthalten: Sicherung hochgeladener Dateien (Logos/
 * Pferdebilder) - im Issue selbst nur als "ggf." (optional) genannt, die
 * Zucht-/Blutliniendaten in der Datenbank sind der eigentlich unwiederbringliche
 * Teil. Kann bei Bedarf als eigenständige Erweiterung nachgezogen werden.
 */
final class BackupService {

    private const TASK_NAME = 'backup.external';
    private const DEFAULT_INTERVAL_HOURS = 24;
    private const DEFAULT_RETENTION_COUNT = 14;
    private const OBJECT_PREFIX = 'backups/';

    /**
     * Registriert die Backup-Aufgabe beim Scheduler, falls in den
     * Admin-Einstellungen aktiviert und vollständig konfiguriert (siehe
     * isConfigured()) - wird bei jedem Request-Bootstrap aufgerufen
     * (public/index.php), analog zur Registrierung von Plugin-Hooks.
     */
    public static function registerScheduledTask(): void {
        $settings = self::loadSettings();
        if (!self::isConfigured($settings)) {
            return;
        }

        $intervalHours = max(1, (int)($settings['backup_interval_hours'] ?? self::DEFAULT_INTERVAL_HOURS));
        Scheduler::register(self::TASK_NAME, $intervalHours * 3600, [self::class, 'run']);
    }

    /**
     * @param array<string, string> $settings
     */
    public static function isConfigured(array $settings): bool {
        return ($settings['backup_enabled'] ?? '') === '1'
            && trim($settings['backup_s3_endpoint'] ?? '') !== ''
            && trim($settings['backup_s3_bucket'] ?? '') !== ''
            && trim($settings['backup_s3_access_key'] ?? '') !== ''
            && trim($settings['backup_s3_secret_key'] ?? '') !== '';
    }

    /**
     * Führt einen einzelnen Backup-Lauf durch: Datenbank-Dump erzeugen,
     * komprimieren, hochladen, danach Aufbewahrungsrotation anwenden. Wird
     * sowohl vom Scheduler (Cron-Trigger/manueller Admin-Klick) als auch von
     * AdminController::testBackup() (siehe dort) aufgerufen.
     *
     * @throws \RuntimeException Falls Backup nicht konfiguriert ist oder der
     *                           Upload fehlschlägt - der Aufrufer (Scheduler)
     *                           protokolliert das zentral im Audit-Log.
     */
    public static function run(): void {
        $settings = self::loadSettings();
        if (!self::isConfigured($settings)) {
            throw new \RuntimeException('Backup ist nicht (vollständig) konfiguriert.');
        }

        $client = self::buildClient($settings);

        $sql = DatabaseDumper::dump();
        $useGzip = function_exists('gzencode');
        $body = $useGzip ? gzencode($sql, 9) : $sql;
        $key = self::OBJECT_PREFIX . 'backup-' . gmdate('Y-m-d_His') . ($useGzip ? '.sql.gz' : '.sql');

        try {
            $client->putObject($key, $body, $useGzip ? 'application/gzip' : 'application/sql');
        } catch (\Throwable $e) {
            self::recordStatus('error', $e->getMessage());
            throw $e;
        }

        self::recordStatus('ok', null);

        // Aufbewahrungsrotation ist ein separater, nicht-kritischer Schritt:
        // ein bereits erfolgreich hochgeladenes Backup gilt unabhängig davon
        // als Erfolg (Datensicherheit erreicht), ein Rotationsfehler wird nur
        // protokolliert, nicht als Gesamtfehler des Laufs gewertet.
        try {
            self::applyRetention($client, $settings);
        } catch (\Throwable $e) {
            AuditLogger::log('Backup-Aufbewahrungsrotation fehlgeschlagen', 'settings', $e->getMessage());
        }
    }

    /**
     * @param array<string, string> $settings
     */
    private static function applyRetention(S3Client $client, array $settings): void {
        $keepCount = max(1, (int)($settings['backup_retention_count'] ?? self::DEFAULT_RETENTION_COUNT));
        $objects = $client->listObjects(self::OBJECT_PREFIX);

        // listObjects() liefert aufsteigend nach Schlüssel sortiert - durch das
        // "backup-<ISO-Zeitstempel>"-Namensschema entspricht das der
        // chronologischen Reihenfolge, älteste zuerst.
        $excess = count($objects) - $keepCount;
        for ($i = 0; $i < $excess; $i++) {
            $client->deleteObject($objects[$i]['key']);
        }
    }

    /**
     * @param array<string, string> $settings
     */
    private static function buildClient(array $settings): S3Client {
        $secretKey = Crypto::decrypt($settings['backup_s3_secret_key'] ?? '') ?? '';

        return new S3Client(
            trim($settings['backup_s3_endpoint'] ?? ''),
            trim($settings['backup_s3_region'] ?? '') ?: 'us-east-1',
            trim($settings['backup_s3_bucket'] ?? ''),
            trim($settings['backup_s3_access_key'] ?? ''),
            $secretKey,
            ($settings['backup_s3_path_style'] ?? '') === '1',
            // Standard: HTTPS (AWS S3 erzwingt es ohnehin). Abschaltbar für
            // selbstgehostetes MinIO/Object Storage ohne TLS in einem
            // vertrauenswürdigen internen Netz - Standardwert bei fehlendem
            // Setting bewusst "an" (sicherer Default).
            ($settings['backup_s3_use_https'] ?? '1') !== '0'
        );
    }

    private static function recordStatus(string $status, ?string $error): void {
        $db = Database::getInstance();
        $values = [
            'backup_last_status' => $status,
            'backup_last_run_at' => (string)time(),
            'backup_last_error' => $error ?? '',
        ];
        foreach ($values as $key => $value) {
            $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$key, $value, $value]);
        }

        AuditLogger::log(
            $status === 'ok' ? 'Externes Backup erfolgreich' : 'Externes Backup fehlgeschlagen',
            'settings',
            $error
        );
    }

    /**
     * @return array<string, string>
     */
    private static function loadSettings(): array {
        try {
            $db = Database::getInstance();
            $stmt = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'backup_%'");
            $settings = [];
            foreach ($stmt->fetchAll() as $row) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            return $settings;
        } catch (\Throwable $e) {
            // Fail-safe analog zu BaseController::loadSettings(): registerScheduledTask()
            // wird bei jedem Request-Bootstrap aufgerufen, auch bevor die Datenbank
            // eingerichtet ist (Setup-Assistent) - dann gilt Backup schlicht als nicht
            // konfiguriert, statt den gesamten Request mit einer Exception abzubrechen.
            return [];
        }
    }
}
