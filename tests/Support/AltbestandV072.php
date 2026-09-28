<?php
// tests/Support/AltbestandV072.php

namespace Tests\Support;

use PDO;

/**
 * Wegwerf-Datenbanken mit dem Schema einer v0.7.2-Installation
 * (tests/Integration/Fixtures/schema-v0.7.2.sql, schema_version 9).
 *
 * Gemeinsamer Aufbau für Migration336AusV07Test, SchemaMigratorLaufTest und
 * Rollback336Test (Audit M41, M42, N39, N40, N76): Die #336-Umstellung wird
 * an einem echten Altschema geprüft, nicht an einem nachgebauten.
 */
final class AltbestandV072 {

    private function __construct() {}

    public static function admin(): PDO {
        return new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    /** Leere Datenbank anlegen (vorhandene wird verworfen). */
    public static function leer(string $name): PDO {
        $admin = self::admin();
        $admin->exec("DROP DATABASE IF EXISTS `{$name}`");
        $admin->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        return self::verbinden($name);
    }

    /**
     * Datenbank mit dem v0.7.2-Schema. $sqlMode wird an die Sitzung
     * angehängt, etwa ONLY_FULL_GROUP_BY (Voreinstellung von MySQL 8).
     */
    public static function anlegen(string $name, string $sqlMode = ''): PDO {
        $pdo = self::leer($name);
        $pdo->exec((string)file_get_contents(dirname(__DIR__) . '/Integration/Fixtures/schema-v0.7.2.sql'));
        if ($sqlMode !== '') {
            $pdo = self::verbinden($name);
            $pdo->exec("SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',{$sqlMode}')");
        }
        return $pdo;
    }

    /** Datenbank mit dem aktuellen database/schema.sql. */
    public static function aktuell(string $name): PDO {
        $pdo = self::leer($name);
        $pdo->exec((string)file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
        return self::verbinden($name);
    }

    public static function verbinden(string $name): PDO {
        return new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . $name . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }

    public static function entfernen(string $name): void {
        self::admin()->exec("DROP DATABASE IF EXISTS `{$name}`");
    }

    public static function einstellung(PDO $pdo, string $key): ?string {
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $wert = $stmt->fetchColumn();
        return $wert === false ? null : (string)$wert;
    }

    public static function tabelleExistiert(PDO $pdo, string $tabelle): bool {
        return $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($tabelle))->rowCount() > 0;
    }

    public static function spalteExistiert(PDO $pdo, string $tabelle, string $spalte): bool {
        return $pdo->query("SHOW COLUMNS FROM `{$tabelle}` LIKE " . $pdo->quote($spalte))->rowCount() > 0;
    }

    /**
     * Hängezustand nach einer abgebrochenen #336-Übernahme herstellen, wie
     * ihn der Kern bis zum Fix hinterließ (Audit M41/M42): contacts und
     * contact_id_map gefüllt, horse_persons.contact_id/station_contact_id
     * angelegt, schema_version 9. Personen behalten ihre ID, Station n wird
     * Kontakt (höchste Personen-ID + n). Die Map-Zeilen tragen einen
     * Zeitpunkt in der Vergangenheit, damit spätere Bearbeitungen als
     * "in der Hängezeit" erkennbar sind.
     *
     * @param bool $uebernahmeMarker Marker migration_336_contacts_uebernahme
     *                               setzen (M42-Hänger) oder nicht (M41)
     * @return array<int,int> alte Stations-ID => Kontakt-ID
     */
    public static function haengezustand(PDO $pdo, bool $uebernahmeMarker): array {
        $pdo->exec("CREATE TABLE `contacts` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(150) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE `contact_id_map` (
            `old_type` ENUM('person', 'station') NOT NULL,
            `old_id` INT NOT NULL,
            `contact_id` INT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`old_type`, `old_id`),
            INDEX `idx_contact_id_map_contact` (`contact_id`),
            FOREIGN KEY (`contact_id`) REFERENCES `contacts`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("INSERT INTO contacts (id, name) SELECT id, name FROM persons");
        $pdo->exec("INSERT INTO contact_id_map (old_type, old_id, contact_id, created_at)
                    SELECT 'person', id, id, NOW() - INTERVAL 1 DAY FROM persons");
        $zuordnung = [];
        $basis = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM persons")->fetchColumn();
        foreach ($pdo->query("SELECT id, name FROM breeding_stations ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $neu = ++$basis;
            $pdo->prepare("INSERT INTO contacts (id, name) VALUES (?, ?)")->execute([$neu, $s['name']]);
            $pdo->prepare("INSERT INTO contact_id_map (old_type, old_id, contact_id, created_at)
                           VALUES ('station', ?, ?, NOW() - INTERVAL 1 DAY)")->execute([(int)$s['id'], $neu]);
            $zuordnung[(int)$s['id']] = $neu;
        }

        $pdo->exec("ALTER TABLE `horse_persons` ADD COLUMN `contact_id` INT NULL DEFAULT NULL AFTER `horse_id`");
        $pdo->exec("ALTER TABLE `horse_persons` ADD COLUMN `station_contact_id` INT NULL DEFAULT NULL AFTER `role`");
        if ($uebernahmeMarker) {
            $pdo->exec("INSERT INTO settings (setting_key, setting_value)
                        VALUES ('migration_336_contacts_uebernahme', '2026-01-01T00:00:00+00:00')");
        }
        return $zuordnung;
    }
}
