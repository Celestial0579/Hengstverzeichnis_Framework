<?php
// tests/Functional/LoginRateLimitTest.php

namespace Tests\Functional;

/**
 * HTTP-Funktionstests für den IP-gekoppelten Login-Rate-Limiter (Issue #115,
 * siehe AuthController::loginSubmit()): Der Konto-Zähler ist an email|ip
 * gebunden - fünf Fehlversuche sperren nur die Kombination aus dieser
 * E-Mail-Adresse und dieser Client-IP, andere Konten von derselben IP bleiben
 * anmeldbar (kein globaler Account-Lockout-DoS über gezielte Fehlversuche).
 *
 * Hinweis zur Suite-Hygiene: Alle Requests der Functional-Suite kommen von
 * 127.0.0.1 - dieser Test erzeugt bewusst nur 6 protokollierte Fehlversuche
 * und bleibt damit deutlich unter dem reinen IP-Limit (login_ip, 20/15 min),
 * um nachfolgende Login-Tests nicht zu beeinflussen.
 */
class LoginRateLimitTest extends FunctionalTestCase {

    use DsgvoFormHelper;

    /** Höchste Zeilen-ID in login_attempts vor dem Test - alles darüber räumt tearDown() ab. */
    private ?int $zaehlerStand = null;

    private function zaehlerMerken(): void {
        $this->zaehlerStand = (int)\App\Database::getInstance()
            ->query("SELECT COALESCE(MAX(id), 0) FROM login_attempts")->fetchColumn();
    }

    protected function tearDown(): void {
        // Alle Anfragen der Suite kommen von 127.0.0.1 und teilen sich
        // login_ip (20 in 15 Minuten). Was diese Tests dort buchen, darf
        // die nachfolgenden Anmeldungen nicht sperren.
        if ($this->zaehlerStand !== null) {
            \App\Database::getInstance()
                ->prepare("DELETE FROM login_attempts WHERE id > ?")
                ->execute([$this->zaehlerStand]);
            $this->zaehlerStand = null;
        }
        parent::tearDown();
    }

    /** @param array<string, string> $mehr */
    private function anmelden(\Tests\Support\HttpClient $client, string $kennung, string $passwort, array $mehr = [], ?\Tests\Support\HttpResponse $seite = null): \Tests\Support\HttpResponse {
        $seite ??= $client->get('/login');
        return $client->post('/login', [
            'csrf_token' => $seite->formField('csrf_token') ?? '',
            'kennung' => $kennung,
            'password' => $passwort,
        ] + $mehr);
    }

    private static function zeilen(string $identifier, string $typ): int {
        $stmt = \App\Database::getInstance()->prepare(
            "SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND type = ?"
        );
        $stmt->execute([$identifier, $typ]);
        return (int)$stmt->fetchColumn();
    }

    private static function kontoweiteFehlversucheSetzen(string $kontoKey, int $anzahl): void {
        $stmt = \App\Database::getInstance()->prepare(
            "INSERT INTO login_attempts (identifier, type, ip_address) VALUES (?, ?, '198.51.100.9')"
        );
        for ($i = 0; $i < $anzahl; $i++) {
            $stmt->execute([$kontoKey, \App\Controllers\AuthController::KONTO_BREMSE_TYP]);
        }
    }

