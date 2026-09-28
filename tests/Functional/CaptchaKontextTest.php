<?php
// tests/Functional/CaptchaKontextTest.php

namespace Tests\Functional;

/**
 * Aufgaben je Formular-Kontext über echte HTTP-Anfragen (Audit N3).
 *
 * Der Befund: Die eingebaute Rechenaufgabe lag in EINEM Platz der Sitzung.
 * Zwei geschützte Formulare in derselben Sitzung - im Addon-Fall Deckanfrage
 * und Verkaufsbörse auf einer Hengstseite - überschrieben sich beim Rendern
 * gegenseitig die Aufgabe, und das zuerst ausgelieferte scheiterte beim
 * Absenden immer mit "Rechenaufgabe nicht richtig gelöst".
 *
 * Im Kern tragen das DSGVO-Portal (Kontext `dsgvo`) und die Anmeldebremse
 * (Kontext `login`) eine Aufgabe. Der Test liefert beide nacheinander an
 * dieselbe Sitzung aus und schickt dann ZUERST das zuerst ausgelieferte
 * Formular ab - genau die Reihenfolge, die vorher scheiterte.
 */
class CaptchaKontextTest extends FunctionalTestCase {

    use DsgvoFormHelper;

    /** Höchste Zeilen-ID in login_attempts vor dem Test - alles darüber räumt tearDown() ab. */
    private ?int $zaehlerStand = null;

    protected function setUp(): void {
        $this->authenticatedClient();
        self::resetDsgvoRateLimit();
    }

    protected function tearDown(): void {
        // Alle Anfragen der Suite kommen von 127.0.0.1 und teilen sich
        // login_ip. Was dieser Test bucht, darf nachfolgende Anmeldungen
        // nicht sperren (vgl. LoginRateLimitTest).
        if ($this->zaehlerStand !== null) {
            \App\Database::getInstance()
                ->prepare("DELETE FROM login_attempts WHERE id > ?")
                ->execute([$this->zaehlerStand]);
            $this->zaehlerStand = null;
        }
        parent::tearDown();
    }

    public function testZweiFormulareInEinerSitzungSindUnabhaengigLoesbar(): void {
        $admin = $this->authenticatedClient();
        $konto = $this->angemeldetOhneFaktor($admin, 'kontexte');
        $this->zaehlerStand = (int)\App\Database::getInstance()
            ->query("SELECT COALESCE(MAX(id), 0) FROM login_attempts")->fetchColumn();

        // Kontoweite Bremse auslösen, damit die Anmeldung eine Aufgabe stellt.
        $stmt = \App\Database::getInstance()->prepare(
            "INSERT INTO login_attempts (identifier, type, ip_address) VALUES (?, ?, '198.51.100.9')"
        );
        for ($i = 0; $i < \App\Controllers\AuthController::KONTO_BREMSE_AB; $i++) {
            $stmt->execute(['uid:' . $konto['id'], \App\Controllers\AuthController::KONTO_BREMSE_TYP]);
        }

        $client = $this->newClient();

        // 1. Formular: DSGVO-Portal.
        $dsgvo = $client->get('/dsgvo');
        $this->assertSame(200, $dsgvo->statusCode);
        $this->assertStringContainsString('<label for="captcha-dsgvo">', $dsgvo->body);
        $this->assertStringContainsString('id="captcha-dsgvo" name="captcha"', $dsgvo->body);

        // 2. Formular in derselben Sitzung: Anmeldung mit Bremse.
        $loginSeite = $client->get('/login');
        $gate = $client->post('/login', [
            'csrf_token' => $loginSeite->formField('csrf_token') ?? '',
            'kennung' => $konto['username'],
            'password' => $konto['passwort'],
        ]);
        $this->assertSame(200, $gate->statusCode);
        $this->assertNull($gate->location(), 'Ohne gelöste Aufgabe keine Anmeldung.');
        $this->assertStringContainsString('<label for="captcha-login">', $gate->body);

        $dsgvoAntwort = $this->solveCaptcha($dsgvo, 'dsgvo');
        $loginAntwort = $this->solveCaptcha($gate, 'login');
        $this->waitForMinimumSolveTime();

        // Zuerst das ZUERST ausgelieferte Formular - vorher hatte die
        // Anmeldeseite dessen Aufgabe bereits überschrieben.
        $email = 'kontexte-' . uniqid('', true) . '@example.com';
        $gesendet = $client->post('/dsgvo', [
            'csrf_token' => $dsgvo->formField('csrf_token') ?? '',
            'name' => 'Max Mustermann',
            'email' => $email,
            'request_type' => 'info',
            'message' => 'Bitte um Auskunft nach Art. 15 DSGVO.',
            'captcha' => (string)$dsgvoAntwort,
        ]);
        $this->assertSame(
            '/dsgvo?success=1',
            $gesendet->location(),
            "Die Aufgabe des DSGVO-Formulars muss neben der Anmeldeaufgabe gültig bleiben. Body: {$gesendet->body}"
        );
        $stmt = \App\Database::getInstance()->prepare("SELECT COUNT(*) FROM gdpr_requests WHERE email = ?");
        $stmt->execute([$email]);
        $this->assertSame(1, (int)$stmt->fetchColumn());

        // Danach das zweite - dessen Aufgabe hat das DSGVO-Formular nicht
        // verbraucht.
        $angemeldet = $client->post('/login', [
            'csrf_token' => $gate->formField('csrf_token') ?? '',
            'kennung' => $konto['username'],
            'password' => $konto['passwort'],
            'captcha' => (string)$loginAntwort,
            'login_captcha' => '1',
        ]);
        $this->assertSame(302, $angemeldet->statusCode, "Body: {$angemeldet->body}");
    }
}
