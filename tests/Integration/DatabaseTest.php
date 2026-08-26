<?php
// tests/Integration/DatabaseTest.php

namespace Tests\Integration;

use App\Database;
use PDO;
use PHPUnit\Framework\Attributes\Depends;
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

        // Ein eventuell von einem früheren Test im selben PHPUnit-Prozess
        // erzeugter Database-Singleton würde unten in
        // testEnsureSchemaUpToDateMigratesLegacySchema() wiederverwendet -
        // getInstance() liefe dann OHNE ensureSchemaUpToDate() gegen das gerade
        // frisch angelegte Alt-Schema. Der Reset stellt sicher, dass diese
        // Klasse immer mit einem echten Verbindungsaufbau (inkl. Migration)
        // testet, unabhängig davon, was vorher im Prozess passiert ist.
        self::resetDatabaseSingleton();

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
        //
        // `settings` gehört mit ins Alt-Schema: Die Tabelle existiert seit der
        // allerersten Fassung von database/schema.sql in JEDER Installation und
        // ist zugleich die Ablage des versionierten Migrationsstands (#213,
        // settings.schema_version) - ohne sie könnte ensureSchemaUpToDate() den
        // Stand nicht persistieren und der Kurzschluss-Test unten liefe ins Leere.
        self::$setupPdo->exec("
            CREATE TABLE `settings` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `setting_key` VARCHAR(50) NOT NULL UNIQUE,
                `setting_value` TEXT,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
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

        // Bestandspferde mit dem Alt-Enum inkl. 'deceased' - der Status-Split
        // (#188, Block 21 in ensureSchemaUpToDate()) muss das deceased-Pferd
        // in is_deceased=1 + status='inactive' überführen und die anderen
        // unangetastet lassen.
        self::$setupPdo->exec("
            INSERT INTO `horses` (`name`, `status`) VALUES
            ('Legacy Aktiv', 'active'),
            ('Legacy Inaktiv', 'inactive'),
            ('Legacy Verstorben', 'deceased')
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
        // Gesperrt ist nicht geloescht (#358) - drei eigene Spalten.
        $this->assertColumnExists($pdo, 'users', 'deactivated_at');
        $this->assertColumnExists($pdo, 'users', 'deactivated_reason');
        $this->assertColumnExists($pdo, 'users', 'unprotected_since');

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
        $this->assertColumnExists($pdo, 'horses', 'is_published');
        $this->assertColumnExists($pdo, 'horses', 'deleted_at');

        // Geschlecht/Rasse (#172) und der Stammdaten-Ausbau (#188)
        $this->assertColumnExists($pdo, 'horses', 'sex');
        $this->assertColumnExists($pdo, 'horses', 'breed');
        $this->assertColumnExists($pdo, 'horses', 'birth_date');
        $this->assertColumnExists($pdo, 'horses', 'height_cm');

        // Genauigkeit des Geburtsdatums (#379, SCHEMA_VERSION 18). Typ und
        // Vorgabe werden mitgeprüft: Eine Migration, die die Spalte mit
        // Vorgabe 'year' anlegt, änderte auf jedem Bestand still die Anzeige
        // von knapp der Hälfte aller Pferde - und das fiele sonst nirgends auf.
        $this->assertColumnExists($pdo, 'horses', 'birth_date_precision');
        $spalte = $pdo->query("SHOW COLUMNS FROM `horses` LIKE 'birth_date_precision'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($spalte);
        $this->assertStringContainsString("enum('day','year')", strtolower((string)$spalte['Type']));
        $this->assertSame('day', (string)$spalte['Default'], 'Die Vorgabe muss day sein - sonst ändert das Update das Anzeigeverhalten eines Bestands.');
        $this->assertSame('NO', (string)$spalte['Null'], 'Kein NULL: eine dritte, unbestimmte Genauigkeit wäre genau die Unschärfe, die #379 beseitigt.');
        $this->assertColumnExists($pdo, 'horses', 'is_deceased');
        $this->assertColumnExists($pdo, 'horses', 'death_year');

        // Kastrationsdatum (#239, SCHEMA_VERSION 2)
        $this->assertColumnExists($pdo, 'horses', 'castration_date');

        // Strukturierte Kontaktdaten (#188 Personen, #256 Bundesland und
        // Stationsadresse) - seit #336 an EINER Tabelle. Die Liste ist die
        // Vereinigung dessen, was vorher an persons und breeding_stations
        // stand; keine Spalte darf beim Zusammenlegen verlorengegangen sein.
        foreach ([
            'street', 'house_number', 'postal_code', 'city', 'state', 'country',
            'email', 'phone', 'mobile', 'website', 'membership_status',
            'is_breeder', 'contact_public', 'is_published',
            // kamen von den Deckstationen:
            'contact_person', 'address',
            // kam von den Personen:
            'contact_info',
        ] as $column) {
            $this->assertColumnExists($pdo, 'contacts', $column);
        }

        // Und die Zuordnungstabelle, ohne die Addons ihre gespeicherten
        // Verweise nicht umrechnen können (#336). Sie bleibt dauerhaft.
        $this->assertTableExists($pdo, 'contact_id_map');

        // birth_year wird von YEAR auf SMALLINT UNSIGNED umgestellt (historische
        // Geburtsjahre vor 1901, die der YEAR-Typ nicht abbilden kann, siehe #10
        // in ensureSchemaUpToDate())
        $columnType = $pdo->query("SHOW COLUMNS FROM `horses` LIKE 'birth_year'")->fetch()['Type'] ?? '';
        $this->assertStringContainsString('smallint', strtolower($columnType));

        // Status-Split (#188): 'deceased' muss aus dem Enum entfernt sein und
        // der Backfill muss das Bestandspferd korrekt überführt haben - die
        // beiden anderen bleiben unangetastet.
        $statusType = $pdo->query("SHOW COLUMNS FROM `horses` LIKE 'status'")->fetch()['Type'] ?? '';
        $this->assertStringNotContainsString('deceased', strtolower($statusType), "horses.status-Enum sollte 'deceased' nach dem Status-Split nicht mehr enthalten");

        $rows = $pdo->query("SELECT name, status, is_deceased FROM horses ORDER BY id")->fetchAll();
        $byName = array_column($rows, null, 'name');
        $this->assertSame('active', $byName['Legacy Aktiv']['status']);
        $this->assertSame(0, (int)$byName['Legacy Aktiv']['is_deceased']);
        $this->assertSame('inactive', $byName['Legacy Inaktiv']['status']);
        $this->assertSame(0, (int)$byName['Legacy Inaktiv']['is_deceased']);
        $this->assertSame('inactive', $byName['Legacy Verstorben']['status']);
        $this->assertSame(1, (int)$byName['Legacy Verstorben']['is_deceased']);

        // Neue Tabellen, die ensureSchemaUpToDate() bei Bedarf komplett anlegt
        $this->assertTableExists($pdo, 'audit_logs');
        $this->assertTableExists($pdo, 'contacts');
        $this->assertTableExists($pdo, 'horse_persons');
        $this->assertTableExists($pdo, 'password_resets');
        $this->assertTableExists($pdo, 'gdpr_requests');
        $this->assertTableExists($pdo, 'login_attempts');
        $this->assertTableExists($pdo, 'groups');
        $this->assertTableExists($pdo, 'user_groups');
        $this->assertTableExists($pdo, 'group_permissions');

        // Weitere Lebensnummern (#246, SCHEMA_VERSION 3)
        $this->assertTableExists($pdo, 'horse_registrations');
        $this->assertIndexExists($pdo, 'horse_registrations', 'idx_horse_registrations_number');

        // Katalog-Filter-Indizes (#221) und die neuen Spalten für den
        // Plugin-Verzeichnis-Stempel (#224) bzw. die API-Schlüssel-Kopplung
        // an session_version (#217)
        $this->assertIndexExists($pdo, 'horses', 'idx_horses_color');
        $this->assertIndexExists($pdo, 'horses', 'idx_horses_breed');

        /* Indexlage der Katalog-Vorschlagslisten (#412, SCHEMA_VERSION 20).
           Die Spalten werden mitgeprüft, nicht nur der Name: Der Index
           `idx_horse_persons_contact` existierte schon vorher - nur zu schmal.
           Ein reiner Namenstest wäre für eine Bestandsinstallation grün, ohne
           dass die Erweiterung je gelaufen wäre. */
        $this->assertIndexColumns($pdo, 'contacts', 'idx_contacts_published_name',
            ['is_published', 'deleted_at', 'name']);
        $this->assertIndexColumns($pdo, 'horse_persons', 'idx_horse_persons_contact',
            ['contact_id', 'horse_id']);
        $this->assertColumnExists($pdo, 'plugins', 'dir_stamp');
        $this->assertColumnExists($pdo, 'api_keys', 'issued_session_version');

        // Versionierter Migrationsstand (#213): Nach dem ersten getInstance()
        // muss der aktuelle Stand in settings.schema_version persistiert sein -
        // er ist der Kurzschluss, der alle folgenden Requests auf eine einzige
        // Abfrage reduziert (siehe die beiden Tests unten).
        $this->assertSame(Database::SCHEMA_VERSION, $this->storedSchemaVersion());

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

    /**
     * Die Verbindung bringt die Zeitzone der Datenbank mit der von PHP zur
     * Deckung (siehe Database::alignSessionTimeZone()). Ohne das rechnen die
     * über dreißig `NOW()`/`CURDATE()`-Stellen des Kerns in einer anderen
     * Zeitzone als jeder PHP-seitige Vergleich - eine Abweichung, die nicht
     * als Fehler auffällt, sondern als Langsamkeit (der Katalog-Cache galt
     * dauerhaft als abgelaufen, #290) oder als Zählfehler (die Tagesstatistik
     * buchte in den Kübel des Vortags).
     *
     * Der Test greift auch dann, wenn beide Seiten zufällig übereinstimmen -
     * er vergleicht nicht zwei Zeitzonen miteinander, sondern die Uhr der
     * Datenbank mit der von PHP. Genau darauf kommt es an, und genau das war
     * im offiziellen Container (beide UTC) nie zu sehen.
     */
    public function testConnectionAlignsDatabaseClockWithPhpClock(): void {
        $pdo = Database::getInstance();

        $dbNow = (string)$pdo->query('SELECT NOW()')->fetchColumn();
        $delta = abs(time() - (int)strtotime($dbNow));

        $this->assertLessThanOrEqual(
            5,
            $delta,
            "Die Uhr der Datenbank ({$dbNow}) weicht um {$delta} s von der PHP-Uhr ("
            . date('Y-m-d H:i:s') . ') ab - die Sitzungs-Zeitzone wurde nicht angeglichen.'
        );

        // Und die Datumsgrenze, an der die Tagesstatistik hängt.
        $this->assertSame(
            (new \DateTimeImmutable('today'))->format('Y-m-d'),
            (string)$pdo->query('SELECT CURDATE()')->fetchColumn(),
            'CURDATE() muss denselben Tag liefern wie PHP - sonst landen frische Datensätze im Kübel des Vortags.'
        );
    }

    /**
     * Beweist den Kurzschluss (#213): Ist settings.schema_version aktuell, führt
     * ein neuer Verbindungsaufbau die Migrationsschritte NICHT mehr aus. Dazu
     * wird eine von der Migration angelegte Spalte absichtlich gedroppt - bliebe
     * die Migration ungegatet (wie früher), würde der nächste getInstance() sie
     * sofort wieder anlegen und dieser Test schlüge fehl.
     */
    #[Depends('testEnsureSchemaUpToDateMigratesLegacySchema')]
    public function testCurrentSchemaVersionShortCircuitsMigrationOnNewConnection(): void {
        self::$setupPdo->exec("ALTER TABLE `contacts` DROP COLUMN `membership_status`");

        self::resetDatabaseSingleton();
        $pdo = Database::getInstance();

        $stmt = $pdo->query("SHOW COLUMNS FROM `contacts` LIKE 'membership_status'");
        $this->assertSame(
            0,
            $stmt->rowCount(),
            'contacts.membership_status wurde trotz aktuellem schema_version-Stand neu angelegt - der Kurzschluss in ensureSchemaUpToDate() greift nicht'
        );
    }

    /**
     * Gegenprobe zum Kurzschluss-Test: Ein zurückgesetzter Stand (wie ihn ein
     * Update mit erhöhter SCHEMA_VERSION erzeugt - dort ist es die Konstante,
     * die dem gespeicherten Stand davonläuft) lässt die Migration beim nächsten
     * Verbindungsaufbau wieder vollständig laufen und den Stand neu persistieren.
     */
    #[Depends('testCurrentSchemaVersionShortCircuitsMigrationOnNewConnection')]
    public function testOutdatedSchemaVersionRerunsMigrationsOnNewConnection(): void {
        self::$setupPdo->exec("UPDATE `settings` SET `setting_value` = '0' WHERE `setting_key` = 'schema_version'");

        // Backfill-Gate der weiteren Lebensnummern (#246): foreign_ueln wird
        // NACH der Erstmigration befüllt - der erneute Voll-Lauf unten darf es
        // nicht in horse_registrations zerlegen, denn der Einmal-Backfill ist
        // an das erstmalige Anlegen der Tabelle gekoppelt (sonst würde jeder
        // Lauf bewusst gepflegte Nummern aus dem veralteten Feld duplizieren).
        self::$setupPdo->exec("UPDATE `horses` SET `foreign_ueln` = 'NOR 111 / SWE 222' WHERE `name` = 'Legacy Aktiv'");

        self::resetDatabaseSingleton();
        $pdo = Database::getInstance();

        // Die im Kurzschluss-Test gedroppte Spalte muss wieder da sein ...
        $this->assertColumnExists($pdo, 'contacts', 'membership_status');
        // ... und der Stand erneut auf der aktuellen SCHEMA_VERSION stehen.
        $this->assertSame(Database::SCHEMA_VERSION, $this->storedSchemaVersion());

        $this->assertSame(
            0,
            (int)$pdo->query("SELECT COUNT(*) FROM horse_registrations")->fetchColumn(),
            'Der foreign_ueln-Backfill darf bei einem erneuten Migrationslauf nicht wiederholt werden (Einmal-Gate über SHOW TABLES)'
        );
    }

    /**
     * Setzt den Database-Singleton zurück, um einen frischen Verbindungsaufbau
     * (= den Bootstrap eines neuen Requests, PHP ist share-nothing) im selben
     * PHP-Prozess zu simulieren - nur so ist der request-übergreifende
     * Kurzschluss über settings.schema_version überhaupt testbar.
     */
    private static function resetDatabaseSingleton(): void {
        $property = new \ReflectionProperty(Database::class, 'instance');
        $property->setValue(null, null);
    }

    private function storedSchemaVersion(): int {
        return (int)self::$setupPdo
            ->query("SELECT setting_value FROM `settings` WHERE setting_key = 'schema_version'")
            ->fetchColumn();
    }

    private function assertIndexExists(PDO $pdo, string $table, string $indexName): void {
        $stmt = $pdo->prepare("SHOW INDEX FROM `$table` WHERE Key_name = ?");
        $stmt->execute([$indexName]);
        $this->assertGreaterThan(0, $stmt->rowCount(), "Erwarteter Index {$table}.{$indexName} fehlt nach ensureSchemaUpToDate()");
    }

    /**
     * @param array<int, string> $erwartet Spalten in der erwarteten Reihenfolge
     */
    private function assertIndexColumns(PDO $pdo, string $table, string $indexName, array $erwartet): void {
        // SHOW INDEX vertraegt kein ORDER BY - deshalb hier sortieren.
        $stmt = $pdo->prepare("SHOW INDEX FROM `$table` WHERE Key_name = ?");
        $stmt->execute([$indexName]);
        $zeilen = $stmt->fetchAll(PDO::FETCH_ASSOC);
        usort($zeilen, static fn(array $a, array $b): int => (int)$a['Seq_in_index'] <=> (int)$b['Seq_in_index']);
        $spalten = array_column($zeilen, 'Column_name');
        $this->assertSame(
            $erwartet,
            $spalten,
            "Index {$table}.{$indexName} hat nicht die erwarteten Spalten nach ensureSchemaUpToDate()"
        );
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
