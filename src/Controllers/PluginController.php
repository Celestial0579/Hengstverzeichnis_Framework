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
}
