<?php
// tests/Functional/ProfileTest.php

namespace Tests\Functional;

use App\Database;
use Tests\Support\HttpClient;

/**
 * Die Selbstbedienungsseite /profil (#357).
 *
 * WAS VORHER FEHLTE: Ein angemeldeter Benutzer konnte sein Passwort nicht
 * ändern. Es blieb der Umweg über „Passwort vergessen" und eine Mail - und
 * für Konten ohne E-Mail-Adresse (die mit #348 absichtlich entstehen) gab es
 * diesen Umweg gar nicht.
 */
class ProfileTest extends FunctionalTestCase {

    use ApiKeyHelper;

    private function spalte(string $benutzer, string $spalte) {
        $stmt = Database::getInstance()->prepare("SELECT `{$spalte}` FROM users WHERE username = ?");
        $stmt->execute([$benutzer]);
        return $stmt->fetchColumn();
    }

    // ---- Erreichbarkeit ------------------------------------------------

    public function testProfileIsReachableForAnyLoggedInUserRegardlessOfPermissions(): void {
        $admin = $this->authenticatedClient();
        $u = uniqid();
        // Ohne jede Gruppe - also ohne ein einziges Recht.
        $editor = $this->createAndLoginEditor($admin, "prof{$u}", "prof-{$u}@example.com");

        $seite = $editor->get('/profil');
        $this->assertSame(200, $seite->statusCode, 'Die Profilseite gehört jedem angemeldeten Benutzer.');
        $this->assertStringContainsString('Passwort ändern', $seite->body);
    }

    public function testEveryProfileRouteRefusesAnonymousAccess(): void {
        $gast = $this->newClient();
        // Token von /login - das gibt es auch fuer eine abgemeldete Sitzung.
        // Ohne gueltiges Token pruefte der POST nur den CSRF-Zweig und der
        // Test waere gruen, ohne die Anmeldeschranke je erreicht zu haben.
        $token = $gast->get('/login')->formField('csrf_token') ?? '';
        $this->assertNotSame('', $token);

        $this->assertSame('/login', $gast->get('/profil')->location());
        foreach (['/profil/passwort', '/profil/backup-codes', '/profil/email', '/profil/email/abbrechen'] as $route) {
            $antwort = $gast->post($route, ['csrf_token' => $token]);
            $this->assertSame('/login', $antwort->location(), "{$route} muss abgemeldet auf /login leiten.");
        }
    }

    // ---- Passwort ------------------------------------------------------

