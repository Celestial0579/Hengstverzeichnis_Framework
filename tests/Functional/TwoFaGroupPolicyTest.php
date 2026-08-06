<?php
// tests/Functional/TwoFaGroupPolicyTest.php

namespace Tests\Functional;

/**
 * HTTP-Funktionstests für die pro Gruppe konfigurierbare 2FA-Pflicht
 * (Issue #84, siehe groups.require_2fa und
 * AuthController::userRequires2fa()):
 *
 * - Gruppen ohne 2FA-Pflicht: Mitglieder werden nach dem Passwort-Login NICHT
 *   in das 2FA-Setup gezwungen.
 * - Default bleibt verpflichtend (neue Gruppen: require_2fa = 1, Benutzer ganz
 *   ohne Gruppen: fail-safe verpflichtend) - der Status quo vor #84 gilt
 *   weiter, solange der Admin nichts abschaltet.
 * - Die Gruppe `admin` ist fest verdrahtet ausgenommen: ihre Pflicht ist
 *   nicht abschaltbar (serverseitig abgelehnt).
 */
class TwoFaGroupPolicyTest extends FunctionalTestCase {

    public function testGroupWithout2faRequirementSkipsForcedSetup(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        // Eigene Gruppe anlegen und 2FA-Pflicht abschalten.
        $groupsPage = $admin->get('/admin/groups');
        $createResponse = $admin->post('/admin/groups/create', [
            'csrf_token' => $groupsPage->formField('csrf_token') ?? '',
            'name' => "Ohne Zwei-FA {$unique}",
        ]);
        preg_match('/group=(\d+)/', (string)$createResponse->location(), $matches);
        $this->assertNotEmpty($matches, 'Konnte Gruppen-ID nicht ermitteln');
        $groupId = (int)$matches[1];

        $toggleResponse = $admin->post('/admin/groups/require-2fa', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'group_id' => (string)$groupId,
            // require_2fa bewusst NICHT gesetzt -> 0
        ]);
        $this->assertSame("/admin/groups?group={$groupId}&success=require_2fa_updated", $toggleResponse->location());

        // Benutzer in dieser Gruppe anlegen.
        $createForm = $admin->get('/admin/users/create');
        $userResponse = $admin->post('/admin/users/store', [
            'csrf_token' => $createForm->formField('csrf_token') ?? '',
            'username' => "no2fa{$unique}",
            'email' => "no2fa-{$unique}@example.com",
            'password' => 'OhneZweiFa123!',
            'groups' => [(string)$groupId],
        ]);
        $this->assertSame('/admin/users?success=created', $userResponse->location());

        // Login: KEIN Zwang zu /2fa/setup - der Login ist direkt abgeschlossen
        // (es folgt nur der verpflichtende Passwortwechsel der Erstanmeldung).
        $client = $this->newClient();
        $loginPage = $client->get('/login');
        $loginResponse = $client->post('/login', [
            'csrf_token' => $loginPage->formField('csrf_token') ?? '',
            'email' => "no2fa-{$unique}@example.com",
            'password' => 'OhneZweiFa123!',
        ]);
        $this->assertSame('/force-password-change', $loginResponse->location(), "Login ohne 2FA-Pflicht sollte direkt (bis auf Passwortwechsel) abgeschlossen sein, Body: {$loginResponse->body}");

        $forcePage = $client->get('/force-password-change');
        $changeResponse = $client->post('/force-password-change', [
            'csrf_token' => $forcePage->formField('csrf_token') ?? '',
            'password' => 'OhneZweiFaNeu456!',
            'password_confirm' => 'OhneZweiFaNeu456!',
        ]);
        $this->assertSame('/admin?password_changed=1', $changeResponse->location());
        $this->assertSame(200, $client->get('/admin')->statusCode);

        // Kein Bestandsschutz: Pflicht wieder aktivieren -> nächster Login
        // erzwingt das Setup.
        $reenable = $admin->post('/admin/groups/require-2fa', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'group_id' => (string)$groupId,
            'require_2fa' => '1',
        ]);
        $this->assertSame("/admin/groups?group={$groupId}&success=require_2fa_updated", $reenable->location());

        $secondClient = $this->newClient();
        $secondLoginPage = $secondClient->get('/login');
        $secondLogin = $secondClient->post('/login', [
            'csrf_token' => $secondLoginPage->formField('csrf_token') ?? '',
            'email' => "no2fa-{$unique}@example.com",
            'password' => 'OhneZweiFaNeu456!',
        ]);
        $this->assertSame('/2fa/setup', $secondLogin->location(), 'Nach Aktivieren der Gruppen-Pflicht muss der nächste Login das 2FA-Setup erzwingen');
    }

    public function testAdminGroupRequirementCannotBeDisabled(): void {
        $admin = $this->authenticatedClient();
        $adminGroupId = $this->findBuiltinGroupId($admin, 'Administrator');

        $response = $admin->post('/admin/groups/require-2fa', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'group_id' => (string)$adminGroupId,
        ]);
        $this->assertSame('/admin/groups?error=protected_group', $response->location());
    }
}
