<?php
// src/Service/UpdateService.php

namespace App\Service;

use App\Database;

/**
 * Class UpdateService
 *
 * Automatisches Update des Frameworks (#85): prüft die GitHub-Releases des
 * Projekts auf eine neuere Version als CORE_VERSION und kann das bereinigte
 * Shared-Hosting-Release-Zip (siehe .github/workflows/release.yml und
 * docs/releasing.md) herunterladen und über die laufende Installation legen.
 *
 * Sicherheits-/Datenschutz-Leitplanken:
 * - **Pflicht-Backup:** Ein Update läuft NIE ohne unmittelbar zuvor
 *   erfolgreiches externes Backup (BackupService::run(), #59). Ist das
 *   Backup nicht konfiguriert oder schlägt es fehl, wird das Update
 *   abgebrochen, bevor irgendeine Datei angefasst wird - die verwalteten
 *   Zucht-/Blutlinien-Daten sind teils unwiederbringlich.
 * - Lokale Konfiguration und Daten werden nie überschrieben: config/
 *   db_config.php, public/uploads/, plugins/ und .env sind vom Kopieren
 *   ausgenommen (zusätzlich zur Tatsache, dass sie im Release-Zip ohnehin
 *   fehlen).
 * - Der Kopiervorgang ist additiv (überschreibt/ergänzt Dateien, löscht
 *   keine) - Migrationsschritte übernimmt wie bisher
 *   Database::ensureSchemaUpToDate() beim nächsten Request.
 * - Anstoßbar manuell im Admin-Bereich (siehe UpdateController) oder seit
 *   #290 unbeaufsichtigt über den Scheduler (registerScheduledTask()) - der
 *   in #85 als zweiter Schritt vorgesehene Cron-Lauf. Beide Wege gehen durch
 *   performUpdate() und damit durch dieselbe Reihenfolge samt Pflicht-Backup;
 *   der unbeaufsichtigte Lauf ist ausdrücklich zu aktivieren.
 */
class UpdateService {

    /**
     * Per Umgebungsvariable UPDATE_RELEASES_URL übersteuerbar (Tests/Staging),
     * Default: die Release-LISTE dieses Projekts. Bewusst nicht der
     * "/releases/latest"-Endpunkt: der schließt Prereleases immer aus und
     * könnte den Beta-Kanal (siehe CHANNEL_BETA) nicht bedienen - die
     * Kanal-Filterung übernimmt selectBestRelease().
     */
    private const DEFAULT_RELEASES_URL = 'https://api.github.com/repos/Celestial0579/Hengstverzeichnis_Framework/releases?per_page=30';

    /**
     * Update-Kanäle (#85-Follow-up): 'stable' (Default) sieht nur reguläre
     * Releases, 'beta' zusätzlich als Prerelease markierte Vorabversionen.
     * Admin-Auswahl unter /admin/updates (Setting `update_channel`).
     */
    public const CHANNEL_STABLE = 'stable';
    public const CHANNEL_BETA = 'beta';

    /** Pfade (relativ zur Installationswurzel), die ein Update nie anfasst. */
    private const PROTECTED_PATHS = [
        'config/db_config.php',
        'public/uploads',
        'plugins',
        '.env',
    ];

    /**
     * Addons, die ein Kern-Update ausnahmsweise DOCH entfernen darf (#339).
     *
     * `plugins` steht oben unter den geschützten Pfaden, und das aus gutem
     * Grund: Ein Kern-Update, das Addon-Verzeichnisse anfasst, könnte fremden
     * Code und fremde Daten mitnehmen. Genau eine Lage braucht die Ausnahme
     * trotzdem - wenn eine Funktion aus einem Addon in den KERN wandert.
     *
     * Ab v0.8 pflegt der Kern Fotos und Videos je Pferd selbst (#339). Bliebe
     * das Galerie-Addon daneben aktiv, gäbe es zwei Pflegeoberflächen für
     * dieselben Daten, zwei Ausliefer-Routen und zwei Vorstellungen davon,
     * welches Bild das Hauptbild ist. Das Addon wird deshalb beim Update
     * DEAKTIVIERT und sein Verzeichnis entfernt.
     *
     * Die Daten bleiben. Entfernt wird ausschliesslich der Code; Tabellen,
     * Dateien und Einstellungen rührt das Update nicht an - der Kern liest sie
     * beim ersten Start ein. Wer sie loswerden will, tut das anschliessend
     * bewusst über /admin/plugins (#338).
     *
     * Die Liste ist eng und namentlich. Ein Muster (`galerie*`) stünde hier
     * nicht: Es träfe eines Tages ein Addon, an das niemand gedacht hat.
     */
    private const ABGELOESTE_ADDONS = [
        // Slug => Kern-Version, ab der das Addon abgelöst ist.
        //
        // DERZEIT LEER, und das ist eine bewusste Entscheidung. Der erste
        // Eintrag sollte 'galerie' => '0.8.0' sein (#339), und er wurde wieder
        // herausgenommen, weil die Kern-Galerie in v0.8.0 NICHT fertig wurde.
        //
        // Ein Eintrag hier ohne den zugehörigen Kern-Ersatz wäre kein halbes
        // Feature, sondern ein Schaden: Das Update entfernte das Addon, und
        // die Betreiber stünden ganz ohne Galerie da. Die Mechanik bleibt
        // trotzdem stehen - sie ist gebaut, dokumentiert und geprüft, und der
        // Eintrag ist eine Zeile, sobald #339 steht.
    ];

    /**
     * Reichweite der UNBEAUFSICHTIGTEN Installation (Setting
     * `update_auto_install_scope`, #290/#85).
     *
     * Standard ist bewusst 'patch_only': Solange die Versionierung bei 0.y.z
     * steht, sind Breaking Changes laut CHANGELOG jederzeit möglich - ein
     * Minor-Sprung ohne Aufsicht wäre damit ein Risiko, das der Betreiber
     * ausdrücklich wählen soll. Innerhalb einer Minor-Linie sagt das
     * Versionsschema Kompatibilität zu, dieselbe Zusicherung, auf der schon
     * das Addon-Autoupdate aufsetzt (AddonUpdateService::coreLine()).
     */
    public const AUTO_SCOPE_PATCH_ONLY = 'patch_only';
    public const AUTO_SCOPE_ANY = 'any';

    /** Name der Scheduler-Aufgabe, die nur PRÜFT und ggf. benachrichtigt. */
    private const TASK_CHECK = 'update.check';

    /** Name der Scheduler-Aufgabe, die unbeaufsichtigt INSTALLIERT. */
    private const TASK_AUTO_INSTALL = 'update.auto_install';

    /**
     * Prüfen ist billig (zwei HTTP-Abfragen) und soll zeitnah melden;
     * Installieren greift in die laufende Installation ein und bleibt
     * deshalb auf einen Lauf pro Tag begrenzt - ein wiederholter Versuch
     * derselben fehlschlagenden Aktualisierung bringt nichts.
     */
    private const CHECK_INTERVAL_SECONDS = 3 * 3600;
    private const AUTO_INSTALL_INTERVAL_SECONDS = 24 * 3600;

    public static function currentVersion(): string {
        return defined('CORE_VERSION') ? CORE_VERSION : '0.0.0';
    }

    public static function normalizeVersion(string $version): string {
        return ltrim(trim($version), 'vV');
    }

    public static function isNewer(string $candidate, string $current): bool {
        return version_compare(self::normalizeVersion($candidate), self::normalizeVersion($current), '>');
    }

