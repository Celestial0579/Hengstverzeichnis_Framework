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
     * Request-Cache für die ID der eingebauten Gast-Gruppe `public` (siehe
     * guestGroupId()). `false` = noch nicht geladen, `null` = nicht vorhanden.
     *
     * @var int|null|false
     */
    private static $guestGroupIdCache = false;

    /**
     * ID der eingebauten Gast-Gruppe (`public`) - der Gruppe, der nicht
     * angemeldete Besucher automatisch angehören (siehe groupIds()). Über ihre
     * group_permissions steuert ein Admin, was Gäste öffentlich sehen dürfen
     * ("wie bei anderen Gruppen auch"). Innerhalb eines Requests gecacht.
     */
    public static function guestGroupId(): ?int {
        if (self::$guestGroupIdCache !== false) {
            return self::$guestGroupIdCache;
        }

        try {
            $db = Database::getInstance();
            $id = $db->query("SELECT id FROM `groups` WHERE slug = 'public' LIMIT 1")->fetchColumn();
            self::$guestGroupIdCache = $id !== false ? (int)$id : null;
        } catch (\Throwable $e) {
            self::$guestGroupIdCache = null;
        }

        return self::$guestGroupIdCache;
    }

    /**
     * @return array<int, int> IDs aller Gruppen, denen der Benutzer angehört
     */
    public static function groupIds(?int $userId): array {
        if (!$userId) {
            // Nicht angemeldete Besucher gehören automatisch der Gast-Gruppe
            // `public` an: ihre Sichtbarkeit im öffentlichen Bereich (siehe
            // PublicController/ApiController) wird über deren group_permissions
            // gesteuert. Fehlt die Gruppe (z. B. sehr alte DB), bleibt es
            // fail-closed bei "keine Gruppen".
            $guestId = self::guestGroupId();
            return $guestId !== null ? [$guestId] : [];
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

    /**
     * Rechteprüfung für einen BELIEBIGEN Benutzer, unabhängig von der aktuellen
     * Session - nötig für Zugriffe ohne Session-Kontext, insbesondere die
     * API-Key-Authentifizierung (siehe App\Security\ApiKey::permits()). Für den
     * Session-Benutzer bleibt BaseController::hasPermission() der Einstieg,
     * da es Gruppen/Admin-Status pro Request cacht.
     *
     * Identische Semantik wie dort: `admin` hat immer alle Rechte, sonst
     * entscheidet group_permissions - fail-closed bei fehlender Zeile oder
     * DB-Fehler.
     */
    public static function hasPermission(?int $userId, string $module, string $action): bool {
        if (self::isAdmin($userId)) {
            return true;
        }

        return self::groupsHavePermission(self::groupIds($userId), $module, $action);
    }

    /**
     * Prüft, ob eine der übergebenen Gruppen die Berechtigung Modul × Aktion
     * besitzt. Gemeinsame Abfrage für hasPermission() (beliebiger Benutzer) und
     * BaseController::hasPermission() (Session-Benutzer mit Request-Cache),
     * damit es die Query - und ihr Fail-closed-Verhalten - nur einmal gibt.
     *
     * @param array<int, int> $groupIds
     */
    public static function groupsHavePermission(array $groupIds, string $module, string $action): bool {
        if (empty($groupIds)) {
            return false;
        }

        try {
            $db = Database::getInstance();
            $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
            $stmt = $db->prepare("SELECT COUNT(*) FROM group_permissions WHERE module = ? AND action = ? AND group_id IN ({$placeholders})");
            $stmt->execute(array_merge([$module, $action], $groupIds));
            return (int)$stmt->fetchColumn() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
