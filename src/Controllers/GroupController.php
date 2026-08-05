<?php
// src/Controllers/GroupController.php

namespace App\Controllers;

use App\Database;
use App\Helper\Paginator;
use App\Permission\PermissionRegistry;
use App\Service\AuditLogger;
use PDO;

/**
 * Class GroupController
 *
 * Admin-only Verwaltung des Gruppen-/Berechtigungssystems (#66): eingebaute
 * Gruppen (admin/editor/public) plus beliebig viele eigene Gruppen, sowie
 * die Berechtigungsmatrix (Modul × Aktion) je Gruppe.
 *
 * Sicherheits-Leitplanken (siehe auch docs/user-groups-plan.md, Abschnitt 8):
 * - `admin` hat serverseitig hart codiert immer alle Rechte
 *   (BaseController::hasPermission()) - dafür gibt es absichtlich keine
 *   editierbaren Berechtigungs-Zeilen.
 * - `public` (nicht angemeldete Besucher) erhält NIE irgendeine Berechtigung
 *   und damit nie Zugriff auf das Backend - sowohl in der View (deaktivierte
 *   Checkboxen) als auch hier serverseitig (harte Ablehnung) durchgesetzt.
 *   Unabhängig davon ist der Backend-Zugriff für Gäste ohnehin bereits durch
 *   BaseController::checkAuth() versperrt (siehe dortiger Hinweis) - dieses
 *   Modul verhindert zusätzlich, dass die Gruppe `public` überhaupt jemals
 *   eine Berechtigungs-Zeile erhalten könnte.
 * - Eingebaute Gruppen (admin/editor/public) können nicht gelöscht werden.
 */
class GroupController extends BaseController {

