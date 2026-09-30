<?php
// tests/Integration/MailerSmtpAbbruchTest.php

namespace Tests\Integration;

use App\Database;
use App\Security\Crypto;
use App\Service\Mailer;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeSmtpServer;

/**
 * Das Mail-Protokoll gegen einen echten SMTP-Dialog (Audit N17, N71).
 *
 * - Abbrüche vor der Übergabe der Nachricht stehen mit Schritt und
 *   Serverantwort im Audit-Log - früher endeten sie still.
 * - Die Serverantwort zitiert bei einer RCPT-TO-Ablehnung die Adresse
 *   (tests/Support/fake-smtp-server.php tut das wie echte Server); im
 *   Protokoll darf sie trotzdem nicht landen.
 * - Der Anmeldecode steht im Betreff der Mail, aber nie im Protokoll.
 * - Die DSGVO-Benachrichtigung fällt bei geleertem Empfängerfeld auf den
 *   Absender zurück und bricht ohne gültige Adresse protokolliert ab.
 */
class MailerSmtpAbbruchTest extends TestCase {

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
        \App\Service\SchemaMigrator::run($setupPdo);

        self::$db = Database::getInstance();
        FakeSmtpServer::ensureStarted();
    }

    protected function setUp(): void {
        self::$db->exec("DELETE FROM settings WHERE setting_key LIKE 'smtp_%' OR setting_key LIKE 'mail_%' OR setting_key IN ('admin_notification_email', 'base_url')");
        self::$db->exec("DELETE FROM audit_logs");
        self::$db->exec("DELETE FROM users WHERE username LIKE 'smtpabbruch-%'");
        FakeSmtpServer::clear();
        $this->smtpKonfigurieren(FakeSmtpServer::port());
    }

    private function smtpKonfigurieren(int $port): void {
        $this->einstellungen([
            'mail_driver' => 'smtp',
            'smtp_host' => FakeSmtpServer::host(),
            'smtp_port' => (string)$port,
            'smtp_encryption' => 'tls',
            'smtp_user' => 'absender@example.com',
            'smtp_pass' => Crypto::encrypt('geheim'),
            'mail_from_email' => 'absender@example.com',
            'mail_from_name' => 'Abbruchtest',
        ]);
    }

    /** @param array<string, string> $paare */
    private function einstellungen(array $paare): void {
        $stmt = self::$db->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        foreach ($paare as $k => $v) {
            $stmt->execute([$k, $v]);
        }
    }

    /** @return array<int, array{action: string, details: string}> */
    private function protokoll(): array {
        return self::$db->query("SELECT action, details FROM audit_logs ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{action: string, details: string} */
    private function eintrag(string $aktion): array {
        foreach ($this->protokoll() as $e) {
            if ($e['action'] === $aktion) {
                return $e;
            }
        }
        $this->fail("Kein Eintrag '{$aktion}'. Vorhanden: " . json_encode($this->protokoll(), JSON_UNESCAPED_UNICODE));
    }

    public function testRcptAblehnungWirdOhneAdresseProtokolliert(): void {
        $this->assertFalse((new Mailer())->send('abgelehnt@example.test', 'Betreff 123456', '<p>x</p>'));

        $e = $this->eintrag('SMTP Fehler: Abbruch bei RCPT TO');
        $this->assertStringContainsString('550', $e['details']);
        $this->assertStringContainsString('Typ: allgemein', $e['details']);
        $this->assertStringContainsString('Empfänger: ' . Mailer::externeEmpfaengerReferenz('abgelehnt@example.test'), $e['details']);
        foreach ($this->protokoll() as $zeile) {
            $this->assertStringNotContainsString('@', (string)$zeile['details'], 'Keine Adresse im Protokoll: ' . $zeile['details']);
            $this->assertStringNotContainsString('abgelehnt', (string)$zeile['details']);
            $this->assertStringNotContainsString('123456', (string)$zeile['details']);
        }
    }

    public function testNichtErreichbarerServerWirdProtokolliert(): void {
        // Freien Port belegen und wieder freigeben: Dort lauscht niemand.
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($s);
        $name = (string)stream_socket_get_name($s, false);
        fclose($s);
        $this->smtpKonfigurieren((int)substr($name, strrpos($name, ':') + 1));

        $this->assertFalse((new Mailer())->send('jemand@example.test', 'x', '<p>x</p>'));

        $e = $this->eintrag('SMTP Fehler: Abbruch bei Verbindung');
        $this->assertStringContainsString('Host: 127.0.0.1:', $e['details']);
        $this->assertStringNotContainsString('@', $e['details']);
    }

    public function testAnmeldecodeStehtNurInDerMailNichtImProtokoll(): void {
        self::$db->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, 'x')")
            ->execute(['smtpabbruch-max', 'smtpabbruch-max@example.com']);
        $id = (int)self::$db->lastInsertId();

        $this->assertTrue((new Mailer())->sendSecondFactorCode('smtpabbruch-max@example.com', '483920', 10));
        $this->assertTrue(FakeSmtpServer::waitForMessages(1));

        $e = $this->eintrag('E-Mail versendet (SMTP)');
        $this->assertSame("Typ: anmeldecode, Empfänger: Benutzer #{$id}", $e['details']);
        foreach ($this->protokoll() as $zeile) {
            $this->assertStringNotContainsString('483920', (string)$zeile['details']);
            $this->assertStringNotContainsString('@', (string)$zeile['details']);
        }
    }

    public function testExternerEmpfaengerErscheintAlsReferenz(): void {
        $this->assertTrue((new Mailer())->send('station@example.org', 'Deckanfrage für Hengst X', '<p>x</p>'));

        $this->assertSame(
            'Typ: allgemein, Empfänger: ' . Mailer::externeEmpfaengerReferenz('station@example.org'),
            $this->eintrag('E-Mail versendet (SMTP)')['details']
        );
    }

    public function testLeererEmpfaengerWirdAbgelehnt(): void {
        $this->assertFalse((new Mailer())->send('  ', 'x', '<p>x</p>'));

        $this->eintrag('E-Mail-Versand abgelehnt (kein Empfänger)');
        usleep(300_000);
        $this->assertSame([], FakeSmtpServer::messages());
    }

    public function testDsgvoBenachrichtigungFaelltAufDenAbsenderZurueck(): void {
        $this->einstellungen(['admin_notification_email' => '', 'mail_from_email' => 'datenschutz@example.com']);

        $this->assertTrue((new Mailer())->sendDsgvoNotification('antrag@example.net', 'Auskunft', 'Hallo', 'Erika'));
        $this->assertTrue(FakeSmtpServer::waitForMessages(1));
        $this->assertStringContainsString('X-Fake-Envelope-To: datenschutz@example.com', FakeSmtpServer::messages()[0]);
        $this->assertStringStartsWith('Typ: dsgvo_benachrichtigung, Empfänger: extern:', $this->eintrag('E-Mail versendet (SMTP)')['details']);
    }

    public function testDsgvoBenachrichtigungOhneGueltigenEmpfaengerBrichtProtokolliertAb(): void {
        $this->einstellungen(['admin_notification_email' => '', 'mail_from_email' => '', 'smtp_user' => 'relayuser']);

        $this->assertFalse((new Mailer())->sendDsgvoNotification('antrag@example.net', 'Auskunft'));

        $e = $this->eintrag('DSGVO-Benachrichtigung nicht versendet');
        $this->assertStringContainsString('Kein gültiger Empfänger', $e['details']);
        usleep(300_000);
        $this->assertSame([], FakeSmtpServer::messages());
    }
}
