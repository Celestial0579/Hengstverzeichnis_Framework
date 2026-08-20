<?php
// tests/Functional/ApiKeyAuthTest.php

namespace Tests\Functional;

use App\Database;

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
 * - Die API gibt aus einem Kontakt ausschließlich den Namen heraus - die
 *   Feldgrenze, die durch die Kontaktliste (#336) neu geprüft werden muss.
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
     * ein Schlüssel wird aber bewusst nur auf contacts.view eingeschränkt - er
     * darf damit keine Pferde mehr lesen, obwohl sein Besitzer es dürfte.
     *
     * Das zweite Recht war bis v0.7 `persons.view`; seit der Kontaktliste
     * (#336) heißt das Modul `contacts`. Die Aussage des Tests hängt nicht an
     * diesem Namen - gebraucht wird irgendein ZWEITES Recht des Besitzers,
     * das nichts mit Pferden zu tun hat.
     */
    public function testKeyScopeCanBeNarrowerThanOwnerRights(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        $horseName = "API Scope Pferd {$unique}";
        $this->createPublishedHorse($admin, $horseName);

        $groupId = $this->createOwnGroup($admin, 'API Scope ' . $unique);
        $this->setGroupPermissions($admin, $groupId, [
            'horses' => ['view'],
            'contacts' => ['view'],
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
        $narrowToken = $this->createApiKey($user, "Scope eng {$unique}", ['contacts.view']);
        $narrow = $client->get('/api/horses?search=' . urlencode($horseName), $this->bearer($narrowToken));
        $this->assertSame(200, $narrow->statusCode, 'Der Schlüssel ist gültig, nur sein Scope deckt horses.view nicht ab.');
        $this->assertSame(
            0,
            json_decode($narrow->body, true)['meta']['total'],
            'Ein Schlüssel ohne horses.view im Scope darf keine Pferde ausliefern.'
        );
    }

    /**
     * Feldgrenze der API nach der Kontaktliste (#336).
     *
     * Bis v0.7 lasen die JOINs dieser Abfrage `persons` (Positivliste an
     * Spalten) und `breeding_stations`; seit dem Umbau lesen beide dieselbe
     * Tabelle `contacts`, und die trägt mehr Spalten, als `persons` je hatte -
     * contact_person, address, contact_info, contact_public. Genau dort
     * entsteht die Gefahr: Ein `SELECT *` oder ein durchgereichter Datensatz
     * trüge die zusammengelegte Tabelle nach außen, ohne dass sich am Aufruf
     * irgendetwas ändert und ohne dass ein bestehender Test es merkt.
     *
     * Festgenagelt wird deshalb die AUSGEGEBENE Feldmenge, nicht der SQL-Text:
     * Aus einem Kontakt darf ausschließlich der Name nach draußen (als
     * breeding_station, breeder, owner). Der Kontakt im Test hat seine
     * Kontaktdaten ausdrücklich FREIGEGEBEN (contact_public = 1) - sonst
     * belegte der Test bloß, dass eine fehlende Freigabe greift. Auch
     * freigegebene Anschriften gehören nicht in eine Pferde-Antwort; die
     * Freigabe gilt der Kontaktseite, nicht dem Katalog.
     */
    public function testApiCarriesContactNamesButNoOtherContactFields(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        // 'x' kommt in Hex-Strings (CSRF-Token, Bild-Hashes) nie vor - macht
        // die Negativ-Prüfungen unten zufallssicher (Muster aus ContactFieldsTest).
        $contactName = "API Kontakt {$unique}";
        $vertraulich = [
            'contact_person' => 'Ansprechpartnerin Aselx',
            'street' => 'Fjordwegx',
            'house_number' => '7x',
            'postal_code' => '24960x',
            'address' => 'Fjordwegx 7x, 24960x Glücksburg',
            'email' => "api-kontakt-{$unique}@example.com",
            'phone' => '04631 111x',
            'mobile' => '0170 222x',
            'contact_info' => 'Rückruf nur abends, Festnetz 04631 999x',
        ];

        $form = $admin->get('/admin/contacts/create');
        $anlegen = $admin->post('/admin/contacts/store', array_merge($vertraulich, [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $contactName,
            'city' => 'Glücksburg',
            'country' => 'DE',
            // Ausdrücklich freigegeben - siehe PHPDoc.
            'contact_public' => '1',
            'is_published' => '1',
        ]));
        $this->assertSame('/admin/contacts?success=created', $anlegen->location());

        $stmt = Database::getInstance()->prepare("SELECT id FROM contacts WHERE name = ?");
        $stmt->execute([$contactName]);
        $contactId = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $contactId, 'Der Testkontakt sollte angelegt sein.');

        // Ein Pferd, das denselben Kontakt in ALLEN drei Rollen führt, in denen
        // ein Kontakt überhaupt in einer API-Antwort auftaucht: als Deckstation
        // des Pferdes, als Station der Zuordnungszeile und als Züchter.
        $horseName = "API Feldgrenze Pferd {$unique}";
        $ueln = 'DE000FELD' . substr((string)$contactId, -4) . '1';
        $createForm = $admin->get('/admin/horses/create');
        $angelegt = $admin->post('/admin/horses/store', [
            'csrf_token' => $createForm->formField('csrf_token') ?? '',
            'name' => $horseName,
            'ueln' => $ueln,
            'status' => 'active',
            'is_published' => '1',
            'breeding_station_id' => (string)$contactId,
            'persons' => [
                ['contact_id' => (string)$contactId, 'role' => 'breeder', 'station_contact_id' => (string)$contactId],
            ],
        ]);
        $this->assertSame('/admin/horses?success=created', $angelegt->location());

        $token = $this->createApiKey($admin, "Feldgrenze {$unique}");
        $client = $this->newClient();

        $liste = $client->get('/api/horses?search=' . urlencode($horseName), $this->bearer($token));
        $this->assertSame(200, $liste->statusCode);
        $daten = json_decode($liste->body, true);
        $this->assertSame(1, $daten['meta']['total'], 'Das Testpferd sollte genau einmal gefunden werden.');
        $datensatz = $daten['data'][0];

        // Der Name kommt an - sonst prüfte der Rest an einer leeren Antwort vorbei.
        $this->assertSame($contactName, $datensatz['breeding_station'], 'Der Stationsname gehört zur öffentlichen Katalogansicht.');
        $this->assertSame($contactName, $datensatz['breeder'], 'Der Züchtername gehört zur öffentlichen Katalogansicht.');

        // Und sonst nichts aus dem Kontakt: die Feldmenge vollständig, damit
        // ein NEU hinzugefügtes Feld hier auffällt statt still auszuliefern.
        $this->assertSame(
            [
                'id', 'name', 'ueln', 'foreign_ueln', 'birth_year', 'birth_date', 'color', 'sex',
                'breed', 'height_cm', 'status', 'is_deceased', 'death_year', 'image_url',
                'breeding_station', 'sire', 'dam', 'breeder', 'owner', 'profile_url',
            ],
            array_keys($datensatz),
            'Die API liefert eine andere Feldmenge als vereinbart - bei einem Kontaktfeld ist das ein Datenschutzvorfall.'
        );

        foreach ($vertraulich as $feld => $wert) {
            $this->assertStringNotContainsString(
                $wert,
                $liste->body,
                "Das Kontaktfeld {$feld} ist in die API-Antwort gelangt."
            );
        }

        // Derselbe Maßstab für den Einzelabruf - er teilt sich die Abfrage mit
        // der Liste, aber das ist eine Eigenschaft der Umsetzung, keine Zusage.
        $einzeln = $client->get('/api/horses/show?ueln=' . urlencode($ueln), $this->bearer($token));
        $this->assertSame(200, $einzeln->statusCode);
        $this->assertSame(
            array_keys($datensatz),
            array_keys(json_decode($einzeln->body, true)['data']),
            'Der Einzelabruf liefert eine andere Feldmenge als die Liste.'
        );
        foreach ($vertraulich as $feld => $wert) {
            $this->assertStringNotContainsString(
                $wert,
                $einzeln->body,
                "Das Kontaktfeld {$feld} ist in den Einzelabruf gelangt."
            );
        }
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
