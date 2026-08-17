<?php
// tests/Integration/UpdateNotificationMailTest.php

namespace Tests\Integration;

use App\Database;
use App\Security\Crypto;
use App\Service\Scheduler;
use App\Service\UpdateService;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeReleaseServer;
use Tests\Support\FakeSmtpServer;

/**
 * Der Mailversand der Update-Benachrichtigung (#290), einmal wirklich
 * durchgeführt statt weggemockt.
 *
 * Warum das nötig ist: Die übrigen Tests dieser Reihe laufen ohne
 * SMTP-Konfiguration, da liefert App\Service\Mailer::send() kontrolliert
 * `false`. Damit lässt sich der Verweigerungsfall prüfen - aber nie, ob im
 * Erfolgsfall tatsächlich eine Mail entsteht, was drinsteht und ob der
 * Merkzettel danach richtig fortgeschrieben wird. Genau dort saß der Fehler,
 * den die Selbstprüfung des PRs gefunden hat (Fund als gemeldet vermerkt,
 * obwohl nichts hinausging); ein Test, der den Erfolgsweg nie geht, hätte ihn
 * auch danach nicht bemerkt.
 *
 * Beide Versandwege werden abgedeckt, denn beide sind in Installationen im
 * Einsatz:
 *
 * - `smtp` (Vorgabe) gegen Tests\Support\FakeSmtpServer - eine echte
 *   TLS-Strecke, weil der Mailer unverschlüsselten Versand grundsätzlich
 *   ablehnt und das Zertifikat vollständig prüft.
 * - `mail` (PHPs mail()) über einen Unterprozess mit umgebogenem
 *   `sendmail_path`. Diese Einstellung ist PHP_INI_SYSTEM und lässt sich zur
 *   Laufzeit nicht setzen - deshalb der eigene Prozess statt eines Kniffs im
 *   laufenden.
 *
 * Dateiname bewusst nicht mit "B"/"Da" beginnend: würde alphabetisch vor
 * DatabaseTest.php laufen und dessen Anforderung brechen, der erste Aufrufer
 * von App\Database::getInstance() im Prozess zu sein (siehe Klassendoc dort).
 */
class UpdateNotificationMailTest extends TestCase {

    private const RECIPIENT = 'update-mail-admin@example.com';

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

