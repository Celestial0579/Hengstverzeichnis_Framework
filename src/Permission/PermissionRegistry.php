<?php
// src/Permission/PermissionRegistry.php

namespace App\Permission;

/**
 * Class PermissionRegistry
 *
 * Katalog aller im Gruppen-/Berechtigungssystem (#66) verfügbaren Module und
 * Aktionen. Besteht aus einem festen Kern-Anteil (CORE_MODULES) sowie zur
 * Laufzeit von aktivierten Plugins registrierten Ergänzungen (#56) - siehe
 * App\Plugin\PluginManager::loadPlugin() und docs/plugin-development.md,
 * Abschnitt "Berechtigungen".
 *
 * Ein Plugin kann darüber:
 * - eine neue Aktion an einem BESTEHENDEN Modul ergänzen (Kern-Modul oder
 *   Modul eines anderen Plugins) - z. B. eine "Exportieren"-Berechtigung
 *   für das Kern-Modul `horses`, ohne dass der Kern dafür angepasst werden
 *   muss.
 * - ein komplett neues, eigenes Modul mit eigenen Aktionen anlegen.
 * Beides über dieselbe Methode registerAction(), siehe dort.
 *
 * Sicherheits-Leitplanke ("wer zuerst registriert, gewinnt"): Ein Plugin kann
 * weder ein Kern-Modul/-Aktion noch die Registrierung eines bereits zuvor
 * geladenen Plugins überschreiben - registerAction() ignoriert eine erneute
 * Registrierung für ein bereits existierendes Modul×Aktion-Paar. Ein Plugin
 * kann also z. B. nicht die Bedeutung von `horses`/`delete` umdefinieren,
 * sondern höchstens eine neue, bisher unbenutzte Kombination ergänzen.
 *
 * Bewusst als einfaches PHP-Array statt einer datenbankgestützten
 * Katalog-Tabelle, konsistent mit der "kein ORM, einfacher Code"-Philosophie
 * des Kerns (siehe docs/architecture.md). Da PluginManager::boot() bei jedem
 * Request vor jeglichem Routing läuft (siehe public/index.php), ist der
 * Katalog innerhalb eines Requests immer vollständig (Kern + alle aktivierten
 * Plugins), bevor er irgendwo abgefragt wird - kein Caching über Requests
 * hinweg nötig oder gewollt.
 */
final class PermissionRegistry {

    private function __construct() {}

    /**
     * Fester Kern-Anteil (Erstumsetzung #66, siehe docs/user-groups-plan.md,
     * Abschnitt 8) - NICHT direkt verwenden, siehe modules().
     *
     * @var array<string, array{label:string, actions:array<string,string>}>
     */
    private const CORE_MODULES = [
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

    /**
     * Kern-Module + zur Laufzeit von Plugins registrierte Ergänzungen.
     *
     * @var array<string, array{label:string, actions:array<string,string>}>|null
     */
    private static ?array $modules = null;

    /**
     * Vollständiger, aktueller Katalog (Kern + Plugin-Ergänzungen).
     *
     * @return array<string, array{label:string, actions:array<string,string>}>
     */
    public static function modules(): array {
        self::ensureInitialized();
        return self::$modules;
    }

    /**
     * Registriert eine Aktion - entweder als neue Aktion an einem bereits
     * existierenden Modul (Kern oder ein anderes Plugin, z. B.
     * registerAction('horses', 'export', 'Exportieren')), oder als erste
     * Aktion eines komplett neuen, vom Plugin selbst definierten Moduls
     * (dann zusätzlich $moduleLabel angeben).
     *
     * Sicherheits-Leitplanke: Existiert das Modul×Aktion-Paar bereits (Kern
     * oder zuvor registriertes Plugin), wird die neue Registrierung
     * stillschweigend ignoriert ("wer zuerst kommt, gewinnt") - ein Plugin
     * kann so nie die Bedeutung einer bestehenden Berechtigung umdefinieren.
     *
     * @param string $module Modul-Schlüssel, z. B. 'horses' oder ein neuer, eigener Schlüssel
     * @param string $action Aktions-Schlüssel, z. B. 'export'
     * @param string $actionLabel Anzeigetext der Aktion in der Admin-UI
     * @param string|null $moduleLabel Anzeigetext des Moduls, nur relevant falls $module neu ist
     */
    public static function registerAction(string $module, string $action, string $actionLabel, ?string $moduleLabel = null): void {
        self::ensureInitialized();

        if (!isset(self::$modules[$module])) {
            self::$modules[$module] = ['label' => $moduleLabel ?? $module, 'actions' => []];
        }

        if (!isset(self::$modules[$module]['actions'][$action])) {
            self::$modules[$module]['actions'][$action] = $actionLabel;
        }
    }

    private static function ensureInitialized(): void {
        if (self::$modules === null) {
            self::$modules = self::CORE_MODULES;
        }
    }

    public static function isValid(string $module, string $action): bool {
        return isset(self::modules()[$module]['actions'][$action]);
    }

    /**
     * Alle Modul/Aktion-Kombinationen aus dem Katalog, z. B. für "Berechtigungen von
     * Admin kopieren" (Admin hat keine eigenen group_permissions-Zeilen, siehe
     * BaseController::hasPermission() - dieser vollständige Katalog steht stellvertretend
     * für "alle Rechte").
     *
     * @return array<int, array{module:string, action:string}>
     */
    public static function allPairs(): array {
        $pairs = [];
        foreach (self::modules() as $module => $def) {
            foreach (array_keys($def['actions']) as $action) {
                $pairs[] = ['module' => $module, 'action' => $action];
            }
        }
        return $pairs;
    }

    /**
     * Gesamtzahl aller Modul/Aktion-Kombinationen im Katalog - für die kompakte
     * "X von Y Rechten"-Zusammenfassung in der Gruppen-Übersicht.
     */
    public static function countAll(): int {
        return count(self::allPairs());
    }
}
