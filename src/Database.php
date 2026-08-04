<?php
// src/Database.php

namespace App;

use PDO;
use PDOException;

/**
 * Class Database
 * 
 * Verwalte die PDO-Verbindung zur MySQL/MariaDB Datenbank im Singleton-Muster.
 * Gewährleistet, dass während der gesamten Anfrage-Laufzeit nur eine einzige
 * Datenbank-Verbindung aufgebaut wird, injiziert SSL/TLS-Optionen und prüft
 * automatisch beim Verbindungsaufbau, ob alle Tabellen & Spalten vorhanden sind.
 */
class Database {
    /**
     * Statische Instanz des PDO-Datenbankverbindungsobjekts.
     * @var PDO|null
     */
    private static ?PDO $instance = null;

    /**
     * Privater Konstruktor zur Verhinderung direkter Instanziierung (Singleton-Pattern).
     */
    private function __construct() {}

    /**
     * Privater Klon-Konstruktor zur Verhinderung von Duplizierung.
     */
    private function __clone() {}

    /**
     * Liefert die zentrale PDO-Datenbankinstanz zurück oder baut diese bei Erstaufruf auf.
     *
     * @return PDO Aktive PDO-Verbindung
     * @throws PDOException Falls im Entwicklungsmodus ein Verbindungsfehler auftritt
     */
    public static function getInstance(): PDO {
        if (self::$instance === null) {
            $host = DB_HOST;
            $port = defined('DB_PORT') ? DB_PORT : '3306';
            $db   = DB_NAME;
            $user = DB_USER;
            $pass = DB_PASS;
            $charset = 'utf8mb4';

            if (strpos($host, '/') === 0) {
                $dsn = "mysql:unix_socket=$host;dbname=$db;charset=$charset";
            } else {
                $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
            }
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Löst Exceptions bei Fehlern aus
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Standard-Fetch: Assoziatives Array
                PDO::ATTR_EMULATE_PREPARES   => false,                  // Verwendet echte vorbereitete Statements
            ];

            // SSL/TLS-Verschlüsselung aktivieren, falls in den Einstellungen hinterlegt
            if (defined('DB_SSL') && DB_SSL) {
                if (defined('PDO::MYSQL_ATTR_SSL_CA') && defined('DB_SSL_CA') && !empty(DB_SSL_CA)) {
                    $options[PDO::MYSQL_ATTR_SSL_CA] = DB_SSL_CA;
                }
                if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = (defined('DB_SSL_VERIFY') && DB_SSL_VERIFY);
                }
            }

