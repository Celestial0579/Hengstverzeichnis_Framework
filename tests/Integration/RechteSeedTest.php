<?php
// tests/Integration/RechteSeedTest.php

namespace Tests\Integration;

use App\Service\SchemaMigrator;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Die Rechte-Seeds im Schema-Migrator laufen genau einmal (Audit M22, N18).
 *
 * WORUM ES GEHT. Zwei Seeds haben früher aus dem aktuellen Rechtebestand
 * geschlossen, ob sie schon gelaufen waren:
 *  - der Standardrechte-Seed (#66) prüfte nur "gibt es irgendeine
 *    view-Zeile?" - ein Admin, der alle Leserechte entzogen hatte, bekam sie
 *    mit dem nächsten Update zurück (N18);
 *  - der Gast-Seed persons.view (#293) lief bei jedem Voll-Lauf vor #336;
 *    INSERT IGNORE verhinderte nur Duplikate. Schritt 31e machte aus dem
 *    wieder eingesetzten persons.view dann contacts.view für Gäste (M22).
 *
 * Jetzt entscheiden ein Marker in settings, der Stand VOR der Migration und
 * ein struktureller Befund. Geprüft wird beides: dass entzogene Rechte
 * entzogen bleiben, und dass Altstände die Vorgaben weiterhin einmal
 * erhalten.
 *
 * Eigene Wegwerf-Datenbank wie SchemaMigratorTest - ohne App\Database, damit
 * jeder Effekt vom expliziten run() stammt.
 */
class RechteSeedTest extends TestCase {

    private const MELDUNG_STANDARDRECHTE = 'Standardrechte für die Gruppen editor/public geseedet (group_permissions)';
    private const MELDUNG_GASTRECHT = 'Gast-Gruppe: Leserecht persons.view für die öffentliche Personenseite vergeben (#293)';
    private const AKTIONEN = ['view', 'create', 'edit', 'delete', 'publish'];

    private static PDO $adminPdo;
    private static PDO $pdo;

    private static function testDatenbank(): string {
        return \Tests\Support\WegwerfDatenbank::name('rechte_seed');
    }

    public static function setUpBeforeClass(): void {
        if (!defined('DB_HOST')) {
            self::markTestSkipped('Keine Test-Datenbank konfiguriert (DB_HOST fehlt) - siehe tests/bootstrap.php.');
        }

        $adminDsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4";
        self::$adminPdo = new PDO($adminDsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        self::$adminPdo->exec("DROP DATABASE IF EXISTS `" . self::testDatenbank() . "`");
        self::$adminPdo->exec("CREATE DATABASE `" . self::testDatenbank() . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        self::$pdo = new PDO(
            "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . self::testDatenbank() . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }

    public static function tearDownAfterClass(): void {
        if (isset(self::$adminPdo)) {
            self::$adminPdo->exec("DROP DATABASE IF EXISTS `" . self::testDatenbank() . "`");
        }
    }

    protected function setUp(): void {
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $tabelle) {
            self::$pdo->exec("DROP TABLE IF EXISTS `{$tabelle}`");
        }
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    // ------------------------------------------------------------------
    // M22: Gast-Seed persons.view (#293)
    // ------------------------------------------------------------------

    /**
     * (a) Realistischer v0.7-Stand: schema_version 9, persons.is_breeder
     * vorhanden, der Admin hat der Gast-Gruppe persons.view entzogen. Nach
     * dem Update darf die Gast-Gruppe die Kontaktseiten NICHT sehen.
     */
    public function testEntzogenesGastrechtPersonsViewBleibtBeimUpgradeAusV07Entzogen(): void {
        $this->altbestand(9, [['horses', 'view'], ['breeding_stations', 'view']], $this->volleEditorAltrechte(), true);

        $steps = SchemaMigrator::run(self::$pdo);

        $this->assertGastOhneKontaktseiteNachUpgrade($steps);
    }

    /** (a2) Nur das Versionssignal: Stand 9, aber ohne is_breeder. */
    public function testEntzogenesGastrechtBleibtEntzogenAlleinUeberDieSchemaVersion(): void {
        $this->altbestand(9, [['horses', 'view'], ['breeding_stations', 'view']], $this->volleEditorAltrechte(), false);

        $steps = SchemaMigrator::run(self::$pdo);

        $this->assertGastOhneKontaktseiteNachUpgrade($steps);
    }

    /**
     * (a3) Nur das strukturelle Signal: Die schema_version-Zeile fehlt (Restore
     * bzw. Settings-Import ohne Stand), persons.is_breeder ist aber da - #293
     * lief also schon.
     */
    public function testEntzogenesGastrechtBleibtEntzogenAlleinUeberIsBreeder(): void {
        $this->altbestand(null, [['horses', 'view'], ['breeding_stations', 'view']], $this->volleEditorAltrechte(), true);

        $steps = SchemaMigrator::run(self::$pdo);

        $this->assertGastOhneKontaktseiteNachUpgrade($steps);
    }

    /**
     * (b) Gegenprobe: Ein Stand vor v0.6.0 (Version 5 bzw. ganz ohne Stand,
     * jeweils ohne is_breeder) hat den Seed nie erhalten und bekommt ihn -
     * einmal. Über die Schnittmenge in 31e wird daraus contacts.view.
     *
     * (c) Danach entzieht der Admin contacts.view, und ein erzwungener
     * Voll-Lauf bringt es nicht zurück.
     */
    public function testAltbestandVor06ErhaeltDieOeffentlicheKontaktseite(): void {
        foreach ([5, null] as $stand) {
            $this->setUp();
            $this->altbestand($stand, [['horses', 'view'], ['breeding_stations', 'view']], $this->volleEditorAltrechte(), false);

            $steps = SchemaMigrator::run(self::$pdo);

            $this->assertContains(self::MELDUNG_GASTRECHT, $steps, "Stand {$stand}");
            $this->assertContains(['contacts', 'view'], $this->rechte('public'), "Stand {$stand}");
            $this->assertMarkerGesetzt('migration_293_gastrecht_personenseite');

            // (c) Entzug überdauert den nächsten Voll-Lauf.
            self::$pdo->exec(
                "DELETE gp FROM group_permissions gp JOIN `groups` g ON g.id = gp.group_id
                 WHERE g.slug = 'public' AND gp.module = 'contacts' AND gp.action = 'view'"
            );
            $this->setzeSchemaVersion(SchemaMigrator::SCHEMA_VERSION - 1);
            $steps = SchemaMigrator::run(self::$pdo);

            $this->assertNotContains(['contacts', 'view'], $this->rechte('public'), "Stand {$stand}: Entzug muss bleiben");
            $this->assertNotContains(['persons', 'view'], $this->rechte('public'));
            $this->assertNotContains(self::MELDUNG_GASTRECHT, $steps);
        }
    }

    // ------------------------------------------------------------------
    // N18: Standardrechte-Seed (#66)
    // ------------------------------------------------------------------

    /**
     * (d) Frisches schema.sql: Der Seed wird nur still markiert. Entzieht der
     * Admin danach ALLE Leserechte, bleibt der Katalog nach einem Update mit
     * Schemaänderung geschlossen.
     */
    public function testGeschlossenerKatalogBleibtNachSchemaAnhebungGeschlossen(): void {
        $this->frischesSchema();
        $this->assertSame(
            [sprintf('settings.schema_version auf %d gesetzt (vorher 0)', SchemaMigrator::SCHEMA_VERSION)],
            SchemaMigrator::run(self::$pdo)
        );
        $this->assertMarkerGesetzt('migration_66_standardrechte_seed');

        $this->katalogSchliessen();
        $this->setzeSchemaVersion(SchemaMigrator::SCHEMA_VERSION - 1);
        $steps = SchemaMigrator::run(self::$pdo);

        $this->assertKatalogGeschlossen($steps);
    }

    /**
     * (e) Bestand von VOR dem Fix: Der Marker fehlt noch. Der Lauf, der ihn
     * einführt, darf den geschlossenen Katalog nicht öffnen, sondern nur
     * markieren.
     */
    public function testBestandVorDemFixWirdNurMarkiert(): void {
        $this->frischesSchema();
        SchemaMigrator::run(self::$pdo);

        $this->katalogSchliessen();
        self::$pdo->exec("DELETE FROM settings WHERE setting_key = 'migration_66_standardrechte_seed'");
        $this->setzeSchemaVersion(21);
        $steps = SchemaMigrator::run(self::$pdo);

        $this->assertKatalogGeschlossen($steps);
    }

    /**
     * (g) Ohne Marker UND ohne schema_version-Zeile (Restore bzw.
     * Settings-Import ohne Stand): Stand 0 allein ist kein Altstand-Beweis -
     * das Kontaktschema steht, der Seed lief also längst.
     */
    public function testGeschlossenerKatalogOhneSchemaVersionUndOhneMarker(): void {
        $this->frischesSchema();
        SchemaMigrator::run(self::$pdo);

        $this->katalogSchliessen();
        self::$pdo->exec(
            "DELETE FROM settings WHERE setting_key IN ('migration_66_standardrechte_seed', 'schema_version')"
        );
        $steps = SchemaMigrator::run(self::$pdo);

        $this->assertContains(sprintf('settings.schema_version auf %d gesetzt (vorher 0)', SchemaMigrator::SCHEMA_VERSION), $steps);
        $this->assertKatalogGeschlossen($steps);
    }

    /**
     * (f) Altstand v0.2.0-beta.1: group_permissions gibt es schon (#66), aber
     * noch ohne Leserecht und ohne schema_version. Der Seed läuft einmal -
     * danach nie wieder, auch wenn der Admin der Gast-Gruppe alles entzieht.
     */
    public function testAltstandOhneLeserechtWirdEinmalGeseedet(): void {
        $this->altbestand(null, [], [['horses', 'create'], ['horses', 'edit'], ['horses', 'delete']], false);

        $steps = SchemaMigrator::run(self::$pdo);

        $this->assertContains(self::MELDUNG_STANDARDRECHTE, $steps);
        $editor = $this->rechte('editor');
        $this->assertContains(['horses', 'view'], $editor);
        foreach (self::AKTIONEN as $aktion) {
            $this->assertContains(['contacts', $aktion], $editor, "editor contacts.{$aktion} fehlt");
        }
        $public = $this->rechte('public');
        $this->assertContains(['horses', 'view'], $public);
        $this->assertContains(['contacts', 'view'], $public);
        $this->assertMarkerGesetzt('migration_66_standardrechte_seed');

        self::$pdo->exec(
            "DELETE gp FROM group_permissions gp JOIN `groups` g ON g.id = gp.group_id
             WHERE g.slug = 'public' AND gp.action = 'view'"
        );
        $this->setzeSchemaVersion(SchemaMigrator::SCHEMA_VERSION - 1);
        $steps = SchemaMigrator::run(self::$pdo);

        $this->assertSame([], $this->rechte('public'), 'Die Gast-Gruppe muss ohne Leserecht bleiben');
        $this->assertNotContains(self::MELDUNG_STANDARDRECHTE, $steps);
    }

    // ------------------------------------------------------------------
    // Audit M10/M13: "Intern lesen" für bisherige view-Gruppen
    // ------------------------------------------------------------------

    /**
     * Bestand von Schema 25: Jede Gruppe mit horses.view/contacts.view
     * bekommt einmalig das passende `internal` - außer admin, public, der
     * Standardgruppe der Selbstregistrierung und der Zielgruppe von
     * mitglieder-konten. Danach bleibt ein Entzug entzogen.
     */
    public function testInternLesenWirdBestandsgruppenEinmalVergeben(): void {
        $this->frischesSchema();
        SchemaMigrator::run(self::$pdo);
        $this->assertMarkerGesetzt('migration_rechte_intern_lesen');

        // Stand vor der Trennung nachbauen.
        self::$pdo->exec("DELETE FROM settings WHERE setting_key = 'migration_rechte_intern_lesen'");
        self::$pdo->exec("DELETE FROM group_permissions WHERE action = 'internal'");
        $mitglieder = $this->gruppe('mitglieder', [['horses', 'view'], ['contacts', 'view']]);
        $registrierung = $this->gruppe('registrierung', [['contacts', 'view']]);
        $vereinskonten = $this->gruppe('vereinskonten', [['horses', 'view']]);
        $this->gruppe('ohne-lesen', [['horses', 'create']]);
        $this->einstellungSetzen('registration_default_group', (string)$registrierung);
        $this->einstellungSetzen('plugin_mitglieder_konten_gruppe', (string)$vereinskonten);
        $this->setzeSchemaVersion(25);

        $steps = SchemaMigrator::run(self::$pdo);

        $this->assertContains(['horses', 'internal'], $this->rechte('mitglieder'));
        $this->assertContains(['contacts', 'internal'], $this->rechte('mitglieder'));
        $this->assertContains(['horses', 'internal'], $this->rechte('editor'));
        $this->assertContains(['contacts', 'internal'], $this->rechte('editor'));
        foreach (['registrierung', 'vereinskonten', 'public', 'admin', 'ohne-lesen'] as $slug) {
            foreach ($this->rechte($slug) as [, $aktion]) {
                $this->assertNotSame('internal', $aktion, "{$slug} darf kein internal bekommen");
            }
        }
        $this->assertMarkerGesetzt('migration_rechte_intern_lesen');
        $protokoll = implode("\n", $steps);
        $this->assertStringContainsString('4 Recht(e) "Intern lesen"', $protokoll);
        $this->assertStringContainsString('Registrierung', $protokoll);
        $this->assertStringContainsString('Vereinskonten', $protokoll);

        // Entzug bleibt entzogen.
        self::$pdo->prepare("DELETE FROM group_permissions WHERE group_id = ? AND action = 'internal'")->execute([$mitglieder]);
        $this->setzeSchemaVersion(25);
        $steps = SchemaMigrator::run(self::$pdo);

        $this->assertNotContains(['horses', 'internal'], $this->rechte('mitglieder'));
        $this->assertNotContains(['contacts', 'internal'], $this->rechte('mitglieder'));
        $this->assertStringNotContainsString('Intern lesen', implode("\n", $steps));
    }

    /**
     * Stand 0 mit Kontaktschema - Werksreset (settings geleert,
     * group_permissions bleibt) oder Restore ohne Stand: nur vermerken. Ein
     * Admin könnte "Intern lesen" bewusst entzogen haben.
     */
    public function testInternLesenNachWerksresetNurVermerkt(): void {
        $this->frischesSchema();
        SchemaMigrator::run(self::$pdo);

        $this->gruppe('mitglieder', [['horses', 'view'], ['contacts', 'view']]);
        self::$pdo->exec("DELETE FROM settings");

        $steps = SchemaMigrator::run(self::$pdo);

        $this->assertNotContains(['horses', 'internal'], $this->rechte('mitglieder'));
        $this->assertStringNotContainsString('Intern lesen', implode("\n", $steps));
        $this->assertMarkerGesetzt('migration_rechte_intern_lesen');
    }

    // ------------------------------------------------------------------
    // Hilfen
    // ------------------------------------------------------------------

    private function einstellungSetzen(string $key, string $wert): void {
        self::$pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        )->execute([$key, $wert]);
    }

    /** @param list<array{0:string,1:string}> $rechte */
    private function gruppe(string $slug, array $rechte): int {
        self::$pdo->prepare("INSERT INTO `groups` (slug, name) VALUES (?, ?)")->execute([$slug, ucfirst($slug)]);
        $id = (int)self::$pdo->lastInsertId();
        $insert = self::$pdo->prepare("INSERT INTO group_permissions (group_id, module, action) VALUES (?, ?, ?)");
        foreach ($rechte as [$modul, $aktion]) {
            $insert->execute([$id, $modul, $aktion]);
        }
        return $id;
    }

    /** @param string[] $steps */
    private function assertGastOhneKontaktseiteNachUpgrade(array $steps): void {
        $public = $this->rechte('public');
        $this->assertNotContains(['contacts', 'view'], $public, 'Entzogenes persons.view darf nicht als contacts.view zurückkommen');
        $this->assertContains(['horses', 'view'], $public);

        $editor = $this->rechte('editor');
        foreach (self::AKTIONEN as $aktion) {
            $this->assertContains(['contacts', $aktion], $editor, "editor contacts.{$aktion} fehlt");
        }

        $archiv = json_decode((string)$this->setting('migration_336_rechte_vorher'), true);
        $this->assertIsArray($archiv, 'Schritt 31e muss den Vorzustand archivieren');
        $publicId = (int)self::$pdo->query("SELECT id FROM `groups` WHERE slug = 'public'")->fetchColumn();
        foreach ($archiv as $zeile) {
            $this->assertFalse(
                (int)$zeile['group_id'] === $publicId && $zeile['module'] === 'persons' && $zeile['action'] === 'view',
                'Das Rechte-Archiv für rollback-336 darf kein wieder eingesetztes persons.view enthalten'
            );
        }

        foreach ($steps as $step) {
            $this->assertStringNotContainsString('persons.view', $step);
            $this->assertNotSame(self::MELDUNG_STANDARDRECHTE, $step);
        }
        $this->assertMarkerGesetzt('migration_293_gastrecht_personenseite');
        $this->assertMarkerGesetzt('migration_66_standardrechte_seed');
    }

    /** @param string[] $steps */
    private function assertKatalogGeschlossen(array $steps): void {
        $this->assertSame(
            0,
            (int)self::$pdo->query("SELECT COUNT(*) FROM group_permissions WHERE action = 'view'")->fetchColumn(),
            'Ein bewusst geschlossener Katalog darf durch ein Update nicht wieder geöffnet werden'
        );
        $this->assertNotContains(['horses', 'view'], $this->rechte('public'));
        foreach (self::AKTIONEN as $aktion) {
            $this->assertNotContains(['contacts', $aktion], $this->rechte('editor'));
        }
        $this->assertNotContains(self::MELDUNG_STANDARDRECHTE, $steps);
        $this->assertFalse($this->setting('migration_336_rechte_vorher'), '31e darf auf einer Neuinstallation nichts archivieren');
        $this->assertMarkerGesetzt('migration_66_standardrechte_seed');
    }

    private function assertMarkerGesetzt(string $key): void {
        $this->assertNotFalse($this->setting($key), "Marker {$key} fehlt");
    }

    /** Alle Leserechte weg, und die Editor-Gruppe verliert contacts.* ganz. */
    private function katalogSchliessen(): void {
        self::$pdo->exec("DELETE FROM group_permissions WHERE action = 'view'");
        self::$pdo->exec(
            "DELETE gp FROM group_permissions gp JOIN `groups` g ON g.id = gp.group_id
             WHERE g.slug = 'editor' AND gp.module = 'contacts'"
        );
    }

    private function frischesSchema(): void {
        try {
            self::$pdo->exec(file_get_contents(__DIR__ . '/../../database/schema.sql'));
        } catch (PDOException $e) {
            // Ignorieren, analog zu SetupController::provision()
        }
        $this->assertSame(1, self::$pdo->query("SHOW TABLES LIKE 'contacts'")->rowCount(), 'schema.sql-Import unvollständig');
    }

    private function setzeSchemaVersion(int $version): void {
        self::$pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES ('schema_version', ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        )->execute([(string)$version]);
    }

    private function setting(string $key): string|false {
        $stmt = self::$pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        return $stmt->fetchColumn();
    }

    /** @return list<array{0:string,1:string}> [module, action], sortiert */
    private function rechte(string $gruppe): array {
        $stmt = self::$pdo->prepare(
            "SELECT gp.module, gp.action FROM group_permissions gp JOIN `groups` g ON g.id = gp.group_id
             WHERE g.slug = ? ORDER BY gp.module, gp.action"
        );
        $stmt->execute([$gruppe]);
        return array_map(fn($r) => [$r['module'], $r['action']], $stmt->fetchAll());
    }

    /** @return list<array{0:string,1:string}> */
    private function volleEditorAltrechte(): array {
        $rechte = [];
        foreach (['horses', 'persons', 'breeding_stations'] as $modul) {
            foreach (self::AKTIONEN as $aktion) {
                $rechte[] = [$modul, $aktion];
            }
        }
        return $rechte;
    }

    /**
     * Reduziertes Altschema wie in SchemaMigratorTest::
     * testRunMigratesLegacySchemaAndReportsSteps, dazu das Gruppensystem (#66)
     * mit den drei eingebauten Gruppen und den übergebenen Rechten.
     *
     * @param int|null $schemaVersion null = keine schema_version-Zeile
     * @param list<array{0:string,1:string}> $publicRechte
     * @param list<array{0:string,1:string}> $editorRechte
     */
    private function altbestand(?int $schemaVersion, array $publicRechte, array $editorRechte, bool $mitIsBreeder): void {
        self::$pdo->exec("
            CREATE TABLE `settings` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `setting_key` VARCHAR(50) NOT NULL UNIQUE,
                `setting_value` TEXT,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        if ($schemaVersion !== null) {
            $this->setzeSchemaVersion($schemaVersion);
        }
        self::$pdo->exec("
            CREATE TABLE `users` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `username` VARCHAR(50) NOT NULL UNIQUE,
                `email` VARCHAR(100) NOT NULL UNIQUE,
                `password_hash` VARCHAR(255) NOT NULL,
                `role` ENUM('admin', 'editor') DEFAULT 'editor',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $isBreeder = $mitIsBreeder ? '`is_breeder` TINYINT(1) NOT NULL DEFAULT 0,' : '';
        self::$pdo->exec("
            CREATE TABLE `persons` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL,
                `contact_info` TEXT,
                {$isBreeder}
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        self::$pdo->exec("INSERT INTO `persons` (`name`) VALUES ('Legacy Halter')");
        self::$pdo->exec("
            CREATE TABLE `breeding_stations` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(150) NOT NULL,
                `contact_person` VARCHAR(100) NULL,
                `address` TEXT NULL,
                `phone` VARCHAR(50) NULL,
                `email` VARCHAR(100) NULL,
                `website` VARCHAR(255) NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        self::$pdo->exec("INSERT INTO `breeding_stations` (`name`) VALUES ('Legacy Gestuet')");
        self::$pdo->exec("
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

        // Gruppensystem (#66) mit derselben DDL wie im Migrator.
        self::$pdo->exec("
            CREATE TABLE `groups` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `slug` VARCHAR(50) NOT NULL UNIQUE,
                `name` VARCHAR(100) NOT NULL,
                `description` VARCHAR(255) NULL,
                `is_builtin` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        self::$pdo->exec("
            INSERT INTO `groups` (`slug`, `name`, `is_builtin`) VALUES
            ('admin', 'Administrator', 1), ('editor', 'Editor', 1), ('public', 'Gast (Öffentlich)', 1)
        ");
        self::$pdo->exec("
            CREATE TABLE `user_groups` (
                `user_id` INT NOT NULL,
                `group_id` INT NOT NULL,
                PRIMARY KEY (`user_id`, `group_id`),
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
                FOREIGN KEY (`group_id`) REFERENCES `groups`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        self::$pdo->exec("
            CREATE TABLE `group_permissions` (
                `group_id` INT NOT NULL,
                `module` VARCHAR(50) NOT NULL,
                `action` VARCHAR(50) NOT NULL,
                PRIMARY KEY (`group_id`, `module`, `action`),
                FOREIGN KEY (`group_id`) REFERENCES `groups`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $insert = self::$pdo->prepare(
            "INSERT INTO group_permissions (group_id, module, action)
             SELECT id, ?, ? FROM `groups` WHERE slug = ?"
        );
        foreach (['public' => $publicRechte, 'editor' => $editorRechte] as $gruppe => $rechte) {
            foreach ($rechte as [$modul, $aktion]) {
                $insert->execute([$modul, $aktion, $gruppe]);
            }
        }
    }
}
