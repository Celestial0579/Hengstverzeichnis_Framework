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

    /**
     * Zusatzspalte, die jede addon_repos-Abfrage mitführen muss, deren Zeilen
     * an catalogForRepo() gehen.
     *
     * Das Alter wird bewusst in SQL berechnet: `cached_at` schreibt MySQL mit
     * NOW(), also in der Zeitzone des Datenbankservers. Ein Vergleich gegen
     * PHPs time() über strtotime() legt dagegen die PHP-Zeitzone zugrunde -
     * laufen beide auseinander (Container auf UTC, PHP auf Europe/Berlin),
     * liegt die TTL-Prüfung um genau diesen Versatz daneben und der Cache
     * gilt dauerhaft als abgelaufen. TIMESTAMPDIFF vergleicht beide Werte auf
     * derselben Uhr - dasselbe Prinzip, mit dem App\Service\Scheduler seine
     * Fälligkeiten über Unix-Zeitstempel bestimmt.
     */
    public const CACHE_AGE_SELECT = 'TIMESTAMPDIFF(SECOND, cached_at, NOW()) AS cached_age_seconds';

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requireAdmin();
    }

    public function index(): void {
        $db = Database::getInstance();
        $repos = $db->query(
            "SELECT *, " . self::CACHE_AGE_SELECT . " FROM addon_repos ORDER BY is_official DESC, owner ASC, repo ASC"
        )->fetchAll();

        $forceRefresh = isset($_GET['refresh']);
        $catalogs = [];
        foreach ($repos as $repoRow) {
            $catalogs[(int)$repoRow['id']] = self::catalogForRepo($db, $repoRow, $forceRefresh);
        }

        $this->render('admin_addon_store', [
            'title' => 'Addon-Store',
            'repos' => $repos,
            'catalogs' => $catalogs,
            'discovered' => PluginManager::getInstance()->getDiscoveredPlugins(),
        ]);
    }

    /**
     * Reine TTL-Entscheidung ohne Netz und ohne Datenbank, damit die Grenze
     * isoliert prüfbar bleibt (gleiche Trennung wie
     * App\Service\AddonUpdateService::resolveAutoUpdateRef()).
     *
     * Erwartet das Alter in Sekunden, nicht den Zeitstempel - siehe
     * CACHE_AGE_SELECT. Ein negatives Alter (Uhr des Datenbankservers
     * zurückgestellt) gilt als frisch: Der Eintrag ist dann jünger als jetzt,
     * ein sofortiger Neuabruf brächte nichts.
     */
    public static function isCacheFresh(?int $ageSeconds, bool $forceRefresh): bool {
        return !$forceRefresh
            && $ageSeconds !== null
            && $ageSeconds < self::CACHE_TTL_SECONDS;
    }

    /**
     * Liefert den Katalog eines Repos und frischt ihn bei abgelaufener TTL
     * gegen GitHub auf. Seit #290 ruft auch UpdateController::index() das
     * hier auf (nur für das offizielle Repo): Die Update-Seite zeigte sonst
     * einen beliebig veralteten Stand, solange niemand den Store besuchte.
     *
     * @param array<string, mixed> $repoRow
     * @return array{ok: bool, plugins: array<int, array<string, mixed>>, error: ?string}
     */
    public static function catalogForRepo(PDO $db, array $repoRow, bool $forceRefresh): array {
        $cacheFresh = self::isCacheFresh(
            isset($repoRow['cached_age_seconds']) ? (int)$repoRow['cached_age_seconds'] : null,
            $forceRefresh
        );

        if ($cacheFresh && $repoRow['cached_catalog_json'] !== null) {
            $decoded = json_decode((string)$repoRow['cached_catalog_json'], true);
            if (is_array($decoded)) {
                return ['ok' => true, 'plugins' => $decoded, 'error' => null];
            }
        }

        $result = GithubAddonRepository::fetchCatalog(
            (string)$repoRow['owner'],
            (string)$repoRow['repo'],
            self::effectiveRef($repoRow)
        );

        if ($result['ok']) {
            $stmt = $db->prepare("UPDATE addon_repos SET cached_catalog_json = ?, cached_at = NOW() WHERE id = ?");
            $stmt->execute([json_encode($result['plugins']), $repoRow['id']]);
        }

        return $result;
    }

    /**
     * Effektiver Bezugspunkt eines Repos für Katalog UND Install. Für das
     * OFFIZIELLE Repo ohne festen Ref (#197 Stufe 3, #212): der beste
     * Release-Tag der laufenden Kern-Linie statt des Branch-HEAD - ein halb
     * fertiger main-Stand kann so nie unbemerkt auf einer Produktivinstanz
     * landen. Existiert (noch) kein passender Release, bleibt hier - anders
     * als beim AUTOMATISCHEN Update im AddonUpdateService, das dann
     * verweigert - der Branch-Bezug als Fallback erlaubt: Der Store-Install
     * ist eine bewusste Einzel-Aktion eines Admins, der die Quelle sieht und
     * die Vertrauensentscheidung selbst trifft (dasselbe Modell wie bei
     * Fremd-Repos); genau dieser Unterschied auto vs. manuell ist der Kern
     * von #212. Fremd-Repos: unverändert der konfigurierte Ref bzw.
     * Standard-Branch.
     *
     * @param array<string, mixed> $repoRow
     */
    public static function effectiveRef(array $repoRow): ?string {
        $ref = $repoRow['ref'] !== null && $repoRow['ref'] !== '' ? (string)$repoRow['ref'] : null;
        if ($ref === null && (int)$repoRow['is_official'] === 1) {
            $line = \App\Service\AddonUpdateService::coreLine(defined('CORE_VERSION') ? CORE_VERSION : '');
            if ($line !== null) {
                $ref = GithubAddonRepository::bestReleaseTagForCoreLine(
                    (string)$repoRow['owner'],
                    (string)$repoRow['repo'],
                    $line
                );
            }
        }
        return $ref;
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

        // Install vom selben Bezugspunkt wie der Katalog (#212): für das
        // offizielle Repo ohne festen Ref wird der beste Release-Tag der
        // laufenden Kern-Linie aufgelöst - der Admin installiert also genau
        // den Stand, den ihm der Katalog angezeigt hat, nicht einen
        // womöglich inzwischen weitergewanderten Branch-HEAD. Ohne Release
        // fällt NUR dieser manuelle Weg auf den Branch zurück, siehe
        // effectiveRef().
        $ref = self::effectiveRef($repoRow);

        $pluginsDir = __DIR__ . '/../../plugins';
        $result = GithubAddonRepository::installPlugin(
            (string)$repoRow['owner'],
            (string)$repoRow['repo'],
            $ref,
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
        // Mit aufgelöstem Release-Tag entsteht dabei der "@vX.Y.z"-Pin, den die
        // Auto-Accept-Regel des PluginManagers als verifizierten Release-Bezug wertet;
        // ein Branch-Install bleibt ohne Pin und damit wiederfreigabepflichtig.
        $stmt = $db->prepare("INSERT INTO plugins (slug, source) VALUES (?, ?) ON DUPLICATE KEY UPDATE source = VALUES(source)");
        $stmt->execute([$slug, "{$repoRow['owner']}/{$repoRow['repo']}" . ($ref !== null ? "@{$ref}" : '')]);

        AuditLogger::log(
            "Plugin über Addon-Store installiert",
            "plugin",
            "Slug: {$slug}, Version: {$result['version']}, Quelle: {$repoRow['owner']}/{$repoRow['repo']}"
            . ($ref !== null ? "@{$ref}" : " (Standard-Branch)")
        );

        header("Location: /admin/plugins/store?success=installed&slug=" . urlencode($slug));
        exit;
    }
}
