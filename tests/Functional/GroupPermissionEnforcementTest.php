<?php
// tests/Functional/GroupPermissionEnforcementTest.php

namespace Tests\Functional;

/**
 * HTTP-Funktionstests für das Gruppen-/Berechtigungssystem (#66,
 * src/Controllers/GroupController.php): Anlegen eigener Gruppen, Vergeben und
 * Entziehen von Berechtigungen und deren tatsächliche Wirkung auf eine echte
 * Nicht-Admin-Sitzung (Admin hat immer alle Rechte, siehe
 * BaseController::hasPermission() - ein reiner DB-/Unit-Test würde die
 * eigentliche Durchsetzung in requirePermission() nicht abdecken), sowie die
 * serverseitigen Sicherheits-Leitplanken aus GroupController (eingebaute
 * Gruppen unlöschbar, admin/public nie editierbar, CSRF-Pflicht).
 */
class GroupPermissionEnforcementTest extends FunctionalTestCase {

    public function testGrantingAndRevokingGroupPermissionTakesEffect(): void {
        $admin = $this->authenticatedClient();

        // Jeder Editor hat horses.create standardmäßig schon über seine
        // Rollen-Gruppe (siehe EDITOR_DEFAULT_PERMISSIONS) - für einen
        // eindeutigen Nachweis, dass die GLEICH getestete EIGENE Gruppe die
        // Berechtigung tatsächlich steuert, wird sie der Editor-Gruppe hier
        // testweise entzogen und danach wiederhergestellt.
        $editorGroupId = $this->findBuiltinGroupId($admin, 'Editor');
        $editorWithoutHorseCreate = self::EDITOR_DEFAULT_PERMISSIONS;
        $editorWithoutHorseCreate['horses'] = array_values(array_diff($editorWithoutHorseCreate['horses'], ['create']));
        $this->setGroupPermissions($admin, $editorGroupId, $editorWithoutHorseCreate);

        try {
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
            $unique = uniqid();
            $editor = $this->createAndLoginEditor($admin, "permtester{$unique}", "perm-test-{$unique}@example.com", [$groupId]);

            // 3. Ohne Berechtigung (weder über Editor-Rolle noch eigene Gruppe): verwehrt.
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
        } finally {
            // Editor-Standardrechte wiederherstellen, damit dieser Test keine
            // Nachwirkungen auf andere Tests im selben Prozess/derselben DB hat.
            $this->setGroupPermissions($admin, $editorGroupId, self::EDITOR_DEFAULT_PERMISSIONS);
        }
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

        // Editor hat horses.create/publish standardmäßig schon über seine
        // Rollen-Gruppe (siehe EDITOR_DEFAULT_PERMISSIONS) - für einen eindeutigen
        // Nachweis, dass der GLEICH getestete Kopiervorgang die Berechtigung
        // tatsächlich überträgt, wird der Editor-Gruppe hier testweise alles
        // entzogen und danach wiederhergestellt.
        $editorGroupId = $this->findBuiltinGroupId($admin, 'Editor');
        $this->setGroupPermissions($admin, $editorGroupId, []);

        try {
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

            $unique = uniqid();
            $editor = $this->createAndLoginEditor(
                $admin,
                "copytester{$unique}",
                "copy-test-{$unique}@example.com",
                [$targetGroupId]
            );

            // Von "alle Berechtigungen" kopiert -> Erstellen UND Veröffentlichen erlaubt
            // (horses.publish ist die restriktivste der vier Standard-Aktionen, siehe
            // HorseController::store()) - beides ausschließlich über die kopierte
            // eigene Gruppe, da Editor oben auf 0 Rechte gesetzt wurde.
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
            ]);
            $this->assertSame('/admin/horses?success=created', $storeResponse->location());

            // Ohne horses.publish würde HorseController::store() den übermittelten Status
            // 'active' stillschweigend auf 'inactive' herabstufen (siehe dortige
            // Begründung) - dass es hier 'active' bleibt, belegt, dass die per
            // copy-permissions von Admin übernommene horses.publish-Berechtigung
            // tatsächlich wirkt, nicht nur horses.create.
            $listPage = $editor->get('/admin/horses');
            $nameEscaped = htmlspecialchars($horseName);
            $this->assertStringContainsString($nameEscaped, $listPage->body);
            $rowSnippet = substr($listPage->body, (int)strpos($listPage->body, $nameEscaped), 600);
            $this->assertStringContainsString(
                'Aktiv (Gekört)',
                $rowSnippet,
                'Pferd sollte dank kopierter horses.publish-Berechtigung direkt aktiv angelegt worden sein'
            );
        } finally {
            $this->setGroupPermissions($admin, $editorGroupId, self::EDITOR_DEFAULT_PERMISSIONS);
        }
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

    public function testAdminAndPublicPermissionsCannotBeModified(): void {
        $admin = $this->authenticatedClient();

        $adminGroupId = $this->findBuiltinGroupId($admin, 'Administrator');
        $publicGroupId = $this->findBuiltinGroupId($admin, 'Öffentlich');
        $groupsOverview = $admin->get('/admin/groups');

        foreach (['Administrator-Gruppe' => $adminGroupId, 'Public-Gruppe' => $publicGroupId] as $label => $groupId) {
            $response = $admin->post('/admin/groups/permissions', [
                'csrf_token' => $groupsOverview->formField('csrf_token') ?? '',
                'group_id' => (string)$groupId,
                'permissions' => ['horses' => ['create', 'edit', 'delete', 'publish']],
            ]);
            $this->assertSame(
                '/admin/groups?error=protected_group',
                $response->location(),
                "Berechtigungsänderung für {$label} hätte serverseitig blockiert werden müssen"
            );
        }
    }

    public function testGroupMutationsRequireCsrfToken(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->post('/admin/groups/create', [
            'name' => 'Ohne CSRF-Token',
        ]);
        $this->assertSame(403, $response->statusCode);
    }
}
