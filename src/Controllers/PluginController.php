<?php
// src/Controllers/PluginController.php

namespace App\Controllers;

use App\Plugin\PluginManager;
use App\Service\AuditLogger;

/**
 * Class PluginController
 *
 * Admin-only Verwaltung des Plugin-Systems (#56): Übersicht aller in
 * `plugins/` gefundenen Plugins (Name, Version, Kompatibilität, deklarierte
 * Hooks) sowie Aktivieren/Deaktivieren einzelner Plugins.
 */
class PluginController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requireAdmin();
    }

    public function index(): void {
        $this->render('admin_plugins', [
            'title' => 'Plugins verwalten',
            'plugins' => PluginManager::getInstance()->getDiscoveredPlugins(),
        ]);
    }

    public function toggle(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $manager = PluginManager::getInstance();
        $plugins = $manager->getDiscoveredPlugins();

        $slug = trim($_POST['slug'] ?? '');
        $enable = !empty($_POST['enable']);

        if (!isset($plugins[$slug])) {
            header("Location: /admin/plugins?error=unknown_plugin");
            exit;
        }

        if ($enable && ($plugins[$slug]['error'] !== null || !$plugins[$slug]['compatible'])) {
            header("Location: /admin/plugins?error=incompatible");
            exit;
        }

        $manager->setEnabled($slug, $enable);

        AuditLogger::log(
            $enable ? "Plugin aktiviert" : "Plugin deaktiviert",
            "plugin",
            "Slug: {$slug}, Version: " . ($plugins[$slug]['manifest']['version'] ?? '?')
        );

        header("Location: /admin/plugins?success=1");
        exit;
    }

    /**
     * GET /admin/plugins/uninstall?slug=... - die Rückfrage vor dem Löschen (#338).
     *
     * Bewusst eine EIGENE Seite und kein Bestätigungsdialog im Browser: Der
     * Betreiber soll sehen, was verschwindet, bevor er entscheidet - und zwar
     * mit Zahlen. "3 Tabellen werden gelöscht" ist keine Information,
     * "1.284 Kontaktanfragen werden gelöscht" ist eine.
     */
    public function uninstallForm(): void {
        $manager = PluginManager::getInstance();
        $plugins = $manager->getDiscoveredPlugins();
        $slug = trim($_GET['slug'] ?? '');

        if (!isset($plugins[$slug])) {
            header("Location: /admin/plugins?error=unknown_plugin");
            exit;
        }

        $this->render('admin_plugin_uninstall', [
            'title' => 'Addon deinstallieren',
            'slug' => $slug,
            'plugin' => $plugins[$slug],
            'vorschau' => $manager->deinstallationsVorschau($slug),
        ]);
    }

    /**
     * POST /admin/plugins/uninstall - deinstalliert, mit oder ohne Daten.
     *
     * Die Entscheidung "Daten löschen" verlangt zusätzlich, dass der Slug von
     * Hand eingetippt wird. Das ist keine Schikane: Ein Häkchen setzt man
     * versehentlich, einen Namen tippt man nicht versehentlich ab - und
     * anders als beim Deaktivieren gibt es hier kein Zurück.
     */
    public function uninstall(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $manager = PluginManager::getInstance();
        $plugins = $manager->getDiscoveredPlugins();
        $slug = trim($_POST['slug'] ?? '');

        if (!isset($plugins[$slug])) {
            header("Location: /admin/plugins?error=unknown_plugin");
            exit;
        }

        $datenLoeschen = ($_POST['daten'] ?? '') === 'loeschen';
        if ($datenLoeschen && trim($_POST['bestaetigung'] ?? '') !== $slug) {
            header("Location: /admin/plugins/uninstall?slug=" . urlencode($slug) . "&error=bestaetigung");
            exit;
        }

        $protokoll = $manager->uninstall($slug, $datenLoeschen);

        // Das Protokoll gehört dem Betreiber - es steht sonst nur im
        // Audit-Log, und dort sucht nach einem Klick niemand.
        $_SESSION['plugin_uninstall_protokoll'] = $protokoll;

        header("Location: /admin/plugins?uninstalled=" . urlencode($slug));
        exit;
    }
}
