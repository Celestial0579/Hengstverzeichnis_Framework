<?php
// tests/Functional/ApiKeyAuthTest.php

namespace Tests\Functional;

/**
 * HTTP-Funktionstests für die Schlüsselpflicht der JSON-API und das
 * dahinterliegende Rechtemodell (siehe App\Security\ApiKey, docs/api.md).
 *
 * Kernaussagen, die hier abgesichert werden:
 * - Ohne gültigen Schlüssel ist die API nicht erreichbar (kein anonymer Zugriff).
 * - Ein Schlüssel darf NIE mehr als sein Besitzer: verliert der Besitzer ein
 *   Recht, verliert der bereits ausgegebene Schlüssel es sofort mit.
 * - Ein Schlüssel darf bewusst WENIGER (Least Privilege über den Scope).
 * - Widerruf wirkt sofort, das Limit von 5 aktiven Schlüsseln wird erzwungen,
 *   und fremde Schlüssel lassen sich nicht widerrufen.
 */
class ApiKeyAuthTest extends FunctionalTestCase {

    use ApiKeyHelper;

    public function testApiIsUnreachableWithoutValidKey(): void {
        // Erzwingt die Ersteinrichtung der Testinstanz (ensureProvisioned()
        // hängt an authenticatedClient()). Ohne diesen Aufruf antwortet eine
        // frische Datenbank auf JEDE Route zunächst mit dem Setup-Redirect,
        // und dieser rein anonyme Test wäre von der Ausführungsreihenfolge
        // innerhalb der Suite abhängig.
        $this->authenticatedClient();

        $client = $this->newClient();

        // 1. Ganz ohne Header.
        $anonymous = $client->get('/api/horses');
        $this->assertSame(401, $anonymous->statusCode, 'Die API darf ohne Schlüssel nicht mehr erreichbar sein.');
        $this->assertSame('unauthorized', json_decode($anonymous->body, true)['error'] ?? null);
        $this->assertNotNull($anonymous->header('WWW-Authenticate'));

        // 2. Mit erfundenem Schlüssel - identische Antwort, damit die API kein
        // Orakel dafür wird, welche Schlüsselwerte existieren.
        $bogus = $client->get('/api/horses', $this->bearer('hv_' . str_repeat('0', 64)));
        $this->assertSame(401, $bogus->statusCode);
        $this->assertSame($anonymous->body, $bogus->body);

        // 3. Auch der Einzelabruf ist geschützt.
        $show = $client->get('/api/horses/show?ueln=DE000TEST0001');
        $this->assertSame(401, $show->statusCode);

        // 4. Kein Wildcard-CORS mehr: ein Schlüssel gehört nicht in Browser-JS.
        $this->assertNull(
            $anonymous->header('Access-Control-Allow-Origin'),
            'Seit der Schlüsselpflicht darf kein Wildcard-CORS-Header mehr gesetzt sein.'
        );
    }

    public function testValidKeyWorksAndRevokedKeyIsRejectedImmediately(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $label = 'Widerruf-Test ' . $unique;

        $token = $this->createApiKey($admin, $label);

        $client = $this->newClient();
        $ok = $client->get('/api/horses?per_page=10', $this->bearer($token));
        $this->assertSame(200, $ok->statusCode, 'Ein gültiger Schlüssel muss Zugriff gewähren.');
        $this->assertIsArray(json_decode($ok->body, true)['data'] ?? null);

        // Widerruf über die echte Route.
        $keyId = $this->findApiKeyIdByLabel($admin, $label);
        $overview = $admin->get('/api-keys');
        $revokeResponse = $admin->post('/api-keys/revoke', [
            'csrf_token' => $overview->formField('csrf_token') ?? '',
            'id' => (string)$keyId,
        ]);
        $this->assertSame('/api-keys?success=revoked', $revokeResponse->location());

        $afterRevoke = $client->get('/api/horses', $this->bearer($token));
        $this->assertSame(401, $afterRevoke->statusCode, 'Ein widerrufener Schlüssel muss sofort ungültig sein.');
    }

