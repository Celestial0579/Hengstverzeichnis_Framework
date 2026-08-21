<?php
// tests/Integration/ApiKeyLifecycleTest.php

namespace Tests\Integration;

use App\Database;
use App\Security\ApiKey;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Lebenszyklus eines API-Schlüssels gegen eine ECHTE MariaDB: Kopplung an
 * `users.session_version` (#217) und das Pflicht-Ablaufdatum (#340).
 *
 * WARUM DIESE TESTS NICHT IN DER UNIT-SUITE STEHEN. tests/Unit/Security/
 * ApiKeyTest.php spielt für seine Fälle eine In-Memory-SQLite in den
 * Database-Singleton. Das trägt für reine Logik - aber nicht für
 * `ApiKey::authenticate()`:
 *
 * - Die Abfrage benutzt `NOW()`. SQLite kennt die Funktion nicht; die Abfrage
 *   würde werfen, `authenticate()` fängt fail-closed ab und lieferte immer
 *   null. Ein Test wäre dann grün, ohne je einen gültigen Schlüssel gesehen
 *   zu haben.
 * - Und selbst mit `CURRENT_TIMESTAMP`, das beide Engines kennen, wäre es
 *   keine Prüfung: SQLite liefert dort UTC, MariaDB die Sitzungszeitzone - auf
 *   diesem Host zwei Stunden auseinander. Für eine Ablaufprüfung ist das der
 *   Unterschied zwischen "gilt noch" und "abgelaufen".
 *
 * Eine zeitbasierte Sicherheitsprüfung wird gegen die Uhr geprüft, die im
 * Betrieb zählt.
 */
class ApiKeyLifecycleTest extends TestCase {

    private static PDO $db;

    public static function setUpBeforeClass(): void {
        if (!defined('DB_HOST')) {
            self::markTestSkipped('Keine Test-Datenbank konfiguriert (DB_HOST fehlt) - siehe tests/bootstrap.php.');
        }

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

        self::$db = Database::getInstance();
    }

    protected function setUp(): void {
        self::$db->exec("DELETE FROM api_keys");
        self::$db->exec("DELETE FROM users WHERE id = 4711");
        self::$db->exec(
            "INSERT INTO users (id, username, email, password_hash, session_version)
             VALUES (4711, 'apikey-besitzer', 'apikey-besitzer@example.com', 'x', 1)"
        );
    }

    // ---- #217: Kopplung an die session_version ------------------------

    public function testKeyIssuedBeforeAPasswordChangeIsRejected(): void {
        $erstellt = ApiKey::create(4711, 'Kopplungstest', null);
        $this->assertTrue($erstellt['ok'], 'Anlegen des Schlüssels sollte gelingen.');

        $vorher = ApiKey::authenticate($erstellt['token']);
        $this->assertNotNull($vorher, 'Ein frisch ausgestellter Schlüssel muss gültig sein.');
        $this->assertSame(4711, $vorher['user_id']);

        self::$db->exec("UPDATE users SET session_version = session_version + 1 WHERE id = 4711");

        $this->assertNull(
            ApiKey::authenticate($erstellt['token']),
            'Nach einer Passwortänderung muss ein zuvor ausgestellter Schlüssel abgelehnt werden.'
        );

        $neu = ApiKey::create(4711, 'Nach der Änderung', null);
        $this->assertTrue($neu['ok']);
        $this->assertNotNull(
            ApiKey::authenticate($neu['token']),
            'Ein nach der Passwortänderung ausgestellter Schlüssel muss gültig sein.'
        );
    }

    // ---- #340: Pflicht-Ablaufdatum ------------------------------------

    public function testExpiredKeyIsRejectedEvenThoughItIsNotRevoked(): void {
        $erstellt = ApiKey::create(4711, 'Läuft ab', null);
        $this->assertTrue($erstellt['ok']);
        $this->assertNotNull(ApiKey::authenticate($erstellt['token']), 'Vorbedingung: gilt zunächst');

        // Nur den Ablauf zurückdatieren - revoked_at bleibt ausdrücklich NULL,
        // damit belegt ist, dass wirklich der ABLAUF greift und nicht der
        // Widerruf.
        self::$db->exec("UPDATE api_keys SET expires_at = NOW() - INTERVAL 1 SECOND");

        $this->assertNull(
            ApiKey::authenticate($erstellt['token']),
            'Ein abgelaufener Schlüssel muss abgelehnt werden, auch ohne Widerruf.'
        );
        $this->assertSame(
            0,
            (int)self::$db->query("SELECT COUNT(*) FROM api_keys WHERE revoked_at IS NOT NULL")->fetchColumn(),
            'Der Test darf nicht versehentlich den Widerrufsweg prüfen.'
        );
    }

    /**
     * Die Obergrenze ist serverseitig. Ein manipuliertes Formularfeld darf sie
     * nicht anheben - sonst ist die Frist eine Formalie.
     */
    public function testLifetimeIsCappedAtTwoYearsRegardlessOfTheRequest(): void {
        $erstellt = ApiKey::create(4711, 'Ewig bitte', null, 100000);
        $this->assertTrue($erstellt['ok']);

        $gespeichert = (string)self::$db->query(
            "SELECT expires_at FROM api_keys ORDER BY id DESC LIMIT 1"
        )->fetchColumn();

        $tage = (int)floor((strtotime($gespeichert) - time()) / 86400);
        $this->assertLessThanOrEqual(
            ApiKey::MAX_LIFETIME_DAYS,
            $tage,
            'Die Laufzeit darf zwei Jahre nie überschreiten, egal was angefragt wurde.'
        );
        $this->assertGreaterThan(ApiKey::MAX_LIFETIME_DAYS - 2, $tage, 'Gedeckelt heisst gedeckelt, nicht verworfen.');
    }

    public function testAShorterLifetimeIsHonoured(): void {
        $erstellt = ApiKey::create(4711, 'Kurz', null, 30);
        $this->assertTrue($erstellt['ok']);

        $gespeichert = (string)self::$db->query(
            "SELECT expires_at FROM api_keys ORDER BY id DESC LIMIT 1"
        )->fetchColumn();

        $tage = (int)round((strtotime($gespeichert) - time()) / 86400);
        $this->assertSame(30, $tage);
    }

    /**
     * Abgelaufene Schlüssel dürfen das Limit nicht blockieren: Der Weg aus
     * einem Ablauf heraus ist "neu ausstellen".
     */
    public function testExpiredKeysDoNotCountAgainstTheLimit(): void {
        for ($i = 0; $i < ApiKey::MAX_KEYS_PER_USER; $i++) {
            $this->assertTrue(ApiKey::create(4711, "Schlüssel {$i}", null)['ok']);
        }
        $this->assertSame(
            'limit_reached',
            ApiKey::create(4711, 'Einer zu viel', null)['error'] ?? null,
            'Vorbedingung: das Limit greift überhaupt'
        );

        self::$db->exec("UPDATE api_keys SET expires_at = NOW() - INTERVAL 1 DAY");

        $this->assertTrue(
            ApiKey::create(4711, 'Nach dem Ablauf', null)['ok'],
            'Nach dem Ablauf muss ein neuer Schlüssel ausgestellt werden können.'
        );
    }

    /** Die Übersicht muss einen abgelaufenen Schlüssel als solchen ausweisen. */
    public function testOverviewMarksExpiredKeysInsteadOfShowingThemAsActive(): void {
        ApiKey::create(4711, 'Gültig', null);
        $liste = ApiKey::forUser(4711);
        $this->assertCount(1, $liste);
        $this->assertSame(0, (int)$liste[0]['is_expired'], 'Vorbedingung: gilt zunächst');
        $this->assertNotEmpty($liste[0]['expires_at']);

        self::$db->exec("UPDATE api_keys SET expires_at = NOW() - INTERVAL 1 DAY");

        $liste = ApiKey::forUser(4711);
        $this->assertCount(1, $liste, 'Ein abgelaufener Schlüssel verschwindet nicht - er wird gekennzeichnet.');
        $this->assertSame(1, (int)$liste[0]['is_expired']);
    }

    /**
     * create() übernimmt die session_version des Besitzers atomar im
     * INSERT ... SELECT - für einen unbekannten (oder gelöschten) Besitzer
     * entsteht deshalb gar kein Schlüssel, statt einer verwaisten Zeile mit
     * geratenem Stand.
     *
     * Stand bis #340 in der Unit-Suite; dort prüfte er nach der Einführung des
     * Ablaufs die falsche Hürde (countActive() scheitert unter SQLite an
     * NOW() und meldet fail-closed 'limit_reached').
     */
    public function testCreateRefusesForUnknownOwner(): void {
        $ergebnis = ApiKey::create(999999, 'Verwaist', null);

        $this->assertFalse($ergebnis['ok']);
        $this->assertSame('db_error', $ergebnis['error'] ?? null, 'Es muss am unbekannten Besitzer scheitern, nicht am Limit.');
        $this->assertArrayNotHasKey('token', $ergebnis, 'Für einen unbekannten Besitzer darf kein Schlüsselwert entstehen.');
    }

    /**
     * Bestandsschlüssel laufen mit dem Update ab (#340) - und zwar über den
     * Migrationsschritt, nicht über den Spaltendefault.
     *
     * Der Default wird in der Zeitzone der DATENBANK ausgewertet;
     * Database::alignSessionTimeZone() greift nur für Verbindungen aus
     * Database::getInstance(), database/migrate.php baut eine eigene. Läge die
     * Datenbank vor der PHP-Zeit, wäre der gesetzte Wert aus Sicht von
     * authenticate() in der Zukunft und der Altbestand bliebe gültig.
     */
    public function testMigrationDatesExistingKeysBackToTheirCreation(): void {
        $erstellt = ApiKey::create(4711, 'Bestand', null);
        $this->assertTrue($erstellt['ok']);

        // Zustand VOR der Migration nachstellen: Schlüssel von vor einem Jahr,
        // Ablauf steht (wie beim Spaltendefault) auf der Migrationszeit.
        self::$db->exec(
            "UPDATE api_keys SET created_at = NOW() - INTERVAL 365 DAY, expires_at = NOW() + INTERVAL 1 HOUR"
        );
        $this->assertNotNull(
            ApiKey::authenticate($erstellt['token']),
            'Vorbedingung: mit einem Ablauf in der Zukunft gilt der Schlüssel noch'
        );

        self::$db->exec("DELETE FROM settings WHERE setting_key = 'migration_340_bestandsschluessel_ablaufen'");
        self::$db->exec("UPDATE settings SET setting_value = '0' WHERE setting_key = 'schema_version'");
        \App\Service\SchemaMigrator::run(self::$db);

        $this->assertNull(
            ApiKey::authenticate($erstellt['token']),
            'Nach der Migration muss ein Bestandsschlüssel abgelaufen sein.'
        );
        $this->assertSame(
            0,
            (int)self::$db->query("SELECT COUNT(*) FROM api_keys WHERE revoked_at IS NOT NULL")->fetchColumn(),
            'Abgelaufen heisst nicht widerrufen - der Widerrufsweg bleibt daneben bestehen.'
        );
    }

    /** Der Hinweis an die Gegenstelle, bevor der Zugang stehenbleibt. */
    public function testRemainingDaysAreReportedForSoonExpiringKeys(): void {
        $erstellt = ApiKey::create(4711, 'Bald fällig', null, 5);
        $this->assertTrue($erstellt['ok']);

        $key = ApiKey::authenticate($erstellt['token']);
        $this->assertNotNull($key);

        $rest = ApiKey::daysUntilExpiry($key);
        $this->assertNotNull($rest);
        $this->assertLessThanOrEqual(ApiKey::EXPIRY_WARNING_DAYS, $rest);
        $this->assertGreaterThanOrEqual(4, $rest);
    }
}
