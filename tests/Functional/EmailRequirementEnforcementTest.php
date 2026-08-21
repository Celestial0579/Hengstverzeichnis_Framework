<?php
// tests/Functional/EmailRequirementEnforcementTest.php

namespace Tests\Functional;

/**
 * Die E-Mail-Adresse ist Pflicht - aber nur fuer Konten, die mehr duerfen als
 * lesen (#348).
 *
 * DER PUNKT, DEN DIESE DATEI ABSICHERT, IST DER ZWEITE ZEITPUNKT. Beim
 * Anlegen zu pruefen ist naheliegend und leicht. Der Fall, in dem die Regel
 * sonst zur Zierde wird, ist ein anderer: Eine Gruppe bekommt SPAETER ein
 * Bearbeitungsrecht, und damit haben es auf einen Schlag alle ihre
 * Mitglieder - auch die ohne Adresse.
 */
class EmailRequirementEnforcementTest extends FunctionalTestCase {

    /** @var array<int, string> */
    private array $aufraeumen = [];

    protected function tearDown(): void {
        if ($this->aufraeumen !== []) {
            $db = \App\Database::getInstance();
            $stmt = $db->prepare("DELETE FROM users WHERE username = ?");
            foreach ($this->aufraeumen as $name) {
                $stmt->execute([$name]);
            }
            $this->aufraeumen = [];
        }
        parent::tearDown();
    }

    private function gruppeAnlegen(\Tests\Support\HttpClient $admin, string $name): int {
        $groupsPage = $admin->get('/admin/groups');
        $antwort = $admin->post('/admin/groups/create', [
            'csrf_token' => $groupsPage->formField('csrf_token') ?? '',
            'name' => $name,
        ]);
        preg_match('/group=(\d+)/', (string)$antwort->location(), $treffer);
        $this->assertNotEmpty($treffer, "Konnte Gruppen-ID nicht ermitteln, Body: {$antwort->body}");
        return (int)$treffer[1];
    }