    /**
     * Der Wechsel muss dasselbe bewirken wie der erzwungene: alle Sitzungen
     * enden, alle API-Schlüssel sind entwertet. Ohne das verspräche die Seite
     * etwas, das sie nicht tut.
     */
    public function testChangingThePasswordEndsAllSessionsAndRevokesApiKeys(): void {
        $admin = $this->authenticatedClient();
        $u = uniqid();
        $name = "profpw{$u}";
        // Mit einem Recht: Ein Schluessel ohne jedes Recht waere ein
        // kuenstlicher Fall, und die Verwaltung zeigt dann keine Auswahl an.
        $gruppe = $this->createCustomGroup($admin, "Profil Leser {$u}");
        $this->setGroupPermissions($admin, $gruppe, ['horses' => ['view']]);
        $editor = $this->createAndLoginEditor($admin, $name, "profpw-{$u}@example.com", [$gruppe]);

        $token = $this->createApiKey($editor, "Profiltest {$u}");
        $this->assertSame(200, $this->newClient()->get('/api/horses', $this->bearer($token))->statusCode);

        $vorher = (string)$this->spalte($name, 'password_hash');
        $version = (int)$this->spalte($name, 'session_version');

        $antwort = $editor->post('/profil/passwort', [
            'csrf_token' => $this->editorCsrfToken($editor),
            'current_password' => 'EditorTestNeu456!',
            'new_password' => 'GanzNeuesPasswort99!',
            'new_password_confirm' => 'GanzNeuesPasswort99!',
        ]);

        $this->assertSame('/login?success=password_changed', $antwort->location());
        $this->assertNotSame($vorher, (string)$this->spalte($name, 'password_hash'));
        $this->assertSame(
            $version + 1,
            (int)$this->spalte($name, 'session_version'),
            'Ohne session_version + 1 laufen fremde Sitzungen weiter.'
        );
        $this->assertSame(
            401,
            $this->newClient()->get('/api/horses', $this->bearer($token))->statusCode,
            'Der Schlüssel muss nach dem Passwortwechsel entwertet sein.'
        );

        // UND der Widerruf datenbankseitig. Die 401 oben allein beweist ihn
        // NICHT: Schon `session_version + 1` lässt den Schlüssel durchfallen
        // (ApiKey::authenticate vergleicht issued_session_version). Ohne diese
        // Zeile bliebe der Test grün, wenn man revokeAllForUser() streicht -
        // aufgefallen in der Gegenprobe.
        $stmt = Database::getInstance()->prepare(
            "SELECT COUNT(*) FROM api_keys k JOIN users u ON u.id = k.user_id
             WHERE u.username = ? AND k.revoked_at IS NULL"
        );
        $stmt->execute([$name]);
        $this->assertSame(
            0,
            (int)$stmt->fetchColumn(),
            'Alle Schlüssel des Kontos müssen ausdrücklich widerrufen sein, nicht nur faktisch ungültig.'
        );
        $this->assertSame('/login', $editor->get('/admin')->location(), 'Auch die eigene Sitzung endet.');
    }

    public function testAWrongCurrentPasswordChangesNothing(): void {
        $admin = $this->authenticatedClient();
        $u = uniqid();
        $name = "profpwfalsch{$u}";
        $editor = $this->createAndLoginEditor($admin, $name, "profpwf-{$u}@example.com");
        $vorher = (string)$this->spalte($name, 'password_hash');

        $antwort = $editor->post('/profil/passwort', [
            'csrf_token' => $this->editorCsrfToken($editor),
            'current_password' => 'das-ist-es-nicht',
            'new_password' => 'GanzNeuesPasswort99!',
            'new_password_confirm' => 'GanzNeuesPasswort99!',
        ]);

        $this->assertSame('/profil?error=current_password_wrong', $antwort->location());
        $this->assertSame($vorher, (string)$this->spalte($name, 'password_hash'), 'Nichts darf geschrieben worden sein.');
    }

    public function testMismatchedRepetitionIsRefused(): void {
        $admin = $this->authenticatedClient();
        $u = uniqid();
        $name = "profpwmis{$u}";
        $editor = $this->createAndLoginEditor($admin, $name, "profpwm-{$u}@example.com");
        $vorher = (string)$this->spalte($name, 'password_hash');

        $antwort = $editor->post('/profil/passwort', [
            'csrf_token' => $this->editorCsrfToken($editor),
            'current_password' => 'EditorTestNeu456!',
            'new_password' => 'GanzNeuesPasswort99!',
            'new_password_confirm' => 'etwasAnderes12345!',
        ]);

        $this->assertSame('/profil?error=mismatch', $antwort->location());
        $this->assertSame($vorher, (string)$this->spalte($name, 'password_hash'));
    }

    public function testPasswordChangeRequiresCsrfToken(): void {
        $admin = $this->authenticatedClient();
        $u = uniqid();
        $name = "profpwcsrf{$u}";
        $editor = $this->createAndLoginEditor($admin, $name, "profpwc-{$u}@example.com");
        $vorher = (string)$this->spalte($name, 'password_hash');

        $antwort = $editor->post('/profil/passwort', [
            'current_password' => 'EditorTestNeu456!',
            'new_password' => 'GanzNeuesPasswort99!',
            'new_password_confirm' => 'GanzNeuesPasswort99!',
        ]);

        $this->assertSame(403, $antwort->statusCode);
        $this->assertSame($vorher, (string)$this->spalte($name, 'password_hash'));
    }

