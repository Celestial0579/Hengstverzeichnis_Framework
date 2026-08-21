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