    /**
     * Kontoweite Bremse (Audit M7): Nach gehäuften Fehlversuchen gegen
     * dasselbe Konto - von vielen Adressen aus - verlangt die Anmeldung
     * zusätzlich die Spam-Schutz-Abfrage. Gesperrt wird das Konto NIE (#115):
     * Mit gelöster Aufgabe kommt es sofort hinein.
     */
    public function testKontoweiteBremseVerlangtZusatzpruefungStattZuSperren(): void {
        $admin = $this->authenticatedClient();
        $konto = $this->angemeldetOhneFaktor($admin, 'bremse');
        $this->zaehlerMerken();
        $kontoKey = 'uid:' . $konto['id'];
        self::kontoweiteFehlversucheSetzen($kontoKey, \App\Controllers\AuthController::KONTO_BREMSE_AB);

        $client = $this->newClient();
        $gate = $this->anmelden($client, $konto['username'], $konto['passwort']);
        $this->assertSame(200, $gate->statusCode);
        $this->assertNull($gate->location(), 'Ohne gelöste Aufgabe keine Anmeldung - auch nicht mit richtigem Passwort.');
        $this->assertStringContainsString(
            htmlspecialchars(\App\I18n\Translator::t('auth.login_captcha_required')),
            $gate->body
        );
        $this->assertStringContainsString('name="captcha"', $gate->body);
        $this->assertStringContainsString('value="' . $konto['username'] . '"', $gate->body, 'Die Kennung bleibt stehen.');
        $this->assertSame(0, self::zeilen($kontoKey . '|127.0.0.1', 'login'), 'Ohne Passwortprüfung kein Konto|IP-Fehlversuch.');

        $antwort = $this->solveCaptcha($gate);
        $this->waitForMinimumSolveTime();
        $angemeldet = $this->anmelden($client, $konto['username'], $konto['passwort'], [
            'captcha' => (string)$antwort,
            'login_captcha' => '1',
        ], $gate);

        $this->assertSame(302, $angemeldet->statusCode, "Body: {$angemeldet->body}");
        $this->assertStringNotContainsString('Zu viele', $angemeldet->body);
        $this->assertSame(
            \App\Controllers\AuthController::KONTO_BREMSE_AB,
            self::zeilen($kontoKey, \App\Controllers\AuthController::KONTO_BREMSE_TYP),
            'Die eigene Buchung des erfolgreichen Versuchs wird freigegeben, der Rest bleibt - '
            . 'sonst setzte jede Anmeldung des Opfers das Budget des Angreifers zurück.'
        );
    }

    /** Eine unbekannte Kennung bekommt dieselbe Bremse - kein Orakel für Konten. */
    public function testKontoweiteBremseVerraetKeineKonten(): void {
        $this->authenticatedClient();
        $this->zaehlerMerken();
        $kennung = 'niemand-' . uniqid() . '@example.com';
        self::kontoweiteFehlversucheSetzen(
            'kennung:' . \App\Security\LoginIdentifier::normalize($kennung),
            \App\Controllers\AuthController::KONTO_BREMSE_AB
        );

        $antwort = $this->anmelden($this->newClient(), $kennung, 'egal-was');

        $this->assertSame(200, $antwort->statusCode);
        $this->assertStringContainsString(
            htmlspecialchars(\App\I18n\Translator::t('auth.login_captcha_required')),
            $antwort->body
        );
        $this->assertStringContainsString('name="captcha"', $antwort->body);
    }

    /**
     * Wer an EINEM Konto gesperrt ist, verbraucht mit weiteren Klicks nicht
     * das IP-Budget des ganzen Anschlusses (Audit M20/M7). Gesperrte
     * Versuche prüfen kein Passwort und zählen deshalb nirgends.
     */
    public function testGesperrterKontoVersuchBelastetIpZaehlerNicht(): void {
        $this->authenticatedClient();
        $this->zaehlerMerken();
        $client = $this->newClient();
        $kennung = 'gesperrt-' . uniqid() . '@example.com';

        for ($i = 0; $i < 5; $i++) {
            $this->assertStringContainsString('Ungültige Zugangsdaten.', $this->anmelden($client, $kennung, 'falsch')->body);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->assertStringContainsString(
                'Zu viele fehlgeschlagene Anmeldeversuche',
                $this->anmelden($client, $kennung, 'falsch')->body
            );
        }

        $stmt = \App\Database::getInstance()->prepare(
            "SELECT COUNT(*) FROM login_attempts WHERE type = 'login_ip' AND id > ?"
        );
        $stmt->execute([$this->zaehlerStand]);
        $this->assertSame(5, (int)$stmt->fetchColumn());
    }

