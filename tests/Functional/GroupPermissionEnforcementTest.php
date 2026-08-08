<?php
// tests/Functional/GroupPermissionEnforcementTest.php

namespace Tests\Functional;

/**
 * HTTP-Funktionstests für das Gruppen-/Berechtigungssystem (#66,
 * src/Controllers/GroupController.php) - EINZIGES Rechtesystem der App
 * (users.role wurde vollständig entfernt): Anlegen eigener Gruppen, Vergeben
 * und Entziehen von Berechtigungen und deren tatsächliche Wirkung auf eine
 * echte Nicht-Admin-Sitzung (Admin hat immer alle Rechte, siehe
 * BaseController::hasPermission() - ein reiner DB-/Unit-Test würde die
 * eigentliche Durchsetzung in requirePermission() nicht abdecken), sowie die
 * serverseitigen Sicherheits-Leitplanken aus GroupController (eingebaute
 * Gruppen unlöschbar, admin/public nie editierbar, CSRF-Pflicht) und das
 * Security-by-Design-Prinzip: ein Benutzer ganz ohne Gruppenzuweisung hat
 * KEINE Rechte, Mitgliedschaft in JEDER Gruppe (auch der eingebauten
 * `editor`-Gruppe) ist ausschließlich explizit über eigene Gruppen (siehe
 * BaseController::userGroupIds()).
 */
class GroupPermissionEnforcementTest extends FunctionalTestCase {

    public function testUserWithoutAnyGroupGrantsNoPermissions(): void {
        $admin = $this->authenticatedClient();

        // Bewusst OHNE jede Gruppenzuweisung - ein Benutzer ganz ohne Gruppen darf
        // keinerlei Rechte haben, exakt wie 'public' (siehe
        // BaseController::userGroupIds()).
        $unique = uniqid();
        $editor = $this->createAndLoginEditor($admin, "norights{$unique}", "no-rights-{$unique}@example.com");

        $denied = $editor->get('/admin/horses/create');
        $this->assertSame(403, $denied->statusCode);
    }

    public function testAssigningBuiltinEditorGroupGrantsItsDefaultPermissions(): void {
        $admin = $this->authenticatedClient();

        // Die eingebaute Editor-Gruppe ist seit der Security-by-Design-Umstellung
        // eine ganz normale, bewusst zuzuweisende Gruppe wie jede eigene (siehe
        // UserController::assignableGroups()) - hier wird sie einem Benutzer
        // EXPLIZIT zugewiesen, um zu verifizieren, dass ihre vordefinierten
        // Standardrechte (siehe FunctionalTestCase::EDITOR_DEFAULT_PERMISSIONS)
        // dadurch tatsächlich wirken.
        $editorGroupId = $this->findBuiltinGroupId($admin, 'Editor');

        $unique = uniqid();
        $editor = $this->createAndLoginEditor(
            $admin,
            "realeditor{$unique}",
            "real-editor-{$unique}@example.com",
            [$editorGroupId]
        );

        $allowed = $editor->get('/admin/horses/create');
        $this->assertSame(200, $allowed->statusCode);
    }

    public function testGrantingAndRevokingGroupPermissionTakesEffect(): void {
        $admin = $this->authenticatedClient();

        // 1. Eigene Gruppe anlegen.
        $groupsPage = $admin->get('/admin/groups');
        $createResponse = $admin->post('/admin/groups/create', [
            'csrf_token' => $groupsPage->formField('csrf_token') ?? '',
            'name' => 'QA Tester ' . uniqid(),
            'description' => 'Für Funktionstests der Berechtigungsdurchsetzung',
        ]);
        $location = (string)$createResponse->location();
        $this->assertMatchesRegularExpression(
            '#^/admin/groups\?group=\d+&success=created$#',
            $location,
            "Gruppe anlegen fehlgeschlagen, Body: {$createResponse->body}"
        );
        preg_match('/group=(\d+)/', $location, $matches);
        $groupId = (int)$matches[1];

        // 2. Editor-Benutzer anlegen, Mitglied der neuen Gruppe, noch ohne eigene Rechte.
        // Erbt bewusst NICHT von der eingebauten Editor-Gruppe (Security-by-Design:
        // neue Gruppen starten bei null Rechten wie 'public', nicht bei den
        // Editor-Standardrechten - siehe BaseController::userGroupIds()).
        $unique = uniqid();
        $editor = $this->createAndLoginEditor($admin, "permtester{$unique}", "perm-test-{$unique}@example.com", [$groupId]);

        // 3. Ohne Berechtigung: verwehrt.
        $denied = $editor->get('/admin/horses/create');
        $this->assertSame(403, $denied->statusCode);

        // 4. Admin vergibt horses.create an die eigene Gruppe.
        $this->setGroupPermissions($admin, $groupId, ['horses' => ['create']]);

        // 5. Jetzt gewährt - wirkt sofort auf die laufende Editor-Sitzung, ohne
        // erneuten Login, da hasPermission() bei jedem Request neu geprüft wird.
        $allowed = $editor->get('/admin/horses/create');
        $this->assertSame(200, $allowed->statusCode);

        // 6. Admin entzieht die Berechtigung wieder (leere Auswahl übermittelt).
        $this->setGroupPermissions($admin, $groupId, []);

        // 7. Wieder verwehrt.
        $deniedAgain = $editor->get('/admin/horses/create');
        $this->assertSame(403, $deniedAgain->statusCode);
    }

