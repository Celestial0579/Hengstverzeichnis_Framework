<?php
// src/Controllers/SetupController.php

namespace App\Controllers;

use App\Database;
use PDO;
use PDOException;

class SetupController extends BaseController {

    private static function dbConfigFilePath(): string {
        return __DIR__ . '/../../config/db_config.php';
    }

    /**
     * Liest die aktuelle config/db_config.php ein (leeres Array, falls sie nicht
     * existiert - z. B. bei rein Env-Var-basierten Deployments).
     */
    public static function readDbConfig(): array {
        $file = self::dbConfigFilePath();
        return file_exists($file) ? (require $file) : [];
    }

    /**
     * Schreibt einen einzelnen Schlüssel in config/db_config.php, bestehende Werte
     * bleiben erhalten. Für sicherheitsrelevante Einstellungen (z. B. TRUSTED_PROXIES),
     * die auch ohne Umgebungsvariablen-Unterstützung (klassisches Webhosting) über den
     * Admin-Bereich konfigurierbar sein müssen.
     */
    public static function writeDbConfigValue(string $key, $value): bool {
        $config = self::readDbConfig();
        $config[$key] = $value;
        $content = "<?php\n// Auto-generated database configuration\nreturn " . var_export($config, true) . ";\n";
        return file_put_contents(self::dbConfigFilePath(), $content) !== false;
    }

