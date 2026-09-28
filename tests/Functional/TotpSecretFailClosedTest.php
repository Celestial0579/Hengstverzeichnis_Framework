<?php
// tests/Functional/TotpSecretFailClosedTest.php

namespace Tests\Functional;

use App\Security\Totp;

/**
 * TOTP-Secrets werden fail-closed gelesen (Audit N8).
 *
 * DER BEFUND. Ließ sich users.totp_secret nicht entschlüsseln - typisch nach
 * einem Wechsel des APP_KEY -, wurde der gespeicherte Rohwert selbst zum
 * Secret. Das war der Base64-Chiffretext, und den kennt jeder, der einen
 * Datenbank-Dump hat: Totp::getCode(<Chiffretext>) ergab einen gültigen Code.
 *
 * Nachgestellt wird der APP_KEY-Wechsel, indem das gespeicherte Secret durch
 * fremden Chiffretext ersetzt wird. Der Test berechnet GENAU den Code, den
 * der alte Pfad angenommen hätte, an allen drei Lesestellen.
 */
class TotpSecretFailClosedTest extends FunctionalTestCase {

    private const PASSWORT = 'EditorTestNeu456!';

    /** @var array<int, string> */
    private array $aufraeumen = [];

    protected function tearDown(): void {
        if ($this->aufraeumen !== []) {
            $stmt = \App\Database::getInstance()->prepare("DELETE FROM users WHERE username = ?");
            foreach ($this->aufraeumen as $name) {
                $stmt->execute([$name]);
            }
            $this->aufraeumen = [];
        }
        parent::tearDown();
    }

    /**
     * @return array{0: \Tests\Support\HttpClient, 1: string, 2: int, 3: string}
     *         angemeldeter Client, E-Mail, ID, gespeicherter (unlesbarer) Wert
     */
    private function editorMitUnlesbaremSecret(): array {
        $unique = uniqid();
        $username = "failclosed{$unique}";
        $email = "failclosed-{$unique}@example.com";
        $client = $this->createAndLoginEditor($this->authenticatedClient(), $username, $email);
        $this->aufraeumen[] = $username;

        $gespeichert = base64_encode(random_bytes(44));
        $db = \App\Database::getInstance();
        $db->prepare("UPDATE users SET totp_secret = ?, last_totp_timeslice = NULL WHERE username = ?")
            ->execute([$gespeichert, $username]);
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);

        return [$client, $email, (int)$stmt->fetchColumn(), $gespeichert];
    }

    private function auditEintraege(int $userId): int {
        $stmt = \App\Database::getInstance()->prepare(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'TOTP-Secret nicht lesbar' AND user_id = ?"
        );
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    public function testCodeAusDemChiffretextWirdBeimLoginAbgelehnt(): void {
        [, $email, $id, $gespeichert] = $this->editorMitUnlesbaremSecret();

        $client = $this->newClient();
        $login = $client->post('/login', [
            'csrf_token' => $client->get('/login')->formField('csrf_token') ?? '',
            'kennung' => $email,
            'password' => self::PASSWORT,
        ]);
        $this->assertSame('/login/2fa', $login->location());

        $antwort = $client->post('/login/2fa', [
            'csrf_token' => $client->get('/login/2fa')->formField('csrf_token') ?? '',
            'totp_code' => Totp::getCode($gespeichert),
        ]);
        $this->assertSame(200, $antwort->statusCode, 'Vorher: 302 - der Chiffretext war das Secret. Location: ' . $antwort->location());
        $this->assertStringContainsString('Ungültiger Code', $antwort->body);
        $this->assertSame(1, $this->auditEintraege($id));

        // Gegenprobe: Der Backup-Code bleibt der Rückweg.
        \App\Database::getInstance()->prepare("UPDATE users SET backup_codes = ? WHERE id = ?")
            ->execute([json_encode([password_hash('ABCDEF0123456789', PASSWORD_DEFAULT)]), $id]);
        $backup = $client->post('/2fa/backup', [
            'csrf_token' => $client->get('/2fa/backup')->formField('csrf_token') ?? '',
            'backup_code' => 'ABCDEF01-23456789',
        ]);
        $this->assertSame('/admin?backup_code_used=1', $backup->location(), "Body: {$backup->body}");
    }

    public function testStepUpUndBackupCodeNeuerzeugungLehnenDenCodeAb(): void {
        [$client, , $id, $gespeichert] = $this->editorMitUnlesbaremSecret();

        $reauth = $client->post('/2fa/reauth', [
            'csrf_token' => $this->editorCsrfToken($client),
            'password' => self::PASSWORT,
            'totp_code' => Totp::getCode($gespeichert),
        ]);
        $this->assertSame(200, $reauth->statusCode, 'Location: ' . $reauth->location());
        $this->assertStringContainsString('Passwort oder Code ungültig', $reauth->body);

        $profil = $client->post('/profil/backup-codes', [
            'csrf_token' => $this->editorCsrfToken($client),
            'current_password' => self::PASSWORT,
            'totp_code' => Totp::getCode($gespeichert),
        ]);
        $this->assertSame('/profil?error=totp_wrong', $profil->location());

        $this->assertSame(2, $this->auditEintraege($id));
    }
}