    public function testCopyingPermissionsFromAdminGrantsFullAccess(): void {
        $admin = $this->authenticatedClient();

        $groupsPage = $admin->get('/admin/groups');
        $createResponse = $admin->post('/admin/groups/create', [
            'csrf_token' => $groupsPage->formField('csrf_token') ?? '',
            'name' => 'Kopiert von Admin ' . uniqid(),
            'description' => '',
        ]);
        preg_match('/group=(\d+)/', (string)$createResponse->location(), $matches);
        $targetGroupId = (int)$matches[1];

        // Administrator-Gruppe hat serverseitig nie eigene group_permissions-Zeilen
        // (siehe BaseController::hasPermission()) - ihre ID wird daher genau wie im
        // "Berechtigungen kopieren von"-Dropdown der Admin-UI aus der Gruppen-Übersicht
        // gelesen, nicht direkt aus der Datenbank.
        $adminGroupId = $this->findBuiltinGroupId($admin, 'Administrator');

        $copyResponse = $admin->post('/admin/groups/copy-permissions', [
            'csrf_token' => $admin->get('/admin/groups?group=' . $targetGroupId)->formField('csrf_token') ?? '',
            'source_group_id' => (string)$adminGroupId,
            'target_group_id' => (string)$targetGroupId,
        ]);
        $this->assertSame(
            "/admin/groups?group={$targetGroupId}&success=copied",
            $copyResponse->location(),
            "Kopieren von Admin-Berechtigungen fehlgeschlagen, Body: {$copyResponse->body}"
        );

        // Erbt bewusst NICHT von der eingebauten Editor-Gruppe (Security-by-Design,
        // siehe testGrantingAndRevokingGroupPermissionTakesEffect) - was dieser
        // Benutzer darf, kommt ausschließlich aus der eben kopierten eigenen Gruppe.
        $unique = uniqid();
        $editor = $this->createAndLoginEditor(
            $admin,
            "copytester{$unique}",
            "copy-test-{$unique}@example.com",
            [$targetGroupId]
        );

        // Von "alle Berechtigungen" kopiert -> Erstellen UND Veröffentlichen erlaubt.
        // Die Veröffentlichung ist seit der Entkopplung ein eigenes Flag (is_published),
        // unabhängig vom Lebenszyklus-Status - horses.publish steuert, ob der Benutzer
        // es setzen darf (siehe HorseController::store()).
        $createForm = $editor->get('/admin/horses/create');
        $this->assertSame(200, $createForm->statusCode);

        $horseName = 'Kopiertestpferd ' . $unique;
        $storeResponse = $editor->post('/admin/horses/store', [
            'csrf_token' => $createForm->formField('csrf_token') ?? '',
            'name' => $horseName,
            'color' => 'Rappe',
            'breeding_station' => 'Testgestüt',
            'birth_year' => '2020',
            'status' => 'active',
            'is_published' => '1',
        ]);
        $this->assertSame('/admin/horses?success=created', $storeResponse->location());

        // Ohne horses.publish würde HorseController::store() das übermittelte
        // is_published stillschweigend auf 0 setzen - dass das Pferd hier als
        // veröffentlicht in der Liste erscheint, belegt, dass die per copy-permissions
        // von Admin übernommene horses.publish-Berechtigung tatsächlich wirkt, nicht
        // nur horses.create.
        $listPage = $editor->get('/admin/horses');
        $nameEscaped = htmlspecialchars($horseName);
        $this->assertStringContainsString($nameEscaped, $listPage->body);
        // Fenster großzügig, damit beide Badges der Statuszelle (Lebenszyklus-Status
        // UND Veröffentlicht-Badge) sicher enthalten sind.
        $rowSnippet = substr($listPage->body, (int)strpos($listPage->body, $nameEscaped), 1200);
        $this->assertStringContainsString(
            '🌐 Veröffentlicht',
            $rowSnippet,
            'Pferd sollte dank kopierter horses.publish-Berechtigung direkt veröffentlicht worden sein'
        );
    }

