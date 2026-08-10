<?php
// tests/Functional/UserManagementTest.php

namespace Tests\Functional;

/**
 * HTTP-Funktionstests für die Benutzerverwaltung (/admin/users,
 * App\Controllers\UserController) - die CRUD-/Rechte-Kernpfade, die bisher
 * in der Suite fehlten (README, "Bekannte Einschränkungen"):
 *
 * - Anlegen: Pflichtfeld-/Format-Validierung, reservierte Benutzernamen,
 *   Duplikat-Schutz (username/email UNIQUE), Willkommens-Mail-Pfad ohne
 *   konfiguriertes SMTP.
 * - Bearbeiten: Stammdaten ändern, Gruppenzugehörigkeit zuweisen/entziehen,
 *   Passwort-Mindestlänge, Redirects bei fehlender/unbekannter ID.
 * - Löschen: Soft-Delete (Papierkorb), sofortiges Ende bestehender Sitzungen
 *   und des Logins des betroffenen Kontos.
 * - Selbstschutz: keine Selbstlöschung, keine Selbst-Degradierung des
 *   eingeloggten Admins (admin-Gruppe wird serverseitig behalten).
 * - Zugriffsschutz: alle /admin/users-Routen nur für Administratoren
 *   (requireAdmin() im Konstruktor), CSRF-Pflicht auf schreibenden Routen.
 * - 2FA-Reset durch den Admin erzwingt eine Neueinrichtung beim nächsten Login.
 *
 * Bewusst NICHT hier (bereits anderweitig abgedeckt, keine Doppelabdeckung):
 * - Session-Invalidierung nach Admin-Passwortänderung (#113):
 *   SessionInvalidationTest.
 * - API-Schlüssel-Widerruf bei Passwort-Neusetzung + /revoke-api-keys samt
 *   CSRF (#217): ApiKeyPasswordRevocationTest.
 * - 2FA-Pflicht pro Gruppe beim Anlegen (#84): TwoFaGroupPolicyTest.
 * - Vollzugriff durch Zuweisung der eingebauten admin-Gruppe:
 *   GroupPermissionEnforcementTest.
 * - Wiederherstellung gelöschter Konten aus dem Papierkorb (#127):
 *   TrashPermissionTest.
 */
class UserManagementTest extends FunctionalTestCase {

    /**
     * Alle in einem Test angelegten (oder versuchsweise angelegten)
     * Benutzernamen - tearDown() räumt sie ab, damit die Klasse den geteilten
     * DB-Zustand der Suite nicht anwachsen lässt und reihenfolgeunabhängig
     * bleibt. Die Namen sind zusätzlich uniqid()-basiert, Kollisionen mit
     * anderen Testklassen sind also ohnehin ausgeschlossen.
     */
    private array $createdUsernames = [];

    /**
     * Direkter DB-Zugriff analog FunctionalTestCase::resetTotpReplayGuard():
     * Hartes Entfernen der Test-Benutzer; user_groups und api_keys hängen per
     * ON DELETE CASCADE daran, audit_logs hat bewusst keinen FK und behält
     * seine Zeilen (revisionssicher, stört keine Folge-Tests).
     */
    protected function tearDown(): void {
        if (!empty($this->createdUsernames)) {
            $db = \App\Database::getInstance();
            $stmt = $db->prepare("DELETE FROM users WHERE username = ?");
            foreach ($this->createdUsernames as $username) {
                $stmt->execute([$username]);
            }
            $this->createdUsernames = [];
        }
        parent::tearDown();
    }

