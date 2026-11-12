<?php
// tests/Functional/AdminResetGuardTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Die Sperren vor `POST /admin/reset` - der destruktivsten Route der
 * Anwendung (Werksreset: Pferde, Kontakte, Konten, Addon-Daten).
 *
 * Getestet war bisher nur, WAS ein Reset leert (SystemResetTest), nicht WER
 * ihn auslösen darf. Fiele eine der vier Sperren - Anmeldung, Admin, CSRF,
 * Bestätigungswort - weg, bliebe die Suite grün, und ein einziger
 * untergeschobener POST löschte den Bestand.
 *
 * Der Erfolgsfall steht bewusst NICHT hier: Er leerte die gemeinsame
 * Funktionstest-Datenbank für alle folgenden Tests. Jeder Fall prüft deshalb
 * zusätzlich, dass der Bestand danach unverändert ist.
 */
class AdminResetGuardTest extends FunctionalTestCase {

    /** @var array<int, string> */
    private array $createdUsernames = [];

    protected function tearDown(): void {
        $stmt = Database::getInstance()->prepare("DELETE FROM users WHERE username = ?");
        foreach ($this->createdUsernames as $username) {
            $stmt->execute([$username]);
        }
        $this->createdUsernames = [];
        parent::tearDown();
    }

    private static function anzahlKonten(): int {
        return (int)Database::getInstance()->query("SELECT COUNT(*) FROM users")->fetchColumn();
    }

    public function testNurEinAdminMitTokenUndBestaetigungKommtDurch(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $this->createdUsernames[] = "resetzugriff{$unique}";
        $editor = $this->createAndLoginEditor($admin, "resetzugriff{$unique}", "reset-zugriff-{$unique}@example.com");
        $vorher = self::anzahlKonten();

        // 1. Unangemeldet: Umleitung zum Login, nicht einmal ein 403.
        $anonym = $this->newClient()->post('/admin/reset', ['csrf_token' => 'egal', 'confirm_text' => 'RESET']);
        $this->assertSame('/login', $anonym->location());

        // 2. Angemeldeter Nicht-Admin - auch mit gültigem eigenem Token.
        $nichtAdmin = $editor->post('/admin/reset', [
            'csrf_token' => $this->csrfTokenFrom($editor, '/profil'),
            'confirm_text' => 'RESET',
        ]);
        $this->assertSame(403, $nichtAdmin->statusCode);
        $this->assertStringContainsString('ausschließlich Administratoren', $nichtAdmin->body);

        // 3. Admin ohne gültigen CSRF-Token: ein untergeschobener POST.
        $ohneToken = $admin->post('/admin/reset', ['csrf_token' => 'gefaelscht', 'confirm_text' => 'RESET']);
        $this->assertSame(403, $ohneToken->statusCode);
        $this->assertStringContainsString('CSRF', $ohneToken->body);

        // 4. Admin mit Token, aber ohne das Bestätigungswort.
        foreach (['', 'reset', 'RESET!', 'JA'] as $wort) {
            $ohneBestaetigung = $admin->post('/admin/reset', [
                'csrf_token' => $this->currentCsrfToken($admin),
                'confirm_text' => $wort,
            ]);
            $this->assertSame(
                '/admin/system-settings?error=reset_confirm_failed',
                $ohneBestaetigung->location(),
                "Bestätigung '{$wort}' darf nicht genügen."
            );
        }

        $this->assertSame($vorher, self::anzahlKonten(), 'Kein Versuch darf etwas geleert haben.');
        $this->assertSame(200, $admin->get('/admin')->statusCode, 'Die Admin-Sitzung lebt noch.');
    }
}
