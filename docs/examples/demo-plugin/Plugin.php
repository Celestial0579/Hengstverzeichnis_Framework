<?php
// docs/examples/demo-plugin/Plugin.php
//
// Referenz-Implementierung für das Plugin-System (#56). Zum Ausprobieren
// lokal in das (gitignorete) Plugin-Verzeichnis kopieren:
//
//   cp -r docs/examples/demo-plugin plugins/demo-plugin
//
// und anschließend unter /admin/plugins aktivieren. Siehe
// docs/plugin-development.md für die vollständige Hook-Referenz.

namespace Plugin\DemoPlugin;

use App\Plugin\HookManager;
use App\Service\AuditLogger;

class Plugin {

    /**
     * Einstiegspunkt: Registriert sich bei allen Hooks, die dieses Referenz-Plugin
     * demonstriert. Wird von App\Plugin\PluginManager nur aufgerufen, wenn das
     * Plugin unter /admin/plugins aktiviert wurde.
     */
    public function register(HookManager $hooks): void {
        $hooks->addFilter('admin.dashboard_tiles', [$this, 'addDashboardTile']);
        $hooks->addFilter('horse.detail_sections', [$this, 'addDetailSection']);
        $hooks->addAction('horse.after_save', [$this, 'onHorseSaved']);
    }

    /**
     * Filter-Beispiel: fügt dem Admin-Dashboard eine zusätzliche Kachel hinzu.
     */
    public function addDashboardTile(array $tiles): array {
        $tiles[] = [
            'url' => '/plugin/demo-plugin/hello',
            'label' => 'Demo-Plugin',
            'icon' => '👋',
        ];
        return $tiles;
    }

    /**
     * Filter-Beispiel: fügt der öffentlichen Pferde-Detailseite einen Abschnitt hinzu.
     * Der Rückgabewert wird von der View unescaped ausgegeben (siehe
     * PublicController::horseDetail()) - das Plugin ist selbst für die
     * XSS-Vermeidung seines eigenen HTML-Fragments verantwortlich.
     *
     * Demonstriert nebenbei die Plugin-i18n-Konvention (#48): Texte kommen aus
     * lang/de.php bzw. lang/en.php in diesem Plugin-Verzeichnis, automatisch
     * unter der Domain "demo-plugin" registriert (siehe App\Plugin\PluginManager).
     */
    public function addDetailSection(array $sections, array $horse, array $horsePersons): array {
        $html = '<h3 style="margin-top:0;">' . htmlspecialchars(\App\I18n\Translator::t('detail_heading', [], 'demo-plugin')) . '</h3>'
            . '<p>' . htmlspecialchars(\App\I18n\Translator::t('detail_text', [], 'demo-plugin')) . '</p>';

        // Demonstriert den Datenvertrag des Hooks (siehe docs/plugin-development.md,
        // "Was in $horse und $horsePersons steht"): Geprüft wird das FELD, das
        // tatsächlich gebraucht wird - nicht die Verknüpfung $horse['breeding_station_id'].
        // Die ist auch dann gesetzt, wenn die Station unveröffentlicht oder gelöscht ist
        // oder der Gast-Gruppe breeding_stations.view fehlt; die station_*-Felder sind
        // dann sämtlich null. Ein Addon ist an genau dieser Verwechslung schon
        // stillschweigend gebrochen (#151).
        if (!empty($horse['station_email'])) {
            $html .= '<p data-demo-station="1">' . htmlspecialchars(\App\I18n\Translator::t(
                'detail_station',
                ['station' => (string)$horse['station_name'], 'email' => (string)$horse['station_email']],
                'demo-plugin'
            )) . '</p>';
        }

        $sections[] = $html;
        return $sections;
    }

    /**
     * Action-Beispiel: reagiert auf das Anlegen/Aktualisieren eines Pferdes.
     * Läuft in try/catch-Isolation durch HookManager::doAction() - ein Fehler
     * hier würde nur diesen Hook-Aufruf abbrechen, nie den Speichervorgang selbst.
     */
    public function onHorseSaved(int $horseId, array $data, bool $isNew): void {
        AuditLogger::log(
            'Demo-Plugin: Pferd gespeichert',
            'plugin',
            'Horse ID ' . $horseId . ', ' . ($isNew ? 'neu angelegt' : 'aktualisiert')
        );
    }

    /**
     * Routen-Beispiel: zusätzliche, vom PluginManager zwingend unter
     * /plugin/demo-plugin/... registrierte Route. Callbacks folgen derselben
     * Konvention wie Kern-Routen ([KlassenName::class, 'methode']) - der
     * Router instanziiert pro Request eine frische Controller-/Plugin-Instanz,
     * ein Callback in Form von [$this, 'methode'] würde daher NICHT die hier
     * zur Registrierungszeit aktive Instanz verwenden.
     *
     * @return array<int, array{method:string, path:string, callback:array}>
     */
    public function routes(): array {
        return [
            [
                'method' => 'GET',
                'path' => '/hello',
                'callback' => [self::class, 'helloPage'],
            ],
            [
                'method' => 'GET',
                'path' => '/export-preview',
                // Zugriffsschutz für Plugin-Routen ist Aufgabe des Plugins (siehe
                // docs/plugin-development.md, Abschnitt "Routen") - hier über eine
                // eigene, von BaseController erbende Klasse, siehe ExportPreviewController.
                'callback' => [ExportPreviewController::class, 'show'],
            ],
            [
                'method' => 'GET',
                'path' => '/premium',
                // Beispiel für eine Zusatzfunktion mit admin-konfigurierbarer
                // Sichtbarkeit (#57), siehe features() und PremiumPageController.
                'callback' => [PremiumPageController::class, 'show'],
            ],
        ];
    }

