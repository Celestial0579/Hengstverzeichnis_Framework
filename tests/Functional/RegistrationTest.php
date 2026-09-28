<?php
// tests/Functional/RegistrationTest.php

namespace Tests\Functional;

/**
 * HTTP-Funktionstests für die abschaltbare Selfservice-Registrierung
 * (Issue #83, siehe RegistrationController): Standard aus (404), Aktivierung
 * über die Systemeinstellungen, Pflicht zur E-Mail-Verifizierung vor der
 * Erstanmeldung, Standard-Gruppen-Zuweisung sowie reservierte Benutzernamen.
 *
 * Der Verifizierungs-Token wird direkt aus der Test-Datenbank gelesen
 * (SMTP-Versand steht in der Testumgebung nicht zur Verfügung und schlägt
 * bewusst leise fehl, siehe Mailer::sendViaSmtp()).
 */
class RegistrationTest extends FunctionalTestCase {

    public function testRegistrationFlowWithEmailVerification(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        // 1. Standard: Registrierung deaktiviert -> 404.
        $client = $this->newClient();
        $this->assertSame(404, $client->get('/register')->statusCode);
        $this->assertSame(404, $client->post('/register', ['username' => 'x'])->statusCode);

        // 2. Standard-Gruppe anlegen (ohne 2FA-Pflicht, damit der Login des
        //    Neukontos direkt durchläuft) und Registrierung aktivieren.
        $groupsPage = $admin->get('/admin/groups');
        $createGroup = $admin->post('/admin/groups/create', [
            'csrf_token' => $groupsPage->formField('csrf_token') ?? '',
            'name' => "Registrierte {$unique}",
        ]);
        preg_match('/group=(\d+)/', (string)$createGroup->location(), $matches);
        $groupId = (int)$matches[1];
        $admin->post('/admin/groups/require-2fa', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'group_id' => (string)$groupId,
        ]);

        $this->saveRegistrationSettings($admin, true, $groupId);

        try {
            // 3. Formular erreichbar, reservierter Benutzername abgelehnt.
            $registerPage = $client->get('/register');
            $this->assertSame(200, $registerPage->statusCode);

            $reserved = $client->post('/register', [
                'csrf_token' => $registerPage->formField('csrf_token') ?? '',
                'username' => 'admin',
                'email' => "reserved-{$unique}@example.com",
                'password' => 'Registrier123!',
                'password_confirm' => 'Registrier123!',
            ]);
            $this->assertStringContainsString('reserviert', $reserved->body);

            // 4. Erfolgreiche Registrierung.
            $email = "selfservice-{$unique}@example.com";
            $response = $client->post('/register', [
                'csrf_token' => $registerPage->formField('csrf_token') ?? '',
                'username' => "selfservice{$unique}",
                'email' => $email,
                'password' => 'Registrier123!',
                'password_confirm' => 'Registrier123!',
            ]);
            $this->assertSame('/register?sent=1', $response->location(), "Registrierung sollte gelingen, Body: {$response->body}");

            // 5. Login vor Verifizierung: gesperrt.
            $loginPage = $client->get('/login');
            $blockedLogin = $client->post('/login', [
                'csrf_token' => $loginPage->formField('csrf_token') ?? '',
                'kennung' => $email,
                'password' => 'Registrier123!',
            ]);
            $this->assertStringContainsString('bestätigen Sie zunächst Ihre E-Mail-Adresse', $blockedLogin->body);

            // 6. Verifizierung: ungültiger Token -> Fehlermeldung, echter Token
            //    (aus der Test-DB) -> Freischaltung.
            $db = \App\Database::getInstance();
            $stmt = $db->prepare("SELECT email_verification_token FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $token = (string)$stmt->fetchColumn();
            $this->assertNotSame('', $token);

            $invalidVerify = $client->get('/verify-email?token=definitiv-falsch');
            $this->assertStringContainsString('ungültig oder abgelaufen', $invalidVerify->body);

            $verify = $client->get('/verify-email?token=' . urlencode($token));
            $this->assertSame('/login?success=email_verified', $verify->location());

            // 7. Standard-Gruppe wurde zugewiesen.
            $stmt = $db->prepare("SELECT COUNT(*) FROM user_groups ug JOIN users u ON u.id = ug.user_id WHERE u.email = ? AND ug.group_id = ?");
            $stmt->execute([$email, $groupId]);
            $this->assertSame(1, (int)$stmt->fetchColumn(), 'Neues Konto sollte in der Standard-Gruppe gelandet sein');

            // 8. Login nach Verifizierung: erfolgreich, ohne 2FA-Zwang (Gruppe
            //    ohne Pflicht, #84) und ohne Passwortwechsel-Zwang (selbst
            //    gewähltes Passwort).
            $login = $client->post('/login', [
                'csrf_token' => $loginPage->formField('csrf_token') ?? '',
                'kennung' => $email,
                'password' => 'Registrier123!',
            ]);
            $this->assertSame('/admin', $login->location(), "Login nach Verifizierung sollte direkt durchlaufen, Body: {$login->body}");
        } finally {
            $this->saveRegistrationSettings($admin, false, 0);
        }

        // 9. Wieder deaktiviert -> 404.
        $this->assertSame(404, $this->newClient()->get('/register')->statusCode);
    }