    public function testViewPermissionGatesModuleListing(): void {
        $admin = $this->authenticatedClient();

        // Eigene Gruppe anlegen (startet ohne jede Berechtigung).
        $groupsPage = $admin->get('/admin/groups');
        $createResponse = $admin->post('/admin/groups/create', [
            'csrf_token' => $groupsPage->formField('csrf_token') ?? '',
            'name' => 'Nur Create ' . uniqid(),
            'description' => 'Testet die Leseberechtigung getrennt von create',
        ]);
        preg_match('/group=(\d+)/', (string)$createResponse->location(), $matches);
        $groupId = (int)$matches[1];

        $unique = uniqid();
        $editor = $this->createAndLoginEditor($admin, "viewtester{$unique}", "view-test-{$unique}@example.com", [$groupId]);

        // Nur horses.create (ohne horses.view): die Pferde-LISTE bleibt gesperrt,
        // obwohl das Anlegen erlaubt ist - die Leseberechtigung gated den Bereich.
        $this->setGroupPermissions($admin, $groupId, ['horses' => ['create']]);
        $denied = $editor->get('/admin/horses');
        $this->assertSame(403, $denied->statusCode, 'Ohne horses.view darf die Pferde-Liste nicht zugänglich sein');

        // Mit horses.view wird die Liste zugänglich (wirkt sofort, ohne erneuten Login).
        $this->setGroupPermissions($admin, $groupId, ['horses' => ['view', 'create']]);
        $allowed = $editor->get('/admin/horses');
        $this->assertSame(200, $allowed->statusCode);
    }

    public function testBuiltinGroupsCannotBeDeleted(): void {
        $admin = $this->authenticatedClient();

        $adminGroupId = $this->findBuiltinGroupId($admin, 'Administrator');
        $groupsOverview = $admin->get('/admin/groups');

        $deleteResponse = $admin->post('/admin/groups/delete', [
            'csrf_token' => $groupsOverview->formField('csrf_token') ?? '',
            'id' => (string)$adminGroupId,
        ]);
        $this->assertSame('/admin/groups?error=cannot_delete_builtin', $deleteResponse->location());

        // Gruppe existiert unverändert weiter.
        $overviewAfter = $admin->get('/admin/groups?group=' . $adminGroupId);
        $this->assertStringContainsString('Administrator', $overviewAfter->body);
    }

    public function testAdminPermissionsCannotBeModified(): void {
        $admin = $this->authenticatedClient();

        $adminGroupId = $this->findBuiltinGroupId($admin, 'Administrator');
        $groupsOverview = $admin->get('/admin/groups');

        // Die Admin-Gruppe hat systemseitig immer alle Rechte und bleibt als einzige
        // Gruppe von der Matrix-Bearbeitung ausgeschlossen (siehe
        // GroupController::PROTECTED_PERMISSION_SLUGS).
        $response = $admin->post('/admin/groups/permissions', [
            'csrf_token' => $groupsOverview->formField('csrf_token') ?? '',
            'group_id' => (string)$adminGroupId,
            'permissions' => ['horses' => ['create', 'edit', 'delete', 'publish']],
        ]);
        $this->assertSame(
            '/admin/groups?error=protected_group',
            $response->location(),
            'Berechtigungsänderung für die Administrator-Gruppe hätte serverseitig blockiert werden müssen'
        );
    }

