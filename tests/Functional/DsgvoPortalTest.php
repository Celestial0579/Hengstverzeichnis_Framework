<?php
// tests/Functional/DsgvoPortalTest.php

namespace Tests\Functional;

use App\Security\Captcha;
use Tests\Support\HttpClient;
use Tests\Support\HttpResponse;

/**
 * HTTP-Funktionstests für den Bot-/Spam-Schutz des öffentlichen DSGVO-Portals
 * (POST /dsgvo, siehe PublicController::dsgvoSubmit()).
 *
 * Der Endpunkt ist bewusst ohne Anmeldung erreichbar und löst pro angenommener
 * Anfrage eine echte Admin-Benachrichtigung sowie eine Zeile in
 * `gdpr_requests` aus. Abgesichert wird er durch vier unabhängige Schichten:
 * CSRF, IP-Rate-Limiting (RateLimiter-Typen `dsgvo_attempt`/`dsgvo_request`),
 * Honeypot und CAPTCHA.
 *
 * Alle Requests der Functional-Suite kommen von 127.0.0.1, teilen sich also
 * dieselben IP-Zähler - setUp() setzt sie deshalb vor jedem Test zurück, damit
 * die Tests unabhängig von ihrer Reihenfolge (und von vorherigen Läufen gegen
 * dieselbe Test-Datenbank) laufen.
 */
class DsgvoPortalTest extends FunctionalTestCase {

    use DsgvoFormHelper;


    protected function setUp(): void {
        // Provisioniert die App (Setup-Wizard) bei isoliertem Lauf dieser Klasse.
        $this->authenticatedClient();
        self::resetDsgvoRateLimit();
    }

    public function testFormOffersCaptchaAndHoneypotAndLeaksNoAnswer(): void {
        $page = $this->newClient()->get('/dsgvo');

        $this->assertSame(200, $page->statusCode);
        $this->assertStringContainsString('name="captcha"', $page->body);
        $this->assertStringContainsString('name="' . Captcha::HONEYPOT_FIELD . '"', $page->body);

        // Die Aufgabe steht ausgeschrieben im HTML, die Lösung liegt
        // ausschließlich serverseitig in der Session - ein Bot kann sie also
        // nicht per Zahlen-Regex aus der Seite ziehen.
        $this->assertDoesNotMatchRegularExpression('/\d/u', $this->captchaQuestion($page));
    }

    public function testSubmissionWithWrongCaptchaIsRejectedAndStoresNothing(): void {
        $client = $this->newClient();
        $page = $client->get('/dsgvo');
        $email = $this->uniqueEmail();

        $wrongAnswer = (string)($this->solveCaptcha($page) + 1);
        sleep(Captcha::MIN_SOLVE_SECONDS + 1);

        $response = $client->post('/dsgvo', $this->formData($page, $email, $wrongAnswer));

        $this->assertSame(200, $response->statusCode, 'Falsches CAPTCHA darf nicht zur Erfolgsseite weiterleiten');
        $this->assertStringContainsString('Rechenaufgabe wurde nicht richtig gelöst', $response->body);
        $this->assertSame(0, self::countStoredRequests($email));
    }

    public function testValidSubmissionIsAcceptedAndStored(): void {
        $client = $this->newClient();
        $page = $client->get('/dsgvo');
        $email = $this->uniqueEmail();

        $answer = (string)$this->solveCaptcha($page);
        sleep(Captcha::MIN_SOLVE_SECONDS + 1);

        $response = $client->post('/dsgvo', $this->formData($page, $email, $answer));

        $this->assertSame(
            '/dsgvo?success=1',
            $response->location(),
            "Gültige Anfrage sollte angenommen werden, Body: {$response->body}"
        );
        $this->assertSame(1, self::countStoredRequests($email));
    }

    /**
     * Kernschutz gegen Massen-Submits: Eine gelöste Aufgabe ist nach der
     * ersten Prüfung verbraucht (Single-Use). Ein Bot kann also nicht einmal
     * lösen und die Antwort anschließend beliebig oft wiederverwenden.
     */
    public function testSolvedCaptchaCannotBeReusedForASecondSubmission(): void {
        $client = $this->newClient();
        $page = $client->get('/dsgvo');
        $firstEmail = $this->uniqueEmail();
        $secondEmail = $this->uniqueEmail();

        $answer = (string)$this->solveCaptcha($page);
        sleep(Captcha::MIN_SOLVE_SECONDS + 1);

        $first = $client->post('/dsgvo', $this->formData($page, $firstEmail, $answer));
        $this->assertSame('/dsgvo?success=1', $first->location(), "Body: {$first->body}");

        // Zweiter POST mit identischer Antwort und identischem CSRF-Token,
        // ohne das Formular neu zu laden.
        $replay = $client->post('/dsgvo', $this->formData($page, $secondEmail, $answer));

        $this->assertSame(200, $replay->statusCode);
        $this->assertStringContainsString('Spam-Schutz ist abgelaufen', $replay->body);
        $this->assertSame(0, self::countStoredRequests($secondEmail));
    }

