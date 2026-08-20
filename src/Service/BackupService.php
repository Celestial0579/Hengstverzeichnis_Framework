<?php
// src/Service/BackupService.php

namespace App\Service;

use App\Database;
use App\Security\Crypto;

/**
 * Class BackupService
 *
 * Automatisierte externe Backups (#59, #93): sichert die Datenbank
 * periodisch an ein von drei wählbaren Zielen - als Kernfunktion, nicht als
 * optionales Plugin. Baut auf der Cron-/Scheduler-Infrastruktur (#67,
 * App\Service\Scheduler) auf.
 *
 * Drei Ziel-Typen (`backup_target`-Einstellung, siehe TARGET_*-Konstanten):
 * - `s3` (Standard, Ursprungsimplementierung aus #59): S3-kompatibler
 *   Objektspeicher (AWS S3, MinIO, Hetzner Object Storage o. Ä.).
 * - `ftps` (#93): FTPS-Zugang, z. B. bereits beim Hoster vorhanden.
 * - `webdav` (#93): WebDAV, z. B. eine bereits genutzte Nextcloud-/
 *   ownCloud-Instanz des Vereins.
 * Die eigentliche Übertragung ist hinter App\Service\BackupTarget
 * abstrahiert (S3Client/FtpsClient/WebDavClient) - der Rest dieser Klasse
 * (Dump-Erzeugung, Aufbewahrungsrotation, Status-Protokollierung) ist
 * bewusst komplett ziel-unabhängig.
 *
 * Uploads (#233): Opt-in-Einstellung `backup_include_uploads` - zusätzlich
 * zum SQL-Dump wird ein tar-Archiv von public/uploads (Logos/Pferdebilder/
 * Galerie-Dateien, siehe App\Service\TarArchive) ans selbe Ziel hochgeladen,
 * mit derselben Aufbewahrungsrotation wie der SQL-Dump. Standard bleibt
 * "aus": Die Zucht-/Blutliniendaten in der Datenbank sind der eigentlich
 * unwiederbringliche Teil, das Uploads-Archiv kann je nach Bildbestand groß
 * werden.
 */
final class BackupService {

    private const TASK_NAME = 'backup.external';
    private const DEFAULT_INTERVAL_HOURS = 24;
    private const DEFAULT_RETENTION_COUNT = 14;
    private const OBJECT_PREFIX = 'backups/';

    public const TARGET_S3 = 's3';
    public const TARGET_FTPS = 'ftps';
    public const TARGET_WEBDAV = 'webdav';

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
        if (($settings['backup_enabled'] ?? '') !== '1') {
            return false;
        }

