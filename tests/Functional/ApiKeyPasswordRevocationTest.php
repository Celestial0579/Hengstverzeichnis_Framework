<?php
// tests/Functional/ApiKeyPasswordRevocationTest.php

namespace Tests\Functional;

/**
 * HTTP-Funktionstests für #217: API-Schlüssel dürfen die Incident-Response-
 * Kette "Passwort zurücksetzen -> alle Zugänge tot" nicht als zweites,
 * dauerhaftes Credential überleben, und ein Admin muss fremde Schlüssel sehen
 * und widerrufen können (beides fehlte, siehe App\Security\ApiKey).
 *
 * Kernaussagen, die hier abgesichert werden:
 * - Setzt ein Admin das Passwort eines Benutzers neu, ist ein zuvor
 *   funktionierender Schlüssel dieses Benutzers sofort unbrauchbar - und zwar
 *   ausdrücklich widerrufen (revoked_at), nicht nur implizit über die
 *   session_version-Kopplung abgelehnt.
 * - Die Admin-Bearbeitungsseite (/admin/users/edit) listet die aktiven
 *   Schlüssel des Kontos (nur Metadaten, nie den Schlüsselwert) und bietet
 *   "Alle widerrufen" an; der Widerruf wirkt sofort.
 * - Der Admin-Widerruf ist ein POST mit CSRF-Pflicht: ohne gültigen Token
 *   passiert nichts (403), die Schlüssel bleiben gültig.
 */
class ApiKeyPasswordRevocationTest extends FunctionalTestCase {

    use ApiKeyHelper;

    public function testAdminPasswordChangeInvalidatesExistingKeys(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $username = "apipwrevoke{$unique}";
        // Mitglied der eingebauten Editor-Gruppe: Ohne mindestens ein eigenes
        // Recht bietet /api-keys kein Anlege-Formular an (ein Schlüssel wäre
        // wirkungslos) und der ApiKeyHelper könnte keinen Schlüssel erzeugen.
        $editorGroupId = $this->findBuiltinGroupId($admin, 'Editor');
        $editor = $this->createAndLoginEditor($admin, $username, "api-pw-revoke-{$unique}@example.com", [$editorGroupId]);

        $token = $this->createApiKey($editor, "Vor Passwortänderung {$unique}");

        // Ausgangslage: der Schlüssel funktioniert (200 braucht nur einen
        // gültigen Schlüssel, keine Rechte - siehe ApiKeyAuthTest zum Scope).
        $client = $this->newClient();
        $before = $client->get('/api/horses?per_page=10', $this->bearer($token));
        $this->assertSame(200, $before->statusCode, 'Der frisch angelegte Schlüssel muss zunächst funktionieren.');

        $editorId = $this->findUserIdByUsername($admin, $username);

        // Admin setzt ein neues Passwort - die typische Reaktion auf einen
        // Kompromittierungsverdacht.
        $editPage = $admin->get('/admin/users/edit?id=' . $editorId);
        $updateResponse = $admin->post('/admin/users/update', [
            'csrf_token' => $editPage->formField('csrf_token') ?? '',
            'id' => (string)$editorId,
            'username' => $username,
            'email' => "api-pw-revoke-{$unique}@example.com",
            'password' => 'NeuGesetzt789!',
            'groups' => [(string)$editorGroupId],
        ]);
        $this->assertSame('/admin/users?success=updated', $updateResponse->location());

        // Der zuvor funktionierende Schlüssel ist jetzt unbrauchbar.
        $after = $client->get('/api/horses?per_page=10', $this->bearer($token));
        $this->assertSame(
            401,
            $after->statusCode,
            'Ein vor der Passwortänderung ausgestellter Schlüssel darf danach keinen Zugriff mehr gewähren.'
        );

        // Und zwar AUSDRÜCKLICH widerrufen, nicht nur implizit abgelehnt: die
        // Admin-Sicht zeigt keine aktiven Schlüssel mehr (revoked_at gesetzt).
        $editPageAfter = $admin->get('/admin/users/edit?id=' . $editorId);
        $this->assertStringContainsString(
            'keine aktiven API-Schlüssel',
            $editPageAfter->body,
            'Nach der Passwort-Neusetzung darf das Konto keine aktiven Schlüssel mehr besitzen.'
        );
    }