    public function testCreateValidatesInputAndRejectsDuplicates(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $username = "uvneu{$unique}";
        $email = "uv-neu-{$unique}@example.com";
        $csrfToken = $this->currentCsrfToken($admin);

        // Das Formular selbst ist erreichbar.
        $createForm = $admin->get('/admin/users/create');
        $this->assertSame(200, $createForm->statusCode);
        $this->assertStringContainsString('Neuen Benutzer anlegen', $createForm->body);

        // Ohne Benutzernamen: Fehler-Render statt Redirect - und die übrigen
        // Eingaben bleiben im Formular erhalten (old-Werte, #123-Muster).
        $missingUsername = $admin->post('/admin/users/store', [
            'csrf_token' => $csrfToken,
            'username' => '',
            'email' => $email,
            'password' => 'VerwaltungTest123!',
        ]);
        $this->assertSame(200, $missingUsername->statusCode);
        $this->assertNull($missingUsername->location());
        $this->assertStringContainsString('Benutzername erforderlich.', $missingUsername->body);
        $this->assertSame($email, $missingUsername->formField('email'), 'Eingegebene E-Mail muss im Fehlerfall erhalten bleiben.');

        // Reservierte Systemnamen (BaseController::isReservedUsername) sind tabu.
        $this->createdUsernames[] = 'administrator';
        $reserved = $admin->post('/admin/users/store', [
            'csrf_token' => $csrfToken,
            'username' => 'administrator',
            'email' => $email,
            'password' => 'VerwaltungTest123!',
        ]);
        $this->assertStringContainsString('reserviert', $reserved->body);

        // Ungültige E-Mail-Adresse.
        $invalidEmail = $admin->post('/admin/users/store', [
            'csrf_token' => $csrfToken,
            'username' => $username,
            'email' => 'keine-adresse',
            'password' => 'VerwaltungTest123!',
        ]);
        $this->assertStringContainsString('Gültige E-Mail-Adresse erforderlich.', $invalidEmail->body);

        // Zu kurzes Passwort (< 8 Zeichen).
        $shortPassword = $admin->post('/admin/users/store', [
            'csrf_token' => $csrfToken,
            'username' => $username,
            'email' => $email,
            'password' => 'kurz123',
        ]);
        $this->assertStringContainsString('Passwort muss mindestens 8 Zeichen lang sein.', $shortPassword->body);

        // Ohne gültigen CSRF-Token wird gar nicht erst validiert, sondern hart
        // abgelehnt - und es entsteht kein Benutzer.
        $csrfAttempt = $admin->post('/admin/users/store', [
            'csrf_token' => 'ungueltig',
            'username' => $username,
            'email' => $email,
            'password' => 'VerwaltungTest123!',
        ]);
        $this->assertSame(403, $csrfAttempt->statusCode, 'Das Anlegen muss CSRF-geschützt sein.');
        $this->assertStringContainsString('CSRF-Sicherheits-Token ungültig', $csrfAttempt->body);

        // Keiner der Fehlversuche hat einen Benutzer erzeugt.
        $emptySearch = $admin->get('/admin/users?search=' . urlencode($username));
        $this->assertStringContainsString('Keine Benutzer für diese Suche gefunden.', $emptySearch->body);

        // Gültiger Antrag: Benutzer entsteht und erscheint in der Liste -
        // ohne Gruppen ("– keine –") und mit ausstehendem 2FA-Status.
        $created = $this->createUser($admin, $username, $email);
        $this->assertSame('/admin/users?success=created', $created->location(), "Anlegen fehlgeschlagen, Body: {$created->body}");

        $listPage = $admin->get('/admin/users?search=' . urlencode($username));
        $this->assertStringContainsString($username, $listPage->body);
        $this->assertStringContainsString($email, $listPage->body);
        $this->assertStringContainsString('– keine –', $listPage->body, 'Ohne Gruppenzuweisung muss die Liste "keine" ausweisen.');
        $this->assertStringContainsString('Ausstehend', $listPage->body, 'Ein frisch angelegtes Konto hat noch kein 2FA.');

        // Duplikate: gleicher Benutzername (andere E-Mail) und gleiche E-Mail
        // (anderer Benutzername) werden über die UNIQUE-Constraints abgefangen
        // und als Formularfehler gemeldet.
        $duplicateUsername = $admin->post('/admin/users/store', [
            'csrf_token' => $csrfToken,
            'username' => $username,
            'email' => "uv-neu-zwei-{$unique}@example.com",
            'password' => 'VerwaltungTest123!',
        ]);
        $this->assertStringContainsString('E-Mail oder Benutzername bereits vergeben.', $duplicateUsername->body);

        $this->createdUsernames[] = "uvneuzwei{$unique}";
        $duplicateEmail = $admin->post('/admin/users/store', [
            'csrf_token' => $csrfToken,
            'username' => "uvneuzwei{$unique}",
            'email' => $email,
            'password' => 'VerwaltungTest123!',
        ]);
        $this->assertStringContainsString('E-Mail oder Benutzername bereits vergeben.', $duplicateEmail->body);

        // Es existiert weiterhin genau EIN Konto mit diesem Benutzernamen.
        $afterDuplicates = $admin->get('/admin/users?search=' . urlencode($username));
        preg_match_all('/\/admin\/users\/edit\?id=(\d+)/', $afterDuplicates->body, $matches);
        $this->assertCount(1, array_unique($matches[1]), 'Duplikat-Versuche dürfen kein zweites Konto erzeugen.');
    }

