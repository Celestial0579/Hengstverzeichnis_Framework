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

        // Addons mitdenken (#197, Stufe 1): Kompatibilität wird gegen die
        // ZIELversion eines verfügbaren Kern-Updates geprüft, nicht nur gegen
        // die laufende - die Warnung muss VOR dem Klick auf "Aktualisieren"
        // stehen, nicht nach dem stillen Verschwinden eines Addons.
        $targetVersion = (is_array($checkResult) && !empty($checkResult['update_available']))
            ? (string)$checkResult['latest']
            : null;
        $addonCatalog = \App\Service\AddonOverview::officialCatalogFromCache();

        $this->render('admin_updates', [
            'title' => 'Updates',
            'currentVersion' => UpdateService::currentVersion(),
            'backupConfigured' => \App\Service\BackupService::isConfigured($this->settings),
            'updateChannel' => UpdateService::normalizeChannel((string)($this->settings['update_channel'] ?? UpdateService::CHANNEL_STABLE)),
            'checkResult' => $checkResult,
            'checkError' => $checkError,
            'inPlaceEnabled' => UPDATE_IN_PLACE,
            'targetVersion' => $targetVersion,
            'addonRows' => \App\Service\AddonOverview::rows($targetVersion),
            'addonCatalogAvailable' => $addonCatalog['available'],
            'addonCatalogCachedAt' => $addonCatalog['cachedAt'],
        ]);
    }

    /**
     * Speichert den Update-Kanal (Beta-Opt-in, siehe UpdateService).
     * Unbekannte Werte fallen serverseitig auf 'stable' zurück; ein
     * Kanalwechsel kann nie zu einem Downgrade führen, da
     * UpdateService::selectBestRelease() ausschließlich strikt neuere
     * Versionen als Kandidaten zulässt.
     */
    public function saveChannel(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $channel = UpdateService::normalizeChannel((string)($_POST['update_channel'] ?? ''));

        $db = \App\Database::getInstance();
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('update_channel', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$channel, $channel]);

        \App\Service\AuditLogger::log(
            'Update-Kanal geändert',
            'update',
            $channel === UpdateService::CHANNEL_BETA ? 'Beta (Vorabversionen aktiviert)' : 'Stabil'
        );

        // Direkt mit frischer Release-Prüfung im neuen Kanal zurückkehren.
        header("Location: /admin/updates?check=1&channel_saved=1");
        exit;
    }

    public function run(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        // Defense-in-Depth: Ist die In-Place-Aktualisierung deaktiviert
        // (Container-Betrieb, UPDATE_IN_PLACE=0), wird das Update auch bei einem
        // direkten POST verweigert - die View blendet den Knopf ohnehin aus.
        if (!UPDATE_IN_PLACE) {
            header("Location: /admin/updates?error=" . urlencode(
                'Die In-Place-Aktualisierung ist in dieser Installation deaktiviert '
                . '(Container-Betrieb). Aktualisiere über ein neues Image, z. B. mit Watchtower.'));
            exit;
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
