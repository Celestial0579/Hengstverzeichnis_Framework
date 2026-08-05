<?php
// src/Plugin/PluginManager.php

namespace App\Plugin;

use App\Database;
use App\Permission\PermissionRegistry;
use App\Service\AuditLogger;
use PDO;

/**
 * Class PluginManager
 *
 * Kern des Plugin-/Erweiterungssystems (#56). Scannt das (bewusst nicht
 * versionierte, siehe .gitignore) Verzeichnis `plugins/` nach Manifesten,
 * prüft Kompatibilität und lädt ausschließlich Plugins, die ein
 * Administrator zuvor über /admin/plugins aktiviert hat.
 *
 * Sicherheits-Leitplanken (siehe auch docs/plugin-development.md):
 * - Plugins werden NIE automatisch aktiviert; das Aktivieren ist eine
 *   bewusste Admin-Entscheidung (Tabelle `plugins`, siehe Database.php).
 * - Zusätzliche Routen eines Plugins werden zwingend unter `/plugin/<slug>/...`
 *   registriert - der Präfix wird vom PluginManager selbst vorangestellt,
 *   ein Plugin kann daher nie eine Kern-Route überschreiben oder sich als
 *   Kernfunktionalität ausgeben.
 * - CSRF-Prüfung und `checkAuth()`/`requireAdmin()` bleiben zentral in
 *   Router/BaseController verankert; ein Plugin-Hook läuft immer erst NACH
 *   diesen Prüfungen (siehe Aufrufstellen in den Controllern), kann sie also
 *   nicht umgehen.
 * - Ein fehlerhaftes Plugin kann die eigene Registrierung (Laden der Datei,
 *   `register()`/`routes()`/`permissions()`) zum Scheitern bringen, ohne den
 *   Bootstrap der gesamten Anwendung zu blockieren (try/catch, Protokollierung
 *   im Audit-Log). Für die laufende Anfrage-Bearbeitung siehe HookManager.
 * - Über die optionale `permissions()`-Methode kann ein Plugin eigene
 *   Aktionen im Gruppen-/Berechtigungssystem (#66) registrieren - entweder
 *   neue Aktionen an bestehenden Kern-Modulen (z. B. eine "Exportieren"-
 *   Berechtigung für `horses`) oder komplett eigene Module. Siehe
 *   App\Permission\PermissionRegistry::registerAction() für die dortige
 *   "wer zuerst registriert, gewinnt"-Leitplanke gegen Überschreiben
 *   bestehender Berechtigungen.
 */
final class PluginManager {

    private static ?PluginManager $instance = null;

    private HookManager $hooks;

    /** @var array<string, array{slug:string, dir:string, manifest:array, error:?string, compatible:bool}> */
    private array $discovered = [];

    /** @var array<int, string> */
    private array $enabledSlugs = [];

    /** @var array<int, array{method:string, path:string, callback:mixed, slug:string}> */
    private array $pluginRoutes = [];

    private bool $booted = false;

    private function __construct() {
        $this->hooks = new HookManager();
    }

    private function __clone() {}

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getHooks(): HookManager {
        return $this->hooks;
    }

    /**
     * Führt den vollständigen Scan- und Ladevorgang aus. Wird einmal pro
     * Request im Bootstrap (public/index.php) aufgerufen, vor der Routen-
     * Registrierung, damit Hooks/Plugin-Routen für den restlichen Request
     * zur Verfügung stehen.
     */
    public function boot(): void {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        $this->discoverPlugins();
        $this->loadEnabledStates();
        $this->loadEnabledPlugins();
    }

    private function pluginsDir(): string {
        return __DIR__ . '/../../plugins';
    }

    /**
     * Liest alle plugins/<slug>/plugin.json Manifeste ein und validiert sie,
     * unabhängig vom Aktivierungsstatus - Grundlage für die Admin-Übersicht.
     */
    private function discoverPlugins(): void {
        $dir = $this->pluginsDir();
        if (!is_dir($dir)) {
            return;
        }

        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.gitkeep') {
                continue;
            }

            $pluginDir = $dir . '/' . $entry;
            if (!is_dir($pluginDir)) {
                continue;
            }

            $manifestFile = $pluginDir . '/plugin.json';
            if (!file_exists($manifestFile)) {
                continue;
            }

