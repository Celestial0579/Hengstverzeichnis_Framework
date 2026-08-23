<?php
// src/Controllers/UpdateController.php

namespace App\Controllers;

use App\Service\Integritaet;
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

        // Der Katalog des offiziellen Repos wird hier NICHT mehr bei jedem
        // Seitenaufruf geholt (#319).
        //
        // refreshOfficialCatalog() landet bei GithubAddonRepository::
        // fetchCatalog(): Download des kompletten Repo-Tarballs von
        // api.github.com (bis MAX_TARBALL_BYTES = 20 MB), Entpacken per
        // PharData in ein temporaeres Verzeichnis, scandir ueber alle
        // plugins/*/plugin.json, rekursives Loeschen - dazu ein zweiter
        // API-Aufruf fuer bestReleaseTagForCoreLine(). Alles synchron, bevor
        // ein Byte HTML rausgeht. Im Stoerfall haengt die reine Anzeigeseite
        // an zwei Zeitgrenzen von je 20 s und laeuft dann in
        // max_execution_time, ohne dass der Admin die Ursache sieht.
        //
        // Warm gehalten wird der Katalog vom ohnehin vorhandenen Cron-Lauf
        // (UpdateService::runCheckAndNotify() ruft dieselbe Methode auf). Wer
        // den Stand sofort braucht, klickt "Katalog jetzt auffrischen" -
        // dann, und nur dann, wird die TTL uebergangen.
        if (isset($_GET['refresh'])) {
            \App\Service\AddonUpdateService::refreshOfficialCatalog(true);
        }

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
            // Zweite Ebene: Der Transport kann stehen und die Mail trotzdem
            // niemanden erreichen, wenn alle Admin-Adressen ins Leere gehen.
            'adminRecipientReachable' => UpdateService::hasReachableAdminRecipient(),
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

        // Reihenfolge der drei Sperren: erst die Eigenschaft der INSTALLATION,
        // dann die der Konfiguration (#313).
        //
        // Im Container gehört der Anwendungscode root und PHP läuft als
        // www-data; die In-Place-Aktualisierung ist dort abgeschaltet, und
        // daran ändert keine Einstellung etwas. Stand diese Prüfung hinter der
        // Backup-Sperre, bekam der Betreiber einer Container-Installation die
        // Aufforderung, ein externes Backup einzurichten - und danach dieselbe
        // Ablehnung aus einem ganz anderen Grund. Die unerreichbare Sperre war
        // zugleich nicht prüfbar, was der Grund ist, dass sie überhaupt
        // auffiel.
        if ($enabled === '1' && !UPDATE_IN_PLACE) {
            header("Location: /admin/updates?error=" . urlencode(
                'Die In-Place-Aktualisierung ist in dieser Installation deaktiviert (Container-Betrieb) - '
                . 'eine automatische Installation ist damit nicht möglich. Über verfügbare Versionen wird '
                . 'weiterhin per E-Mail informiert.'));
            exit;
        }

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

        // Ausdrückliche Bestätigung, wenn Addons auf der Strecke blieben (#364).
        //
        // HIER, nicht nur in der View. Ein Eingabefeld im Formular ist keine
        // Prüfung - ein direkter POST kommt ohne es aus. Die View macht die
        // Hürde sichtbar, durchgesetzt wird sie an dieser Stelle.
        //
        // Das Kriterium ist NICHT der Versionssprung. Updates sollen
        // grundsätzlich laufen, so wie es Kanal und Reichweite vorgeben; ein
        // Linienwechsel mit passenden Addon-Fassungen ist unproblematisch.
        // Reibung braucht genau der Fall, in dem eine Funktion verschwindet
        // und niemand sie zurückholen kann: ein aktives Addon, das die
        // Zielversion nicht unterstützt und für das auch im Store nichts
        // Passendes liegt.
        //
        // Ein Bestätigungsdialog hilft dagegen nicht - der wird mit "OK"
        // beantwortet, ohne gelesen zu werden. Eine Versionsnummer tippt man
        // nicht versehentlich ab (dasselbe Muster wie beim Löschen von
        // Addon-Daten, #338).
        //
        // Blockiert wird NICHT. Der manuelle Weg muss jede Version einspielen
        // können - er ist ja gerade der Ausweg, den die Automatik verweigert.
        // Das Pflicht-Backup ZUERST, noch vor der Release-Abfrage.
        //
        // performUpdate() prueft es ohnehin und hat es immer schon als Erstes
        // getan - hier ging aber die Release-Abfrage davor, und die geht ins
        // Netz. Eine Installation ohne eingerichtetes Backup holte damit
        // zuerst die Release-Liste von GitHub, um danach an einer Bedingung
        // zu scheitern, die ohne jeden Netzzugriff feststeht. Der Test
        // testRunIsRejectedWithoutConfiguredBackup lief deshalb regelmaessig
        // in ein Zehn-Sekunden-Timeout, sobald GitHub langsam war.
        //
        // Beide Meldungen waeren fuer sich richtig: Ein nicht erreichbarer
        // Release-Server IST eine fehlgeschlagene Release-Pruefung. Gemeldet
        // wird aber nur die zuerst gescheiterte Bedingung, und dafuer ist
        // diese hier die nuetzlichere - sie haengt allein an der eigenen
        // Konfiguration.
        //
        // Die Reihenfolge INNERHALB von performUpdate() haelt
        // UpdateRunTest::testTheBackupGuardRunsBeforeTheReleaseLookup() fest.
        // Diese Zeile hier ist davon unabhaengig und spart nur den Umweg; die
        // Meldung selbst steht in UpdateService, damit es sie nur einmal gibt.
        $hindernis = UpdateService::backupHindernis();
        if ($hindernis !== null) {
            header("Location: /admin/updates?error=" . urlencode($hindernis));
            exit;
        }

        try {
            $vorschau = UpdateService::checkForUpdate();
        } catch (\Throwable $e) {
            header("Location: /admin/updates?error=" . urlencode(
                'Release-Prüfung fehlgeschlagen: ' . $e->getMessage()));
            exit;
        }

        $ziel = (string)($vorschau['latest'] ?? '');
        $verlierer = $ziel === ''
            ? []
            : UpdateService::addonsBlockingAutoInstall(\App\Service\AddonOverview::rows($ziel));

        if ($verlierer !== []) {
            $getippt = trim((string)($_POST['bestaetigung'] ?? ''));
            if ($getippt !== $ziel) {
                header("Location: /admin/updates?error=" . urlencode(sprintf(
                    'Nicht aktualisiert: %d aktive(s) Addon(s) unterstützen %s nicht, und im Addon-Store '
                    . 'liegt keine passende Fassung. Zum Einspielen muss die Zielversion zur Bestätigung '
                    . 'eingetippt werden - die Addons werden dabei nicht gelöscht, aber unsichtbar. '
                    . 'Betroffen: %s',
                    count($verlierer),
                    $ziel,
                    implode(' | ', $verlierer)
                )));
                exit;
            }
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
    /**
     * Prueft den Codebaum gegen den Sollzustand des Releases (#403).
     *
     * Zwei Knoepfe, zwei Aussagen - und der Unterschied gehoert in die
     * Oberflaeche, nicht in eine Fussnote: Die mitgelieferte Liste liegt im
     * selben Dateibaum wie die geprueften Dateien und findet deshalb keinen
     * Angreifer, der beides anfasst. Die veroeffentlichte kommt von GitHub
     * und liegt ausserhalb seiner Reichweite.
     */
    public function pruefeIntegritaet(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $gegenVeroeffentlichte = ($_POST['quelle'] ?? '') === 'veroeffentlicht';

        try {
            $ergebnis = Integritaet::pruefe($gegenVeroeffentlichte);
        } catch (\Throwable $e) {
            $_SESSION['integritaet_fehler'] = $e->getMessage();
            header('Location: /admin/updates');
            exit;
        }

        \App\Service\AuditLogger::log(
            'Integritätsprüfung des Codebaums',
            'security',
            sprintf(
                'Quelle: %s, %d Datei(en) geprüft - %d geändert, %d fehlend, %d zusätzlich.',
                $ergebnis['quelle'],
                $ergebnis['geprueft'],
                count($ergebnis['geaendert']),
                count($ergebnis['fehlt']),
                count($ergebnis['zusaetzlich'])
            )
        );

        $_SESSION['integritaet'] = $ergebnis;
        header('Location: /admin/updates#integritaet');
        exit;
    }

    /**
     * Stellt abweichende Dateien aus dem Release wieder her (#403).
     *
     * Die Auswahl kommt aus dem Formular, wird aber NICHT als Pfadliste
     * vertraut: Integritaet::repariere() laesst nur durch, was in der
     * veroeffentlichten Solliste steht und in einem KERN-Pfad liegt. Ein
     * Formularfeld darf nie bestimmen, welche Datei ueberschrieben wird.
     */
    public function repariere(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $pfade = array_values(array_filter(
            array_map('strval', (array)($_POST['pfade'] ?? [])),
            static fn(string $p): bool => $p !== ''
        ));

        if ($pfade === []) {
            $_SESSION['integritaet_fehler'] = 'Es war keine Datei ausgewählt.';
            header('Location: /admin/updates#integritaet');
            exit;
        }

        try {
            $ergebnis = Integritaet::repariere($pfade);
        } catch (\Throwable $e) {
            $_SESSION['integritaet_fehler'] = $e->getMessage();
            header('Location: /admin/updates#integritaet');
            exit;
        }

        $_SESSION['integritaet_repariert'] = $ergebnis;
        // Direkt nachmessen: Eine Reparatur, die niemand nachprueft, ist eine
        // Behauptung. Und zwar gegen die veroeffentlichte Liste - gegen die
        // mitgelieferte zu pruefen hiesse, das Ergebnis an derselben Quelle
        // zu messen, aus der die Reparatur kam.
        try {
            $_SESSION['integritaet'] = Integritaet::pruefe(true);
        } catch (\Throwable) {
            unset($_SESSION['integritaet']);
        }

        header('Location: /admin/updates#integritaet');
        exit;
    }

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
