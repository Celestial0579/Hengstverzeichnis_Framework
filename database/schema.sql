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
    `last_totp_timeslice` BIGINT NULL DEFAULT NULL,
    -- Selfservice-Registrierung (#83): gesetzter Token = E-Mail noch nicht
    -- verifiziert, Login gesperrt. Admin-angelegte Konten: immer NULL.
    `email_verification_token` VARCHAR(64) NULL DEFAULT NULL,
    `email_verification_expires_at` DATETIME NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL DEFAULT NULL,
    INDEX `idx_users_deleted` (`deleted_at`)
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
    -- Öffentliche Sichtbarkeit (unabhängig vom Datensatz-Status): nur is_published = 1
    -- erscheint in öffentlichen Katalog-Filterlisten. Neu angelegte Personen sind
    -- standardmäßig unveröffentlicht und werden über die Admin-Verwaltung freigegeben.
    `is_published` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL DEFAULT NULL,
    INDEX `idx_persons_deleted_name` (`deleted_at`, `name`)
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
    -- Öffentliche Sichtbarkeit (unabhängig vom Datensatz-Status): nur is_published = 1
    -- erscheint auf der öffentlichen Stations-Detailseite und in den Katalog-Filterlisten.
    -- Neu angelegte Stationen sind standardmäßig unveröffentlicht.
    `is_published` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL DEFAULT NULL,
    INDEX `idx_bs_deleted_name` (`deleted_at`, `name`)
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
    -- Geschlecht (#165): NULL = unbekannt (Altbestand). Werte englisch wie beim
    -- Lebenszyklus-`status`; Wallache sind als Vater ausgeschlossen (#166).
    `sex` ENUM('stallion', 'mare', 'gelding') NULL DEFAULT NULL,
    -- Rasse (#163): bewusst Freitext, keine normierte Rasseliste.
    `breed` VARCHAR(100) NULL DEFAULT NULL,
    `breeding_station_id` INT NULL,
    `breeding_station` VARCHAR(255) NULL,
    `description` TEXT,
    `status` ENUM('active', 'inactive', 'deceased') DEFAULT 'active',
    -- Öffentliche Sichtbarkeit, bewusst UNABHÄNGIG vom Lebenszyklus-`status`
    -- (#66-Folge): nur is_published = 1 erscheint im öffentlichen Katalog/API,
    -- gesteuert durch die Berechtigung horses.publish. `status` ist rein
    -- informativ (Gekört/Inaktiv/Verstorben) und beeinflusst die Sichtbarkeit nicht.
    `is_published` TINYINT(1) NOT NULL DEFAULT 0,
    `image_url` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL DEFAULT NULL,
    FOREIGN KEY (`sire_id`) REFERENCES `horses`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`dam_id`) REFERENCES `horses`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`breeding_station_id`) REFERENCES `breeding_stations`(`id`) ON DELETE SET NULL,
    -- Indizes für die Standardfilter/-sortierung der öffentlichen Abfragen
    -- (is_published + deleted_at + ORDER BY name) sowie Namens-/UELN-Lookups
    -- des PedigreeBuilder (#120)
    INDEX `idx_horses_published_name` (`is_published`, `deleted_at`, `name`),
    INDEX `idx_horses_deleted_name` (`deleted_at`, `name`),
    INDEX `idx_horses_name` (`name`),
    INDEX `idx_horses_foreign_ueln` (`foreign_ueln`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Horse Persons Relation (Ownership History & Roles)
-- person_id ist NULL-faehig: Eine Zeile kann auch NUR eine Deckstation
-- zuordnen (breeding_station_id fuer verknuepfte Stationen,
-- breeding_station_text fuer freien Text ohne Stations-Datensatz).
CREATE TABLE IF NOT EXISTS `horse_persons` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `horse_id` INT NOT NULL,
    `person_id` INT NULL DEFAULT NULL,
    `role` ENUM('breeder', 'owner', 'keeper') NOT NULL DEFAULT 'owner',
    `breeding_station_id` INT NULL,
    `breeding_station_text` VARCHAR(255) NULL,
    `from_year` SMALLINT UNSIGNED NULL,
    `until_year` SMALLINT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`horse_id`) REFERENCES `horses`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`person_id`) REFERENCES `persons`(`id`) ON DELETE CASCADE,
    INDEX `idx_horse_persons_horse_role` (`horse_id`, `role`)
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

-- Addon-Store-Quellen (GitHub-Repos, aus denen /admin/plugins/store Addons
-- anbietet) samt Katalog-Cache. Das offizielle Repo wird von
-- Database::ensureSchemaUpToDate() per INSERT IGNORE eingetragen.
CREATE TABLE IF NOT EXISTS `addon_repos` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `owner` VARCHAR(100) NOT NULL,
    `repo` VARCHAR(100) NOT NULL,
    `ref` VARCHAR(100) NULL DEFAULT NULL,
    `is_official` TINYINT(1) NOT NULL DEFAULT 0,
    `added_by` INT NULL DEFAULT NULL,
    `cached_catalog_json` MEDIUMTEXT NULL DEFAULT NULL,
    `cached_at` DATETIME NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `owner_repo` (`owner`, `repo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gruppen-/Berechtigungssystem (#66, siehe docs/user-groups-plan.md und
-- BaseController::hasPermission()) - EINZIGES Rechtesystem der App (das
-- frühere users.role wurde vollständig entfernt, siehe
-- Database::ensureSchemaUpToDate()). Security-by-Design: Für angemeldete
-- Benutzer ist Mitgliedschaft ausschließlich explizit über `user_groups`;
-- einzig nicht angemeldete Besucher gehören automatisch der Gast-Gruppe
-- `public` an (GroupMembership::groupIds(null)). `admin` hat systemseitig
-- immer implizit ALLE Rechte, unabhängig vom Inhalt von `group_permissions`
-- (siehe hasPermission()) - ihre eigene Berechtigungs-Matrix bleibt deshalb
-- bewusst leer und nicht editierbar. `public` erhält per Seed unten
-- Leseberechtigungen (horses.view, breeding_stations.view) und ist über die
-- Matrix editierbar - sie ist der Steuerungspunkt der öffentlichen
-- Sichtbarkeit (#121/#122).
CREATE TABLE IF NOT EXISTS `groups` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `slug` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) NULL,
    `is_builtin` TINYINT(1) NOT NULL DEFAULT 0,
    -- 2FA-Pflicht pro Gruppe (#84): 1 = Mitglieder müssen TOTP-2FA einrichten.
    -- Für `admin` fest verdrahtet immer verpflichtend, unabhängig von dieser
    -- Spalte (siehe AuthController::userRequires2fa()).
    `require_2fa` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `groups` (`slug`, `name`, `description`, `is_builtin`) VALUES
('admin', 'Administrator', 'Hat systemseitig immer uneingeschränkt alle Berechtigungen.', 1),
('editor', 'Editor', 'Vorlage für Bearbeiter mit Verwaltungszugriff - muss Benutzern wie jede andere Gruppe bewusst zugewiesen werden, kein automatischer Standard.', 1),
('public', 'Gast (Öffentlich)', 'Gilt automatisch für nicht angemeldete Besucher. Über ihre Lese-Rechte steuert ein Admin, welche Bereiche im öffentlichen Teil der Website sichtbar sind. Backend-Zugriff (/admin/...) bleibt stets ausgeschlossen (siehe BaseController::checkAuth()).', 1);

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
-- Installation vollen Verwaltungszugriff auf alle Inhaltsmodule - inklusive der
-- Standard-Aktionen 'view' (Lesen) und 'publish' (Veröffentlichen), siehe
-- App\Permission\PermissionRegistry::STANDARD_ACTIONS und docs/user-groups-plan.md.
-- Wer tatsächlich Mitglied dieser Gruppe wird, entscheidet der Admin bewusst je
-- Benutzer (siehe UserController).
INSERT IGNORE INTO `group_permissions` (`group_id`, `module`, `action`)
SELECT `id`, `module`, `action` FROM `groups`
CROSS JOIN (
    SELECT 'horses' AS `module`, 'view' AS `action` UNION ALL
    SELECT 'horses', 'create' UNION ALL
    SELECT 'horses', 'edit' UNION ALL
    SELECT 'horses', 'delete' UNION ALL
    SELECT 'horses', 'publish' UNION ALL
    SELECT 'persons', 'view' UNION ALL
    SELECT 'persons', 'create' UNION ALL
    SELECT 'persons', 'edit' UNION ALL
    SELECT 'persons', 'delete' UNION ALL
    SELECT 'persons', 'publish' UNION ALL
    SELECT 'breeding_stations', 'view' UNION ALL
    SELECT 'breeding_stations', 'create' UNION ALL
    SELECT 'breeding_stations', 'edit' UNION ALL
    SELECT 'breeding_stations', 'delete' UNION ALL
    SELECT 'breeding_stations', 'publish'
) AS `defaults`
WHERE `groups`.`slug` = 'editor';

-- Gast-GRUPPE (`public`): erhält standardmäßig ausschließlich die Lese-Rechte für
-- die heute öffentlich sichtbare Fläche (Katalog + Deckstationsdetail). Bewusst
-- KEINE weiteren Rechte: neue/Plugin-Bereiche sind für nicht angemeldete Besucher
-- damit fail-closed unsichtbar, bis ein Admin sie bewusst freischaltet
-- (Datenleck-Schutz, siehe docs/user-groups-plan.md).
INSERT IGNORE INTO `group_permissions` (`group_id`, `module`, `action`)
SELECT `id`, `module`, `action` FROM `groups`
CROSS JOIN (
    SELECT 'horses' AS `module`, 'view' AS `action` UNION ALL
    SELECT 'breeding_stations', 'view'
) AS `guest_defaults`
WHERE `groups`.`slug` = 'public';

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

-- API-Schlüssel für die JSON-API (`/api/...`, siehe docs/api.md und
-- App\Security\ApiKey). Die API ist ausschließlich mit einem gültigen Schlüssel
-- erreichbar; jeder Schlüssel gehört einem Benutzer und darf höchstens das, was
-- dieser Benutzer aktuell selbst darf (Schnittmenge aus seinen Gruppenrechten
-- und `scope_permissions`).
--
-- `token_hash` ist ein SHA-256-Hash - der Klartext-Schlüssel wird nie
-- gespeichert und ist nach dem Anlegen nicht wieder abrufbar (wie die
-- 2FA-Backup-Codes). `scope_permissions` = NULL bedeutet "alle Rechte des
-- Besitzers" (dynamisch), sonst eine JSON-Liste erlaubter "modul.aktion"-Paare.
CREATE TABLE IF NOT EXISTS `api_keys` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