    public function testFifthFailureLocksOnlyThatEmailIpCombination(): void {
        // Provisioniert die App (Setup-Wizard) bei isoliertem Lauf und belegt
        // nebenbei, dass der Admin-Login VOR den Fehlversuchen funktioniert.
        $this->authenticatedClient();

        $unique = uniqid();
        $targetEmail = "ratelimit-opfer-{$unique}@example.com";

        $client = $this->newClient();

        // 5 Fehlversuche für die Ziel-Adresse -> Limit (5/15 min) erreicht.
        for ($i = 0; $i < 5; $i++) {
            $loginPage = $client->get('/login');
            $response = $client->post('/login', [
                'csrf_token' => $loginPage->formField('csrf_token') ?? '',
                'kennung' => $targetEmail,
                'password' => 'falsches-passwort',
            ]);
            $this->assertStringContainsString('Ungültige Zugangsdaten.', $response->body);
        }

        // 6. Versuch für dieselbe Adresse: gesperrt.
        $loginPage = $client->get('/login');
        $blocked = $client->post('/login', [
            'csrf_token' => $loginPage->formField('csrf_token') ?? '',
            'kennung' => $targetEmail,
            'password' => 'falsches-passwort',
        ]);
        $this->assertStringContainsString('Zu viele fehlgeschlagene Anmeldeversuche', $blocked->body);

        // Anderes Konto von derselben IP: normal behandelbar (nur die
        // email|ip-Kombination ist gesperrt, kein globaler IP- oder
        // E-Mail-Lockout nach 5 Versuchen).
        $otherResponse = $client->post('/login', [
            'csrf_token' => $loginPage->formField('csrf_token') ?? '',
            'kennung' => "anderes-konto-{$unique}@example.com",
            'password' => 'falsches-passwort',
        ]);
        $this->assertStringContainsString('Ungültige Zugangsdaten.', $otherResponse->body);
        $this->assertStringNotContainsString('Zu viele fehlgeschlagene Anmeldeversuche', $otherResponse->body);

        // Die andere Hälfte der Zusicherung - und die eigentliche: DIESELBE
        // Adresse von einer ANDEREN IP darf nicht gesperrt sein.
        //
        // Über HTTP ist dieser Fall in der Suite nicht herstellbar: Alle
        // Anfragen kommen über php -S von 127.0.0.1, und ClientIp::resolve()
        // liefert ohne TRUSTED_PROXIES immer REMOTE_ADDR. Der IP-Anteil des
        // Schlüssels ist damit über den ganzen Test hinweg konstant - alles
        // oben verhielte sich exakt genauso, wenn der Zähler nur die
        // E-Mail-Adresse führte. Genau das ist aber der Account-Lockout-DoS,
        // gegen den #115 gebaut wurde. Deshalb wird der Fremd-IP-Zustand
        // direkt in login_attempts gesetzt.
        $db = \App\Database::getInstance();
        $fremdeIp = '203.0.113.7';
        $stmt = $db->prepare(
            "INSERT INTO login_attempts (identifier, type, created_at) VALUES (?, 'login', NOW())"
        );
        $andereEmail = "ratelimit-fremdip-{$unique}@example.com";
        for ($i = 0; $i < 5; $i++) {
            $stmt->execute([strtolower($andereEmail) . '|' . $fremdeIp]);
        }

        $loginPage = $client->get('/login');
        $vonUnsererIp = $client->post('/login', [
            'csrf_token' => $loginPage->formField('csrf_token') ?? '',
            'kennung' => $andereEmail,
            'password' => 'falsches-passwort',
        ]);
        $this->assertStringContainsString('Ungültige Zugangsdaten.', $vonUnsererIp->body);
        $this->assertStringNotContainsString(
            'Zu viele fehlgeschlagene Anmeldeversuche',
            $vonUnsererIp->body,
            'Fehlversuche einer FREMDEN IP dürfen dieselbe Adresse hier nicht sperren - sonst genügt ein '
            . 'Angreifer von aussen, um ein Konto auszusperren (#115).'
        );

        // Der echte Admin-Account bleibt trotz der Sperre der Ziel-Adresse
        // voll anmeldbar - genau das verhindert den Account-Lockout-DoS,
        // sobald Angreifer und Opfer unterschiedliche IPs haben; hier wird
        // stellvertretend die Unabhängigkeit der Zähler pro E-Mail belegt.
        $adminClient = $this->authenticatedClient();
        $dashboard = $adminClient->get('/admin');
        $this->assertSame(200, $dashboard->statusCode);
    }
}