    /**
     * Das Honeypot-Feld ist für Menschen unsichtbar; ist es befüllt, sieht der
     * Absender die normale Erfolgsmeldung (kein Hinweis auf die Erkennung),
     * gespeichert und benachrichtigt wird aber nichts.
     */
    public function testFilledHoneypotIsDiscardedSilently(): void {
        $client = $this->newClient();
        $page = $client->get('/dsgvo');
        $email = $this->uniqueEmail();

        $data = $this->formData($page, $email, (string)$this->solveCaptcha($page));
        $data[Captcha::HONEYPOT_FIELD] = 'https://spam.example';

        $response = $client->post('/dsgvo', $data);

        $this->assertSame('/dsgvo?success=1', $response->location(), "Body: {$response->body}");
        $this->assertSame(0, self::countStoredRequests($email));
    }

    /**
     * Mengenschutz: Pro Client-IP werden höchstens drei Anfragen je Stunde
     * angenommen - erst danach greift die Sperre, korrekt gelöste CAPTCHAs
     * hin oder her.
     */
    public function testFourthAcceptedRequestPerHourIsRateLimited(): void {
        // Alle vier Formulare vorab laden (jeder Client hat eine eigene
        // Session und damit eine eigene Aufgabe), damit die Mindest-Ausfüllzeit
        // nur einmal statt viermal abgewartet werden muss.
        /** @var array<int, array{client: HttpClient, page: HttpResponse, email: string}> $submissions */
        $submissions = [];
        for ($i = 0; $i < 4; $i++) {
            $client = $this->newClient();
            $submissions[] = [
                'client' => $client,
                'page' => $client->get('/dsgvo'),
                'email' => $this->uniqueEmail(),
            ];
        }
        sleep(Captcha::MIN_SOLVE_SECONDS + 1);

        foreach (array_slice($submissions, 0, 3) as $index => $submission) {
            $response = $submission['client']->post(
                '/dsgvo',
                $this->formData($submission['page'], $submission['email'], (string)$this->solveCaptcha($submission['page']))
            );
            $this->assertSame(
                '/dsgvo?success=1',
                $response->location(),
                "Anfrage " . ($index + 1) . " von 3 sollte noch angenommen werden, Body: {$response->body}"
            );
            $this->assertSame(1, self::countStoredRequests($submission['email']));
        }

        $blocked = $submissions[3];
        $response = $blocked['client']->post(
            '/dsgvo',
            $this->formData($blocked['page'], $blocked['email'], (string)$this->solveCaptcha($blocked['page']))
        );

        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('Zu viele Anfragen von Ihrer Adresse', $response->body);
        $this->assertSame(0, self::countStoredRequests($blocked['email']));
    }

    /**
     * Baut einen vollständigen, gültigen Formular-Datensatz zum übergebenen
     * Formular (CSRF-Token) - einzelne Felder überschreiben die Tests bei Bedarf.
     *
     * @return array<string, string>
     */
    private function formData(HttpResponse $page, string $email, string $captchaAnswer): array {
        return [
            'csrf_token' => $page->formField('csrf_token') ?? '',
            'name' => 'Max Mustermann',
            'email' => $email,
            'request_type' => 'info',
            'message' => 'Bitte um Auskunft nach Art. 15 DSGVO.',
            'captcha' => $captchaAnswer,
        ];
    }



    private function uniqueEmail(): string {
        return 'dsgvo-test-' . uniqid('', true) . '@example.com';
    }

    /**
     * Leert die IP-Zähler des DSGVO-Portals. Direkter DB-Zugriff aus dem
     * PHPUnit-Prozess, analog zu FunctionalTestCase::resetTotpReplayGuard().
     */

    private static function countStoredRequests(string $email): int {
        $db = \App\Database::getInstance();
        $stmt = $db->prepare("SELECT COUNT(*) FROM gdpr_requests WHERE email = ?");
        $stmt->execute([$email]);

        return (int)$stmt->fetchColumn();
    }
}
