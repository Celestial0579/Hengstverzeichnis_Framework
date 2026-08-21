<?php
// tests/Functional/BackupCodeLoginTest.php

namespace Tests\Functional;

use App\Database;

/**
 * HTTP-Funktionstests für den 2FA-Backup-Code-Login (#128): Ein gültiger
 * Backup-Code funktioniert genau EINMAL (Einmalverbrauch wird persistiert),
 * danach wird derselbe Code abgelehnt. Zusätzlich greift das Rate-Limit
 * (RateLimiter-Typ 'backup') nach zu vielen Fehlversuchen, und kaputte/leere
 * backup_codes-Werte in der DB führen zu einer sauberen Ablehnung statt zu
 * einem PHP-Fehler.
 */
class BackupCodeLoginTest extends FunctionalTestCase {

    private function db(): \PDO {
        return Database::getInstance();
    }

    protected function tearDown(): void {
        // Backup-Codes und Rate-Limit-Zähler zurücksetzen, damit nachfolgende
        // Testklassen den gemeinsam genutzten Admin-Account unverändert vorfinden.
        $db = $this->db();
        $stmt = $db->prepare("UPDATE users SET backup_codes = NULL WHERE email = ?");
        $stmt->execute([self::$adminEmail]);
        $db->exec("DELETE FROM login_attempts WHERE type = 'backup'");
        parent::tearDown();
    }

    private function setAdminBackupCodes(?string $rawValue): void {
        $stmt = $this->db()->prepare("UPDATE users SET backup_codes = ? WHERE email = ?");
        $stmt->execute([$rawValue, self::$adminEmail]);
    }

    private function adminBackupCodesFromDb(): ?string {
        $stmt = $this->db()->prepare("SELECT backup_codes FROM users WHERE email = ?");
        $stmt->execute([self::$adminEmail]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }

    /**
     * Führt den Passwort-Login des Admins aus und bleibt im 2FA-Pending-Zustand
     * stehen (statt wie authenticatedClient() per TOTP abzuschließen).
     */
    private function loginUntil2faPending(): \Tests\Support\HttpClient {
        // authenticatedClient() einmalig aufrufen, damit die App provisioniert
        // ist und self::$adminEmail/-Password gesetzt sind.
        $client = $this->newClient();
        $loginPage = $client->get('/login');
        $loginResponse = $client->post('/login', [
            'csrf_token' => $loginPage->formField('csrf_token') ?? '',
            'kennung' => self::$adminEmail,
            'password' => self::$adminPassword,
        ]);
        $this->assertSame('/login/2fa', $loginResponse->location(), "Login sollte zur 2FA-Verifikation weiterleiten, Body: {$loginResponse->body}");
        return $client;
    }

    private function postBackupCode(\Tests\Support\HttpClient $client, string $code): \Tests\Support\HttpResponse {
        $backupPage = $client->get('/2fa/backup');
        return $client->post('/2fa/backup', [
            'csrf_token' => $backupPage->formField('csrf_token') ?? '',
            'backup_code' => $code,
        ]);
    }

    public function testBackupCodeWorksExactlyOnce(): void {
        // Provisionierung sicherstellen (setzt self::$adminEmail).
        $this->authenticatedClient();
        $this->setAdminBackupCodes(json_encode([password_hash('AAAABBBB', PASSWORD_DEFAULT)]));

        // 1. Gültiger Backup-Code (mit Bindestrich/Leerzeichen-Normalisierung)
        //    schließt den Login ab.
        $client = $this->loginUntil2faPending();
        $response = $this->postBackupCode($client, 'AAAA-BBBB');
        $this->assertSame('/admin?backup_code_used=1', $response->location(), "Backup-Code-Login fehlgeschlagen, Body: {$response->body}");

        // Der verbrauchte Code wurde persistiert entfernt.
        $this->assertSame([], json_decode((string)$this->adminBackupCodesFromDb(), true), 'Der verbrauchte Backup-Code muss aus der DB entfernt sein');

        // 2. Derselbe Code funktioniert von einem neuen Client aus NICHT erneut.
        $secondClient = $this->loginUntil2faPending();
        $secondResponse = $this->postBackupCode($secondClient, 'AAAA-BBBB');
        $this->assertNotSame(302, $secondResponse->statusCode, 'Ein bereits verbrauchter Backup-Code darf nicht erneut funktionieren');
        $this->assertStringContainsString('Ungültiger oder bereits verwendeter Backup-Code.', $secondResponse->body);
    }

    public function testTooManyFailedAttemptsAreRateLimited(): void {
        $this->authenticatedClient();
        $this->setAdminBackupCodes(json_encode([password_hash('CCCCDDDD', PASSWORD_DEFAULT)]));

        $client = $this->loginUntil2faPending();

        // 5 Fehlversuche füllen das Limit (RateLimiter: max. 5 je 15 Minuten).
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postBackupCode($client, 'WRONG' . $i);
            $this->assertStringContainsString('Ungültiger oder bereits verwendeter Backup-Code.', $response->body);
        }

        // 6. Versuch: selbst der RICHTIGE Code wird jetzt abgewiesen.
        $limited = $this->postBackupCode($client, 'CCCC-DDDD');
        $this->assertStringContainsString('Zu viele fehlgeschlagene Versuche', $limited->body);
    }

    public function testCorruptBackupCodesValueIsRejectedCleanly(): void {
        $this->authenticatedClient();

        // Leerer String: json_decode liefert null - der Login-Versuch muss als
        // "ungültig" enden statt in einem PHP-Fehler (foreach über null, #128).
        $this->setAdminBackupCodes('');
        $client = $this->loginUntil2faPending();
        $response = $this->postBackupCode($client, 'AAAA-BBBB');
        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('Ungültiger oder bereits verwendeter Backup-Code.', $response->body);

        // Kaputtes JSON ebenso.
        $this->db()->exec("DELETE FROM login_attempts WHERE type = 'backup'");
        $this->setAdminBackupCodes('{not-json');
        $client = $this->loginUntil2faPending();
        $response = $this->postBackupCode($client, 'AAAA-BBBB');
        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('Ungültiger oder bereits verwendeter Backup-Code.', $response->body);
    }
}
