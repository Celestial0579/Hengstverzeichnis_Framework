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
            // Nur aktive Konten (Audit N14): Ein gelöschtes oder deaktiviertes
            // Konto hat keine Rechte - auch nicht für Aufrufer, die eine rohe
            // ID aus einer alten Sitzung oder einem API-Schlüssel übergeben.
            $stmt = $db->prepare(
                "SELECT ug.group_id FROM user_groups ug
                 JOIN users u ON u.id = ug.user_id AND u.deleted_at IS NULL AND u.deactivated_at IS NULL
                 WHERE ug.user_id = ?"
            );
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
                 -- Nur aktive Konten (Audit N14), siehe groupIds().
                 JOIN users u ON u.id = ug.user_id AND u.deleted_at IS NULL AND u.deactivated_at IS NULL
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
     * Hat die Gast-Gruppe `public` dieses Recht? (Audit N61)
     *
     * Nur Aktionen aus PermissionRegistry::GUEST_ALLOWED_ACTIONS zählen - eine
     * Schreibzeile, die vor #218 an die Gast-Gruppe geriet, öffnet hier
     * nichts. Fehlt die Gruppe oder scheitert die Abfrage: false.
     */
    public static function guestHasPermission(string $module, string $action): bool {
        if (!in_array($action, PermissionRegistry::GUEST_ALLOWED_ACTIONS, true)) {
            return false;
        }
        $guestId = self::guestGroupId();
        if ($guestId === null) {
            return false;
        }
        return self::groupsHavePermission([$guestId], $module, $action);
    }

    /**
     * Öffentliche Sichtprüfung für einen BELIEBIGEN Benutzer (Audit N61): das
     * eigene Recht ODER das der Gast-Gruppe.
     *
     * WARUM. Die Gast-Gruppe steuert, was nicht angemeldete Besucher im
     * öffentlichen Teil sehen. Angemeldete Konten gehören ihr nicht an
     * (groupIds()); prüfte eine öffentliche Seite hasPermission(), sah ein
     * angemeldetes Mitglied ohne eigenes `view` WENIGER als ein Gast - einen
     * leeren Katalog und 404 auf Pferde- und Kontaktseiten. Die Rechte der
     * Gast-Gruppe gelten deshalb bei öffentlichen Prüfungen als Untergrenze.
     *
     * Die Mitgliedschaft bleibt explizit: hasPermission(), groupIds() und
     * API-Schlüssel sind unverändert - die Untergrenze gilt nur, wo ein
     * Aufrufer ausdrücklich die ÖFFENTLICHE Sicht prüft. Sie öffnet nichts
     * Unveröffentlichtes; jede öffentliche Abfrage filtert selbst auf
     * is_published. Für Addon-Hooks ohne Controller-Instanz; in Controllern
     * BaseController::hasPublicPermission().
     */
    public static function hasPublicPermission(?int $userId, string $module, string $action): bool {
        if (self::hasPermission($userId, $module, $action)) {
            return true;
        }
        return $userId !== null && $userId > 0 && self::guestHasPermission($module, $action);
    }

    /**
     * Interne Einsicht in ein Modul (Audit M10/M13): Administrator oder eine
     * der Aktionen aus PermissionRegistry::INTERNAL_ACCESS_ACTIONS. Gäste nie.
     * Für Stellen ohne Controller-Instanz; in Controllern
     * BaseController::hasInternalAccess().
     */
    public static function hasInternalAccess(?int $userId, string $module): bool {
        if (!$userId) {
            return false;
        }
        if (self::isAdmin($userId)) {
            return true;
        }
        return self::groupsHaveAnyPermission(self::groupIds($userId), $module, PermissionRegistry::INTERNAL_ACCESS_ACTIONS);
    }

    /**
     * Hat eine der Gruppen IRGENDEINE der Aktionen am Modul? Eine Abfrage,
     * fail-closed wie groupsHavePermission().
     *
     * @param array<int, int> $groupIds
     * @param array<int, string> $actions
     */
    public static function groupsHaveAnyPermission(array $groupIds, string $module, array $actions): bool {
        if (empty($groupIds) || empty($actions)) {
            return false;
        }

        try {
            $db = Database::getInstance();
            $gruppen = implode(',', array_fill(0, count($groupIds), '?'));
            $aktionen = implode(',', array_fill(0, count($actions), '?'));
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM group_permissions
                 WHERE module = ? AND action IN ({$aktionen}) AND group_id IN ({$gruppen})"
            );
            $stmt->execute(array_merge([$module], array_values($actions), array_values($groupIds)));
            return (int)$stmt->fetchColumn() > 0;
        } catch (\Throwable $e) {
            return false;
        }
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