    public function testAtMostFiveActiveKeysPerUser(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        // Frischer Benutzer, damit das Limit nicht von Schlüsseln aus anderen
        // Tests beeinflusst wird (die Testdatenbank ist über die Suite geteilt).
        $groupId = $this->createOwnGroup($admin, 'API Limit ' . $unique);
        $this->setGroupPermissions($admin, $groupId, ['horses' => ['view']]);
        $user = $this->createAndLoginEditor($admin, "apilimit{$unique}", "api-limit-{$unique}@example.com", [$groupId]);

        for ($i = 1; $i <= 5; $i++) {
            $this->createApiKey($user, "Limit-Schlüssel {$i} {$unique}");
        }

        $page = $user->get('/api-keys');
        $sixth = $user->post('/api-keys/create', [
            'csrf_token' => $page->formField('csrf_token') ?? '',
            'label' => 'Sechster Schlüssel ' . $unique,
            'scope_mode' => 'all',
        ]);

        $this->assertSame(
            '/api-keys?error=limit_reached',
            $sixth->location(),
            'Der sechste Schlüssel muss abgelehnt werden (Maximum 5).'
        );
    }

    /**
     * Least Privilege: derselbe Benutzer besitzt horses.view UND persons.view,
     * ein Schlüssel wird aber bewusst nur auf persons.view eingeschränkt - er
     * darf damit keine Pferde mehr lesen, obwohl sein Besitzer es dürfte.
     */
    public function testKeyScopeCanBeNarrowerThanOwnerRights(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        $horseName = "API Scope Pferd {$unique}";
        $this->createPublishedHorse($admin, $horseName);

        $groupId = $this->createOwnGroup($admin, 'API Scope ' . $unique);
        $this->setGroupPermissions($admin, $groupId, [
            'horses' => ['view'],
            'persons' => ['view'],
        ]);
        $user = $this->createAndLoginEditor($admin, "apiscope{$unique}", "api-scope-{$unique}@example.com", [$groupId]);

        $client = $this->newClient();

        // a) Schlüssel MIT horses.view im Scope sieht das Pferd.
        $wideToken = $this->createApiKey($user, "Scope weit {$unique}", ['horses.view']);
        $wide = $client->get('/api/horses?search=' . urlencode($horseName), $this->bearer($wideToken));
        $this->assertSame(200, $wide->statusCode);
        $this->assertSame(1, json_decode($wide->body, true)['meta']['total']);

        // b) Schlüssel OHNE horses.view im Scope ist gültig (kein 401), sieht
        // aber nichts - die Einschränkung wirkt, obwohl der Besitzer das Recht hat.
        $narrowToken = $this->createApiKey($user, "Scope eng {$unique}", ['persons.view']);
        $narrow = $client->get('/api/horses?search=' . urlencode($horseName), $this->bearer($narrowToken));
        $this->assertSame(200, $narrow->statusCode, 'Der Schlüssel ist gültig, nur sein Scope deckt horses.view nicht ab.');
        $this->assertSame(
            0,
            json_decode($narrow->body, true)['meta']['total'],
            'Ein Schlüssel ohne horses.view im Scope darf keine Pferde ausliefern.'
        );
    }

    /**
     * Live-Cap: ein bereits ausgegebener Schlüssel verliert ein Recht in dem
     * Moment, in dem sein Besitzer es verliert - es wird nichts eingefroren,
     * was zum Zeitpunkt der Ausstellung galt.
     */
    public function testKeyLosesAccessWhenOwnerLosesTheRight(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        $horseName = "API Live-Cap Pferd {$unique}";
        $this->createPublishedHorse($admin, $horseName);

        $groupId = $this->createOwnGroup($admin, 'API LiveCap ' . $unique);
        $this->setGroupPermissions($admin, $groupId, ['horses' => ['view']]);
        $user = $this->createAndLoginEditor($admin, "apilivecap{$unique}", "api-livecap-{$unique}@example.com", [$groupId]);

        // Schlüssel mit "alle meine Rechte" - zum Ausstellungszeitpunkt inkl. horses.view.
        $token = $this->createApiKey($user, "Live-Cap {$unique}");

        $client = $this->newClient();
        $before = $client->get('/api/horses?search=' . urlencode($horseName), $this->bearer($token));
        $this->assertSame(1, json_decode($before->body, true)['meta']['total']);

        // Admin entzieht der Gruppe das Leserecht.
        $this->setGroupPermissions($admin, $groupId, []);

        $after = $client->get('/api/horses?search=' . urlencode($horseName), $this->bearer($token));
        $this->assertSame(200, $after->statusCode);
        $this->assertSame(
            0,
            json_decode($after->body, true)['meta']['total'],
            'Verliert der Besitzer horses.view, darf sein Schlüssel sofort keine Pferde mehr liefern.'
        );
    }

