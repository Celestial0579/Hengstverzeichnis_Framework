-- database/schema.sql

-- Settings for Branding / Theming
CREATE TABLE IF NOT EXISTS `settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(50) NOT NULL UNIQUE,
    `setting_value` TEXT,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initial Settings
-- Kein 'logo_url'-Default: ohne konfiguriertes Logo zeigt der Header nur den
-- Vereinsnamen als Text (layout.php prüft auf !empty($logoUrl)), das vermeidet
-- ein kaputtes Bild-Icon bei Neuinstallationen ohne eigenen Logo-Upload.
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('site_name', 'Hengstverzeichnis Framework'),
('primary_color', '#2c3e50'),
('secondary_color', '#18bc9c');

-- Users Table
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `totp_secret` VARCHAR(255) NULL,
    `totp_enabled` TINYINT(1) DEFAULT 0,
    `backup_codes` TEXT NULL,
    `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
    `session_version` INT NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Password Resets
CREATE TABLE IF NOT EXISTS `password_resets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(100) NOT NULL,
    `token` VARCHAR(64) NOT NULL UNIQUE,
    `expires_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Persons (Breeders, Owners, Keepers)
CREATE TABLE IF NOT EXISTS `persons` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `contact_info` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Breeding Stations (Deckstationen / Gestüte)
CREATE TABLE IF NOT EXISTS `breeding_stations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `contact_person` VARCHAR(100) NULL,
    `address` TEXT NULL,
    `phone` VARCHAR(50) NULL,
    `email` VARCHAR(100) NULL,
    `website` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Horses
CREATE TABLE IF NOT EXISTS `horses` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `ueln` VARCHAR(50) UNIQUE, -- Unique Equine Life Number (Deutschland / Haupt-UELN)
    `foreign_ueln` VARCHAR(50) NULL DEFAULT NULL, -- Lebensnummer Ursprungsland / Ausländische UELN
    `sire_id` INT NULL, -- Father (Stallion FK)
    `sire_name` VARCHAR(100) NULL, -- Unlinked Father Name
    `sire_ueln` VARCHAR(15) NULL, -- Unlinked Father UELN
    `dam_id` INT NULL, -- Mother (Mare FK)
    `dam_name` VARCHAR(100) NULL, -- Unlinked Mother Name
    `dam_ueln` VARCHAR(15) NULL, -- Unlinked Mother UELN
    `birth_year` SMALLINT UNSIGNED NULL,
    `color` VARCHAR(50),
    `breeding_station_id` INT NULL,
    `breeding_station` VARCHAR(255) NULL,
    `description` TEXT,
    `status` ENUM('active', 'inactive', 'deceased') DEFAULT 'active',
    `image_url` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL DEFAULT NULL,
    FOREIGN KEY (`sire_id`) REFERENCES `horses`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`dam_id`) REFERENCES `horses`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`breeding_station_id`) REFERENCES `breeding_stations`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Horse Persons Relation (Ownership History & Roles)
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

-- GDPR Requests
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

-- Login Attempts (Brute-Force-Schutz für Login, 2FA, Backup-Codes)
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `identifier` VARCHAR(255) NOT NULL,
    `type` VARCHAR(20) NOT NULL DEFAULT 'login',
    `ip_address` VARCHAR(45) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (`identifier`, `type`),
    INDEX (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Plugins (Aktivierungsstatus, siehe src/Plugin/PluginManager.php, #56)
-- content_hash: Inhalts-Fingerabdruck der bei Aktivierung freigegebenen Version,
-- verhindert stillschweigendes Weiterlaufen nachträglich ausgetauschten Codes.
CREATE TABLE IF NOT EXISTS `plugins` (
    `slug` VARCHAR(100) NOT NULL PRIMARY KEY,
    `enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `installed_version` VARCHAR(20) NOT NULL DEFAULT '0.0.0',
    `content_hash` VARCHAR(64) NULL DEFAULT NULL,
    `activated_at` DATETIME NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gruppen-/Berechtigungssystem (#66, siehe docs/user-groups-plan.md und
-- BaseController::hasPermission()) - EINZIGES Rechtesystem der App (das
-- frühere users.role wurde vollständig entfernt, siehe
-- Database::ensureSchemaUpToDate()). Security-by-Design: Mitgliedschaft ist
-- für JEDE Gruppe (auch `admin`/`editor`) ausschließlich explizit über
-- `user_groups` - kein impliziter Standard (siehe
-- BaseController::userGroupIds()). `admin` hat zusätzlich systemseitig immer
-- implizit ALLE Rechte, unabhängig vom Inhalt von `group_permissions` (siehe
-- hasPermission()) - ihre eigene Berechtigungs-Matrix bleibt deshalb bewusst
-- leer und nicht editierbar. `public` repräsentiert nicht angemeldete
-- Besucher und erhält nie Berechtigungs-Zeilen.
CREATE TABLE IF NOT EXISTS `groups` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `slug` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) NULL,
    `is_builtin` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `groups` (`slug`, `name`, `description`, `is_builtin`) VALUES
('admin', 'Administrator', 'Hat systemseitig immer uneingeschränkt alle Berechtigungen.', 1),
('editor', 'Editor', 'Vorlage für Bearbeiter mit Verwaltungszugriff - muss Benutzern wie jede andere Gruppe bewusst zugewiesen werden, kein automatischer Standard.', 1),
('public', 'Öffentlich / Gäste', 'Nicht angemeldete Besucher - erhält niemals Zugriff auf das Backend (/admin/...) und keine Berechtigungen, unabhängig von dieser Tabelle (siehe BaseController::checkAuth()).', 1);

CREATE TABLE IF NOT EXISTS `user_groups` (
    `user_id` INT NOT NULL,
    `group_id` INT NOT NULL,
    PRIMARY KEY (`user_id`, `group_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`group_id`) REFERENCES `groups`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `group_permissions` (
    `group_id` INT NOT NULL,
    `module` VARCHAR(50) NOT NULL,
    `action` VARCHAR(50) NOT NULL,
    PRIMARY KEY (`group_id`, `module`, `action`),
    FOREIGN KEY (`group_id`) REFERENCES `groups`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Editor-GRUPPE (nicht: automatische Mitgliedschaft) behält bei einer frischen
-- Installation dieselben Rechte, die die Rolle vor #66 schon hatte
-- (uneingeschränkter CRUD-Zugriff) - siehe docs/user-groups-plan.md. Wer
-- tatsächlich Mitglied dieser Gruppe wird, entscheidet der Admin bewusst je
-- Benutzer (siehe UserController).
INSERT IGNORE INTO `group_permissions` (`group_id`, `module`, `action`)
SELECT `id`, `module`, `action` FROM `groups`
CROSS JOIN (
    SELECT 'horses' AS `module`, 'create' AS `action` UNION ALL
    SELECT 'horses', 'edit' UNION ALL
    SELECT 'horses', 'delete' UNION ALL
    SELECT 'horses', 'publish' UNION ALL
    SELECT 'persons', 'create' UNION ALL
    SELECT 'persons', 'edit' UNION ALL
    SELECT 'persons', 'delete' UNION ALL
    SELECT 'breeding_stations', 'create' UNION ALL
    SELECT 'breeding_stations', 'edit' UNION ALL
    SELECT 'breeding_stations', 'delete'
) AS `defaults`
WHERE `groups`.`slug` = 'editor';

-- Audit Logs Table
CREATE TABLE IF NOT EXISTS `audit_logs` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
