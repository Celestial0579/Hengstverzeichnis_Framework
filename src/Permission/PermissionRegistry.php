<?php
// src/Permission/PermissionRegistry.php

namespace App\Permission;

/**
 * Class PermissionRegistry
 *
 * Statischer Katalog aller im Gruppen-/Berechtigungssystem (#66) verfügbaren
 * Module und Aktionen. Bewusst als einfaches PHP-Array statt einer
 * datenbankgestützten Katalog-Tabelle, konsistent mit der
 * "kein ORM, einfacher Code"-Philosophie des Kerns (siehe
 * docs/architecture.md).
 *
 * Erstumsetzung deckt die drei bestehenden CRUD-Verwaltungsbereiche ab
 * (siehe docs/user-groups-plan.md, Abschnitt 8). Benutzerverwaltung, DSGVO,
 * System-/Mail-Einstellungen, Papierkorb und Plugin-Aktivierung bleiben
 * bewusst weiterhin ausschließlich admin-only (BaseController::requireAdmin()).
 */
final class PermissionRegistry {

    private function __construct() {}

    /**
     * @var array<string, array{label:string, actions:array<string,string>}>
     */
    public const MODULES = [
        'horses' => [
            'label' => 'Pferde',
            'actions' => [
                'create' => 'Erstellen',
                'edit' => 'Bearbeiten',
                'delete' => 'Löschen',
                'publish' => 'Veröffentlichen (im öffentlichen Katalog sichtbar machen)',
            ],
        ],
        'persons' => [
            'label' => 'Personen',
            'actions' => [
                'create' => 'Erstellen',
                'edit' => 'Bearbeiten',
                'delete' => 'Löschen',
            ],
        ],
        'breeding_stations' => [
            'label' => 'Deckstationen',
            'actions' => [
                'create' => 'Erstellen',
                'edit' => 'Bearbeiten',
                'delete' => 'Löschen',
            ],
        ],
    ];

    public static function isValid(string $module, string $action): bool {
        return isset(self::MODULES[$module]['actions'][$action]);
    }
}
