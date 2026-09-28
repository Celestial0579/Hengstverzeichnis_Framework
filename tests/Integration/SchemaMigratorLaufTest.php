<?php
// tests/Integration/SchemaMigratorLaufTest.php

namespace Tests\Integration;

use App\Helper\HorseImagePath;
use App\Service\SchemaMigrator;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AltbestandV072;
use Tests\Support\WegwerfDatenbank;

/**
 * Die Laufmechanik von SchemaMigrator::run() (Audit N75, N76, N77):
 * Migrationssperre, offene Datenschritte samt Status und Drosselung, der
 * einmalige Reset-Token-Schritt, die Altspalten von #336 und der
 * wiederaufsetzbare Indexumbau #412.
 *
 * Eigene Wegwerf-Datenbank, je Test frisch aus database/schema.sql bzw. der
 * v0.7.2-Fixture.
 */
class SchemaMigratorLaufTest extends TestCase {

    private const SPERRE = "CONCAT('hv_schema_', MD5(DATABASE()))";

    private static function db(): string {
        return WegwerfDatenbank::name('migrator_lauf');
    }

    /** @var string[] */
    private array $aufraeumen = [];

    protected function setUp(): void {
        if (!defined('DB_HOST')) {
            $this->markTestSkipped('Keine Test-Datenbank konfiguriert (DB_HOST fehlt) - siehe tests/bootstrap.php.');
        }
        SchemaMigrator::setzeSperreWartezeitFuerTests(-1);
        // #366/#339 nie in die echte Ablage des Checkouts greifen lassen.
        HorseImagePath::overrideForTests($this->tempPfad(), $this->tempPfad());
        HorseImagePath::overrideGalerieLegacyDirsForTests([]);
    }

    protected function tearDown(): void {
        SchemaMigrator::setzeSperreWartezeitFuerTests(-1);
        HorseImagePath::overrideForTests(null, null);
        HorseImagePath::overrideGalerieLegacyDirsForTests(null);
        foreach ($this->aufraeumen as $pfad) {
            self::loeschen($pfad);
        }
        if (defined('DB_HOST')) {
            AltbestandV072::entfernen(self::db());
        }
    }

    private static function loeschen(string $pfad): void {
        if (is_dir($pfad) && !is_link($pfad)) {
            foreach (glob($pfad . '/{,.}[!.]*', GLOB_BRACE) ?: [] as $kind) {
                self::loeschen($kind);
            }
            @rmdir($pfad);
        } elseif (file_exists($pfad)) {
            @unlink($pfad);
        }
    }

    private function tempPfad(): string {
        $pfad = sys_get_temp_dir() . '/' . uniqid('hengst_lauf_');
        $this->aufraeumen[] = $pfad;
        return $pfad;
    }

    /** Aktuelles Schema, einmal vollständig migriert, dann um eine Version zurück. */
    private function aktuellEineVersionZurueck(): PDO {
        $pdo = AltbestandV072::aktuell(self::db());
        SchemaMigrator::run($pdo);
        $this->assertSame(SchemaMigrator::SCHEMA_VERSION, SchemaMigrator::storedVersion($pdo));
        self::setzeVersion($pdo, SchemaMigrator::SCHEMA_VERSION - 1);
        return $pdo;
    }

    private static function setzeVersion(PDO $pdo, int $version): void {
        $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'schema_version'")->execute([(string)$version]);
    }

    private static function statusSetzen(PDO $pdo, string $zustand, int $vorSekunden, array $meldungen = ['x']): void {
        $pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES ('schema_migration_status', ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        )->execute([json_encode([
            'zustand' => $zustand,
            'von' => SchemaMigrator::SCHEMA_VERSION - 1,
            'ziel' => SchemaMigrator::SCHEMA_VERSION,
            'zeit' => gmdate('c', time() - $vorSekunden),
            'meldungen' => $meldungen,
        ])]);
    }

    // ---------------------------------------------------------------- N75