    public function testUserCannotRevokeSomeoneElsesKey(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        $label = 'Fremdschlüssel ' . $unique;
        $victimToken = $this->createApiKey($admin, $label);
        $victimKeyId = $this->findApiKeyIdByLabel($admin, $label);

        $groupId = $this->createOwnGroup($admin, 'API IDOR ' . $unique);
        $this->setGroupPermissions($admin, $groupId, ['horses' => ['view']]);
        $attacker = $this->createAndLoginEditor($admin, "apiidor{$unique}", "api-idor-{$unique}@example.com", [$groupId]);

        $attackerPage = $attacker->get('/api-keys');
        $attempt = $attacker->post('/api-keys/revoke', [
            'csrf_token' => $attackerPage->formField('csrf_token') ?? '',
            'id' => (string)$victimKeyId,
        ]);
        $this->assertSame(
            '/api-keys?error=revoke_failed',
            $attempt->location(),
            'Ein fremder Schlüssel darf nicht widerrufbar sein.'
        );

        // Der fremde Schlüssel funktioniert unverändert weiter.
        $stillValid = $this->newClient()->get('/api/horses?per_page=10', $this->bearer($victimToken));
        $this->assertSame(200, $stillValid->statusCode);
    }

    public function testApiKeyPageRequiresLogin(): void {
        // Siehe testApiIsUnreachableWithoutValidKey(): stellt die Ersteinrichtung
        // sicher, damit der Test nicht den Setup-Redirect misst.
        $this->authenticatedClient();

        $anonymous = $this->newClient()->get('/api-keys');
        $this->assertSame('/login', $anonymous->location(), 'Die Schlüsselverwaltung darf nur angemeldet erreichbar sein.');
    }

    // ---- Hilfsmethoden -------------------------------------------------

    /**
     * Legt eine eigene, rechtelose Gruppe an (bewusst nicht die eingebaute
     * Editor-Gruppe, deren Standardrechte die Prüfung verfälschen und deren
     * Änderung andere Tests der geteilten Testdatenbank beeinflussen würde -
     * siehe FunctionalTestCase::EDITOR_DEFAULT_PERMISSIONS).
     */
    private function createOwnGroup(\Tests\Support\HttpClient $admin, string $name): int {
        $groupsPage = $admin->get('/admin/groups');
        $createResponse = $admin->post('/admin/groups/create', [
            'csrf_token' => $groupsPage->formField('csrf_token') ?? '',
            'name' => $name,
            'description' => 'Funktionstest der API-Schlüssel',
        ]);
        $location = (string)$createResponse->location();
        $this->assertMatchesRegularExpression(
            '#^/admin/groups\?group=\d+&success=created$#',
            $location,
            "Gruppe anlegen fehlgeschlagen, Body: {$createResponse->body}"
        );
        preg_match('/group=(\d+)/', $location, $matches);

        return (int)$matches[1];
    }

    private function createPublishedHorse(\Tests\Support\HttpClient $admin, string $name): void {
        $createForm = $admin->get('/admin/horses/create');
        $response = $admin->post('/admin/horses/store', [
            'csrf_token' => $createForm->formField('csrf_token') ?? '',
            'name' => $name,
            'status' => 'active',
            'is_published' => '1',
        ]);
        $this->assertSame('/admin/horses?success=created', $response->location());
    }
}
