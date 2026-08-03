<?php
// src/Controllers/SetupController.php

namespace App\Controllers;

use App\Database;
use PDO;
use PDOException;

class SetupController extends BaseController {

    public static function needsSetup(): bool {
        $dbConfigFile = __DIR__ . '/../../config/db_config.php';
        if (!file_exists($dbConfigFile)) {
            return true;
        }

        try {
            $db = Database::getInstance();
            $stmt = $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
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

    public function showSetup(): void {
        if (!self::needsSetup()) {
            header("Location: /login");
            exit;
        }

        $this->render('setup', [
            'title' => 'Einrichtung - Hengstverzeichnis Framework'
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

        // DB Fields
        $dbHost = trim($_POST['db_host'] ?? '127.0.0.1');
        $dbPort = trim($_POST['db_port'] ?? '3306');
        $dbName = trim($_POST['db_name'] ?? 'hengstverzeichnis');
        $dbUser = trim($_POST['db_user'] ?? 'root');
        $dbPass = $_POST['db_pass'] ?? '';

        // App Fields
        $siteName = trim($_POST['site_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';

        $errors = [];

        if (empty($dbHost)) $errors[] = "Bitte geben Sie den Datenbank-Server (Host) ein.";
        if (empty($dbPort)) $errors[] = "Bitte geben Sie den Datenbank-Port ein.";
        if (empty($dbName)) $errors[] = "Bitte geben Sie den Datenbank-Namen ein.";
        if (empty($dbUser)) $errors[] = "Bitte geben Sie den Datenbank-Benutzer ein.";

        if (empty($siteName)) $errors[] = "Bitte geben Sie einen Namen für den Verband / die Seite ein.";
        if (empty($username)) $errors[] = "Bitte geben Sie einen Benutzernamen ein.";
        if ($this->isReservedUsername($username)) $errors[] = "Der Benutzername '{$username}' ist aus Sicherheitsgründen reserviert und darf nicht verwendet werden.";
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Bitte geben Sie eine gültige E-Mail-Adresse ein.";
        if (strlen($password) < 8) $errors[] = "Das Passwort muss mindestens 8 Zeichen lang sein.";
        if ($password !== $passwordConfirm) $errors[] = "Die Passwörter stimmen nicht überein.";

        if (!empty($errors)) {
            $this->render('setup', [
                'title' => 'Einrichtung - Hengstverzeichnis Framework',
                'errors' => $errors,
                'old' => $_POST
            ]);
            return;
        }

        $overwriteDb = !empty($_POST['overwrite_db']);

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
            $this->render('setup', [
                'title' => 'Einrichtung - Hengstverzeichnis Framework',
                'errors' => ['Datenbank-Verbindung fehlgeschlagen: ' . $e->getMessage()],
                'old' => $_POST
            ]);
            return;
        }

        // Save DB Config File
        $dbConfigFile = __DIR__ . '/../../config/db_config.php';
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
        ], true) . ";\n";

        if (file_put_contents($dbConfigFile, $configContent) === false) {
            $this->render('setup', [
                'title' => 'Einrichtung - Hengstverzeichnis Framework',
                'errors' => ['Konnte config/db_config.php nicht schreiben. Bitte Schreibrechte im ordner config/ prüfen.'],
                'old' => $_POST
            ]);
            return;
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
            $stmt = $testPdo->prepare("INSERT INTO users (username, email, password_hash, role, must_change_password) VALUES (?, ?, ?, 'admin', 0)");
            $stmt->execute([$username, $email, $passwordHash]);

            // Set pending 2FA session for setup
            $newUserId = $testPdo->lastInsertId();
            $_SESSION['pending_2fa_user_id'] = $newUserId;
            $_SESSION['pending_2fa_role'] = 'admin';

            header("Location: /2fa/setup");
            exit;

        } catch (\Exception $e) {
            $this->render('setup', [
                'title' => 'Einrichtung - Hengstverzeichnis Framework',
                'errors' => ['Einrichtungsfehler: ' . $e->getMessage()],
                'old' => $_POST
            ]);
        }
    }
}
