<?php
// tests/Integration/MailerSitzungTest.php

namespace Tests\Integration;

use App\Database;
use App\Security\Crypto;
use App\Service\Mailer;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeSmtpServer;

/**
 * Mehrere Mails über eine SMTP-Verbindung: Mailer::inEinerSitzung()
 * ([Addons #223](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/223)).
 *
 * Ohne die Klammer baut jede Mail ihre eigene Sitzung auf - Verbindung,
 * STARTTLS, AUTH LOGIN, QUIT. Hundert Zugangsdaten-Mails nach einer
 * Kontenanlage waren hundert Verbindungen in einer Anfrage.
 *
 * Gezählt wird, was der Server sieht: tests/Support/fake-smtp-server.php
 * hinterlässt je angenommener Verbindung eine Datei. Den Nachrichten selbst
 * sieht man nicht an, über wie viele Verbindungen sie kamen.
 *
 * Der zweite Teil gilt den Fehlern, denn eine offene Verbindung ist ein
 * Zustand, und ein Zustand kann hängen bleiben: Eine abgelehnte Mail darf
 * weder die Verbindung kosten noch ihren Absender an die nächste vererben,
 * ein Abriss muss zu einer neuen Verbindung führen, und ein Server, der
 * nicht antwortet, darf nicht für jede weitere Mail erneut abgewartet werden.
 *
 * Dateiname sortiert hinter DatabaseTest.php - siehe MatchSuggestionFinderTest
 * für den Grund.
 */