    // ---- Backup-Codes --------------------------------------------------

    public function testProfileShowsTheNumberOfRemainingBackupCodes(): void {
        $admin = $this->authenticatedClient();
        $u = uniqid();
        $editor = $this->createAndLoginEditor($admin, "profbc{$u}", "profbc-{$u}@example.com");

        $seite = $editor->get('/profil');
        $this->assertSame(200, $seite->statusCode);
        $this->assertStringContainsString('Noch <strong>10</strong> ungenutzte Backup-Code(s)', $seite->body);
    }

    /**
     * Zehn frische Backup-Codes sind dasselbe Material wie ein neues Geheimnis.
     * Deshalb Passwort UND TOTP - derselbe Maßstab wie beim Einrichten (#112).
     */
    public function testRegeneratingBackupCodesNeedsPasswordAndTotp(): void {
        $admin = $this->authenticatedClient();
        $u = uniqid();
        $name = "profbcneu{$u}";
        $editor = $this->createAndLoginEditor($admin, $name, "profbcn-{$u}@example.com");
        $secret = $this->lastEditorTotpSecret;
        $this->assertNotNull($secret);

        $vorher = (string)$this->spalte($name, 'backup_codes');

        // Ohne gültigen TOTP-Code passiert nichts.
        $antwort = $editor->post('/profil/backup-codes', [
            'csrf_token' => $this->editorCsrfToken($editor),
            'current_password' => 'EditorTestNeu456!',
            'totp_code' => '000000',
        ]);
        $this->assertSame('/profil?error=totp_wrong', $antwort->location());
        $this->assertSame($vorher, (string)$this->spalte($name, 'backup_codes'), 'Ohne gültigen Code darf sich nichts ändern.');

        // Und ohne Passwort auch nicht - selbst mit gültigem Code.
        self::resetTotpReplayGuard("profbcn-{$u}@example.com");
        $antwort = $editor->post('/profil/backup-codes', [
            'csrf_token' => $this->editorCsrfToken($editor),
            'current_password' => 'falsch',
            'totp_code' => \App\Security\Totp::getCode($secret),
        ]);
        $this->assertSame('/profil?error=current_password_wrong', $antwort->location());
        $this->assertSame($vorher, (string)$this->spalte($name, 'backup_codes'));

        // Mit beidem: zehn neue Codes, einmalig angezeigt.
        self::resetTotpReplayGuard("profbcn-{$u}@example.com");
        $antwort = $editor->post('/profil/backup-codes', [
            'csrf_token' => $this->editorCsrfToken($editor),
            'current_password' => 'EditorTestNeu456!',
            'totp_code' => \App\Security\Totp::getCode($secret),
        ]);
        $this->assertSame('/profil?success=backup_codes', $antwort->location());
        $nachher = (string)$this->spalte($name, 'backup_codes');
        $this->assertNotSame($vorher, $nachher, 'Die alten Codes müssen ersetzt sein.');
        $this->assertCount(10, json_decode($nachher, true));

        $seite = $editor->get('/profil');
        $this->assertStringContainsString('Ihre neuen Backup-Codes', $seite->body);
        // Und wirklich nur EINMAL.
        $this->assertStringNotContainsString('Ihre neuen Backup-Codes', $editor->get('/profil')->body);
    }

    // ---- E-Mail-Adresse ------------------------------------------------

