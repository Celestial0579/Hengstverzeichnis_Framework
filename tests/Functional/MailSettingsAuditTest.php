<?php
// tests/Functional/MailSettingsAuditTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Änderungen an den Mail-/SMTP-Einstellungen im Audit-Log (Audit N6).
 *
 * Wer Host, Benutzer oder Absender umstellt, leitet jede Mail des Systems -
 * Reset-Links, Anmeldecodes - über ein anderes Relay. Das muss
 * nachvollziehbar sein; vom Passwort steht nur, OB es geändert wurde.
 *
 * Die Suite teilt sich eine Datenbank: tearDown() stellt die Einstellungen
 * wieder her, sonst versuchten spätere Tests, über relay.boese.test zu
 * versenden.
 */
class MailSettingsAuditTest extends FunctionalTestCase {

    private const SCHLUESSEL = [
        'mail_driver', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user',
        'smtp_pass', 'mail_from_email', 'mail_from_name', 'admin_notification_email',
    ];

    /** @var array<string, string>|null */
    private ?array $vorher = null;

    protected function setUp(): void {
        $this->authenticatedClient();
        $this->vorher = [];
        $stmt = Database::getInstance()->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        foreach (self::SCHLUESSEL as $k) {
            $stmt->execute([$k]);
            $wert = $stmt->fetchColumn();
            if ($wert !== false) {
                $this->vorher[$k] = (string)$wert;
            }
        }
    }

    protected function tearDown(): void {
        if ($this->vorher !== null) {
            $db = Database::getInstance();
            foreach (self::SCHLUESSEL as $k) {
                if (array_key_exists($k, $this->vorher)) {
                    $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                        ->execute([$k, $this->vorher[$k]]);
                } else {
                    $db->prepare("DELETE FROM settings WHERE setting_key = ?")->execute([$k]);
                }
            }
            $this->vorher = null;
        }
        parent::tearDown();
    }

    /** @return array<string, string> */
    private function formular(array $ueberschreiben = []): array {
        return $ueberschreiben + [
            'mail_driver' => 'smtp',
            'smtp_host' => '',
            'smtp_port' => '587',
            'smtp_encryption' => 'tls',
            'smtp_user' => '',
            'smtp_pass' => '',
            'mail_from_email' => '',
            'mail_from_name' => '',
            'admin_notification_email' => '',
        ];
    }

    private function speichern(\Tests\Support\HttpClient $admin, array $felder): \Tests\Support\HttpResponse {
        $seite = $admin->get('/admin/mail-settings');
        return $admin->post('/admin/mail-settings', ['csrf_token' => $seite->formField('csrf_token') ?? ''] + $felder);
    }

    /** @return array<int, array{username: string, category: string, details: string}> */
    private function eintraegeSeit(int $id): array {
        $stmt = Database::getInstance()->prepare(
            "SELECT username, category, details FROM audit_logs WHERE id > ? AND action = 'Mail-Einstellungen aktualisiert' ORDER BY id"
        );
        $stmt->execute([$id]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function letzteId(): int {
        return (int)Database::getInstance()->query("SELECT COALESCE(MAX(id), 0) FROM audit_logs")->fetchColumn();
    }

    public function testAenderungWirdMitDiffUndOhnePasswortProtokolliert(): void {
        $admin = $this->authenticatedClient();
        $start = $this->letzteId();

        $antwort = $this->speichern($admin, $this->formular([
            'smtp_host' => 'relay.boese.test',
            'smtp_user' => 'relay@boese.test',
            'smtp_pass' => 'Geheim123!',
        ]));
        $this->assertSame('/admin/mail-settings?success=saved', $antwort->location(), "Body: {$antwort->body}");

        $eintraege = $this->eintraegeSeit($start);
        $this->assertCount(1, $eintraege);
        $e = $eintraege[0];
        $this->assertSame('settings', $e['category']);
        $this->assertSame((string)getenv('ADMIN_USERNAME'), $e['username']);
        $this->assertStringContainsString('smtp_host: "', $e['details']);
        $this->assertStringContainsString('" → "relay.boese.test"', $e['details']);
        $this->assertStringContainsString('Passwort geändert: ja', $e['details']);
        $this->assertStringNotContainsString('Geheim123!', $e['details']);

        $chiffrat = (string)Database::getInstance()->query("SELECT setting_value FROM settings WHERE setting_key = 'smtp_pass'")->fetchColumn();
        $this->assertNotSame('', $chiffrat);
        $this->assertStringNotContainsString($chiffrat, $e['details']);
        $this->assertStringNotContainsString('smtp_pass', $e['details']);

        // Zweites Speichern ohne Änderung und ohne Passwort: kein Scheindiff,
        // auch nicht beim Port (int geschrieben, String gelesen).
        $start = $this->letzteId();
        $this->speichern($admin, $this->formular([
            'smtp_host' => 'relay.boese.test',
            'smtp_user' => 'relay@boese.test',
        ]));
        $eintraege = $this->eintraegeSeit($start);
        $this->assertCount(1, $eintraege);
        $this->assertSame('Geändert: keine; Passwort geändert: nein', $eintraege[0]['details']);
    }

    public function testAbgelehnteEingabeErzeugtKeinenEintrag(): void {
        $admin = $this->authenticatedClient();
        $start = $this->letzteId();

        $antwort = $this->speichern($admin, $this->formular([
            'smtp_host' => 'relay.boese.test',
            'mail_from_email' => 'keine-adresse',
        ]));
        $this->assertSame('/admin/mail-settings?error=invalid_mail_from_email', $antwort->location());
        $this->assertSame([], $this->eintraegeSeit($start));
    }
}