    /**
     * Normalisiert einen Kanal-Wert; unbekannte Werte fallen auf 'stable'
     * zurück (fail-safe: nie versehentlich Vorabversionen anbieten).
     */
    public static function normalizeChannel(string $channel): string {
        return $channel === self::CHANNEL_BETA ? self::CHANNEL_BETA : self::CHANNEL_STABLE;
    }

    /**
     * Der aktuell konfigurierte Update-Kanal (Setting `update_channel`).
     */
    public static function configuredChannel(): string {
        return self::normalizeChannel((string)(self::loadSettings()['update_channel'] ?? self::CHANNEL_STABLE));
    }

    public static function normalizeAutoScope(string $scope): string {
        return $scope === self::AUTO_SCOPE_ANY ? self::AUTO_SCOPE_ANY : self::AUTO_SCOPE_PATCH_ONLY;
    }

    /**
     * Registriert die beiden Update-Aufgaben beim Scheduler (#290, schließt
     * die in #85 offen gebliebene zweite Stufe). Wird im Request-Bootstrap
     * aufgerufen, analog BackupService/DigestService.
     *
     * Beide Aufgaben sind ausdrücklich zu aktivieren - wie bei
     * BackupService und DigestService ist eine nicht konfigurierte Automatik
     * ein No-Op. Das ist hier keine Förmlichkeit: Die Prüfung ruft
     * regelmäßig GitHub ab und verschickt E-Mails; beides ungefragt bei
     * jeder bestehenden Installation aufzunehmen, wäre eine stille
     * Verhaltensänderung durch ein Update.
     *
     * Die BENACHRICHTIGUNG lässt sich unabhängig von der Installation
     * einschalten - im Container-Betrieb (UPDATE_IN_PLACE=0) ist sie sogar
     * der einzig nutzbare Teil, weil dort über ein neues Image aktualisiert
     * wird (#158). Die INSTALLATION verlangt zusätzlich, dass die
     * Installation sich selbst überschreiben darf.
     */
    public static function registerScheduledTask(): void {
        if (self::isNotifyEnabled()) {
            Scheduler::register(self::TASK_CHECK, self::CHECK_INTERVAL_SECONDS, [self::class, 'runCheckAndNotify']);
        }

        if (!self::isAutoInstallEnabled() || !self::inPlaceAllowed()) {
            return;
        }

        Scheduler::register(
            self::TASK_AUTO_INSTALL,
            self::AUTO_INSTALL_INTERVAL_SECONDS,
            [self::class, 'runAutoInstallIfEligible']
        );
    }

    public static function isNotifyEnabled(): bool {
        return (string)(self::loadSettings()['update_notify'] ?? '0') === '1';
    }

    /**
     * Die unbeaufsichtigte Installation setzt die Benachrichtigung mit
     * voraus: Wer automatisch einspielen lässt, muss erfahren, was passiert
     * ist - ein stiller Codeaustausch auf einem Produktivsystem wäre genau
     * die Art Vorgang, die niemand bemerkt, bis etwas fehlt.
     */
    public static function isAutoInstallEnabled(): bool {
        $settings = self::loadSettings();
        return (string)($settings['update_auto_install'] ?? '0') === '1'
            && (string)($settings['update_notify'] ?? '0') === '1';
    }

    public static function configuredAutoScope(): string {
        return self::normalizeAutoScope((string)(self::loadSettings()['update_auto_install_scope'] ?? self::AUTO_SCOPE_PATCH_ONLY));
    }

    /**
     * Entscheidet, ob eine gefundene Version unbeaufsichtigt eingespielt
     * werden darf. Rein und ohne Netz/DB, damit die Grenze isoliert prüfbar
     * ist - dieselbe Trennung wie bei
     * AddonUpdateService::resolveAutoUpdateRef().
     */
    public static function isEligibleForAutoInstall(string $current, string $candidate, string $scope): bool {
        if (!self::isNewer($candidate, $current)) {
            return false;
        }
        if (self::normalizeAutoScope($scope) === self::AUTO_SCOPE_ANY) {
            return true;
        }

        $currentLine = AddonUpdateService::coreLine(self::normalizeVersion($current));
        $candidateLine = AddonUpdateService::coreLine(self::normalizeVersion($candidate));

        return $currentLine !== null && $currentLine === $candidateLine;
    }

    /**
     * Aktive Addons, die die ZIELversion nicht unterstützen (#362).
     *
     * WOZU, wo die Update-Seite doch längst warnt: Sie warnt einen Menschen,
     * der davorsteht. Der unbeaufsichtigte Lauf hat keinen Menschen davor.
     *
     * Bis v0.8.0-beta.1 prüfte `runAutoInstallIfEligible()` ausschließlich die
     * Versionslinie (AUTO_SCOPE_*). Dass ein Minor-Sprung damit auch dann
     * ausblieb, wenn er Addons zerlegt hätte, war ein Nebeneffekt und keine
     * Zusicherung: Es galt nur, weil `core_supported_max` zufällig ebenfalls
     * auf Major.Minor läuft. Wer die Reichweite auf AUTO_SCOPE_ANY stellte,
     * hatte gar keinen Schutz - der Kern wurde getauscht, und sämtliche
     * Addons der alten Linie waren danach fail-closed unsichtbar. Genau in
     * diesem Zustand ist das System nach v0.8.0-beta.1 gewesen: Kern-Release
     * draußen, Addons-Release der Linie 0.8 noch nicht.
     *
     * Rein und ohne Netz/DB - dieselbe Trennung wie bei
     * isEligibleForAutoInstall(), damit die Grenze isoliert prüfbar ist.
     *
     * @param array<int, array<string, mixed>> $addonRows Zeilen aus AddonOverview::rows($ziel)
     * @return array<int, string> Menschenlesbare Gründe, leer = nichts im Weg
     */
    public static function addonsBlockingAutoInstall(array $addonRows): array {
        $gruende = [];
        foreach ($addonRows as $row) {
            // Nur AKTIVE Addons. Ein deaktiviertes läuft ohnehin nicht mit;
            // es aufzuhalten hieße, ein Update wegen etwas zu verweigern, das
            // niemand benutzt.
            if (empty($row['enabled'])) {
                continue;
            }
            $grund = $row['reasonTarget'] ?? null;
            if (!is_string($grund) || $grund === '') {
                continue;
            }
            $gruende[] = sprintf('%s: %s', (string)($row['slug'] ?? '?'), $grund);
        }
        return $gruende;
    }

    /**
     * Merkzettel, für welche Zielversion die Addon-Sperre schon gemeldet
     * wurde - damit der TÄGLICHE Lauf nicht täglich dieselbe Mail schickt.
     *
     * Bewusst ein eigener Schlüssel und nicht der von rememberNotifiedState():
     * "über diese Version wurde informiert" und "diese Version wurde wegen
     * Addons zurückgestellt" sind verschiedene Aussagen, und wer den einen
     * Merkzettel für den anderen benutzt, unterdrückt irgendwann die falsche
     * Meldung.
     */
    private const SETTING_BLOCKED_NOTIFIED = 'update_auto_install_blocked_version';

