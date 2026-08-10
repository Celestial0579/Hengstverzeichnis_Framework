<?php
// tests/Unit/Security/ApiKeyTest.php

namespace Tests\Unit\Security;

use App\Database;
use App\Security\ApiKey;
use PHPUnit\Framework\TestCase;

/**
 * Deckt die zustandslosen Anteile von App\Security\ApiKey ab: Erzeugung und
 * Hashing der Schlüssel sowie den Scope-Anteil der Rechteprüfung, der bereits
 * VOR jedem Datenbankzugriff entscheidet (permits() bricht ab, sobald der
 * Scope die Aktion nicht enthält).
 *
 * Zusätzlich die session_version-Kopplung von authenticate() (#217) - die
 * braucht zwar SQL, aber keinen HTTP-Kontext: Sie läuft hier gegen eine
 * private In-Memory-SQLite-Datenbank, die per Reflection in den
 * Database-Singleton injiziert wird (siehe useInMemoryDatabase()). So ist die
 * sicherheitskritische Ablehnungslogik ohne externe Abhängigkeit prüfbar.
 *
 * Alles Weitere - insbesondere die Schnittmenge mit den echten Rechten des
 * Besitzers, das Limit von 5 Schlüsseln und der Widerruf über die echten
 * Routen - hängt an Datenbank und HTTP-Kontext und wird deshalb in
 * tests/Functional/ApiKeyAuthTest.php und
 * tests/Functional/ApiKeyPasswordRevocationTest.php gegen eine echte Instanz
 * geprüft (gleiche Aufteilung wie bei den übrigen Security-Klassen dieses Repos).
 */
class ApiKeyTest extends TestCase {

    /**
     * Injizierte Datenbank nach jedem Test wieder entfernen, damit kein
     * anderer Test versehentlich gegen die SQLite-Attrappe läuft (dasselbe
     * Muster wie tests/Integration/DatabaseTest::resetDatabaseSingleton()).
     */
    protected function tearDown(): void {
        $property = new \ReflectionProperty(Database::class, 'instance');
        $property->setValue(null, null);
        parent::tearDown();
    }

    public function testGeneratedTokenHasExpectedFormat(): void {
        $token = ApiKey::generateToken();

        $this->assertMatchesRegularExpression(
            '/^hv_[0-9a-f]{64}$/',
            $token,
            'Schlüssel sollten am Präfix erkennbar sein und 32 Byte (64 Hex-Zeichen) Zufall enthalten.'
        );
    }

    public function testGeneratedTokensAreUnique(): void {
        $tokens = [];
        for ($i = 0; $i < 50; $i++) {
            $tokens[] = ApiKey::generateToken();
        }

        $this->assertCount(50, array_unique($tokens), 'Erzeugte Schlüssel dürfen sich nie wiederholen.');
    }

    public function testHashIsDeterministicSha256AndNotThePlaintext(): void {
        $token = ApiKey::generateToken();
        $hash = ApiKey::hashToken($token);

        $this->assertSame(hash('sha256', $token), $hash);
        $this->assertSame($hash, ApiKey::hashToken($token), 'Gleicher Schlüssel muss denselben Hash liefern.');
        $this->assertNotSame($token, $hash);
        $this->assertStringNotContainsString($token, $hash, 'Der Hash darf den Klartext nicht enthalten.');
    }

    public function testDifferentTokensProduceDifferentHashes(): void {
        $this->assertNotSame(
            ApiKey::hashToken(ApiKey::generateToken()),
            ApiKey::hashToken(ApiKey::generateToken())
        );
    }

    /**
     * Ein eingeschränkter Scope, der die angefragte Aktion nicht enthält, muss
     * bereits ohne Datenbankzugriff ablehnen - unabhängig davon, welche Rechte
     * der Besitzer selbst hätte. Das ist die "weniger Rechte sind möglich"-
     * Hälfte des Rechtemodells.
     */
    public function testPermitsRejectsActionOutsideScopeWithoutConsultingOwnerRights(): void {
        $key = ['user_id' => 1, 'scope' => ['horses.view']];

        $this->assertFalse(ApiKey::permits($key, 'horses', 'edit'));
        $this->assertFalse(ApiKey::permits($key, 'persons', 'view'));
    }

