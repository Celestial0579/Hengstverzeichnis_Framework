<?php
// tests/Functional/TwoFaCrossAccountTest.php

namespace Tests\Functional;

use App\Security\Totp;
use Tests\Support\HttpClient;

/**
 * Der zweite Faktor muss auch dann halten, wenn ein Angreifer das PASSWORT
 * des Opfers bereits kennt - genau dafür gibt es ihn.
 *
 * Die hier geprüfte Lücke entstand aus zwei Sitzungswerten, die auf
 * verschiedene Konten zeigen konnten: `pending_2fa_user_id` (Faktor 1 des
 * gerade laufenden Logins) benannte das Zielkonto, `user_id` und
 * `twofa_reauth_at` (eine bestehende, fremde Anmeldung) galten als Nachweis.
 * Wer sein eigenes Konto mit 2FA anlegte, sich dort per Step-up bestätigte
 * und dann in derselben Sitzung das Passwort des Opfers eingab, bekam
 * /2fa/setup für das OPFER ausgeliefert, überschrieb dessen Secret und war
 * als Opfer angemeldet.
 *
 * Der Test bildet genau diesen Ablauf nach. Er ist die Gegenprobe zu
 * TwoFaStepUpTest, der ausschließlich Same-User-Fälle abdeckt.
 */
class TwoFaCrossAccountTest extends FunctionalTestCase {

    /**
     * CSRF-Token für eine angemeldete Nicht-Admin-Sitzung.
     *
     * currentCsrfToken() holt es von /admin/users/create und funktioniert
     * deshalb nur für Administratoren. Das Dashboard rendert ebenfalls ein
     * Formular mit Token und steht jeder angemeldeten Sitzung offen
     * (AdminController::dashboard() prüft nur checkAuth()).
     */
    private function csrfTokenOfLoggedInClient(HttpClient $client): string {
        return $client->get('/admin')->formField('csrf_token') ?? '';
    }

