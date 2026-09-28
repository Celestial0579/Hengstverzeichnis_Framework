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

    use ParalleleArbeiter;

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

    private function versuche(): ?int {
        $wert = self::$db->query("SELECT attempts FROM email_2fa_codes")->fetchColumn();
        return $wert === false ? null : (int)$wert;
    }

    /**
     * Der Versuch wird VOR der Prüfung gebucht (Audit M20) - auch der
     * erfolgreiche. Ein falscher Code erhöht den Zähler um genau eins, und
     * mit der letzten freien Buchung gilt der richtige noch.
     */
    public function testVersuchWirdVorDerPruefungGebucht(): void {
        $code = EmailSecondFactor::issue($this->userId, EmailSecondFactor::PURPOSE_LOGIN);
        $falsch = $code === '000000' ? '111111' : '000000';

        $this->assertFalse(EmailSecondFactor::verify($this->userId, EmailSecondFactor::PURPOSE_LOGIN, $falsch));
        $this->assertSame(1, $this->versuche());

        self::$db->exec("UPDATE email_2fa_codes SET attempts = " . (EmailSecondFactor::MAX_ATTEMPTS - 1));
        $this->assertTrue(
            EmailSecondFactor::verify($this->userId, EmailSecondFactor::PURPOSE_LOGIN, $code),
            'Die letzte freie Buchung ist noch erlaubt.'
        );
    }

    /**
     * Zehn gleichzeitige Einlösungen desselben richtigen Codes: genau eine
     * gewinnt (Audit N43). Bisher lasen alle die Zeile, prüften und
     * löschten - jede meldete Erfolg.
     */
    public function testParalleleEinloesungGiltGenauEinmal(): void {
        $code = EmailSecondFactor::issue($this->userId, EmailSecondFactor::PURPOSE_LOGIN);

        $ausgaben = $this->parallelAusfuehren(array_fill(
            0,
            10,
            ['mailcode', (string)$this->userId, EmailSecondFactor::PURPOSE_LOGIN, $code]
        ));

        $this->assertSame(1, self::anzahl($ausgaben, 'OK'), implode('', $ausgaben));
        $this->assertSame(0, (int)self::$db->query("SELECT COUNT(*) FROM email_2fa_codes")->fetchColumn());
    }

    /**
     * Zwanzig gleichzeitige falsche Codes: Der Zähler je Code endet bei
     * MAX_ATTEMPTS, nicht darüber (Audit M20). Jede Prüfung setzt eine
     * gelungene Buchung voraus - höchstens MAX_ATTEMPTS Arbeiter kommen also
     * überhaupt zum password_verify().
     */
    public function testParalleleFehlversuchePassierenDieGrenzeNicht(): void {
        $code = EmailSecondFactor::issue($this->userId, EmailSecondFactor::PURPOSE_LOGIN);
        $falsch = $code === '000000' ? '111111' : '000000';

        $ausgaben = $this->parallelAusfuehren(array_fill(
            0,
            20,
            ['mailcode', (string)$this->userId, EmailSecondFactor::PURPOSE_LOGIN, $falsch]
        ));

        $this->assertSame(0, self::anzahl($ausgaben, 'OK'));
        $versuche = $this->versuche();
        $this->assertTrue(
            $versuche === null || $versuche <= EmailSecondFactor::MAX_ATTEMPTS,
            "Der Zähler steht bei {$versuche} - mehr Prüfungen als erlaubt."
        );
        $this->assertFalse(
            EmailSecondFactor::verify($this->userId, EmailSecondFactor::PURPOSE_LOGIN, $code),
            'Nach der Grenze gilt auch der richtige Code nicht mehr.'
        );
    }

    /**
     * Wird während einer laufenden Prüfung ein neuer Code ausgestellt, darf
     * das Ende dieser Prüfung den neuen nicht mit abräumen (Audit N43). Das
     * Löschen ist deshalb an den gelesenen Abdruck gebunden.
     *
     * Die laufende Prüfung wird über einen absichtlich teuren Abdruck
     * verlängert (bcrypt-Kosten 14); in dieser Zeit stellt der Test den
     * neuen Code aus.
     */
    public function testNeuAusgestellterCodeWirdNichtVerworfen(): void {
        EmailSecondFactor::issue($this->userId, EmailSecondFactor::PURPOSE_LOGIN);
        $alt = '424242';
        self::$db->prepare("UPDATE email_2fa_codes SET code_hash = ?")
            ->execute([password_hash($alt, PASSWORD_BCRYPT, ['cost' => 14])]);

        $lauf = $this->parallelStarten([
            ['mailcode', (string)$this->userId, EmailSecondFactor::PURPOSE_LOGIN, $alt],
        ]);
        self::wartenBis($lauf['start'] + 0.3);
        $neu = EmailSecondFactor::issue($this->userId, EmailSecondFactor::PURPOSE_LOGIN);
        $ausgaben = $this->einsammeln($lauf);

        $this->assertSame(0, self::anzahl($ausgaben, 'OK'), 'Der alte Code ist abgelöst: ' . implode('', $ausgaben));
        $this->assertSame(
            1,
            (int)self::$db->query("SELECT COUNT(*) FROM email_2fa_codes")->fetchColumn(),
            'Der neue Code muss stehen bleiben.'
        );
        $this->assertTrue(EmailSecondFactor::verify($this->userId, EmailSecondFactor::PURPOSE_LOGIN, $neu));
    }
}
