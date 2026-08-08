<?php
// database/migrate.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Dieses Skript darf nur über die CLI ausgeführt werden.');
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Database.php';

use App\Database;

echo "===============================================\n";
echo " Hengstverzeichnis Framework - DB Migration\n";
echo "===============================================\n";

try {
    $db = Database::getInstance();

    // Helper to safely add column if it doesn't exist
    $addColumn = function($table, $column, $definition) use ($db) {
        $stmt = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        if ($stmt->rowCount() === 0) {
            $db->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            echo "[MIGRATION] Spalte '$column' zu Tabelle '$table' hinzugefügt.\n";
        } else {
            echo "[OK] Spalte '$column' in Tabelle '$table' existiert bereits.\n";
        }
    };

    // Users table migrations (2FA)
    $addColumn('users', 'totp_secret', 'VARCHAR(64) NULL');
    $addColumn('users', 'totp_enabled', 'TINYINT(1) DEFAULT 0 AFTER `totp_secret`');
    $addColumn('users', 'backup_codes', 'TEXT NULL AFTER `totp_enabled`');
    $addColumn('users', 'passkeys', 'TEXT NULL AFTER `backup_codes`');

    // Horses table migrations (Parent references)
    $addColumn('horses', 'sire_id', 'INT NULL AFTER `ueln`');
    $addColumn('horses', 'dam_id', 'INT NULL AFTER `sire_id`');

    // API-Schlüssel für die JSON-API (siehe database/schema.sql und
    // App\Security\ApiKey). Idempotent über CREATE TABLE IF NOT EXISTS -
    // bestehende Installationen erhalten die Tabelle beim nächsten Migrationslauf.
    $db->exec(
        "CREATE TABLE IF NOT EXISTS `api_keys` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `label` VARCHAR(100) NOT NULL,
            `token_hash` CHAR(64) NOT NULL UNIQUE,
            `token_prefix` VARCHAR(20) NOT NULL,
            `scope_permissions` TEXT NULL DEFAULT NULL,
            `last_used_at` DATETIME NULL DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `revoked_at` DATETIME NULL DEFAULT NULL,
            INDEX `idx_api_keys_user` (`user_id`, `revoked_at`),
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "[OK] Tabelle 'api_keys' vorhanden.\n";

    echo "===============================================\n";
    echo "[SUCCESS] Migration erfolgreich abgeschlossen!\n";
    echo "===============================================\n";

} catch (Exception $e) {
    echo "[FEHLER] Migration fehlgeschlagen: " . $e->getMessage() . "\n";
}
