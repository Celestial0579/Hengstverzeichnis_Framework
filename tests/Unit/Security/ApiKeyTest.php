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
        // Der Besitzer bekommt hier ausdrücklich ALLE Rechte (Gruppe `admin`).
        // Ohne diesen Schritt bewies der Test nichts: Ohne eingespielte
        // Datenbank wirft GroupMembership::hasPermission() intern und liefert
        // fail-closed false - assertFalse() wäre also auch dann grün, wenn
        // ApiKey::permits() den Scope komplett ignorierte und nur noch die
        // Besitzerrechte fragte. Genau das ist die Aussage, die hier
        // widerlegt werden soll.
        $this->seedOwnerWithFullRights();

        $key = ['user_id' => 1, 'scope' => ['horses.view']];

        // Gegenprobe zuerst: Was IM Scope liegt, geht durch - sonst könnte
        // die Fixture stillschweigend kaputt sein und alles wäre false.
        $this->assertTrue(ApiKey::permits($key, 'horses', 'view'), 'Vorbedingung: der Besitzer darf alles, der Scope erlaubt horses.view');

        $this->assertFalse(ApiKey::permits($key, 'horses', 'edit'));
        $this->assertFalse(ApiKey::permits($key, 'persons', 'view'));
    }

    /**
     * Fail-closed: ein leerer Scope erlaubt nichts (so wird u. a. ein
     * unlesbar gewordener Scope-Eintrag behandelt, siehe authenticate()).
     */
    public function testPermitsRejectsEverythingForEmptyScope(): void {
        // Auch hier: Der Besitzer darf alles. Nur so belegt das assertFalse()
        // den leeren Scope und nicht bloss eine fehlende Datenbank.
        $this->seedOwnerWithFullRights();

        $key = ['user_id' => 1, 'scope' => []];

        $this->assertFalse(ApiKey::permits($key, 'horses', 'view'));
    }

    /**
     * Benutzer 1 in die eingebaute Gruppe `admin` legen - damit liefert
     * GroupMembership::hasPermission() für jede Kombination true, und der
     * Scope ist die einzige verbleibende Schranke.
     */
    private function seedOwnerWithFullRights(): void {
        $pdo = $this->useInMemoryDatabase();
        $pdo->exec("CREATE TABLE `groups` (id INTEGER PRIMARY KEY, slug TEXT NOT NULL)");
        $pdo->exec("CREATE TABLE user_groups (user_id INTEGER NOT NULL, group_id INTEGER NOT NULL)");
        $pdo->exec("CREATE TABLE group_permissions (group_id INTEGER NOT NULL, module TEXT NOT NULL, action TEXT NOT NULL)");
        $pdo->exec("INSERT INTO `groups` (id, slug) VALUES (1, 'admin')");
        $pdo->exec("INSERT INTO user_groups (user_id, group_id) VALUES (1, 1)");
    }

    public function testMaxKeysPerUserIsFive(): void {
        $this->assertSame(5, ApiKey::MAX_KEYS_PER_USER);
    }

    /**
     * WO DIE PRUEFUNG VON authenticate() STEHT - UND WARUM NICHT HIER.
     *
     * Diese Klasse spielt eine In-Memory-SQLite in den Database-Singleton, damit
     * die Unit-Suite ohne Datenbank laeuft. Das traegt fuer reine Logik
     * (permits(), Hashes, Formate) und fuer create(), dessen INSERT keine
     * engineabhaengigen Funktionen benutzt.
     *
     * authenticate() prueft seit #340 zusaetzlich `expires_at > NOW()`. Das ist
     * eine ZEITBASIERTE Sicherheitspruefung, und genau dort gehen die beiden
     * Engines auseinander: SQLite kennt NOW() gar nicht, und sein
     * CURRENT_TIMESTAMP liefert UTC, waehrend MariaDB die Sitzungszeitzone
     * nimmt - auf diesem Host zwei Stunden Unterschied. Ein hier gruener Test
     * wuerde also weder dieselbe Funktion noch dieselbe Uhr benutzen wie die
     * Produktion.
     *
     * Die Kopplung an session_version (#217) und der Ablauf (#340) werden
     * deshalb in tests/Integration/ApiKeyLifecycleTest.php gegen eine echte
     * MariaDB geprueft. Nicht hierher zurueckholen, auch nicht "nur schnell".
     */

    /**
     * AUCH create() WIRD NICHT MEHR HIER GEPRUEFT.
     *
     * create() ruft countActive(), und das fragt seit #340
     * `expires_at > NOW()` ab. In der SQLite-Fassung wirft diese Abfrage,
     * countActive() faengt fail-closed ab und liefert MAX_KEYS_PER_USER -
     * create() bricht dann mit 'limit_reached' ab, nicht daran, dass es den
     * Besitzer nicht gibt. Ein Test auf `ok === false` waere hier also gruen,
     * ohne die geprueft geglaubte Zusicherung je erreicht zu haben.
     *
     * Siehe tests/Integration/ApiKeyLifecycleTest.php.
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
            -- Pflicht-Ablauf (#340). Der Default ist bewusst die aktuelle Zeit,
            -- also sofort abgelaufen: Wer die Spalte beim INSERT vergisst,
            -- bekommt einen unbrauchbaren Schluessel statt eines ewigen.
            expires_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            revoked_at TEXT NULL DEFAULT NULL
        )");

        $property = new \ReflectionProperty(Database::class, 'instance');
        $property->setValue(null, $pdo);

        return $pdo;
    }
}
