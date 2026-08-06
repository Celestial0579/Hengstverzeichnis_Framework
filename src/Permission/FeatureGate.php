<?php
// src/Permission/FeatureGate.php

namespace App\Permission;

use App\Database;

/**
 * Class FeatureGate
 *
 * Durchsetzung der admin-konfigurierbaren Sichtbarkeit von Zusatzfunktionen
 * (#57, siehe FeatureRegistry): Plugins (bzw. zukünftige Kern-Funktionen)
 * rufen isVisible() in ihrem Hook/ihrer Route auf, bevor sie die Funktion
 * rendern oder ausführen - analog zu BaseController::hasPermission().
 *
 * Sicherheits-Leitplanken (konsistent mit dem übrigen Gruppensystem, #66):
 * - Unbekannte (nicht registrierte) Funktionen sind nie sichtbar (fail-closed).
 * - Bei Sichtbarkeit "members": ohne Anmeldung kein Zugriff; Admin-Mitglieder
 *   haben immer Zugriff; sonst entscheidet die Leseberechtigung
 *   `feature_<key>`/`read` der Gruppen des Benutzers.
 * - DB-Fehler bei der Berechtigungsprüfung führen zu "nicht sichtbar",
 *   nie zu "sichtbar".
 */
final class FeatureGate {

    private function __construct() {}

    /**
     * @param string $key Feature-Schlüssel (siehe FeatureRegistry::register())
     * @param array<string, string>|null $settings Bereits geladene Einstellungen
     *        (z. B. $this->settings eines Controllers); null lädt den einzelnen
     *        Sichtbarkeitswert direkt aus der Datenbank.
     */
    public static function isVisible(string $key, ?array $settings = null): bool {
        if (!FeatureRegistry::isRegistered($key)) {
            return false;
        }

        $settingKey = FeatureRegistry::settingKey($key);
        if ($settings !== null) {
            $visibility = $settings[$settingKey] ?? FeatureRegistry::defaultVisibility($key);
        } else {
            $visibility = self::loadVisibilitySetting($settingKey) ?? FeatureRegistry::defaultVisibility($key);
        }

        if ($visibility === FeatureRegistry::VISIBILITY_PUBLIC) {
            return true;
        }

        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        if (!$userId) {
            return false;
        }
        if (GroupMembership::isAdmin($userId)) {
            return true;
        }

        $groupIds = GroupMembership::groupIds($userId);
        if (empty($groupIds)) {
            return false;
        }

        try {
            $db = Database::getInstance();
            $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
            $stmt = $db->prepare("SELECT COUNT(*) FROM group_permissions WHERE module = ? AND action = 'read' AND group_id IN ({$placeholders})");
            $stmt->execute(array_merge([FeatureRegistry::permissionModule($key)], $groupIds));
            return (int)$stmt->fetchColumn() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function loadVisibilitySetting(string $settingKey): ?string {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
            $stmt->execute([$settingKey]);
            $value = $stmt->fetchColumn();
            return $value === false ? null : (string)$value;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
