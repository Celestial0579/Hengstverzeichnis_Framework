<?php
// tests/Integration/EmailRequirementTest.php

namespace Tests\Integration;

use App\Database;
use App\Permission\EmailRequirement;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Wer braucht eine E-Mail-Adresse? (#348)
 *
 * Die Regel besteht aus zwei Abfragen ueber `groups`, `group_permissions` und
 * `user_groups` - inklusive eines LEFT JOIN, dessen Sonderfall (`admin` hat
 * absichtlich KEINE Berechtigungszeilen) genau der Punkt ist, an dem eine
 * naive Fassung Administratoren fuer Nur-Leser haelt. Das laesst sich nur
 * gegen echte Tabellen pruefen.
 */
class EmailRequirementTest extends TestCase {

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
        self::$db->exec("DELETE FROM group_permissions WHERE group_id NOT IN (SELECT id FROM `groups` WHERE slug IN ('editor','public'))");
        self::$db->exec("DELETE FROM `groups` WHERE slug NOT IN ('admin','editor','public')");
    }

    private function gruppeId(string $slug): int {
        $stmt = self::$db->prepare("SELECT id FROM `groups` WHERE slug = ?");
        $stmt->execute([$slug]);
        return (int)$stmt->fetchColumn();
    }

    private function eigeneGruppe(string $slug, array $rechte): int {
        $stmt = self::$db->prepare("INSERT INTO `groups` (slug, name) VALUES (?, ?)");
        $stmt->execute([$slug, ucfirst($slug)]);
        $id = (int)self::$db->lastInsertId();

        $insert = self::$db->prepare("INSERT INTO group_permissions (group_id, module, action) VALUES (?, ?, ?)");
        foreach ($rechte as [$modul, $aktion]) {
            $insert->execute([$id, $modul, $aktion]);
        }
        return $id;
    }

    private function konto(string $name, ?string $email, array $gruppen = []): int {
        $stmt = self::$db->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, 'x')");
        $stmt->execute([$name, $email]);
        $id = (int)self::$db->lastInsertId();

        $insert = self::$db->prepare("INSERT INTO user_groups (user_id, group_id) VALUES (?, ?)");
        foreach ($gruppen as $gruppenId) {
            $insert->execute([$id, $gruppenId]);
        }
        return $id;
    }

    /**
     * Der wichtigste Fall: `admin` hat systemseitig alle Rechte und
     * absichtlich KEINE Zeilen in group_permissions. Wer nur die Tabelle
     * abfragt, haelt Administratoren fuer Nur-Leser.
     */
    public function testAdminVerlangtEineAdresseObwohlKeineRechtezeilenExistieren(): void {
        $adminId = $this->gruppeId('admin');

        $this->assertSame(
            0,
            (int)self::$db->query("SELECT COUNT(*) FROM group_permissions WHERE group_id = {$adminId}")->fetchColumn(),
            'Voraussetzung des Tests: Die Admin-Matrix ist leer.'
        );
        $this->assertContains($adminId, EmailRequirement::groupIdsRequiringEmail(self::$db));
        $this->assertTrue(EmailRequirement::groupsRequireEmail(self::$db, [$adminId]));
    }

    public function testEineNurLesendeGruppeVerlangtKeineAdresse(): void {
        $leser = $this->eigeneGruppe('leser', [['horses', 'view'], ['contacts', 'view']]);

        $this->assertFalse(EmailRequirement::groupsRequireEmail(self::$db, [$leser]));
        $this->assertNotContains($leser, EmailRequirement::groupIdsRequiringEmail(self::$db));
    }

    /**
     * Jede Aktion ausser `view` zaehlt - auch auf einem Modul, das der Kern
     * gar nicht kennt. Genau das ist der Fall "Addon-Modul" aus #348.
     */
    public function testJedeAktionAusserViewVerlangtEineAdresse(): void {
        $fremd = $this->eigeneGruppe('addonmodul', [['irgendein_plugin', 'edit']]);

        $this->assertTrue(EmailRequirement::groupsRequireEmail(self::$db, [$fremd]));
    }

    /**
     * `read` ist die ZWEITE Leseaktion des Kerns.
     *
     * FeatureRegistry legt fuer jede Plugin-Zusatzfunktion
     * `feature_<key>`/`read` an, und FeatureGate wertet sie als reine
     * Leseberechtigung. Wer nur `view` als lesend kennt, haelt eine Gruppe,
     * die eine Plugin-Funktion bloss SEHEN darf, fuer schreibberechtigt - und
     * lehnt damit genau den Fall ab, fuer den #348 gebaut wurde. In jeder
     * Installation mit Addons waere die Regel unbrauchbar.
     */
    public function testDieZweiteLeseaktionReadZaehltNichtAlsSchreibrecht(): void {
        $funktion = $this->eigeneGruppe('funktionsleser', [['feature_demo-premium', 'read']]);

        $this->assertFalse(
            EmailRequirement::groupsRequireEmail(self::$db, [$funktion]),
            'Eine Gruppe, die eine Plugin-Funktion nur sehen darf, braucht keine Adresse.'
        );
        $this->assertFalse(EmailRequirement::pairsRequireEmail([
            ['module' => 'feature_demo-premium', 'action' => 'read'],
            ['module' => 'horses', 'action' => 'view'],
        ]));
    }

    /**
     * Die Kehrseite: Eine unbekannte Aktion gilt weiter als schreibend.
     * Plugins duerfen eigene Aktionen anmelden, und die Positivliste darf
     * nicht zur Luecke werden.
     */
    public function testEineUnbekannteAktionGiltWeiterAlsSchreibend(): void {
        $this->assertTrue(EmailRequirement::pairsRequireEmail([
            ['module' => 'irgendein_plugin', 'action' => 'freigeben'],
        ]));
    }

    public function testEineGruppeOhneJedesRechtVerlangtKeineAdresse(): void {
        $leer = $this->eigeneGruppe('leer', []);

        $this->assertFalse(EmailRequirement::groupsRequireEmail(self::$db, [$leer]));
    }

    public function testEineEinzigeSchreibendeGruppeGenuegt(): void {
        $leser = $this->eigeneGruppe('nurlesen', [['horses', 'view']]);
        $schreiber = $this->eigeneGruppe('schreiben', [['horses', 'edit']]);

        $this->assertTrue(EmailRequirement::groupsRequireEmail(self::$db, [$leser, $schreiber]));
    }

    public function testOhneGruppenGiltKeinePflicht(): void {
        $this->assertFalse(EmailRequirement::groupsRequireEmail(self::$db, []));
        $this->assertFalse(EmailRequirement::groupsRequireEmail(self::$db, [0, -1]));
    }

    public function testAccountsWithoutEmailNenntNurBetroffeneUndNichtGeloeschte(): void {
        $gruppe = $this->eigeneGruppe('redaktion', [['horses', 'edit']]);

        $this->konto('mit_adresse', 'mit@example.org', [$gruppe]);
        $this->konto('ohne_adresse', null, [$gruppe]);
        $this->konto('leerer_string', '', [$gruppe]);
        $this->konto('fremde_gruppe', null, []);
        $geloescht = $this->konto('geloescht', null, [$gruppe]);
        self::$db->prepare("UPDATE users SET deleted_at = NOW() WHERE id = ?")->execute([$geloescht]);

        $namen = array_column(EmailRequirement::accountsWithoutEmail(self::$db, [$gruppe]), 'username');
        sort($namen);

        $this->assertSame(['leerer_string', 'ohne_adresse'], $namen);
    }

    /**
     * Eine Sperre ist umkehrbar (#358) - das Konto kaeme mit dem neuen Recht
     * und ohne Adresse zurueck. Es muss also mitgezaehlt werden.
     */
    public function testGesperrteKontenZaehlenMit(): void {
        $gruppe = $this->eigeneGruppe('redaktion2', [['horses', 'publish']]);
        $gesperrt = $this->konto('gesperrt', null, [$gruppe]);
        self::$db->prepare("UPDATE users SET deactivated_at = NOW() WHERE id = ?")->execute([$gesperrt]);

        $namen = array_column(EmailRequirement::accountsWithoutEmail(self::$db, [$gruppe]), 'username');

        $this->assertSame(['gesperrt'], $namen);
    }

    public function testUserRequiresEmailRichtetSichNachDenGruppenDesKontos(): void {
        $leser = $this->eigeneGruppe('nurlesen2', [['horses', 'view']]);
        $schreiber = $this->eigeneGruppe('schreiben2', [['horses', 'create']]);

        $nurLeser = $this->konto('leserin', null, [$leser]);
        $bearbeiterin = $this->konto('bearbeiterin', 'b@example.org', [$schreiber]);

        $this->assertFalse(EmailRequirement::userRequiresEmail(self::$db, $nurLeser));
        $this->assertTrue(EmailRequirement::userRequiresEmail(self::$db, $bearbeiterin));
    }

    public function testPairsRequireEmailPrueftDieNochNichtGespeicherteAuswahl(): void {
        $this->assertFalse(EmailRequirement::pairsRequireEmail([
            ['module' => 'horses', 'action' => 'view'],
        ]));
        $this->assertTrue(EmailRequirement::pairsRequireEmail([
            ['module' => 'horses', 'action' => 'view'],
            ['module' => 'horses', 'action' => 'delete'],
        ]));
        $this->assertFalse(EmailRequirement::pairsRequireEmail([]));
    }
}