            $raw = file_get_contents($manifestFile);
            $manifest = $raw !== false ? json_decode($raw, true) : null;
            $error = $this->validateManifest($manifest, $entry);

            $this->discovered[$entry] = [
                'slug' => $entry,
                'dir' => $pluginDir,
                'manifest' => is_array($manifest) ? $manifest : [],
                'error' => $error,
                'compatible' => $error === null && $this->isCompatible((string)($manifest['core_compatibility'] ?? '')),
            ];
        }

        ksort($this->discovered);
    }

    /**
     * @return string|null Fehlermeldung, oder null wenn das Manifest gültig ist.
     */
    private function validateManifest(mixed $manifest, string $dirSlug): ?string {
        if (!is_array($manifest)) {
            return 'plugin.json ist kein gültiges JSON-Objekt.';
        }

        foreach (['slug', 'name', 'version', 'core_compatibility'] as $field) {
            if (empty($manifest[$field]) || !is_string($manifest[$field])) {
                return "Pflichtfeld '{$field}' fehlt oder ist ungültig im Manifest.";
            }
        }

        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $dirSlug)) {
            return "Ungültiger Plugin-Verzeichnisname '{$dirSlug}' (erlaubt: Kleinbuchstaben, Ziffern, Bindestrich).";
        }

        // Manifest-Slug muss dem Verzeichnisnamen entsprechen - verhindert, dass sich
        // ein Plugin unter einem anderen als seinem tatsächlichen Verzeichnisnamen
        // ausgibt (relevant u. a. für die Zuordnung in der `plugins`-Aktivierungstabelle).
        if ($manifest['slug'] !== $dirSlug) {
            return "Manifest-Slug '{$manifest['slug']}' stimmt nicht mit dem Verzeichnisnamen '{$dirSlug}' überein.";
        }

        return null;
    }

    /**
     * Prüft eine Kompatibilitäts-Angabe wie ">=0.1.0-beta.1" gegen CORE_VERSION.
     */
    private function isCompatible(string $constraint): bool {
        $constraint = trim($constraint);
        if ($constraint === '' || !defined('CORE_VERSION')) {
            return false;
        }

        if (!preg_match('/^(>=|<=|>|<|=)?\s*([0-9][0-9A-Za-z.\-]*)$/', $constraint, $m)) {
            return false;
        }

        $operator = $m[1] !== '' ? $m[1] : '=';
        $required = $m[2];

        return version_compare(CORE_VERSION, $required, $operator);
    }

    private function loadEnabledStates(): void {
        try {
            $db = Database::getInstance();
            $stmt = $db->query("SELECT slug FROM plugins WHERE enabled = 1");
            $this->enabledSlugs = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) {
            // Fail-closed: Ohne DB-Zugriff bleiben alle Plugins deaktiviert (Ausfallsicherheit
            // für den Kern hat Vorrang vor Plugin-Funktionalität).
            $this->enabledSlugs = [];
        }
    }

    /**
     * Lädt und registriert nur Plugins, die aktiviert UND kompatibel UND
     * fehlerfrei validiert sind. Jedes Plugin wird einzeln in try/catch
     * isoliert geladen, damit ein defektes Plugin nicht den gesamten
     * Bootstrap-Vorgang verhindert.
     */
    private function loadEnabledPlugins(): void {
        foreach ($this->discovered as $slug => $info) {
            if ($info['error'] !== null || !$info['compatible']) {
                continue;
            }
            if (!in_array($slug, $this->enabledSlugs, true)) {
                continue;
            }

            try {
                $this->loadPlugin($slug, $info);
            } catch (\Throwable $e) {
                AuditLogger::log("Plugin konnte nicht geladen werden: {$slug}", "plugin", $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            }
        }
    }

    /**
     * @param array{slug:string, dir:string, manifest:array, error:?string, compatible:bool} $info
     */
    private function loadPlugin(string $slug, array $info): void {
        $entryFile = rtrim($info['dir'], '/') . '/' . ($info['manifest']['entry'] ?? 'Plugin.php');
        if (!file_exists($entryFile)) {
            AuditLogger::log("Plugin-Einstiegspunkt fehlt: {$slug}", "plugin", "Erwartet: {$entryFile}");
            return;
        }

        require_once $entryFile;

        $className = $this->resolvePluginClassName($slug);
        if (!class_exists($className)) {
            AuditLogger::log("Plugin-Klasse nicht gefunden: {$slug}", "plugin", "Erwartet: {$className}");
            return;
        }

        $instance = new $className();

        if (method_exists($instance, 'register')) {
            $instance->register($this->hooks);
        }

        if (method_exists($instance, 'routes')) {
            foreach ((array)$instance->routes() as $route) {
                $this->registerPluginRoute($slug, $route);
            }
        }

        if (method_exists($instance, 'permissions')) {
            foreach ((array)$instance->permissions() as $entry) {
                $this->registerPluginPermission($entry);
            }
        }
    }

    /**
     * Verarbeitet einen Eintrag aus der optionalen Plugin::permissions()-Methode und
     * meldet ihn bei App\Permission\PermissionRegistry an (#66-Integration). Erwartetes
     * Format je Eintrag: ['module' => string, 'action' => string, 'label' => string,
     * 'module_label' => string (optional, nur bei neuem Modul relevant)]. Ungültige
     * Einträge werden ignoriert statt den Bootstrap zu unterbrechen - konsistent mit der
     * übrigen Fehlertoleranz gegenüber fehlerhaften Plugin-Deklarationen (siehe
     * registerPluginRoute()).
     */
    private function registerPluginPermission(mixed $entry): void {
        if (!is_array($entry) || empty($entry['module']) || empty($entry['action']) || empty($entry['label'])) {
            return;
        }

        PermissionRegistry::registerAction(
            (string)$entry['module'],
            (string)$entry['action'],
            (string)$entry['label'],
            isset($entry['module_label']) ? (string)$entry['module_label'] : null
        );
    }

    /**
     * Konvention: plugins/<slug>/Plugin.php definiert die Klasse
     * `Plugin\<StudlySlug>\Plugin` (z. B. Slug "demo-plugin" -> "Plugin\DemoPlugin\Plugin").
     */
    private function resolvePluginClassName(string $slug): string {
        $studly = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $slug)));
        return "Plugin\\{$studly}\\Plugin";
    }

    /**
     * Registriert eine vom Plugin deklarierte Route mit zwingend vorangestelltem
     * `/plugin/<slug>/`-Präfix (siehe Klassen-PHPDoc oben zur Begründung).
     */
    private function registerPluginRoute(string $slug, mixed $route): void {
        if (!is_array($route) || empty($route['method']) || empty($route['path']) || empty($route['callback'])) {
            return;
        }

        $method = strtoupper((string)$route['method']);
        if (!in_array($method, ['GET', 'POST'], true)) {
            return;
        }

        $relativePath = '/' . ltrim((string)$route['path'], '/');
        $fullPath = rtrim('/plugin/' . $slug . $relativePath, '/');
        if ($fullPath === '') {
            $fullPath = '/plugin/' . $slug;
        }

        $this->pluginRoutes[] = [
            'method' => $method,
            'path' => $fullPath,
            'callback' => $route['callback'],
            'slug' => $slug,
        ];
    }

    /**
     * @return array<int, array{method:string, path:string, callback:mixed, slug:string}>
     */
    public function getPluginRoutes(): array {
        return $this->pluginRoutes;
    }

    /**
     * Alle gefundenen Plugins (unabhängig vom Aktivierungsstatus) für die Admin-Übersicht.
     *
     * @return array<string, array{slug:string, dir:string, manifest:array, error:?string, compatible:bool}>
     */
    public function getDiscoveredPlugins(): array {
        return $this->discovered;
    }

    public function isEnabled(string $slug): bool {
        return in_array($slug, $this->enabledSlugs, true);
    }

    /**
     * Aktiviert/deaktiviert ein Plugin. Wirkt erst ab dem nächsten Request
     * (kein Hot-Reload nötig, PHP lädt ohnehin pro Request neu).
     */
    public function setEnabled(string $slug, bool $enabled): void {
        if (!isset($this->discovered[$slug])) {
            throw new \InvalidArgumentException("Unbekanntes Plugin: {$slug}");
        }

        $db = Database::getInstance();
        $version = (string)($this->discovered[$slug]['manifest']['version'] ?? '0.0.0');

        $stmt = $db->prepare(
            "INSERT INTO plugins (slug, enabled, installed_version, activated_at) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), installed_version = VALUES(installed_version), activated_at = VALUES(activated_at)"
        );
        $stmt->execute([$slug, $enabled ? 1 : 0, $version, $enabled ? date('Y-m-d H:i:s') : null]);
    }
}
