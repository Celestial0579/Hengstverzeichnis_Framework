<?php
// tests/Integration/EmailSecondFactorTest.php

namespace Tests\Integration;

use App\Database;
use App\Security\EmailSecondFactor;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Einmalcodes fuer den zweiten Faktor per E-Mail (#354), gegen eine ECHTE
 * MariaDB.
 *
 * WARUM NICHT IN DER UNIT-SUITE. Dieselbe Begruendung wie bei
 * ApiKeyLifecycleTest: Die Gueltigkeit haengt an `NOW()` und
 * `DATE_ADD(..., INTERVAL ? SECOND)`. SQLite kennt beides nicht bzw. rechnet
 * in einer anderen Zeitzone - ein gruener Test haette dann nie einen
 * gueltigen Code gesehen. Eine zeitbasierte Sicherheitspruefung wird gegen
 * die Uhr geprueft, die im Betrieb zaehlt.
 */
class EmailSecondFactorTest extends TestCase {

    private static PDO $db;
    private int $userId;

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
        self::$db->exec("DELETE FROM email_2fa_codes");
        self::$db->exec("DELETE FROM users");
        self::$db->exec("INSERT INTO users (username, email, password_hash) VALUES ('mailfaktor', 'mailfaktor@example.org', 'x')");
        $this->userId = (int)self::$db->lastInsertId();
    }

    public function testEinAusgestellterCodeGiltGenauEinmal(): void {
        $code = EmailSecondFactor::issue($this->userId, EmailSecondFactor::PURPOSE_LOGIN);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code, 'Sechs Ziffern, mit fuehrenden Nullen.');
        $this->assertTrue(EmailSecondFactor::verify($this->userId, EmailSecondFactor::PURPOSE_LOGIN, $code));
        $this->assertFalse(
            EmailSecondFactor::verify($this->userId, EmailSecondFactor::PURPOSE_LOGIN, $code),
            'Ein eingeloester Code darf kein zweites Mal gelten.'
        );
    }

    /**
     * Der Klartext darf nirgends in der Tabelle stehen - dasselbe Prinzip wie
     * bei den Backup-Codes.
     */
    public function testGespeichertWirdNurDerAbdruck(): void {
        $code = EmailSecondFactor::issue($this->userId, EmailSecondFactor::PURPOSE_LOGIN);

        $hash = (string)self::$db->query("SELECT code_hash FROM email_2fa_codes")->fetchColumn();
        $this->assertNotSame($code, $hash);
        $this->assertStringNotContainsString($code, $hash);
        $this->assertTrue(password_verify($code, $hash), 'Der Abdruck muss zum Code passen.');
    }

    public function testEinNeuerCodeLoestDenAltenAb(): void {
        $alt = EmailSecondFactor::issue($this->userId, EmailSecondFactor::PURPOSE_LOGIN);
        $neu = EmailSecondFactor::issue($this->userId, EmailSecondFactor::PURPOSE_LOGIN);

        $this->assertSame(
            1,
            (int)self::$db->query("SELECT COUNT(*) FROM email_2fa_codes")->fetchColumn(),
            'Je Konto und Zweck darf es nur EINE Zeile geben.'
        );
        if ($alt !== $neu) {
            $this->assertFalse(EmailSecondFactor::verify($this->userId, EmailSecondFactor::PURPOSE_LOGIN, $alt));
        }
        $this->assertTrue(EmailSecondFactor::verify($this->userId, EmailSecondFactor::PURPOSE_LOGIN, $neu));
    }

    public function testEinAbgelaufenerCodeGiltNichtMehr(): void {
        $code = EmailSecondFactor::issue($this->userId, EmailSecondFactor::PURPOSE_LOGIN);
        self::$db->exec("UPDATE email_2fa_codes SET expires_at = NOW() - INTERVAL 1 SECOND");

        $this->assertFalse(EmailSecondFactor::verify($this->userId, EmailSecondFactor::PURPOSE_LOGIN, $code));
        $this->assertSame(
            0,
            (int)self::$db->query("SELECT COUNT(*) FROM email_2fa_codes")->fetchColumn(),
            'Was nicht mehr gilt, bleibt nicht als Zeile liegen.'
        );
    }

    /**
     * Nach MAX_ATTEMPTS ist der Code VERBRAUCHT, nicht nur gebremst. Ein
     * Zaehler, der nur bremst, liesse ihn weiterleben - die naechste Runde
     * finge von vorn an.
     */
    public function testNachZuVielenFehlversuchenIstDerCodeVerbraucht(): void {
        $code = EmailSecondFactor::issue($this->userId, EmailSecondFactor::PURPOSE_LOGIN);
        $falsch = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < EmailSecondFactor::MAX_ATTEMPTS; $i++) {
            $this->assertFalse(EmailSecondFactor::verify($this->userId, EmailSecondFactor::PURPOSE_LOGIN, $falsch));
        }

        $this->assertFalse(
            EmailSecondFactor::verify($this->userId, EmailSecondFactor::PURPOSE_LOGIN, $code),
            'Auch der RICHTIGE Code darf danach nicht mehr gelten.'
        );
    }

    /**
     * Ein Probecode aus der Einrichtung darf sich nicht als Anmeldefaktor
     * einloesen lassen. Beide gehen an dieselbe Adresse - aber ein Nachweis
     * gilt fuer den Vorgang, fuer den er ausgestellt wurde.
     */
    public function testCodesSindAnIhrenZweckGebunden(): void {
        $probe = EmailSecondFactor::issue($this->userId, EmailSecondFactor::PURPOSE_SETUP);

        $this->assertFalse(EmailSecondFactor::verify($this->userId, EmailSecondFactor::PURPOSE_LOGIN, $probe));
        $this->assertTrue(EmailSecondFactor::verify($this->userId, EmailSecondFactor::PURPOSE_SETUP, $probe));
    }

    public function testDiscardOhneZweckRaeumtBeideVorgaengeAb(): void {
        EmailSecondFactor::issue($this->userId, EmailSecondFactor::PURPOSE_LOGIN);
        EmailSecondFactor::issue($this->userId, EmailSecondFactor::PURPOSE_SETUP);
        $this->assertSame(2, (int)self::$db->query("SELECT COUNT(*) FROM email_2fa_codes")->fetchColumn());

        EmailSecondFactor::discard($this->userId);

        $this->assertSame(0, (int)self::$db->query("SELECT COUNT(*) FROM email_2fa_codes")->fetchColumn());
    }

    public function testPendingKenntNurGueltigeCodes(): void {
        $this->assertFalse(EmailSecondFactor::pending($this->userId, EmailSecondFactor::PURPOSE_SETUP));

        EmailSecondFactor::issue($this->userId, EmailSecondFactor::PURPOSE_SETUP);
        $this->assertTrue(EmailSecondFactor::pending($this->userId, EmailSecondFactor::PURPOSE_SETUP));

        self::$db->exec("UPDATE email_2fa_codes SET expires_at = NOW() - INTERVAL 1 SECOND");
        $this->assertFalse(EmailSecondFactor::pending($this->userId, EmailSecondFactor::PURPOSE_SETUP));
    }

    /**
     * Ein geloeschtes Konto darf keine Codes hinterlassen - der
     * Fremdschluessel raeumt sie mit ab.
     */
    public function testCodesVerschwindenMitDemKonto(): void {
        EmailSecondFactor::issue($this->userId, EmailSecondFactor::PURPOSE_LOGIN);

        self::$db->prepare("DELETE FROM users WHERE id = ?")->execute([$this->userId]);

        $this->assertSame(0, (int)self::$db->query("SELECT COUNT(*) FROM email_2fa_codes")->fetchColumn());
    }

    public function testLeereEingabeTrifftNie(): void {
        EmailSecondFactor::issue($this->userId, EmailSecondFactor::PURPOSE_LOGIN);

        $this->assertFalse(EmailSecondFactor::verify($this->userId, EmailSecondFactor::PURPOSE_LOGIN, ''));
        $this->assertFalse(EmailSecondFactor::verify($this->userId, EmailSecondFactor::PURPOSE_LOGIN, '   '));
    }
}
