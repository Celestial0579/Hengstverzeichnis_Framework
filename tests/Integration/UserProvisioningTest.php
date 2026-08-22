<?php
// tests/Integration/UserProvisioningTest.php

namespace Tests\Integration;

use App\Database;
use App\Service\UserProvisioning;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Der eine Weg, ein Konto anzulegen (#384).
 *
 * Geprueft wird gegen eine echte MariaDB: Der Dienst haengt an der
 * Adresspflicht (#348, drei Tabellen), am UNIQUE-Index (der letzte Schutz
 * gegen ein Wettrennen) und an der Filterung nicht zuweisbarer Gruppen.
 */
class UserProvisioningTest extends TestCase {

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
        self::$db->exec("DELETE FROM user_groups");
        self::$db->exec("DELETE FROM users");
        self::$db->exec("DELETE FROM `groups` WHERE slug NOT IN ('admin','editor','public')");
    }

    private function gruppeId(string $slug): int {
        $stmt = self::$db->prepare("SELECT id FROM `groups` WHERE slug = ?");
        $stmt->execute([$slug]);
        return (int)$stmt->fetchColumn();
    }

    private function leseGruppe(string $slug = 'nurlesen'): int {
        $stmt = self::$db->prepare("INSERT INTO `groups` (slug, name) VALUES (?, ?)");
        $stmt->execute([$slug, ucfirst($slug)]);
        $id = (int)self::$db->lastInsertId();
        self::$db->prepare("INSERT INTO group_permissions (group_id, module, action) VALUES (?, 'horses', 'view')")->execute([$id]);
        return $id;
    }

    public function testEinKontoEntstehtMitAllenVorgaben(): void {
        $ergebnis = UserProvisioning::create(self::$db, 'redakteurin', 'red@example.org', 'GenugLang123', [], 'Test');

        $this->assertTrue($ergebnis->erfolgreich(), implode(' | ', $ergebnis->errors));

        $stmt = self::$db->prepare("SELECT username, email, must_change_password, session_version, password_hash FROM users WHERE id = ?");
        $stmt->execute([$ergebnis->userId]);
        $konto = $stmt->fetch();

        $this->assertSame('redakteurin', $konto['username']);
        $this->assertSame('red@example.org', $konto['email']);
        $this->assertSame(1, (int)$konto['must_change_password'], 'Ein erzeugtes Erstpasswort darf nicht dauerhaft gelten.');
        $this->assertSame(1, (int)$konto['session_version']);
        $this->assertTrue(password_verify('GenugLang123', $konto['password_hash']));
        $this->assertStringNotContainsString('GenugLang123', $konto['password_hash']);
    }

    /**
     * Keine Adresse heisst NULL, nicht Leerstring: Der UNIQUE-Index laesst
     * beliebig viele NULL zu, aber nur EINEN Leerstring - das zweite Konto
     * ohne Adresse liefe sonst in einen Duplikatsfehler.
     */
    public function testZweiKontenOhneAdresseSindMoeglich(): void {
        $lesen = $this->leseGruppe();

        $eins = UserProvisioning::create(self::$db, 'mitglied1', '', 'GenugLang123', [$lesen], 'Test');
        $zwei = UserProvisioning::create(self::$db, 'mitglied2', null, 'GenugLang123', [$lesen], 'Test');

        $this->assertTrue($eins->erfolgreich(), implode(' | ', $eins->errors));
        $this->assertTrue($zwei->erfolgreich(), implode(' | ', $zwei->errors));
        $this->assertSame(
            2,
            (int)self::$db->query("SELECT COUNT(*) FROM users WHERE email IS NULL")->fetchColumn()
        );
    }

    public function testOhneAdresseUndMitSchreibrechtWirdAbgelehnt(): void {
        $stmt = self::$db->prepare("INSERT INTO `groups` (slug, name) VALUES ('redaktion', 'Redaktion')");
        $stmt->execute();
        $redaktion = (int)self::$db->lastInsertId();
        self::$db->prepare("INSERT INTO group_permissions (group_id, module, action) VALUES (?, 'horses', 'edit')")->execute([$redaktion]);

        $ergebnis = UserProvisioning::create(self::$db, 'ohneadresse', '', 'GenugLang123', [$redaktion], 'Test');

        $this->assertFalse($ergebnis->erfolgreich());
        $this->assertSame(0, (int)self::$db->query("SELECT COUNT(*) FROM users")->fetchColumn(), 'Abgelehnt heisst: nichts angelegt.');
    }

    public function testBenutzernameMitAtWirdAbgelehnt(): void {
        $ergebnis = UserProvisioning::create(self::$db, 'kunde@example.org', 'k@example.org', 'GenugLang123', [], 'Test');

        $this->assertFalse($ergebnis->erfolgreich());
        $this->assertStringContainsString('@', implode(' ', $ergebnis->errors));
    }

    public function testReservierteNamenWerdenAbgelehnt(): void {
        foreach (['admin', 'ADMIN', ' Root ', 'postmaster'] as $name) {
            $ergebnis = UserProvisioning::create(self::$db, $name, 'x@example.org', 'GenugLang123', [], 'Test');
            $this->assertFalse($ergebnis->erfolgreich(), "'{$name}' haette abgelehnt werden muessen.");
        }
    }

    public function testZuKurzesPasswortWirdAbgelehnt(): void {
        $ergebnis = UserProvisioning::create(self::$db, 'kurz', 'kurz@example.org', 'sieben7', [], 'Test');

        $this->assertFalse($ergebnis->erfolgreich());
    }

    /**
     * Alle Gruende auf einmal, nicht beim ersten abgebrochen: Wer ein
     * Formular ausfuellt, soll nicht dreimal hintereinander abgewiesen werden.
     */
    public function testMehrereFehlerWerdenGesammelt(): void {
        $ergebnis = UserProvisioning::create(self::$db, 'admin@example.org', 'kaputt', 'kurz', [], 'Test');

        $this->assertGreaterThanOrEqual(3, count($ergebnis->errors), implode(' | ', $ergebnis->errors));
    }

    public function testEinZweitesKontoMitDemselbenNamenScheitertAmIndex(): void {
        UserProvisioning::create(self::$db, 'doppelt', 'a@example.org', 'GenugLang123', [], 'Test');
        $zweiter = UserProvisioning::create(self::$db, 'doppelt', 'b@example.org', 'GenugLang123', [], 'Test');

        $this->assertFalse($zweiter->erfolgreich());
        $this->assertSame(1, (int)self::$db->query("SELECT COUNT(*) FROM users")->fetchColumn());
    }

    /**
     * `public` gilt allein fuer nicht angemeldete Besucher. Ein echtes Konto
     * darf sie nie bekommen - auch nicht, wenn der Aufrufer sie nennt.
     */
    public function testDieGastGruppeWirdNieZugewiesen(): void {
        $lesen = $this->leseGruppe();
        $ergebnis = UserProvisioning::create(
            self::$db,
            'gastversuch',
            'g@example.org',
            'GenugLang123',
            [$lesen, $this->gruppeId('public')],
            'Test'
        );

        $this->assertTrue($ergebnis->erfolgreich(), implode(' | ', $ergebnis->errors));

        $stmt = self::$db->prepare("SELECT group_id FROM user_groups WHERE user_id = ?");
        $stmt->execute([$ergebnis->userId]);
        $gruppen = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $this->assertSame([$lesen], $gruppen);
    }

    public function testErzeugtePasswoerterSindLangGenugUndVerschieden(): void {
        $eins = UserProvisioning::erzeugePasswort();
        $zwei = UserProvisioning::erzeugePasswort();

        $this->assertGreaterThanOrEqual(UserProvisioning::MIN_PASSWORD_LENGTH, strlen($eins));
        $this->assertNotSame($eins, $zwei);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $eins);
    }
}