        return match (self::targetType($settings)) {
            self::TARGET_FTPS => trim($settings['backup_ftps_host'] ?? '') !== ''
                && trim($settings['backup_ftps_user'] ?? '') !== ''
                && trim($settings['backup_ftps_pass'] ?? '') !== '',
            self::TARGET_WEBDAV => trim($settings['backup_webdav_url'] ?? '') !== ''
                && trim($settings['backup_webdav_user'] ?? '') !== ''
                && trim($settings['backup_webdav_pass'] ?? '') !== '',
            default => trim($settings['backup_s3_endpoint'] ?? '') !== ''
                && trim($settings['backup_s3_bucket'] ?? '') !== ''
                && trim($settings['backup_s3_access_key'] ?? '') !== ''
                && trim($settings['backup_s3_secret_key'] ?? '') !== '',
        };
    }

    /**
     * @param array<string, string> $settings
     */
    private static function targetType(array $settings): string {
        $target = $settings['backup_target'] ?? self::TARGET_S3;
        return in_array($target, [self::TARGET_S3, self::TARGET_FTPS, self::TARGET_WEBDAV], true)
            ? $target
            : self::TARGET_S3;
    }

    /**
     * Führt einen einzelnen Backup-Lauf durch: Datenbank-Dump streamend
     * erzeugen (#231), komprimieren, hochladen - bei aktivierter Option
     * zusätzlich das tar-Archiv der Uploads (#233) -, danach die
     * Aufbewahrungsrotation anwenden. Wird
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

        $useGzip = function_exists('gzopen');
        $stamp = gmdate('Y-m-d_His');
        $dumpFile = null;
        $uploadsFile = null;

        try {
            // Dump streamend (#231) in eine Temp-Datei schreiben - über
            // DatabaseDumper::dumpTo() direkt in den gzip-Stream, der Dump
            // liegt nie als Gesamtstring im Speicher.
            $dumpFile = self::tempFile('hv-backup-sql-');
            self::writeDumpFile($dumpFile, $useGzip);
            $dumpKey = self::OBJECT_PREFIX . 'backup-' . $stamp . ($useGzip ? '.sql.gz' : '.sql');

            // Uploads-Archiv (#233, Opt-in) ebenfalls streamend in eine
            // Temp-Datei bauen (App\Service\TarArchive), dann hochladen.
            $uploadsKey = null;
            if (self::includeUploads($settings)) {
                $uploadsFile = self::tempFile('hv-backup-uploads-');
                self::writeUploadsArchive($uploadsFile, $useGzip);
                $uploadsKey = self::OBJECT_PREFIX . 'uploads-' . $stamp . ($useGzip ? '.tar.gz' : '.tar');
            }

            try {
                // Streamender Upload (#237): Die Ziel-Clients übernehmen die
                // fertige (komprimierte) Temp-Datei direkt - über die gesamte
                // Backup-Kette (Dump #231, Archiv #233, Upload #237) wird der
                // Inhalt damit nie als Gesamtstring in den Speicher geladen.
                $client->putObjectFromFile($dumpKey, $dumpFile, $useGzip ? 'application/gzip' : 'application/sql');
                if ($uploadsKey !== null) {
                    $client->putObjectFromFile($uploadsKey, $uploadsFile, $useGzip ? 'application/gzip' : 'application/x-tar');
                }
            } catch (\Throwable $e) {
                self::recordStatus('error', $e->getMessage());
                throw $e;
            }
        } finally {
            if ($dumpFile !== null) {
                @unlink($dumpFile);
            }
            if ($uploadsFile !== null) {
                @unlink($uploadsFile);
            }
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
     * Aufbewahrungsrotation, getrennt je Backup-Art (#233): SQL-Dumps
     * (`backup-…`) und Uploads-Archive (`uploads-…`) werden unabhängig
     * voneinander auf die konfigurierte Anzahl gehalten - sonst würde ein
     * Lauf mit beiden Objekten die effektive Dump-Aufbewahrung halbieren.
     * Uploads-Archive rotieren auch dann weiter, wenn die Option inzwischen
     * deaktiviert ist (es kommen dann schlicht keine neuen hinzu).
     *
     * @param array<string, string> $settings
     */
    private static function applyRetention(BackupTarget $client, array $settings): void {
        $keepCount = max(1, (int)($settings['backup_retention_count'] ?? self::DEFAULT_RETENTION_COUNT));
        $objects = $client->listObjects(self::OBJECT_PREFIX);

        foreach (['backup-', 'uploads-'] as $kindPrefix) {
            // listObjects() liefert aufsteigend nach Schlüssel sortiert - durch
            // das "<Art>-<ISO-Zeitstempel>"-Namensschema entspricht das je Art
            // der chronologischen Reihenfolge, älteste zuerst.
            $kind = array_values(array_filter(
                $objects,
                fn(array $object) => str_starts_with($object['key'], self::OBJECT_PREFIX . $kindPrefix)
            ));
            $excess = count($kind) - $keepCount;
            for ($i = 0; $i < $excess; $i++) {
                $client->deleteObject($kind[$i]['key']);
            }
        }
    }

    /**
     * @param array<string, string> $settings
     */
    private static function includeUploads(array $settings): bool {
        return ($settings['backup_include_uploads'] ?? '') === '1';
    }

    /**
     * Schreibt den Datenbank-Dump streamend (#231) in die Zieldatei -
     * gzip-komprimiert, sofern die zlib-Extension vorhanden ist.
     */
    private static function writeDumpFile(string $path, bool $gzip): void {
        $handle = $gzip ? gzopen($path, 'wb9') : fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Backup-Zwischendatei nicht schreibbar: {$path}");
        }
        try {
            DatabaseDumper::dumpTo(function (string $chunk) use ($handle, $gzip): void {
                $ok = $gzip ? gzwrite($handle, $chunk) : fwrite($handle, $chunk);
                if ($ok === false) {
                    throw new \RuntimeException('Schreiben des Datenbank-Dumps fehlgeschlagen.');
                }
            });
        } finally {
            $gzip ? gzclose($handle) : fclose($handle);
        }
    }

    /**
     * Baut das tar(.gz)-Archiv des Uploads-Verzeichnisses streamend in die
     * Zieldatei (#233). Ein fehlendes oder leeres Uploads-Verzeichnis ergibt
     * ein gültiges leeres Archiv - der Lauf bleibt damit deterministisch,
     * statt je nach Instanzzustand Objekte auszulassen.
     */
    private static function writeUploadsArchive(string $path, bool $gzip): void {
        $archive = TarArchive::create($path, $gzip);
        $dir = self::uploadsDir();
        if (is_dir($dir)) {
            $archive->addDirectoryTree($dir, 'uploads');
        }
        // Pferdefotos liegen seit #366 außerhalb des Webroots und damit
        // außerhalb von public/uploads. Ohne diese zweite Zeile enthielte
        // "Hochgeladene Dateien mitsichern" plötzlich keine Pferdefotos mehr -
        // und das fiele erst beim Zurückspielen auf. Sie landen im Archiv an
        // ihrer alten Stelle (uploads/horses), damit der Inhalt derselbe
        // bleibt wie vor der Verschiebung.
        $horses = \App\Helper\HorseImagePath::dir();
        if (is_dir($horses)) {
            $archive->addDirectoryTree($horses, 'uploads/horses');
        }
        $archive->close();
    }

    private static ?string $uploadsDirOverride = null;

    /**
     * Nur für Tests: das zu sichernde Uploads-Verzeichnis umbiegen (analog
     * Scheduler::resetForTests()), damit Integrationstests nicht vom echten
     * public/uploads des Arbeitsverzeichnisses abhängen. `null` stellt den
     * Normalzustand wieder her.
     */
    public static function overrideUploadsDirForTests(?string $dir): void {
        self::$uploadsDirOverride = $dir;
    }

    private static function uploadsDir(): string {
        return self::$uploadsDirOverride ?? dirname(__DIR__, 2) . '/public/uploads';
    }

    private static function tempFile(string $prefix): string {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw new \RuntimeException('Konnte keine temporäre Backup-Datei anlegen.');
        }
        return $path;
    }

    /**
     * @param array<string, string> $settings
     */
    private static function buildClient(array $settings): BackupTarget {
        return match (self::targetType($settings)) {
            self::TARGET_FTPS => new FtpsClient(
                trim($settings['backup_ftps_host'] ?? ''),
                max(1, (int)($settings['backup_ftps_port'] ?? 21)),
                trim($settings['backup_ftps_user'] ?? ''),
                Crypto::decrypt($settings['backup_ftps_pass'] ?? '') ?? '',
                trim($settings['backup_ftps_path'] ?? '')
            ),
            self::TARGET_WEBDAV => new WebDavClient(
                rtrim(trim($settings['backup_webdav_url'] ?? ''), '/'),
                trim($settings['backup_webdav_user'] ?? ''),
                Crypto::decrypt($settings['backup_webdav_pass'] ?? '') ?? ''
            ),
            default => new S3Client(
                trim($settings['backup_s3_endpoint'] ?? ''),
                trim($settings['backup_s3_region'] ?? '') ?: 'us-east-1',
                trim($settings['backup_s3_bucket'] ?? ''),
                trim($settings['backup_s3_access_key'] ?? ''),
                Crypto::decrypt($settings['backup_s3_secret_key'] ?? '') ?? '',
                ($settings['backup_s3_path_style'] ?? '') === '1',
                // Standard: HTTPS (AWS S3 erzwingt es ohnehin). Abschaltbar für
                // selbstgehostetes MinIO/Object Storage ohne TLS in einem
                // vertrauenswürdigen internen Netz - Standardwert bei fehlendem
                // Setting bewusst "an" (sicherer Default).
                ($settings['backup_s3_use_https'] ?? '1') !== '0'
            ),
        };
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
