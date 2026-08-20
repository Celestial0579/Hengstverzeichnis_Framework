<?php
// tests/Integration/SchemaMigratorTest.php

namespace Tests\Integration;

use App\Service\SchemaMigrator;
use PDO;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

/**
 * Prüft App\Service\SchemaMigrator (#230) als EXPLIZIT aufrufbaren
 * Schema-Migrationslauf - das Szenario ist der versionsübergreifende
 * Datenimport: Ein Restore-Werkzeug spielt den Dump einer älteren
 * Kern-Version ein und muss das Schema danach ohne shell_exec auf den
 * aktuellen Stand heben können.
 *
 * Bewusst OHNE App\Database: Der Singleton dort führt die Migration beim
 * Verbindungsaufbau bereits implizit aus (und tests/Integration/DatabaseTest.php
 * verlangt, der erste getInstance()-Aufrufer im PHPUnit-Prozess zu sein).
 * Diese Klasse arbeitet deshalb ausschließlich mit eigenen PDO-Verbindungen
 * gegen eine EIGENE Wegwerf-Datenbank - so ist garantiert, dass jeder
 * geprüfte Effekt vom expliziten SchemaMigrator::run()-Aufruf stammt und
 * nicht von einem impliziten Verbindungsaufbau nebenher.
 */
class SchemaMigratorTest extends TestCase {

    /**
     * Name der Wegwerf-Datenbank. Ueber HV_TEST_DB_PREFIX umstellbar, damit
     * die Suite auch mit einem Datenbank-Benutzer laeuft, der nur auf einem
     * eigenen Namensraum Rechte hat - siehe Tests\Support\WegwerfDatenbank.
     */
    private static function testDatenbank(): string {
        return \Tests\Support\WegwerfDatenbank::name('schema_migrator');
    }

    private static PDO $adminPdo;
    private static PDO $pdo;