    public function testCreateWithWelcomeEmailSucceedsWithoutSmtpConfiguration(): void {
        // Die Testinstanz hat kein SMTP konfiguriert - Mailer::send() schlägt
        // dann kontrolliert fehl (return false, keine Exception). Das Anlegen
        // mit angehaktem "Willkommens-E-Mail senden" muss trotzdem gelingen:
        // Würde der Mail-Pfad eine Exception werfen, liefe er in den
        // catch-Block von UserController::store() und meldete fälschlich
        // "E-Mail oder Benutzername bereits vergeben".
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $username = "uvmail{$unique}";

        $created = $this->createUser($admin, $username, "uv-mail-{$unique}@example.com", [
            'send_welcome_email' => '1',
        ]);
        $this->assertSame(
            '/admin/users?success=created',
            $created->location(),
            "Ein fehlgeschlagener Mail-Versand darf das Anlegen nicht blockieren, Body: {$created->body}"
        );

        $listPage = $admin->get('/admin/users?search=' . urlencode($username));
        $this->assertStringContainsString($username, $listPage->body);
    }

    public function testEditUpdatesAccountDataAndGroupMembership(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $username = "uvedit{$unique}";
        $email = "uv-edit-{$unique}@example.com";
        $newUsername = "uveditneu{$unique}";
        $newEmail = "uv-edit-neu-{$unique}@example.com";
        $this->createdUsernames[] = $newUsername;

        $created = $this->createUser($admin, $username, $email);
        $this->assertSame('/admin/users?success=created', $created->location());
        $userId = $this->findUserIdByUsername($admin, $username);

        // Ohne/mit unbekannter ID führt die Bearbeitungsseite kommentarlos
        // zurück zur Liste.
        $this->assertSame('/admin/users', $admin->get('/admin/users/edit')->location());
        $this->assertSame('/admin/users', $admin->get('/admin/users/edit?id=99999999')->location());

        // Die Bearbeitungsseite zeigt die aktuellen Stammdaten.
        $editPage = $admin->get('/admin/users/edit?id=' . $userId);
        $this->assertSame(200, $editPage->statusCode);
        $this->assertSame($username, $editPage->formField('username'));
        $this->assertSame($email, $editPage->formField('email'));

        // Schreibversuch ohne gültigen CSRF-Token: hart abgelehnt.
        $csrfAttempt = $admin->post('/admin/users/update', [
            'csrf_token' => 'ungueltig',
            'id' => (string)$userId,
            'username' => $newUsername,
            'email' => $newEmail,
        ]);
        $this->assertSame(403, $csrfAttempt->statusCode, 'Das Aktualisieren muss CSRF-geschützt sein.');

        // Zu kurzes neues Passwort: Fehler-Render, kein Redirect. Der
        // ERFOLGREICHE Passwort-Neusetzungs-Pfad (Session-Invalidierung #113,
        // API-Schlüssel-Widerruf #217) ist bewusst NICHT hier, sondern in
        // SessionInvalidationTest/ApiKeyPasswordRevocationTest abgedeckt.
        $shortPassword = $admin->post('/admin/users/update', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'id' => (string)$userId,
            'username' => $newUsername,
            'email' => $newEmail,
            'password' => 'kurz123',
        ]);
        $this->assertNull($shortPassword->location());
        $this->assertStringContainsString('Das Passwort muss mindestens 8 Zeichen lang sein.', $shortPassword->body);