    /**
     * Vergleicht die aktuell verfügbaren Updates gegen den zuletzt gemeldeten
     * Stand und liefert nur, was NEU ist. Ohne diesen Vergleich stünde alle
     * drei Stunden dieselbe Meldung im Postfach, bis das Update eingespielt
     * ist - und würde spätestens nach dem dritten Mal ignoriert.
     *
     * Rein: kein Netz, keine DB, damit die Vergleichsregel isoliert prüfbar
     * bleibt.
     *
     * @param array<string, string> $previouslyNotifiedAddons slug => version
     * @param array<int, array{slug: string, version: string}> $currentAddonUpdates
     * @return array{coreIsNew: bool, newAddons: array<int, array{slug: string, version: string}>, nextNotifiedAddons: array<string, string>}
     */
    public static function computeNewFindings(
        ?string $previouslyNotifiedCoreVersion,
        ?string $availableCoreVersion,
        array $previouslyNotifiedAddons,
        array $currentAddonUpdates
    ): array {
        $coreIsNew = $availableCoreVersion !== null
            && $availableCoreVersion !== ''
            && $availableCoreVersion !== $previouslyNotifiedCoreVersion;

        $newAddons = [];
        $nextNotifiedAddons = [];
        foreach ($currentAddonUpdates as $addon) {
            $slug = (string)$addon['slug'];
            $version = (string)$addon['version'];

            // Der neue Stand wird IMMER vollständig aus der aktuellen Lage
            // aufgebaut, nie in den alten hineingemischt: Ein zwischenzeitlich
            // aktualisiertes oder deinstalliertes Addon verschwindet damit von
            // selbst, ohne separates Aufräumen. Meldet es später erneut ein
            // Update, ist das dann auch wieder ein neuer Fund.
            $nextNotifiedAddons[$slug] = $version;

            if (($previouslyNotifiedAddons[$slug] ?? null) !== $version) {
                $newAddons[] = ['slug' => $slug, 'version' => $version];
            }
        }

        return [
            'coreIsNew' => $coreIsNew,
            'newAddons' => $newAddons,
            'nextNotifiedAddons' => $nextNotifiedAddons,
        ];
    }

    /**
     * Prüft die GitHub-Releases gegen die laufende Version - im gewählten
     * Kanal ('stable' ohne, 'beta' mit Prereleases).
     *
     * @return array{current:string, channel:string, latest:?string, update_available:bool, zip_url:?string, html_url:?string, is_prerelease:bool}
     * @throws \RuntimeException bei Netzwerk-/API-Fehlern
     */
    public static function checkForUpdate(?string $channel = null): array {
        $channel = self::normalizeChannel($channel ?? self::configuredChannel());
        $releases = self::fetchReleases();

        $best = self::selectBestRelease($releases, $channel === self::CHANNEL_BETA, self::currentVersion());

        if ($best === null) {
            // Kein Kandidat, der STRIKT neuer ist als die installierte Version
            // (Gleichstand und ältere Releases zählen nie - kein Downgrade,
            // auch nicht nach einem Kanalwechsel von Beta zurück auf Stabil).
            $newestSeen = self::newestVersionInChannel($releases, $channel === self::CHANNEL_BETA);
            return [
                'current' => self::currentVersion(),
                'channel' => $channel,
                'latest' => $newestSeen,
                'update_available' => false,
                'zip_url' => null,
                'html_url' => null,
                'is_prerelease' => false,
            ];
        }

        // Neben dem Zip wird die vom Release-Workflow miterzeugte
        // Prüfsummendatei gesucht (SHA256SUMS.txt, siehe
        // .github/workflows/release.yml). Ohne sie wird nicht aktualisiert -
        // siehe verifyArchiveChecksum().
        $zipUrl = null;
        $zipName = null;
        $checksumsUrl = null;
        foreach ((array)($best['assets'] ?? []) as $asset) {
            $name = (string)($asset['name'] ?? '');
            if ($zipUrl === null && preg_match('/^hengstverzeichnis-framework-.*\.zip$/', $name) === 1) {
                $zipUrl = (string)($asset['browser_download_url'] ?? '');
                $zipName = $name;
                continue;
            }
            if ($checksumsUrl === null && strcasecmp($name, 'SHA256SUMS.txt') === 0) {
                $checksumsUrl = (string)($asset['browser_download_url'] ?? '');
            }
        }

        return [
            'current' => self::currentVersion(),
            'channel' => $channel,
            'latest' => self::normalizeVersion((string)$best['tag_name']),
            'update_available' => true,
            'zip_url' => $zipUrl !== '' && $zipUrl !== null ? $zipUrl : null,
            'zip_name' => $zipName,
            'checksums_url' => $checksumsUrl !== '' && $checksumsUrl !== null ? $checksumsUrl : null,
            'html_url' => isset($best['html_url']) ? (string)$best['html_url'] : null,
            'is_prerelease' => !empty($best['prerelease']),
        ];
    }

    /**
     * Wählt aus einer Release-Liste den besten Update-Kandidaten: Drafts sind
     * nie zulässig, Prereleases nur mit Beta-Opt-in, und es zählen
     * ausschließlich Versionen, die STRIKT neuer sind als die installierte -
     * ein Downgrade (oder Neuinstallieren derselben Version) ist damit
     * konstruktionsbedingt unmöglich, unabhängig davon, was die Release-API
     * liefert oder wie der Kanal gewechselt wird. Öffentlich und ohne
     * Netzwerkzugriff, damit die Auswahl-Logik isoliert testbar ist.
     *
     * @param array<int, array<string, mixed>> $releases
     * @return array<string, mixed>|null Das beste Release oder null
     */
    public static function selectBestRelease(array $releases, bool $includePrereleases, string $currentVersion): ?array {
        $best = null;
        $bestVersion = null;

        foreach ($releases as $release) {
            if (!is_array($release) || !empty($release['draft'])) {
                continue;
            }
            if (!empty($release['prerelease']) && !$includePrereleases) {
                continue;
            }

            $version = self::normalizeVersion((string)($release['tag_name'] ?? ''));
            if ($version === '' || !self::isNewer($version, $currentVersion)) {
                continue;
            }
            if ($bestVersion === null || self::isNewer($version, $bestVersion)) {
                $best = $release;
                $bestVersion = $version;
            }
        }

        return $best;
    }

    /**
     * Höchste im Kanal sichtbare Version (nur zur Anzeige "neuestes Release"
     * auf der Update-Seite, wenn kein Update ansteht).
     *
     * @param array<int, array<string, mixed>> $releases
     */
    private static function newestVersionInChannel(array $releases, bool $includePrereleases): ?string {
        $newest = null;
        foreach ($releases as $release) {
            if (!is_array($release) || !empty($release['draft'])) {
                continue;
            }
            if (!empty($release['prerelease']) && !$includePrereleases) {
                continue;
            }
            $version = self::normalizeVersion((string)($release['tag_name'] ?? ''));
            if ($version === '') {
                continue;
            }
            if ($newest === null || self::isNewer($version, $newest)) {
                $newest = $version;
            }
        }
        return $newest;
    }