    public static function setUpBeforeClass(): void {
        if (!defined('DB_HOST')) {
            self::markTestSkipped('Keine Test-Datenbank konfiguriert (DB_HOST fehlt) - siehe tests/bootstrap.php.');
        }

        // Eigene Wegwerf-Datenbank, damit die Läufe hier den Zustand der
        // regulären Test-DB (DB_NAME) anderer Integrationstests nicht berühren.
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

    /**
     * Restore-Entscheidungsgrundlage (#230): Auf einer leeren Datenbank (kein
     * settings, wie mitten in einem Restore vor dem Import) muss
     * storedVersion() 0 melden statt zu werfen - nur so kann ein
     * Import-Werkzeug gefahrlos prüfen, ob Migrationen ausstehen.
     */
    public function testStoredVersionIsZeroOnEmptyDatabase(): void {
        $this->assertSame(0, SchemaMigrator::storedVersion(self::$pdo));
        $this->assertFalse(SchemaMigrator::isUpToDate(self::$pdo));
    }

    /**
     * Kernszenario: Der "eingespielte Dump einer älteren Kern-Version" wird
     * durch dasselbe reduzierte Alt-Schema simuliert, das auch
     * DatabaseTest.php benutzt (inkl. der längst entfernten users.role-Spalte,
     * dem alten status-Enum mit 'deceased' und birth_year als YEAR). Ein
     * expliziter run() muss das Schema vollständig heben, die tatsächlich
     * durchgeführten Schritte melden und den Stand persistieren.
     */
    #[Depends('testStoredVersionIsZeroOnEmptyDatabase')]
    public function testRunMigratesLegacySchemaAndReportsSteps(): void {
        self::$pdo->exec("
            CREATE TABLE `settings` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `setting_key` VARCHAR(50) NOT NULL UNIQUE,
                `setting_value` TEXT,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
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
        self::$pdo->exec("
            INSERT INTO `users` (`username`, `email`, `password_hash`, `role`) VALUES
            ('legacy-admin', 'legacy-admin@example.com', 'x', 'admin'),
            ('legacy-editor', 'legacy-editor@example.com', 'x', 'editor')
        ");
        self::$pdo->exec("
            CREATE TABLE `persons` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL,
                `contact_info` TEXT,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        // Zwei Bestandspersonen, damit die Kontaktlisten-Uebernahme (#336)
        // nicht nur "hat nichts kaputtgemacht" zeigen kann, sondern
        // nachweislich Daten hinuebertraegt.
        self::$pdo->exec("
            INSERT INTO `persons` (`name`, `contact_info`) VALUES
            ('Legacy Zuechter', 'interne Notiz'),
            ('Legacy Halter', NULL)
        ");
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
        self::$pdo->exec("
            INSERT INTO `breeding_stations` (`name`, `contact_person`, `phone`) VALUES
            ('Legacy Gestuet', 'Frau Ohlsen', '0431-1')
        ");
        // foreign_ueln gehört mit ins Alt-Schema (#246): So wird der
        // Einmal-Backfill der horse_registrations-Migration sichtbar getestet -
        // die ' / '-Verkettung (teils ohne Leerzeichen, wie sie das
        // varchar(50)-Limit real hinterlassen hat) muss in Einzelzeilen
        // zerlegt werden.
        self::$pdo->exec("
            CREATE TABLE `horses` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL,
                `ueln` VARCHAR(50) UNIQUE,
                `foreign_ueln` VARCHAR(50) NULL DEFAULT NULL,
                `birth_year` YEAR NULL,
                `color` VARCHAR(50),
                `description` TEXT,
                `status` ENUM('active', 'inactive', 'deceased') DEFAULT 'active',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        self::$pdo->exec("
            INSERT INTO `horses` (`name`, `ueln`, `foreign_ueln`, `status`) VALUES
            ('Legacy Aktiv', NULL, NULL, 'active'),
            ('Legacy Verstorben', NULL, NULL, 'deceased'),
            ('Legacy Mehrfach', 'DK 007', 'NOR 111 / SWE 222 /NOR 111/ DK 007', 'active'),
            ('Legacy Einzel', NULL, 'FIN 444', 'active')
        ");

        $steps = SchemaMigrator::run(self::$pdo);

        // Die Schritt-Liste ist der Vertrag aus #230 (Import-Protokoll):
        // Stichproben über alle Schrittarten - neue Tabelle, neue Spalte,
        // Index, Einmal-Backfills, Typ-Umstellung und der Versionsstempel.
        $this->assertNotEmpty($steps);
        $this->assertContains('Tabelle audit_logs angelegt', $steps);
        $this->assertContains('Spalte users.totp_secret ergänzt', $steps);
        $this->assertContains('Index horses.idx_horses_color angelegt', $steps);
        $this->assertContains("Spalte horses.is_published ergänzt (Bestand mit status='active' als veröffentlicht übernommen)", $steps);
        $this->assertContains('Spalte horses.birth_year von YEAR auf SMALLINT UNSIGNED umgestellt', $steps);
        $this->assertContains("Status-Split: horses.status-Bestand 'deceased' nach is_deceased/death_year überführt, Enum bereinigt", $steps);
        $this->assertContains('Spalte users.role in user_groups-Mitgliedschaften überführt und entfernt', $steps);
        $this->assertContains('Tabelle horse_registrations angelegt', $steps);
        $this->assertContains('horse_registrations-Backfill: foreign_ueln von 2 Pferd(en) in Einzelnummern zerlegt', $steps);
        $this->assertContains(
            sprintf('settings.schema_version auf %d gesetzt (vorher 0)', SchemaMigrator::SCHEMA_VERSION),
            $steps
        );

        // Und das Schema ist wirklich gehoben (Stichproben; die vollständige
        // Spalten-für-Spalte-Prüfung des Migrationsinhalts leistet weiterhin
        // DatabaseTest.php über den impliziten Weg beim Verbindungsaufbau).
        $this->assertSame(1, self::$pdo->query("SHOW TABLES LIKE 'api_keys'")->rowCount());
        $this->assertSame(1, self::$pdo->query("SHOW COLUMNS FROM `horses` LIKE 'is_deceased'")->rowCount());
        $this->assertSame(0, self::$pdo->query("SHOW COLUMNS FROM `users` LIKE 'role'")->rowCount());

        // Reihenfolge-Regression (#309), jetzt an ihrem neuen Ort geprüft.
        //
        // Der ursprüngliche Fehler: persons.contact_public wurde mit
        // `AFTER is_breeder` ergänzt, is_breeder aber erst einen Schritt
        // SPÄTER angelegt - auf jeder Installation ohne is_breeder scheiterte
        // das ALTER, der Fehler wurde verschluckt und schema_version trotzdem
        // hochgesetzt.
        //
        // Seit #336 gibt es `persons` nach der Migration nicht mehr; die
        // Spalten stehen an `contacts`. Die Zusicherung bleibt aber dieselbe
        // und wird sogar SCHÄRFER: Wenn Schritt 30 (contact_public/is_breeder
        // an persons) heute stillschweigend scheitern würde, kämen die Spalten
        // nicht nur nicht an - Schritt 31 kopierte dann eine Tabelle ohne sie,
        // und der Kopierschritt selbst bräche ab. Geprüft wird deshalb, was am
        // Ende zählt: dass die Spalten an contacts stehen UND dass der Bestand
        // tatsächlich hinübergewandert ist.
        foreach (['is_breeder', 'contact_public', 'contact_person', 'address', 'mobile'] as $spalte) {
            $this->assertSame(
                1,
                self::$pdo->query("SHOW COLUMNS FROM `contacts` LIKE '{$spalte}'")->rowCount(),
                "contacts.{$spalte} fehlt nach der Migration"
            );
        }

        // Kontaktlisten-Übernahme (#336): zwei Personen und eine Deckstation
        // gingen hinein, drei Kontakte müssen herauskommen - mit erhaltenen
        // Werten und einer vollständigen Zuordnungstabelle.
        $kontakte = self::$pdo->query(
            "SELECT name, contact_info, contact_person, phone FROM contacts ORDER BY id"
        )->fetchAll();
        $this->assertCount(3, $kontakte, 'Aus 2 Personen + 1 Deckstation müssen 3 Kontakte werden');
        $nachName = array_column($kontakte, null, 'name');
        $this->assertSame('interne Notiz', $nachName['Legacy Zuechter']['contact_info']);
        $this->assertSame('Frau Ohlsen', $nachName['Legacy Gestuet']['contact_person']);
        $this->assertSame('0431-1', $nachName['Legacy Gestuet']['phone']);

        $this->assertSame(
            3,
            (int)self::$pdo->query('SELECT COUNT(*) FROM contact_id_map')->fetchColumn(),
            'contact_id_map muss jede alte Kennung abbilden - sonst können Addons ihre Verweise nicht umrechnen'
        );
        $this->assertSame(
            0,
            (int)self::$pdo->query(
                "SELECT COUNT(*) FROM contact_id_map WHERE old_type = 'person' AND old_id <> contact_id"
            )->fetchColumn(),
            'Personen behalten ihre ID - sonst zeigen die Adressen aus Suchmaschinen ins Leere'
        );

        // Die Alttabellen sind stillgelegt, nicht gelöscht: Der Rückweg
        // (database/rollback-336.php) braucht sie.
        $this->assertSame(0, self::$pdo->query("SHOW TABLES LIKE 'persons'")->rowCount());
        $this->assertSame(1, self::$pdo->query("SHOW TABLES LIKE 'persons_pre_contacts'")->rowCount());
        $this->assertSame(1, self::$pdo->query("SHOW TABLES LIKE 'breeding_stations_pre_contacts'")->rowCount());

        // Der Einmal-Backfill hat den Bestand korrekt überführt.
        $rows = self::$pdo->query("SELECT name, status, is_published, is_deceased FROM horses ORDER BY id")->fetchAll();
        $byName = array_column($rows, null, 'name');
        $this->assertSame(1, (int)$byName['Legacy Aktiv']['is_published']);
        $this->assertSame(0, (int)$byName['Legacy Aktiv']['is_deceased']);
        $this->assertSame('inactive', $byName['Legacy Verstorben']['status']);
        $this->assertSame(1, (int)$byName['Legacy Verstorben']['is_deceased']);

        // Backfill der weiteren Lebensnummern (#246): Die ' / '-Verkettung ist
        // in Einzelzeilen mit stabiler Reihenfolge zerlegt; Duplikate innerhalb
        // der Verkettung und die Primärnummer (ueln) werden NICHT übernommen,
        // und foreign_ueln selbst bleibt als Kompatibilitätsfeld unangetastet.
        $registrations = self::$pdo->query("
            SELECT h.name, r.registration_number, r.sort_order
            FROM horse_registrations r
            JOIN horses h ON h.id = r.horse_id
            ORDER BY h.name, r.sort_order
        ")->fetchAll();
        $this->assertSame([
            ['name' => 'Legacy Einzel', 'registration_number' => 'FIN 444', 'sort_order' => 0],
            ['name' => 'Legacy Mehrfach', 'registration_number' => 'NOR 111', 'sort_order' => 0],
            ['name' => 'Legacy Mehrfach', 'registration_number' => 'SWE 222', 'sort_order' => 1],
        ], array_map(fn($r) => ['name' => $r['name'], 'registration_number' => $r['registration_number'], 'sort_order' => (int)$r['sort_order']], $registrations));

        $foreignUeln = self::$pdo->query("SELECT foreign_ueln FROM horses WHERE name = 'Legacy Mehrfach'")->fetchColumn();
        $this->assertSame('NOR 111 / SWE 222 /NOR 111/ DK 007', $foreignUeln, 'foreign_ueln bleibt als Kompatibilitätsfeld unangetastet');

        // Stand persistiert -> ein Folgelauf hat entscheidbar nichts zu tun.
        $this->assertSame(SchemaMigrator::SCHEMA_VERSION, SchemaMigrator::storedVersion(self::$pdo));
        $this->assertTrue(SchemaMigrator::isUpToDate(self::$pdo));
    }

    /**
     * Idempotenz ist Pflicht (#230): Ein zweiter Lauf direkt nach dem ersten
     * meldet eine LEERE Schritt-Liste - er darf weder erneut migrieren noch
     * Einmal-Backfills (is_published, Rechte-Seed) wiederholen.
     */
    #[Depends('testRunMigratesLegacySchemaAndReportsSteps')]
    public function testSecondRunIsANoOp(): void {
        // Gegenprobe über einen bewusst veränderten Zustand: Eine nach der
        // Migration depublizierte Zeile darf ein Folgelauf nicht wieder
        // veröffentlichen (genau das würde ein wiederholter Backfill tun).
        self::$pdo->exec("UPDATE `horses` SET `is_published` = 0 WHERE `name` = 'Legacy Aktiv'");

        $this->assertSame([], SchemaMigrator::run(self::$pdo));

        $published = self::$pdo->query("SELECT is_published FROM horses WHERE name = 'Legacy Aktiv'")->fetchColumn();
        $this->assertSame(0, (int)$published);

        // Der horse_registrations-Backfill (#246) darf ebenfalls nicht erneut
        // laufen - sonst würde er die Zeilen aus dem weiterhin befüllten
        // foreign_ueln-Kompatibilitätsfeld duplizieren.
        $registrationCount = (int)self::$pdo->query("SELECT COUNT(*) FROM horse_registrations")->fetchColumn();
        $this->assertSame(3, $registrationCount, 'Ein Folgelauf darf den foreign_ueln-Backfill nicht wiederholen');

        // Gegenprobe über einen ERZWUNGENEN Voll-Lauf (Stand zurückgesetzt, wie
        // bei einem Update mit erhöhter SCHEMA_VERSION): Alle Migrationsschritte
        // laufen erneut - der Einmal-Backfill aber nicht, weil die Tabelle
        // bereits existiert (SHOW TABLES-Gate).
        self::$pdo->exec("UPDATE `settings` SET `setting_value` = '0' WHERE `setting_key` = 'schema_version'");
        $steps = SchemaMigrator::run(self::$pdo);
        $this->assertNotContains('Tabelle horse_registrations angelegt', $steps);
        $this->assertSame(
            3,
            (int)self::$pdo->query("SELECT COUNT(*) FROM horse_registrations")->fetchColumn(),
            'Ein erzwungener Voll-Lauf darf den foreign_ueln-Backfill nicht wiederholen'
        );
    }

    /**
     * Restore eines AKTUELLEN Dumps ohne persistierten Stand (z. B. Dump aus
     * einer Instanz, die database/schema.sql frisch importiert hatte): run()
     * findet strukturell nichts zu tun und persistiert nur den Stand - die
     * Schritt-Liste enthält dann genau den Versionsstempel. Schlägt dieser
     * Test mit zusätzlichen Schritten fehl, ist database/schema.sql gegenüber
     * den Migrationsschritten gedriftet (Verstoß gegen die Doppelpflege-Regel,
     * siehe docs/database.md).
     */
    #[Depends('testSecondRunIsANoOp')]
    public function testRunOnCurrentSchemaOnlyPersistsVersion(): void {
        self::$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        foreach (self::$pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            self::$pdo->exec("DROP TABLE IF EXISTS `$table`");
        }
        self::$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

        // Frischer Import des Erststand-Schemas als EIN Multi-Statement-exec()
        // (wie SetupController::provision() und tests/Integration/DumpAndRestoreTest.php).
        try {
            self::$pdo->exec(file_get_contents(__DIR__ . '/../../database/schema.sql'));
        } catch (\PDOException $e) {
            // Ignorieren, analog zu SetupController::provision()
        }
        $this->assertSame(1, self::$pdo->query("SHOW TABLES LIKE 'horses'")->rowCount(), 'schema.sql-Import unvollständig');

        $this->assertSame(0, SchemaMigrator::storedVersion(self::$pdo));

        $steps = SchemaMigrator::run(self::$pdo);

        $this->assertSame(
            [sprintf('settings.schema_version auf %d gesetzt (vorher 0)', SchemaMigrator::SCHEMA_VERSION)],
            $steps,
            'Auf einem frisch importierten database/schema.sql darf run() nur den Versionsstempel setzen - zusätzliche Schritte bedeuten Schema-Drift'
        );

        $this->assertSame([], SchemaMigrator::run(self::$pdo));
    }

    /**
     * Die Verschiebung der Pferdefotos aus dem Webroot (#366).
     *
     * Der Schritt ist der riskanteste des ganzen Migrators: Fotos gibt es nur
     * einmal. Deshalb kopiert er, vergleicht den Inhalt und löscht die Quelle
     * erst danach - ein rename(), das auf halbem Weg scheitert, verlöre die
     * Datei. Geprüft wird beides: dass verschoben wird, und dass ein zweiter
     * Lauf nichts mehr anfasst.
     */
    #[Depends('testRunOnCurrentSchemaOnlyPersistsVersion')]
    public function testHorsePhotosAreMovedOutOfTheWebroot(): void {
        $alt = sys_get_temp_dir() . '/' . uniqid('hengst_alt_');
        $neu = sys_get_temp_dir() . '/' . uniqid('hengst_neu_');
        mkdir($alt, 0777, true);

        // Ein Bild und zwei Dateien, die NICHT mitwandern dürfen: die
        // .htaccess sperrt den statischen Weg und bleibt liegen, die .txt ist
        // kein Bild.
        file_put_contents($alt . '/horse_1755000000_a1b2c3d4.jpg', 'BILDINHALT');
        file_put_contents($alt . '/.htaccess', 'Require all denied');
        file_put_contents($alt . '/notiz.txt', 'kein Bild');

        \App\Helper\HorseImagePath::overrideForTests($neu, $alt);
        try {
            // Marker entfernen und Version zurücksetzen, damit der Schritt läuft.
            self::$pdo->exec("DELETE FROM `settings` WHERE `setting_key` = 'migration_366_pferdefotos_aus_dem_webroot'");
            self::$pdo->exec("UPDATE `settings` SET `setting_value` = '0' WHERE `setting_key` = 'schema_version'");

            $schritte = SchemaMigrator::run(self::$pdo);

            $this->assertFileExists($neu . '/horse_1755000000_a1b2c3d4.jpg', 'Das Foto muss im neuen Verzeichnis liegen');
            $this->assertSame('BILDINHALT', file_get_contents($neu . '/horse_1755000000_a1b2c3d4.jpg'));
            $this->assertFileDoesNotExist($alt . '/horse_1755000000_a1b2c3d4.jpg', 'Im Webroot darf es nicht liegenbleiben');
            $this->assertFileExists($alt . '/.htaccess', 'Die Sperre bleibt liegen - sie schützt Instanzen, bei denen der Schritt nicht durchlief');
            $this->assertFileExists($alt . '/notiz.txt', 'Was kein Bild ist, wird nicht angefasst');

            $this->assertNotEmpty(
                array_filter($schritte, static fn(string $z) => str_contains($z, 'Pferdefotos (#366)')),
                'Eine tatsächliche Verschiebung wird gemeldet'
            );

            // Zweiter Lauf: Marker steht, es passiert nichts mehr.
            file_put_contents($alt . '/spaeter.jpg', 'DANACH');
            self::$pdo->exec("UPDATE `settings` SET `setting_value` = '0' WHERE `setting_key` = 'schema_version'");
            SchemaMigrator::run(self::$pdo);
            $this->assertFileExists($alt . '/spaeter.jpg', 'Der Schritt darf sich nicht wiederholen');
        } finally {
            \App\Helper\HorseImagePath::overrideForTests(null, null);
            foreach ([$alt, $neu] as $dir) {
                foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $datei) {
                    if (is_file($datei)) {
                        @unlink($datei);
                    }
                }
                @rmdir($dir);
            }
        }
    }
}
