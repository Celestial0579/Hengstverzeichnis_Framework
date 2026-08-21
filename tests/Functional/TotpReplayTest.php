<?php
// tests/Functional/TotpReplayTest.php

namespace Tests\Functional;

use App\Security\Totp;

/**
 * HTTP-Funktionstest für den TOTP-Replay-Schutz (Issue #111, siehe
 * users.last_totp_timeslice und Totp::verifyCodeReturnSlice()): Ein einmal
 * für einen Login verbrauchter TOTP-Code kann nicht innerhalb seines
 * Toleranzfensters (~90 s) für einen zweiten Login wiederverwendet werden.
 *
 * Wichtig: Anders als die übrigen Tests ruft dieser Test
 * resetTotpReplayGuard() zwischen den beiden Logins bewusst NICHT auf -
 * genau dieses Zurücksetzen erlaubt es dem Rest der Suite, denselben Code
 * mehrfach zu verwenden (siehe FunctionalTestCase::authenticatedClient()).
 */
class TotpReplayTest extends FunctionalTestCase {

    public function testSameTotpCodeCannotBeUsedForTwoLogins(): void {
        // Erster voller Login-Flow (inkl. 2FA) konsumiert einen Zeitschlitz T
        // für den Admin. Der Replay-Code wird VOR diesem Login erfasst: Kippt
        // das 30s-Fenster zwischendurch, konsumiert der Login T+1 und der
        // erfasste Code (T) ist als älterer Schlitz ebenso sicher abgelehnt -
        // der Test bleibt in beiden Fällen deterministisch.
        $this->authenticatedClient(); // provisioniert bei isoliertem Lauf
        $replayCode = Totp::getCode(self::$totpSecret);
        $this->authenticatedClient();

        $client = $this->newClient();
        $loginPage = $client->get('/login');
        $loginResponse = $client->post('/login', [
            'csrf_token' => $loginPage->formField('csrf_token') ?? '',
            'kennung' => self::$adminEmail,
            'password' => self::$adminPassword,
        ]);
        $this->assertSame('/login/2fa', $loginResponse->location());

        $verifyPage = $client->get('/login/2fa');
        $verifyResponse = $client->post('/login/2fa', [
            'csrf_token' => $verifyPage->formField('csrf_token') ?? '',
            'totp_code' => $replayCode,
        ]);

        // Kein Redirect ins Backend, sondern erneut die 2FA-Seite mit Fehler.
        $this->assertSame(200, $verifyResponse->statusCode, 'Replay eines verbrauchten TOTP-Codes darf nicht zum Login führen');
        $this->assertStringContainsString('form', $verifyResponse->body);

        // Und die Session ist nicht angemeldet:
        $adminArea = $client->get('/admin');
        $this->assertSame(302, $adminArea->statusCode, 'Session darf nach abgelehntem Replay nicht angemeldet sein');
    }
}