    /**
     * Führt das Update durch. Reihenfolge bewusst fail-fast:
     * 1. Backup konfiguriert? (sonst Abbruch ohne Netzwerkzugriff)
     * 2. Neues Release verfügbar? (sonst Abbruch)
     * 3. Pflicht-Backup ausführen (Abbruch bei Fehler)
     * 4. Release-Zip herunterladen und anwenden
     *
     * @return array{from:string, to:string, files:int}
     * @throws \RuntimeException mit sprechender Meldung bei jedem Abbruchgrund
     */
    public static function performUpdate(): array {
        $settings = self::loadSettings();
        if (!BackupService::isConfigured($settings)) {
            throw new \RuntimeException('Update abgebrochen: Automatische Backups sind nicht (vollständig) konfiguriert. Ein Update ohne vorheriges Backup ist nicht zulässig - bitte zunächst unter /admin/backups einrichten.');
        }

        $check = self::checkForUpdate();
        if (!$check['update_available']) {
            $latestInfo = $check['latest'] !== null ? " (neuestes Release im Kanal '{$check['channel']}': {$check['latest']})" : '';
            throw new \RuntimeException("Kein Update verfügbar: Version {$check['current']} ist aktuell{$latestInfo}.");
        }

        // Doppelte Absicherung gegen Downgrades: selectBestRelease() liefert
        // bereits nur strikt neuere Versionen - dieser Guard stellt das
        // zusätzlich unmittelbar vor dem Anwenden sicher, unabhängig von der
        // Kandidaten-Auswahl (Defense in depth, niemals ein Downgrade).
        if ($check['latest'] === null || !self::isNewer($check['latest'], $check['current'])) {
            throw new \RuntimeException("Update abgebrochen: Zielversion {$check['latest']} ist nicht neuer als die installierte Version {$check['current']} - Downgrades sind nicht zulässig.");
        }

        if (empty($check['zip_url'])) {
            throw new \RuntimeException('Das gewählte Release enthält kein Shared-Hosting-Zip als Asset.');
        }

        // Pflicht-Backup: wirft bei jedem Fehler und bricht das Update damit ab.
        AuditLogger::log('Update: Pflicht-Backup wird ausgeführt', 'update', "Vor Update auf {$check['latest']}");
        BackupService::run();

        // Integritätsprüfung VOR dem Anwenden. Ein Update überschreibt den
        // gesamten Codebaum - was hier durchkommt, läuft danach als PHP.
        if (empty($check['checksums_url']) || empty($check['zip_name'])) {
            throw new \RuntimeException(
                'Update abgebrochen: Das Release enthält keine Prüfsummendatei (SHA256SUMS.txt). '
                . 'Ohne sie lässt sich nicht feststellen, ob das heruntergeladene Archiv unversehrt ist.'
            );
        }

        $zipPath = self::downloadToTempFile($check['zip_url']);

        // Ab hier wird der laufende Codebaum ausgetauscht. Bis #290 lief das
        // ohne Wartungsmodus, weil ein Update immer ein anwesender Admin
        // ausgelöst hat; seit der Lauf auch unbeaufsichtigt per Cron
        // stattfinden kann, kann jederzeit ein echter Besucher mitten in den
        // Dateiaustausch geraten - genau der Fall, für den der Marker beim
        // Datenmigrations-Addon schon existiert. Backup und Download bleiben
        // bewusst AUSSERHALB des Fensters, damit es so kurz wie möglich ist.
        // Das finally ist wesentlich: Bricht das Anwenden ab, darf die
        // Installation nicht dauerhaft auf 503 stehen bleiben.
        Maintenance::enable("Update wird eingespielt: {$check['current']} auf {$check['latest']}");
        try {
            try {
                self::verifyArchiveChecksum($zipPath, (string)$check['zip_name'], (string)$check['checksums_url']);
                $files = self::applyUpdateArchive($zipPath, self::baseDir());
            } finally {
                @unlink($zipPath);
            }

            AuditLogger::log('Update angewendet', 'update', "Von {$check['current']} auf {$check['latest']}, {$files} Dateien aktualisiert");

            // Addon-Phase (#197, Stufe 2): Nach dem Kern werden die aus dem
            // offiziellen Repo installierten Addons auf den zur ZIEL-Linie
            // passenden Release-Stand mitgezogen (Reihenfolge Backup -> Kern ->
            // Addons). Fehler einzelner Addons brechen das bereits angewendete
            // Kern-Update nicht ab - sie landen in der Ergebnisliste und über
            // AddonUpdateService im Audit-Log; PROTECTED_PATHS bleibt davon
            // unberührt (der Kern-Kopiervorgang oben fasst plugins/ nie an,
            // die Addon-Phase schreibt bewusst über ihren eigenen Weg).
            $addonPhase = AddonUpdateService::updateOfficialAddonsAfterCoreUpdate($check['latest']);
        } finally {
            Maintenance::disable();
        }

        return [
            'from' => $check['current'],
            'to' => $check['latest'],
            'files' => $files,
            'addons' => $addonPhase['results'],
            'addons_ref' => $addonPhase['ref'],
        ];
    }

    /**
     * Scheduler-Aufgabe `update.check` (alle 3 h): prüft auf neue Kern- und
     * Addon-Versionen und benachrichtigt die Admins - aber nur bei NEUEN
     * Funden (siehe computeNewFindings()). Installiert nichts.
     *
     * Ein nicht erreichbares GitHub ist hier kein Fehler des Laufs, sondern
     * schlicht "keine Aussage": Es wird nichts gemeldet und nichts
     * fortgeschrieben, der nächste Lauf versucht es erneut. Ein geworfener
     * Fehler würde den Betreiber alle drei Stunden mit einem Audit-Eintrag
     * über eine Netzstörung behelligen, die ihn nicht betrifft.
     */
    public static function runCheckAndNotify(): void {
        // Grundlage für die Addon-Seite: ohne aufgefrischten Katalog meldete
        // der Lauf ewig den Stand, den zuletzt jemand im Store abgerufen hat.
        AddonUpdateService::refreshOfficialCatalog();

        $availableCore = null;
        try {
            $check = self::checkForUpdate();
            if (!empty($check['update_available']) && $check['latest'] !== null) {
                $availableCore = (string)$check['latest'];
            }
        } catch (\Throwable $e) {
            AuditLogger::log('Update-Prüfung nicht möglich', 'update', $e->getMessage(), null, 'SYSTEM');
            return;
        }

        $currentAddonUpdates = [];
        foreach (AddonOverview::rows() as $row) {
            if (!empty($row['hasUpdate']) && is_string($row['availableVersion'])) {
                $currentAddonUpdates[] = ['slug' => (string)$row['slug'], 'version' => $row['availableVersion']];
            }
        }

        $settings = self::loadSettings();
        $previousAddons = json_decode((string)($settings['update_last_notified_addons'] ?? '{}'), true);
        $previousCore = isset($settings['update_last_notified_version']) && $settings['update_last_notified_version'] !== ''
            ? (string)$settings['update_last_notified_version']
            : null;
        $findings = self::computeNewFindings(
            $previousCore,
            $availableCore,
            is_array($previousAddons) ? $previousAddons : [],
            $currentAddonUpdates
        );

        if (!$findings['coreIsNew'] && $findings['newAddons'] === []) {
            // Nichts Neues, aber der Merkzettel wird trotzdem fortgeschrieben:
            // Verschwundene Addon-Updates müssen herausfallen, sonst gälte ein
            // später erneut auftauchendes Update fälschlich als bekannt.
            self::rememberNotifiedState($availableCore, $findings['nextNotifiedAddons']);
            return;
        }

        $recipients = self::adminRecipients();
        $sent = 0;
        if ($recipients === []) {
            AuditLogger::log(
                'Update verfügbar, aber kein Empfänger',
                'update',
                'Kein Konto in der Gruppe admin hat eine E-Mail-Adresse.',
                null,
                'SYSTEM'
            );
        } else {
            $mailer = new Mailer();
            $autoInstall = self::isAutoInstallEnabled();
            foreach ($recipients as $recipient) {
                if ($mailer->sendUpdatesAvailableNotification(
                    $recipient,
                    $findings['coreIsNew'] ? $availableCore : null,
                    $findings['newAddons'],
                    $autoInstall
                )) {
                    $sent++;
                }
            }
        }

        // Als gemeldet gilt ein Fund erst, wenn tatsächlich eine Mail
        // rausging. Andernfalls wäre ein Ausfall des Mailservers - oder ein
        // Admin-Konto ohne E-Mail-Adresse - endgültig: Der Fund stünde als
        // erledigt im Merkzettel und würde nie wieder gemeldet, auch nicht
        // nach Behebung der Ursache. Erst ein noch neueres Release löste dann
        // wieder etwas aus. Der bereinigte Stand (verschwundene Addons) wird
        // trotzdem geschrieben, nur die nicht zugestellten Funde bleiben offen.
        self::rememberNotifiedState(
            $sent > 0 || !$findings['coreIsNew'] ? $availableCore : $previousCore,
            $sent > 0
                ? $findings['nextNotifiedAddons']
                : array_diff_key(
                    $findings['nextNotifiedAddons'],
                    array_flip(array_column($findings['newAddons'], 'slug'))
                )
        );

        if ($recipients !== []) {
            $addonList = implode(', ', array_map(
                static fn(array $a): string => $a['slug'] . ' ' . $a['version'],
                $findings['newAddons']
            ));
            AuditLogger::log(
                'Update verfügbar gemeldet',
                'update',
                'Kern: ' . ($findings['coreIsNew'] ? (string)$availableCore : '—')
                . '; Addons: ' . ($addonList !== '' ? $addonList : '—')
                . "; Benachrichtigt: {$sent}/" . count($recipients),
                null,
                'SYSTEM'
            );
        }
    }

