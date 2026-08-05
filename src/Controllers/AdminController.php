<?php
// src/Controllers/AdminController.php

namespace App\Controllers;

use App\Database;

class AdminController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
    }



    public function dashboard(): void {
        // Plugin-Hook (#56): Erweiterungspunkt für zusätzliche Kacheln im Dashboard.
        // Erwartetes Format je Eintrag: ['url' => string, 'label' => string, 'icon' => string].
        $pluginTiles = $this->hooks()->applyFilters('admin.dashboard_tiles', []);

        $this->render('admin_dashboard', [
            'title' => 'Admin Dashboard',
            'pluginTiles' => $pluginTiles
        ]);
    }

    public function settings(): void {
        $this->requireAdmin();
        $this->render('admin_settings', ['title' => 'Branding Einstellungen']);
    }

    public function updateSettings(): void {
        $this->requireAdmin();
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $db = Database::getInstance();

        // 1. Text settings
        $settingsToUpdate = [
            'site_name' => trim($_POST['site_name'] ?? ''),
            'copyright_holder' => trim($_POST['copyright_holder'] ?? ''),
            'primary_color' => trim($_POST['primary_color'] ?? '#2a52be'),
            'secondary_color' => trim($_POST['secondary_color'] ?? '#4b6bba'),
            'home_title' => trim($_POST['home_title'] ?? ''),
            'home_text' => trim($_POST['home_text'] ?? ''),
            'impressum_text' => trim($_POST['impressum_text'] ?? ''),
            'datenschutz_text' => trim($_POST['datenschutz_text'] ?? '')
        ];

        foreach ($settingsToUpdate as $key => $value) {
            $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$key, $value, $value]);
        }

        // 2. Handle Logo Removal if requested
        if (!empty($_POST['remove_logo'])) {
            $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'site_logo'");
            $stmt->execute();
            $oldLogo = $stmt->fetchColumn();

            if ($oldLogo && str_starts_with($oldLogo, '/uploads/branding/')) {
                $filePath = __DIR__ . '/../../public' . $oldLogo;
                if (file_exists($filePath)) @unlink($filePath);
            }

            $stmt = $db->prepare("DELETE FROM settings WHERE setting_key IN ('site_logo', 'logo_url')");
            $stmt->execute();
        }

        // 3. Handle New Logo Upload
        if (!empty($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK && $_FILES['logo_file']['size'] > 0) {
            $file = $_FILES['logo_file'];
            if ($file['size'] <= 5 * 1024 * 1024) {
                // SVG bewusst nicht erlaubt: kann eingebettete <script>-Tags enthalten,
                // die bei direktem Aufruf der Datei-URL im Browser ausgeführt würden.
                $allowedMimeTypes = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                ];

                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']);

                if (isset($allowedMimeTypes[$mime])) {
                    $ext = $allowedMimeTypes[$mime];
                    $uploadDir = __DIR__ . '/../../public/uploads/branding/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }

                    $filename = 'logo_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $targetPath = $uploadDir . $filename;

                    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                        $newLogoUrl = '/uploads/branding/' . $filename;
                        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('site_logo', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                        $stmt->execute([$newLogoUrl, $newLogoUrl]);
                    }
                }
            }
        }

        \App\Service\AuditLogger::log("Branding-Einstellungen aktualisiert", "settings", "Verbandsname: " . ($settingsToUpdate['site_name'] ?? ''));

        header("Location: /admin/settings?success=1");
        exit;
    }

    public function systemSettings(): void {
        $this->requireAdmin();

        $trustedProxiesFromEnv = getenv('TRUSTED_PROXIES') !== false;
        $trackingDomainsFromEnv = getenv('TRACKING_DOMAINS') !== false;
        $this->render('admin_system_settings', [
            'title' => 'Systemeinstellungen',
            'trustedProxies' => $trustedProxiesFromEnv ? getenv('TRUSTED_PROXIES') : (SetupController::readDbConfig()['trusted_proxies'] ?? ''),
            'trustedProxiesFromEnv' => $trustedProxiesFromEnv,
            'trackingDomains' => $trackingDomainsFromEnv ? getenv('TRACKING_DOMAINS') : (SetupController::readDbConfig()['tracking_domains'] ?? ''),
            'trackingDomainsFromEnv' => $trackingDomainsFromEnv,
            'availableLocales' => \App\I18n\Translator::getAvailableLocales(),
        ]);
    }

    public function updateSystemSettings(): void {
        $this->requireAdmin();
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $db = Database::getInstance();

        $baseUrl = trim($_POST['base_url'] ?? '');
        $isHttpWarning = false;

        if (!empty($baseUrl)) {
            if (!str_starts_with($baseUrl, 'http://') && !str_starts_with($baseUrl, 'https://')) {
                $baseUrl = 'https://' . $baseUrl;
            }

            if (str_starts_with($baseUrl, 'http://')) {
                $isHttpWarning = true;
            }

            $baseUrl = rtrim($baseUrl, '/') . '/';

            $parsedUrl = parse_url($baseUrl);
            $host = $parsedUrl['host'] ?? '';

            // Verteidigung in die Tiefe (OWASP SSRF Cheat Sheet): "localhost" sowie
            // literale private/reservierte IPs als Host blockieren. base_url wird
            // aktuell nur zur Erzeugung von Links genutzt (Mailer.php, layout.php)
            // und nie serverseitig abgerufen - kein aktiver SSRF-Sink -, das soll
            // aber auch so bleiben, falls hier künftig ein serverseitiger Abruf
            // hinzukommt.
            $isPrivateOrLoopbackIp = filter_var($host, FILTER_VALIDATE_IP) !== false
                && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;

            if (
                filter_var(rtrim($baseUrl, '/'), FILTER_VALIDATE_URL) === false
                || empty($host)
                || strcasecmp($host, 'localhost') === 0
                || $isPrivateOrLoopbackIp
            ) {
                header("Location: /admin/system-settings?error=invalid_base_url");
                exit;
            }
        }

        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('base_url', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$baseUrl, $baseUrl]);

        \App\Service\AuditLogger::log("Systemeinstellungen aktualisiert", "settings", "Stamm-URL: " . $baseUrl);

        // Standardsprache (#48): fällt auf 'de' zurück, falls ein unbekannter
        // Wert übermittelt wird (z. B. manipuliertes Formular) - fail-safe statt
        // Fehlerseite, da eine falsche Sprache keine sicherheitsrelevante Wirkung hat.
        $language = trim($_POST['language'] ?? 'de');
        if (!isset(\App\I18n\Translator::getAvailableLocales()[$language])) {
            $language = 'de';
        }
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('language', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$language, $language]);
        \App\Service\AuditLogger::log("Systemeinstellungen aktualisiert", "settings", "Standardsprache: " . $language);

        // Trusted Proxies: nur verarbeiten, wenn nicht bereits per Env-Var vorgegeben
        // (sonst hätte eine Änderung hier ohnehin keine Wirkung, siehe config/config.php).
        $trustedProxiesError = null;
        if (getenv('TRUSTED_PROXIES') === false) {
            $trustedProxiesRaw = trim($_POST['trusted_proxies'] ?? '');
            $entries = $trustedProxiesRaw === '' ? [] : array_map('trim', explode(',', $trustedProxiesRaw));
            foreach ($entries as $entry) {
                if ($entry === '' || \App\Security\ClientIp::isValidProxyEntry($entry)) {
                    continue;
                }
                $trustedProxiesError = $entry;
                break;
            }

            if ($trustedProxiesError === null) {
                $normalized = implode(',', array_filter($entries, fn($e) => $e !== ''));
                if (!SetupController::writeDbConfigValue('trusted_proxies', $normalized)) {
                    header("Location: /admin/system-settings?error=trusted_proxies_write_failed");
                    exit;
                }
                \App\Service\AuditLogger::log("Trusted Proxies aktualisiert", "security", "Wert: " . ($normalized !== '' ? $normalized : '(leer)'));
            }
        }

        // Tracking-Code (Matomo/Google Analytics o. ä.): rohes HTML/JS-Snippet, wird
        // absichtlich unescaped in layout.php ausgegeben - Admin-only vertrauenswürdige
        // Eingabe (requireAdmin() oben), siehe layout.php für die Begründung.
        $trackingCode = trim($_POST['tracking_code'] ?? '');
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('tracking_code', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$trackingCode, $trackingCode]);
        \App\Service\AuditLogger::log("Systemeinstellungen aktualisiert", "settings", "Tracking-Code " . ($trackingCode !== '' ? 'gesetzt' : 'entfernt'));

        // Tracking-Domains: nur verarbeiten, wenn nicht bereits per Env-Var vorgegeben
        // (sonst hätte eine Änderung hier ohnehin keine Wirkung, siehe config/config.php).
        // Nur echte https://-Origins ohne Pfad werden akzeptiert, da dieser Wert direkt
        // in die Content-Security-Policy einfließt (siehe config/config.php).
        $trackingDomainsError = null;
        if (getenv('TRACKING_DOMAINS') === false) {
            $trackingDomainsRaw = trim($_POST['tracking_domains'] ?? '');
            $domainEntries = $trackingDomainsRaw === '' ? [] : array_map('trim', explode(',', $trackingDomainsRaw));
            foreach ($domainEntries as $entry) {
                if ($entry === '' || preg_match('#^https://[a-zA-Z0-9.-]+(:\d+)?$#', $entry) === 1) {
                    continue;
                }
                $trackingDomainsError = $entry;
                break;
            }

            if ($trackingDomainsError === null) {
                $normalizedDomains = implode(',', array_filter($domainEntries, fn($e) => $e !== ''));
                if (!SetupController::writeDbConfigValue('tracking_domains', $normalizedDomains)) {
                    header("Location: /admin/system-settings?error=tracking_domains_write_failed");
                    exit;
                }
                \App\Service\AuditLogger::log("Tracking-Domains aktualisiert", "security", "Wert: " . ($normalizedDomains !== '' ? $normalizedDomains : '(leer)'));
            }
        }

        $redirectUrl = "/admin/system-settings?success=1" . ($isHttpWarning ? "&warning=http_unencrypted" : "") . ($trustedProxiesError !== null ? "&error=trusted_proxies_invalid&invalid_entry=" . urlencode($trustedProxiesError) : "") . ($trackingDomainsError !== null ? "&error=tracking_domains_invalid&invalid_entry=" . urlencode($trackingDomainsError) : "");
        header("Location: " . $redirectUrl);
        exit;
    }

    public function mailSettings(): void {
        $this->requireAdmin();
        $db = Database::getInstance();
        $stmt = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'mail_%' OR setting_key LIKE 'smtp_%' OR setting_key = 'admin_notification_email'");
        $rows = $stmt->fetchAll();

        $settings = [];
        foreach ($rows as $r) {
            $settings[$r['setting_key']] = $r['setting_value'];
        }

        $errorMessages = [
            'invalid_mail_from_email' => 'Ungültiges Format der Absender-E-Mail-Adresse. Es wurden keine Änderungen gespeichert.',
            'invalid_admin_notification_email' => 'Ungültiges Format der Admin-Benachrichtigungs-E-Mail-Adresse. Es wurden keine Änderungen gespeichert.',
        ];

        $this->render('admin_mail_settings', [
            'title' => 'E-Mail & SMTP Einstellungen',
            'settings' => $settings,
            'error' => $errorMessages[$_GET['error'] ?? ''] ?? null
        ]);
    }

    public function updateMailSettings(): void {
        $this->requireAdmin();
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $encryption = strtolower(trim($_POST['smtp_encryption'] ?? 'tls'));
        if (!in_array($encryption, ['ssl', 'tls'], true)) {
            $encryption = 'tls'; // Enforce SSL or TLS only!
        }

        $mailDriver = trim($_POST['mail_driver'] ?? 'smtp');
        $smtpHost = trim($_POST['smtp_host'] ?? '');
        $smtpPort = (int)($_POST['smtp_port'] ?? 587);
        $smtpUser = trim($_POST['smtp_user'] ?? '');
        $mailFromEmail = trim($_POST['mail_from_email'] ?? '');
        $mailFromName = trim($_POST['mail_from_name'] ?? '');
        $adminNotificationEmail = trim($_POST['admin_notification_email'] ?? '');

        if ($mailFromEmail !== '' && filter_var($mailFromEmail, FILTER_VALIDATE_EMAIL) === false) {
            header("Location: /admin/mail-settings?error=invalid_mail_from_email");
            exit;
        }
        if ($adminNotificationEmail !== '' && filter_var($adminNotificationEmail, FILTER_VALIDATE_EMAIL) === false) {
            header("Location: /admin/mail-settings?error=invalid_admin_notification_email");
            exit;
        }

        $db = Database::getInstance();

        $settings = [
            'mail_driver' => $mailDriver,
            'smtp_host' => $smtpHost,
            'smtp_port' => $smtpPort,
            'smtp_encryption' => $encryption,
            'smtp_user' => $smtpUser,
            'mail_from_email' => $mailFromEmail,
            'mail_from_name' => $mailFromName,
            'admin_notification_email' => $adminNotificationEmail
        ];

        // Encrypt SMTP password if updated
        if (!empty($_POST['smtp_pass'])) {
            $settings['smtp_pass'] = \App\Security\Crypto::encrypt($_POST['smtp_pass']);
        }

        foreach ($settings as $key => $val) {
            $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$key, $val, $val]);
        }

        header("Location: /admin/mail-settings?success=saved");
        exit;
    }

    public function testMail(): void {
        $this->requireAdmin();
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $testEmail = trim($_POST['test_email'] ?? '');
        if (empty($testEmail) || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            $db = Database::getInstance();
            $stmt = $db->query("SELECT setting_key, setting_value FROM settings");
            $rows = $stmt->fetchAll();
            $settings = [];
            foreach ($rows as $r) $settings[$r['setting_key']] = $r['setting_value'];

            $this->render('admin_mail_settings', [
                'title' => 'E-Mail & SMTP Einstellungen',
                'settings' => $settings,
                'error' => 'Bitte eine gültige Empfänger-E-Mail-Adresse für den Test eingeben.'
            ]);
            return;
        }

        $mailer = new \App\Service\Mailer();
        $sent = $mailer->send($testEmail, 'Test E-Mail - Hengstverzeichnis Framework', '<p>Hallo!</p><p>Dies ist eine erfolgreiche Test-E-Mail Ihres Verbandsportals.</p>');

        if ($sent) {
            header("Location: /admin/mail-settings?success=test_sent");
            exit;
        }

        $db = Database::getInstance();
        $stmt = $db->query("SELECT setting_key, setting_value FROM settings");
        $rows = $stmt->fetchAll();
        $settings = [];
        foreach ($rows as $r) $settings[$r['setting_key']] = $r['setting_value'];

        $this->render('admin_mail_settings', [
            'title' => 'E-Mail & SMTP Einstellungen',
            'settings' => $settings,
            'error' => 'Der E-Mail-Versand ist fehlgeschlagen. Bitte überprüfen Sie die SMTP-Serverdaten, den Port (465/587) und das Passwort in den Server-Logs.'
        ]);
    }

    public function resetSystem(): void {
        $this->requireAdmin();
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $confirmText = trim($_POST['confirm_text'] ?? '');
        if ($confirmText !== 'RESET') {
            header("Location: /admin/system-settings?error=reset_confirm_failed");
            exit;
        }

        $db = Database::getInstance();

        // Reset-Vorgang protokollieren, bevor die Daten gelöscht werden (Audit-Log bleibt über Resets hinweg erhalten)
        \App\Service\AuditLogger::log("System zurückgesetzt (Reset)", "settings", "Alle Daten außer dem Audit-Log wurden auf Werkseinstellungen zurückgesetzt.");

        // Disable foreign key checks to allow truncating/wiping tables cleanly
        $db->exec("SET FOREIGN_KEY_CHECKS = 0;");
        $db->exec("TRUNCATE TABLE horse_persons;");
        $db->exec("TRUNCATE TABLE breeding_stations;");
        $db->exec("TRUNCATE TABLE password_resets;");
        $db->exec("TRUNCATE TABLE gdpr_requests;");
        $db->exec("TRUNCATE TABLE horses;");
        $db->exec("TRUNCATE TABLE persons;");
        $db->exec("TRUNCATE TABLE users;");
        $db->exec("TRUNCATE TABLE settings;");
        $db->exec("SET FOREIGN_KEY_CHECKS = 1;");

        // Optionally remove db_config.php to force full database re-setup
        $dbConfigFile = __DIR__ . '/../../config/db_config.php';
        if (file_exists($dbConfigFile)) {
            @unlink($dbConfigFile);
        }

        // Destroy session and redirect to setup wizard
        session_destroy();
        header("Location: /setup?reset=completed");
        exit;
    }

    /**
     * Cron-/Scheduler-Verwaltung (#67, siehe App\Service\Scheduler): zeigt das
     * für den externen Auslöse-Endpunkt (/cron/run) konfigurierte Secret sowie
     * alle aktuell registrierten Aufgaben inkl. letztem Ausführungszeitpunkt.
     */
    public function cronSettings(): void {
        $this->requireAdmin();

        $this->render('admin_cron_settings', [
            'title' => 'Automatisierung (Cron)',
            'cronSecret' => $this->settings['cron_secret'] ?? '',
            'tasks' => \App\Service\Scheduler::registeredTasks(),
        ]);
    }

    /**
     * Erzeugt ein neues zufälliges Cron-Secret und ersetzt ein zuvor gesetztes -
     * z. B. beim ersten Einrichten oder falls das alte versehentlich in einem
     * öffentlich einsehbaren Skript/Log gelandet ist.
     */
    public function regenerateCronSecret(): void {
        $this->requireAdmin();
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $secret = bin2hex(random_bytes(32));
        $db = Database::getInstance();
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('cron_secret', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$secret, $secret]);

        \App\Service\AuditLogger::log("Cron-Secret neu generiert", "settings");

        header("Location: /admin/cron?success=secret_regenerated");
        exit;
    }

    /**
     * Löst alle fälligen registrierten Cron-Aufgaben manuell aus - Alternative
     * für Betreiber ohne Zugriff auf einen System-Cron (analog zum in #85
     * diskutierten "Jetzt aktualisieren"-Ansatz für ein manuell im Admin-Bereich
     * angestoßenes Update ohne vollständige Cron-Infrastruktur).
     */
    public function runCronNow(): void {
        $this->requireAdmin();
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $results = \App\Service\Scheduler::runDue();
        \App\Service\AuditLogger::log(
            "Cron-Aufgaben manuell ausgelöst",
            "cron",
            count($results) . " fällige Aufgabe(n) ausgeführt: " . implode(', ', array_column($results, 'name'))
        );

        header("Location: /admin/cron?success=run_now&ran=" . count($results));
        exit;
    }

    /**
     * Backup-Verwaltung (#59, siehe App\Service\BackupService): S3-Zugangsdaten,
     * Intervall/Aufbewahrung konfigurieren, letzten Lauf einsehen, manuellen
     * Testlauf anstoßen. Baut auf der Cron-/Scheduler-Infrastruktur (#67) auf.
     */
    public function backupSettings(): void {
        $this->requireAdmin();

        $db = Database::getInstance();
        $stmt = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'backup_%'");
        $settings = [];
        foreach ($stmt->fetchAll() as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        $schedulerTask = null;
        foreach (\App\Service\Scheduler::registeredTasks() as $task) {
            if ($task['name'] === 'backup.external') {
                $schedulerTask = $task;
                break;
            }
        }

        $this->render('admin_backup_settings', [
            'title' => 'Backups',
            'settings' => $settings,
            'schedulerTask' => $schedulerTask,
        ]);
    }

    public function updateBackupSettings(): void {
        $this->requireAdmin();
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $db = Database::getInstance();

        $settings = [
            'backup_enabled' => !empty($_POST['backup_enabled']) ? '1' : '0',
            'backup_s3_endpoint' => trim($_POST['backup_s3_endpoint'] ?? ''),
            'backup_s3_region' => trim($_POST['backup_s3_region'] ?? ''),
            'backup_s3_bucket' => trim($_POST['backup_s3_bucket'] ?? ''),
            'backup_s3_access_key' => trim($_POST['backup_s3_access_key'] ?? ''),
            'backup_s3_path_style' => !empty($_POST['backup_s3_path_style']) ? '1' : '0',
            'backup_s3_use_https' => !empty($_POST['backup_s3_use_https']) ? '1' : '0',
            'backup_interval_hours' => (string)max(1, (int)($_POST['backup_interval_hours'] ?? 24)),
            'backup_retention_count' => (string)max(1, (int)($_POST['backup_retention_count'] ?? 14)),
        ];

        // Secret Key nur überschreiben, wenn tatsächlich ein neuer Wert eingegeben
        // wurde (analog zum SMTP-Passwort in updateMailSettings()) - ein leeres Feld
        // bedeutet "unverändert lassen", nicht "Secret löschen".
        if (!empty($_POST['backup_s3_secret_key'])) {
            $settings['backup_s3_secret_key'] = \App\Security\Crypto::encrypt($_POST['backup_s3_secret_key']);
        }

        foreach ($settings as $key => $value) {
            $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$key, $value, $value]);
        }

        \App\Service\AuditLogger::log(
            "Backup-Einstellungen aktualisiert",
            "settings",
            "Aktiviert: {$settings['backup_enabled']}, Endpoint: {$settings['backup_s3_endpoint']}, Bucket: {$settings['backup_s3_bucket']}"
        );

        header("Location: /admin/backups?success=1");
        exit;
    }

    /**
     * Löst einen sofortigen Backup-Lauf aus, unabhängig vom konfigurierten
     * Intervall - z. B. um eine neue S3-Konfiguration direkt zu testen.
     */
    public function testBackup(): void {
        $this->requireAdmin();
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        try {
            \App\Service\BackupService::run();
            header("Location: /admin/backups?success=backup_run");
        } catch (\Throwable $e) {
            header("Location: /admin/backups?error=" . urlencode($e->getMessage()));
        }
        exit;
    }

    /**
     * Audit log viewer for Administrators. Entries are immutable and kept
     * indefinitely (no automatic purge) - the "30 days" here is only the
     * default display window, with a fallback to the latest 500 entries.
     */
    public function logs(): void {
        $this->requireAdmin();

        $db = Database::getInstance();

        $category = trim($_GET['category'] ?? '');
        $userFilter = trim($_GET['user'] ?? '');
        $search = trim($_GET['search'] ?? '');

        // Immutable policy: Only fetch logs from the last 30 days
        $where = ["created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"];
        $params = [];

        if (!empty($category)) {
            $where[] = "category = ?";
            $params[] = $category;
        }

        if (!empty($userFilter)) {
            if (strtoupper($userFilter) === 'SYSTEM') {
                $where[] = "username = 'SYSTEM'";
            } else {
                $where[] = "username LIKE ?";
                $params[] = '%' . $userFilter . '%';
            }
        }

        if (!empty($search)) {
            $where[] = "(action LIKE ? OR details LIKE ? OR ip_address LIKE ?)";
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $whereSql = implode(' AND ', $where);
        $stmt = $db->prepare("SELECT * FROM audit_logs WHERE {$whereSql} ORDER BY id DESC LIMIT 500");
        $stmt->execute($params);
        $logs = $stmt->fetchAll();

        // Fallback: If 30-day query yields 0 results and no explicit filter was supplied, show latest 500 logs
        if (empty($logs) && empty($category) && empty($userFilter) && empty($search)) {
            $stmt = $db->query("SELECT * FROM audit_logs ORDER BY id DESC LIMIT 500");
            $logs = $stmt->fetchAll();
        }

        // Fetch distinct categories for filter dropdown
        $stmt = $db->query("SELECT DISTINCT category FROM audit_logs ORDER BY category ASC");
        $categories = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $this->render('admin_logs', [
            'title' => 'System Audit-Log (Letzte 30 Tage)',
            'logs' => $logs,
            'categories' => $categories,
            'filters' => [
                'category' => $category,
                'user' => $userFilter,
                'search' => $search
            ]
        ]);
    }
}