    /**
     * Die Gast-Gruppe (`public`) ist seit der Einführung der Leseberechtigung eine
     * normal editierbare Gruppe: über ihre view-Rechte steuert ein Admin, welche
     * Bereiche nicht angemeldete Besucher öffentlich sehen. Sie ist damit - anders
     * als früher - NICHT mehr von der Matrix-Bearbeitung ausgeschlossen (Backend-
     * Zugriff bleibt für Gäste dennoch über checkAuth() gesperrt).
     */
    public function testGuestGroupPermissionsCanBeModified(): void {
        $admin = $this->authenticatedClient();

        $publicGroupId = $this->findBuiltinGroupId($admin, 'Gast');

        // Geprüft wird am öffentlichen HTML-Katalog, nicht mehr an der JSON-API:
        // seit der Schlüsselpflicht (siehe ApiKeyAuthTest) hängen die Rechte der
        // API am Besitzer des verwendeten Schlüssels statt an der Gast-Gruppe.
        // Der Katalog ist damit die verbliebene Fläche, die die Gast-Gruppe steuert.
        $unique = uniqid();
        $horseName = "Gastrechte Testpferd {$unique}";
        $createForm = $admin->get('/admin/horses/create');
        $createResponse = $admin->post('/admin/horses/store', [
            'csrf_token' => $createForm->formField('csrf_token') ?? '',
            'name' => $horseName,
            'status' => 'active',
            'is_published' => '1',
        ]);
        $this->assertSame('/admin/horses?success=created', $createResponse->location());

        // ID über die Admin-Liste ermitteln, um anschließend die öffentliche
        // Detailseite zu prüfen. Bewusst NICHT die Katalog-Suche als Sonde:
        // dort erscheint der Suchbegriff auch im Suchfeld der Seite, ein
        // einfaches "Name im HTML enthalten?" wäre also selbst dann wahr, wenn
        // gar keine Pferdekarte gerendert wurde.
        $horsesPage = $admin->get('/admin/horses?search=' . urlencode($horseName));
        preg_match('#/admin/horses/edit\?id=(\d+)#', $horsesPage->body, $idMatch);
        $this->assertNotEmpty($idMatch, 'Konnte die ID des Testpferds nicht aus /admin/horses ermitteln.');
        $detailPath = '/hengst?id=' . (int)$idMatch[1];

        $guest = $this->newClient();

        // Mit horses.view (Standard der Gast-Gruppe): Detailseite öffentlich erreichbar.
        $this->setGroupPermissions($admin, $publicGroupId, [
            'horses' => ['view'],
            'breeding_stations' => ['view'],
        ]);
        $this->assertSame(
            200,
            $guest->get($detailPath)->statusCode,
            'Mit horses.view der Gast-Gruppe muss die öffentliche Pferde-Detailseite erreichbar sein'
        );

        // Entziehen: Gast darf Pferde nicht mehr sehen -> Detailseite liefert 404.
        $this->setGroupPermissions($admin, $publicGroupId, ['breeding_stations' => ['view']]);
        $this->assertSame(
            404,
            $guest->get($detailPath)->statusCode,
            'Ohne horses.view der Gast-Gruppe darf die öffentliche Pferde-Detailseite nicht erreichbar sein'
        );

        // Wiederherstellen der Standard-Lese-Rechte der Gast-Gruppe.
        $this->setGroupPermissions($admin, $publicGroupId, [
            'horses' => ['view'],
            'breeding_stations' => ['view'],
        ]);
    }

    /**
     * Seit der Entfernung von users.role ist Mitgliedschaft in der eingebauten
     * Gruppe `admin` die EINZIGE Quelle für Adminrechte (siehe
     * BaseController::requireAdmin()/isAdmin()) - anders als jede andere
     * Gruppe muss `admin` deshalb regulär über /admin/users zuweisbar sein
     * (siehe UserController::assignableGroups()), auch wenn ihre eigene
     * Berechtigungs-Matrix weiterhin nicht editierbar ist (siehe
     * testAdminAndPublicPermissionsCannotBeModified()).
     */
    public function testAssigningBuiltinAdminGroupGrantsFullAdminAccess(): void {
        $admin = $this->authenticatedClient();
        $adminGroupId = $this->findBuiltinGroupId($admin, 'Administrator');

        $unique = uniqid();
        $newAdmin = $this->createAndLoginEditor(
            $admin,
            "newadmin{$unique}",
            "new-admin-{$unique}@example.com",
            [$adminGroupId]
        );

        // Vorher requireAdmin()-only, unabhängig von group_permissions.
        $usersPage = $newAdmin->get('/admin/users');
        $this->assertSame(200, $usersPage->statusCode);
    }

    public function testGroupMutationsRequireCsrfToken(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->post('/admin/groups/create', [
            'name' => 'Ohne CSRF-Token',
        ]);
        $this->assertSame(403, $response->statusCode);
    }
}