    /**
     * Scheduler-Aufgabe `update.auto_install` (täglich): spielt ein
     * verfügbares Update unbeaufsichtigt ein - die in #85 vorgesehene, aber
     * nie umgesetzte zweite Stufe.
     *
     * Verwendet unverändert performUpdate() und damit dieselbe Reihenfolge
     * wie der manuelle Knopf (Pflicht-Backup -> Kern -> Addons), inklusive
     * Wartungsmodus. Ein Fehlschlag wird gemeldet UND weitergeworfen, damit
     * Scheduler::runDue() ihn zusätzlich zentral protokolliert.
     */
    public static function runAutoInstallIfEligible(): void {
        // Erneute Prüfung zur Laufzeit statt Verlass auf die Registrierung -
        // gleiches Vorgehen wie BackupService::run()/DigestService::run().
        if (!self::isAutoInstallEnabled() || !self::inPlaceAllowed()) {
            return;
        }

        try {
            $check = self::checkForUpdate();
        } catch (\Throwable $e) {
            // Netzstörung ist kein Update-Fehlschlag - siehe runCheckAndNotify().
            AuditLogger::log('Automatisches Update: Prüfung nicht möglich', 'update', $e->getMessage(), null, 'SYSTEM');
            return;
        }

        if (empty($check['update_available']) || $check['latest'] === null) {
            return;
        }

        $scope = self::configuredAutoScope();
        if (!self::isEligibleForAutoInstall((string)$check['current'], (string)$check['latest'], $scope)) {
            // Bewusst ohne eigene Mail: Über die Version wurde bereits per
            // runCheckAndNotify() informiert, und dass sie den gewählten
            // Rahmen überschreitet, ist genau die gewollte Wirkung der
            // Einstellung - keine Störung, die gemeldet werden müsste.
            AuditLogger::log(
                'Automatisches Update übersprungen',
                'update',
                "Version {$check['latest']} liegt außerhalb der Einstellung '{$scope}' (installiert: {$check['current']}).",
                null,
                'SYSTEM'
            );
            return;
        }

        // Addon-Sperre (#362). NUR für den unbeaufsichtigten Weg - der
        // manuelle Knopf warnt namentlich und bleibt bedienbar: Wer die
        // Warnung liest und trotzdem aktualisiert, entscheidet informiert.
        // Eine Sperre auch dort ließe jeden stranden, dessen Addon nicht mehr
        // gepflegt wird.
        //
        // Hier gilt die strengere Regel: Unbeaufsichtigt wird nur eingespielt,
        // was nichts kaputtmacht. Bewusst wird NICHT nachgesehen, ob es im
        // Katalog eine passende Addon-Version gäbe - der Katalog-Cache kann
        // veraltet sein ("konnte nicht prüfen" ist nicht "geprüft"), und die
        // Addon-Phase läuft erst NACH dem Austausch des Kerns. Scheiterte sie
        // dort, stünde die Instanz bereits auf dem neuen Kern mit toten
        // Addons - also genau in dem Zustand, den diese Sperre verhindern soll.
        $blocker = self::addonsBlockingAutoInstall(
            \App\Service\AddonOverview::rows((string)$check['latest'])
        );
        if ($blocker !== []) {
            AuditLogger::log(
                'Automatisches Update zurückgestellt: Addons',
                'update',
                sprintf(
                    'Version %s würde %d aktive(s) Addon(s) deaktivieren: %s. '
                    . 'Manuell unter /admin/updates einspielbar.',
                    (string)$check['latest'],
                    count($blocker),
                    implode(' | ', $blocker)
                ),
                null,
                'SYSTEM'
            );
            self::notifyAutoInstallBlockedOnce((string)$check['latest'], $blocker);
            return;
        }

        $recipients = self::adminRecipients();
        $mailer = new Mailer();

        try {
            $result = self::performUpdate();
        } catch (\Throwable $e) {
            foreach ($recipients as $recipient) {
                $mailer->sendAutoUpdateNotification(
                    $recipient,
                    false,
                    (string)$check['current'],
                    (string)$check['latest'],
                    $e->getMessage()
                );
            }
            AuditLogger::log('Automatisches Update fehlgeschlagen', 'update', $e->getMessage(), null, 'SYSTEM');
            throw $e;
        }

        $summary = AddonUpdateService::summarizeFailures($result['addons']);
        foreach ($recipients as $recipient) {
            $mailer->sendAutoUpdateNotification(
                $recipient,
                true,
                (string)$result['from'],
                (string)$result['to'],
                null,
                $summary['reasons']
            );
        }

        AuditLogger::log(
            'Automatisches Update eingespielt',
            'update',
            "Von {$result['from']} auf {$result['to']}, {$result['files']} Dateien"
            . ($summary['reasons'] !== [] ? '; Addon-Probleme: ' . implode(' | ', $summary['reasons']) : ''),
            null,
            'SYSTEM'
        );
    }

    /**
     * Ist die Addon-Sperre für DIESE Zielversion noch zu melden? (#362)
     *
     * Rein und ohne Netz/DB - dieselbe Trennung wie bei
     * isEligibleForAutoInstall() und addonsBlockingAutoInstall(), damit die
     * Grenze isoliert prüfbar ist.
     *
     * Der Merkzettel hält GENAU EINE Version fest, nicht eine Liste. Das ist
     * Absicht: Erscheint eine neuere Zielversion, ist das eine neue Lage - die
     * betroffenen Addons können andere sein, und der Betreiber soll erneut
     * erfahren, dass sein System stehen bleibt. Eine Liste aller je gemeldeten
     * Versionen würde dagegen dazu führen, dass er nach einem Rücksprung auf
     * eine ältere Zielversion (etwa nach dem Zurückziehen eines Releases)
     * nichts mehr hört.
     */
    public static function shouldNotifyBlocked(string $zuletztGemeldet, string $zielVersion): bool {
        if ($zielVersion === '') {
            return false; // Ohne Zielversion gibt es nichts zu melden.
        }
        return $zuletztGemeldet !== $zielVersion;
    }

