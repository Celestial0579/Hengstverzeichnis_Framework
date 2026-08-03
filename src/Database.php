<?php
// src/Database.php

namespace App;

use PDO;
use PDOException;

class Database {
    private static ?PDO $instance = null;

    private function __construct() {}
    private function __clone() {}

    public static function getInstance(): PDO {
        if (self::$instance === null) {
            $host = DB_HOST;
            $port = defined('DB_PORT') ? DB_PORT : '3306';
            $db   = DB_NAME;
            $user = DB_USER;
            $pass = DB_PASS;
            $charset = 'utf8mb4';

            $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Throw exceptions on errors
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Fetch associative arrays
                PDO::ATTR_EMULATE_PREPARES   => false,                  // Use real prepared statements
            ];

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
                self::ensureSchemaUpToDate(self::$instance);
            } catch (PDOException $e) {
                // In production, log this error securely instead of displaying it
                if (APP_ENV === 'development') {
                    throw new PDOException($e->getMessage(), (int)$e->getCode());
                } else {
                    die("Database connection failed.");
                }
            }
        }

        return self::$instance;
    }

    private static function ensureSchemaUpToDate(PDO $pdo): void {
        static $checked = false;
        if ($checked) return;
        $checked = true;

        try {
            $addColumn = function($table, $column, $definition) use ($pdo) {
                $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
                if ($stmt && $stmt->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
                }
            };

            // Ensure 2FA columns exist
            $addColumn('users', 'totp_secret', 'VARCHAR(64) NULL AFTER `role`');
            $addColumn('users', 'totp_enabled', 'TINYINT(1) DEFAULT 0 AFTER `totp_secret`');
            $addColumn('users', 'backup_codes', 'TEXT NULL AFTER `totp_enabled`');
            $addColumn('users', 'passkeys', 'TEXT NULL AFTER `backup_codes`');

            // Ensure Sire, Dam & Breeding Station columns exist
            $addColumn('horses', 'foreign_ueln', 'VARCHAR(50) NULL DEFAULT NULL AFTER `ueln`');
            $addColumn('horses', 'sire_id', 'INT NULL AFTER `foreign_ueln`');
            $addColumn('horses', 'sire_name', 'VARCHAR(100) NULL AFTER `sire_id`');
            $addColumn('horses', 'sire_ueln', 'VARCHAR(15) NULL AFTER `sire_name`');
            $addColumn('horses', 'dam_id', 'INT NULL AFTER `sire_ueln`');
            $addColumn('horses', 'dam_name', 'VARCHAR(100) NULL AFTER `dam_id`');
            $addColumn('horses', 'dam_ueln', 'VARCHAR(15) NULL AFTER `dam_name`');
            // Ensure breeding_stations table exists
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

            $addColumn('horses', 'breeding_station_id', 'INT NULL AFTER `color`');
            $addColumn('horses', 'breeding_station', 'VARCHAR(255) NULL AFTER `breeding_station_id`');
            $addColumn('horses', 'image_url', 'VARCHAR(255) NULL AFTER `status`');

            // Ensure horse_persons table exists
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `horse_persons` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `horse_id` INT NOT NULL,
                    `person_id` INT NOT NULL,
                    `role` ENUM('breeder', 'owner', 'keeper') NOT NULL DEFAULT 'owner',
                    `from_year` SMALLINT UNSIGNED NULL,
                    `until_year` SMALLINT UNSIGNED NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (`horse_id`) REFERENCES `horses`(`id`) ON DELETE CASCADE,
                    FOREIGN KEY (`person_id`) REFERENCES `persons`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            // Ensure password_resets table exists
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `password_resets` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `email` VARCHAR(100) NOT NULL,
                    `token` VARCHAR(64) NOT NULL UNIQUE,
                    `expires_at` DATETIME NOT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            // Ensure gdpr_requests table exists and has new columns
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

            $addColumn('gdpr_requests', 'name', 'VARCHAR(100) NULL AFTER `id`');
            $addColumn('gdpr_requests', 'message', 'TEXT NULL AFTER `request_type`');
            $addColumn('gdpr_requests', 'admin_notes', 'TEXT NULL AFTER `status`');

            // Soft Delete support across all main entities
            $addColumn('horses', 'deleted_at', 'DATETIME NULL DEFAULT NULL');
            $addColumn('persons', 'deleted_at', 'DATETIME NULL DEFAULT NULL');
            $addColumn('breeding_stations', 'deleted_at', 'DATETIME NULL DEFAULT NULL');
            $addColumn('users', 'deleted_at', 'DATETIME NULL DEFAULT NULL');

            // Ensure must_change_password column exists on users table
            try {
                $pdo->exec("ALTER TABLE `users` ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0");
            } catch (\PDOException $e) {
                // Ignore if column already exists
            }

            // Upgrade birth_year from YEAR to SMALLINT UNSIGNED to support historical years (< 1901)
            $pdo->exec("ALTER TABLE `horses` MODIFY COLUMN `birth_year` SMALLINT UNSIGNED NULL");

            // Ensure audit_logs table exists
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
        } catch (\Exception $e) {
            // Ignore if tables don't exist yet
        }
    }
}
