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
                'email' => $targetEmail,
                'password' => 'falsches-passwort',
            ]);
            $this->assertStringContainsString('Ungültige E-Mail oder Passwort.', $response->body);
        }

        // 6. Versuch für dieselbe Adresse: gesperrt.
        $loginPage = $client->get('/login');
        $blocked = $client->post('/login', [
            'csrf_token' => $loginPage->formField('csrf_token') ?? '',
            'email' => $targetEmail,
            'password' => 'falsches-passwort',
        ]);
        $this->assertStringContainsString('Zu viele fehlgeschlagene Anmeldeversuche', $blocked->body);

        // Anderes Konto von derselben IP: normal behandelbar (nur die
        // email|ip-Kombination ist gesperrt, kein globaler IP- oder
        // E-Mail-Lockout nach 5 Versuchen).
        $otherResponse = $client->post('/login', [
            'csrf_token' => $loginPage->formField('csrf_token') ?? '',
            'email' => "anderes-konto-{$unique}@example.com",
            'password' => 'falsches-passwort',
        ]);
        $this->assertStringContainsString('Ungültige E-Mail oder Passwort.', $otherResponse->body);
        $this->assertStringNotContainsString('Zu viele fehlgeschlagene Anmeldeversuche', $otherResponse->body);

        // Der echte Admin-Account bleibt trotz der Sperre der Ziel-Adresse
        // voll anmeldbar - genau das verhindert den Account-Lockout-DoS,
        // sobald Angreifer und Opfer unterschiedliche IPs haben; hier wird
        // stellvertretend die Unabhängigkeit der Zähler pro E-Mail belegt.
        $adminClient = $this->authenticatedClient();
        $dashboard = $adminClient->get('/admin');
        $this->assertSame(200, $dashboard->statusCode);
    }
}