    // ---- Audit N54: abgelaufener Link, Neuversand, Höchstlebensdauer -------

    private const PASSWORT = 'Registrier123!';

    /**
     * Registriert ein Konto über das Formular (Registrierung dafür kurz
     * eingeschaltet, Standardgruppe ohne 2FA-Pflicht) und gibt Client,
     * Adresse und ID zurück. Die IP-Drossel der Registrierung (5/h) wird
     * vorher geleert - sonst hinge das Ergebnis davon ab, wie viele Tests
     * vorher registriert haben.
     *
     * @return array{0: \Tests\Support\HttpClient, 1: string, 2: int}
     */
    private function registriertesKonto(): array {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $groupId = $this->createGroupWithoutTwoFa($admin, "Registriert N54 {$unique}");
        $db = \App\Database::getInstance();
        $db->exec("DELETE FROM login_attempts WHERE type = 'registration'");

        $this->saveRegistrationSettings($admin, true, $groupId);
        try {
            $client = $this->newClient();
            $email = "n54-{$unique}@example.com";
            $antwort = $client->post('/register', [
                'csrf_token' => $client->get('/register')->formField('csrf_token') ?? '',
                'username' => "n54{$unique}",
                'email' => $email,
                'password' => self::PASSWORT,
                'password_confirm' => self::PASSWORT,
            ]);
            $this->assertSame('/register?sent=1', $antwort->location(), "Body: {$antwort->body}");
        } finally {
            $this->saveRegistrationSettings($admin, false, 0);
        }

        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        return [$client, $email, (int)$stmt->fetchColumn()];
    }