    /**
     * Meldet die Addon-Sperre - höchstens EINMAL je Zielversion (#362).
     *
     * Warum überhaupt gemeldet wird, wo das Überschreiten der eingestellten
     * Reichweite bewusst stumm bleibt: Die Reichweite hat der Betreiber selbst
     * gewählt, das Ausbleiben ist dort die gewollte Wirkung. Diese Sperre
     * dagegen hat er nicht gewählt. Bliebe sie stumm, hörte die Instanz auf,
     * sich zu aktualisieren, und niemand wüsste warum - der schlechteste
     * denkbare Zustand für eine Sicherheitsfunktion.
     *
     * @param array<int, string> $gruende
     */
    private static function notifyAutoInstallBlockedOnce(string $zielVersion, array $gruende): void {
        try {
            $settings = self::loadSettings();
            if (!self::shouldNotifyBlocked(
                (string)($settings[self::SETTING_BLOCKED_NOTIFIED] ?? ''),
                $zielVersion
            )) {
                return;
            }

            // Eigene Nachricht, NICHT die Fehlschlag-Variante: Es ist
            // nichts fehlgeschlagen, und ein "Update fehlgeschlagen" im
            // Postfach liesse den Betreiber nach einem Defekt suchen, den es
            // nicht gibt. Sie nennt alle vier Auswege - aktualisieren,
            // deaktivieren, entfernen, trotzdem einspielen - samt dem, was
            // jeder kostet.
            $mailer = new Mailer();
            foreach (self::adminRecipients() as $empfaenger) {
                $mailer->sendAutoUpdateBlockedNotification(
                    $empfaenger,
                    (string)(defined('CORE_VERSION') ? CORE_VERSION : ''),
                    $zielVersion,
                    $gruende
                );
            }

            Database::getInstance()->prepare(
                "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            )->execute([self::SETTING_BLOCKED_NOTIFIED, $zielVersion]);
        } catch (\Throwable $e) {
            // Eine gescheiterte Meldung darf die Sperre nicht aufheben - der
            // Protokolleintrag oben steht bereits.
            AuditLogger::log(
                'Meldung zur Addon-Sperre fehlgeschlagen',
                'update',
                $e->getMessage(),
                null,
                'SYSTEM'
            );
        }
    }

    /**
     * Schreibt fest, worüber bereits benachrichtigt wurde.
     */
    private static function rememberNotifiedState(?string $coreVersion, array $addons): void {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?"
        );

        $core = $coreVersion ?? '';
        $stmt->execute(['update_last_notified_version', $core, $core]);

        // JSON_FORCE_OBJECT: Ein leeres PHP-Array würde sonst als "[]"
        // abgelegt, ein gefülltes als Objekt - der Merkzettel ist aber immer
        // eine Zuordnung slug => version, auch wenn er gerade leer ist.
        $json = (string)json_encode($addons, JSON_FORCE_OBJECT);
        $stmt->execute(['update_last_notified_addons', $json, $json]);
    }