class MailerSitzungTest extends TestCase {

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
        FakeSmtpServer::clear();
        $this->smtpKonfigurieren(FakeSmtpServer::port());
    }

    private function smtpKonfigurieren(int $port): void {
        $stmt = self::$db->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        foreach ([
            'mail_driver' => 'smtp',
            'smtp_host' => FakeSmtpServer::host(),
            'smtp_port' => (string)$port,
            'smtp_encryption' => 'tls',
            'smtp_user' => 'absender@example.com',
            'smtp_pass' => Crypto::encrypt('geheim'),
            'mail_from_email' => 'absender@example.com',
            'mail_from_name' => 'Sitzungstest',
        ] as $schluessel => $wert) {
            $stmt->execute([$schluessel, $wert]);
        }
    }

    /** Ein Port, auf dem niemand lauscht. */
    private function toterPort(): int {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($s);
        $name = (string)stream_socket_get_name($s, false);
        fclose($s);

        return (int)substr($name, strrpos($name, ':') + 1);
    }

    /**
     * Die Umschlag-Empfänger der eingegangenen Nachrichten, in der
     * Reihenfolge des Eingangs.
     *
     * @return list<string>
     */
    private function empfaenger(): array {
        $aus = [];
        foreach (FakeSmtpServer::messages() as $roh) {
            $this->assertSame(1, preg_match('/^X-Fake-Envelope-To: (.*)\r$/m', $roh, $m), $roh);
            $aus[] = $m[1];
        }

        return $aus;
    }

    /** @return list<string> Die Aktionen des Mail-Protokolls, in Reihenfolge */
    private function aktionen(): array {
        return self::$db->query("SELECT action FROM audit_logs WHERE category = 'email' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Wartet, bis der Server die erwartete Zahl an Nachrichten abgelegt hat. */
    private function nachrichtenAbwarten(int $anzahl): void {
        $this->assertTrue(
            FakeSmtpServer::waitForMessages($anzahl),
            "Erwartet waren {$anzahl} Nachricht(en), angekommen sind " . count(FakeSmtpServer::messages())
        );
    }

    /** Die Ausgangslage, gegen die alles Folgende gemessen wird. */
    public function testOhneSitzungBautJedeMailIhreEigeneVerbindungAuf(): void {
        $mailer = new Mailer();
        foreach (['eins@example.org', 'zwei@example.org', 'drei@example.org'] as $adresse) {
            $this->assertTrue($mailer->send($adresse, 'Betreff', '<p>Text</p>'));
        }

        $this->nachrichtenAbwarten(3);
        $this->assertSame(3, FakeSmtpServer::connections());
    }

    public function testInEinerSitzungGehenAlleMailsUeberEineVerbindung(): void {
        $mailer = new Mailer();
        $adressen = ['eins@example.org', 'zwei@example.org', 'drei@example.org'];

        $ergebnis = $mailer->inEinerSitzung(function (Mailer $m) use ($adressen): array {
            $aus = [];
            foreach ($adressen as $adresse) {
                $aus[] = $m->send($adresse, 'Betreff für ' . $adresse, '<p>Text</p>');
            }
            return $aus;
        });

        $this->assertSame([true, true, true], $ergebnis, 'inEinerSitzung() reicht den Rückgabewert durch.');
        $this->nachrichtenAbwarten(3);
        $this->assertSame(1, FakeSmtpServer::connections(), 'Drei Mails, eine Verbindung.');
        $this->assertSame($adressen, $this->empfaenger(), 'Jede Nachricht trägt genau ihren eigenen Empfänger.');
        $this->assertSame(
            ['E-Mail versendet (SMTP)', 'E-Mail versendet (SMTP)', 'E-Mail versendet (SMTP)'],
            $this->aktionen(),
            'Das Protokoll schreibt weiterhin je Mail einen Eintrag.'
        );

        // Nach der Klammer ist die Verbindung geschlossen: Die nächste Mail
        // baut ihre eigene auf.
        $this->assertTrue($mailer->send('vier@example.org', 'Betreff', '<p>Text</p>'));
        $this->nachrichtenAbwarten(4);
        $this->assertSame(2, FakeSmtpServer::connections());
    }

    /**
     * Auch die fertigen send…()-Methoden laufen über die offene Verbindung -
     * dafür ist die Klammer da: Der Aufrufer muss keine Mail selbst bauen.
     */
    public function testDieFertigenMailsNutzenDieSitzung(): void {
        $mailer = new Mailer();

        $mailer->inEinerSitzung(function (Mailer $m): void {
            $this->assertTrue($m->sendWelcomeEmail('anna@example.org', 'anna', 'Geheim123!'));
            $this->assertTrue($m->sendWelcomeEmail('bert@example.org', 'bert', 'Geheim456!'));
            $this->assertTrue($m->sendKontoHinweis('carl@example.org', 'Hinweis', 'Text'));
        });

        $this->nachrichtenAbwarten(3);
        $this->assertSame(1, FakeSmtpServer::connections());
        $this->assertSame(['anna@example.org', 'bert@example.org', 'carl@example.org'], $this->empfaenger());
    }

    /**
     * Der Server lehnt den zweiten Empfänger ab. Das kostet weder die
     * Verbindung noch vererbt es etwas an die dritte Mail.
     */
    public function testEineAbgelehnteMailStoertDieSitzungNicht(): void {
        $mailer = new Mailer();

        $ergebnis = $mailer->inEinerSitzung(static fn(Mailer $m): array => [
            $m->send('eins@example.org', 'Betreff', '<p>Text</p>'),
            $m->send('abgelehnt@example.org', 'Betreff', '<p>Text</p>'),
            $m->send('drei@example.org', 'Betreff', '<p>Text</p>'),
        ]);

        $this->assertSame([true, false, true], $ergebnis);
        $this->nachrichtenAbwarten(2);
        $this->assertSame(1, FakeSmtpServer::connections(), 'Die Ablehnung einer Mail kostet nicht die Verbindung.');
        $this->assertSame(['eins@example.org', 'drei@example.org'], $this->empfaenger());
        $this->assertSame(
            ['E-Mail versendet (SMTP)', 'SMTP Fehler: Abbruch bei RCPT TO', 'E-Mail versendet (SMTP)'],
            $this->aktionen()
        );
    }

    /**
     * Der Server legt mitten in der zweiten Mail auf. Sie gilt als nicht
     * versendet; die dritte baut eine neue Verbindung auf und kommt an.
     */
    public function testNachEinemAbrissVerbindetDieNaechsteMailNeu(): void {
        $mailer = new Mailer();

        $ergebnis = $mailer->inEinerSitzung(static fn(Mailer $m): array => [
            $m->send('eins@example.org', 'Betreff', '<p>Text</p>'),
            $m->send('trennt@example.org', 'Betreff', '<p>Text</p>'),
            $m->send('drei@example.org', 'Betreff', '<p>Text</p>'),
        ]);

        $this->assertSame([true, false, true], $ergebnis);
        $this->nachrichtenAbwarten(2);
        $this->assertSame(2, FakeSmtpServer::connections(), 'Nach dem Abriss genau eine neue Verbindung.');
        $this->assertSame(['eins@example.org', 'drei@example.org'], $this->empfaenger());
    }

    /**
     * Antwortet der Server gar nicht, wartete früher jede Mail ihre eigene
     * Zeitgrenze ab. Eine Sitzung gibt nach zwei gescheiterten Aufbauten auf:
     * Die übrigen Mails schlagen sofort fehl - jede mit ihrem Eintrag im
     * Protokoll, damit sichtbar bleibt, welche fehlen.
     */
    public function testNachZweiGescheitertenVerbindungenGibtDieSitzungAuf(): void {
        $this->smtpKonfigurieren($this->toterPort());
        $mailer = new Mailer();

        $ergebnis = $mailer->inEinerSitzung(static fn(Mailer $m): array => [
            $m->send('eins@example.org', 'Betreff', '<p>Text</p>'),
            $m->send('zwei@example.org', 'Betreff', '<p>Text</p>'),
            $m->send('drei@example.org', 'Betreff', '<p>Text</p>'),
            $m->send('vier@example.org', 'Betreff', '<p>Text</p>'),
        ]);

        $this->assertSame([false, false, false, false], $ergebnis);

        $zeilen = self::$db->query("SELECT action, details FROM audit_logs WHERE category = 'email' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame(array_fill(0, 4, 'SMTP Fehler: Abbruch bei Verbindung'), array_column($zeilen, 'action'));
        $nichtVersucht = array_map(
            static fn(array $z): bool => str_contains((string)$z['details'], 'nicht versucht'),
            $zeilen
        );
        $this->assertSame([false, false, true, true], $nichtVersucht, 'Zwei Versuche, danach keiner mehr.');
        foreach ($zeilen as $zeile) {
            $this->assertStringNotContainsString('@', (string)$zeile['details'], 'Keine Adresse im Protokoll (Audit N17).');
        }

        // Die NÄCHSTE Sitzung versucht es wieder - das Aufgeben gilt nicht
        // für die Lebensdauer des Objekts.
        self::$db->exec("DELETE FROM audit_logs");
        $mailer->inEinerSitzung(static fn(Mailer $m): bool => $m->send('fuenf@example.org', 'Betreff', '<p>Text</p>'));
        $spaeter = self::$db->query("SELECT details FROM audit_logs WHERE category = 'email'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertCount(1, $spaeter);
        $this->assertStringNotContainsString('nicht versucht', (string)$spaeter[0]);
    }

    /** Verschachtelte Klammern teilen sich die Verbindung der äußersten. */
    public function testVerschachtelteSitzungenTeilenSichDieVerbindung(): void {
        $mailer = new Mailer();

        $mailer->inEinerSitzung(function (Mailer $m): void {
            $this->assertTrue($m->send('eins@example.org', 'Betreff', '<p>Text</p>'));
            $m->inEinerSitzung(function (Mailer $innen): void {
                $this->assertTrue($innen->send('zwei@example.org', 'Betreff', '<p>Text</p>'));
            });
            // Die innere Klammer hat die Verbindung nicht geschlossen.
            $this->assertTrue($m->send('drei@example.org', 'Betreff', '<p>Text</p>'));
        });

        $this->nachrichtenAbwarten(3);
        $this->assertSame(1, FakeSmtpServer::connections());
    }

    /** Wirft der Aufrufer, wird die Verbindung trotzdem geschlossen. */
    public function testEineAusnahmeSchliesstDieSitzung(): void {
        $mailer = new Mailer();

        try {
            $mailer->inEinerSitzung(function (Mailer $m): void {
                $m->send('eins@example.org', 'Betreff', '<p>Text</p>');
                throw new \RuntimeException('Abbruch im Aufrufer');
            });
            $this->fail('Die Ausnahme des Aufrufers muss durchschlagen.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Abbruch im Aufrufer', $e->getMessage());
        }

        // Der Testserver bedient eine Verbindung nach der anderen: Hinge die
        // alte noch, bliebe diese Mail an der Begrüßung hängen.
        $this->assertTrue($mailer->send('zwei@example.org', 'Betreff', '<p>Text</p>'));
        $this->nachrichtenAbwarten(2);
        $this->assertSame(2, FakeSmtpServer::connections());
    }
}