    /** @return array{token: ?string, ablauf: ?string, erstellt: string, abgelaufen: int} */
    private function verifizierung(int $id): array {
        $stmt = \App\Database::getInstance()->prepare(
            "SELECT email_verification_token AS token, email_verification_expires_at AS ablauf, created_at AS erstellt,
                    (email_verification_expires_at <= NOW()) AS abgelaufen,
                    (email_verification_expires_at <= created_at + INTERVAL 9 DAY) AS innerhalb
             FROM users WHERE id = ?"
        );
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    private function neuversandEintraege(int $id): int {
        $stmt = \App\Database::getInstance()->prepare(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'Bestätigungslink erneut versendet' AND user_id = ?"
        );
        $stmt->execute([$id]);
        return (int)$stmt->fetchColumn();
    }

    private function anmelden(\Tests\Support\HttpClient $client, string $email): \Tests\Support\HttpResponse {
        return $client->post('/login', [
            'csrf_token' => $client->get('/login')->formField('csrf_token') ?? '',
            'kennung' => $email,
            'password' => self::PASSWORT,
        ]);
    }

    public function testAbgelaufenerLinkWirdBeiDerAnmeldungErneuert(): void {
        [$client, $email, $id] = $this->registriertesKonto();
        $db = \App\Database::getInstance();
        $db->prepare("UPDATE users SET email_verification_expires_at = NOW() - INTERVAL 1 HOUR WHERE id = ?")->execute([$id]);
        $alt = $this->verifizierung($id)['token'];

        $this->assertStringContainsString(
            'ungültig oder abgelaufen',
            $client->get('/verify-email?token=' . urlencode((string)$alt))->body
        );

        $gesperrt = $this->anmelden($client, $email);
        $this->assertStringContainsString('bestätigen Sie zunächst', $gesperrt->body);

        $neu = $this->verifizierung($id);
        $this->assertNotSame($alt, $neu['token'], 'Ein abgelaufener Link muss ersetzt werden.');
        $this->assertSame(0, (int)$neu['abgelaufen']);

        $verify = $client->get('/verify-email?token=' . urlencode((string)$neu['token']));
        $this->assertSame('/login?success=email_verified', $verify->location());
        $this->assertSame('/admin', $this->anmelden($client, $email)->location());
    }

    public function testNeuversandIstGedrosseltUndGueltigesTokenBleibt(): void {
        [$client, $email, $id] = $this->registriertesKonto();
        $token = $this->verifizierung($id)['token'];

        for ($i = 0; $i < 5; $i++) {
            $this->assertStringContainsString('bestätigen Sie zunächst', $this->anmelden($client, $email)->body);
        }

        $this->assertSame(3, $this->neuversandEintraege($id), 'Höchstens drei Neuversände am Tag.');
        $this->assertSame($token, $this->verifizierung($id)['token'], 'Ein noch gültiger Link bleibt gültig.');
    }

    public function testKeinNeuversandUeberDieHoechstlebensdauer(): void {
        [$client, $email, $id] = $this->registriertesKonto();
        \App\Database::getInstance()->prepare(
            "UPDATE users SET created_at = NOW() - INTERVAL 10 DAY, email_verification_expires_at = NOW() - INTERVAL 8 DAY WHERE id = ?"
        )->execute([$id]);
        $token = $this->verifizierung($id)['token'];

        $this->assertStringContainsString('bestätigen Sie zunächst', $this->anmelden($client, $email)->body);

        $this->assertSame($token, $this->verifizierung($id)['token']);
        $this->assertSame(0, $this->neuversandEintraege($id));
    }

    public function testNeuerLinkEndetSpaetestensMitDerHoechstlebensdauer(): void {
        [$client, $email, $id] = $this->registriertesKonto();
        \App\Database::getInstance()->prepare(
            "UPDATE users SET created_at = NOW() - INTERVAL 8 DAY, email_verification_expires_at = NOW() - INTERVAL 6 DAY WHERE id = ?"
        )->execute([$id]);
        $token = $this->verifizierung($id)['token'];

        $this->anmelden($client, $email);

        $neu = $this->verifizierung($id);
        $this->assertNotSame($token, $neu['token']);
        $this->assertSame(0, (int)$neu['abgelaufen']);
        $this->assertSame(1, (int)$neu['innerhalb'], 'Der neue Link darf nicht über created_at + 9 Tage hinaus gelten.');
    }

    public function testPasswortResetBestaetigtDieAdresse(): void {
        [$client, $email, $id] = $this->registriertesKonto();
        $resetToken = bin2hex(random_bytes(32));
        \App\Database::getInstance()->prepare(
            "INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, NOW() + INTERVAL 15 MINUTE)"
        )->execute([$email, hash('sha256', $resetToken)]);

        $seite = $client->get('/reset-password?token=' . $resetToken);
        $neuesPasswort = 'Uebernommen789!';
        $reset = $client->post('/reset-password', [
            'csrf_token' => $seite->formField('csrf_token') ?? '',
            'token' => $resetToken,
            'password' => $neuesPasswort,
            'password_confirm' => $neuesPasswort,
        ]);
        $this->assertSame('/login?success=password_reset', $reset->location(), "Body: {$reset->body}");
        $this->assertNull($this->verifizierung($id)['token']);

        $login = $client->post('/login', [
            'csrf_token' => $client->get('/login')->formField('csrf_token') ?? '',
            'kennung' => $email,
            'password' => $neuesPasswort,
        ]);
        $this->assertSame('/admin', $login->location(), "Body: {$login->body}");
    }

    private function saveRegistrationSettings(\Tests\Support\HttpClient $admin, bool $enabled, int $defaultGroupId): void {
        $page = $admin->get('/admin/system-settings');
        $fields = [
            'csrf_token' => $page->formField('csrf_token') ?? '',
            'base_url' => '',
            'language' => 'de',
            'registration_default_group' => (string)$defaultGroupId,
        ];
        if ($enabled) {
            $fields['registration_enabled'] = '1';
        }
        $response = $admin->post('/admin/system-settings', $fields);
        $this->assertStringContainsString('/admin/system-settings?success=1', (string)$response->location());
    }
}