    public function helloPage(): void {
        // Plugin-Seiten laufen im zentralen Haupt-Layout (Addons#66, siehe
        // App\Plugin\PluginPage): Header, Navigation, Footer, Theme-Umschalter
        // und die admin-konfigurierten Markenfarben kommen vom Framework -
        // das Plugin liefert nur Titel und Inhalts-HTML (dynamische Werte
        // selbst mit htmlspecialchars() escapen).
        $content = '<div class="card">'
            . '<h1>👋 Hallo vom Demo-Plugin!</h1>'
            . '<p>Diese Seite wurde über die routes()-Methode des Plugins registriert und läuft unter /plugin/demo-plugin/hello.</p>'
            . '<p><a href="/admin/plugins" class="btn btn-secondary">Zurück zur Plugin-Verwaltung</a></p>'
            . '</div>';
        \App\Plugin\PluginPage::render('Demo-Plugin', $content);
    }

    /**
     * Berechtigungs-Beispiel (#66): registriert eine neue Aktion "export" am
     * BESTEHENDEN Kern-Modul "horses", ohne dass der Kern selbst dafür angepasst
     * werden muss. Erscheint danach als zusätzliche Checkbox "Exportieren" unter
     * "Pferde" in der Berechtigungsmatrix unter /admin/groups. Ein Plugin kann
     * über dieselbe Methode auch ein komplett neues, eigenes Modul anlegen -
     * dafür zusätzlich 'module_label' angeben (siehe
     * App\Permission\PermissionRegistry::registerAction()).
     *
     * @return array<int, array{module:string, action:string, label:string}>
     */
    public function permissions(): array {
        return [
            ['module' => 'horses', 'action' => 'export', 'label' => 'Exportieren'],
        ];
    }

    /**
     * Zusatzfunktions-Beispiel (#57): registriert eine Funktion mit
     * admin-konfigurierbarer Sichtbarkeit. Der Admin wählt unter
     * /admin/system-settings zwischen "Öffentlich" und "Nur für Gruppen mit
     * Leseberechtigung"; die Leseberechtigung selbst (Modul
     * `feature_demo-premium`, Aktion `read`) erscheint automatisch in der
     * Berechtigungsmatrix unter /admin/groups. Die Durchsetzung übernimmt das
     * Plugin in seiner Route über App\Permission\FeatureGate::isVisible()
     * (siehe PremiumPageController) - analog zu hasPermission() bei normalen
     * Berechtigungen.
     *
     * @return array<int, array{key:string, label:string, default_visibility:string}>
     */
    public function features(): array {
        return [
            [
                'key' => 'demo-premium',
                'label' => 'Demo-Premium-Bereich',
                // fail-closed: ohne Admin-Entscheidung nur für berechtigte Gruppen
                'default_visibility' => 'members',
            ],
        ];
    }
}

/**
 * Demonstriert, wie eine Plugin-Route die neu registrierte Berechtigung
 * tatsächlich durchsetzt - analog zu einem Kern-Controller über BaseController.
 */
/**
 * Demonstriert die Durchsetzung einer Zusatzfunktion mit admin-konfigurierbarer
 * Sichtbarkeit (#57): Die Seite ist bewusst eine ÖFFENTLICHE Route (kein
 * checkAuth()) - ob ein anonymer Besucher sie sehen darf, entscheidet allein
 * die vom Admin gewählte Sichtbarkeit bzw. die Gruppen-Leseberechtigung des
 * angemeldeten Benutzers (FeatureGate::isVisible(), fail-closed).
 */
class PremiumPageController extends \App\Controllers\BaseController {

    public function show(): void {
        if (!\App\Permission\FeatureGate::isVisible('demo-premium', $this->settings)) {
            $this->renderForbidden('Diese Zusatzfunktion ist Mitgliedern mit entsprechender Leseberechtigung vorbehalten.');
        }

        $content = '<div class="card">'
            . '<h1>✨ Demo-Premium-Bereich</h1>'
            . '<p>Diese Zusatzfunktion ist sichtbar, weil sie entweder öffentlich geschaltet ist oder Ihre Gruppe die Leseberechtigung besitzt (#57).</p>'
            . '</div>';
        \App\Plugin\PluginPage::render('Demo-Plugin: Premium-Bereich', $content);
    }
}

class ExportPreviewController extends \App\Controllers\BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission('horses', 'export');
    }

    public function show(): void {
        $content = '<div class="card">'
            . '<h1>📤 Export-Vorschau</h1>'
            . '<p>Diese Seite ist nur erreichbar, wenn Ihre Gruppe die Berechtigung <code>horses.export</code> besitzt '
            . '(siehe <a href="/admin/groups">Gruppen &amp; Berechtigungen</a>) - eine vom Demo-Plugin selbst '
            . 'registrierte, zusätzliche Aktion am bestehenden Kern-Modul "Pferde".</p>'
            . '<p><a href="/admin/plugins" class="btn btn-secondary">Zurück zur Plugin-Verwaltung</a></p>'
            . '</div>';
        \App\Plugin\PluginPage::render('Demo-Plugin: Export-Vorschau', $content);
    }
}