        // Reservierte Benutzernamen sind auch beim Umbenennen tabu.
        $reserved = $admin->post('/admin/users/update', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'id' => (string)$userId,
            'username' => 'root',
            'email' => $newEmail,
        ]);
        $this->assertStringContainsString('reserviert', $reserved->body);

        // Alle Fehlversuche haben nichts verändert.
        $unchanged = $admin->get('/admin/users?search=' . urlencode($username));
        $this->assertStringContainsString($email, $unchanged->body, 'Fehlversuche dürfen die Stammdaten nicht ändern.');

        // Gültige Aktualisierung: neuer Name, neue E-Mail, Mitglied der
        // eingebauten Editor-Gruppe (leeres Passwortfeld = Passwort bleibt).
        $editorGroupId = $this->findBuiltinGroupId($admin, 'Editor');
        $update = $admin->post('/admin/users/update', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'id' => (string)$userId,
            'username' => $newUsername,
            'email' => $newEmail,
            'password' => '',
            'groups' => [(string)$editorGroupId],
        ]);
        $this->assertSame('/admin/users?success=updated', $update->location(), "Aktualisieren fehlgeschlagen, Body: {$update->body}");

        // Liste und Formular spiegeln den neuen Stand: alter Name weg, neue
        // Daten da, Editor-Gruppe zugewiesen (Badge + vorausgewählte Option).
        $oldGone = $admin->get('/admin/users?search=' . urlencode($username));
        $this->assertStringContainsString('Keine Benutzer für diese Suche gefunden.', $oldGone->body);

        $listPage = $admin->get('/admin/users?search=' . urlencode($newUsername));
        $this->assertStringContainsString($newEmail, $listPage->body);
        $this->assertStringContainsString('Editor', $listPage->body, 'Die Gruppenzuweisung muss in der Liste sichtbar sein.');

        $editAfter = $admin->get('/admin/users/edit?id=' . $userId);
        $this->assertMatchesRegularExpression(
            '/value="' . $editorGroupId . '" selected>/',
            $editAfter->body,
            'Die Editor-Gruppe muss im Formular vorausgewählt sein.'
        );

        // Gruppen wieder entziehen: Ein Update ohne groups-Auswahl leert die
        // Mitgliedschaft vollständig (syncUserGroups, #66).
        $clearGroups = $admin->post('/admin/users/update', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'id' => (string)$userId,
            'username' => $newUsername,
            'email' => $newEmail,
            'password' => '',
        ]);
        $this->assertSame('/admin/users?success=updated', $clearGroups->location());

        $editCleared = $admin->get('/admin/users/edit?id=' . $userId);
        $this->assertDoesNotMatchRegularExpression('/value="' . $editorGroupId . '" selected>/', $editCleared->body);
        $listCleared = $admin->get('/admin/users?search=' . urlencode($newUsername));
        $this->assertStringContainsString('– keine –', $listCleared->body, 'Nach dem Entzug darf keine Gruppe mehr gelistet sein.');
    }

    public function testDeleteIsSoftDeleteAndEndsAccountAccess(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $username = "uvdel{$unique}";
        $email = "uv-del-{$unique}@example.com";

        // Voll eingerichtetes Konto über den echten Flow - createAndLoginEditor
        // sichert dabei nebenbei must_change_password=1 beim Admin-Anlegen ab
        // (erzwungener Passwortwechsel bei Erstanmeldung, Assertions im Helper).
        $this->createdUsernames[] = $username;
        $editor = $this->createAndLoginEditor($admin, $username, $email);
        $this->assertSame(200, $editor->get('/admin')->statusCode);

        $userId = $this->findUserIdByUsername($admin, $username);

        // Ohne gültigen CSRF-Token wird nicht gelöscht.
        $csrfAttempt = $admin->post('/admin/users/delete', [
            'csrf_token' => 'ungueltig',
            'id' => (string)$userId,
        ]);
        $this->assertSame(403, $csrfAttempt->statusCode, 'Das Löschen muss CSRF-geschützt sein.');
        $stillThere = $admin->get('/admin/users?search=' . urlencode($username));
        $this->assertStringContainsString($username, $stillThere->body);

        // Echtes Löschen: Redirect + Konto verschwindet aus der Liste.
        $delete = $admin->post('/admin/users/delete', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'id' => (string)$userId,
        ]);
        $this->assertSame('/admin/users?success=deleted', $delete->location());
        $gone = $admin->get('/admin/users?search=' . urlencode($username));
        $this->assertStringContainsString('Keine Benutzer für diese Suche gefunden.', $gone->body);

        // Soft-Delete, kein hartes Entfernen: Die Zeile bleibt mit gesetztem
        // deleted_at erhalten (Papierkorb). Direkter DB-Blick analog
        // resetTotpReplayGuard(), weil die Benutzerliste hart und weich
        // Gelöschtes identisch ausblendet - die Wiederherstellung selbst ist
        // Sache von TrashPermissionTest (#127).
        $db = \App\Database::getInstance();
        $stmt = $db->prepare("SELECT deleted_at FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $this->assertNotNull($stmt->fetchColumn(), 'Löschen muss ein Soft-Delete sein (deleted_at gesetzt, Zeile bleibt).');

        // Die bestehende Sitzung des Kontos endet mit dem nächsten Request
        // (checkAuth() prüft deleted_at live, Meldung account_disabled -
        // anders als die session_expired-Invalidierung aus #113).
        $this->assertSame('/login?error=account_disabled', $editor->get('/admin')->location());

        // Und ein neuer Login mit dem weiterhin korrekten Passwort scheitert
        // (Login-Query filtert deleted_at IS NULL). Bewusst nur EIN
        // Fehlversuch - Suite-Hygiene gegenüber dem IP-Zähler des
        // Login-Rate-Limiters (login_ip, 20/15 min, siehe LoginRateLimitTest).
        $client = $this->newClient();
        $loginPage = $client->get('/login');
        $loginAttempt = $client->post('/login', [
            'csrf_token' => $loginPage->formField('csrf_token') ?? '',
            'email' => $email,
            // Endgültiges Passwort nach dem erzwungenen Wechsel der
            // Erstanmeldung, siehe FunctionalTestCase::createAndLoginEditor().
            'password' => 'EditorTestNeu456!',
        ]);
        $this->assertStringContainsString('Ungültige E-Mail oder Passwort.', $loginAttempt->body, 'Ein gelöschtes Konto darf sich nicht mehr anmelden können.');
    }

    public function testAdminCannotDeleteOrDegradeOwnAccount(): void {
        $admin = $this->authenticatedClient();

        // Eigene ID über die Benutzerliste ermitteln (Suche trifft auch die
        // E-Mail-Spalte) - die Tests kennen bewusst nur die HTTP-Oberflächen.
        $selfId = $this->findUserIdByUsername($admin, self::$adminEmail);

        // Die Oberfläche bietet Selbstlöschung gar nicht erst an: In der nur
        // die eigene Zeile enthaltenden Suchansicht fehlt das Lösch-Formular
        // (der 2FA-Reset-Button für das eigene Konto ist dagegen sichtbar).
        $selfRow = $admin->get('/admin/users?search=' . urlencode(self::$adminEmail));
        $this->assertStringNotContainsString('/admin/users/delete', $selfRow->body, 'Für das eigene Konto darf kein Lösch-Button angeboten werden.');

        // Serverseitiger Schutz unabhängig von der Oberfläche: Ein direkter
        // Selbstlösch-POST wird still ignoriert. Ist-Verhalten des Controllers:
        // Der Redirect meldet trotzdem success=deleted - gelöscht wird nichts.
        $selfDelete = $admin->post('/admin/users/delete', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'id' => (string)$selfId,
        ]);
        $this->assertSame('/admin/users?success=deleted', $selfDelete->location());

        // Konto und Sitzung leben unverändert weiter.
        $this->assertSame(200, $admin->get('/admin/users')->statusCode, 'Die eigene Sitzung muss den Selbstlösch-Versuch überleben.');
        $stillListed = $admin->get('/admin/users?search=' . urlencode(self::$adminEmail));
        $this->assertStringContainsString('/admin/users/edit?id=' . $selfId, $stillListed->body, 'Das eigene Konto darf nicht löschbar sein.');

        // Selbst-Degradierung: Ein Update des eigenen Kontos OHNE Gruppen-
        // Auswahl darf die admin-Mitgliedschaft nicht entziehen - der
        // Controller fügt sie serverseitig wieder hinzu (#123, sonst könnte
        // sich der letzte Administrator aussperren). Stammdaten bleiben
        // unverändert (geteilter Admin-Account der ganzen Suite!).
        $editPage = $admin->get('/admin/users/edit?id=' . $selfId);
        $update = $admin->post('/admin/users/update', [
            'csrf_token' => $editPage->formField('csrf_token') ?? '',
            'id' => (string)$selfId,
            'username' => $editPage->formField('username') ?? '',
            'email' => $editPage->formField('email') ?? '',
            'password' => '',
        ]);
        $this->assertSame('/admin/users?success=updated', $update->location(), "Selbst-Update fehlgeschlagen, Body: {$update->body}");

        // Adminrechte bestehen fort (requireAdmin() prüft live über die
        // Gruppenmitgliedschaft, nichts davon liegt in der Session).
        $this->assertSame(200, $admin->get('/admin/users')->statusCode, 'Der Admin darf sich nicht selbst degradieren können.');
        $adminGroupId = $this->findBuiltinGroupId($admin, 'Administrator');
        $editAfter = $admin->get('/admin/users/edit?id=' . $selfId);
        $this->assertMatchesRegularExpression(
            '/value="' . $adminGroupId . '" selected>/',
            $editAfter->body,
            'Die admin-Gruppe muss dem eigenen Konto serverseitig erhalten bleiben.'
        );
    }

    public function testUserRoutesRequireAdminPrivileges(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $victimUsername = "uvopfer{$unique}";

        // Ziel-Konto, an dem die abgewehrten Schreibversuche nichts ändern dürfen.
        $created = $this->createUser($admin, $victimUsername, "uv-opfer-{$unique}@example.com");
        $this->assertSame('/admin/users?success=created', $created->location());
        $victimId = $this->findUserIdByUsername($admin, $victimUsername);

        // Angemeldeter Nicht-Admin ohne jede Gruppe: checkAuth() lässt ihn
        // durch, requireAdmin() im Konstruktor blockt jede UserController-
        // Route - lesend wie schreibend.
        $this->createdUsernames[] = "uvzugriff{$unique}";
        $editor = $this->createAndLoginEditor($admin, "uvzugriff{$unique}", "uv-zugriff-{$unique}@example.com");

        foreach (['/admin/users', '/admin/users/create', '/admin/users/edit?id=' . $victimId] as $path) {
            $response = $editor->get($path);
            $this->assertSame(403, $response->statusCode, "Nicht-Admin darf {$path} nicht erreichen.");
            $this->assertStringContainsString('ausschließlich Administratoren', $response->body);
        }

        // Schreibende Routen: Der Konstruktor-Guard feuert VOR der CSRF-
        // Prüfung der Methoden - der Token-Wert ist hier deshalb bedeutungslos,
        // entscheidend ist die requireAdmin()-Meldung im 403-Body.
        // (/admin/users/revoke-api-keys funktional: ApiKeyPasswordRevocationTest.)
        $this->createdUsernames[] = "uvzugriffneu{$unique}";
        $this->createdUsernames[] = "uvgeaendert{$unique}";
        $writeAttempts = [
            ['/admin/users/store', ['username' => "uvzugriffneu{$unique}", 'email' => "uv-zugriff-neu-{$unique}@example.com", 'password' => 'VerwaltungTest123!']],
            ['/admin/users/update', ['id' => (string)$victimId, 'username' => "uvgeaendert{$unique}", 'email' => "uv-geaendert-{$unique}@example.com"]],
            ['/admin/users/delete', ['id' => (string)$victimId]],
            ['/admin/users/reset-2fa', ['id' => (string)$victimId]],
            ['/admin/users/revoke-api-keys', ['id' => (string)$victimId]],
        ];
        foreach ($writeAttempts as [$path, $fields]) {
            $response = $editor->post($path, array_merge(['csrf_token' => 'egal'], $fields));
            $this->assertSame(403, $response->statusCode, "Nicht-Admin darf {$path} nicht ausführen.");
            $this->assertStringContainsString('ausschließlich Administratoren', $response->body);
        }

        // Keiner der Versuche hat gewirkt: kein neues Konto, Opfer unverändert.
        $noNewUser = $admin->get('/admin/users?search=' . urlencode("uvzugriffneu{$unique}"));
        $this->assertStringContainsString('Keine Benutzer für diese Suche gefunden.', $noNewUser->body);
        $victimUnchanged = $admin->get('/admin/users?search=' . urlencode($victimUsername));
        $this->assertStringContainsString($victimUsername, $victimUnchanged->body);

        // Gänzlich Unangemeldete kommen nicht einmal bis zum 403: checkAuth()
        // leitet lesend wie schreibend zum Login um.
        $anonymous = $this->newClient();
        $this->assertSame('/login', $anonymous->get('/admin/users')->location());
        $this->assertSame('/login', $anonymous->post('/admin/users/delete', ['id' => (string)$victimId])->location());
    }

    public function testReset2faForcesNewSetupAndRequiresCsrf(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $username = "uvzweifa{$unique}";
        $email = "uv-zweifa-{$unique}@example.com";

        // Konto mit vollständig eingerichtetem TOTP (Helper durchläuft das
        // verpflichtende 2FA-Setup der Erstanmeldung).
        $this->createdUsernames[] = $username;
        $this->createAndLoginEditor($admin, $username, $email);
        $activeBefore = $admin->get('/admin/users?search=' . urlencode($username));
        $this->assertStringContainsString('🔒 Aktiv', $activeBefore->body, 'Das Konto muss vor dem Reset aktives 2FA haben.');

        $userId = $this->findUserIdByUsername($admin, $username);

        // Ohne gültigen CSRF-Token bleibt das 2FA unangetastet.
        $csrfAttempt = $admin->post('/admin/users/reset-2fa', [
            'csrf_token' => 'ungueltig',
            'id' => (string)$userId,
        ]);
        $this->assertSame(403, $csrfAttempt->statusCode, 'Der 2FA-Reset muss CSRF-geschützt sein.');
        $stillActive = $admin->get('/admin/users?search=' . urlencode($username));
        $this->assertStringContainsString('🔒 Aktiv', $stillActive->body);

        // Echter Reset: Status kippt auf "Ausstehend" (totp_secret,
        // backup_codes und Replay-Schutz werden serverseitig geleert).
        $reset = $admin->post('/admin/users/reset-2fa', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'id' => (string)$userId,
        ]);
        $this->assertSame('/admin/users?success=2fa_reset', $reset->location());
        $pending = $admin->get('/admin/users?search=' . urlencode($username));
        $this->assertStringContainsString('⚠️ Ausstehend', $pending->body, 'Nach dem Reset darf kein aktives 2FA mehr ausgewiesen sein.');

        // Der nächste Login landet in der NEU-Einrichtung (/2fa/setup) statt
        // in der Code-Abfrage (/login/2fa) - das alte Secret ist wertlos.
        $client = $this->newClient();
        $loginPage = $client->get('/login');
        $loginResponse = $client->post('/login', [
            'csrf_token' => $loginPage->formField('csrf_token') ?? '',
            'email' => $email,
            // Endgültiges Passwort nach dem erzwungenen Wechsel der
            // Erstanmeldung, siehe FunctionalTestCase::createAndLoginEditor().
            'password' => 'EditorTestNeu456!',
        ]);
        $this->assertSame('/2fa/setup', $loginResponse->location(), "Nach dem Admin-Reset muss der Login das 2FA-Setup neu erzwingen, Body: {$loginResponse->body}");
    }

    // ---- Hilfsmethoden -------------------------------------------------

    /**
     * Legt über die echte HTTP-Oberfläche einen Benutzer an (ohne Gruppen,
     * Standard-Testpasswort) und merkt den Namen für das tearDown-Aufräumen
     * vor - auch bei absichtlich scheiternden Anlage-Versuchen unschädlich.
     *
     * @param array<string, string> $extraFields z. B. ['send_welcome_email' => '1']
     */
    private function createUser(\Tests\Support\HttpClient $admin, string $username, string $email, array $extraFields = []): \Tests\Support\HttpResponse {
        $this->createdUsernames[] = $username;
        $createForm = $admin->get('/admin/users/create');
        return $admin->post('/admin/users/store', array_merge([
            'csrf_token' => $createForm->formField('csrf_token') ?? '',
            'username' => $username,
            'email' => $email,
            'password' => 'VerwaltungTest123!',
        ], $extraFields));
    }

    /**
     * Ermittelt die Benutzer-ID über die Admin-Benutzerliste (derselbe Weg
     * wie in SessionInvalidationTest/ApiKeyPasswordRevocationTest - die Tests
     * kennen bewusst nur die echten HTTP-Oberflächen; die Suche trifft
     * Benutzername UND E-Mail-Spalte).
     */
    private function findUserIdByUsername(\Tests\Support\HttpClient $admin, string $searchTerm): int {
        $usersPage = $admin->get('/admin/users?search=' . urlencode($searchTerm));
        preg_match('/\/admin\/users\/edit\?id=(\d+)/', $usersPage->body, $matches);
        $this->assertNotEmpty($matches, "Konnte die ID zu '{$searchTerm}' nicht aus /admin/users ermitteln.");
        return (int)$matches[1];
    }
}
