<?php
// src/Controllers/UpdateController.php

namespace App\Controllers;

use App\Service\UpdateService;

/**
 * Class UpdateController
 *
 * Admin-Oberfläche für das automatische Update (#85, siehe
 * App\Service\UpdateService): Version anzeigen, Release-Prüfung anstoßen, das
 * Update manuell ausführen und - seit #290 - die unbeaufsichtigte Automatik
 * konfigurieren (saveAutomation()). Kein Weg führt am Pflicht-Backup vorbei
 * (#59), durchgesetzt in UpdateService::performUpdate().
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

        // Katalog-Cache des OFFIZIELLEN Repos hier selbst auffrischen (#290),
        // bevor die Addon-Übersicht daraus gebaut wird - sonst zeigt die
        // Update-Seite einen beliebig veralteten Stand, solange niemand den
        // Addon-Store aufruft.
        \App\Service\AddonUpdateService::refreshOfficialCatalog();

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
            'notifyEnabled' => UpdateService::isNotifyEnabled(),
            'autoInstallEnabled' => UpdateService::isAutoInstallEnabled(),
            'autoInstallScope' => UpdateService::configuredAutoScope(),
            // Ob die zugesagte Benachrichtigung ueberhaupt rausgehen kann.
            // Die Automatik haengt daran (siehe Mailer::isDeliverable()); ohne
            // diesen Hinweis merkt der Betreiber erst, dass nichts ankommt,
            // wenn ein Update laengst still eingespielt wurde.
            'mailDeliverable' => \App\Service\Mailer::isDeliverable($this->settings),
        ]);
    }

    /**
     * Speichert die Einstellungen der unbeaufsichtigten Update-Automatik
     * (#290, zweite Stufe aus #85). Der Kanal bleibt bewusst ein eigenes
     * Formular: Er gilt auch für das manuelle Update, die Automatik nicht.
     */
    public function saveAutomation(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $notify = !empty($_POST['update_notify']) ? '1' : '0';
        $enabled = !empty($_POST['update_auto_install']) ? '1' : '0';
        $scope = UpdateService::normalizeAutoScope((string)($_POST['update_auto_install_scope'] ?? ''));

        // Automatisch installieren ohne zu benachrichtigen wäre ein stiller
        // Codeaustausch - die Kombination wird gar nicht erst gespeichert.
        if ($enabled === '1' && $notify === '0') {
            header("Location: /admin/updates?error=" . urlencode(
                'Automatische Installation setzt die E-Mail-Benachrichtigung voraus - '
                . 'sonst bliebe unbemerkt, was auf der Installation passiert ist.'));
            exit;
        }

        // Ohne konfiguriertes Backup würde performUpdate() ohnehin abbrechen -
        // die Automatik hier trotzdem einschalten zu lassen, erzeugte nur eine
        // tägliche Fehlermail. Serverseitig durchgesetzt, nicht nur in der View.
        if ($enabled === '1' && !\App\Service\BackupService::isConfigured($this->settings)) {
            header("Location: /admin/updates?error=" . urlencode(
                'Automatische Updates lassen sich erst aktivieren, wenn ein externes Backup eingerichtet ist - '
                . 'ein Update ohne vorheriges Backup wird grundsätzlich nicht ausgeführt.'));
            exit;
        }

        if ($enabled === '1' && !UPDATE_IN_PLACE) {
            header("Location: /admin/updates?error=" . urlencode(
                'Die In-Place-Aktualisierung ist in dieser Installation deaktiviert (Container-Betrieb) - '
                . 'eine automatische Installation ist damit nicht möglich. Über verfügbare Versionen wird '
                . 'weiterhin per E-Mail informiert.'));
            exit;
        }

        $db = \App\Database::getInstance();
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute(['update_notify', $notify, $notify]);
        $stmt->execute(['update_auto_install', $enabled, $enabled]);
        $stmt->execute(['update_auto_install_scope', $scope, $scope]);

        \App\Service\AuditLogger::log(
            'Automatische Updates geändert',
            'update',
            'Benachrichtigung: ' . ($notify === '1' ? 'an' : 'aus')
            . '; Installation: ' . ($enabled === '1'
                ? 'an, Reichweite ' . ($scope === UpdateService::AUTO_SCOPE_ANY ? 'jede neuere Version' : 'nur Patch-Versionen')
                : 'aus')
        );

        header("Location: /admin/updates?automation_saved=1");
        exit;
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

        // Ergebnis der Addon-Phase (#197, Stufe 2) mit in die Erfolgsmeldung
        // nehmen. Seit #290 wandert auch der KLARTEXT-Grund mit: Die blosse
        // Zahl "N fehlgeschlagen" liess Betreiber im Unklaren, warum Addons
        // nach einem Kern-Update nicht mitgezogen wurden - der Grund stand
        // nur im Audit-Log, wo kaum jemand nachsieht.
        $addonResults = is_array($result['addons'] ?? null) ? $result['addons'] : [];
        $addonsOk = count(array_filter($addonResults, static fn(array $r): bool => (bool)$r['ok']));
        $addonsFail = count($addonResults) - $addonsOk;
        $summary = \App\Service\AddonUpdateService::summarizeFailures($addonResults);

        $location = "/admin/updates?success=1&from=" . urlencode($result['from']) . "&to=" . urlencode($result['to'])
            . "&addons_ok=" . $addonsOk . "&addons_fail=" . $addonsFail;
        if ($summary['reasons'] !== []) {
            $location .= "&addons_fail_reasons=" . urlencode(implode(';', $summary['reasons']))
                . "&addons_fail_slugs=" . urlencode(implode(',', $summary['slugs']));
        }

        header("Location: " . $location);
        exit;
    }

    /**
     * Manuelles Update eines einzelnen Addons aus dem offiziellen Repo,
     * innerhalb der laufenden Kern-Linie (#197, Stufe 2). Fremd-Repos und
     * manuell kopierte Addons lehnt der AddonUpdateService serverseitig ab.
     */
    public function updateAddon(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $slug = (string)($_POST['slug'] ?? '');
        $result = \App\Service\AddonUpdateService::updateAddon($slug);

        if (!$result['ok']) {
            header("Location: /admin/updates?addon_error=" . urlencode((string)$result['error']) . "&slug=" . urlencode($slug));
            exit;
        }

        header("Location: /admin/updates?addon_success=1&slug=" . urlencode($slug)
            . "&from=" . urlencode((string)($result['from'] ?? ''))
            . "&to=" . urlencode((string)($result['to'] ?? '')));
        exit;
    }
}
