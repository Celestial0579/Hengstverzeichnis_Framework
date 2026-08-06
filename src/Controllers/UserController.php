<?php
// src/Controllers/UserController.php

namespace App\Controllers;

use App\Database;
use App\Helper\Paginator;

class UserController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requireAdmin();
    }

    public function index(): void {
        $db = Database::getInstance();
        // group_names: für die Anzeige aggregierte Gruppenmitgliedschaft (#66) -
        // ersetzt die frühere "Rolle"-Spalte, seit Gruppen das einzige Rechtesystem sind.
        $stmt = $db->query("
            SELECT u.id, u.username, u.email, u.created_at, u.totp_enabled,
                   GROUP_CONCAT(g.name ORDER BY g.is_builtin DESC, g.name SEPARATOR ', ') AS group_names
            FROM users u
            LEFT JOIN user_groups ug ON ug.user_id = u.id
            LEFT JOIN `groups` g ON g.id = ug.group_id
            WHERE u.deleted_at IS NULL
            GROUP BY u.id
            ORDER BY u.username ASC
        ");
        $users = $stmt->fetchAll();

        // Suche + Seitengrößen-Auswahl/Pagination (10/25/50/100/alle), analog zu
        // GroupController::index() - siehe App\Helper\Paginator.
        $search = trim((string)($_GET['search'] ?? ''));
        $searchableUsers = Paginator::search($users, $search, ['username', 'email']);
        $perPage = Paginator::readPerPage($_GET);
        $result = Paginator::paginate($searchableUsers, $perPage, (int)($_GET['page'] ?? 1));

        $this->render('admin_users', [
            'title' => 'Benutzer verwalten',
            'users' => $result['items'],
            'search' => $search,
            'totalUsersUnfiltered' => count($users),
            'perPage' => $perPage,
            'perPageOptions' => Paginator::PER_PAGE_OPTIONS,
            'page' => $result['page'],
            'totalPages' => $result['totalPages'],
            'totalUsers' => $result['total'],
        ]);
    }

    public function create(): void {
        $db = Database::getInstance();
        $assignableGroups = $this->assignableGroups($db);

        $this->render('admin_user_form', [
            'title' => 'Neuen Benutzer anlegen',
            'user' => null,
            'assignableGroups' => $assignableGroups,
            'userGroupIds' => []
        ]);
    }

    public function store(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        $errors = [];
        if (empty($username)) $errors[] = "Benutzername erforderlich.";
        if ($this->isReservedUsername($username)) $errors[] = "Der Benutzername '{$username}' ist aus Sicherheitsgründen reserviert und darf nicht verwendet werden.";
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Gültige E-Mail-Adresse erforderlich.";
        if (strlen($password) < 8) $errors[] = "Passwort muss mindestens 8 Zeichen lang sein.";

        if (!empty($errors)) {
            $this->render('admin_user_form', [
                'title' => 'Neuen Benutzer anlegen',
                'user' => null,
                'errors' => $errors,
                'old' => $_POST
            ]);
            return;
        }

        try {
            $db = Database::getInstance();
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, must_change_password) VALUES (?, ?, ?, 1)");
            $stmt->execute([$username, $email, $passwordHash]);
            $newUserId = (int)$db->lastInsertId();

            $this->syncUserGroups($db, $newUserId, $_POST['groups'] ?? []);

            \App\Service\AuditLogger::log("Benutzer angelegt", "users", "Benutzer: {$username} ({$email})");

            // Send Welcome E-Mail with initial credentials if requested
            if (!empty($_POST['send_welcome_email'])) {
                $mailer = new \App\Service\Mailer();
                $mailer->sendWelcomeEmail($email, $username, $password);
            }

            header("Location: /admin/users?success=created");
            exit;
        } catch (\Exception $e) {
            $this->render('admin_user_form', [
                'title' => 'Neuen Benutzer anlegen',
                'user' => null,
                'errors' => ['E-Mail oder Benutzername bereits vergeben.'],
                'old' => $_POST
            ]);
        }
    }

    public function edit(): void {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            header("Location: /admin/users");
            exit;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT id, username, email FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();

        if (!$user) {
            header("Location: /admin/users");
            exit;
        }

        $assignableGroups = $this->assignableGroups($db);

        $stmt = $db->prepare("SELECT group_id FROM user_groups WHERE user_id = ?");
        $stmt->execute([$id]);
        $userGroupIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

        $this->render('admin_user_form', [
            'title' => 'Benutzer bearbeiten',
            'user' => $user,
            'assignableGroups' => $assignableGroups,
            'userGroupIds' => $userGroupIds
        ]);
    }

    public function update(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $id = $_POST['id'] ?? null;
        if (!$id) {
            header("Location: /admin/users");
            exit;
        }

        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($this->isReservedUsername($username)) {
            $this->render('admin_user_form', [
                'title' => 'Benutzer bearbeiten',
                'user' => ['id' => $id, 'username' => $username, 'email' => $email],
                'errors' => ["Der Benutzername '{$username}' ist aus Sicherheitsgründen reserviert und darf nicht gewählt werden."]
            ]);
            return;
        }

        $db = Database::getInstance();

        if (!empty($password)) {
            if (strlen($password) < 8) {
                $this->render('admin_user_form', [
                    'title' => 'Benutzer bearbeiten',
                    'user' => ['id' => $id, 'username' => $username, 'email' => $email],
                    'errors' => ['Das Passwort muss mindestens 8 Zeichen lang sein.']
                ]);
                return;
            }
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            // session_version erhöhen: Bestehende Sessions des Benutzers werden
            // durch die Admin-Passwortänderung beendet (#113, siehe
            // BaseController::checkAuth()).
            $stmt = $db->prepare("UPDATE users SET username = ?, email = ?, password_hash = ?, session_version = session_version + 1 WHERE id = ?");
            $stmt->execute([$username, $email, $passwordHash, $id]);

            // Ändert der Admin das eigene Passwort, übernimmt seine gerade
            // aktive Session den neuen Stand und bleibt angemeldet.
            if ($id == $_SESSION['user_id']) {
                $stmt = $db->prepare("SELECT session_version FROM users WHERE id = ?");
                $stmt->execute([$id]);
                $_SESSION['session_version'] = (int)$stmt->fetchColumn();
            }
        } else {
            $stmt = $db->prepare("UPDATE users SET username = ?, email = ? WHERE id = ?");
            $stmt->execute([$username, $email, $id]);
        }

        $this->syncUserGroups($db, (int)$id, $_POST['groups'] ?? []);

        \App\Service\AuditLogger::log("Benutzer aktualisiert", "users", "Benutzer ID {$id}: {$username} ({$email})");

        // If updating self, keep the displayed username in sync
        if ($id == $_SESSION['user_id']) {
            $_SESSION['username'] = $username;
        }

        header("Location: /admin/users?success=updated");
        exit;
    }

    public function delete(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $id = $_POST['id'] ?? null;
        
        // Prevent deleting oneself
        if ($id && $id != $_SESSION['user_id']) {
            $db = Database::getInstance();
            $stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $deletedUsername = $stmt->fetchColumn() ?: "ID {$id}";

            $stmt = $db->prepare("UPDATE users SET deleted_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);

            \App\Service\AuditLogger::log("Benutzer in Papierkorb verschoben", "users", "Benutzer: {$deletedUsername} (ID: {$id})");
        }

        header("Location: /admin/users?success=deleted");
        exit;
    }

    public function reset2fa(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $id = $_POST['id'] ?? null;
        if ($id) {
            $db = Database::getInstance();
            $stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $targetUsername = $stmt->fetchColumn() ?: "ID {$id}";

            $stmt = $db->prepare("UPDATE users SET totp_secret = NULL, totp_enabled = 0, backup_codes = NULL, last_totp_timeslice = NULL WHERE id = ?");
            $stmt->execute([$id]);

            \App\Service\AuditLogger::log("2FA zurückgesetzt", "users", "2FA für Benutzer {$targetUsername} (ID: {$id}) durch Admin zurückgesetzt");
        }

        header("Location: /admin/users?success=2fa_reset");
        exit;
    }

    /**
     * Alle Gruppen, die einem Benutzer über `user_groups` zugewiesen werden
     * dürfen (#66) - jede Gruppe außer GroupController::NON_ASSIGNABLE_SLUGS
     * (`public` ist ausschließlich für nicht angemeldete Besucher gedacht).
     * Das schließt ausdrücklich die eingebauten Gruppen `admin` und `editor`
     * mit ein - Mitgliedschaft in JEDER Gruppe ist bewusst zuzuweisen, kein
     * automatischer Standard (siehe BaseController::userGroupIds()). `admin`
     * MUSS hier zuweisbar sein, sonst könnte nie ein Administrator angelegt
     * werden (siehe GroupController::PROTECTED_PERMISSION_SLUGS für den davon
     * unabhängigen Schutz ihrer Berechtigungs-Matrix).
     *
     * @return array<int, array{id:int, name:string, is_builtin:int}>
     */
    private function assignableGroups(\PDO $db): array {
        $nonAssignable = \App\Controllers\GroupController::NON_ASSIGNABLE_SLUGS;
        $placeholders = implode(',', array_fill(0, count($nonAssignable), '?'));
        $stmt = $db->prepare("SELECT id, name, is_builtin FROM `groups` WHERE slug NOT IN ({$placeholders}) ORDER BY is_builtin DESC, name ASC");
        $stmt->execute($nonAssignable);
        return $stmt->fetchAll();
    }

    /**
     * Gleicht die Gruppen eines Benutzers mit der übermittelten Auswahl ab
     * (#66, siehe docs/user-groups-plan.md). Mitgliedschaft ist für JEDE
     * Gruppe ausschließlich explizit über `user_groups` (siehe
     * BaseController::userGroupIds()) - hier werden bewusst nur
     * assignableGroups() akzeptiert, damit eine manipulierte Anfrage niemals
     * `public` über user_groups zuweisen kann (`admin` ist hier absichtlich
     * NICHT ausgeschlossen, siehe assignableGroups()).
     *
     * @param array<int, mixed> $groupIds
     */
    private function syncUserGroups(\PDO $db, int $userId, array $groupIds): void {
        $stmt = $db->prepare("DELETE FROM user_groups WHERE user_id = ?");
        $stmt->execute([$userId]);

        $groupIds = array_map('intval', $groupIds);
        $groupIds = array_filter($groupIds, fn($id) => $id > 0);
        if (empty($groupIds)) {
            return;
        }

        $nonAssignable = \App\Controllers\GroupController::NON_ASSIGNABLE_SLUGS;
        $groupPlaceholders = implode(',', array_fill(0, count($groupIds), '?'));
        $nonAssignablePlaceholders = implode(',', array_fill(0, count($nonAssignable), '?'));
        $stmt = $db->prepare("SELECT id FROM `groups` WHERE slug NOT IN ({$nonAssignablePlaceholders}) AND id IN ({$groupPlaceholders})");
        $stmt->execute(array_merge($nonAssignable, array_values($groupIds)));
        $validIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if (empty($validIds)) {
            return;
        }

        $insertStmt = $db->prepare("INSERT IGNORE INTO user_groups (user_id, group_id) VALUES (?, ?)");
        foreach ($validIds as $groupId) {
            $insertStmt->execute([$userId, (int)$groupId]);
        }
    }
}
