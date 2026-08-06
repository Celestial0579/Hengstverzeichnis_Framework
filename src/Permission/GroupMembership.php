<?php
// src/Permission/GroupMembership.php

namespace App\Permission;

use App\Database;
use PDO;

/**
 * Class GroupMembership
 *
 * Einzige Quelle für "welchen Gruppen gehört Benutzer X an" und "ist Benutzer
 * X Mitglied der Gruppe `admin`" (#66) - genutzt sowohl instanzgebunden über
 * App\Controllers\BaseController (mit Request-Cache) als auch von Stellen
 * ohne Controller-Instanz (z. B. TrashController::getTrashCount() als
 * statische Methode, src/Views/admin_dashboard.php). Bündelt diese Abfrage
 * an einer Stelle, damit es dafür nur EIN Rechtesystem gibt statt mehrerer
 * unabhängiger Implementierungen derselben Logik.
 *
 * Fail-closed wie BaseController::hasPermission(): Ein DB-Fehler oder ein
 * nicht angemeldeter Benutzer führt nie zu impliziten Rechten.
 */
final class GroupMembership {

    private function __construct() {}

    /**
     * @return array<int, int> IDs aller Gruppen, denen der Benutzer angehört
     */
    public static function groupIds(?int $userId): array {
        if (!$userId) {
            return [];
        }

        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("SELECT group_id FROM user_groups WHERE user_id = ?");
            $stmt->execute([$userId]);
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            return array_values(array_unique($ids));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Prüft Mitgliedschaft in der eingebauten Gruppe `admin` - die einzige
     * verbliebene Stelle mit besonderer Bedeutung: Mitglieder haben
     * systemseitig immer alle Rechte (siehe BaseController::hasPermission())
     * und dürfen den kompletten Backend-Admin-Bereich nutzen (siehe
     * BaseController::requireAdmin()).
     */
    public static function isAdmin(?int $userId): bool {
        if (!$userId) {
            return false;
        }

        try {
            $db = Database::getInstance();
            $stmt = $db->prepare(
                "SELECT 1 FROM user_groups ug JOIN `groups` g ON g.id = ug.group_id
                 WHERE ug.user_id = ? AND g.slug = 'admin' LIMIT 1"
            );
            $stmt->execute([$userId]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
