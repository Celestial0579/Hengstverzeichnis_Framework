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
 * - Ein optionales `lang/<locale>.php`-Verzeichnis im Plugin-Ordner wird
 *   automatisch als eigene Übersetzungs-Domain (Plugin-Slug) bei
 *   App\I18n\Translator registriert (#48) - reine Konvention wie beim
 *   Default-Entry `Plugin.php`, keine Manifest-Deklaration nötig.
 * - Eindeutige Kennung pro Plugin-**Version**: Bei Aktivierung wird die
 *   Manifest-Version zusammen mit einem SHA-256-Fingerabdruck über den
 *   gesamten Plugin-Ordner in der Tabelle `plugins` gespeichert
 *   (`installed_version`/`content_hash`). Der Verzeichnisname/Slug allein
 *   ist damit keine ausreichende Identität mehr, um eine einmal erteilte
 *   Freigabe zu behalten:
 *   - Hebt das Manifest beim nächsten Request eine NEUE Versionsnummer
 *     aus (normales Plugin-Update), wird das NUR DANN automatisch
 *     akzeptiert, wenn die gespeicherte Herkunft (`plugins.source`) auf
 *     einen unveränderlichen Release-Tag zeigt (`owner/repo@vX.Y.z`, siehe
 *     isReleaseTagSource()) - also der über Store/AddonUpdateService aus
 *     einem Release eingespielte Normalfall (#212). Manuell kopierte oder
 *     aus einem Branch-Stand installierte Plugins werden bei einem
 *     Versionswechsel dagegen fail-closed NICHT geladen, bis ein Admin die
 *     neue Version über /admin/plugins ausdrücklich freigibt - sonst wäre
 *     "Versionsnummer im Manifest erhöhen" ein trivialer Umweg um die
 *     gesamte Fingerabdruck-Kette.
 *   - Bleibt die Versionsnummer GLEICH, weicht aber der Fingerabdruck vom
 *     zuletzt freigegebenen ab (Code wurde ausgetauscht, ohne die Version
 *     zu erhöhen - untypisch für ein reguläres Update), wird das Plugin
 *     NICHT geladen, bis ein Admin es über /admin/plugins erneut freigibt.
 *   Siehe computeFingerprint()/needsReapproval().
 * - Performance des Fingerabdrucks (#224): Der SHA-256 über alle Dateien wird
 *   nicht mehr bei jedem Request für jedes Plugin berechnet, sondern lazy
 *   (fingerprintOf()) und nur, wenn der billige Verzeichnis-Stempel
 *   (computeDirStamp(): max(filemtime), Dateianzahl, Gesamtgröße - reine
 *   stat()-Aufrufe) vom bei der Freigabe gespeicherten `plugins.dir_stamp`
 *   abweicht. Der Stempel ist ausschließlich eine Abkürzung für den Fall
 *   "nachweislich nichts angefasst": Jede Abweichung - auch eine fehlende
 *   Bestandszeile ohne Stempel - führt unverändert zum vollen
 *   Hash-Vergleich, die Fail-Closed-Garantien bleiben vollständig bestehen.
 * - Installations-Hook (Addons#75): Definiert ein Plugin eine öffentliche
 *   install()-Methode, ruft setEnabled(..., true) sie bei jeder
 *   (Re-)Aktivierung auf; der AddonUpdateService ruft sie über
 *   runInstallHook() nach einem eingespielten Update erneut auf. install()
 *   ist damit der Ort für DDL/Migrationen eines Plugins (idempotent,
 *   z. B. CREATE TABLE IF NOT EXISTS) - register() läuft bei JEDEM Request
 *   und soll kein DDL mehr ausführen (siehe docs/plugin-development.md).
 * - Nicht-destruktive Fail-Closed-Garantie: Wird ein Plugin als "muss erneut
 *   freigegeben werden" markiert (needsReapproval()), wird dafür NIE die
 *   `plugins`-Zeile verändert oder gelöscht - nur eine reine Laufzeit-Markierung
 *   "für diesen Request nicht laden" gesetzt. Selbst ein Bug in der
 *   Fingerabdruck-/Versionsprüfung kann daher höchstens fälschlich diese
 *   Markierung auslösen, aber nie die bisherige Aktivierung, Konfiguration
 *   oder zugewiesene Berechtigungen (Tabelle `group_permissions`, unabhängig
 *   vom Plugin-Aktivierungsstatus) zerstören. Die Wiederherstellung ist immer
 *   ein einzelner Klick auf "Mit bisherigem Status erneut freigeben" unter
 *   /admin/plugins (ruft lediglich erneut setEnabled() auf).
 */
final class PluginManager {

    private static ?PluginManager $instance = null;

    private HookManager $hooks;

    /** @var array<string, array{slug:string, dir:string, manifest:array, error:?string, compatible:bool, incompatible_reason:?string, fingerprint:?string, dir_stamp:?string}> */
    private array $discovered = [];

    /** @var array<int, string> */
    private array $enabledSlugs = [];

    /** @var array<string, string|null> slug => zuletzt freigegebene Manifest-Version */
    private array $approvedVersions = [];

    /** @var array<string, string|null> slug => zuletzt freigegebener content_hash */
    private array $approvedHashes = [];

    /** @var array<string, string|null> slug => bei der Freigabe gespeicherter Verzeichnis-Stempel (plugins.dir_stamp, #224) */
    private array $approvedDirStamps = [];

    /** @var array<string, string|null> slug => Herkunft (plugins.source, z. B. 'owner/repo@v1.2.3'; null = manuell kopiert) */
    private array $sources = [];

    /** @var array<string, bool> slug => true, wenn der aktuelle Code vom freigegebenen Fingerabdruck abweicht */
    private array $needsReapproval = [];

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

        // Ab hier steht der vollstaendige Sprachbestand fest (#378). Erst
        // jetzt darf Translator eine Session-Sprachwahl als "deaktiviert"
        // verwerfen - vorher weiss er von den Sprach-Addons (#344) nichts,
        // und ein Weg, der diesen Bootstrap ueberspringt, haette jede
        // Addon-Sprache aus der Sitzung geloescht.
        \App\I18n\Translator::bestandIstVollstaendig();
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

            $incompatibleReason = $error === null
                ? self::incompatibilityReason(is_array($manifest) ? $manifest : [], defined('CORE_VERSION') ? CORE_VERSION : '')
                : null;

            $this->discovered[$entry] = [
                'slug' => $entry,
                'dir' => $pluginDir,
                'manifest' => is_array($manifest) ? $manifest : [],
                'error' => $error,
                'compatible' => $error === null && $incompatibleReason === null,
                // Warum das Plugin nicht zur laufenden Kern-Version passt (#197):
                // Der Skip in loadEnabledPlugins() war bisher stumm - die
                // Begründung macht ihn in Admin-Übersichten erklärbar.
                'incompatible_reason' => $incompatibleReason,
                // Inhalts-Fingerabdruck und Verzeichnis-Stempel werden LAZY über
                // fingerprintOf()/dirStampOf() berechnet und hier memoisiert (#224):
                // discoverPlugins() läuft im Bootstrap JEDES Requests auch für
                // deaktivierte Plugins - der SHA-256 über sämtliche Dateien aller
                // Plugins wäre an dieser Stelle reiner Overhead (gemessen 15-40 ms
                // pro Request auf Netzwerk-Storage).
                'fingerprint' => null,
                'dir_stamp' => null,
            ];
        }

        ksort($this->discovered);
    }

    /**
     * Berechnet einen SHA-256-Fingerabdruck über den gesamten Inhalt eines
     * Plugin-Verzeichnisses (alle Dateien rekursiv, Pfad + Inhalt), unabhängig von
     * Dateisystem-Metadaten wie Zeitstempeln - identischer Code liefert immer
     * denselben Fingerabdruck, jede inhaltliche Änderung einen anderen. Dient als
     * eindeutige Kennung der freigegebenen Plugin-Version (siehe Klassen-PHPDoc).
     */
    private function computeFingerprint(string $dir): string {
        $files = [];

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $relativePath = substr($file->getPathname(), strlen($dir) + 1);
                $fileHash = hash_file('sha256', $file->getPathname());
                if ($fileHash !== false) {
                    $files[$relativePath] = $fileHash;
                }
            }
        } catch (\Throwable $e) {
            // Verzeichnis nicht lesbar o. Ä. - liefert unten einen Fingerabdruck über eine
            // leere Dateiliste, was beim Vergleich zuverlässig als "abweichend" auffällt.
        }

        ksort($files);
        $summary = '';
        foreach ($files as $relativePath => $fileHash) {
            $summary .= $relativePath . ':' . $fileHash . "\n";
        }

        return hash('sha256', $summary);
    }

    /**
     * Liefert den SHA-256-Fingerabdruck eines entdeckten Plugins - lazy und pro
     * Request memoisiert (#224). Berechnet wird erst (und nur), wenn eine Stelle
     * ihn wirklich braucht: loadEnabledPlugins() bei abweichendem
     * Verzeichnis-Stempel bzw. bei einem Versionswechsel und setEnabled() bei
     * der Freigabe. Für Plugins mit Manifest-Fehler bleibt er wie bisher null -
     * sie werden ohnehin nie geladen (Verhalten identisch zur früheren eager
     * Berechnung in discoverPlugins()).
     */
    private function fingerprintOf(string $slug): ?string {
        $info = $this->discovered[$slug] ?? null;
        if ($info === null || $info['error'] !== null) {
            return null;
        }
        if ($info['fingerprint'] === null) {
            $this->discovered[$slug]['fingerprint'] = $this->computeFingerprint($info['dir']);
        }
        return $this->discovered[$slug]['fingerprint'];
    }

    /**
     * Liefert den billigen Verzeichnis-Stempel eines entdeckten Plugins - lazy
     * und pro Request memoisiert, analog zu fingerprintOf() (#224). Auch der
     * Stempel wird nur für Plugins berechnet, die ihn brauchen (aktivierte bzw.
     * gerade freizugebende) - deaktivierte Plugins kosten damit im Bootstrap
     * gar keine Dateisystem-Zugriffe über den Manifest-Read hinaus.
     */
    private function dirStampOf(string $slug): ?string {
        $info = $this->discovered[$slug] ?? null;
        if ($info === null || $info['error'] !== null) {
            return null;
        }
        if ($info['dir_stamp'] === null) {
            $this->discovered[$slug]['dir_stamp'] = $this->computeDirStamp($info['dir']);
        }
        return $this->discovered[$slug]['dir_stamp'];
    }

    /**
     * Billiger Verzeichnis-Stempel als Vorfilter für den SHA-256-Fingerabdruck
     * (#224): "max(filemtime):Dateianzahl:Summe(filesize)" über alle Dateien
     * rekursiv - reine stat()-Aufrufe, kein Öffnen/Lesen/Hashen von Inhalten.
     *
     * Sicherheits-Einordnung: Der Stempel ist ausschließlich eine Abkürzung für
     * "nachweislich nichts angefasst". Nur ein EXAKT übereinstimmender Stempel
     * erspart den vollen Hash; jede Abweichung (andere mtime, andere Anzahl,
     * andere Gesamtgröße, fehlender Bestandswert) führt unverändert zum
     * SHA-256-Vergleich. Ein Angreifer, der mtimes zurückdatieren und die
     * Gesamtgröße konstant halten kann, hat bereits Schreibzugriff auf plugins/
     * und könnte damit ohnehin beliebigen Code direkt einspielen - die
     * Fingerabdruck-Kette schützt vor unbemerkt ausgetauschten Ständen, nicht
     * vor einem kompromittierten Dateisystem (siehe docs/plugin-development.md,
     * "Sicherheitsgrenzen").
     */
    private function computeDirStamp(string $dir): string {
        $maxMtime = 0;
        $count = 0;
        $bytes = 0;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $maxMtime = max($maxMtime, (int)$file->getMTime());
                $count++;
                $bytes += (int)$file->getSize();
            }
        } catch (\Throwable $e) {
            // Verzeichnis nicht lesbar o. Ä. - der Stempel über die bis dahin
            // erfasste (ggf. leere) Liste weicht beim Vergleich zuverlässig ab
            // und erzwingt den vollen Hash-Vergleich (fail-closed).
        }

        return $maxMtime . ':' . $count . ':' . $bytes;
    }

    /**
     * True, wenn eine gespeicherte Plugin-Herkunft (plugins.source) auf einen
     * unveränderlichen Release-Tag zeigt: 'owner/repo@vX.Y.z'. Nur solche
     * Stände dürfen einen Versionswechsel automatisch akzeptiert bekommen
     * (#212) - Branch-Refs ('owner/repo@main'), Herkunft ohne Ref
     * ('owner/repo' = Default-Branch-HEAD) und manuell kopierte Plugins
     * (source NULL) sind mutabel bzw. unbelegt und brauchen die
     * Admin-Freigabe. Public static, damit AddonUpdateService und Tests
     * dieselbe Definition verwenden können.
     */
    public static function isReleaseTagSource(?string $source): bool {
        return is_string($source)
            && preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]*/[A-Za-z0-9][A-Za-z0-9._-]*@v\d+\.\d+\.\d+$#', $source) === 1;
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

        // Pflicht-Obergrenze der unterstützten Kern-Version (#197, Stufe 2):
        // Jedes Addon muss die höchste bekannte unterstützte Kern-Linie
        // ausweisen - fehlt die Angabe, wird das Manifest abgewiesen
        // (Installation und Laden verweigert, fail-closed; sichtbar mit
        // Begründung in /admin/plugins und auf der Update-Seite).
        if (empty($manifest['core_supported_max'])
            || !is_string($manifest['core_supported_max'])
            || !preg_match('/^\d+\.\d+$/', $manifest['core_supported_max'])) {
            return "Pflichtfeld 'core_supported_max' fehlt oder ist keine Major.Minor-Angabe wie \"0.4\" (höchste unterstützte Kern-Linie, siehe docs/plugin-development.md).";
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
     * Prüft eine Kompatibilitäts-Angabe wie ">=0.1.0-beta.1" gegen eine
     * konkrete Kern-Version. Bewusst genau EIN Operator + eine Version -
     * Bereichs-Syntax wäre fail-closed inkompatibel (#197 dokumentiert das;
     * die Obergrenze ist deshalb das eigene Feld core_supported_max).
     */
    public static function constraintSatisfied(string $constraint, string $coreVersion): bool {
        $constraint = trim($constraint);
        if ($constraint === '' || $coreVersion === '') {
            return false;
        }

        if (!preg_match('/^(>=|<=|>|<|=)?\s*([0-9][0-9A-Za-z.\-]*)$/', $constraint, $m)) {
            return false;
        }

        $operator = $m[1] !== '' ? $m[1] : '=';
        $required = $m[2];

        return version_compare($coreVersion, $required, $operator);
    }

    /**
     * Passt das Manifest zu der gegebenen Kern-Version? Prüft die Untergrenze
     * (core_compatibility, Ein-Operator-Format) und - falls deklariert - die
     * Obergrenze core_supported_max ("X.Y": höchste unterstützte
     * Major.Minor-Linie des Kerns). Parametrisiert, damit die Update-Seite
     * gegen die ZIELversion eines anstehenden Kern-Updates prüfen kann,
     * nicht nur gegen die laufende (#197).
     */
    public static function manifestSupports(array $manifest, string $coreVersion): bool {
        return self::incompatibilityReason($manifest, $coreVersion) === null;
    }

    /**
     * Wie manifestSupports(), liefert aber die Begründung der Ablehnung -
     * für Admin-Übersichten und die Warnungen vor einem Kern-Update.
     *
     * @return string|null null = kompatibel
     */
    public static function incompatibilityReason(array $manifest, string $coreVersion): ?string {
        if ($coreVersion === '') {
            return 'Kern-Version unbekannt (CORE_VERSION nicht definiert).';
        }

        $constraint = (string)($manifest['core_compatibility'] ?? '');
        if (!self::constraintSatisfied($constraint, $coreVersion)) {
            return "benötigt Kern {$constraint}, geprüft gegen {$coreVersion}";
        }

        $max = $manifest['core_supported_max'] ?? null;
        if (is_string($max) && preg_match('/^(\d+)\.(\d+)$/', $max, $m)) {
            $parts = explode('.', $coreVersion);
            $coreMajor = (int)($parts[0] ?? 0);
            $coreMinor = (int)($parts[1] ?? 0);
            $maxMajor = (int)$m[1];
            $maxMinor = (int)$m[2];
            if ($coreMajor > $maxMajor || ($coreMajor === $maxMajor && $coreMinor > $maxMinor)) {
                return "unterstützt höchstens Kern {$max}, geprüft gegen {$coreVersion}";
            }
        }

        return null;
    }

    private function loadEnabledStates(): void {
        try {
            $db = Database::getInstance();
            // dir_stamp (#224) und source (#212) gehören mit zur Freigabe-Baseline:
            // der Stempel entscheidet, ob der SHA-256 überhaupt berechnet werden
            // muss, die Herkunft, ob ein Versionswechsel automatisch akzeptiert
            // werden darf. Beide Spalten legt die versionierte Migration an
            // (App\Service\SchemaMigrator), die vor jeder Query dieser Verbindung läuft.
            $stmt = $db->query("SELECT slug, installed_version, content_hash, dir_stamp, source FROM plugins WHERE enabled = 1");
            $rows = $stmt->fetchAll();
            $this->enabledSlugs = array_column($rows, 'slug');
            foreach ($rows as $row) {
                $this->approvedVersions[$row['slug']] = $row['installed_version'];
                $this->approvedHashes[$row['slug']] = $row['content_hash'];
                $this->approvedDirStamps[$row['slug']] = $row['dir_stamp'];
                $this->sources[$row['slug']] = $row['source'];
            }
        } catch (\Throwable $e) {
            // Fail-closed: Ohne DB-Zugriff bleiben alle Plugins deaktiviert (Ausfallsicherheit
            // für den Kern hat Vorrang vor Plugin-Funktionalität).
            $this->enabledSlugs = [];
            $this->approvedVersions = [];
            $this->approvedHashes = [];
            $this->approvedDirStamps = [];
            $this->sources = [];
        }
    }

    /**
     * Lädt und registriert nur Plugins, die aktiviert UND kompatibel UND fehlerfrei
     * validiert sind. Unterscheidet dabei die Fälle bei abweichendem Code
     * (siehe Klassen-PHPDoc, "eindeutige Kennung pro Plugin-Version"):
     * reguläres Update aus einem Release-Tag (neue Manifest-Version + Herkunft
     * `owner/repo@vX.Y.z` -> automatisch akzeptiert, #212) vs. Versionswechsel
     * ohne belegte Release-Herkunft bzw. unverändert deklarierte Version mit
     * abweichendem Fingerabdruck (beides fail-closed, erneute Admin-Freigabe
     * nötig). Der teure SHA-256-Vergleich läuft dabei nur noch, wenn der
     * billige Verzeichnis-Stempel von der Freigabe-Baseline abweicht (#224).
     * Jedes Plugin wird einzeln in try/catch isoliert geladen, damit ein
     * defektes Plugin nicht den gesamten Bootstrap-Vorgang verhindert.
     */
    private function loadEnabledPlugins(): void {
        foreach ($this->discovered as $slug => $info) {
            if ($info['error'] !== null || !$info['compatible']) {
                continue;
            }
            if (!in_array($slug, $this->enabledSlugs, true)) {
                continue;
            }

            $currentVersion = (string)($info['manifest']['version'] ?? '');
            $approvedVersion = $this->approvedVersions[$slug] ?? null;
            $approvedHash = $this->approvedHashes[$slug] ?? null;

            if ($approvedVersion !== null && $currentVersion !== '' && $currentVersion !== $approvedVersion) {
                // Manifest weist eine neue Versionsnummer aus. Automatisch akzeptiert
                // wird das nur, wenn der Stand nachweislich aus einem unveränderlichen
                // Release-Tag stammt (#212) - sonst wäre das Erhöhen der Manifest-
                // Version ein trivialer Umweg um die Fingerabdruck-Kette.
                if (self::isReleaseTagSource($this->sources[$slug] ?? null)) {
                    $this->acceptPluginUpdate($slug, $currentVersion, $this->fingerprintOf($slug), $this->dirStampOf($slug));
                    AuditLogger::log(
                        "Plugin automatisch aktualisiert",
                        "plugin",
                        "Slug: {$slug}, Version {$approvedVersion} -> {$currentVersion} (Versionsnummer im Manifest erhöht, Herkunft "
                        . ($this->sources[$slug] ?? '?') . " ist ein Release-Tag, automatisch akzeptiert)."
                    );
                } else {
                    // Fail-closed: manuell kopierte oder aus einem Branch-Stand
                    // installierte Plugins brauchen für einen Versionswechsel die
                    // ausdrückliche Freigabe eines Admins (siehe setEnabled()).
                    $this->needsReapproval[$slug] = true;
                    AuditLogger::log(
                        "Plugin-Update ohne Release-Herkunft",
                        "plugin",
                        "Slug: {$slug}, Version {$approvedVersion} -> {$currentVersion} - Herkunft '"
                        . ($this->sources[$slug] ?? 'manuell/unbekannt')
                        . "' ist kein Release-Tag (owner/repo@vX.Y.z). Wurde für diesen Request nicht geladen. Erneute Freigabe über /admin/plugins erforderlich."
                    );
                    continue;
                }
            } else {
                // Versionsnummer unverändert: Stimmt der billige Verzeichnis-Stempel
                // mit der Freigabe-Baseline überein, gilt der Code als unverändert und
                // der SHA-256 über alle Dateien entfällt komplett (#224). Der Stempel
                // ist nur zusammen mit einem vorhandenen content_hash belastbar - eine
                // Zeile ohne Hash bleibt fail-closed im Hash-Zweig.
                $currentStamp = $this->dirStampOf($slug);
                $approvedStamp = $this->approvedDirStamps[$slug] ?? null;
                $stampMatches = $approvedHash !== null && $approvedStamp !== null && $approvedStamp === $currentStamp;

                if (!$stampMatches) {
                    if ($approvedHash === null || $approvedHash !== $this->fingerprintOf($slug)) {
                        // Freigabe noch nie mit Fingerabdruck erfolgt oder der Code
                        // weicht ab - untypisch für ein reguläres Update, typisch für
                        // einen unter demselben Slug ausgetauschten Plugin-Ordner.
                        // Fail-closed: NICHT laden, bis ein Admin die aktuelle Version
                        // explizit erneut freigibt (siehe setEnabled()).
                        $this->needsReapproval[$slug] = true;
                        AuditLogger::log(
                            "Plugin-Code seit Aktivierung geändert",
                            "plugin",
                            "Slug: {$slug} - gleiche Version ({$currentVersion}), aber abweichender Code. Wurde für diesen Request nicht geladen. Erneute Freigabe über /admin/plugins erforderlich."
                        );
                        continue;
                    }

                    // Inhalt per vollem Hash nachweislich unverändert, nur der Stempel
                    // fehlt (Bestandszeile von vor dir_stamp) oder weicht ab (z. B.
                    // Deployment mit frischen mtimes bei identischem Inhalt): Stempel
                    // mitschreiben, damit der nächste Request wieder ohne SHA-256 auskommt.
                    $this->persistDirStamp($slug, $currentStamp);
                }
            }

            try {
                $this->loadPlugin($slug, $info);
            } catch (\Throwable $e) {
                AuditLogger::log("Plugin konnte nicht geladen werden: {$slug}", "plugin", $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            }
        }
    }

    /**
     * Akzeptiert ein reguläres Plugin-Update (neue Manifest-Version aus einem
     * Release-Tag, siehe loadEnabledPlugins()) automatisch, ohne dass ein Admin
     * erneut aktiv werden muss.
     */
    private function acceptPluginUpdate(string $slug, string $version, ?string $fingerprint, ?string $dirStamp): void {
        $this->approvedVersions[$slug] = $version;
        $this->approvedHashes[$slug] = $fingerprint;
        $this->approvedDirStamps[$slug] = $dirStamp;

        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("UPDATE plugins SET installed_version = ?, content_hash = ?, dir_stamp = ? WHERE slug = ?");
            $stmt->execute([$version, $fingerprint, $dirStamp, $slug]);
        } catch (\Throwable $e) {
            // Schreibfehler blockiert das Laden für DIESEN Request nicht - beim nächsten
            // Request wird die Aktualisierung erneut versucht (idempotent).
        }
    }

    /**
     * Schreibt den aktuellen Verzeichnis-Stempel als neue Baseline (#224) -
     * ausschließlich, nachdem der volle SHA-256-Vergleich den Inhalt als
     * unverändert bestätigt hat (siehe loadEnabledPlugins()).
     */
    private function persistDirStamp(string $slug, ?string $dirStamp): void {
        $this->approvedDirStamps[$slug] = $dirStamp;

        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("UPDATE plugins SET dir_stamp = ? WHERE slug = ?");
            $stmt->execute([$dirStamp, $slug]);
        } catch (\Throwable $e) {
            // Schreibfehler ist unkritisch: Der nächste Request rechnet dann eben
            // erneut den vollen Hash - nur Performance, keine Sicherheitsfrage.
        }
    }

    /**
     * True, wenn ein Plugin aktiviert ist, sein aktueller Code-Fingerabdruck bei
     * unveränderter Manifest-Version aber vom zuletzt freigegebenen abweicht - wird
     * deshalb für den aktuellen Request NICHT geladen, bis ein Admin es erneut
     * freigibt. Für die Admin-UI (/admin/plugins).
     */
    public function needsReapproval(string $slug): bool {
        return $this->needsReapproval[$slug] ?? false;
    }

    /**
     * Lädt die Einstiegsdatei eines Plugins (entry-Whitelist, Datei-Existenz,
     * Klassen-Konvention - siehe Kommentare unten) und liefert eine frische
     * Instanz der Plugin-Klasse. Gemeinsame Grundlage für loadPlugin() und
     * runInstallHook(); null (mit Audit-Log-Eintrag), wenn Einstiegspunkt oder
     * Klasse fehlen bzw. unzulässig sind.
     *
     * @param array{slug:string, dir:string, manifest:array, error:?string, compatible:bool, incompatible_reason:?string, fingerprint:?string, dir_stamp:?string} $info
     */
    private function instantiatePlugin(string $slug, array $info): ?object {
        // Der optionale Manifest-Eintrag `entry` wird gleich per require_once
        // geladen - daher strikt auf einen einfachen Dateinamen im Plugin-Ordner
        // begrenzen (keine Pfadtrenner, kein "..") . Ein aktiviertes Plugin ist
        // zwar ohnehin vertrauenswürdiger PHP-Code, aber so kann ein manipuliertes
        // Manifest nie eine Datei außerhalb seines eigenen Verzeichnisses einbinden.
        $entry = (string)($info['manifest']['entry'] ?? 'Plugin.php');
        if (!preg_match('/^[A-Za-z0-9._-]+\.php$/', $entry)) {
            AuditLogger::log("Plugin-Einstiegspunkt ungültig: {$slug}", "plugin", "Unzulässiger entry-Wert im Manifest: {$entry}");
            return null;
        }

        $entryFile = rtrim($info['dir'], '/') . '/' . $entry;
        if (!file_exists($entryFile)) {
            AuditLogger::log("Plugin-Einstiegspunkt fehlt: {$slug}", "plugin", "Erwartet: {$entryFile}");
            return null;
        }

        require_once $entryFile;

        $className = $this->resolvePluginClassName($slug);
        if (!class_exists($className)) {
            AuditLogger::log("Plugin-Klasse nicht gefunden: {$slug}", "plugin", "Erwartet: {$className}");
            return null;
        }

        return new $className();
    }

    /**
     * @param array{slug:string, dir:string, manifest:array, error:?string, compatible:bool, incompatible_reason:?string, fingerprint:?string, dir_stamp:?string} $info
     */
    private function loadPlugin(string $slug, array $info): void {
        $instance = $this->instantiatePlugin($slug, $info);
        if ($instance === null) {
            return;
        }

        // Mehrsprachigkeit (#48, #56): Konvention statt Manifest-Pflicht, analog
        // zum Default-Entry "Plugin.php" - ein optionales lang/-Verzeichnis im
        // Plugin-Ordner wird automatisch unter dem Plugin-Slug als eigene
        // Übersetzungs-Domain registriert (siehe App\I18n\Translator::registerDomain()).
        $langDir = rtrim($info['dir'], '/') . '/lang';
        if (is_dir($langDir)) {
            \App\I18n\Translator::registerDomain($slug, $langDir);
        }

        // Die andere Richtung (#344): ein Verzeichnis `lang/core/` bedeutet
        // "ich bringe zusaetzliche Sprachen fuer die KERN-Domaene mit". Auch
        // das Konvention statt Manifest-Pflicht - der Dateiname ist der
        // Sprachcode, mehr braucht es nicht.
        $coreLangDir = $langDir . '/core';
        if (is_dir($coreLangDir)) {
            foreach (glob($coreLangDir . '/*.php') ?: [] as $datei) {
                \App\I18n\Translator::registerCoreLocale(basename($datei, '.php'), $coreLangDir);
            }
        }

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

        if (method_exists($instance, 'features')) {
            foreach ((array)$instance->features() as $entry) {
                $this->registerPluginFeature($entry);
            }
        }

        // Formulare, die einen Spam-Schutz bekommen sollen (#351). Die
        // oeffentlichen Formulare dieses Systems liegen ueberwiegend in
        // Addons - Kontaktanfrage, Deckanfrage, Verkaufsboerse. Genau die,
        // die Spam bekommen, konnten den vorhandenen Captcha-Unterbau bis
        // v0.7 nicht nutzen, weil sich kein Kontext anmelden liess.
        //
        //     public function captchaContexts(): array {
        //         return ['kontaktanfrage' => 'Kontaktanfrage an einen Kontakt'];
        //     }
        if (method_exists($instance, 'captchaContexts')) {
            foreach ((array)$instance->captchaContexts() as $key => $label) {
                if (is_string($key) && is_string($label)) {
                    \App\Security\CaptchaContext::register($key, $label);
                }
            }
        }
    }

    /**
     * Verarbeitet einen Eintrag aus der optionalen Plugin::features()-Methode und
     * meldet ihn bei App\Permission\FeatureRegistry an (#57): Zusatzfunktionen mit
     * admin-konfigurierbarer Sichtbarkeit (öffentlich vs. nur für Gruppen mit
     * Leseberechtigung, siehe /admin/system-settings). Erwartetes Format je
     * Eintrag: ['key' => string, 'label' => string,
     * 'default_visibility' => 'public'|'members' (optional, Default 'members')].
     * Ungültige Einträge werden ignoriert, konsistent mit
     * registerPluginPermission().
     */
    private function registerPluginFeature(mixed $entry): void {
        if (!is_array($entry) || empty($entry['key']) || empty($entry['label'])) {
            return;
        }

        \App\Permission\FeatureRegistry::register(
            (string)$entry['key'],
            (string)$entry['label'],
            isset($entry['default_visibility']) ? (string)$entry['default_visibility'] : \App\Permission\FeatureRegistry::VISIBILITY_MEMBERS
        );
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
     * fingerprint/dir_stamp sind hier null, solange sie in diesem Request noch
     * nicht gebraucht wurden (lazy, #224 - siehe fingerprintOf()/dirStampOf()).
     *
     * @return array<string, array{slug:string, dir:string, manifest:array, error:?string, compatible:bool, incompatible_reason:?string, fingerprint:?string, dir_stamp:?string}>
     */
    public function getDiscoveredPlugins(): array {
        return $this->discovered;
    }

    public function isEnabled(string $slug): bool {
        return in_array($slug, $this->enabledSlugs, true);
    }

    /**
     * Aktiviert/deaktiviert ein Plugin. Wirkt erst ab dem nächsten Request
     * (kein Hot-Reload nötig, PHP lädt ohnehin pro Request neu). Bei
     * Aktivierung wird zusätzlich der optionale install()-Hook des Plugins
     * aufgerufen (Addons#75, siehe runInstallHook()).
     */
    public function setEnabled(string $slug, bool $enabled): void {
        if (!isset($this->discovered[$slug])) {
            throw new \InvalidArgumentException("Unbekanntes Plugin: {$slug}");
        }

        $db = Database::getInstance();
        $version = (string)($this->discovered[$slug]['manifest']['version'] ?? '0.0.0');
        // Bei (erneuter) Aktivierung wird die aktuell vorgefundene Version + ihr
        // Fingerabdruck + der Verzeichnis-Stempel (#224) als neue Freigabe-Baseline
        // gespeichert (siehe Klassen-PHPDoc) - deckt sowohl die Erstaktivierung
        // als auch eine bewusste manuelle Re-Freigabe nach einer als verdächtig
        // erkannten Änderung ab. `source` bleibt bewusst unangetastet - die
        // Herkunft schreibt ausschließlich der Store/AddonUpdateService.
        $hash = $enabled ? $this->fingerprintOf($slug) : null;
        $dirStamp = $enabled ? $this->dirStampOf($slug) : null;

        $stmt = $db->prepare(
            "INSERT INTO plugins (slug, enabled, installed_version, content_hash, dir_stamp, activated_at) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), installed_version = VALUES(installed_version), content_hash = VALUES(content_hash), dir_stamp = VALUES(dir_stamp), activated_at = VALUES(activated_at)"
        );
        $stmt->execute([$slug, $enabled ? 1 : 0, $version, $hash, $dirStamp, $enabled ? date('Y-m-d H:i:s') : null]);

        // Installations-Hook (Addons#75): läuft bewusst NACH dem Persistieren
        // der Freigabe - ein fehlschlagender Hook nimmt dem Admin nicht die
        // gerade erteilte Aktivierung wieder weg (Fehler landet im Audit-Log).
        if ($enabled) {
            $this->runInstallHook($slug);
        }
    }

    /**
     * Ruft den optionalen install()-Hook eines Plugins auf (Addons#75): der
     * vorgesehene Ort für einmalige Einrichtungsarbeiten wie das Anlegen
     * eigener Tabellen - statt DDL in register(), das bei JEDEM Request liefe
     * (siehe docs/plugin-development.md, "Installation & Migrationen").
     *
     * Aufrufer: setEnabled(..., true) bei jeder (Re-)Aktivierung und der
     * AddonUpdateService nach einem eingespielten Addon-Update. install() muss
     * deshalb idempotent sein (z. B. CREATE TABLE IF NOT EXISTS) - der Hook
     * garantiert "mindestens einmal nach Installation/Update", nicht "genau
     * einmal". Plugins ohne install()-Methode sind unverändert gültig (No-Op).
     *
     * Fehler im Hook werden abgefangen und im Audit-Log protokolliert - sie
     * verhindern weder die Aktivierung noch das Update; das Plugin meldet
     * Folgefehler dann bei der ersten Nutzung, statt die Admin-Aktion
     * kommentarlos abzubrechen.
     */
    public function runInstallHook(string $slug): void {
        $info = $this->discovered[$slug] ?? null;
        if ($info === null || $info['error'] !== null) {
            return;
        }

        try {
            $instance = $this->instantiatePlugin($slug, $info);
            if ($instance !== null && method_exists($instance, 'install')) {
                $instance->install();
            }
        } catch (\Throwable $e) {
            AuditLogger::log(
                "Plugin-install()-Hook fehlgeschlagen: {$slug}",
                "plugin",
                $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
            );
        }
    }

    /**
     * Installationswurzel - Basis für die Pfadprüfung im Datenregister.
     */
    private function wurzel(): string {
        return dirname(__DIR__, 2);
    }

    /**
     * Was gehört diesem Addon? Geprüftes Register aus der plugin.json (#338).
     *
     * @return array{tables:string[], directories:string[], settings:string[], abgelehnt:string[]}
     */
    public function datenRegister(string $slug): array {
        $info = $this->discovered[$slug] ?? null;
        $manifest = is_array($info) ? ($info['manifest'] ?? []) : [];
        return PluginDataRegistry::fuer(is_array($manifest) ? $manifest : [], $this->wurzel());
    }

    /**
     * Was würde eine Deinstallation MIT Datenlöschung tatsächlich entfernen -
     * mit Zeilen- und Dateizahlen, damit die Rückfrage an den Betreiber eine
     * Zahl nennen kann statt eines Tabellennamens (#338).
     */
    public function deinstallationsVorschau(string $slug): array {
        return PluginDataRegistry::vorschau($this->datenRegister($slug));
    }

    /**
     * Deinstalliert ein Addon (#338).
     *
     * DEAKTIVIEREN UND DEINSTALLIEREN SIND ZWEI DINGE. Deaktivieren ist
     * umkehrbar und lässt alles stehen - man tut es, um einen Fehler
     * einzugrenzen. Deinstallieren fragt nach den Daten, und genau diese Frage
     * gab es bis v0.7 nicht: Ein Addon verschwand aus dem Verzeichnis und
     * liess seine Tabellen liegen, darunter Kontaktanfragen mit Namen und
     * E-Mail-Adressen. Der Betreiber nahm an, er sei sie los.
     *
     * REIHENFOLGE. Erst der uninstall()-Hook des Addons (es weiss Dinge, die
     * kein Register aufzählen kann), dann das Register. Beides NUR, wenn
     * $datenLoeschen gesetzt ist - sonst wird lediglich deaktiviert und der
     * Bestand bleibt unangetastet.
     *
     * SICHERUNG. Vor dem Löschen wird gesichert, sofern eine Sicherung
     * eingerichtet ist. Nicht als Bequemlichkeit: Das hier ist die einzige
     * Stelle im Kern, an der auf Knopfdruck Nutzdaten unwiederbringlich
     * verschwinden, und "ich wollte nur aufräumen" ist der häufigste Anlass
     * für so einen Klick.
     *
     * @param bool $datenLoeschen false = nur deaktivieren, Daten behalten.
     *
     * @return string[] Menschenlesbares Protokoll dessen, was geschehen ist.
     */
    public function uninstall(string $slug, bool $datenLoeschen): array {
        if (!isset($this->discovered[$slug])) {
            throw new \InvalidArgumentException("Unbekanntes Plugin: {$slug}");
        }

        $protokoll = [];

        if ($this->isEnabled($slug)) {
            $this->setEnabled($slug, false);
            $protokoll[] = 'Addon deaktiviert.';
        }

        if (!$datenLoeschen) {
            $protokoll[] = 'Daten behalten - Tabellen, Dateien und Einstellungen bleiben unverändert stehen.';
            AuditLogger::log('Addon deinstalliert (Daten behalten)', 'plugin', "Slug: {$slug}");
            return $protokoll;
        }

        // 1. Sicherung, solange noch alles da ist.
        try {
            if (\App\Service\BackupService::isConfigured($this->settingsFuerSicherung())) {
                \App\Service\BackupService::run();
                $protokoll[] = 'Sicherung vor dem Löschen ausgeführt.';
            } else {
                $protokoll[] = 'HINWEIS: Keine Sicherung eingerichtet - es wurde ohne Sicherung gelöscht.';
            }
        } catch (\Throwable $e) {
            // Eine gescheiterte Sicherung darf das Löschen NICHT stillschweigend
            // durchwinken - aber auch nicht dauerhaft blockieren. Sie wird
            // gemeldet, und der Betreiber sieht es im Protokoll.
            $protokoll[] = 'WARNUNG: Sicherung fehlgeschlagen (' . $e->getMessage() . ') - trotzdem gelöscht.';
        }

        // 2. Der Hook des Addons zuerst: Er kennt Dinge, die kein Register
        //    aufzählen kann (etwa Zeilen in einer Kern-Tabelle).
        $info = $this->discovered[$slug];
        if ($info['error'] === null) {
            try {
                $instance = $this->instantiatePlugin($slug, $info);
                if ($instance !== null && method_exists($instance, 'uninstall')) {
                    $instance->uninstall();
                    $protokoll[] = 'uninstall()-Hook des Addons ausgeführt.';
                }
            } catch (\Throwable $e) {
                $protokoll[] = 'WARNUNG: uninstall()-Hook fehlgeschlagen: ' . $e->getMessage();
            }
        }

        // 3. Das Register.
        $register = $this->datenRegister($slug);
        $pdo = Database::getInstance();

        foreach ($register['tables'] as $tabelle) {
            try {
                // Name stammt aus dem geprüften Register (Präfix erzwungen).
                $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $tabelle) . '`');
                $protokoll[] = "Tabelle {$tabelle} entfernt.";
            } catch (\Throwable $e) {
                $protokoll[] = "WARNUNG: Tabelle {$tabelle} liess sich nicht entfernen: " . $e->getMessage();
            }
        }

        foreach ($register['settings'] as $schluessel) {
            try {
                $stmt = $pdo->prepare('DELETE FROM settings WHERE setting_key = ?');
                $stmt->execute([$schluessel]);
                if ($stmt->rowCount() > 0) {
                    $protokoll[] = "Einstellung {$schluessel} entfernt.";
                }
            } catch (\Throwable $e) {
                $protokoll[] = "WARNUNG: Einstellung {$schluessel}: " . $e->getMessage();
            }
        }

        foreach ($register['directories'] as $verzeichnis) {
            if (self::verzeichnisLoeschen($verzeichnis)) {
                $protokoll[] = 'Verzeichnis ' . basename($verzeichnis) . ' entfernt.';
            } else {
                $protokoll[] = 'WARNUNG: Verzeichnis ' . basename($verzeichnis) . ' nicht vollständig entfernt.';
            }
        }

        foreach ($register['abgelehnt'] as $grund) {
            // Nicht verschweigen: Was das Register nicht durchlassen konnte,
            // bleibt liegen - und der Betreiber muss wissen, dass da noch
            // etwas ist.
            $protokoll[] = 'NICHT gelöscht: ' . $grund;
        }

        AuditLogger::log(
            'Addon deinstalliert (Daten gelöscht)',
            'plugin',
            "Slug: {$slug} - " . implode(' | ', $protokoll)
        );

        return $protokoll;
    }

    /**
     * Die Einstellungen, die BackupService::isConfigured() erwartet.
     */
    private function settingsFuerSicherung(): array {
        try {
            $rows = Database::getInstance()
                ->query('SELECT setting_key, setting_value FROM settings')
                ->fetchAll(\PDO::FETCH_KEY_PAIR);
            return is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Verzeichnis samt Inhalt entfernen. Symlinks werden entfernt, nicht
     * verfolgt - sonst räumte eine Deinstallation über einen Symlink hinaus
     * auf, und das Register hat nur den Symlink geprüft.
     */
    private static function verzeichnisLoeschen(string $pfad): bool {
        if (!is_dir($pfad)) {
            return true;
        }
        $ok = true;
        $eintraege = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pfad, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($eintraege as $eintrag) {
            /** @var \SplFileInfo $eintrag */
            $ok = ($eintrag->isDir() && !$eintrag->isLink() ? @rmdir($eintrag->getPathname()) : @unlink($eintrag->getPathname())) && $ok;
        }
        return @rmdir($pfad) && $ok;
    }
}
