<?php
// src/Controllers/GroupController.php

namespace App\Controllers;

use App\Database;
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
 * - `public` darf NIE sicherheitsrelevante (schreibende) Berechtigungen
 *   erhalten - sowohl in der View (deaktivierte Checkboxen) als auch hier
 *   serverseitig (harte Ablehnung) durchgesetzt.
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

        $this->render('admin_groups', [
            'title' => 'Gruppen & Berechtigungen',
            'groups' => $groups,
            'permissions' => $permissions,
            'modules' => PermissionRegistry::MODULES,
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

            AuditLogger::log("Gruppe angelegt", "groups", "Gruppe: {$name} (Slug: {$slug})");

            header("Location: /admin/groups?success=created");
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

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("DELETE FROM group_permissions WHERE group_id = ?");
            $stmt->execute([$groupId]);

            $insertStmt = $db->prepare("INSERT INTO group_permissions (group_id, module, action) VALUES (?, ?, ?)");
            foreach ($selected as $module => $actions) {
                foreach ((array)$actions as $action) {
                    if (PermissionRegistry::isValid((string)$module, (string)$action)) {
                        $insertStmt->execute([$groupId, (string)$module, (string)$action]);
                    }
                }
            }

            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            header("Location: /admin/groups?error=save_failed");
            exit;
        }

        AuditLogger::log("Berechtigungen aktualisiert", "groups", "Gruppe: {$group['name']}");

        header("Location: /admin/groups?success=permissions_updated");
        exit;
    }
}