    public function testStepUpOfOneAccountCannotReconfigureAnother(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        // Opfer: Konto mit aktiver 2FA. Sein Passwort gilt als kompromittiert.
        $victimEmail = "opfer-{$unique}@example.com";
        $this->createAndLoginEditor($admin, "opfer{$unique}", $victimEmail);
        $victimSecret = $this->lastEditorTotpSecret;
        $victimPassword = 'EditorTestNeu456!'; // siehe createAndLoginEditor()
        $this->assertNotNull($victimSecret);

        // Angreifer: eigenes Konto, ebenfalls mit aktiver 2FA - nur so lässt
        // sich überhaupt eine Step-up-Freigabe erzeugen.
        $attackerEmail = "angreifer-{$unique}@example.com";
        $attacker = $this->createAndLoginEditor($admin, "angreifer{$unique}", $attackerEmail);
        $attackerSecret = $this->lastEditorTotpSecret;
        $attackerPassword = 'EditorTestNeu456!';
        $this->assertNotNull($attackerSecret);
        $this->assertNotSame($victimSecret, $attackerSecret);

        // Das CSRF-Token gehört zur Sitzung, nicht zur Identität - einmal
        // geholt, gilt es über den ganzen Ablauf.
        $csrf = $this->csrfTokenOfLoggedInClient($attacker);
        $this->assertNotSame('', $csrf, 'Konnte kein CSRF-Token für die Angreifer-Sitzung holen');

        // 1. Angreifer schaltet die 2FA-Neukonfiguration FÜR SICH frei.
        self::resetTotpReplayGuard($attackerEmail);
        $reauth = $attacker->post('/2fa/reauth', [
            'csrf_token' => $csrf,
            'password' => $attackerPassword,
            'totp_code' => Totp::getCode($attackerSecret),
        ]);
        $this->assertSame('/2fa/setup', $reauth->location(), "Step-up des Angreifers auf dem EIGENEN Konto muss gelingen, Body: {$reauth->body}");

        // 2. In DERSELBEN Sitzung: Passwort des Opfers eingeben. Faktor 1 ist
        //    damit für das Opfer erbracht, mehr nicht.
        $login = $attacker->post('/login', [
            'csrf_token' => $csrf,
            'email' => $victimEmail,
            'password' => $victimPassword,
        ]);
        $this->assertSame('/login/2fa', $login->location(), "Login mit dem Passwort des Opfers muss zur 2FA-Abfrage führen, Body: {$login->body}");

        // 3. /2fa/setup darf für das Opfer KEIN Secret ausliefern - weder das
        //    alte noch ein neues. Der Weg führt zur 2FA-Abfrage.
        $setupPage = $attacker->get('/2fa/setup');
        $this->assertNull(
            self::extractTotpSecret($setupPage),
            '/2fa/setup darf mit dem Step-up eines FREMDEN Kontos kein Secret für das Opfer erzeugen'
        );
        $this->assertStringNotContainsString(
            'Geheimer Schlüssel',
            $setupPage->body,
            '/2fa/setup darf für das Opfer keine Einrichtungsseite zeigen'
        );

        // 4. Auch der direkte POST auf /2fa/enable darf das Secret des Opfers
        //    nicht überschreiben.
        $enable = $attacker->post('/2fa/enable', [
            'csrf_token' => $csrf,
            'confirm_backup' => '1',
            'totp_code' => Totp::getCode($attackerSecret),
        ]);
        $this->assertNotSame(
            '/admin?2fa=enabled',
            $enable->location(),
            "POST /2fa/enable darf die 2FA eines fremden Kontos nicht aktivieren, Body: {$enable->body}"
        );

        // 5. Das Opfer meldet sich weiterhin mit seinem UNVERÄNDERTEN Secret
        //    an - der Beweis, dass nichts überschrieben wurde.
        self::resetTotpReplayGuard($victimEmail);
        $victim = $this->newClient();
        $victimLoginPage = $victim->get('/login');
        $victimLogin = $victim->post('/login', [
            'csrf_token' => $victimLoginPage->formField('csrf_token') ?? '',
            'email' => $victimEmail,
            'password' => $victimPassword,
        ]);
        $this->assertSame('/login/2fa', $victimLogin->location(), "Body: {$victimLogin->body}");

        $verifyPage = $victim->get('/login/2fa');
        $verify = $victim->post('/login/2fa', [
            'csrf_token' => $verifyPage->formField('csrf_token') ?? '',
            'totp_code' => Totp::getCode($victimSecret),
        ]);
        $this->assertSame(
            302,
            $verify->statusCode,
            "Das ursprüngliche Secret des Opfers muss weiterhin gültig sein, Body: {$verify->body}"
        );
    }

    /**
     * Ein neuer Passwort-Login löst die bestehende Anmeldung derselben
     * Sitzung ab. Ohne das laufen zwei Identitäten nebeneinander - die
     * Voraussetzung des Angriffs oben.
     */
    public function testNewLoginDiscardsPreviousSessionIdentity(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        $editorEmail = "wechsel-{$unique}@example.com";
        $editor = $this->createAndLoginEditor($admin, "wechsel{$unique}", $editorEmail);
        $this->assertSame(200, $editor->get('/admin')->statusCode, 'Editor ist zunächst angemeldet');

        $csrf = $this->csrfTokenOfLoggedInClient($editor);
        $this->assertNotSame('', $csrf);

        // Zweiter Login in derselben Sitzung, diesmal als Administrator.
        // Bis zur Bestätigung des zweiten Faktors darf die Sitzung NICHT mehr
        // als der vorherige Benutzer angemeldet sein.
        self::resetTotpReplayGuard(self::$adminEmail);
        $login = $editor->post('/login', [
            'csrf_token' => $csrf,
            'email' => self::$adminEmail,
            'password' => self::$adminPassword,
        ]);
        $this->assertSame('/login/2fa', $login->location(), "Body: {$login->body}");

        $adminArea = $editor->get('/admin');
        $this->assertNotSame(
            200,
            $adminArea->statusCode,
            'Nach einem neuen Faktor-1-Nachweis darf die alte Anmeldung nicht weiterlaufen'
        );
    }
}
