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
     */
    public function addDetailSection(array $sections, array $horse, array $horsePersons): array {
        $sections[] = '<h3 style="margin-top:0;">👋 Demo-Plugin</h3>'
            . '<p>Dieser Abschnitt wurde vom Demo-Plugin über den Hook <code>horse.detail_sections</code> '
            . 'ergänzt, ohne eine einzige Kern-Datei zu verändern.</p>';
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
        ];
    }

    public function helloPage(): void {
        echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Demo-Plugin</title></head>';
        echo '<body style="font-family: sans-serif; padding: 2rem;">';
        echo '<h1>👋 Hallo vom Demo-Plugin!</h1>';
        echo '<p>Diese Seite wurde über die routes()-Methode des Plugins registriert und läuft unter /plugin/demo-plugin/hello.</p>';
        echo '<p><a href="/admin/plugins">Zurück zur Plugin-Verwaltung</a></p>';
        echo '</body></html>';
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
}

/**
 * Demonstriert, wie eine Plugin-Route die neu registrierte Berechtigung
 * tatsächlich durchsetzt - analog zu einem Kern-Controller über BaseController.
 */
class ExportPreviewController extends \App\Controllers\BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission('horses', 'export');
    }

    public function show(): void {
        echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Demo-Plugin: Export-Vorschau</title></head>';
        echo '<body style="font-family: sans-serif; padding: 2rem;">';
        echo '<h1>📤 Export-Vorschau</h1>';
        echo '<p>Diese Seite ist nur erreichbar, wenn Ihre Gruppe die Berechtigung <code>horses.export</code> besitzt ';
        echo '(siehe <a href="/admin/groups">Gruppen &amp; Berechtigungen</a>) - eine vom Demo-Plugin selbst ';
        echo 'registrierte, zusätzliche Aktion am bestehenden Kern-Modul "Pferde".</p>';
        echo '<p><a href="/admin/plugins">Zurück zur Plugin-Verwaltung</a></p>';
        echo '</body></html>';
    }
}
