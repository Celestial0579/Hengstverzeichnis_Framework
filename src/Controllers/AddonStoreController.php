<?php
// src/Controllers/AddonStoreController.php

namespace App\Controllers;

use App\Database;
use App\Plugin\PluginManager;
use App\Service\AuditLogger;
use App\Service\GithubAddonRepository;
use PDO;

/**
 * Class AddonStoreController
 *
 * Admin-only "Addon-Store" (docs/plugin-system-plan.md, Phase 3): listet
 * Plugins aus registrierten GitHub-Repositories (dem offiziellen
 * Hengstverzeichnis_Addons-Repo sowie beliebigen, von einem Admin per Link
 * hinzugefügten weiteren Repos) und installiert eine gewählte Version direkt
 * nach `plugins/<slug>/` - siehe App\Service\GithubAddonRepository für den
 * eigentlichen Download/Entpack-/Sicherheitsmechanismus.
 *
 * Wichtig: Installieren aktiviert ein Plugin NIE automatisch. Das bleibt wie
 * bisher ein separater, bewusster Schritt unter /admin/plugins
 * (App\Controllers\PluginController) - dieselbe Trennung zwischen "Code liegt
 * vor" und "Code läuft" wie beim bisherigen manuellen `cp -r`-Workflow, nur
 * dass der Kopiervorgang selbst jetzt über die UI läuft.
 */
class AddonStoreController extends BaseController {

    /** Wie lange ein zuvor abgerufener Repo-Katalog wiederverwendet wird, bevor er neu geladen wird. */
    private const CACHE_TTL_SECONDS = 900;

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requireAdmin();
    }

    public function index(): void {
        $db = Database::getInstance();
        $repos = $db->query("SELECT * FROM addon_repos ORDER BY is_official DESC, owner ASC, repo ASC")->fetchAll();

        $forceRefresh = isset($_GET['refresh']);
        $catalogs = [];
        foreach ($repos as $repoRow) {
            $catalogs[(int)$repoRow['id']] = $this->catalogForRepo($db, $repoRow, $forceRefresh);
        }

        $this->render('admin_addon_store', [
            'title' => 'Addon-Store',
            'repos' => $repos,
            'catalogs' => $catalogs,
            'discovered' => PluginManager::getInstance()->getDiscoveredPlugins(),
        ]);
    }

    /**
     * @param array<string, mixed> $repoRow
     * @return array{ok: bool, plugins: array<int, array<string, mixed>>, error: ?string}
     */
    private function catalogForRepo(PDO $db, array $repoRow, bool $forceRefresh): array {
        $cacheFresh = !$forceRefresh
            && $repoRow['cached_at'] !== null
            && (time() - strtotime((string)$repoRow['cached_at'])) < self::CACHE_TTL_SECONDS;

        if ($cacheFresh && $repoRow['cached_catalog_json'] !== null) {
            $decoded = json_decode((string)$repoRow['cached_catalog_json'], true);
            if (is_array($decoded)) {
                return ['ok' => true, 'plugins' => $decoded, 'error' => null];
            }
        }

        $result = GithubAddonRepository::fetchCatalog((string)$repoRow['owner'], (string)$repoRow['repo'], $repoRow['ref']);

        if ($result['ok']) {
            $stmt = $db->prepare("UPDATE addon_repos SET cached_catalog_json = ?, cached_at = NOW() WHERE id = ?");
            $stmt->execute([json_encode($result['plugins']), $repoRow['id']]);
        }

        return $result;
    }

    public function addRepo(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $parsed = GithubAddonRepository::parseOwnerRepo(trim($_POST['repo_url'] ?? ''));
        if ($parsed === null) {
            header("Location: /admin/plugins/store?error=invalid_repo");
            exit;
        }

        $ref = trim($_POST['ref'] ?? '');
        $ref = $ref !== '' ? $ref : null;

        $db = Database::getInstance();
        try {
            $stmt = $db->prepare("INSERT INTO addon_repos (owner, repo, ref, is_official, added_by) VALUES (?, ?, ?, 0, ?)");
            $stmt->execute([$parsed['owner'], $parsed['repo'], $ref, $_SESSION['user_id'] ?? null]);
        } catch (\Exception $e) {
            header("Location: /admin/plugins/store?error=duplicate_repo");
            exit;
        }

        AuditLogger::log(
            "Addon-Repo hinzugefügt",
            "plugin",
            "Repo: {$parsed['owner']}/{$parsed['repo']}" . ($ref !== null ? "@{$ref}" : "")
        );

        header("Location: /admin/plugins/store?success=repo_added");
        exit;
    }

    public function removeRepo(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $id = (int)($_POST['id'] ?? 0);
        $db = Database::getInstance();

        $stmt = $db->prepare("SELECT owner, repo, is_official FROM addon_repos WHERE id = ?");
        $stmt->execute([$id]);
        $repoRow = $stmt->fetch();

        // Serverseitige Durchsetzung zusätzlich zum fehlenden Button in der View -
        // das mitgelieferte offizielle Repo darf nicht entfernbar sein.
        if (!$repoRow || (bool)$repoRow['is_official']) {
            header("Location: /admin/plugins/store?error=cannot_remove_official");
            exit;
        }

        $stmt = $db->prepare("DELETE FROM addon_repos WHERE id = ?");
        $stmt->execute([$id]);

        AuditLogger::log("Addon-Repo entfernt", "plugin", "Repo: {$repoRow['owner']}/{$repoRow['repo']}");

        header("Location: /admin/plugins/store?success=repo_removed");
        exit;
    }

    public function install(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $repoId = (int)($_POST['repo_id'] ?? 0);
        $slug = trim($_POST['slug'] ?? '');
        $overwrite = !empty($_POST['overwrite']);

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM addon_repos WHERE id = ?");
        $stmt->execute([$repoId]);
        $repoRow = $stmt->fetch();

        if (!$repoRow || !preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
            header("Location: /admin/plugins/store?error=invalid_install_request");
            exit;
        }

        $pluginsDir = __DIR__ . '/../../plugins';
        $result = GithubAddonRepository::installPlugin(
            (string)$repoRow['owner'],
            (string)$repoRow['repo'],
            $repoRow['ref'],
            $slug,
            $pluginsDir,
            $overwrite
        );

        if (!$result['ok']) {
            $errorCode = $result['error'] === 'already_installed' ? 'already_installed' : 'install_failed';
            header("Location: /admin/plugins/store?error={$errorCode}&slug=" . urlencode($slug));
            exit;
        }

        // Legt (falls noch nicht vorhanden) eine plugins-Zeile mit enabled=0 an, rein
        // zur Herkunftsanzeige unter /admin/plugins - PluginManager::setEnabled()
        // überschreibt bei der eigentlichen Aktivierung ohnehin installed_version/
        // content_hash frisch vom dann aktuellen Code, lässt `source` aber unangetastet.
        $stmt = $db->prepare("INSERT INTO plugins (slug, source) VALUES (?, ?) ON DUPLICATE KEY UPDATE source = VALUES(source)");
        $stmt->execute([$slug, "{$repoRow['owner']}/{$repoRow['repo']}" . ($repoRow['ref'] ? "@{$repoRow['ref']}" : '')]);

        AuditLogger::log(
            "Plugin über Addon-Store installiert",
            "plugin",
            "Slug: {$slug}, Version: {$result['version']}, Quelle: {$repoRow['owner']}/{$repoRow['repo']}"
        );

        header("Location: /admin/plugins/store?success=installed&slug=" . urlencode($slug));
        exit;
    }
}