    /**
     * E-Mail-Adressen aller Admin-Konten. Bewusst die tatsächlichen Admins
     * statt der Einstellung `admin_notification_email`: Die ist für
     * DSGVO-Anfragen gedacht, häufig leer und fällt dann still auf eine
     * Beispieladresse zurück - eine Update-Meldung ginge damit ins Leere,
     * ohne dass es jemandem auffiele.
     *
     * @return array<int, string>
     */
    private static function adminRecipients(): array {
        try {
            $rows = Database::getInstance()->query("
                SELECT DISTINCT u.email
                FROM users u
                JOIN user_groups ug ON ug.user_id = u.id
                JOIN `groups` g ON g.id = ug.group_id
                WHERE g.slug = 'admin' AND u.deleted_at IS NULL AND u.email <> ''
            ")->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $rows)));
    }

    /**
     * Steht unter den Admin-Adressen ueberhaupt eine, die erreichbar sein
     * KANN? Fuer die Anzeige gedacht, nicht als Versand-Schranke.
     *
     * Der Anlass ist ein Befund von der Entwicklungsinstanz dieses Hosts:
     * SMTP war korrekt eingerichtet, die Benachrichtigung eingeschaltet - und
     * trotzdem erreichte keine Mail einen Menschen. Von vier Admin-Konten
     * zeigten drei auf `@migration.invalid` (Altbestand einer Datenmigration)
     * und das vierte auf eine externe Adresse, die der Mailstack dieses Hosts
     * bewusst nicht zustellt. `Mailer::isDeliverable()` sagte korrekt "ja",
     * denn der TRANSPORT stand; die Luecke lag eine Ebene weiter, bei den
     * Empfaengern.
     *
     * Geprueft wird nur, was sich sicher sagen laesst: Die Endung `.invalid`
     * ist nach RFC 2606 reserviert und ausdruecklich niemals zustellbar. Eine
     * Adresse, die nicht offensichtlich ins Leere geht, gilt hier als
     * erreichbar - ob sie es wirklich ist, weiss erst die Warteschlange des
     * Mailservers, und eine Vermutung darueber waere schlechter als keine.
     */
    public static function hasReachableAdminRecipient(): bool {
        foreach (self::adminRecipients() as $adresse) {
            if (!self::istOffensichtlichUnzustellbar($adresse)) {
                return true;
            }
        }
        return false;
    }

    /** Reservierte, per Norm nicht zustellbare Endungen (RFC 2606, RFC 6761). */
    private static function istOffensichtlichUnzustellbar(string $adresse): bool {
        $at = strrpos($adresse, '@');
        if ($at === false) {
            return true;
        }
        $domain = strtolower(rtrim(substr($adresse, $at + 1), '.'));

        foreach (['.invalid', '.test', '.example', '.localhost'] as $endung) {
            if ($domain === ltrim($endung, '.') || str_ends_with($domain, $endung)) {
                return true;
            }
        }
        return $domain === '' || !str_contains($domain, '.');
    }

    private static function inPlaceAllowed(): bool {
        return !defined('UPDATE_IN_PLACE') || UPDATE_IN_PLACE;
    }

    private static ?string $baseDirOverride = null;

    /**
     * Nur für Tests: Zielverzeichnis des Kern-Updates umbiegen (analog
     * BackupService::overrideUploadsDirForTests()), damit ein Integrationstest
     * den vollständigen performUpdate()-Ablauf fahren kann, ohne den
     * Codebaum des Arbeitsverzeichnisses zu überschreiben. `null` stellt den
     * Normalzustand wieder her.
     */
    public static function overrideBaseDirForTests(?string $dir): void {
        self::$baseDirOverride = $dir;
    }

    private static function baseDir(): string {
        return self::$baseDirOverride ?? dirname(__DIR__, 2);
    }

    /**
     * Entpackt ein Release-Zip und kopiert dessen Inhalt additiv über die
     * Installation. Erwartet das Layout des Release-Workflows (ein einzelnes
     * Wurzelverzeichnis "hengstverzeichnis-framework-<version>/"), akzeptiert
     * aber auch Archive ohne Präfix-Verzeichnis. Öffentlich und ohne
     * Netzwerkzugriff, damit die Logik isoliert testbar ist.
     *
     * @return int Anzahl kopierter Dateien
     */
    public static function applyUpdateArchive(string $zipPath, string $targetDir): int {
        $extractDir = rtrim(sys_get_temp_dir(), '/') . '/hengst_update_' . bin2hex(random_bytes(6));
        if (!mkdir($extractDir, 0755, true)) {
            throw new \RuntimeException('Temporäres Entpack-Verzeichnis konnte nicht angelegt werden.');
        }

        $backupDir = rtrim(sys_get_temp_dir(), '/') . '/hengst_update_bak_' . bin2hex(random_bytes(6));
        if (!mkdir($backupDir, 0700, true)) {
            self::removeTree($extractDir);
            throw new \RuntimeException('Temporäres Sicherungsverzeichnis konnte nicht angelegt werden.');
        }

        try {
            self::extractArchive($zipPath, $extractDir);

            // Wurzel des entpackten Codes ermitteln: entweder genau ein
            // Verzeichnis (git archive --prefix) oder direkt die Dateien.
            $entries = array_values(array_diff(scandir($extractDir) ?: [], ['.', '..']));
            $sourceDir = (count($entries) === 1 && is_dir($extractDir . '/' . $entries[0]))
                ? $extractDir . '/' . $entries[0]
                : $extractDir;

            $target = rtrim($targetDir, '/');

            // Vorabprüfung, bevor die erste Datei angefasst wird: Lässt sich
            // wirklich alles schreiben? Ein Abbruch auf halbem Weg hinterließe
            // sonst einen Mischstand aus zwei Versionen - und der Codebaum ist
            // genau das, was die Anwendung als Nächstes ausführt.
            self::assertTreeIsWritable($sourceDir, $target, '');

            // Journal: Was wurde überschrieben (mit Sicherungskopie), was neu
            // angelegt. Bricht das Kopieren trotz Vorabprüfung ab - volle
            // Platte, entzogene Rechte, Fehler im Dateisystem -, wird der
            // Ausgangszustand daraus wiederhergestellt.
            $journal = ['restore' => [], 'created' => []];

            try {
                return self::copyTree($sourceDir, $target, '', $backupDir, $journal);
            } catch (\Throwable $e) {
                self::rollback($journal);
                throw new \RuntimeException(
                    'Update abgebrochen und zurückgerollt: ' . $e->getMessage()
                    . ' - die Installation steht wieder auf dem Stand vor dem Update.',
                    0,
                    $e
                );
            }
        } finally {
            self::removeTree($extractDir);
            self::removeTree($backupDir);
        }
    }

    /**
     * Prüft vorab, ob jede Datei des Archivs an ihrem Ziel geschrieben werden
     * könnte. Wirft beim ersten Zielpfad, der sich nicht schreiben lässt.
     */
    private static function assertTreeIsWritable(string $sourceDir, string $targetDir, string $relative): void {
        $base = $sourceDir . ($relative !== '' ? '/' . $relative : '');
        $entries = array_diff(scandir($base) ?: [], ['.', '..']);

        foreach ($entries as $entry) {
            $relPath = $relative === '' ? $entry : $relative . '/' . $entry;
            if (in_array($relPath, self::PROTECTED_PATHS, true)) {
                continue;
            }

            $src = $sourceDir . '/' . $relPath;
            $dst = $targetDir . '/' . $relPath;

            if (is_dir($src)) {
                if (is_dir($dst) && !is_writable($dst)) {
                    throw new \RuntimeException("Verzeichnis ist nicht beschreibbar: {$relPath}");
                }
                if (!is_dir($dst) && !is_writable(dirname($dst))) {
                    throw new \RuntimeException("Verzeichnis kann nicht angelegt werden: {$relPath}");
                }
                self::assertTreeIsWritable($sourceDir, $targetDir, $relPath);
                continue;
            }

            if (is_dir($dst)) {
                // Im Archiv eine Datei, im Ziel ein Verzeichnis: Das lässt
                // sich nicht auflösen, und copy() würde nur eine Warnung
                // werfen und false liefern.
                throw new \RuntimeException("Im Ziel liegt ein Verzeichnis, wo das Update eine Datei erwartet: {$relPath}");
            }

            if (file_exists($dst)) {
                if (!is_writable($dst)) {
                    throw new \RuntimeException("Datei ist nicht überschreibbar: {$relPath}");
                }
            } elseif (!is_writable(dirname($dst)) && !is_dir($dst)) {
                // Übergeordnetes Verzeichnis kann in diesem Lauf erst noch
                // entstehen; dann ist es Sache des Verzeichnis-Zweigs oben.
                if (is_dir(dirname($dst))) {
                    throw new \RuntimeException("Datei kann nicht angelegt werden: {$relPath}");
                }
            }
        }
    }

    /**
     * Stellt den Zustand vor dem Kopieren wieder her.
     *
     * @param array{restore: array<int, array{0: string, 1: string}>, created: array<int, string>} $journal
     */
    private static function rollback(array $journal): void {
        foreach (array_reverse($journal['created']) as $path) {
            @unlink($path);
        }
        foreach (array_reverse($journal['restore']) as [$backup, $original]) {
            @copy($backup, $original);
        }
        AuditLogger::log(
            'Update zurückgerollt',
            'update',
            sprintf(
                '%d überschriebene Datei(en) wiederhergestellt, %d neu angelegte entfernt',
                count($journal['restore']),
                count($journal['created'])
            )
        );
    }

    /**
     * Entpackt ein Zip-Archiv - bevorzugt über die "zip"-Erweiterung
     * (ZipArchive), sonst über die praktisch immer verfügbare
     * "phar"-Erweiterung (PharData; auf manchen Shared-Hosting-/Minimal-
     * Umgebungen fehlt "zip"). PharData erkennt das Format an der
     * Dateiendung, daher wird bei Bedarf eine .zip-suffigierte Kopie genutzt.
     */
    private static function extractArchive(string $zipPath, string $extractDir): void {
        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new \RuntimeException('Release-Zip konnte nicht geöffnet werden.');
            }
            if (!$zip->extractTo($extractDir)) {
                $zip->close();
                throw new \RuntimeException('Release-Zip konnte nicht entpackt werden.');
            }
            $zip->close();
            return;
        }

        if (class_exists(\PharData::class)) {
            $pharPath = $zipPath;
            $tempCopy = null;
            if (!str_ends_with(strtolower($zipPath), '.zip')) {
                $tempCopy = $zipPath . '.zip';
                if (!copy($zipPath, $tempCopy)) {
                    throw new \RuntimeException('Release-Zip konnte nicht vorbereitet werden.');
                }
                $pharPath = $tempCopy;
            }
            try {
                (new \PharData($pharPath))->extractTo($extractDir, null, true);
            } catch (\Throwable $e) {
                throw new \RuntimeException('Release-Zip konnte nicht entpackt werden: ' . $e->getMessage());
            } finally {
                if ($tempCopy !== null) {
                    @unlink($tempCopy);
                }
            }
            return;
        }

        throw new \RuntimeException('Weder die PHP-Erweiterung "zip" noch "phar" ist verfügbar - automatisches Update nicht möglich.');
    }

    /**
     * @param array{restore: array<int, array{0: string, 1: string}>, created: array<int, string>} $journal
     */
    private static function copyTree(
        string $sourceDir,
        string $targetDir,
        string $relative,
        string $backupDir,
        array &$journal
    ): int {
        $copied = 0;
        $entries = array_diff(scandir($sourceDir . ($relative !== '' ? '/' . $relative : '')) ?: [], ['.', '..']);

        foreach ($entries as $entry) {
            $relPath = $relative === '' ? $entry : $relative . '/' . $entry;
            if (in_array($relPath, self::PROTECTED_PATHS, true)) {
                continue;
            }

            $src = $sourceDir . '/' . $relPath;
            $dst = $targetDir . '/' . $relPath;

            if (is_dir($src)) {
                $existed = is_dir($dst);
                if (!$existed && !mkdir($dst, 0755, true) && !is_dir($dst)) {
                    throw new \RuntimeException("Verzeichnis konnte nicht angelegt werden: {$relPath}");
                }
                $copied += self::copyTree($sourceDir, $targetDir, $relPath, $backupDir, $journal);
                continue;
            }

            if (is_dir($dst)) {
                throw new \RuntimeException("Im Ziel liegt ein Verzeichnis, wo das Update eine Datei erwartet: {$relPath}");
            }

            if (file_exists($dst)) {
                // Sicherungskopie in einer flachen Ablage - der relative Pfad
                // wird zum Dateinamen, damit keine Verzeichnisstruktur
                // nachgebaut werden muss.
                $backupPath = $backupDir . '/' . str_replace('/', '__', $relPath);
                if (!copy($dst, $backupPath)) {
                    throw new \RuntimeException("Sicherungskopie fehlgeschlagen: {$relPath}");
                }
                $journal['restore'][] = [$backupPath, $dst];
            } else {
                $journal['created'][] = $dst;
            }

            if (!copy($src, $dst)) {
                throw new \RuntimeException("Datei konnte nicht kopiert werden: {$relPath}");
            }
            $copied++;
        }

        return $copied;
    }

