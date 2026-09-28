<?php
// tests/Functional/PasswordResetRateLimitTest.php

namespace Tests\Functional;

use App\Controllers\AuthController;
use App\Database;

/**
 * „Passwort vergessen“ ist zusätzlich je EMPFÄNGER begrenzt (Audit M7).
 *
 * Die Grenze je Absender-IP allein reichte nicht: Wer viele Adressen hat -
 * bei IPv6 ein ganzes Präfix -, verteilte seine Anfragen und flutete das
 * Postfach eines Opfers trotzdem. Jetzt gehen höchstens drei Reset-Mails je
 * Adresse und Stunde hinaus. Die Antwort bleibt in jedem Fall gleich, und
 * gezählt wird nur ein Abdruck der Adresse.
 *
 * Gemessen wird am Reset-Token: Nur wenn eine Mail hinausgeht, entsteht ein
 * neues (in der Testumgebung ist kein SMTP eingerichtet, der Versand scheitert
 * leise - das Token wird vorher geschrieben).
 */
class PasswordResetRateLimitTest extends FunctionalTestCase {

    private ?string $email = null;

    protected function tearDown(): void {
        $db = Database::getInstance();
        $db->exec("DELETE FROM login_attempts WHERE type = 'password_reset'");
        if ($this->email !== null) {
            $db->prepare("DELETE FROM login_attempts WHERE identifier = ? AND type = ?")
                ->execute([AuthController::resetEmpfaengerKey($this->email), AuthController::RESET_EMPFAENGER_TYP]);
            $db->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$this->email]);
        }
        parent::tearDown();
    }

    private function resetToken(): ?string {
        $stmt = Database::getInstance()->prepare("SELECT token FROM password_resets WHERE email = ?");
        $stmt->execute([$this->email]);
        $token = $stmt->fetchColumn();
        return $token === false ? null : (string)$token;
    }

    public function testHoechstensDreiResetsJeEmpfaenger(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $this->email = "resetgrenze-{$unique}@example.com";
        $angelegt = $admin->post('/admin/users/store', [
            'csrf_token' => $admin->get('/admin/users/create')->formField('csrf_token') ?? '',
            'username' => "resetgrenze{$unique}",
            'email' => $this->email,
            'password' => 'ResetGrenze123!',
            'groups' => [],
        ]);
        $this->assertSame('/admin/users?success=created', $angelegt->location(), "Body: {$angelegt->body}");

        $client = $this->newClient();
        $tokens = [];
        for ($i = 1; $i <= 4; $i++) {
            // Der IP-Topf (5 je 15 Minuten) wird geleert - hier geht es allein
            // um die Grenze je Empfänger.
            Database::getInstance()->exec("DELETE FROM login_attempts WHERE type = 'password_reset'");

            $antwort = $client->post('/forgot-password', [
                'csrf_token' => $client->get('/forgot-password')->formField('csrf_token') ?? '',
                // Die Schreibweise wechselt: Gross/klein ist dieselbe Adresse.
                'email' => $i % 2 === 0 ? strtoupper($this->email) : $this->email,
            ]);
            $this->assertSame('/forgot-password?sent=1', $antwort->location(), "Aufruf {$i} muss gleich antworten.");
            $tokens[$i] = $this->resetToken();
        }

        $this->assertNotNull($tokens[1]);
        $this->assertNotSame($tokens[1], $tokens[2], 'Der zweite Reset stellt ein neues Token aus.');
        $this->assertNotSame($tokens[2], $tokens[3]);
        $this->assertSame($tokens[3], $tokens[4], 'Der vierte Reset in der Stunde darf nichts mehr auslösen.');

        $stmt = Database::getInstance()->prepare("SELECT identifier FROM login_attempts WHERE type = ?");
        $stmt->execute([AuthController::RESET_EMPFAENGER_TYP]);
        $alle = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $zeilen = array_values(array_filter(
            $alle,
            fn(string $id): bool => $id === AuthController::resetEmpfaengerKey($this->email)
        ));
        $this->assertCount(3, $zeilen);
        foreach ($alle as $identifier) {
            $this->assertStringNotContainsString('@', (string)$identifier, 'Adressen nur als Abdruck.');
        }
        $this->assertStringNotContainsString('resetgrenze', $zeilen[0]);
    }
}
