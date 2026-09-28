<?php
// tests/Integration/LoginSessionTest.php

namespace Tests\Integration;

use App\Service\InstallEpoch;
use App\Service\LoginSession;
use PHPUnit\Framework\TestCase;

/**
 * Die eine Sitzungsregel (Audit M24, N14, N44): LoginSession::validate()
 * und die leichte Form currentUserId() gegen eine echte Datenbank.
 *
 * $_SESSION wird als Array gesetzt, ohne session_start() - validate() und
 * currentUserId() dürfen nur lesen bzw. Schlüssel entfernen, nie rotieren.
 * failOnWarning fängt ein versehentliches session_regenerate_id() ohne
 * aktive Sitzung.
 */
class LoginSessionTest extends TestCase {

    use KontoTestbestand;

    private const EPOCHE = 'epoche-logsession-test-0000000000';

    private int $konto;

    protected function setUp(): void {
        self::$db->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        )->execute([InstallEpoch::SETTING, self::EPOCHE]);

        $this->konto = $this->kontoAnlegen('ls-' . bin2hex(random_bytes(4)));
        $_SERVER['HTTP_USER_AGENT'] = 'LoginSessionTest/1.0';
        // AuditLogger startet eine Sitzung, wenn keine läuft - und lädt dabei
        // $_SESSION neu. In der Anwendung läuft sie immer schon; hier wird
        // sie vorher gestartet, damit der Testzustand nicht verschwindet.
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        $_SESSION = $this->gueltigeSitzung();
    }

    protected function tearDown(): void {
        $_SESSION = [];
        unset($_SERVER['HTTP_USER_AGENT']);
        self::$db->prepare("DELETE FROM users WHERE id = ?")->execute([$this->konto]);
        self::$db->prepare("DELETE FROM settings WHERE setting_key = ?")->execute([InstallEpoch::SETTING]);
    }

    /** @return array<string, mixed> */
    private function gueltigeSitzung(): array {
        return [
            'user_id' => $this->konto,
            'username' => 'ls',
            'user_agent_hash' => hash('sha256', 'LoginSessionTest/1.0'),
            'session_version' => 1,
            InstallEpoch::SETTING => self::EPOCHE,
            'last_activity' => time(),
            'created_time' => time(),
            'csrf_token' => 'csrf-bleibt',
            'locale' => 'de',
        ];
    }

    public function testGueltigeSitzungLiefertDieId(): void {
        $this->assertNull(LoginSession::validate());
        $this->assertSame($this->konto, LoginSession::currentUserId());
        $this->assertSame($this->konto, $_SESSION['user_id'], 'Eine gültige Sitzung bleibt unverändert.');
    }

    public function testOhneAnmeldungNull(): void {
        $_SESSION = ['csrf_token' => 'x'];
        $this->assertSame(LoginSession::ANONYM, LoginSession::validate());
        $this->assertNull(LoginSession::currentUserId());
    }

    /**
     * @return array<string, array{0: callable(self): void, 1: string}>
     */
    public static function ungueltigeSitzungen(): array {
        return [
            'gelöscht' => [static function (self $t): void {
                self::$db->prepare("UPDATE users SET deleted_at = NOW() WHERE id = ?")->execute([$t->konto]);
            }, LoginSession::GELOESCHT],
            'gibt es nicht mehr' => [static function (self $t): void {
                $_SESSION['user_id'] = $t->konto + 100000;
            }, LoginSession::GELOESCHT],
            'deaktiviert' => [static function (self $t): void {
                self::$db->prepare("UPDATE users SET deactivated_at = NOW() WHERE id = ?")->execute([$t->konto]);
            }, LoginSession::DEAKTIVIERT],
            'Passwort geändert' => [static function (self $t): void {
                self::$db->prepare("UPDATE users SET session_version = session_version + 1 WHERE id = ?")->execute([$t->konto]);
            }, LoginSession::PASSWORT_GEAENDERT],
            'fremde Epoche' => [static function (self $t): void {
                self::$db->prepare("UPDATE settings SET setting_value = 'andere-installation' WHERE setting_key = ?")
                    ->execute([InstallEpoch::SETTING]);
            }, LoginSession::INSTALLATION],
            'Epoche fehlt in der Datenbank' => [static function (self $t): void {
                self::$db->prepare("DELETE FROM settings WHERE setting_key = ?")->execute([InstallEpoch::SETTING]);
            }, LoginSession::INSTALLATION],
            'Legacy-Sitzung ohne Epoche' => [static function (self $t): void {
                unset($_SESSION[InstallEpoch::SETTING]);
            }, LoginSession::INSTALLATION],
            'anderer Browser' => [static function (self $t): void {
                $_SERVER['HTTP_USER_AGENT'] = 'Fremd/2.0';
            }, LoginSession::FREMDER_BROWSER],
            'inaktiv' => [static function (self $t): void {
                $_SESSION['last_activity'] = time() - LoginSession::MAX_INAKTIVITAET - 5;
            }, LoginSession::INAKTIV],
        ];
    }

    /**
     * @param callable(self): void $verderben
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('ungueltigeSitzungen')]
    public function testUngueltigeSitzungVerliertIhreIdentitaet(callable $verderben, string $grund): void {
        $_SESSION['passkey_bestanden'] = $this->konto;
        $_SESSION['api_key_new_token'] = 'hv_klartext';
        $_SESSION[\App\Security\BackupCodes::SESSION] = ['code-1'];
        $_SESSION['pending_2fa_user_id'] = $this->konto;
        $verderben($this);

        $this->assertSame($grund, LoginSession::validate());
        $this->assertNull(LoginSession::currentUserId());

        foreach (['user_id', 'username', 'session_version', InstallEpoch::SETTING, 'passkey_bestanden',
                  'api_key_new_token', \App\Security\BackupCodes::SESSION, 'pending_2fa_user_id', 'last_activity'] as $schluessel) {
            $this->assertArrayNotHasKey($schluessel, $_SESSION, "{$schluessel} muss mit der Identität verschwinden");
        }
        $this->assertSame('csrf-bleibt', $_SESSION['csrf_token'], 'Das CSRF-Token gehört zur Sitzung, nicht zur Identität.');
        $this->assertSame('de', $_SESSION['locale']);

        // Danach ist die Sitzung anonym - ein zweiter Aufruf protokolliert nichts mehr.
        $this->assertNull(LoginSession::currentUserId());
    }

    public function testFremdeEpocheWirdProtokolliertOhneKontozuordnung(): void {
        $vorher = $this->auditAnzahl('Session beendet: Installation wurde zurückgesetzt');
        $_SESSION[InstallEpoch::SETTING] = 'alte-installation';

        $this->assertNull(LoginSession::currentUserId());

        $this->assertSame($vorher + 1, $this->auditAnzahl('Session beendet: Installation wurde zurückgesetzt'));
        $zeile = self::$db->query(
            "SELECT user_id, username, details FROM audit_logs
             WHERE action = 'Session beendet: Installation wurde zurückgesetzt' ORDER BY id DESC LIMIT 1"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertNull($zeile['user_id'], 'Die ID einer Alt-Sitzung kann nach einem Reset einem fremden Konto gehören.');
        $this->assertStringContainsString('ohne Anmeldeschranke erkannt', (string)$zeile['details']);
    }

    /**
     * Die leichte Form rotiert nicht und verlängert nichts (Audit N44).
     */
    public function testCurrentUserIdRotiertNichtUndSchreibtKeineAktivitaet(): void {
        $_SESSION['last_token_rotation'] = time() - 5000;
        $_SESSION['last_activity'] = time() - 100;
        $vorher = $_SESSION;

        $this->assertSame($this->konto, LoginSession::currentUserId());

        $this->assertSame($vorher, $_SESSION);
    }

    public function testForgetIdentityBehaeltCsrfUndSprache(): void {
        $_SESSION['twofa_reauth'] = ['user_id' => $this->konto, 'at' => time()];
        LoginSession::forgetIdentity();
        $this->assertSame(['csrf_token' => 'csrf-bleibt', 'locale' => 'de'], $_SESSION);
    }

    public function testBeginSecondFactorMerktDieEpoche(): void {
        $_SESSION = [];
        LoginSession::beginSecondFactor($this->konto);
        $this->assertSame($this->konto, $_SESSION['pending_2fa_user_id']);
        $this->assertSame(self::EPOCHE, $_SESSION[InstallEpoch::SETTING]);
        $this->assertTrue(InstallEpoch::matches(self::EPOCHE));
        $this->assertFalse(InstallEpoch::matches('andere'));

        LoginSession::beginSecondFactor($this->konto, 'aus-provision');
        $this->assertSame('aus-provision', $_SESSION[InstallEpoch::SETTING]);
    }

    public function testInstallEpochRenewErzeugtNeuenWert(): void {
        $neu = InstallEpoch::renew(self::$db);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $neu);
        $this->assertSame($neu, InstallEpoch::current(self::$db));
        $this->assertSame(LoginSession::INSTALLATION, LoginSession::validate());
    }
}
