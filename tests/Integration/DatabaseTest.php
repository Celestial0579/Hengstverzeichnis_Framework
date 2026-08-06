<?php
// tests/Integration/DatabaseTest.php

namespace Tests\Integration;

use App\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Prüft Database::ensureSchemaUpToDate() (den impliziten Migrationsmechanismus,
 * siehe Issue #54) gegen eine echte Test-Datenbank. Braucht DB_HOST/DB_NAME/etc.
 * als Konstanten (siehe tests/bootstrap.php) - läuft nur, wenn eine Test-DB per
 * Umgebungsvariable konfiguriert ist (siehe .github/workflows/tests.yml).
 *
 * Die Test-DB wird hier absichtlich mit einem stark REDUZIERTEN "Alt"-Schema
 * (ohne die von ensureSchemaUpToDate() nachgezogenen Spalten/Tabellen) befüllt,
 * bevor Database::getInstance() zum ersten Mal aufgerufen wird - database/schema.sql
 * ist mittlerweile selbst schon vollständig aktuell und würde die Migration gar
 * nicht mehr sichtbar testen (jeder $addColumn()-Aufruf wäre von vornherein ein No-Op).
 * Das Alt-Schema behält bewusst die inzwischen aus database/schema.sql entfernte
 * users.role-Spalte samt zweier Bestandsbenutzer, um die Einmal-Migration
 * (Backfill nach user_groups + DROP COLUMN role, siehe
 * Database::ensureSchemaUpToDate()) tatsächlich zu durchlaufen.
 */
class DatabaseTest extends TestCase {

    private static PDO $setupPdo;

    public static function setUpBeforeClass(): void {
        if (!defined('DB_HOST')) {
            self::markTestSkipped('Keine Test-Datenbank konfiguriert (DB_HOST fehlt) - siehe tests/bootstrap.php.');
        }

        self::$setupPdo = new PDO(
            "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        // Sauberer Start: alle Tabellen der Test-DB löschen, unabhängig von einem
        // eventuellen Vorlauf (Fremdschlüssel-Prüfung kurz deaktivieren).
        self::$setupPdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        $tables = self::$setupPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            self::$setupPdo->exec("DROP TABLE IF EXISTS `$table`");
        }
        self::$setupPdo->exec("SET FOREIGN_KEY_CHECKS = 1");

        // Reduziertes Alt-Schema: nur die absolut nötigen Basistabellen/-spalten,
        // wie sie vor den in ensureSchemaUpToDate() nachgezogenen Änderungen
        // ausgesehen hätten.
        self::$setupPdo->exec("
            CREATE TABLE `users` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `username` VARCHAR(50) NOT NULL UNIQUE,
                `email` VARCHAR(100) NOT NULL UNIQUE,
                `password_hash` VARCHAR(255) NOT NULL,
                `role` ENUM('admin', 'editor') DEFAULT 'editor',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Bestandsbenutzer mit dem inzwischen entfernten users.role - simuliert
        // eine Installation von VOR der Rollensystem-Entfernung. Muss vor dem
        // ersten Database::getInstance()-Aufruf existieren, damit
        // ensureSchemaUpToDate() sie in echte user_groups-Zeilen überführen kann
        // (siehe testEnsureSchemaUpToDateMigratesLegacySchema()).
        self::$setupPdo->exec("
            INSERT INTO `users` (`username`, `email`, `password_hash`, `role`) VALUES
            ('legacy-admin', 'legacy-admin@example.com', 'x', 'admin'),
            ('legacy-editor', 'legacy-editor@example.com', 'x', 'editor')
        ");
        self::$setupPdo->exec("
            CREATE TABLE `persons` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL,
                `contact_info` TEXT,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        self::$setupPdo->exec("
            CREATE TABLE `horses` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL,
                `ueln` VARCHAR(50) UNIQUE,
                `birth_year` YEAR NULL,
                `color` VARCHAR(50),
                `description` TEXT,
                `status` ENUM('active', 'inactive', 'deceased') DEFAULT 'active',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * Erste Verbindung über Database::getInstance() löst ensureSchemaUpToDate()
     * gegen das reduzierte Alt-Schema aus. Ein einziger Test, damit die Reihenfolge
     * (erst Alt-Schema anlegen, dann EINMAL verbinden) garantiert eingehalten wird -
     * PHPUnit-Testmethoden innerhalb einer Klasse dürfen sich sonst nicht auf eine
     * bestimmte Ausführungsreihenfolge verlassen.
     */
    public function testEnsureSchemaUpToDateMigratesLegacySchema(): void {
        $pdo = Database::getInstance();

        // Neue Spalten auf `users`, die ensureSchemaUpToDate() nachziehen muss
        $this->assertColumnExists($pdo, 'users', 'totp_secret');
        $this->assertColumnExists($pdo, 'users', 'totp_enabled');
        $this->assertColumnExists($pdo, 'users', 'backup_codes');
        $this->assertColumnExists($pdo, 'users', 'must_change_password');
        $this->assertColumnExists($pdo, 'users', 'deleted_at');

        // Neue Spalten auf `horses` (Abstammung, Deckstation, Papierkorb)
        $this->assertColumnExists($pdo, 'horses', 'foreign_ueln');
        $this->assertColumnExists($pdo, 'horses', 'sire_id');
        $this->assertColumnExists($pdo, 'horses', 'sire_name');
        $this->assertColumnExists($pdo, 'horses', 'sire_ueln');
        $this->assertColumnExists($pdo, 'horses', 'dam_id');
        $this->assertColumnExists($pdo, 'horses', 'dam_name');
        $this->assertColumnExists($pdo, 'horses', 'dam_ueln');
        $this->assertColumnExists($pdo, 'horses', 'breeding_station_id');
        $this->assertColumnExists($pdo, 'horses', 'breeding_station');
        $this->assertColumnExists($pdo, 'horses', 'image_url');
        $this->assertColumnExists($pdo, 'horses', 'deleted_at');

        // birth_year wird von YEAR auf SMALLINT UNSIGNED umgestellt (historische
        // Geburtsjahre vor 1901, die der YEAR-Typ nicht abbilden kann, siehe #10
        // in ensureSchemaUpToDate())
        $columnType = $pdo->query("SHOW COLUMNS FROM `horses` LIKE 'birth_year'")->fetch()['Type'] ?? '';
        $this->assertStringContainsString('smallint', strtolower($columnType));

        // Neue Tabellen, die ensureSchemaUpToDate() bei Bedarf komplett anlegt
        $this->assertTableExists($pdo, 'audit_logs');
        $this->assertTableExists($pdo, 'breeding_stations');
        $this->assertTableExists($pdo, 'horse_persons');
        $this->assertTableExists($pdo, 'password_resets');
        $this->assertTableExists($pdo, 'gdpr_requests');
        $this->assertTableExists($pdo, 'login_attempts');
        $this->assertTableExists($pdo, 'groups');
        $this->assertTableExists($pdo, 'user_groups');
        $this->assertTableExists($pdo, 'group_permissions');

        // Rollensystem entfernt: users.role muss weg sein, und die vorher per
        // role='admin'/'editor' angelegten Bestandsbenutzer (siehe
        // setUpBeforeClass()) müssen die passende user_groups-Zeile bekommen
        // haben, damit sich ihre Rechte durch die Migration nicht ändern.
        $stmt = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'role'");
        $this->assertSame(0, $stmt->rowCount(), "Spalte users.role sollte nach ensureSchemaUpToDate() entfernt sein");

        $this->assertUserIsMemberOfGroup($pdo, 'legacy-admin@example.com', 'admin');
        $this->assertUserIsMemberOfGroup($pdo, 'legacy-editor@example.com', 'editor');

        // Verbindung bleibt trotz der vielen ALTER/CREATE-Statements funktionsfähig
        $this->assertSame('1', (string)$pdo->query("SELECT 1")->fetchColumn());
    }

    public function testGetInstanceReturnsSameSingletonAcrossCalls(): void {
        $this->assertSame(Database::getInstance(), Database::getInstance());
    }

    private function assertColumnExists(PDO $pdo, string $table, string $column): void {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($column));
        $this->assertGreaterThan(0, $stmt->rowCount(), "Erwartete Spalte {$table}.{$column} fehlt nach ensureSchemaUpToDate()");
    }

    private function assertTableExists(PDO $pdo, string $table): void {
        $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
        $this->assertGreaterThan(0, $stmt->rowCount(), "Erwartete Tabelle {$table} fehlt nach ensureSchemaUpToDate()");
    }

    private function assertUserIsMemberOfGroup(PDO $pdo, string $email, string $groupSlug): void {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM user_groups ug
            JOIN users u ON u.id = ug.user_id
            JOIN `groups` g ON g.id = ug.group_id
            WHERE u.email = ? AND g.slug = ?
        ");
        $stmt->execute([$email, $groupSlug]);
        $this->assertGreaterThan(
            0,
            (int)$stmt->fetchColumn(),
            "Erwartete user_groups-Zeile für {$email} in Gruppe '{$groupSlug}' fehlt nach der role-Migration"
        );
    }
}
