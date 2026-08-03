<?php
// src/Controllers/TrashController.php

namespace App\Controllers;

use App\Database;

class TrashController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
    }

    public static function getTrashCount(): int {
        try {
            $db = Database::getInstance();
            $isAdmin = ($_SESSION['role'] ?? '') === 'admin';

            $horses = (int)$db->query("SELECT COUNT(*) FROM horses WHERE deleted_at IS NOT NULL")->fetchColumn();
            $persons = (int)$db->query("SELECT COUNT(*) FROM persons WHERE deleted_at IS NOT NULL")->fetchColumn();
            $stations = (int)$db->query("SELECT COUNT(*) FROM breeding_stations WHERE deleted_at IS NOT NULL")->fetchColumn();
            $users = $isAdmin ? (int)$db->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NOT NULL")->fetchColumn() : 0;

            return $horses + $persons + $stations + $users;
        } catch (\Exception $e) {
            return 0;
        }
    }

    public function index(): void {
        $db = Database::getInstance();
        $isAdmin = ($_SESSION['role'] ?? '') === 'admin';

        $deletedHorses = $db->query("SELECT * FROM horses WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC")->fetchAll();
        $deletedPersons = $db->query("SELECT * FROM persons WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC")->fetchAll();
        $deletedStations = $db->query("SELECT * FROM breeding_stations WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC")->fetchAll();
        $deletedUsers = $isAdmin ? $db->query("SELECT * FROM users WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC")->fetchAll() : [];

        $totalCount = count($deletedHorses) + count($deletedPersons) + count($deletedStations) + count($deletedUsers);

        $this->render('admin_trash', [
            'title' => 'Papierkorb',
            'deletedHorses' => $deletedHorses,
            'deletedPersons' => $deletedPersons,
            'deletedStations' => $deletedStations,
            'deletedUsers' => $deletedUsers,
            'totalCount' => $totalCount,
            'isAdmin' => $isAdmin
        ]);
    }

    public function restore(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $isAdmin = ($_SESSION['role'] ?? '') === 'admin';
        $type = $_POST['type'] ?? '';
        $id = (int)($_POST['id'] ?? 0);

        // Editors cannot restore user accounts
        if ($type === 'user' && !$isAdmin) {
            die("Zugriff verweigert: Nur Administratoren können Benutzerkonten wiederherstellen.");
        }

        $allowedTables = [
            'horse' => 'horses',
            'person' => 'persons',
            'breeding_station' => 'breeding_stations',
            'user' => 'users'
        ];

        if (isset($allowedTables[$type]) && $id > 0) {
            $table = $allowedTables[$type];
            $db = Database::getInstance();
            $stmt = $db->prepare("UPDATE {$table} SET deleted_at = NULL WHERE id = ?");
            $stmt->execute([$id]);

            \App\Service\AuditLogger::log("Element aus Papierkorb wiederhergestellt", "trash", "Typ: {$type}, ID: {$id}");
        }

        header("Location: /admin/trash?success=restored");
        exit;
    }

    public function permanentDelete(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $isAdmin = ($_SESSION['role'] ?? '') === 'admin';
        $type = $_POST['type'] ?? '';
        $id = (int)($_POST['id'] ?? 0);

        // Editors cannot purge users
        if ($type === 'user' && !$isAdmin) {
            die("Zugriff verweigert.");
        }

        $allowedTables = [
            'horse' => 'horses',
            'person' => 'persons',
            'breeding_station' => 'breeding_stations',
            'user' => 'users'
        ];

        if (isset($allowedTables[$type]) && $id > 0) {
            $table = $allowedTables[$type];
            $db = Database::getInstance();

            // Check if item is older than 30 days
            $stmt = $db->prepare("SELECT deleted_at FROM {$table} WHERE id = ?");
            $stmt->execute([$id]);
            $deletedAt = $stmt->fetchColumn();

            $isOlderThan30Days = $deletedAt && (strtotime($deletedAt) <= strtotime('-30 days'));

            // Rule: Permanent deletion allowed if user is Admin OR if item is older than 30 days
            if ($isAdmin || $isOlderThan30Days) {
                $stmt = $db->prepare("DELETE FROM {$table} WHERE id = ?");
                $stmt->execute([$id]);

                \App\Service\AuditLogger::log("Element endgültig gelöscht", "trash", "Typ: {$type}, ID: {$id}");

                header("Location: /admin/trash?success=purged");
            } else {
                header("Location: /admin/trash?error=retention_period_30_days");
            }
            exit;
        }

        header("Location: /admin/trash");
        exit;
    }

    public function emptyTrash(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $isAdmin = ($_SESSION['role'] ?? '') === 'admin';
        $db = Database::getInstance();

        if ($isAdmin) {
            // Admins can clear all trash immediately
            $db->exec("DELETE FROM horses WHERE deleted_at IS NOT NULL");
            $db->exec("DELETE FROM persons WHERE deleted_at IS NOT NULL");
            $db->exec("DELETE FROM breeding_stations WHERE deleted_at IS NOT NULL");
            $db->exec("DELETE FROM users WHERE deleted_at IS NOT NULL");

            \App\Service\AuditLogger::log("Papierkorb geleert (Admin)", "trash", "Alle gelöschten Elemente endgültig bereinigt");
        } else {
            // Editors can only purge items older than 30 days
            $db->exec("DELETE FROM horses WHERE deleted_at IS NOT NULL AND deleted_at <= DATE_SUB(NOW(), INTERVAL 30 DAY)");
            $db->exec("DELETE FROM persons WHERE deleted_at IS NOT NULL AND deleted_at <= DATE_SUB(NOW(), INTERVAL 30 DAY)");
            $db->exec("DELETE FROM breeding_stations WHERE deleted_at IS NOT NULL AND deleted_at <= DATE_SUB(NOW(), INTERVAL 30 DAY)");

            \App\Service\AuditLogger::log("Papierkorb bereinigt (>30 Tage)", "trash", "Ältere Elemente durch Editor bereinigt");
        }

        header("Location: /admin/trash?success=emptied");
        exit;
    }
}
