<?php
// src/Controllers/UserController.php

namespace App\Controllers;

use App\Database;

class UserController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requireAdmin();
    }

    public function index(): void {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT id, username, email, role, created_at, totp_enabled FROM users WHERE deleted_at IS NULL ORDER BY username ASC");
        $users = $stmt->fetchAll();

        $this->render('admin_users', [
            'title' => 'Benutzer verwalten',
            'users' => $users
        ]);
    }

    public function create(): void {
        $db = Database::getInstance();
        $customGroups = $db->query("SELECT id, name FROM `groups` WHERE is_builtin = 0 ORDER BY name ASC")->fetchAll();

        $this->render('admin_user_form', [
            'title' => 'Neuen Benutzer anlegen',
            'user' => null,
            'customGroups' => $customGroups,
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
        $role = $_POST['role'] ?? 'editor';

        $errors = [];
        if (empty($username)) $errors[] = "Benutzername erforderlich.";
        if ($this->isReservedUsername($username)) $errors[] = "Der Benutzername '{$username}' ist aus Sicherheitsgründen reserviert und darf nicht verwendet werden.";
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Gültige E-Mail-Adresse erforderlich.";
        if (strlen($password) < 8) $errors[] = "Passwort muss mindestens 8 Zeichen lang sein.";
        if (!in_array($role, ['admin', 'editor'])) $role = 'editor';

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
            $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, role, must_change_password) VALUES (?, ?, ?, ?, 1)");
            $stmt->execute([$username, $email, $passwordHash, $role]);
            $newUserId = (int)$db->lastInsertId();

            $this->syncUserGroups($db, $newUserId, $_POST['groups'] ?? []);

            \App\Service\AuditLogger::log("Benutzer angelegt", "users", "Benutzer: {$username} ({$email}), Rolle: {$role}");

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
        $stmt = $db->prepare("SELECT id, username, email, role FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();

        if (!$user) {
            header("Location: /admin/users");
            exit;
        }

        $customGroups = $db->query("SELECT id, name FROM `groups` WHERE is_builtin = 0 ORDER BY name ASC")->fetchAll();

        $stmt = $db->prepare("SELECT group_id FROM user_groups WHERE user_id = ?");
        $stmt->execute([$id]);
        $userGroupIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

        $this->render('admin_user_form', [
            'title' => 'Benutzer bearbeiten',
            'user' => $user,
            'customGroups' => $customGroups,
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
        $role = $_POST['role'] ?? 'editor';

        if ($this->isReservedUsername($username)) {
            $this->render('admin_user_form', [
                'title' => 'Benutzer bearbeiten',
                'user' => ['id' => $id, 'username' => $username, 'email' => $email, 'role' => $role],
                'errors' => ["Der Benutzername '{$username}' ist aus Sicherheitsgründen reserviert und darf nicht gewählt werden."]
            ]);
            return;
        }

        if (!in_array($role, ['admin', 'editor'])) $role = 'editor';

        $db = Database::getInstance();

        if (!empty($password)) {
            if (strlen($password) < 8) {
                $this->render('admin_user_form', [
                    'title' => 'Benutzer bearbeiten',
                    'user' => ['id' => $id, 'username' => $username, 'email' => $email, 'role' => $role],
                    'errors' => ['Das Passwort muss mindestens 8 Zeichen lang sein.']
                ]);
                return;
            }
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET username = ?, email = ?, password_hash = ?, role = ? WHERE id = ?");
            $stmt->execute([$username, $email, $passwordHash, $role, $id]);
        } else {
            $stmt = $db->prepare("UPDATE users SET username = ?, email = ?, role = ? WHERE id = ?");
            $stmt->execute([$username, $email, $role, $id]);
        }

        $this->syncUserGroups($db, (int)$id, $_POST['groups'] ?? []);

        \App\Service\AuditLogger::log("Benutzer aktualisiert", "users", "Benutzer ID {$id}: {$username} ({$email}), Rolle: {$role}");

        // If updating self role, update session
        if ($id == $_SESSION['user_id']) {
            $_SESSION['role'] = $role;
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

            $stmt = $db->prepare("UPDATE users SET totp_secret = NULL, totp_enabled = 0, backup_codes = NULL WHERE id = ?");
            $stmt->execute([$id]);

            \App\Service\AuditLogger::log("2FA zurückgesetzt", "users", "2FA für Benutzer {$targetUsername} (ID: {$id}) durch Admin zurückgesetzt");
        }

        header("Location: /admin/users?success=2fa_reset");
        exit;
    }

    /**
     * Gleicht die eigenen (nicht eingebauten) Gruppen eines Benutzers mit der
     * übermittelten Auswahl ab (#66, siehe docs/user-groups-plan.md). Die
     * Mitgliedschaft in den eingebauten Gruppen admin/editor/public ergibt sich
     * ausschließlich aus users.role (siehe BaseController::userGroupIds()) - hier
     * werden bewusst nur is_builtin = 0 Gruppen akzeptiert, damit eine manipulierte
     * Anfrage keine eingebaute Gruppe über user_groups zuweisen kann.
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

        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        $stmt = $db->prepare("SELECT id FROM `groups` WHERE is_builtin = 0 AND id IN ({$placeholders})");
        $stmt->execute(array_values($groupIds));
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