    public function testAdminCanListAndRevokeAllKeysOfAccount(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $username = "apiadminrev{$unique}";
        // Editor-Gruppe: siehe testAdminPasswordChangeInvalidatesExistingKeys.
        $editorGroupId = $this->findBuiltinGroupId($admin, 'Editor');
        $editor = $this->createAndLoginEditor($admin, $username, "api-admin-rev-{$unique}@example.com", [$editorGroupId]);

        $labelOne = "Adminsicht Schlüssel A {$unique}";
        $labelTwo = "Adminsicht Schlüssel B {$unique}";
        $tokenOne = $this->createApiKey($editor, $labelOne);
        $tokenTwo = $this->createApiKey($editor, $labelTwo);

        $editorId = $this->findUserIdByUsername($admin, $username);

        // Die Bearbeitungsseite listet beide Schlüssel mit Metadaten - aber
        // niemals einen vollständigen Schlüsselwert (der existiert nur als Hash).
        $editPage = $admin->get('/admin/users/edit?id=' . $editorId);
        $this->assertSame(200, $editPage->statusCode);
        $this->assertStringContainsString(htmlspecialchars($labelOne), $editPage->body);
        $this->assertStringContainsString(htmlspecialchars($labelTwo), $editPage->body);
        $this->assertStringContainsString(substr($tokenOne, 0, 11), $editPage->body, 'Das Anzeige-Präfix gehört in die Admin-Sicht.');
        $this->assertStringContainsString('Alle widerrufen', $editPage->body);
        $this->assertDoesNotMatchRegularExpression(
            '/hv_[0-9a-f]{64}/',
            $editPage->body,
            'Die Admin-Sicht darf niemals einen vollständigen Schlüsselwert enthalten.'
        );

        // Widerruf über die echte Route.
        $revokeResponse = $admin->post('/admin/users/revoke-api-keys', [
            'csrf_token' => $editPage->formField('csrf_token') ?? '',
            'id' => (string)$editorId,
        ]);
        $this->assertSame(
            "/admin/users/edit?id={$editorId}&success=api_keys_revoked",
            $revokeResponse->location(),
            'Der Admin-Widerruf sollte zurück auf die Bearbeitungsseite führen.'
        );

        // Beide Schlüssel sind sofort ungültig.
        $client = $this->newClient();
        $this->assertSame(401, $client->get('/api/horses?per_page=10', $this->bearer($tokenOne))->statusCode);
        $this->assertSame(401, $client->get('/api/horses?per_page=10', $this->bearer($tokenTwo))->statusCode);

        // Und die Liste ist leer.
        $editPageAfter = $admin->get('/admin/users/edit?id=' . $editorId);
        $this->assertStringNotContainsString(htmlspecialchars($labelOne), $editPageAfter->body);
        $this->assertStringContainsString('keine aktiven API-Schlüssel', $editPageAfter->body);
    }

    public function testAdminRevokeRequiresValidCsrfToken(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $username = "apicsrfrev{$unique}";
        // Editor-Gruppe: siehe testAdminPasswordChangeInvalidatesExistingKeys.
        $editorGroupId = $this->findBuiltinGroupId($admin, 'Editor');
        $editor = $this->createAndLoginEditor($admin, $username, "api-csrf-rev-{$unique}@example.com", [$editorGroupId]);

        $token = $this->createApiKey($editor, "CSRF-Pflicht {$unique}");
        $editorId = $this->findUserIdByUsername($admin, $username);

        // Ohne gültigen CSRF-Token wird der Widerruf abgelehnt ...
        $attempt = $admin->post('/admin/users/revoke-api-keys', [
            'csrf_token' => 'ungueltig',
            'id' => (string)$editorId,
        ]);
        $this->assertSame(403, $attempt->statusCode, 'Der Admin-Widerruf muss CSRF-geschützt sein.');

        // ... und der Schlüssel funktioniert unverändert weiter.
        $stillValid = $this->newClient()->get('/api/horses?per_page=10', $this->bearer($token));
        $this->assertSame(200, $stillValid->statusCode, 'Ohne gültigen CSRF-Token darf nichts widerrufen werden.');
    }

    // ---- Hilfsmethoden -------------------------------------------------

    /**
     * Ermittelt die Benutzer-ID über die Admin-Benutzerliste (derselbe Weg
     * wie in SessionInvalidationTest - die Tests kennen bewusst nur die
     * echten HTTP-Oberflächen, keine Datenbank-Abkürzungen).
     */
    private function findUserIdByUsername(\Tests\Support\HttpClient $admin, string $username): int {
        $usersPage = $admin->get('/admin/users?search=' . urlencode($username));
        preg_match('/\/admin\/users\/edit\?id=(\d+)/', $usersPage->body, $matches);
        $this->assertNotEmpty($matches, "Konnte die ID des Benutzers '{$username}' nicht aus /admin/users ermitteln.");
        return (int)$matches[1];
    }
}