    private static function removeTree(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    /**
     * Die Übersteuerung per UPDATE_RELEASES_URL ist ein Test-/Staging-Hilfsmittel
     * und greift nur in der Entwicklungsumgebung. In Produktion bestimmt sie,
     * woher der Code kommt, der anschließend die Installation überschreibt -
     * eine gesetzte Umgebungsvariable soll das nicht entscheiden dürfen. Eine
     * ignorierte Übersteuerung wird protokolliert, damit sie nicht still
     * wirkungslos bleibt.
     */
    private static function releasesUrl(): string {
        $override = getenv('UPDATE_RELEASES_URL');
        if ($override === false || $override === '') {
            return self::DEFAULT_RELEASES_URL;
        }

        if (!self::isDevelopment()) {
            error_log('UPDATE_RELEASES_URL wird außerhalb der Entwicklungsumgebung ignoriert.');
            return self::DEFAULT_RELEASES_URL;
        }

        return $override;
    }

    /**
     * Lädt die Release-Liste. Antwortet die (ggf. per UPDATE_RELEASES_URL
     * übersteuerte) API mit einem einzelnen Release-Objekt statt einer Liste,
     * wird es als Ein-Element-Liste behandelt.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function fetchReleases(): array {
        $raw = self::httpGet(self::releasesUrl(), ['Accept: application/vnd.github+json']);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Antwort der Release-API war kein gültiges JSON.');
        }
        // Einzelnes Release-Objekt (assoziativ) vs. Liste von Releases
        return array_is_list($data) ? $data : [$data];
    }

    /**
     * Prüft das heruntergeladene Archiv gegen die Prüfsummendatei des
     * Releases (`SHA256SUMS.txt`, erzeugt von `sha256sum *.zip` im
     * Release-Workflow).
     *
     * WAS DAS LEISTET UND WAS NICHT: Es ist eine Integritäts-, keine
     * Echtheitsprüfung - Archiv und Prüfsumme stammen aus derselben Quelle,
     * wer die Release-API fälschen kann, fälscht beides. Es fängt aber
     * abgebrochene und veränderte Downloads ab (das Zip ist ein paar Megabyte
     * groß und wandert über einen anderen Host als die API-Antwort) und
     * verhindert, dass ein zur Version unpassendes Asset angewendet wird. Die
     * Echtheit trägt die TLS-Verbindung zur fest verdrahteten
     * `api.github.com`-URL; für eine echte Signaturprüfung bräuchte es die
     * Verifikation der SLSA-Provenance-Attestierung, die der Release-Workflow
     * bereits erzeugt - das ist der nächste Schritt, nicht dieser.
     *
     * Fail-closed: Fehlt die Datei oder der Eintrag, wird nicht aktualisiert.
     */
    private static function verifyArchiveChecksum(string $zipPath, string $zipName, string $checksumsUrl): void {
        $checksums = self::httpGet($checksumsUrl, ['Accept: text/plain'], 60);

        $expected = null;
        foreach (preg_split('/\R/', $checksums) ?: [] as $line) {
            // Format von sha256sum: "<hash>  <dateiname>" (zwei Leerzeichen,
            // bei Binärmodus "<hash> *<dateiname>").
            if (preg_match('/^([a-f0-9]{64})\s+\*?(.+)$/i', trim($line), $m) !== 1) {
                continue;
            }
            if (basename(trim($m[2])) === $zipName) {
                $expected = strtolower($m[1]);
                break;
            }
        }

        if ($expected === null) {
            throw new \RuntimeException(
                "Update abgebrochen: In SHA256SUMS.txt steht kein Eintrag für {$zipName}."
            );
        }

        $actual = hash_file('sha256', $zipPath);
        if ($actual === false) {
            throw new \RuntimeException('Update abgebrochen: Prüfsumme des Archivs konnte nicht berechnet werden.');
        }

        if (!hash_equals($expected, strtolower($actual))) {
            AuditLogger::log(
                'Update abgebrochen: Prüfsumme stimmt nicht',
                'security',
                "Erwartet {$expected}, berechnet {$actual} für {$zipName}"
            );
            throw new \RuntimeException(
                'Update abgebrochen: Die Prüfsumme des heruntergeladenen Archivs stimmt nicht mit der '
                . 'des Releases überein. Das Archiv wurde nicht angewendet.'
            );
        }
    }

    private static function downloadToTempFile(string $url): string {
        $body = self::httpGet($url, ['Accept: application/octet-stream'], 300);
        $tmp = tempnam(sys_get_temp_dir(), 'hengst_update_zip_');
        if ($tmp === false || file_put_contents($tmp, $body) === false) {
            throw new \RuntimeException('Release-Zip konnte nicht zwischengespeichert werden.');
        }
        return $tmp;
    }

    private static function isDevelopment(): bool {
        return defined('APP_ENV') && APP_ENV === 'development';
    }

    private static function allowedProtocols(): string {
        return self::isDevelopment() ? 'https,http' : 'https';
    }

    /**
     * @param string[] $headers
     */
    private static function httpGet(string $url, array $headers = [], int $timeout = 30): string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'Hengstverzeichnis-Framework-Updater/' . self::currentVersion(),
            CURLOPT_HTTPHEADER => $headers,
            // Nur HTTPS - auch nach einer Umleitung. Ohne diese Bindung
            // führte eine 302 auf http:// oder file:// aus dem gesicherten
            // Transport heraus, und der Updater lädt ausgerechnet den Code,
            // der danach ausgeführt wird.
            //
            // In der Entwicklungsumgebung ist http zusätzlich erlaubt: Die
            // Funktionstests liefern ihr Release-Fixture über einen lokalen
            // `php -S` aus, der kein TLS kann. Dieselbe Grenze wie bei
            // UPDATE_RELEASES_URL (siehe releasesUrl()) - was den Update-Weg
            // aufweicht, gilt ausschließlich dort, wo ohnehin nichts
            // Schützenswertes läuft.
            CURLOPT_PROTOCOLS_STR => self::allowedProtocols(),
            CURLOPT_REDIR_PROTOCOLS_STR => self::allowedProtocols(),
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        if ($body === false) {
            throw new \RuntimeException("Update-Server nicht erreichbar: {$error}");
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("Update-Server antwortete mit HTTP {$status}.");
        }
        return (string)$body;
    }

    /**
     * @return array<string, string>
     */
    private static function loadSettings(): array {
        $settings = [];
        try {
            $rows = Database::getInstance()->query("SELECT setting_key, setting_value FROM settings")->fetchAll();
            foreach ($rows as $row) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        } catch (\Throwable $e) {
            // Leere Einstellungen führen zu "Backup nicht konfiguriert" und
            // damit zum sicheren Abbruch des Updates.
        }
        return $settings;
    }
}