        FakeReleaseServer::ensureStarted();
        FakeSmtpServer::ensureStarted();
    }

    protected function setUp(): void {
        Scheduler::resetForTests();
        self::$db->exec("DELETE FROM settings WHERE setting_key LIKE 'update_%' OR setting_key LIKE 'smtp_%' OR setting_key LIKE 'mail_%' OR setting_key LIKE 'cron_last_run__%'");
        self::$db->exec("DELETE FROM audit_logs");
        self::$db->exec("DELETE FROM users WHERE username LIKE 'mail-admin-%'");
        $this->insertAdminUser(self::RECIPIENT);
        // Siehe UpdateRunTest: sonst ruft refreshOfficialCatalog() live GitHub ab.
        self::$db->exec("UPDATE addon_repos SET cached_catalog_json = '[]', cached_at = NOW() WHERE is_official = 1");
        FakeReleaseServer::clear();
        FakeSmtpServer::clear();
    }

    protected function tearDown(): void {
        putenv('UPDATE_RELEASES_URL');
        putenv('ADDON_RELEASES_URL');
    }

    /**
     * Der Erfolgsweg über SMTP: Es entsteht eine echte Nachricht, sie geht an
     * das Admin-Konto, sie nennt die neue Version - und erst danach gilt der
     * Fund als gemeldet.
     */
    public function testNotificationIsActuallyDeliveredOverSmtp(): void {
        $this->configureSmtp();
        $this->publishRelease('9.9.9');

        UpdateService::runCheckAndNotify();

        $this->assertTrue(
            FakeSmtpServer::waitForMessages(1),
            'Es hätte eine Nachricht beim SMTP-Server ankommen müssen.'
        );

        $messages = FakeSmtpServer::messages();
        $this->assertCount(1, $messages, 'Genau ein Admin-Konto, also genau eine Mail.');
        $raw = $messages[0];

        $this->assertStringContainsString(self::RECIPIENT, $raw, 'Empfänger im Umschlag');
        $this->assertStringContainsString('9.9.9', $this->decodeBody($raw), 'Die neue Version gehört in die Mail.');
        $this->assertStringContainsString(
            'Update verfügbar',
            $this->decodeSubject($raw),
            'Betreff soll ohne Öffnen erkennen lassen, worum es geht.'
        );

        // Und die andere Hälfte: Erst der geglückte Versand macht den Fund
        // zu einem gemeldeten.
        $this->assertSame('9.9.9', $this->getSetting('update_last_notified_version'));
    }

    /**
     * Die Nutzeranforderung "nur einmal pro Fund", diesmal vollständig
     * durchgespielt: erster Lauf verschickt, zweiter Lauf schweigt - und zwar
     * nicht, weil nichts verfügbar wäre, sondern weil der Fund bekannt ist.
     */
    public function testTheSameFindingIsNotMailedTwice(): void {
        $this->configureSmtp();
        $this->publishRelease('9.9.9');

        UpdateService::runCheckAndNotify();
        $this->assertTrue(FakeSmtpServer::waitForMessages(1));

        UpdateService::runCheckAndNotify();
        // Kurz warten, damit eine fälschlich zweite Mail auch wirklich Zeit
        // hätte anzukommen - sonst wäre der Test grün, bloß weil er zu schnell
        // hinsieht.
        usleep(500_000);

        $this->assertCount(
            1,
            FakeSmtpServer::messages(),
            'Derselbe Fund darf kein zweites Mal gemeldet werden.'
        );
    }

    /**
     * Eine neuere Version ist ein neuer Fund und wird erneut zugestellt -
     * die Gegenprobe zum Test oben, damit "meldet nur einmal" nicht mit
     * "meldet nur ein einziges Mal überhaupt" verwechselt wird.
     */
    public function testANewerVersionIsMailedAgain(): void {
        $this->configureSmtp();
        $this->publishRelease('9.9.9');
        UpdateService::runCheckAndNotify();
        $this->assertTrue(FakeSmtpServer::waitForMessages(1));

        FakeReleaseServer::clear();
        $this->publishRelease('9.9.10');
        UpdateService::runCheckAndNotify();

        $this->assertTrue(FakeSmtpServer::waitForMessages(2), 'Die neuere Version gehört gemeldet.');

        $messages = FakeSmtpServer::messages();
        $this->assertCount(2, $messages);
        $this->assertStringContainsString(
            '9.9.10',
            $this->decodeBody(end($messages)),
            'Die zuletzt verschickte Mail muss die neuere Version nennen.'
        );
        $this->assertSame('9.9.10', $this->getSetting('update_last_notified_version'));
    }

    /**
     * Der zweite Versandweg: `mail_driver = 'mail'` nutzt PHPs mail() statt
     * eines eigenen SMTP-Clients. Zahlreiche Installationen (klassisches
     * Hosting) fahren so - ein Test nur des SMTP-Wegs hätte für sie keine
     * Aussagekraft.
     *
     * `sendmail_path` ist PHP_INI_SYSTEM und im laufenden Prozess nicht
     * änderbar, deshalb ein Unterprozess mit `-d`: mail() schreibt dort in
     * eine Datei statt an einen MTA.
     */
    public function testNotificationIsAlsoDeliveredViaPhpMailDriver(): void {
        $this->configureMailDriver();
        $this->publishRelease('9.9.9');

        $capture = tempnam(sys_get_temp_dir(), 'phpmail_');
        $runner = __DIR__ . '/../Support/send-update-notification.php';

        $process = proc_open(
            [
                PHP_BINARY,
                '-d', 'sendmail_path=cat >> ' . $capture,
                $runner,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            array_merge(getenv() ?: [], [
                'UPDATE_RELEASES_URL' => (string)getenv('UPDATE_RELEASES_URL'),
            ])
        );
        $this->assertIsResource($process, 'Unterprozess für den mail()-Weg ließ sich nicht starten.');

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        $this->assertSame(0, $exit, "Unterprozess scheiterte.\nstdout: {$stdout}\nstderr: {$stderr}");
        $this->assertStringContainsString('VERSAND=ok', $stdout, "Mailer meldete keinen Erfolg.\n{$stdout}\n{$stderr}");

        $raw = (string)file_get_contents($capture);
        @unlink($capture);

        $this->assertNotSame('', $raw, 'mail() hat nichts an sendmail übergeben.');
        $this->assertStringContainsString(self::RECIPIENT, $raw, 'Empfänger fehlt in der Nachricht.');
        $this->assertStringContainsString('9.9.9', $raw, 'Die neue Version gehört in die Mail.');
    }

    // ---- Helfer --------------------------------------------------------

    private function configureSmtp(): void {
        $this->setSettings([
            'mail_driver' => 'smtp',
            'smtp_host' => FakeSmtpServer::host(),
            'smtp_port' => (string)FakeSmtpServer::port(),
            'smtp_encryption' => 'tls',
            'smtp_user' => 'absender@example.com',
            'smtp_pass' => Crypto::encrypt('geheim'),
            'mail_from_email' => 'absender@example.com',
            'mail_from_name' => 'Hengstverzeichnis Test',
            'update_notify' => '1',
        ]);
    }

    private function configureMailDriver(): void {
        $this->setSettings([
            'mail_driver' => 'mail',
            'mail_from_email' => 'absender@example.com',
            'mail_from_name' => 'Hengstverzeichnis Test',
            'update_notify' => '1',
        ]);
    }

    /** @param array<string, string> $pairs */
    private function setSettings(array $pairs): void {
        $stmt = self::$db->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        foreach ($pairs as $key => $value) {
            $stmt->execute([$key, $value]);
        }
    }

    private function getSetting(string $key): ?string {
        $stmt = self::$db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function insertAdminUser(string $email): void {
        $stmt = self::$db->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, 'x')");
        $stmt->execute(['mail-admin-' . uniqid(), $email]);
        $userId = (int)self::$db->lastInsertId();

        $groupId = self::$db->query("SELECT id FROM `groups` WHERE slug = 'admin'")->fetchColumn();
        $stmt = self::$db->prepare("INSERT IGNORE INTO user_groups (user_id, group_id) VALUES (?, ?)");
        $stmt->execute([$userId, $groupId]);
    }

    private function publishRelease(string $version): void {
        $file = FakeReleaseServer::putFile('releases-' . $version . '.json', json_encode([[
            'tag_name' => 'v' . $version,
            'draft' => false,
            'prerelease' => false,
            'html_url' => 'https://example.invalid/releases/v' . $version,
            'assets' => [],
        ]]));
        putenv('UPDATE_RELEASES_URL=' . $file);
    }

    /** Der Mailer verschickt base64-kodierte MIME-Teile - hier wieder lesbar gemacht. */
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

    private function decodeSubject(string $raw): string {
        if (preg_match('/^Subject: (.*)$/mi', $raw, $m) !== 1) {
            return '';
        }
        $value = trim($m[1]);
        if (preg_match('/=\?UTF-8\?B\?(.*)\?=/i', $value, $b) === 1) {
            return (string)base64_decode($b[1], true);
        }
        return $value;
    }
}
