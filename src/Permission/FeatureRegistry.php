<?php
// src/Permission/FeatureRegistry.php

namespace App\Permission;

/**
 * Class FeatureRegistry
 *
 * Katalog aller "Zusatzfunktionen" mit admin-konfigurierbarer Sichtbarkeit
 * (#57): Eine Zusatzfunktion ist eine für Besucher bzw. Mitglieder gedachte
 * Funktion (typischerweise aus einem Plugin, z. B. der Verpaarungsrechner aus
 * Hengstverzeichnis_Addons), deren Sichtbarkeit der Admin pro Installation
 * festlegt:
 *
 * - "Öffentlich": jeder Besucher sieht die Funktion (wie der übrige Katalog).
 * - "Nur für Gruppen mit Leseberechtigung": ausschließlich angemeldete
 *   Benutzer, deren Gruppe die Leseberechtigung `feature_<key>`/`read`
 *   besitzt (Admin-Mitglieder immer, analog zum generellen Admin-Bypass).
 *
 * Die Registrierung legt automatisch das zugehörige Berechtigungs-Modul in
 * der PermissionRegistry an, damit die Leseberechtigung in der bestehenden
 * Gruppen-Berechtigungsmatrix (/admin/groups) zuweisbar ist. Die gewählte
 * Sichtbarkeit selbst liegt in der `settings`-Tabelle
 * (`feature_visibility__<key>`, siehe AdminController::updateSystemSettings()).
 *
 * Analog zur PermissionRegistry gilt "wer zuerst registriert, gewinnt", und
 * der Katalog wird pro Request beim Plugin-Bootstrap neu aufgebaut - siehe
 * App\Plugin\PluginManager::loadPlugin().
 *
 * Die Durchsetzung übernimmt App\Permission\FeatureGate::isVisible() - im
 * Plugin-Hook bzw. der Plugin-Route, genau wie Kern-Controller
 * hasPermission() aufrufen.
 */
final class FeatureRegistry {

    public const VISIBILITY_PUBLIC = 'public';
    public const VISIBILITY_MEMBERS = 'members';

    /** @var array<string, array{label:string, default:string}> */
    private static array $features = [];

    private function __construct() {}

    /**
     * Registriert eine Zusatzfunktion. Ungültige Schlüssel und bereits
     * registrierte Funktionen werden ignoriert ("wer zuerst kommt, gewinnt").
     *
     * @param string $key Eindeutiger Schlüssel, z. B. 'verpaarungsrechner'
     *                    (a-z, 0-9, '-', '_')
     * @param string $label Anzeigetext in der Admin-UI
     * @param string $defaultVisibility Standard-Sichtbarkeit, solange der Admin
     *                                  nichts gewählt hat - Default 'members'
     *                                  (fail-closed: neue Premium-Funktionen
     *                                  erscheinen nicht ungefragt öffentlich)
     */
    public static function register(string $key, string $label, string $defaultVisibility = self::VISIBILITY_MEMBERS): void {
        $key = trim($key);
        if ($key === '' || preg_match('/^[a-z0-9][a-z0-9_-]*$/', $key) !== 1 || isset(self::$features[$key])) {
            return;
        }

        $default = $defaultVisibility === self::VISIBILITY_PUBLIC ? self::VISIBILITY_PUBLIC : self::VISIBILITY_MEMBERS;
        self::$features[$key] = ['label' => $label, 'default' => $default];

        PermissionRegistry::registerAction(
            self::permissionModule($key),
            'read',
            'Sehen/Nutzen (Leseberechtigung)',
            'Zusatzfunktion: ' . $label
        );
    }

    /**
     * @return array<string, array{label:string, default:string}>
     */
    public static function all(): array {
        return self::$features;
    }

    public static function isRegistered(string $key): bool {
        return isset(self::$features[$key]);
    }

    public static function defaultVisibility(string $key): string {
        return self::$features[$key]['default'] ?? self::VISIBILITY_MEMBERS;
    }

    /**
     * Modul-Schlüssel der zugehörigen Leseberechtigung in der
     * Gruppen-Berechtigungsmatrix.
     */
    public static function permissionModule(string $key): string {
        return 'feature_' . $key;
    }

    /**
     * Schlüssel der Sichtbarkeits-Einstellung in der `settings`-Tabelle.
     */
    public static function settingKey(string $key): string {
        return 'feature_visibility__' . $key;
    }
}
