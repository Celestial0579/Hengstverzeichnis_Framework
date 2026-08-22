<?php
// tests/Functional/AddonStoreAdminTest.php

namespace Tests\Functional;

use Tests\Support\HttpClient;

/**
 * HTTP-Funktionstests für den Addon-Store (App\Controllers\AddonStoreController):
 * Admin-Pflicht, CSRF-Schutz und serverseitige Validierung. Bewusst OHNE
 * Erwartungen an einen tatsächlich erfolgreichen GitHub-Download - die Seite
 * muss auch dann normal rendern, wenn der (live abgerufene) Katalog des
 * offiziellen Repos in der Testumgebung nicht erreichbar ist (siehe
 * AddonStoreController::catalogForRepo(), das einen Fehlschlag abfängt statt
 * die Seite abstürzen zu lassen). Das eigentliche Download-/Entpack-
 * Sicherheitsverhalten wird unabhängig von echtem Netzwerkzugriff in
 * tests/Unit/Service/GithubAddonRepositoryTest.php geprüft.
 */
class AddonStoreAdminTest extends FunctionalTestCase {

    /**
     * Katalog-Cache vorbelegen, BEVOR die Store-Seite geöffnet wird.
     *
     * Ohne Cache holt `/admin/plugins/store` den Katalog live von
     * api.github.com. Damit misst dieser Test die Erreichbarkeit von GitHub
     * statt die eigene Seite: Ohne Netz - im nächtlichen Lauf, hinter einem
     * Egress-Filter, bei erschöpftem Ratelimit - läuft die Anfrage zehn
     * Sekunden ins Timeout und der Test wird rot, obwohl nichts kaputt ist.
     * `tests/Integration/UpdateRunTest.php` macht es aus genau diesem Grund
     * seit Langem so; hier fehlte es.
     *
     * Ein leerer Katalog genügt: Geprüft wird die Seite mit ihren
     * Repo-Zeilen, nicht der Inhalt des Stores.
     */
    protected function setUp(): void {
        parent::setUp();

        \App\Database::getInstance()->exec(
            "UPDATE addon_repos SET cached_catalog_json = '[]', cached_at = NOW() WHERE is_official = 1"
        );
    }

    public function testStorePageRequiresAdmin(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $editor = $this->createAndLoginEditor($admin, "storetester{$unique}", "store-test-{$unique}@example.com");

        $response = $editor->get('/admin/plugins/store');
        $this->assertSame(403, $response->statusCode);
    }

    public function testStorePageIsReachableForAdminAndListsOfficialRepo(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->get('/admin/plugins/store');
        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('Addon-Store', $response->body);
        $this->assertStringContainsString('Hengstverzeichnis_Addons', $response->body);
        $this->assertStringContainsString('Offiziell', $response->body);
    }

    public function testAddRepoRequiresCsrfToken(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->post('/admin/plugins/store/add-repo', [
            'repo_url' => 'someone/some-addon-repo',
        ]);
        $this->assertSame(403, $response->statusCode);
    }

    public function testAddRepoRejectsInvalidFormat(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->post('/admin/plugins/store/add-repo', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'repo_url' => 'dies ist kein repo link',
        ]);
        $this->assertSame('/admin/plugins/store?error=invalid_repo', $response->location());
    }

    public function testAddingAndRemovingCustomRepoWorks(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $repoName = "test-addon-repo-{$unique}";

        $addResponse = $admin->post('/admin/plugins/store/add-repo', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'repo_url' => "https://github.com/someuser/{$repoName}",
        ]);
        $this->assertSame('/admin/plugins/store?success=repo_added', $addResponse->location());

        $storePage = $admin->get('/admin/plugins/store');
        $this->assertStringContainsString($repoName, $storePage->body);

        // Erneutes Hinzufügen desselben Repos muss als Duplikat abgelehnt werden.
        $duplicateResponse = $admin->post('/admin/plugins/store/add-repo', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'repo_url' => "someuser/{$repoName}",
        ]);
        $this->assertSame('/admin/plugins/store?error=duplicate_repo', $duplicateResponse->location());

        // Repo-ID aus der gerenderten Seite extrahieren, um es wieder zu entfernen.
        $this->assertMatchesRegularExpression('#name="id" value="(\d+)"#', $storePage->body);
        preg_match_all('#name="id" value="(\d+)"#', $storePage->body, $matches);
        $repoId = end($matches[1]);

        $removeResponse = $admin->post('/admin/plugins/store/remove-repo', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'id' => $repoId,
        ]);
        $this->assertSame('/admin/plugins/store?success=repo_removed', $removeResponse->location());

        $storePageAfterRemoval = $admin->get('/admin/plugins/store');
        $this->assertStringNotContainsString($repoName, $storePageAfterRemoval->body);
    }

    /**
     * Ermittelt die ID der offiziellen, per Migration geseedeten
     * Hengstverzeichnis_Addons-Zeile aus der gerenderten Seite, statt eine
     * feste ID (z. B. "1") anzunehmen - AddonStoreController::index() sortiert
     * per "ORDER BY is_official DESC, ...", das offizielle Repo ist daher
     * immer der erste "repo-<id>"-Anker in der Ausgabe (siehe
     * admin_addon_store.php).
     */
    private function officialRepoId(HttpClient $admin): string {
        $storePage = $admin->get('/admin/plugins/store');
        preg_match('#id="repo-(\d+)"#', $storePage->body, $matches);
        $this->assertNotEmpty($matches, 'Konnte offizielle Repo-ID nicht aus der Store-Seite extrahieren.');
        return $matches[1];
    }

    public function testOfficialRepoCannotBeRemoved(): void {
        $admin = $this->authenticatedClient();
        $officialId = $this->officialRepoId($admin);

        // Das offizielle Repo (is_official=1) hat kein Entfernen-Formular in der View -
        // serverseitig zusätzlich erzwungen, siehe AddonStoreController::removeRepo().
        $response = $admin->post('/admin/plugins/store/remove-repo', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'id' => $officialId,
        ]);
        $this->assertSame('/admin/plugins/store?error=cannot_remove_official', $response->location());
    }

    public function testInstallRejectsUnknownRepo(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->post('/admin/plugins/store/install', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'repo_id' => '999999',
            'slug' => 'demo-addon',
        ]);
        $this->assertSame('/admin/plugins/store?error=invalid_install_request', $response->location());
    }

    public function testInstallRejectsInvalidSlug(): void {
        $admin = $this->authenticatedClient();
        $officialId = $this->officialRepoId($admin);

        $response = $admin->post('/admin/plugins/store/install', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'repo_id' => $officialId,
            'slug' => '../../etc/passwd',
        ]);
        $this->assertSame('/admin/plugins/store?error=invalid_install_request', $response->location());
    }
}