    /**
     * Ein Konto, das nur lesen darf, kommt ohne Adresse aus - genau dafuer
     * gibt es #348.
     */
    public function testEinNurLesendesKontoDarfOhneAdresseAngelegtWerden(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $gruppe = $this->gruppeAnlegen($admin, "Nur Einblick {$unique}");
        $this->setGroupPermissions($admin, $gruppe, ['horses' => ['view']]);

        $username = "leserin{$unique}";
        $createForm = $admin->get('/admin/users/create');
        $antwort = $admin->post('/admin/users/store', [
            'csrf_token' => $createForm->formField('csrf_token') ?? '',
            'username' => $username,
            'email' => '',
            'password' => 'NurLesen123!',
            'groups' => [(string)$gruppe],
        ]);
        $this->assertSame('/admin/users?success=created', $antwort->location(), "Body: {$antwort->body}");
        $this->aufraeumen[] = $username;

        // Und die Adresse steht wirklich auf NULL, nicht auf Leerstring: Der
        // UNIQUE-Index laesst beliebig viele NULL zu, aber nur EINEN
        // Leerstring - sonst scheiterte das zweite solche Konto.
        $db = \App\Database::getInstance();
        $stmt = $db->prepare("SELECT email IS NULL FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $this->assertSame(1, (int)$stmt->fetchColumn(), 'Keine Adresse heisst NULL, nicht Leerstring.');
    }

    public function testEinBearbeitendesKontoBrauchtEineAdresse(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $gruppe = $this->gruppeAnlegen($admin, "Redaktion {$unique}");
        $this->setGroupPermissions($admin, $gruppe, ['horses' => ['view', 'edit']]);

        $createForm = $admin->get('/admin/users/create');
        $antwort = $admin->post('/admin/users/store', [
            'csrf_token' => $createForm->formField('csrf_token') ?? '',
            'username' => "redakteurin{$unique}",
            'email' => '',
            'password' => 'Redaktion123!',
            'groups' => [(string)$gruppe],
        ]);

        $this->assertNull($antwort->location(), 'Das Anlegen darf nicht gelingen.');
        $this->assertStringContainsString('nur für Konten, die ausschließlich lesen', $antwort->body);
    }

    /**
     * Der eigentliche Fall: Die Gruppe bekommt das Recht erst NACHTRAEGLICH.
     */
    public function testRechtevergabeVerlangtAdressen(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $gruppe = $this->gruppeAnlegen($admin, "Spaeter mehr {$unique}");
        $this->setGroupPermissions($admin, $gruppe, ['horses' => ['view']]);

        $username = "spaeter{$unique}";
        $createForm = $admin->get('/admin/users/create');
        $angelegt = $admin->post('/admin/users/store', [
            'csrf_token' => $createForm->formField('csrf_token') ?? '',
            'username' => $username,
            'email' => '',
            'password' => 'SpaeterMehr123!',
            'groups' => [(string)$gruppe],
        ]);
        $this->assertSame('/admin/users?success=created', $angelegt->location(), "Body: {$angelegt->body}");
        $this->aufraeumen[] = $username;

        // Jetzt Bearbeitungsrecht nachschieben: muss abgelehnt werden.
        $editPage = $admin->get('/admin/groups?group=' . $gruppe);
        $abgelehnt = $admin->post('/admin/groups/permissions', [
            'csrf_token' => $editPage->formField('csrf_token') ?? '',
            'group_id' => (string)$gruppe,
            'permissions' => ['horses' => ['view', 'edit']],
        ]);
        $this->assertSame(
            "/admin/groups?group={$gruppe}&error=email_required",
            $abgelehnt->location(),
            "Body: {$abgelehnt->body}"
        );

        // Die Seite nennt das betroffene Konto - sonst muesste der Admin raten.
        $hinweis = $admin->get("/admin/groups?group={$gruppe}&error=email_required");
        $this->assertStringContainsString($username, $hinweis->body);

        // Und das Recht wurde tatsaechlich NICHT gespeichert.
        $db = \App\Database::getInstance();
        $stmt = $db->prepare("SELECT COUNT(*) FROM group_permissions WHERE group_id = ? AND action = 'edit'");
        $stmt->execute([$gruppe]);
        $this->assertSame(0, (int)$stmt->fetchColumn(), 'Abgelehnt heisst: nichts gespeichert.');

        // Nach dem Nachtragen der Adresse geht es.
        $userId = $this->benutzerId($admin, $username);
        $editUser = $admin->get('/admin/users/edit?id=' . $userId);
        $aktualisiert = $admin->post('/admin/users/update', [
            'csrf_token' => $editUser->formField('csrf_token') ?? '',
            'id' => (string)$userId,
            'username' => $username,
            'email' => "spaeter-{$unique}@example.com",
            'groups' => [(string)$gruppe],
        ]);
        $this->assertSame('/admin/users?success=updated', $aktualisiert->location(), "Body: {$aktualisiert->body}");

        $this->setGroupPermissions($admin, $gruppe, ['horses' => ['view', 'edit']]);
    }

    /**
     * "Berechtigungen von Administrator kopieren" ist der schnellste Weg,
     * einer Gruppe auf einen Schlag alle Schreibrechte zu geben - die Regel
     * muss auch dort greifen.
     */
    public function testAuchDasKopierenVonRechtenVerlangtAdressen(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $quelle = $this->gruppeAnlegen($admin, "Quelle {$unique}");
        $this->setGroupPermissions($admin, $quelle, ['horses' => ['view', 'delete']]);

        $ziel = $this->gruppeAnlegen($admin, "Ziel {$unique}");
        $this->setGroupPermissions($admin, $ziel, ['horses' => ['view']]);

        $username = "kopierziel{$unique}";
        $createForm = $admin->get('/admin/users/create');
        $angelegt = $admin->post('/admin/users/store', [
            'csrf_token' => $createForm->formField('csrf_token') ?? '',
            'username' => $username,
            'email' => '',
            'password' => 'Kopieren123!',
            'groups' => [(string)$ziel],
        ]);
        $this->assertSame('/admin/users?success=created', $angelegt->location(), "Body: {$angelegt->body}");
        $this->aufraeumen[] = $username;

        $seite = $admin->get('/admin/groups?group=' . $ziel);
        $abgelehnt = $admin->post('/admin/groups/copy-permissions', [
            'csrf_token' => $seite->formField('csrf_token') ?? '',
            'source_group_id' => (string)$quelle,
            'target_group_id' => (string)$ziel,
        ]);
        $this->assertSame(
            "/admin/groups?group={$ziel}&error=email_required",
            $abgelehnt->location(),
            "Body: {$abgelehnt->body}"
        );
    }

    /**
     * Der DRITTE Zeitpunkt, an dem die Regel greifen muss.
     *
     * Die Gruppenzugehoerigkeiten ueberleben den Soft-Delete. Liegt ein
     * Mitglied ohne Adresse im Papierkorb, laesst die Rechtevergabe an seine
     * Gruppe durch (geloeschte Konten zaehlen dort bewusst nicht mit) - und
     * das Zurueckholen erzeugte danach genau den Zustand, den die beiden
     * anderen Pruefungen verweigern.
     */
    public function testWiederherstellungAusDemPapierkorbVerlangtEineAdresse(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $gruppe = $this->gruppeAnlegen($admin, "Papierkorb {$unique}");
        $this->setGroupPermissions($admin, $gruppe, ['horses' => ['view']]);

        $username = "papierkorb{$unique}";
        $createForm = $admin->get('/admin/users/create');
        $angelegt = $admin->post('/admin/users/store', [
            'csrf_token' => $createForm->formField('csrf_token') ?? '',
            'username' => $username,
            'email' => '',
            'password' => 'Papierkorb123!',
            'groups' => [(string)$gruppe],
        ]);
        $this->assertSame('/admin/users?success=created', $angelegt->location(), "Body: {$angelegt->body}");
        $this->aufraeumen[] = $username;
        $userId = $this->benutzerId($admin, $username);

        // In den Papierkorb.
        $geloescht = $admin->post('/admin/users/delete', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'id' => (string)$userId,
        ]);
        $this->assertSame('/admin/users?success=deleted', $geloescht->location(), "Body: {$geloescht->body}");

        // Jetzt bekommt die Gruppe ein Bearbeitungsrecht - das geht durch,
        // weil das Konto im Papierkorb nicht mitzaehlt.
        $this->setGroupPermissions($admin, $gruppe, ['horses' => ['view', 'edit']]);

        // Und genau deshalb muss das Zurueckholen scheitern.
        $trash = $admin->get('/admin/trash');
        $zurueck = $admin->post('/admin/trash/restore', [
            'csrf_token' => $trash->formField('csrf_token') ?? '',
            'type' => 'user',
            'id' => (string)$userId,
        ]);
        $this->assertSame('/admin/trash?error=email_required', $zurueck->location(), "Body: {$zurueck->body}");

        $db = \App\Database::getInstance();
        $stmt = $db->prepare("SELECT deleted_at IS NOT NULL FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $this->assertSame(1, (int)$stmt->fetchColumn(), 'Abgelehnt heisst: das Konto bleibt im Papierkorb.');
    }

    private function benutzerId(\Tests\Support\HttpClient $admin, string $username): int {
        $seite = $admin->get('/admin/users?search=' . urlencode($username));
        preg_match('/\/admin\/users\/edit\?id=(\d+)/', $seite->body, $treffer);
        $this->assertNotEmpty($treffer, "Konnte die ID zu '{$username}' nicht ermitteln.");
        return (int)$treffer[1];
    }
}