    public function testGehalteneSperreLaesstDenLaufAbbrechenOhneStatus(): void {
        $pdo = $this->aktuellEineVersionZurueck();
        $andere = AltbestandV072::verbinden(self::db());
        $this->assertSame(1, (int)$andere->query('SELECT GET_LOCK(' . self::SPERRE . ', 0)')->fetchColumn());

        SchemaMigrator::setzeSperreWartezeitFuerTests(1);
        try {
            SchemaMigrator::run($pdo);
            $this->fail('Bei gehaltener Sperre darf kein zweiter Lauf starten.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('läuft bereits', $e->getMessage());
        }
        $this->assertSame(SchemaMigrator::SCHEMA_VERSION - 1, SchemaMigrator::storedVersion($pdo));
        $this->assertNull(SchemaMigrator::status($pdo), 'Warten ist kein Fehlschlag');

        $andere->query('DO RELEASE_LOCK(' . self::SPERRE . ')');
        SchemaMigrator::run($pdo);
        $this->assertSame(SchemaMigrator::SCHEMA_VERSION, SchemaMigrator::storedVersion($pdo));
        $this->assertSame(0, (int)$pdo->query('SELECT IS_USED_LOCK(' . self::SPERRE . ') IS NOT NULL')->fetchColumn(),
            'Die Sperre wird nach dem Lauf freigegeben');
    }

    public function testWiederholungslaufWartetNichtAufDieSperre(): void {
        $pdo = $this->aktuellEineVersionZurueck();
        self::statusSetzen($pdo, 'offen', 3600);
        $andere = AltbestandV072::verbinden(self::db());
        $this->assertSame(1, (int)$andere->query('SELECT GET_LOCK(' . self::SPERRE . ', 0)')->fetchColumn());

        $start = microtime(true);
        $this->assertSame([], SchemaMigrator::run($pdo, true));
        $this->assertLessThan(1.0, microtime(true) - $start);
        $this->assertSame(SchemaMigrator::SCHEMA_VERSION - 1, SchemaMigrator::storedVersion($pdo));
        $andere->query('DO RELEASE_LOCK(' . self::SPERRE . ')');
    }

    /**
     * Wiedereintritt: migrate.php migriert mit eigener PDO, und AuditLogger
     * (Dublettenmeldung in 31b) baut über Database::getInstance() eine zweite
     * Verbindung auf dieselbe Datenbank auf. Deren impliziter Lauf wartete
     * ohne Schutz 30 s auf die Sperre, die der Prozess selbst hält.
     */
    public function testMigrateCliWartetNichtAufDieEigeneSperre(): void {
        $pdo = AltbestandV072::anlegen(self::db());
        $pdo->exec("INSERT INTO persons (id, name) VALUES (1, 'Hans Muster'), (2, 'hans muster')");

        $start = microtime(true);
        [$code, $ausgabe] = self::cli('database/migrate.php', [], self::db());
        $dauer = microtime(true) - $start;

        $this->assertSame(0, $code, $ausgabe);
        $this->assertStringContainsString('namensgleiche Kontaktpaare', $ausgabe);
        $this->assertLessThan(20.0, $dauer, 'Der verschachtelte Lauf darf nicht auf die eigene Sperre warten');
        $this->assertSame(SchemaMigrator::SCHEMA_VERSION, SchemaMigrator::storedVersion($pdo));
    }

    /**
     * @return array{0: int, 1: string}
     */
    public static function cli(string $skript, array $argumente, string $datenbank): array {
        $env = getenv();
        $env['DB_NAME'] = $datenbank;
        $env['DB_HOST'] = DB_HOST;
        $env['DB_PORT'] = (string)DB_PORT;
        $env['DB_USER'] = DB_USER;
        $env['DB_PASS'] = DB_PASS;
        $befehl = array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/' . $skript], $argumente);
        $proc = proc_open($befehl, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $env);
        $aus = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), (string)$aus];
    }

    // ---------------------------------------------------------------- N76

    public function testBlockierterFotoschrittHaeltDenStempelAuf(): void {
        $pdo = $this->aktuellEineVersionZurueck();
        $alt = $this->tempPfad();
        $ziel = $this->tempPfad();
        mkdir($alt);
        file_put_contents($alt . '/pferd.jpg', 'BILD');
        // Zielpfad ist eine DATEI - mkdir scheitert, auch unter root.
        file_put_contents($ziel, 'im Weg');
        HorseImagePath::overrideForTests($ziel, $alt);
        $pdo->exec("DELETE FROM settings WHERE setting_key = 'migration_366_pferdefotos_aus_dem_webroot'");

        $schritte = SchemaMigrator::run($pdo);

        $this->assertSame(SchemaMigrator::SCHEMA_VERSION - 1, SchemaMigrator::storedVersion($pdo));
        $this->assertNotEmpty(array_filter($schritte, static fn($z) => str_contains($z, 'bleibt auf')), implode("\n", $schritte));
        $status = SchemaMigrator::status($pdo);
        $this->assertSame('offen', $status['zustand'] ?? null);
        $this->assertStringContainsString('#366', implode(' ', $status['meldungen']));
        $this->assertNull(AltbestandV072::einstellung($pdo, 'migration_366_pferdefotos_aus_dem_webroot'));

        // Gedrosselt: Der implizite Weg versucht es vor Ablauf der Frist nicht.
        $this->assertSame([], SchemaMigrator::run($pdo, true));
        $this->assertFileExists($alt . '/pferd.jpg');

        // Ursache behoben - der nächste explizite Lauf holt es nach.
        unlink($ziel);
        SchemaMigrator::run($pdo);
        $this->assertFileExists($ziel . '/pferd.jpg');
        $this->assertFileDoesNotExist($alt . '/pferd.jpg');
        $this->assertSame(SchemaMigrator::SCHEMA_VERSION, SchemaMigrator::storedVersion($pdo));
        $this->assertNull(SchemaMigrator::status($pdo));
        $this->assertNotNull(AltbestandV072::einstellung($pdo, 'migration_366_pferdefotos_aus_dem_webroot'));
    }

    public function testAbgelaufeneDrosselungLaesstDenImplizitenWegWiederRan(): void {
        $pdo = $this->aktuellEineVersionZurueck();
        self::statusSetzen($pdo, 'fehler', SchemaMigrator::WIEDERHOLEN_NACH['fehler'] + 5);
        SchemaMigrator::run($pdo, true);
        $this->assertSame(SchemaMigrator::SCHEMA_VERSION, SchemaMigrator::storedVersion($pdo));
        $this->assertNull(SchemaMigrator::status($pdo));
    }

    public function testGescheiterteTabellenanlageHaeltDenStempelAuf(): void {
        $pdo = AltbestandV072::leer(self::db());
        $pdo->exec("CREATE TABLE `settings` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `setting_key` VARCHAR(50) NOT NULL UNIQUE,
            `setting_value` TEXT,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        SchemaMigrator::run($pdo);

        $this->assertSame(0, SchemaMigrator::storedVersion($pdo));
        $status = SchemaMigrator::status($pdo);
        $this->assertSame('offen', $status['zustand'] ?? null);
        $this->assertStringContainsString('tabelle:user_passkeys', implode(' ', $status['meldungen']));
    }

    public function testResetTokenWerdenNurAufAltbestaendenGeleert(): void {
        $pdo = $this->aktuellEineVersionZurueck();
        $pdo->exec("INSERT INTO password_resets (email, token, expires_at) VALUES ('a@example.com', REPEAT('a', 64), NOW() + INTERVAL 10 MINUTE)");
        $pdo->exec("DELETE FROM settings WHERE setting_key = 'migration_318_reset_token_klartext'");
        self::setzeVersion($pdo, 21);

        SchemaMigrator::run($pdo);
        $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM password_resets")->fetchColumn(),
            'Ab v0.7.1 liegen nur Abdrücke vor - gültige Reset-Links bleiben');
        $this->assertNotNull(AltbestandV072::einstellung($pdo, 'migration_318_reset_token_klartext'));

        // Ein zweiter Voll-Lauf leert nicht mehr (früher: bei jedem Lauf).
        self::setzeVersion($pdo, 21);
        SchemaMigrator::run($pdo);
        $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM password_resets")->fetchColumn());

        // Gegenprobe: unbekannter Stand zählt als Altbestand.
        $pdo->exec("DELETE FROM settings WHERE setting_key = 'migration_318_reset_token_klartext'");
        self::setzeVersion($pdo, 0);
        SchemaMigrator::run($pdo);
        $this->assertSame(0, (int)$pdo->query("SELECT COUNT(*) FROM password_resets")->fetchColumn());
    }

    public function testNichtEntfernbareAltspalteBleibtOffen(): void {
        $pdo = AltbestandV072::anlegen(self::db());
        $pdo->exec("INSERT INTO persons (id, name) VALUES (1, 'Anna')");
        $pdo->exec("INSERT INTO horses (id, name) VALUES (10, 'Hengst')");
        $pdo->exec("INSERT INTO horse_persons (horse_id, person_id, role) VALUES (10, 1, 'owner')");
        // Eine fremde Tabelle zeigt auf horse_persons.person_id -
        // $dropForeignKey löst nur Schlüssel AUF horse_persons, das DROP
        // COLUMN scheitert also.
        $pdo->exec("CREATE TABLE `hilfs_ref` (`pid` INT NULL, FOREIGN KEY (`pid`) REFERENCES `horse_persons`(`person_id`))
                    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        SchemaMigrator::run($pdo);

        $this->assertSame(9, SchemaMigrator::storedVersion($pdo));
        $status = SchemaMigrator::status($pdo);
        $this->assertSame('offen', $status['zustand'] ?? null);
        $this->assertStringContainsString('Altspalte', implode(' ', $status['meldungen']));
        $this->assertNull(AltbestandV072::einstellung($pdo, 'migration_336_altbestand_stilllegen'));
        $this->assertNotNull(AltbestandV072::einstellung($pdo, 'migration_336_horse_persons_umhaengen'));
    }

    // ---------------------------------------------------------------- N77

    /** @return array<string, array{0: string[]}> DDL, die den Abbruchzustand herstellt */
    public static function abbruchzustaende(): array {
        $deckend = "CREATE INDEX `idx_horse_persons_contact_deckend` ON `horse_persons` (`contact_id`, `horse_id`)";
        $zielWeg = "DROP INDEX `idx_horse_persons_contact` ON `horse_persons`";
        $zielSchmal = "CREATE INDEX `idx_horse_persons_contact` ON `horse_persons` (`contact_id`)";
        $deckendWeg = "DROP INDEX `idx_horse_persons_contact_deckend` ON `horse_persons`";
        return [
            'Ziel einspaltig, _deckend vorhanden' => [[$deckend, $zielWeg, $zielSchmal]],
            'nur _deckend' => [[$deckend, $zielWeg]],
            'Ziel einspaltig, kein _deckend' => [[$deckend, $zielWeg, $zielSchmal, $deckendWeg]],
            'Ziel zweispaltig, _deckend übrig' => [[$deckend]],
        ];
    }

    /** @param string[] $ddl */
    #[DataProvider('abbruchzustaende')]
    public function testIndexumbauSetztAusJedemZwischenzustandFort(array $ddl): void {
        $pdo = $this->aktuellEineVersionZurueck();
        foreach ($ddl as $sql) {
            $pdo->exec($sql);
        }
        $pdo->exec("DELETE FROM settings WHERE setting_key = 'migration_412_horse_persons_contact_deckend'");

        SchemaMigrator::run($pdo);

        $this->assertSame(SchemaMigrator::SCHEMA_VERSION, SchemaMigrator::storedVersion($pdo));
        $spalten = $pdo->query("SHOW INDEX FROM `horse_persons` WHERE Key_name = 'idx_horse_persons_contact'")->fetchAll();
        usort($spalten, static fn($a, $b) => (int)$a['Seq_in_index'] <=> (int)$b['Seq_in_index']);
        $this->assertSame(['contact_id', 'horse_id'], array_column($spalten, 'Column_name'));
        $this->assertSame(0, $pdo->query("SHOW INDEX FROM `horse_persons` WHERE Key_name = 'idx_horse_persons_contact_deckend'")->rowCount());

        self::setzeVersion($pdo, SchemaMigrator::SCHEMA_VERSION - 1);
        $pdo->exec("DELETE FROM settings WHERE setting_key = 'migration_412_horse_persons_contact_deckend'");
        $schritte = SchemaMigrator::run($pdo);
        $this->assertEmpty(array_filter($schritte, static fn($z) => str_contains($z, '#412')));
    }
}