            try {
                self::$instance = new PDO($dsn, $user, $pass, $options);
                
                // Datenbank-Schema bei Verbindungsaufbau automatisch auf den neuesten Stand bringen
                self::ensureSchemaUpToDate(self::$instance);
            } catch (PDOException $e) {
                // Fallback: Versuch über Unix Sockets falls TCP fehlgeschlagen ist
                $fallbackSockets = ['/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock'];
                $connected = false;
                foreach ($fallbackSockets as $sock) {
                    if (file_exists($sock)) {
                        try {
                            $fallbackDsn = "mysql:unix_socket=$sock;dbname=$db;charset=$charset";
                            self::$instance = new PDO($fallbackDsn, $user, $pass, $options);
                            self::ensureSchemaUpToDate(self::$instance);
                            $connected = true;
                            break;
                        } catch (PDOException $ex) {
                            // Fallback nicht erfolgreich
                        }
                    }
                }

                if (!$connected) {
                    if (APP_ENV === 'development') {
                        throw new PDOException($e->getMessage(), (int)$e->getCode());
                    } else {
                        die("Datenbank-Verbindung fehlgeschlagen. Bitte überprüfen Sie die Einstellungen.");
                    }
                }
            }
        }

        return self::$instance;
    }

    /**
     * Stellt sicher, dass alle erforderlichen Tabellen und Spalten in der Datenbank existieren.
     * Ermöglicht reibungslose Updates ohne manuelle SQL-Migrationsskripte.
     *
     * @param PDO $pdo Aktive Datenbankverbindung
     */
    private static function ensureSchemaUpToDate(PDO $pdo): void {
        static $checked = false;
        if ($checked) return;
        $checked = true;

        try {
        // Helper-Funktion zum schrittweisen Hinzufügen fehlender Spalten
        $addColumn = function($table, $column, $definition) use ($pdo) {
            try {
                $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
                if ($stmt && $stmt->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
                }
            } catch (\Throwable $e) {
                // Table doesn't exist yet or column check failed
            }
        };

        // 1. Audit-Log für Revisionssicherheit (30 Tage Speicherdauer)
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `audit_logs` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NULL,
                `username` VARCHAR(50) NOT NULL DEFAULT 'SYSTEM',
                `action` VARCHAR(100) NOT NULL,
                `category` VARCHAR(50) NOT NULL DEFAULT 'general',
                `details` TEXT NULL,
                `ip_address` VARCHAR(45) NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (`created_at`),
                INDEX (`category`),
                INDEX (`username`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $e) {}

        // 2. 2-Faktor-Authentifizierung & Passkeys für Benutzer
        $addColumn('users', 'totp_secret', 'VARCHAR(64) NULL AFTER `role`');
        $addColumn('users', 'totp_enabled', 'TINYINT(1) DEFAULT 0 AFTER `totp_secret`');
        $addColumn('users', 'backup_codes', 'TEXT NULL AFTER `totp_enabled`');
        $addColumn('users', 'passkeys', 'TEXT NULL AFTER `backup_codes`');

        // 3. Erweiterungen für Pferdeprofile (Ausländische UELN, Abstammung, Deckstation)
        $addColumn('horses', 'foreign_ueln', 'VARCHAR(50) NULL DEFAULT NULL AFTER `ueln`');
        $addColumn('horses', 'sire_id', 'INT NULL AFTER `foreign_ueln`');
        $addColumn('horses', 'sire_name', 'VARCHAR(100) NULL AFTER `sire_id`');
        $addColumn('horses', 'sire_ueln', 'VARCHAR(15) NULL AFTER `sire_name`');
        $addColumn('horses', 'dam_id', 'INT NULL AFTER `sire_ueln`');
        $addColumn('horses', 'dam_name', 'VARCHAR(100) NULL AFTER `dam_id`');
        $addColumn('horses', 'dam_ueln', 'VARCHAR(15) NULL AFTER `dam_name`');

        // 4. Deckstationen-Tabelle anlegen
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `breeding_stations` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `name` VARCHAR(150) NOT NULL,
                    `contact_person` VARCHAR(100) NULL,
                    `address` TEXT NULL,
                    `phone` VARCHAR(50) NULL,
                    `email` VARCHAR(100) NULL,
                    `website` VARCHAR(255) NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        } catch (\Throwable $e) {}

        $addColumn('horses', 'breeding_station_id', 'INT NULL AFTER `color`');
        $addColumn('horses', 'breeding_station', 'VARCHAR(255) NULL AFTER `breeding_station_id`');
        $addColumn('horses', 'image_url', 'VARCHAR(255) NULL AFTER `status`');

        // 5. Zuordnungen zwischen Pferden & Personen/Besitzern anlegen
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `horse_persons` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `horse_id` INT NOT NULL,
                    `person_id` INT NULL,
                    `role` ENUM('breeder', 'owner', 'keeper') NOT NULL DEFAULT 'owner',
                    `breeding_station_id` INT NULL,
                    `breeding_station_text` VARCHAR(255) NULL,
                    `from_year` SMALLINT UNSIGNED NULL,
                    `until_year` SMALLINT UNSIGNED NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (`horse_id`) REFERENCES `horses`(`id`) ON DELETE CASCADE,
                    FOREIGN KEY (`person_id`) REFERENCES `persons`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        } catch (\Throwable $e) {}

        $addColumn('horse_persons', 'breeding_station_id', 'INT NULL AFTER `role`');
        $addColumn('horse_persons', 'breeding_station_text', 'VARCHAR(255) NULL AFTER `breeding_station_id`');

        try {
            $pdo->exec("ALTER TABLE `horse_persons` MODIFY COLUMN `person_id` INT NULL DEFAULT NULL;");
        } catch (\Throwable $e) {}

        // 6. Tabelle für Passwort-Zurücksetzen-Tokens
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `password_resets` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `email` VARCHAR(100) NOT NULL,
                    `token` VARCHAR(64) NOT NULL UNIQUE,
                    `expires_at` DATETIME NOT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        } catch (\Throwable $e) {}

        // 7. DSGVO-Anfragen-Tabelle
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `gdpr_requests` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `name` VARCHAR(100) NULL,
                    `email` VARCHAR(100) NOT NULL,
                    `request_type` ENUM('info', 'deletion') NOT NULL,
                    `message` TEXT NULL,
                    `status` ENUM('pending', 'processed', 'rejected') DEFAULT 'pending',
                    `admin_notes` TEXT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        } catch (\Throwable $e) {}

        $addColumn('gdpr_requests', 'name', 'VARCHAR(100) NULL AFTER `id`');
        $addColumn('gdpr_requests', 'message', 'TEXT NULL AFTER `request_type`');
        $addColumn('gdpr_requests', 'admin_notes', 'TEXT NULL AFTER `status`');

        // 8. Papierkorb-Unterstützung (Soft Delete)
        $addColumn('horses', 'deleted_at', 'DATETIME NULL DEFAULT NULL');
        $addColumn('persons', 'deleted_at', 'DATETIME NULL DEFAULT NULL');
        $addColumn('breeding_stations', 'deleted_at', 'DATETIME NULL DEFAULT NULL');
        $addColumn('users', 'deleted_at', 'DATETIME NULL DEFAULT NULL');

        // 9. Passwortänderungs-Zwang für neue/zurückgesetzte Benutzer
        try {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0");
        } catch (\Throwable $e) {}

        // 10. Historische Geburtsjahre vor 1901 unterstützen (SMALLINT statt YEAR)
        try {
            $pdo->exec("ALTER TABLE `horses` MODIFY COLUMN `birth_year` SMALLINT UNSIGNED NULL");
        } catch (\Throwable $e) {}

        // 11. Login-Versuche für Brute-Force-Schutz (Login, 2FA, Backup-Codes)
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `login_attempts` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `identifier` VARCHAR(255) NOT NULL,
                `type` VARCHAR(20) NOT NULL DEFAULT 'login',
                `ip_address` VARCHAR(45) NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (`identifier`, `type`),
                INDEX (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $e) {}
        } catch (\Exception $e) {
            // Falls Tabellen noch nicht initialisiert wurden
        }
    }
}