    /**
     * Fail-closed: ein leerer Scope erlaubt nichts (so wird u. a. ein
     * unlesbar gewordener Scope-Eintrag behandelt, siehe authenticate()).
     */
    public function testPermitsRejectsEverythingForEmptyScope(): void {
        $key = ['user_id' => 1, 'scope' => []];

        $this->assertFalse(ApiKey::permits($key, 'horses', 'view'));
    }

    public function testMaxKeysPerUserIsFive(): void {
        $this->assertSame(5, ApiKey::MAX_KEYS_PER_USER);
    }

    /**
     * Kernaussage von #217: Ein Schlüssel ist an die session_version seines
     * Besitzers zum Ausstellungszeitpunkt gekoppelt. Erhöht eine
     * Passwortänderung die session_version, wird derselbe Schlüssel von
     * authenticate() abgelehnt - er überlebt die Incident-Response-Kette
     * "Passwort zurücksetzen -> alle Zugänge tot" nicht mehr.
     */
    public function testAuthenticateRejectsKeyIssuedBeforePasswordChange(): void {
        $pdo = $this->useInMemoryDatabase();
        $pdo->exec("INSERT INTO users (id, session_version) VALUES (1, 1)");

        $created = ApiKey::create(1, 'Kopplungstest', null);
        $this->assertTrue($created['ok'], 'Anlegen des Schlüssels sollte gelingen.');
        $token = $created['token'];

        // Vor der Passwortänderung: Schlüssel ist gültig und gehört Benutzer 1.
        $before = ApiKey::authenticate($token);
        $this->assertNotNull($before, 'Ein frisch ausgestellter Schlüssel muss gültig sein.');
        $this->assertSame(1, $before['user_id']);

        // Passwortänderung simulieren: exakt das Statement-Muster der
        // Passwort-Pfade (session_version + 1, siehe AuthController/UserController).
        $pdo->exec("UPDATE users SET session_version = session_version + 1 WHERE id = 1");

        $this->assertNull(
            ApiKey::authenticate($token),
            'Nach einer Passwortänderung muss ein zuvor ausgestellter Schlüssel abgelehnt werden.'
        );

        // Ein NEUER Schlüssel übernimmt den aktuellen Stand und funktioniert -
        // die Kopplung sperrt gezielt Altbestand, nicht das Konto.
        $recreated = ApiKey::create(1, 'Nach der Änderung', null);
        $this->assertTrue($recreated['ok']);
        $this->assertNotNull(
            ApiKey::authenticate($recreated['token']),
            'Ein nach der Passwortänderung ausgestellter Schlüssel muss gültig sein.'
        );
    }

    /**
     * create() übernimmt die session_version des Besitzers atomar im
     * INSERT ... SELECT - für einen unbekannten (oder gelöschten) Besitzer
     * entsteht deshalb gar kein Schlüssel, statt einer verwaisten Zeile mit
     * geratenem Stand.
     */
    public function testCreateRefusesForUnknownOwner(): void {
        $this->useInMemoryDatabase();

        $result = ApiKey::create(999, 'Verwaist', null);

        $this->assertFalse($result['ok']);
        $this->assertArrayNotHasKey('token', $result, 'Für einen unbekannten Besitzer darf kein Schlüsselwert entstehen.');
    }

    /**
     * Baut eine In-Memory-SQLite-Datenbank mit den von ApiKey benötigten
     * Tabellen (Minimal-Schema analog database/schema.sql) und injiziert sie
     * in den Database-Singleton. tearDown() räumt die Injektion wieder ab.
     */
    private function useInMemoryDatabase(): \PDO {
        $pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            session_version INTEGER NOT NULL DEFAULT 1,
            deleted_at TEXT NULL DEFAULT NULL
        )");
        $pdo->exec("CREATE TABLE api_keys (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            label TEXT NOT NULL,
            token_hash TEXT NOT NULL UNIQUE,
            token_prefix TEXT NOT NULL,
            scope_permissions TEXT NULL DEFAULT NULL,
            issued_session_version INTEGER NOT NULL DEFAULT 1,
            last_used_at TEXT NULL DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            revoked_at TEXT NULL DEFAULT NULL
        )");

        $property = new \ReflectionProperty(Database::class, 'instance');
        $property->setValue(null, $pdo);

        return $pdo;
    }
}
