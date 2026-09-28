<?php
// tests/Integration/MailerTokenLinkTest.php

namespace Tests\Integration;

use App\Database;
use App\Security\Crypto;
use App\Service\Mailer;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeSmtpServer;

/**
 * Token-Links in Mails nur mit fester Stamm-URL (Audit M6) - mit echtem
 * Versand gegen Tests\Support\FakeSmtpServer, damit "verweigert" wirklich
 * "nichts kam an" heißt und nicht bloß "SMTP war nicht konfiguriert".
 *
 * Der Angriff: Host-Header evil.example, keine base_url, kein APP_URL, kein
 * TRUSTED_HOSTS. Vorher ging die Reset-Mail mit einem Link auf evil.example
 * hinaus.
 *
 * Dateiname bewusst nicht mit "B"/"Da" beginnend (siehe DatabaseTest).
 */
class MailerTokenLinkTest extends TestCase {

    private static PDO $db;

    private string|false $appUrlVorher = false;
    private string|false $trustedHostsVorher = false;
    private string|false $errorLogVorher = false;

    public static function setUpBeforeClass(): void {
        if (!defined('DB_HOST')) {
            self::markTestSkipped('Keine Test-Datenbank konfiguriert (DB_HOST fehlt) - siehe tests/bootstrap.php.');
        }

        $setupPdo = new PDO(
            Database::buildDsn(DB_HOST, DB_PORT, DB_NAME),
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        if ((int)$setupPdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'settings'")->fetchColumn() === 0) {
            try {
                $setupPdo->exec(file_get_contents(__DIR__ . '/../../database/schema.sql'));
            } catch (PDOException $e) {
                // Ignorieren, analog zu SetupController::provision()
            }
        }
        \App\Service\SchemaMigrator::run($setupPdo);

        self::$db = Database::getInstance();
        FakeSmtpServer::ensureStarted();
    }

    protected function setUp(): void {
        $this->appUrlVorher = getenv('APP_URL');
        $this->trustedHostsVorher = getenv('TRUSTED_HOSTS');
        putenv('APP_URL');
        putenv('TRUSTED_HOSTS');
        $_SERVER['HTTP_HOST'] = 'evil.example';
        // Die Verweigerung schreibt bewusst ins error_log - hier nicht in die
        // Testausgabe.
        $this->errorLogVorher = ini_set('error_log', '/dev/null');

        self::$db->exec("DELETE FROM settings WHERE setting_key LIKE 'smtp_%' OR setting_key LIKE 'mail_%' OR setting_key IN ('base_url', 'admin_notification_email')");
        self::$db->exec("DELETE FROM audit_logs");
        $this->setSettings([
            'mail_driver' => 'smtp',
            'smtp_host' => FakeSmtpServer::host(),
            'smtp_port' => (string)FakeSmtpServer::port(),
            'smtp_encryption' => 'tls',
            'smtp_user' => 'absender@example.com',
            'smtp_pass' => Crypto::encrypt('geheim'),
            'mail_from_email' => 'absender@example.com',
            'admin_notification_email' => 'dsgvo-admin@example.com',
        ]);
        FakeSmtpServer::clear();
    }

    protected function tearDown(): void {
        putenv($this->appUrlVorher === false ? 'APP_URL' : 'APP_URL=' . $this->appUrlVorher);
        putenv($this->trustedHostsVorher === false ? 'TRUSTED_HOSTS' : 'TRUSTED_HOSTS=' . $this->trustedHostsVorher);
        unset($_SERVER['HTTP_HOST']);
        ini_set('error_log', $this->errorLogVorher === false ? '' : $this->errorLogVorher);
        self::$db->exec("DELETE FROM settings WHERE setting_key LIKE 'smtp_%' OR setting_key LIKE 'mail_%' OR setting_key IN ('base_url', 'admin_notification_email')");
    }

    public function testOhneFesteStammUrlGehtKeineResetMailRaus(): void {
        $this->assertFalse((new Mailer())->sendPasswordResetEmail('opfer@example.com', str_repeat('a', 64)));

        usleep(300_000);
        $this->assertSame([], FakeSmtpServer::messages(), 'Es darf keine Mail beim SMTP-Server ankommen.');
        $this->assertSame(1, $this->verweigerungen(), 'Die Verweigerung gehört ins Audit-Log.');
    }

    public function testOhneFesteStammUrlAuchKeineVerifizierungUndKeineAdressbestaetigung(): void {
        $mailer = new Mailer();
        $this->assertFalse($mailer->sendEmailVerification('neu@example.com', str_repeat('b', 64)));
        $this->assertFalse($mailer->sendProfileEmailChangeConfirmation('neu@example.com', str_repeat('c', 64)));

        usleep(300_000);
        $this->assertSame([], FakeSmtpServer::messages());
        $this->assertSame(2, $this->verweigerungen());
    }

    public function testMitBaseUrlZeigtDerLinkAufDieFesteAdresse(): void {
        $this->setSettings(['base_url' => 'https://verband.example/']);
        $token = str_repeat('d', 64);

        $this->assertTrue((new Mailer())->sendPasswordResetEmail('opfer@example.com', $token));

        $this->assertTrue(FakeSmtpServer::waitForMessages(1));
        $body = $this->decodeBody(FakeSmtpServer::messages()[0]);
        $this->assertStringContainsString('https://verband.example/reset-password?token=' . $token, $body);
        $this->assertStringNotContainsString('evil.example', $body);
        $this->assertSame(0, $this->verweigerungen());
    }

    public function testMitAppUrlAusDerUmgebung(): void {
        putenv('APP_URL=https://env-verband.example');

        $this->assertTrue((new Mailer())->sendEmailVerification('neu@example.com', str_repeat('e', 64)));

        $this->assertTrue(FakeSmtpServer::waitForMessages(1));
        $body = $this->decodeBody(FakeSmtpServer::messages()[0]);
        $this->assertStringContainsString('https://env-verband.example/verify-email?token=', $body);
        $this->assertStringNotContainsString('evil.example', $body);
    }

    public function testMitAllowlistNurDerGelisteteHost(): void {
        putenv('TRUSTED_HOSTS=verband.example');

        $this->assertFalse((new Mailer())->sendPasswordResetEmail('opfer@example.com', str_repeat('f', 64)), 'evil.example steht nicht auf der Liste.');

        $_SERVER['HTTP_HOST'] = 'verband.example';
        $this->assertTrue((new Mailer())->sendProfileEmailChangeConfirmation('neu@example.com', str_repeat('g', 64)));

        $this->assertTrue(FakeSmtpServer::waitForMessages(1));
        $this->assertCount(1, FakeSmtpServer::messages());
        $this->assertStringContainsString('http://verband.example/profil/email/bestaetigen?token=', $this->decodeBody(FakeSmtpServer::messages()[0]));
    }

    /**
     * Die DSGVO-Mail ist anonym auslösbar und geht an Admins. Sie kommt
     * weiterhin an (Fristen), aber ohne Link auf den fremden Host.
     */
    public function testDsgvoMailOhneFesteStammUrlOhneFremdenLink(): void {
        $this->assertTrue((new Mailer())->sendDsgvoNotification('anfrage@example.com', 'Auskunft', 'Bitte um Auskunft'));

        $this->assertTrue(FakeSmtpServer::waitForMessages(1));
        $body = $this->decodeBody(FakeSmtpServer::messages()[0]);
        $this->assertStringNotContainsString('evil.example', $body);
        $this->assertStringContainsString('DSGVO Anfragen', $body);
    }

    public function testDsgvoMailMitFesterStammUrlMitLink(): void {
        $this->setSettings(['base_url' => 'https://verband.example/']);

        $this->assertTrue((new Mailer())->sendDsgvoNotification('anfrage@example.com', 'Auskunft'));

        $this->assertTrue(FakeSmtpServer::waitForMessages(1));
        $this->assertStringContainsString('https://verband.example/admin/gdpr', $this->decodeBody(FakeSmtpServer::messages()[0]));
    }

    /**
     * getBaseUrl() bleibt für Mails ohne Token und für Addons
     * (kontaktanfrage) unverändert.
     */
    public function testGetBaseUrlBleibtUnveraendert(): void {
        $this->setSettings(['base_url' => 'https://verband.example']);
        $this->assertSame('https://verband.example/', (new Mailer())->getBaseUrl());
    }

    private function verweigerungen(): int {
        $stmt = self::$db->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = ? AND category = 'email'");
        $stmt->execute([Mailer::AUDIT_VERSAND_VERWEIGERT]);
        return (int)$stmt->fetchColumn();
    }

    /** @param array<string, string> $pairs */
    private function setSettings(array $pairs): void {
        $stmt = self::$db->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        foreach ($pairs as $key => $value) {
            $stmt->execute([$key, $value]);
        }
    }

    private function decodeBody(string $raw): string {
        $decoded = '';
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && preg_match('#^[A-Za-z0-9+/=]+$#', $line) === 1) {
                $decoded .= (string)base64_decode($line, true);
            }
        }
        return $decoded . $raw;
    }
}
