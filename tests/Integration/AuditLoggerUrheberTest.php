<?php
// tests/Integration/AuditLoggerUrheberTest.php

namespace Tests\Integration;

use App\Service\AuditLogger;
use PHPUnit\Framework\TestCase;

/**
 * Urheber eines Audit-Eintrags, wenn nur die Benutzer-ID übergeben wird
 * (Audit N64).
 *
 * Der Anlass: AuthController::sendeAnmeldecode() protokolliert in der
 * Pending-2FA-Phase mit `$userId`, aber ohne Namen - und die Session trägt
 * dort absichtlich keinen Namen. Der Eintrag stand deshalb unter "SYSTEM"
 * und fehlte beim Filtern nach dem Benutzer.
 */
class AuditLoggerUrheberTest extends TestCase {

    use KontoTestbestand;

    protected function setUp(): void {
        self::$db->exec("DELETE FROM users");
        self::$db->exec("DELETE FROM audit_logs");
        // AuditLogger startet eine Sitzung, wenn keine läuft - und lädt dabei
        // $_SESSION neu. Vorher starten, damit der Testzustand bleibt.
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void {
        $_SESSION = [];
    }

    /** @return array{user_id: mixed, username: mixed} */
    private function letzterEintrag(): array {
        $zeile = self::$db->query("SELECT user_id, username FROM audit_logs ORDER BY id DESC LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($zeile, 'Es wurde kein Eintrag geschrieben.');
        return $zeile;
    }

    public function testNurDieIdLaedtDenNamenAusDerDatenbank(): void {
        $max = $this->kontoAnlegen('max');

        AuditLogger::log('x', 'auth', null, $max);

        $e = $this->letzterEintrag();
        $this->assertSame($max, (int)$e['user_id']);
        $this->assertSame('max', $e['username']);
    }

    /** Der Name wird NICHT in die Session geschrieben - das hieße, die Anmeldung vorwegzunehmen. */
    public function testDerNachgeladeneNameLandetNichtInDerSession(): void {
        $max = $this->kontoAnlegen('max');
        $_SESSION = ['pending_2fa_user_id' => $max];

        AuditLogger::log('x', 'auth', null, $max);

        $this->assertSame('max', $this->letzterEintrag()['username']);
        $this->assertArrayNotHasKey('username', $_SESSION);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testGehoertDieSessionDemBenutzerKommtDerNameAusIhr(): void {
        $max = $this->kontoAnlegen('max');
        $_SESSION = ['user_id' => $max, 'username' => 'max-aus-session'];

        AuditLogger::log('x', 'auth', null, $max);

        $this->assertSame('max-aus-session', $this->letzterEintrag()['username']);
    }

    /** Eine fremde Session darf den Namen nicht liefern. */
    public function testEineFremdeSessionLiefertNichtDenNamen(): void {
        $anna = $this->kontoAnlegen('anna');
        $bernd = $this->kontoAnlegen('bernd');
        $_SESSION = ['user_id' => $anna, 'username' => 'anna'];

        AuditLogger::log('x', 'auth', null, $bernd);

        $e = $this->letzterEintrag();
        $this->assertSame($bernd, (int)$e['user_id']);
        $this->assertSame('bernd', $e['username']);
    }

    public function testUnbekannteIdWirdUnbekannt(): void {
        AuditLogger::log('x', 'auth', null, 999999);

        $e = $this->letzterEintrag();
        $this->assertSame(999999, (int)$e['user_id']);
        $this->assertSame('Unbekannt', $e['username']);
    }

    /** Explizit übergebenes SYSTEM (Mailer, Cron) bleibt unverändert. */
    public function testExplizitesSystemBleibt(): void {
        $_SESSION = ['user_id' => $this->kontoAnlegen('max'), 'username' => 'max'];

        AuditLogger::log('x', 'email', null, null, 'SYSTEM');

        $e = $this->letzterEintrag();
        $this->assertNull($e['user_id']);
        $this->assertSame('SYSTEM', $e['username']);
    }

    /** Eine übergebene 0 bleibt wie bisher SYSTEM, nicht "Unbekannt". */
    public function testNullAlsIdBleibtSystem(): void {
        AuditLogger::log('x', 'auth', null, 0);

        $e = $this->letzterEintrag();
        $this->assertNull($e['user_id']);
        $this->assertSame('SYSTEM', $e['username']);
    }

    /** Ein übergebener Name hat weiterhin Vorrang. */
    public function testUebergebenerNameGilt(): void {
        $max = $this->kontoAnlegen('max');

        AuditLogger::log('x', 'auth', null, $max, 'anders');

        $this->assertSame('anders', $this->letzterEintrag()['username']);
    }
}