    /**
     * Die neue Adresse gilt erst nach Bestätigung. Sonst trägt sich ein
     * Angreifer mit übernommener Sitzung eine eigene ein und übernimmt damit
     * den Passwort-Reset-Weg.
     */
    public function testANewAddressOnlyTakesEffectAfterConfirmation(): void {
        $admin = $this->authenticatedClient();
        $u = uniqid();
        $name = "profmail{$u}";
        $alt = "profmail-{$u}@example.com";
        $neu = "profmail-neu-{$u}@example.com";
        $editor = $this->createAndLoginEditor($admin, $name, $alt);

        $antwort = $editor->post('/profil/email', [
            'csrf_token' => $this->editorCsrfToken($editor),
            'new_email' => $neu,
            'current_password' => 'EditorTestNeu456!',
        ]);

        $this->assertSame('/profil?success=email_requested', $antwort->location());
        $this->assertSame($alt, (string)$this->spalte($name, 'email'), 'Bis zur Bestätigung gilt die alte Adresse.');
        $this->assertSame($neu, (string)$this->spalte($name, 'pending_email'));

        // Der Link aus der Mail - ohne Anmeldung erreichbar.
        $tokenHash = (string)$this->spalte($name, 'pending_email_token');
        $this->assertNotSame('', $tokenHash);
        $klartext = $this->tokenFuerHash($name);

        $bestaetigt = $this->newClient()->get('/profil/email/bestaetigen?token=' . urlencode($klartext));
        $this->assertSame('/profil?success=email_changed', $bestaetigt->location());
        $this->assertSame($neu, (string)$this->spalte($name, 'email'));
        $this->assertNull($this->spalte($name, 'pending_email'));
    }

    public function testAnInvalidConfirmationTokenChangesNothing(): void {
        $admin = $this->authenticatedClient();
        $u = uniqid();
        $name = "profmailtok{$u}";
        $alt = "profmailtok-{$u}@example.com";
        $editor = $this->createAndLoginEditor($admin, $name, $alt);
        $editor->post('/profil/email', [
            'csrf_token' => $this->editorCsrfToken($editor),
            'new_email' => "andere-{$u}@example.com",
            'current_password' => 'EditorTestNeu456!',
        ]);

        $antwort = $this->newClient()->get('/profil/email/bestaetigen?token=' . str_repeat('a', 64));

        $this->assertSame('/profil?error=email_token_invalid', $antwort->location());
        $this->assertSame($alt, (string)$this->spalte($name, 'email'));
    }

    public function testRequestingAnAddressNeedsTheCurrentPassword(): void {
        $admin = $this->authenticatedClient();
        $u = uniqid();
        $name = "profmailpw{$u}";
        $editor = $this->createAndLoginEditor($admin, $name, "profmailpw-{$u}@example.com");

        $antwort = $editor->post('/profil/email', [
            'csrf_token' => $this->editorCsrfToken($editor),
            'new_email' => "neu-{$u}@example.com",
            'current_password' => 'falsch',
        ]);

        $this->assertSame('/profil?error=current_password_wrong', $antwort->location());
        $this->assertNull($this->spalte($name, 'pending_email'), 'Ohne Passwort darf kein Antrag entstehen.');
    }

    // ---- Helfer --------------------------------------------------------

    /**
     * Der Klartext des Tokens steht nirgends - gespeichert ist nur sein
     * SHA-256-Abdruck. Für den Test wird deshalb ein bekannter Wert gesetzt
     * und sein Abdruck eingetragen; das ist exakt das, was der Controller
     * beim Anlegen tut.
     */
    private function createCustomGroup(HttpClient $admin, string $name): int {
        $groupsPage = $admin->get('/admin/groups');
        $response = $admin->post('/admin/groups/create', [
            'csrf_token' => $groupsPage->formField('csrf_token') ?? '',
            'name' => $name,
        ]);
        preg_match('/group=(\d+)/', (string)$response->location(), $matches);
        $this->assertNotEmpty($matches, "Konnte neue Gruppen-ID nicht ermitteln, Body: {$response->body}");
        return (int)$matches[1];
    }

    private function tokenFuerHash(string $benutzer): string {
        $klartext = bin2hex(random_bytes(32));
        Database::getInstance()
            ->prepare('UPDATE users SET pending_email_token = ? WHERE username = ?')
            ->execute([hash('sha256', $klartext), $benutzer]);
        return $klartext;
    }

}
