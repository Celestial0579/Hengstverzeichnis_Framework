<?php
// tests/Integration/DatabaseNeueVerbindungTest.php

namespace Tests\Integration;

use App\Database;
use App\Service\SchemaMigrator;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Database::neueVerbindung() gegen eine echte Datenbank (#519): eine eigene
 * Verbindung mit den Einstellungen der Instanz, aber ohne deren
 * Schema-Migration.
 */
class DatabaseNeueVerbindungTest extends TestCase {

    public static function setUpBeforeClass(): void {
        if (!defined('DB_HOST')) {
            self::markTestSkipped('Keine Test-Datenbank konfiguriert (DB_HOST fehlt) - siehe tests/bootstrap.php.');
        }

        // Frisches Schema wie in den übrigen Integrationstests ...
        $setupPdo = new PDO(
            "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $setupPdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        foreach ($setupPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $setupPdo->exec("DROP TABLE IF EXISTS `$table`");
        }
        $setupPdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        try {
            $setupPdo->exec(file_get_contents(__DIR__ . '/../../database/schema.sql'));
        } catch (PDOException $e) {
            // Ignorieren, analog zu SetupController::provision()
        }

        // ... und EIN Verbindungsaufbau mit Migration: schema.sql stempelt
        // settings.schema_version nicht selbst, das tut erst der Migrator.
        // Ohne das Verwerfen bliebe die Instanz einer früheren Testklasse
        // stehen, und es liefe gar keine Migration.
        self::instanzVerwerfen();
        Database::getInstance();
    }

    public function testJederAufrufBautEineWeitereVerbindung(): void {
        $geteilt = Database::getInstance();
        $erste = Database::neueVerbindung();
        $zweite = Database::neueVerbindung();

        $this->assertNotSame($geteilt, $erste);
        $this->assertNotSame($erste, $zweite);
        $this->assertCount(
            3,
            array_unique([self::kennung($geteilt), self::kennung($erste), self::kennung($zweite)]),
            'Drei Objekte, drei Verbindungen auf dem Server.'
        );
        $this->assertSame($geteilt, Database::getInstance(), 'Die geteilte Verbindung bleibt, wie sie war.');
    }

    public function testSitzungszustandBleibtAufDerEigenenVerbindung(): void {
        $geteilt = Database::getInstance();
        $eigene = Database::neueVerbindung();

        // Was ein Import an seiner Verbindung verstellt.
        $eigene->exec('SET @hv_neue_verbindung = 5, SESSION foreign_key_checks = 0');

        $this->assertSame(5, (int)$eigene->query('SELECT @hv_neue_verbindung')->fetchColumn());
        $this->assertNull($geteilt->query('SELECT @hv_neue_verbindung')->fetchColumn());
        $this->assertSame(1, (int)$geteilt->query('SELECT @@SESSION.foreign_key_checks')->fetchColumn());
    }

    public function testDieVerbindungTraegtDieEinstellungenDerInstanz(): void {
        $geteilt = Database::getInstance();
        $eigene = Database::neueVerbindung();

        $this->assertSame(PDO::ERRMODE_EXCEPTION, $eigene->getAttribute(PDO::ATTR_ERRMODE));
        $this->assertSame(PDO::FETCH_ASSOC, $eigene->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE));
        $this->assertFalse((bool)$eigene->getAttribute(PDO::ATTR_EMULATE_PREPARES));

        // Die Sitzungs-Zeitzone ist der Versatz von PHP - nicht der
        // Serverstandard, den eine von Hand gebaute Verbindung hätte.
        $versatz = (new \DateTimeImmutable('now'))->format('P');
        $this->assertSame($versatz, self::zeitzone($eigene));
        $this->assertSame(self::zeitzone($geteilt), self::zeitzone($eigene));
    }

    public function testZusatzOptionenErgaenzenUndUeberschreiben(): void {
        // Gegenprobe: Ohne die Option nimmt eine Verbindung zwei Anweisungen in einem Aufruf an.
        $this->assertSame(0, Database::neueVerbindung()->exec('DO 1; DO 2'));

        $streng = Database::neueVerbindung([
            \Pdo\Mysql::ATTR_MULTI_STATEMENTS => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM,
        ]);

        $this->assertSame(PDO::FETCH_NUM, $streng->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE));
        try {
            $streng->exec('DO 1; DO 2');
            $this->fail('Mit ATTR_MULTI_STATEMENTS = false darf keine zweite Anweisung mitlaufen.');
        } catch (PDOException $e) {
            $this->assertStringContainsString('1064', $e->getMessage());
        }
    }

    public function testEsLaeuftKeineSchemaMigration(): void {
        $aktuell = SchemaMigrator::storedVersion(Database::getInstance());
        $this->assertSame(Database::SCHEMA_VERSION, $aktuell, 'Vorbedingung: Das Schema der Test-Datenbank ist aktuell.');

        // Ein Stand, bei dem die Instanz migrieren würde.
        self::standSetzen($aktuell - 1);
        try {
            $eigene = Database::neueVerbindung();
            $this->assertSame(
                $aktuell - 1,
                SchemaMigrator::storedVersion($eigene),
                'neueVerbindung() darf keine Migration anstoßen.'
            );

            // Gegenprobe: Derselbe Zustand über getInstance() - dort läuft
            // die Migration und stempelt den Stand.
            self::instanzVerwerfen();
            $this->assertSame($aktuell, SchemaMigrator::storedVersion(Database::getInstance()));
        } finally {
            self::standSetzen($aktuell);
        }
    }

    private static function kennung(PDO $db): int {
        return (int)$db->query('SELECT CONNECTION_ID()')->fetchColumn();
    }

    private static function zeitzone(PDO $db): string {
        return (string)$db->query('SELECT @@SESSION.time_zone')->fetchColumn();
    }

    /** Schreibt settings.schema_version an der Migration vorbei. */
    private static function standSetzen(int $version): void {
        Database::neueVerbindung()
            ->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'schema_version'")
            ->execute([(string)$version]);
    }

    /** Wie tests/Integration/DatabaseTest: Der nächste getInstance() verbindet neu. */
    private static function instanzVerwerfen(): void {
        (new \ReflectionProperty(Database::class, 'instance'))->setValue(null, null);
    }
}
