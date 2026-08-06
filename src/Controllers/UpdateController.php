<?php
// src/Controllers/UpdateController.php

namespace App\Controllers;

use App\Service\UpdateService;

/**
 * Class UpdateController
 *
 * Admin-Oberfläche für das automatische Update (#85, siehe
 * App\Service\UpdateService): Version anzeigen, Release-Prüfung anstoßen und
 * das Update manuell ausführen. Bewusst nur manuell (kein Scheduler-Lauf) -
 * und niemals ohne unmittelbar zuvor erfolgreiches Pflicht-Backup (#59),
 * durchgesetzt in UpdateService::performUpdate().
 */
class UpdateController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requireAdmin();
    }

    public function index(): void {
        $checkResult = null;
        $checkError = null;

        if (isset($_GET['check'])) {
            try {
                $checkResult = UpdateService::checkForUpdate();
            } catch (\Throwable $e) {
                $checkError = $e->getMessage();
            }
        }

        $this->render('admin_updates', [
            'title' => 'Updates',
            'currentVersion' => UpdateService::currentVersion(),
            'backupConfigured' => \App\Service\BackupService::isConfigured($this->settings),
            'checkResult' => $checkResult,
            'checkError' => $checkError,
        ]);
    }

    public function run(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        try {
            $result = UpdateService::performUpdate();
        } catch (\Throwable $e) {
            header("Location: /admin/updates?error=" . urlencode($e->getMessage()));
            exit;
        }

        header("Location: /admin/updates?success=1&from=" . urlencode($result['from']) . "&to=" . urlencode($result['to']));
        exit;
    }
}
