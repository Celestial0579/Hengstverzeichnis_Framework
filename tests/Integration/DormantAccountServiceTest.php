<?php
// tests/Integration/DormantAccountServiceTest.php

namespace Tests\Integration;

use App\Database;
use App\Service\DormantAccountService;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Die 180-Tage-Regel für Konten ohne zweiten Faktor und ohne E-Mail (#358).
 *
 * Der Dateiname beginnt bewusst mit "Do": Alles, was alphabetisch VOR
 * DatabaseTest.php sortiert, bräche dessen Anspruch, erster
 * Database::getInstance()-Aufrufer im Prozess zu sein.
 *
 * Geprüft wird gegen eine echte MariaDB, weil die gesamte Fachlogik aus
 * SQL-Datumsarithmetik besteht (`unprotected_since + INTERVAL n DAY`).
 */
class DormantAccountServiceTest extends TestCase {

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
        self::$db->exec("DELETE FROM settings WHERE setting_key = 'dormant_rule_active_since'");
        // Karenz aus dem Weg räumen: Die Regel gilt in den meisten Tests als
        // lange aktiv. Wo die Karenz SELBST geprüft wird, setzt der Test sie um.
        self::$db->exec(
            "INSERT INTO settings (setting_key, setting_value) VALUES ('dormant_rule_active_since', DATE_FORMAT(NOW() - INTERVAL 90 DAY, '%Y-%m-%d %H:%i:%s'))"
        );
    }

    /**
     * @param array{email?: ?string, totp?: int, since?: ?string, admin?: bool} $o
     */
    private function konto(string $name, array $o = []): int {
        $stmt = self::$db->prepare(
            "INSERT INTO users (username, email, password_hash, totp_enabled, unprotected_since)
             VALUES (?, ?, 'x', ?, ?)"
        );
        $stmt->execute([
            $name,
            array_key_exists('email', $o) ? $o['email'] : $name . '@example.com',
            $o['totp'] ?? 0,
            $o['since'] ?? null,
        ]);
        $id = (int)self::$db->lastInsertId();

        if (!empty($o['admin'])) {
            self::$db->exec("INSERT IGNORE INTO `groups` (id, name, slug, is_builtin) VALUES (1, 'Administrator', 'admin', 1)");
            self::$db->prepare("INSERT INTO user_groups (user_id, group_id) VALUES (?, 1)")->execute([$id]);
        }
        return $id;
    }

    private function spalte(int $id, string $spalte) {
        $stmt = self::$db->prepare("SELECT `{$spalte}` FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetchColumn();
    }

    // ---- Fristanker ----------------------------------------------------

    public function testMarkerIsSetOnlyForAccountsWithoutFactorAndWithoutEmail(): void {
        $ungeschuetzt = $this->konto('ohne-alles', ['email' => null]);
        $mitMail      = $this->konto('mit-mail');
        $mitFaktor    = $this->konto('mit-faktor', ['email' => null, 'totp' => 1]);

        DormantAccountService::refreshMarkers();

        $this->assertNotNull($this->spalte($ungeschuetzt, 'unprotected_since'));
        $this->assertNull($this->spalte($mitMail, 'unprotected_since'), 'Eine E-Mail-Adresse schützt.');
        $this->assertNull($this->spalte($mitFaktor, 'unprotected_since'), 'Ein zweiter Faktor schützt.');
    }

    /**
     * Der Kern des Ankers: Sobald das Konto geschützt ist, beginnt die Frist
     * von vorn. Mit `created_at` als Grundlage wäre das nicht ausdrückbar.
     */
    public function testMarkerIsClearedAgainWhenTheAccountBecomesProtected(): void {
        $id = $this->konto('spaeter-geschuetzt', ['email' => null, 'since' => '2020-01-01 00:00:00']);
        $this->assertNotNull($this->spalte($id, 'unprotected_since'));

        self::$db->prepare("UPDATE users SET email = 'jetzt@example.com' WHERE id = ?")->execute([$id]);
        DormantAccountService::refreshMarkers();

        $this->assertNull($this->spalte($id, 'unprotected_since'), 'Der Anker muss verschwinden, sobald der Zustand endet.');

        // Und er beginnt neu, nicht beim alten Wert.
        self::$db->prepare("UPDATE users SET email = NULL WHERE id = ?")->execute([$id]);
        DormantAccountService::refreshMarkers();
        $neu = (string)$this->spalte($id, 'unprotected_since');
        $this->assertGreaterThan(strtotime('-1 hour'), strtotime($neu), 'Die Frist muss von vorn beginnen.');
    }

    // ---- Der Lauf ------------------------------------------------------

    public function testAccountsPastTheDeadlineAreDeactivatedButNotDeleted(): void {
        $this->konto('schutz-admin', ['admin' => true]);
        $faellig = $this->konto('laengst-faellig', ['email' => null, 'since' => '2020-01-01 00:00:00']);
        $frisch  = $this->konto('frisch', ['email' => null]);

        $ergebnis = DormantAccountService::run();

        $this->assertFalse($ergebnis['karenz']);
        $this->assertSame(1, $ergebnis['deaktiviert']);
        $this->assertNotNull($this->spalte($faellig, 'deactivated_at'));
        $this->assertSame(DormantAccountService::REASON_DORMANT, $this->spalte($faellig, 'deactivated_reason'));
        $this->assertNull($this->spalte($faellig, 'deleted_at'), 'Deaktiviert heisst NICHT geloescht.');
        $this->assertNull($this->spalte($frisch, 'deactivated_at'), 'Ein frisches Konto bleibt unberührt.');
    }

    /**
     * Das letzte Konto, das die Installation noch verwalten kann, wird nie
     * deaktiviert - sonst sperrt sie sich selbst aus, und die Sperre liesse
     * sich nicht mehr aufheben.
     */
    public function testTheLastRemainingAdminIsNeverDeactivated(): void {
        $admin = $this->konto('einziger-admin', ['email' => null, 'since' => '2020-01-01 00:00:00', 'admin' => true]);

        $ergebnis = DormantAccountService::run();

        $this->assertSame(0, $ergebnis['deaktiviert']);
        $this->assertSame(1, $ergebnis['uebersprungen_admin']);
        $this->assertNull($this->spalte($admin, 'deactivated_at'));
    }

    public function testASecondAdminMakesTheFirstOneDeactivatable(): void {
        $alt = $this->konto('admin-alt', ['email' => null, 'since' => '2020-01-01 00:00:00', 'admin' => true]);
        $this->konto('admin-neu', ['admin' => true]);

        $ergebnis = DormantAccountService::run();

        $this->assertSame(1, $ergebnis['deaktiviert']);
        $this->assertNotNull($this->spalte($alt, 'deactivated_at'));
    }

    /**
     * Karenz nach dem Update: Der erste Lauf darf den Altbestand nicht am
     * selben Tag abräumen, ohne dass die Vorwarnung je jemand gesehen hat.
     */
    public function testTheFirstRunAfterTheUpdateOnlyStartsTheGracePeriod(): void {
        self::$db->exec("DELETE FROM settings WHERE setting_key = 'dormant_rule_active_since'");
        $faellig = $this->konto('altbestand', ['email' => null, 'since' => '2020-01-01 00:00:00']);

        $ergebnis = DormantAccountService::run();

        $this->assertTrue($ergebnis['karenz']);
        $this->assertSame(0, $ergebnis['deaktiviert']);
        $this->assertNull($this->spalte($faellig, 'deactivated_at'));

        // Auch ein Lauf am Folgetag bleibt in der Karenz.
        $this->assertTrue(DormantAccountService::run()['karenz']);
        $this->assertNull($this->spalte($faellig, 'deactivated_at'));
    }

    // ---- Vorwarnung und Rückweg ----------------------------------------

    public function testDueSoonReportsAccountsInsideTheWarningWindowOnly(): void {
        $bald = $this->konto('bald', [
            'email' => null,
            'since' => date('Y-m-d H:i:s', strtotime('-' . (DormantAccountService::DORMANT_DAYS - 3) . ' days')),
        ]);
        $this->konto('noch-lange', [
            'email' => null,
            'since' => date('Y-m-d H:i:s', strtotime('-10 days')),
        ]);

        $liste = DormantAccountService::dueSoon();

        $this->assertCount(1, $liste);
        $this->assertSame($bald, (int)$liste[0]['id']);
        $this->assertNotEmpty($liste[0]['due_at']);
    }

    public function testReactivationAlsoResetsTheDeadlineAnchor(): void {
        $id = $this->konto('wieder-an', ['email' => null, 'since' => '2020-01-01 00:00:00']);
        DormantAccountService::run();
        $this->assertNotNull($this->spalte($id, 'deactivated_at'), 'Vorbedingung: deaktiviert');

        $this->assertTrue(DormantAccountService::reactivate($id));

        $this->assertNull($this->spalte($id, 'deactivated_at'));
        $this->assertNull($this->spalte($id, 'deactivated_reason'));
        $this->assertNull(
            $this->spalte($id, 'unprotected_since'),
            'Ohne Zuruecksetzen des Ankers deaktivierte der naechste Lauf dasselbe Konto sofort wieder.'
        );

        // Genau das wird hier nachgewiesen: der naechste Lauf laesst es in Ruhe.
        DormantAccountService::run();
        $this->assertNull($this->spalte($id, 'deactivated_at'));
    }
}
