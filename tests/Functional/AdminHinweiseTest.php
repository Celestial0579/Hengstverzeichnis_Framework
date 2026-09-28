<?php
// tests/Functional/AdminHinweiseTest.php

namespace Tests\Functional;

use App\Database;
use App\Service\Mailer;

/**
 * Feste Stamm-URL (Audit M6) über den echten HTTP-Weg: Warnung im
 * Dashboard, Verweigerung der Reset-Mail bei gefälschtem Host-Header - und
 * beides mit gesetzter Stamm-URL nicht.
 *
 * Voraussetzung: Der Testserver setzt weder APP_URL noch TRUSTED_HOSTS (wie
 * PasskeyAdminTest). Sonst ist der Zustand ohne feste Quelle nicht
 * herstellbar.
 */
class AdminHinweiseTest extends FunctionalTestCase {

    private const WARNUNG = 'Keine feste Stamm-URL';

    private ?string $stammUrlVorher = null;

    protected function setUp(): void {
        if (getenv('APP_URL') !== false || getenv('TRUSTED_HOSTS') !== false) {
            $this->markTestSkipped('APP_URL/TRUSTED_HOSTS gesetzt - der Zustand ohne feste Stamm-URL ist so nicht erreichbar.');
        }
        $this->stammUrlVorher = self::stammUrlSetzen(null);
    }

    protected function tearDown(): void {
        self::stammUrlSetzen($this->stammUrlVorher);
    }

    public function testDashboardWarntBisDieStammUrlGesetztIst(): void {
        $admin = $this->authenticatedClient();

        $dashboard = $admin->get('/admin');
        $this->assertStringContainsString(self::WARNUNG, $dashboard->body);
        $this->assertStringContainsString('<a href="/admin/system-settings" style="color: inherit;">Stamm-URL festlegen</a>', $dashboard->body);

        // Der Weg über das Formular, wie ihn der Admin geht.
        $antwort = $admin->post('/admin/system-settings', [
            'csrf_token' => $this->csrfTokenFrom($admin, '/admin/system-settings'),
            'base_url' => 'https://hengstverzeichnis.example.com',
            'language' => 'de',
        ]);
        $this->assertStringContainsString('/admin/system-settings?success=1', (string)$antwort->location());

        $this->assertStringNotContainsString(self::WARNUNG, $admin->get('/admin')->body);
    }

    public function testNichtAdminsSehenDieWarnungNicht(): void {
        $editor = $this->createAndLoginEditor($this->authenticatedClient(), 'hinweisred' . uniqid(), 'hinweisred-' . uniqid() . '@example.com');
        $this->assertStringNotContainsString(self::WARNUNG, $editor->get('/admin')->body);
    }

    /**
     * Systemeinstellungen: Ein Vorschlag aus dem Host erscheint nur, wenn er
     * die Prüfung besteht - 127.0.0.1 des Testservers tut das nicht. Das Feld
     * wird nie aus dem Host vorbelegt (Fälle mit öffentlichem Host:
     * tests/Unit/Security/BaseUrlTest).
     */
    public function testKeinVorschlagFuerLokaleAdressen(): void {
        $seite = $this->authenticatedClient()->get('/admin/system-settings');
        $this->assertStringNotContainsString('Vorschlag:', $seite->body);
        $this->assertSame('', $seite->formField('base_url'));
    }

    /**
     * Der Angriff aus dem Befund: POST /forgot-password mit gefälschtem Host.
     * Die Antwort bleibt gleich (kein Orakel), die Mail wird verweigert und
     * protokolliert. Mit gesetzter Stamm-URL gibt es keine Verweigerung.
     */
    public function testResetMitFremdemHostWirdVerweigert(): void {
        $konto = $this->angemeldetOhneFaktor($this->authenticatedClient(), 'hinweisreset');
        $email = (string)$konto['email'];
        $this->assertNotSame('', $email);

        $this->assertSame(1, $this->resetAnfordern($email), 'Ohne feste Stamm-URL: genau eine Verweigerung.');

        self::stammUrlSetzen(self::TEST_STAMM_URL);
        $this->assertSame(0, $this->resetAnfordern($email), 'Mit Stamm-URL wird nicht verweigert.');
    }

    /** Fordert einen Reset mit Host evil.example an und zählt neue Verweigerungen. */
    private function resetAnfordern(string $email): int {
        $db = Database::getInstance();
        $db->exec("DELETE FROM login_attempts WHERE type IN ('password_reset', 'password_reset_to')");
        $bisher = (int)$db->query('SELECT COALESCE(MAX(id), 0) FROM audit_logs')->fetchColumn();

        $client = $this->newClient();
        $kopf = ['Host' => 'evil.example'];
        $seite = $client->get('/forgot-password', $kopf);
        $antwort = $client->post('/forgot-password', [
            'csrf_token' => $seite->formField('csrf_token') ?? '',
            'email' => $email,
        ], $kopf);
        $this->assertSame('/forgot-password?sent=1', $antwort->location(), "Body: {$antwort->body}");

        $stmt = $db->prepare('SELECT COUNT(*) FROM audit_logs WHERE id > ? AND action = ?');
        $stmt->execute([$bisher, Mailer::AUDIT_VERSAND_VERWEIGERT]);
        return (int)$stmt->fetchColumn();
    }
}