    public static function needsSetup(): bool {
        $dbConfigFile = self::dbConfigFilePath();
        $hasEnvConfig = self::isDbConfiguredViaEnv();
        if (!file_exists($dbConfigFile) && !$hasEnvConfig) {
            return true;
        }

        try {
            $db = Database::getInstance();
            $stmt = $db->query("
                SELECT COUNT(*) FROM user_groups ug
                JOIN `groups` g ON g.id = ug.group_id
                WHERE g.slug = 'admin'
            ");
            $count = (int)$stmt->fetchColumn();
            return $count === 0;
        } catch (\PDOException $e) {
            // Table 'users' does not exist yet -> needs setup
            if ($e->getCode() === '42S02' || strpos($e->getMessage(), '42S02') !== false || strpos($e->getMessage(), "doesn't exist") !== false) {
                return true;
            }
            // Connection error when db_config.php exists -> do NOT redirect to setup loop
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function isDbConfiguredViaEnv(): bool {
        return getenv('DB_HOST') !== false || getenv('DB_USER') !== false || getenv('DB_PASS') !== false;
    }

    /**
     * @return array{username: string, email: string, password: string}|null
     */
    private static function envAdminCredentials(): ?array {
        $username = getenv('ADMIN_USERNAME');
        $email = getenv('ADMIN_EMAIL');
        $password = getenv('ADMIN_PASSWORD');
        if ($username === false || $email === false || $password === false) {
            return null;
        }

        $username = trim($username);
        $email = trim($email);
        if ($username === '' || $email === '' || strlen($password) < 8) {
            return null;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return ['username' => $username, 'email' => $email, 'password' => $password];
    }

    private static function envSiteName(): ?string {
        $siteName = getenv('SITE_NAME');
        if ($siteName === false) {
            return null;
        }
        $siteName = trim($siteName);
        return $siteName !== '' ? $siteName : null;
    }

    public function showSetup(): void {
        if (!self::needsSetup()) {
            header("Location: /login");
            exit;
        }

        $dbFromEnv = self::isDbConfiguredViaEnv();
        $siteFromEnv = self::envSiteName();
        $adminFromEnv = self::envAdminCredentials();
        if ($adminFromEnv !== null && $this->isReservedUsername($adminFromEnv['username'])) {
            $adminFromEnv = null;
        }

        // Vollautomatische Ersteinrichtung: alle nötigen Werte kamen per Umgebungsvariable,
        // der Wizard wird komplett übersprungen.
        if ($dbFromEnv && $siteFromEnv !== null && $adminFromEnv !== null) {
            if (empty(getenv('APP_KEY'))) {
                $this->render('setup', [
                    'title' => 'Einrichtung - Hengstverzeichnis Framework',
                    'errors' => ['Automatische Ersteinrichtung übersprungen: APP_KEY ist nicht gesetzt. Bitte APP_KEY als Umgebungsvariable definieren und die Seite neu laden.'],
                    'hideDb' => $dbFromEnv,
                    'hideSite' => true,
                ]);
                return;
            }

            $this->provision(
                getenv('DB_HOST') ?: '127.0.0.1',
                getenv('DB_PORT') ?: '3306',
                getenv('DB_NAME') ?: 'hengstverzeichnis',
                getenv('DB_USER') ?: '',
                getenv('DB_PASS') ?: '',
                in_array(getenv('DB_SSL'), ['true', '1'], true),
                in_array(getenv('DB_SSL_VERIFY'), ['true', '1'], true),
                getenv('DB_SSL_CA') ?: '',
                $siteFromEnv,
                $adminFromEnv['username'],
                $adminFromEnv['email'],
                $adminFromEnv['password'],
                false,
                false,
                ['hideDb' => $dbFromEnv, 'hideSite' => true]
            );
            return;
        }

        // Reduzierter Wizard: Abschnitte, die bereits per Env-Variable feststehen, werden
        // ausgeblendet, damit nicht versehentlich bereits konfigurierte Werte überschrieben werden.
        $this->render('setup', [
            'title' => 'Einrichtung - Hengstverzeichnis Framework',
            'hideDb' => $dbFromEnv,
            'hideSite' => $siteFromEnv !== null,
        ]);
    }

    public function processSetup(): void {
        if (!self::needsSetup()) {
            header("Location: /login");
            exit;
        }

        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $dbFromEnv = self::isDbConfiguredViaEnv();
        $siteFromEnv = self::envSiteName();

        // DB Fields (Umgebungsvariablen haben Vorrang, falls der DB-Abschnitt ausgeblendet war)
        $dbHost = getenv('DB_HOST') ?: trim($_POST['db_host'] ?? '127.0.0.1');
        $dbPort = getenv('DB_PORT') ?: trim($_POST['db_port'] ?? '3306');
        $dbName = getenv('DB_NAME') ?: trim($_POST['db_name'] ?? 'hengstverzeichnis');
        $dbUser = getenv('DB_USER') ?: trim($_POST['db_user'] ?? 'root');
        $dbPass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : ($_POST['db_pass'] ?? '');

        // DB SSL/TLS Fields
        $dbSsl = getenv('DB_SSL') !== false ? in_array(getenv('DB_SSL'), ['true', '1'], true) : !empty($_POST['db_ssl']);
        $dbSslVerify = getenv('DB_SSL_VERIFY') !== false ? in_array(getenv('DB_SSL_VERIFY'), ['true', '1'], true) : !empty($_POST['db_ssl_verify']);
        $dbSslCa = getenv('DB_SSL_CA') !== false ? getenv('DB_SSL_CA') : trim($_POST['db_ssl_ca'] ?? '');

        // App Fields
        $siteName = $siteFromEnv ?? trim($_POST['site_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';

        $errors = [];

        if (!$dbFromEnv) {
            if (empty($dbHost)) $errors[] = "Bitte geben Sie den Datenbank-Server (Host) ein.";
            if (empty($dbPort)) $errors[] = "Bitte geben Sie den Datenbank-Port ein.";
            if (empty($dbName)) $errors[] = "Bitte geben Sie den Datenbank-Namen ein.";
            elseif (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) $errors[] = "Der Datenbank-Name darf nur Buchstaben, Ziffern und Unterstriche enthalten.";
            if (empty($dbUser)) $errors[] = "Bitte geben Sie den Datenbank-Benutzer ein.";
        }

        if ($siteFromEnv === null && empty($siteName)) $errors[] = "Bitte geben Sie einen Namen für den Verband / die Seite ein.";
        if (empty($username)) $errors[] = "Bitte geben Sie einen Benutzernamen ein.";
        if ($this->isReservedUsername($username)) $errors[] = "Der Benutzername '{$username}' ist aus Sicherheitsgründen reserviert und darf nicht verwendet werden.";
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Bitte geben Sie eine gültige E-Mail-Adresse ein.";
        if (strlen($password) < 8) $errors[] = "Das Passwort muss mindestens 8 Zeichen lang sein.";
        if ($password !== $passwordConfirm) $errors[] = "Die Passwörter stimmen nicht überein.";

        $renderExtra = ['hideDb' => $dbFromEnv, 'hideSite' => $siteFromEnv !== null];

        if (!empty($errors)) {
            $this->render('setup', array_merge([
                'title' => 'Einrichtung - Hengstverzeichnis Framework',
                'errors' => $errors,
                'old' => $_POST,
            ], $renderExtra));
            return;
        }

        $overwriteDb = !empty($_POST['overwrite_db']);

        $this->provision(
            $dbHost, $dbPort, $dbName, $dbUser, $dbPass,
            $dbSsl, $dbSslVerify, $dbSslCa,
            $siteName, $username, $email, $password,
            $overwriteDb,
            !$dbFromEnv,
            array_merge(['old' => $_POST], $renderExtra)
        );
    }

    /**
     * Führt die eigentliche Ersteinrichtung durch: DB anlegen, Schema importieren,
     * Admin-Konto erstellen. Wird sowohl vom klassischen Formular (processSetup)
     * als auch von der vollautomatischen Env-Var-Ersteinrichtung (showSetup) genutzt.
     *
     * @param bool $writeDbConfigFile Nur wahr, wenn die DB-Zugangsdaten NICHT bereits per
     *   Umgebungsvariable vorliegen - sonst würde eine überflüssige config/db_config.php
     *   entstehen, obwohl die App bereits rein über Env-Variablen lauffähig ist.
     * @param array $errorRenderExtra Zusätzliche View-Variablen (alte Eingaben, hideDb/hideSite),
     *   die bei einem Fehler zusammen mit der Fehlermeldung erneut gerendert werden.
     */
    private function provision(
        string $dbHost, string $dbPort, string $dbName, string $dbUser, string $dbPass,
        bool $dbSsl, bool $dbSslVerify, string $dbSslCa,
        string $siteName, string $username, string $email, string $password,
        bool $overwriteDb, bool $writeDbConfigFile, array $errorRenderExtra = []
    ): void {
        // Build PDO Options including SSL if enabled
        $pdoOptions = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        if ($dbSsl) {
            if (defined('PDO::MYSQL_ATTR_SSL_CA') && !empty($dbSslCa)) {
                $pdoOptions[PDO::MYSQL_ATTR_SSL_CA] = $dbSslCa;
            }
            if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                $pdoOptions[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $dbSslVerify;
            }
        }

        // Test Database Connection (Connect to MySQL server first)
        $dsnWithoutDb = "mysql:host=$dbHost;port=$dbPort;charset=utf8mb4";
        try {
            $testPdo = new PDO($dsnWithoutDb, $dbUser, $dbPass, $pdoOptions);

            if ($overwriteDb) {
                // Drop database if user requested overwrite
                $testPdo->exec("DROP DATABASE IF EXISTS `$dbName`");
            }

            // Create database if not exists and select it
            $testPdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $testPdo->exec("USE `$dbName`");

        } catch (PDOException $e) {
            $this->render('setup', array_merge([
                'title' => 'Einrichtung - Hengstverzeichnis Framework',
                'errors' => ['Datenbank-Verbindung fehlgeschlagen: ' . $e->getMessage()],
            ], $errorRenderExtra));
            return;
        }

        if ($writeDbConfigFile) {
            // Save DB Config File
            $configContent = "<?php\n// Auto-generated database configuration\nreturn " . var_export([
                'host' => $dbHost,
                'port' => $dbPort,
                'name' => $dbName,
                'user' => $dbUser,
                'pass' => $dbPass,
                'ssl'  => $dbSsl,
                'ssl_verify' => $dbSslVerify,
                'ssl_ca' => $dbSslCa,
                'app_key' => bin2hex(random_bytes(32)),
                // Explizit 'production': eine per Setup-Wizard eingerichtete Instanz ist eine
                // echte Installation, keine lokale Entwicklungsumgebung - PHP-Fehlerdetails
                // dürfen Besuchern nicht angezeigt werden (siehe config/config.php).
                'app_env' => 'production',
            ], true) . ";\n";

            if (file_put_contents(self::dbConfigFilePath(), $configContent) === false) {
                $this->render('setup', array_merge([
                    'title' => 'Einrichtung - Hengstverzeichnis Framework',
                    'errors' => ['Konnte config/db_config.php nicht schreiben. Bitte Schreibrechte im Ordner config/ prüfen.'],
                ], $errorRenderExtra));
                return;
            }
        }

        // Import SQL Schema automatically
        $schemaFile = __DIR__ . '/../../database/schema.sql';
        if (file_exists($schemaFile)) {
            $sql = file_get_contents($schemaFile);
            try {
                $testPdo->exec($sql);
            } catch (PDOException $e) {
                // Ignore errors if table already exists, proceed to insert admin
            }
        }

        try {
            // Save Site Name setting
            $stmt = $testPdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('site_name', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$siteName, $siteName]);

            // Create Admin User (must_change_password = 0 since password was set during setup)
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $testPdo->prepare("INSERT INTO users (username, email, password_hash, must_change_password) VALUES (?, ?, ?, 0)");
            $stmt->execute([$username, $email, $passwordHash]);
            $newUserId = $testPdo->lastInsertId();

            // Mitgliedschaft in der Gruppe `admin` (#66) - einziges Rechtesystem,
            // macht diesen Benutzer zum vollwertigen Administrator. Die Gruppe wurde
            // bereits durch den oben importierten schema.sql geseedet.
            $adminGroupId = $testPdo->query("SELECT id FROM `groups` WHERE slug = 'admin'")->fetchColumn();
            if ($adminGroupId) {
                $stmt = $testPdo->prepare("INSERT IGNORE INTO user_groups (user_id, group_id) VALUES (?, ?)");
                $stmt->execute([$newUserId, $adminGroupId]);
            }

            // Set pending 2FA session for setup
            $_SESSION['pending_2fa_user_id'] = $newUserId;

            header("Location: /2fa/setup");
            exit;

        } catch (\Exception $e) {
            $this->render('setup', array_merge([
                'title' => 'Einrichtung - Hengstverzeichnis Framework',
                'errors' => ['Einrichtungsfehler: ' . $e->getMessage()],
            ], $errorRenderExtra));
        }
    }
}