    /** Gruppen, deren Berechtigungen serverseitig nie verändert werden dürfen. */
    private const PROTECTED_PERMISSION_SLUGS = ['admin', 'public'];

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requireAdmin();
    }

    public function index(): void {
        $db = Database::getInstance();
        $groups = $db->query("SELECT * FROM `groups` ORDER BY is_builtin DESC, name ASC")->fetchAll();

        $permissions = [];
        $rows = $db->query("SELECT group_id, module, action FROM group_permissions")->fetchAll();
        foreach ($rows as $row) {
            $permissions[(int)$row['group_id']][$row['module']][$row['action']] = true;
        }

        // Aktuell zur Bearbeitung ausgewählte Gruppe (Dropdown, siehe admin_groups.php) -
        // Kompaktheit: es wird immer nur die Berechtigungsmatrix EINER Gruppe angezeigt,
        // nicht mehr aller Gruppen untereinander. Default: die erste nicht-eingebaute
        // Gruppe (meist "Editor"), da das der häufigste Bearbeitungsfall ist.
        // WICHTIG: Diese Auswahl bezieht sich immer auf die VOLLSTÄNDIGE Gruppenliste,
        // unabhängig von der Pagination der Übersichtstabelle unten (siehe $pagedGroups).
        $groupIds = array_column($groups, 'id');
        $selectedGroupId = isset($_GET['group']) ? (int)$_GET['group'] : 0;
        if (!in_array($selectedGroupId, array_map('intval', $groupIds), true)) {
            $defaultGroup = null;
            foreach ($groups as $g) {
                if (!$g['is_builtin'] || $g['slug'] === 'editor') {
                    $defaultGroup = $g;
                    break;
                }
            }
            $selectedGroupId = $defaultGroup ? (int)$defaultGroup['id'] : (int)($groups[0]['id'] ?? 0);
        }

        // Suche + Pagination der Übersichtstabelle (nicht der Bearbeiten-Auswahl oben,
        // siehe App\Helper\Paginator) - wirkt nur auf $pagedGroups, NICHT auf $groups
        // selbst (die "Gruppe zur Bearbeitung auswählen"- und "Berechtigungen kopieren
        // von"-Dropdowns bleiben unabhängig von Suche/Pagination immer vollständig).
        $search = trim((string)($_GET['search'] ?? ''));
        $searchableGroups = Paginator::search($groups, $search, ['name', 'description']);
        $perPage = Paginator::readPerPage($_GET);
        $result = Paginator::paginate($searchableGroups, $perPage, (int)($_GET['page'] ?? 1));

        $this->render('admin_groups', [
            'title' => 'Gruppen & Berechtigungen',
            'groups' => $groups,
            'pagedGroups' => $result['items'],
            'search' => $search,
            'totalGroupsUnfiltered' => count($groups),
            'permissions' => $permissions,
            'modules' => PermissionRegistry::MODULES,
            'selectedGroupId' => $selectedGroupId,
            'totalPermissionCount' => PermissionRegistry::countAll(),
            'perPage' => $perPage,
            'perPageOptions' => Paginator::PER_PAGE_OPTIONS,
            'page' => $result['page'],
            'totalPages' => $result['totalPages'],
            'totalGroups' => $result['total'],
        ]);
    }

    public function createGroup(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (empty($name)) {
            header("Location: /admin/groups?error=name_required");
            exit;
        }

        $slug = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
        if ($slug === '' || in_array($slug, ['admin', 'editor', 'public'], true)) {
            header("Location: /admin/groups?error=invalid_slug");
            exit;
        }

        $db = Database::getInstance();
        try {
            $stmt = $db->prepare("INSERT INTO `groups` (slug, name, description, is_builtin) VALUES (?, ?, ?, 0)");
            $stmt->execute([$slug, $name, $description]);
            $newGroupId = (int)$db->lastInsertId();

            AuditLogger::log("Gruppe angelegt", "groups", "Gruppe: {$name} (Slug: {$slug})");

            header("Location: /admin/groups?group={$newGroupId}&success=created");
        } catch (\Exception $e) {
            header("Location: /admin/groups?error=slug_taken");
        }
        exit;
    }

    public function deleteGroup(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $id = (int)($_POST['id'] ?? 0);
        $db = Database::getInstance();

        $stmt = $db->prepare("SELECT slug, name, is_builtin FROM `groups` WHERE id = ?");
        $stmt->execute([$id]);
        $group = $stmt->fetch();

        // Eingebaute Gruppen sind Teil des Kern-Sicherheitsmodells (siehe
        // BaseController::hasPermission()) und dürfen nicht gelöscht werden.
        if (!$group || (bool)$group['is_builtin']) {
            header("Location: /admin/groups?error=cannot_delete_builtin");
            exit;
        }

        $stmt = $db->prepare("DELETE FROM `groups` WHERE id = ?");
        $stmt->execute([$id]);

        AuditLogger::log("Gruppe gelöscht", "groups", "Gruppe: {$group['name']}");

        header("Location: /admin/groups?success=deleted");
        exit;
    }

    public function updatePermissions(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $groupId = (int)($_POST['group_id'] ?? 0);
        $db = Database::getInstance();

        $stmt = $db->prepare("SELECT slug, name FROM `groups` WHERE id = ?");
        $stmt->execute([$groupId]);
        $group = $stmt->fetch();

        if (!$group) {
            header("Location: /admin/groups?error=unknown_group");
            exit;
        }

        // Serverseitige Durchsetzung zusätzlich zu den deaktivierten Checkboxen in der
        // View - eine manipulierte Anfrage darf diese Regeln nicht umgehen können.
        if (in_array($group['slug'], self::PROTECTED_PERMISSION_SLUGS, true)) {
            header("Location: /admin/groups?error=protected_group");
            exit;
        }

        /** @var array<string, array<int, string>> $selected */
        $selected = $_POST['permissions'] ?? [];
        $this->replacePermissions($db, $groupId, $this->flattenSelectedPermissions($selected));

        AuditLogger::log("Berechtigungen aktualisiert", "groups", "Gruppe: {$group['name']}");

        header("Location: /admin/groups?group={$groupId}&success=permissions_updated");
        exit;
    }

    /**
     * Kopiert die komplette Berechtigungsmenge einer Quell-Gruppe auf eine Ziel-Gruppe
     * (überschreibt die bisherigen Berechtigungen der Ziel-Gruppe vollständig). Für die
     * Quelle "admin" wird dabei bewusst nicht group_permissions abgefragt (dort gibt es
     * absichtlich keine Zeilen, siehe BaseController::hasPermission()), sondern der
     * vollständige App\Permission\PermissionRegistry-Katalog als "alle Rechte" verwendet -
     * andernfalls würde "von Admin kopieren" fälschlich 0 Berechtigungen übertragen.
     */
    public function copyPermissions(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $sourceGroupId = (int)($_POST['source_group_id'] ?? 0);
        $targetGroupId = (int)($_POST['target_group_id'] ?? 0);
        $db = Database::getInstance();

        $stmt = $db->prepare("SELECT id, slug, name FROM `groups` WHERE id IN (?, ?)");
        $stmt->execute([$sourceGroupId, $targetGroupId]);
        $found = $stmt->fetchAll();
        $bySlugId = [];
        foreach ($found as $row) {
            $bySlugId[(int)$row['id']] = $row;
        }

        $source = $bySlugId[$sourceGroupId] ?? null;
        $target = $bySlugId[$targetGroupId] ?? null;

        if (!$source || !$target) {
            header("Location: /admin/groups?error=unknown_group");
            exit;
        }

        // Ziel bleibt geschützt (admin/public) - siehe updatePermissions() für die Begründung.
        if (in_array($target['slug'], self::PROTECTED_PERMISSION_SLUGS, true)) {
            header("Location: /admin/groups?error=protected_group");
            exit;
        }

        if ($source['slug'] === 'admin') {
            $pairs = PermissionRegistry::allPairs();
        } else {
            $stmt = $db->prepare("SELECT module, action FROM group_permissions WHERE group_id = ?");
            $stmt->execute([$sourceGroupId]);
            $pairs = $stmt->fetchAll();
        }

        $this->replacePermissions($db, $targetGroupId, $pairs);

        AuditLogger::log(
            "Berechtigungen kopiert",
            "groups",
            "Von '{$source['name']}' nach '{$target['name']}'"
        );

        header("Location: /admin/groups?group={$targetGroupId}&success=copied");
        exit;
    }

    /**
     * @param array<string, array<int, string>> $selected Aus $_POST['permissions'], Format module => [action, ...]
     * @return array<int, array{module:string, action:string}>
     */
    private function flattenSelectedPermissions(array $selected): array {
        $pairs = [];
        foreach ($selected as $module => $actions) {
            foreach ((array)$actions as $action) {
                $pairs[] = ['module' => (string)$module, 'action' => (string)$action];
            }
        }
        return $pairs;
    }

    /**
     * Ersetzt die komplette group_permissions-Menge einer Gruppe durch $pairs
     * (nur gültige Modul/Aktion-Kombinationen aus PermissionRegistry werden übernommen).
     *
     * @param array<int, array{module:string, action:string}> $pairs
     */
    private function replacePermissions(PDO $db, int $groupId, array $pairs): void {
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("DELETE FROM group_permissions WHERE group_id = ?");
            $stmt->execute([$groupId]);

            $insertStmt = $db->prepare("INSERT INTO group_permissions (group_id, module, action) VALUES (?, ?, ?)");
            foreach ($pairs as $pair) {
                if (PermissionRegistry::isValid($pair['module'], $pair['action'])) {
                    $insertStmt->execute([$groupId, $pair['module'], $pair['action']]);
                }
            }

            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            header("Location: /admin/groups?error=save_failed");
            exit;
        }
    }
}
