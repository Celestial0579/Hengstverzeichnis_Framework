<?php
// tests/Functional/TwoFaStepUpTest.php

namespace Tests\Functional;

use App\Security\Totp;

/**
 * HTTP-Funktionstests für die Step-up-Reauthentifizierung vor einer
 * 2FA-Neukonfiguration (Issue #112, siehe AuthController::show2faSetup()/
 * process2faReauth()/enable2fa()): Eine bereits angemeldete Session darf
 * totp_secret/backup_codes nicht ohne erneute Bestätigung von Passwort und
 * aktuellem TOTP-Code überschreiben. Secret und Backup-Codes stammen zudem
 * ausschließlich aus dem Server-State der Session, nicht aus POST-Feldern.
 */
class TwoFaStepUpTest extends FunctionalTestCase {

    public function testReSetupRequiresPasswordAndCurrentCode(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $editor = $this->createAndLoginEditor($admin, "stepup{$unique}", "stepup-{$unique}@example.com");
        $editorPassword = 'EditorTestNeu456!'; // siehe createAndLoginEditor()
        $oldSecret = $this->lastEditorTotpSecret;
        $this->assertNotNull($oldSecret);

        // 1. Angemeldete Session mit aktiver 2FA bekommt auf /2fa/setup KEIN
        //    neues Secret, sondern die Reauth-Abfrage.
        $setupPage = $editor->get('/2fa/setup');
        $this->assertSame(200, $setupPage->statusCode);
        $this->assertStringContainsString('2FA-Änderung bestätigen', $setupPage->body);
        $this->assertStringNotContainsString('Geheimer Schlüssel', $setupPage->body);

        // 2. Direkter POST /2fa/enable ohne Reauth wird abgelehnt.
        $directEnable = $editor->post('/2fa/enable', [
            'csrf_token' => $setupPage->formField('csrf_token') ?? '',
            'confirm_backup' => '1',
            'totp_code' => Totp::getCode($oldSecret),
        ]);
        $this->assertSame(403, $directEnable->statusCode, 'POST /2fa/enable ohne Step-up-Reauth muss abgelehnt werden');

        // 3. Reauth mit falschem Passwort scheitert.
        self::resetTotpReplayGuard("stepup-{$unique}@example.com");
        $wrongPassword = $editor->post('/2fa/reauth', [
            'csrf_token' => $setupPage->formField('csrf_token') ?? '',
            'password' => 'definitiv-falsch',
            'totp_code' => Totp::getCode($oldSecret),
        ]);
        $this->assertSame(200, $wrongPassword->statusCode);
        $this->assertStringContainsString('ungültig', $wrongPassword->body);

        // 4. Reauth mit korrektem Passwort + aktuellem Code schaltet die
        //    Neukonfiguration frei.
        self::resetTotpReplayGuard("stepup-{$unique}@example.com");
        $reauth = $editor->post('/2fa/reauth', [
            'csrf_token' => $setupPage->formField('csrf_token') ?? '',
            'password' => $editorPassword,
            'totp_code' => Totp::getCode($oldSecret),
        ]);
        $this->assertSame('/2fa/setup', $reauth->location(), "Reauth sollte zu /2fa/setup weiterleiten, Body: {$reauth->body}");

        // 5. Jetzt liefert /2fa/setup ein NEUES serverseitiges Secret ...
        $newSetupPage = $editor->get('/2fa/setup');
        $newSecret = self::extractTotpSecret($newSetupPage);
        $this->assertNotNull($newSecret, 'Nach Reauth sollte /2fa/setup ein neues Secret anzeigen');
        $this->assertNotSame($oldSecret, $newSecret);

        // ... und die Aktivierung mit dem Code des neuen Secrets gelingt.
        $enableResponse = $editor->post('/2fa/enable', [
            'csrf_token' => $newSetupPage->formField('csrf_token') ?? '',
            'confirm_backup' => '1',
            'totp_code' => Totp::getCode($newSecret),
        ]);
        $this->assertSame('/admin?2fa=enabled', $enableResponse->location(), "2FA-Neukonfiguration sollte gelingen, Body: {$enableResponse->body}");
    }

    public function testEnableIgnoresClientSuppliedSecret(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $editor = $this->createAndLoginEditor($admin, "stepupb{$unique}", "stepupb-{$unique}@example.com");
        $oldSecret = $this->lastEditorTotpSecret;

        // Reauth korrekt durchlaufen, dann versuchen, beim Enable ein eigenes
        // (Angreifer-)Secret per POST unterzuschieben: Der Server muss das
        // serverseitige Secret verwenden - ein Code zum untergeschobenen
        // Secret darf NICHT akzeptiert werden.
        self::resetTotpReplayGuard("stepupb-{$unique}@example.com");
        $anyPage = $editor->get('/2fa/setup');
        $reauth = $editor->post('/2fa/reauth', [
            'csrf_token' => $anyPage->formField('csrf_token') ?? '',
            'password' => 'EditorTestNeu456!',
            'totp_code' => Totp::getCode($oldSecret),
        ]);
        $this->assertSame('/2fa/setup', $reauth->location());
        $editor->get('/2fa/setup'); // erzeugt serverseitiges Setup-Secret

        $attackerSecret = Totp::generateSecret();
        $enableResponse = $editor->post('/2fa/enable', [
            'csrf_token' => $anyPage->formField('csrf_token') ?? '',
            'totp_secret' => $attackerSecret,
            'backup_codes' => json_encode(['AAAA-BBBB']),
            'confirm_backup' => '1',
            'totp_code' => Totp::getCode($attackerSecret),
        ]);

        // Kein Redirect ins Backend: Der Code passt nicht zum serverseitigen
        // Secret, die POST-Felder wurden ignoriert.
        $this->assertSame(200, $enableResponse->statusCode);
        $this->assertStringContainsString('Ungültiger 6-stelliger Code', $enableResponse->body);
    }
}
