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

    /**
     * Pfade, die ein Update nie anfasst, stehen seit #403 nicht mehr hier,
     * sondern in App\Service\Baumordnung - zusammen mit der Antwort auf die
     * umgekehrte Frage, welche Pfade dem Kern gehören und deshalb abgeglichen
     * werden dürfen. Beides war dieselbe Eigentumsfrage, hier stand nur die
     * eine Hälfte davon.
     */

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
        // Der Eintrag stand bis v0.9.0-beta.3 aus, weil die Kern-Galerie noch
        // nicht fertig war - ein Eintrag ohne den zugehörigen Ersatz wäre kein
        // halbes Feature, sondern ein Schaden: Das Update entfernte das Addon,
        // und die Betreiber stünden ganz ohne Galerie da.
        //
        // Jetzt ist der Ersatz da (App\Service\HorseMedia, #339), und die
        // Reihenfolge stimmt: Der SchemaMigrator übernimmt die Daten
        // (Schritt 339_galerie_uebernahme), bevor hier irgendetwas entfernt
        // wird - Tabellen und Dateien des Addons rührt das Update ohnehin
        // nicht an, entfernt wird ausschließlich der Code.
        'galerie' => '0.9.0',
    ];

    /** Nur für Tests: Die Liste ist privat, ihr Inhalt aber prüfbar. */
    public static function abgeloesteAddons(): array {
        return self::ABGELOESTE_ADDONS;
    }

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
     * Aktive Addons, die die Zielversion nicht unterstützen UND für die es
     * keine passende Fassung gibt (#362, #364).
     *
     * DAS IST DIE EINE REGEL FÜR BEIDE WEGE. Sie beantwortet die Frage
     * „braucht dieses Update Aufsicht?" - unbeaufsichtigt heisst das
     * „zurückstellen und melden", von Hand „die Zielversion abtippen".
     *
     * ENTSCHEIDEND IST NICHT DER VERSIONSSPRUNG. Ein erster Entwurf hat hier
     * auf den Linienwechsel abgestellt (0.7.x -> 0.8.x) - das war falsch:
     * Updates sollen grundsätzlich automatisch laufen, so wie es die beiden
     * Einstellungen Kanal (stabil/beta) und Reichweite (nur Patch/jede
     * Version) vorgeben. Ein Linienwechsel, für den passende Addon-Fassungen
     * bereitliegen, ist unproblematisch - die Addon-Phase zieht sie nach dem
     * Kern von selbst mit.
     *
     * Aufsicht braucht genau ein Fall: ein aktives Addon, das die Zielversion
     * nicht unterstützt und für das auch im Katalog nichts Passendes liegt.
     * Dann verschwindet eine Funktion, und niemand kann sie zurückholen.
     *
     * `availableSupportsTarget === null` heisst „keine Aussage" (kein
     * Katalog, kein Eintrag) und wird wie „kein Update möglich" behandelt -
     * die strengere Seite. „Konnte nicht prüfen" ist nicht „geprüft, ist in
     * Ordnung".
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
            // Gibt es eine passende Fassung, ist nichts im Weg - die
            // Addon-Phase zieht sie nach dem Kern mit.
            if (($row['availableSupportsTarget'] ?? null) === true) {
                continue;
            }
            $gruende[] = sprintf(
                '%s: %s%s',
                (string)($row['slug'] ?? '?'),
                $grund,
                ($row['availableSupportsTarget'] ?? null) === null
                    ? ' (kein Katalog-Eintrag - es liess sich nicht feststellen, ob es eine passende Fassung gibt)'
                    : ' (auch im Addon-Store liegt keine passende Fassung)'
            );
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
     * Die Assets eines Releases zu einer bestimmten Version (#403).
     *
     * Die Update-Strecke fragt immer nach dem BESTEN neueren Release. Die
     * Integritaetspruefung braucht das Gegenteil: das Release zu genau der
     * Version, die gerade laeuft - denn daran wird der Codebaum gemessen.
     *
     * @return array<string, string> Asset-Name => Download-URL, leer wenn es
     *         das Release nicht gibt oder GitHub nicht erreichbar ist.
     */
    public static function releaseAssets(string $version): array {
        try {
            $roh = self::httpGet(self::releasesUrl(), ['Accept: application/vnd.github+json']);
            $releases = json_decode($roh, true);
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($releases)) {
            return [];
        }

        $gesucht = self::normalizeVersion($version);
        foreach ($releases as $release) {
            if (!is_array($release) || !empty($release['draft'])) {
                continue;
            }
            if (self::normalizeVersion((string)($release['tag_name'] ?? '')) !== $gesucht) {
                continue;
            }
            $assets = [];
            foreach ((array)($release['assets'] ?? []) as $asset) {
                $name = (string)($asset['name'] ?? '');
                $url = (string)($asset['browser_download_url'] ?? '');
                if ($name !== '' && $url !== '') {
                    $assets[$name] = $url;
                }
            }
            return $assets;
        }

        return [];
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
    /**
     * Der Grund, aus dem ein Update gar nicht erst beginnen darf - oder null.
     *
     * AN EINER STELLE, WEIL ZWEI AUFRUFER IHN BRAUCHEN. performUpdate()
     * prueft ihn als Erstes; der Controller ebenfalls, noch vor der
     * Release-Abfrage.
     *
     * WARUM VOR DER RELEASE-ABFRAGE. Das fehlende Backup und ein nicht
     * erreichbarer Release-Server sind ZWEI VERSCHIEDENE Dinge, und beide
     * Meldungen sind fuer sich genommen richtig - "Release-Pruefung
     * fehlgeschlagen" ist keine Falschaussage, wenn GitHub gerade nicht
     * antwortet. Gemeldet wird aber nur die Bedingung, die zuerst scheitert,
     * und dafuer ist diese hier die bessere: Sie haengt allein an der eigenen
     * Konfiguration, ist ohne Netz feststellbar und muss ohnehin erfuellt
     * sein, bevor irgendetwas passiert. Wer sie zuerst hoert, kann sofort
     * etwas tun; wer zuerst "Release-Pruefung fehlgeschlagen" hoert, wartet
     * auf das Netz und stoesst danach trotzdem auf das Backup.
     *
     * Der Nebeneffekt ist ein Netzzugriff weniger, aber das ist nicht der
     * Grund.
     */
    public static function backupHindernis(): ?string {
        if (BackupService::isConfigured(self::loadSettings())) {
            return null;
        }

        return 'Update abgebrochen: Automatische Backups sind nicht (vollständig) konfiguriert. '
             . 'Ein Update ohne vorheriges Backup ist nicht zulässig - bitte zunächst unter '
             . '/admin/backups einrichten.';
    }

    public static function performUpdate(): array {
        $hindernis = self::backupHindernis();
        if ($hindernis !== null) {
            throw new \RuntimeException($hindernis);
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
            // AddonUpdateService im Audit-Log; die Baumordnung bleibt davon
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
            // Das konkrete Ergebnis statt der Bedingung (#364): Wird DIESE
            // Version unbeaufsichtigt eingespielt oder nicht? null, wenn gar
            // keine Kern-Version dabei ist (reine Addon-Meldung).
            $kernWirdEingespielt = ($autoInstall && $findings['coreIsNew'] && $availableCore !== null)
                ? self::isEligibleForAutoInstall(self::currentVersion(), $availableCore, self::configuredAutoScope())
                : null;
            foreach ($recipients as $recipient) {
                if ($mailer->sendUpdatesAvailableNotification(
                    $recipient,
                    $findings['coreIsNew'] ? $availableCore : null,
                    $findings['newAddons'],
                    $autoInstall,
                    $kernWirdEingespielt
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
                WHERE g.slug = 'admin' AND u.deleted_at IS NULL AND u.deactivated_at IS NULL AND u.email <> ''
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

    public static function baseDir(): string {
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
            $journal = [
                'restore' => [], 'created' => [], 'created_dirs' => [],
                'deleted' => [], 'rmdir' => [],
            ];

            // Das Manifest der LAUFENDEN Installation JETZT lesen - vor
            // copyTree().
            //
            // Es ist die zweite Beweisquelle des Abgleichs: Was ihm entspricht,
            // ist nachweislich unsere Datei und seither unangetastet. Nur
            // liegt KERN-SHA256SUMS.txt auch im Archiv, und copyTree() kopiert
            // es mit - danach steht dort das Manifest des NEUEN Releases, und
            // das weiss ueber die abgeloesten Dateien der alten Installation
            // naturgemaess nichts.
            //
            // Die Quelle war damit in jedem echten Release wirkungslos: Sie
            // half nur, wenn das Archiv gar kein Manifest mitbrachte - also
            // ausgerechnet dann nicht, wenn sie gebraucht wird.
            $eigeneBeweise = self::beweiseAusInstallation($target);

            try {
                $kopiert = self::copyTree($sourceDir, $target, '', $backupDir, $journal);

                // Abgleich der KERN-Pfade (#403): Was die Installation aus
                // einer frueheren Version hat und das Archiv nicht mehr
                // mitbringt, wird jetzt entfernt. NACH dem Kopieren, damit der
                // Ersatz schon liegt; VOR entferneAbgeloesteAddons(), weil bis
                // hierher noch zurueckgerollt werden kann.
                $entfernt = self::abgleicheKernPfade($sourceDir, $target, $backupDir, $journal, $eigeneBeweise);
                $unklar = self::unklareFunde();

                if ($entfernt > 0) {
                    AuditLogger::log(
                        'Update: abgeloeste Kerndateien entfernt',
                        'update',
                        sprintf(
                            '%d Datei(en) entfernt, die eine fruehere Version ausgeliefert hat und dieses '
                            . 'Release nicht mehr - jede davon beweisbar unveraendert (Pruefsumme in %s).',
                            $entfernt,
                            self::ABGELOESTE_LISTE
                        )
                    );
                }

                // Was sich nicht beweisen laesst, wird gemeldet statt still
                // liegen gelassen. Genau dieser stille Zustand war #403.
                if ($unklar !== []) {
                    AuditLogger::log(
                        'Update: unklare Dateien in Kern-Verzeichnissen',
                        'update',
                        sprintf(
                            '%d Datei(en) liegen in Kern-Verzeichnissen, gehoeren nicht zu diesem Release und '
                            . 'lassen sich keiner frueheren Version zuordnen. Sie wurden NICHT entfernt - '
                            . 'moeglicherweise stammen sie vom Betreiber. Bitte pruefen: %s',
                            count($unklar),
                            implode(', ', array_slice($unklar, 0, 40))
                            . (count($unklar) > 40 ? sprintf(' ... (%d weitere)', count($unklar) - 40) : '')
                        )
                    );
                }

                // ERST NACH dem Kopieren: Bis hierher könnte das Update noch
                // zurückgerollt werden, und ein Addon, das schon weg ist, käme
                // dabei nicht wieder. Jetzt steht der neue Kern - und mit ihm
                // der Ersatz für das, was gleich entfernt wird.
                self::entferneAbgeloesteAddons($target, self::neueVersionAus($sourceDir));

                return $kopiert;
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
     * Liest CORE_VERSION aus dem entpackten Archiv.
     *
     * Nicht aus der laufenden Konstante: Die gehoert noch zum ALTEN Stand -
     * der neue Code liegt erst auf der Platte, geladen ist er nicht. Wer hier
     * CORE_VERSION nimmt, entfernt ein Addon eine Version zu frueh oder gar
     * nicht.
     */
    private static function neueVersionAus(string $sourceDir): string {
        $datei = $sourceDir . '/config/config.php';
        if (!is_file($datei)) {
            return '';
        }

        // Lesen statt einbinden: config.php baut eine Datenbankverbindung auf
        // und definiert Konstanten, die in diesem Prozess laengst stehen.
        $inhalt = (string)file_get_contents($datei);
        if (preg_match("/define\\(\\s*'CORE_VERSION'\\s*,\\s*'([^']+)'/", $inhalt, $treffer) !== 1) {
            return '';
        }

        return $treffer[1];
    }

    /**
     * Entfernt Addons, deren Funktion in den Kern gewandert ist (#339).
     *
     * `plugins` ist in der Baumordnung BETREIBER, ein Update fasst Addon-
     * Verzeichnisse also nie an. Genau eine Lage braucht die Ausnahme: wenn
     * der Kern uebernimmt, was das Addon tat. Bliebe es aktiv, gaebe es zwei
     * Pflegeoberflaechen fuer dieselben Daten und zwei Vorstellungen davon,
     * welches Bild das Hauptbild ist.
     *
     * ENTFERNT WIRD AUSSCHLIESSLICH DER CODE. Tabellen, Dateien und
     * Einstellungen des Addons bleiben - der Kern hat sie zu diesem Zeitpunkt
     * uebernommen, und wer sie loswerden will, tut das anschliessend bewusst
     * ueber /admin/plugins (#338).
     *
     * @return array<int, string> Entfernte Slugs
     */
    private static function entferneAbgeloesteAddons(string $targetDir, string $neueVersion): array {
        if ($neueVersion === '' || self::ABGELOESTE_ADDONS === []) {
            return [];
        }

        $entfernt = [];

        foreach (self::ABGELOESTE_ADDONS as $slug => $abVersion) {
            // Nur, wenn der neue Kern tatsaechlich weit genug ist. Ein
            // Downgrade oder ein Sprung auf eine aeltere Fassung darf das
            // Addon nicht mitnehmen.
            if (self::isNewer($abVersion, $neueVersion)) {
                continue;
            }

            $verzeichnis = rtrim($targetDir, '/') . '/plugins/' . $slug;
            if (!is_dir($verzeichnis)) {
                continue;
            }

            // Zuerst deaktivieren, dann loeschen: Bleibt der Lauf dazwischen
            // stehen, ist das Addon aus und sein Code noch da - der harmlose
            // der beiden Zwischenstaende. Umgekehrt zeigte die Verwaltung ein
            // aktives Addon ohne Code.
            try {
                $stmt = Database::getInstance()->prepare(
                    'UPDATE plugins SET enabled = 0 WHERE slug = ?'
                );
                $stmt->execute([$slug]);
            } catch (\Throwable $e) {
                // Ohne Datenbank kein Deaktivieren - dann bleibt auch das
                // Verzeichnis stehen. Ein Addon-Code ohne den zugehoerigen
                // Eintrag waere der unklarere Zustand.
                continue;
            }

            self::removeTree($verzeichnis);
            $entfernt[] = (string)$slug;

            AuditLogger::log(
                'Abgeloestes Addon entfernt',
                'system',
                sprintf(
                    'Addon "%s" ist ab Kern %s abgeloest und wurde deaktiviert; sein Verzeichnis ist entfernt. '
                    . 'Tabellen, Dateien und Einstellungen bleiben - der Kern hat die Daten uebernommen.',
                    $slug,
                    $abVersion
                )
            );
        }

        return $entfernt;
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
            if (Baumordnung::istBetreiber($relPath)) {
                continue;
            }

            $src = $sourceDir . '/' . $relPath;
            $dst = $targetDir . '/' . $relPath;

            if (is_dir($src)) {
                if (is_dir($dst) && !is_writable($dst)) {
                    throw new \RuntimeException("Verzeichnis ist nicht beschreibbar: {$relPath}");
                }
                // Nur meckern, wenn der Elternordner wirklich schon existiert.
                // Sonst legt copyTree() ihn in diesem Lauf selbst an
                // (mkdir(..., recursive: true)) - is_writable() liefert für
                // einen noch nicht existierenden Pfad immer false und würde
                // jedes neue Verzeichnis in einem neuen Verzeichnis fälschlich
                // als "nicht anlegbar" melden. Derselbe Wächter steht unten im
                // Datei-Zweig.
                if (!is_dir($dst) && is_dir(dirname($dst)) && !is_writable(dirname($dst))) {
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
     * Die Reihenfolge ist nicht beliebig. Verzeichnisse, die der Abgleich
     * entfernt hat, müssen zuerst wieder da sein - sonst hat die
     * Wiederherstellung der Dateien darin keinen Ort. Und die vom Kopieren
     * angelegten Verzeichnisse kommen zuletzt weg, weil `rmdir` auf einem
     * nicht leeren Verzeichnis fehlschlägt: Steht dort noch etwas, bleibt es
     * stehen, statt mitgerissen zu werden.
     *
     * @param array{restore: array<int, array{0: string, 1: string}>, created: array<int, string>, created_dirs: array<int, string>, deleted: array<int, array{0: ?string, 1: string}>, rmdir: array<int, string>} $journal
     */
    private static function rollback(array $journal): void {
        // 1. Vom Abgleich entfernte Verzeichnisse zurück - in umgekehrter
        //    Entfernungsreihenfolge, also Eltern vor Kindern.
        foreach (array_reverse($journal['rmdir'] ?? []) as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }

        // 2. Vom Abgleich entfernte Dateien zurück. Eine Sicherung von null
        //    steht für eine symbolische Verknüpfung - die wird nicht kopiert
        //    und lässt sich hier nicht wiederherstellen; sie wird gemeldet.
        $verknuepfungen = 0;
        foreach (array_reverse($journal['deleted'] ?? []) as [$backup, $original]) {
            if ($backup === null) {
                $verknuepfungen++;
                continue;
            }
            @copy($backup, $original);
        }

        // 3. Neu angelegte Dateien weg, dann überschriebene zurück.
        foreach (array_reverse($journal['created']) as $path) {
            @unlink($path);
        }
        foreach (array_reverse($journal['restore']) as [$backup, $original]) {
            @copy($backup, $original);
        }

        // 4. Neu angelegte Verzeichnisse weg - Kinder vor Eltern, und nur,
        //    wenn sie leer sind.
        foreach (array_reverse($journal['created_dirs'] ?? []) as $dir) {
            @rmdir($dir);
        }

        AuditLogger::log(
            'Update zurückgerollt',
            'update',
            sprintf(
                '%d überschriebene Datei(en) wiederhergestellt, %d neu angelegte entfernt, '
                . '%d Verzeichnis(se) entfernt, %d beim Abgleich gelöschte Datei(en) zurückgeholt%s',
                count($journal['restore']),
                count($journal['created']),
                count($journal['created_dirs'] ?? []),
                count($journal['deleted'] ?? []) - $verknuepfungen,
                $verknuepfungen > 0
                    ? sprintf(' - ACHTUNG: %d symbolische Verknüpfung(en) konnten nicht wiederhergestellt werden', $verknuepfungen)
                    : ''
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
            if (Baumordnung::istBetreiber($relPath)) {
                continue;
            }

            $src = $sourceDir . '/' . $relPath;
            $dst = $targetDir . '/' . $relPath;

            if (is_dir($src)) {
                $existed = is_dir($dst);
                if (!$existed && !mkdir($dst, 0755, true) && !is_dir($dst)) {
                    throw new \RuntimeException("Verzeichnis konnte nicht angelegt werden: {$relPath}");
                }
                if (!$existed) {
                    // Seit #403 wird das festgehalten. Vorher wurde $existed
                    // berechnet und nur in der Bedingung darueber verwendet -
                    // ein fehlgeschlagenes Update liess deshalb ein leeres
                    // Verzeichnisgeruest stehen, weil rollback() gar nicht
                    // wusste, welche Verzeichnisse neu waren.
                    $journal['created_dirs'][] = $dst;
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
                $backupPath = $backupDir . '/' . self::sicherungsname('ueberschrieben', $relPath);
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

    /** Name der Beweisliste im Release-Archiv (siehe scripts/kern-manifest.php). */
    public const ABGELOESTE_LISTE = 'ABGELOESTE-DATEIEN.txt';

    /**
     * Pfade, die beim letzten Abgleich weder ins Archiv noch in die
     * Beweisliste passten - also nicht entfernt wurden. Fuer die Anzeige im
     * Adminbereich und fuer Tests.
     *
     * @var array<int, string>
     */
    private static array $unklareFunde = [];

    /** @return array<int, string> */
    public static function unklareFunde(): array {
        return self::$unklareFunde;
    }

    /**
     * Gleicht die KERN-Pfade gegen das Archiv ab (#403).
     *
     * Entfernt wird eine vorgefundene Datei NUR, wenn zweierlei zutrifft: Das
     * Archiv bringt sie nicht mehr mit, UND ihre Pruefsumme steht in der
     * Beweisliste des Archivs. Dann ist bewiesen, dass sie von uns stammt und
     * niemand sie seither angefasst hat.
     *
     * Warum der Beweis noetig ist: "Das Archiv hat die Datei nicht" trifft auf
     * eine Leiche aus v0.8.0 genauso zu wie auf eine Datei, die der Betreiber
     * selbst dort abgelegt hat - eine eigene Uebersetzung in lang/ etwa, die
     * die Vorrangregel in Translator::loadTable() ausdruecklich hergibt, oder
     * ein config.php.bak vor einer Aenderung. Ohne den Beweis waere das
     * Aufraeumen ein Raten mit Datenverlust als Einsatz.
     *
     * Was sich nicht beweisen laesst, wird NICHT stillschweigend liegen
     * gelassen, sondern gemeldet: ins Audit-Log und ueber unklareFunde() in
     * den Adminbereich. Eine Leiche, von der niemand weiss, ist der Zustand,
     * aus dem #403 entstanden ist.
     *
     * Fehlt die Beweisliste im Archiv - aeltere Releases haben sie nicht -,
     * wird gar nichts entfernt und alles gemeldet. Fail-closed.
     *
     * Leitplanken darueber hinaus:
     * - Nur wo die Baumordnung KERN sagt. Betreiberdaten in einem
     *   KERN-Verzeichnis (public/uploads) fallen damit von selbst heraus.
     * - Das Archiv muss das Verzeichnis ueberhaupt mitbringen; "fehlt" heisst
     *   "unvollstaendiges Archiv", nicht "alles loeschen".
     * - Vorher sichern und journalisieren, damit rollback() es zurueckholt.
     *   Laesst sich nicht sichern, wird nicht geloescht.
     * - Symbolische Verknuepfungen werden nicht verfolgt und nicht entfernt -
     *   sie stehen nie in der Beweisliste, gelten also als unklar.
     *
     * @param array{restore: array<int, array{0: string, 1: string}>, created: array<int, string>, created_dirs: array<int, string>, deleted: array<int, array{0: string, 1: string}>, rmdir: array<int, string>} $journal
     * @return int Anzahl entfernter Dateien
     */
    private static function abgleicheKernPfade(
        string $sourceDir,
        string $targetDir,
        string $backupDir,
        array &$journal,
        array $eigeneBeweise = []
    ): int {
        self::$unklareFunde = [];
        // Die Beweise der Installation werden VOM AUFRUFER uebergeben, weil
        // sie vor copyTree() gelesen werden muessen - danach steht dort das
        // Manifest des neuen Releases. Siehe applyUpdateArchive().
        $beweise = self::leseBeweisliste($sourceDir) + $eigeneBeweise;
        $entfernt = 0;

        foreach (Baumordnung::kernPfade() as $kernPfad) {
            if (!is_dir($sourceDir . '/' . $kernPfad) || !is_dir($targetDir . '/' . $kernPfad)) {
                continue;
            }
            $entfernt += self::abgleicheVerzeichnis(
                $sourceDir, $targetDir, $kernPfad, $backupDir, $beweise, $journal
            );
        }

        return $entfernt;
    }

    /**
     * Die ZWEITE Beweisquelle: das Manifest der laufenden Installation.
     *
     * `ABGELOESTE-DATEIEN.txt` entsteht aus der Git-Historie und deckt damit
     * nur ab, was auch in git steht. Fuer alles andere - allen voran ein
     * kuenftiges `vendor/`, das erst beim Bauen entsteht - waere sonst nie ein
     * Beweis zu fuehren, und der Abgleich koennte dort nie aufraeumen.
     *
     * Das eigene Manifest schliesst die Luecke, und zwar mit derselben
     * Beweiskraft: Es beschreibt den Sollzustand DIESER Installation, wurde
     * beim Einspielen aus einem gegen SHA256SUMS.txt geprueften Archiv
     * uebernommen, und eine Datei, die ihm exakt entspricht, ist damit
     * nachweislich unsere und seither unangetastet. Fehlt sie im neuen
     * Archiv, ist sie abgeloest.
     *
     * Die Grenze ist ehrlich zu benennen: Wer Dateien schreiben kann, kann
     * auch das Manifest schreiben und sich so eine Loeschung erschleichen.
     * Das aendert nichts an der Lage - wer so weit ist, kann die Datei
     * genauso gut selbst loeschen. Der Beweis schuetzt vor VERSEHEN, nicht
     * vor einem Angreifer mit Schreibrecht; wogegen der schuetzt, steht in
     * App\Service\Integritaet.
     *
     * @return array<string, true> "<sha256>  <pfad>" => true
     */
    private static function beweiseAusInstallation(string $targetDir): array {
        $datei = rtrim($targetDir, '/') . '/' . Integritaet::MANIFEST;
        if (!is_file($datei)) {
            return [];
        }

        $beweise = [];
        foreach (file($datei, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $zeile) {
            if ($zeile === '' || $zeile[0] === '#') {
                continue;
            }
            if (preg_match('/^([0-9a-f]{64})  (.+)$/', $zeile, $t) === 1) {
                $beweise[$t[1] . '  ' . $t[2]] = true;
            }
        }

        return $beweise;
    }

    /**
     * Liest die Beweisliste aus dem entpackten Archiv.
     *
     * @return array<string, true> "<sha256>  <pfad>" => true
     */
    private static function leseBeweisliste(string $sourceDir): array {
        $datei = $sourceDir . '/' . self::ABGELOESTE_LISTE;
        if (!is_file($datei)) {
            return [];
        }

        $beweise = [];
        foreach (file($datei, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $zeile) {
            if ($zeile === '' || $zeile[0] === '#') {
                continue;
            }
            // Format wie sha256sum: 64 Hex, zwei Leerzeichen, Pfad.
            if (preg_match('/^([0-9a-f]{64})  (.+)$/', $zeile, $t) !== 1) {
                continue;
            }
            $beweise[$t[1] . '  ' . $t[2]] = true;
        }

        return $beweise;
    }

    /**
     * Ein Kern-Verzeichnis abgleichen. Rekursiv, Kinder vor dem Elternteil -
     * ein Verzeichnis laesst sich erst entfernen, wenn es leer ist.
     *
     * @param array<string, true> $beweise
     * @param array{restore: array<int, array{0: string, 1: string}>, created: array<int, string>, created_dirs: array<int, string>, deleted: array<int, array{0: string, 1: string}>, rmdir: array<int, string>} $journal
     */
    private static function abgleicheVerzeichnis(
        string $sourceDir,
        string $targetDir,
        string $relative,
        string $backupDir,
        array $beweise,
        array &$journal
    ): int {
        $entfernt = 0;
        $eintraege = array_diff(scandir($targetDir . '/' . $relative) ?: [], ['.', '..']);

        foreach ($eintraege as $eintrag) {
            $relPath = $relative . '/' . $eintrag;
            $ziel = $targetDir . '/' . $relPath;
            $quelle = $sourceDir . '/' . $relPath;

            if (!Baumordnung::darfAbgeglichenWerden($relPath)) {
                continue;
            }

            // Verknuepfungen: nicht verfolgen, nicht entfernen. Sie haben
            // keinen Inhalt, den die Beweisliste kennen koennte.
            if (is_link($ziel)) {
                if (!file_exists($quelle)) {
                    self::$unklareFunde[] = $relPath . ' (symbolische Verknuepfung)';
                }
                continue;
            }

            if (is_dir($ziel)) {
                $darin = self::abgleicheVerzeichnis(
                    $sourceDir, $targetDir, $relPath, $backupDir, $beweise, $journal
                );
                $entfernt += $darin;

                if (is_dir($quelle)) {
                    continue;   // gibt es im Archiv, bleibt
                }

                // WAS IST DER BEWEIS FUER EIN VERZEICHNIS?
                //
                // Eine Datei laesst sich ueber ihre Pruefsumme als unsere
                // ausweisen. Ein Verzeichnis hat keinen Inhalt, den eine Liste
                // kennen koennte - hier gibt es nichts zu vergleichen.
                //
                // Die erste Fassung entfernte deshalb jedes leere Verzeichnis,
                // das im Archiv fehlte. Damit verschwand auch, was der
                // Betreiber selbst angelegt hatte: public/.well-known/
                // acme-challenge etwa, der Webroot fuer certbot. Leer ist es
                // im Ruhezustand immer. Weg waren Besitzer, Rechte und ACL -
                // und die naechste Zertifikatserneuerung schlug fehl. Kein
                // Protokolleintrag, keine Meldung: $entfernt zaehlt nur
                // Dateien, also griff auch der AuditLogger nicht.
                //
                // Der Beweis ist deshalb ein anderer: Ein Verzeichnis darf nur
                // gehen, wenn DIESER Abgleich es geleert hat. Dann bestand es
                // nachweislich aus unseren Dateien. War es schon vorher leer,
                // haben wir es nicht angelegt und fassen es nicht an.
                if ($darin > 0 && @rmdir($ziel)) {
                    $journal['rmdir'][] = $ziel;
                    continue;
                }

                // Uebrig bleibt: fehlt im Archiv, aber wir haben es nicht
                // geleert. Das wird gemeldet statt still liegen gelassen -
                // dieselbe Zusage wie bei den Dateien.
                if ($darin === 0 && self::istLeer($ziel)) {
                    self::$unklareFunde[] = $relPath . '/ (leeres Verzeichnis)';
                }
                continue;
            }

            if (is_file($quelle)) {
                continue;   // hat eine Entsprechung im Archiv, bleibt
            }

            // Kein Gegenstueck im Archiv. Ist es beweisbar unsere Datei?
            $hash = @hash_file('sha256', $ziel);
            if ($hash === false || !isset($beweise[$hash . '  ' . $relPath])) {
                self::$unklareFunde[] = $relPath;
                continue;
            }

            // Die Ablage ist flach - der relative Pfad wird zum Dateinamen.
            //
            // Der Pfad allein reicht dafuer NICHT: str_replace('/','__') bildet
            // lang/a/b.php und lang/a__b.php auf denselben Namen ab. Beide
            // Sicherungen landeten uebereinander, das Journal zeigte zweimal
            // auf dieselbe Datei, und ein Rollback stellte die eine aus dem
            // Inhalt der anderen wieder her - ohne Fehler, ohne Warnung, mit
            // der Meldung "2 Datei(en) zurueckgeholt". Nachgestellt.
            //
            // Deshalb kommt eine Kurzfassung des vollstaendigen Pfades dazu.
            // Der lesbare Teil bleibt vorn (fuer den Fall, dass jemand in das
            // Verzeichnis schaut) und wird gekuerzt, damit auch tiefe Pfade
            // unter NAME_MAX bleiben.
            //
            // Das @ unterdrueckt nur die rohe PHP-Warnung, der Fehlerfall wird
            // ausgewertet. Und er endet richtig herum: ohne Sicherung wird
            // NICHT geloescht, sondern abgebrochen. Ein Abgleich ohne Rueckweg
            // waere schlimmer als eine liegengebliebene Datei.
            $sicherung = $backupDir . '/' . self::sicherungsname('geloescht', $relPath);
            if (!@copy($ziel, $sicherung)) {
                throw new \RuntimeException(
                    "Sicherungskopie vor dem Entfernen fehlgeschlagen: {$relPath} - es wurde nichts entfernt."
                );
            }
            if (!@unlink($ziel)) {
                throw new \RuntimeException("Abgeloeste Datei konnte nicht entfernt werden: {$relPath}");
            }
            $journal['deleted'][] = [$sicherung, $ziel];
            $entfernt++;
        }

        return $entfernt;
    }

    /**
     * Stellt einzelne Dateien aus einem Release-Archiv wieder her (#403).
     *
     * Die Reparaturseite der Integritaetspruefung. Bewusst schmal gehalten:
     *
     * - Nur Pfade aus $soll. Der Aufrufer kann also nichts Beliebiges
     *   schreiben; die Liste kommt von GitHub, nicht aus einem Formular.
     * - Nur KERN-Pfade. Betreiberdaten sind hier nie das Ziel.
     * - Nach dem Auspacken wird jede Datei GEGEN $soll geprueft, bevor sie
     *   kopiert wird. Ein Archiv, das an dieser Stelle etwas anderes
     *   mitbringt als die veroeffentlichte Liste sagt, wird nicht eingespielt -
     *   sonst repariert man kaputte Dateien mit anderen kaputten.
     * - Journal und Rueckweg wie beim Update: bricht es in der Mitte ab,
     *   steht der Ausgangszustand wieder.
     *
     * @param array<int, string> $pfade
     * @param array<string, string> $soll pfad => sha256
     * @return array<int, string> tatsaechlich wiederhergestellte Pfade
     */
    public static function stelleDateienHer(string $zipPath, string $targetDir, array $pfade, array $soll): array {
        $extractDir = rtrim(sys_get_temp_dir(), '/') . '/hengst_repair_' . bin2hex(random_bytes(6));
        if (!mkdir($extractDir, 0755, true)) {
            throw new \RuntimeException('Temporaeres Entpack-Verzeichnis konnte nicht angelegt werden.');
        }
        $backupDir = rtrim(sys_get_temp_dir(), '/') . '/hengst_repair_bak_' . bin2hex(random_bytes(6));
        if (!mkdir($backupDir, 0700, true)) {
            self::removeTree($extractDir);
            throw new \RuntimeException('Temporaeres Sicherungsverzeichnis konnte nicht angelegt werden.');
        }

        try {
            self::extractArchive($zipPath, $extractDir);

            $entries = array_values(array_diff(scandir($extractDir) ?: [], ['.', '..']));
            $sourceDir = (count($entries) === 1 && is_dir($extractDir . '/' . $entries[0]))
                ? $extractDir . '/' . $entries[0]
                : $extractDir;

            $target = rtrim($targetDir, '/');
            $journal = [
                'restore' => [], 'created' => [], 'created_dirs' => [],
                'deleted' => [], 'rmdir' => [],
            ];
            $fertig = [];

            try {
                foreach ($pfade as $relPath) {
                    if (!isset($soll[$relPath]) || !Baumordnung::istKern($relPath)) {
                        continue;
                    }

                    $src = $sourceDir . '/' . $relPath;
                    if (!is_file($src)) {
                        throw new \RuntimeException(
                            "Das Release-Archiv enthaelt {$relPath} nicht - es passt nicht zur Pruefliste."
                        );
                    }
                    if (!hash_equals($soll[$relPath], (string)@hash_file('sha256', $src))) {
                        throw new \RuntimeException(
                            "Im Archiv weicht {$relPath} von der veroeffentlichten Pruefliste ab - "
                            . 'es wird nichts eingespielt.'
                        );
                    }

                    $dst = $target . '/' . $relPath;
                    $ordner = dirname($dst);
                    if (!is_dir($ordner)) {
                        if (!mkdir($ordner, 0755, true) && !is_dir($ordner)) {
                            throw new \RuntimeException("Verzeichnis konnte nicht angelegt werden: {$relPath}");
                        }
                        $journal['created_dirs'][] = $ordner;
                    }

                    if (file_exists($dst)) {
                        $sicherung = $backupDir . '/' . str_replace('/', '__', $relPath);
                        if (!@copy($dst, $sicherung)) {
                            throw new \RuntimeException("Sicherungskopie fehlgeschlagen: {$relPath}");
                        }
                        $journal['restore'][] = [$sicherung, $dst];
                    } else {
                        $journal['created'][] = $dst;
                    }

                    if (!copy($src, $dst)) {
                        throw new \RuntimeException("Datei konnte nicht kopiert werden: {$relPath}");
                    }
                    $fertig[] = $relPath;
                }

                return $fertig;
            } catch (\Throwable $e) {
                self::rollback($journal);
                throw new \RuntimeException(
                    'Reparatur abgebrochen und zurueckgerollt: ' . $e->getMessage(),
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
     * Ein eindeutiger, noch lesbarer Name fuer die flache Sicherungsablage.
     *
     * Der Pfad allein taugt nicht: Ersetzt man '/' durch '__', fallen
     * lang/a/b.php und lang/a__b.php zusammen. Deshalb haengt eine
     * Kurzfassung des VOLLSTAENDIGEN Pfades hinten an - sie unterscheidet die
     * beiden zuverlaessig. Der lesbare Teil bleibt vorn und wird gekuerzt,
     * damit auch tiefe Pfade unter NAME_MAX (255) bleiben.
     */
    private static function sicherungsname(string $zweck, string $relPath): string {
        $lesbar = str_replace('/', '__', $relPath);
        if (strlen($lesbar) > 160) {
            $lesbar = substr($lesbar, -160);
        }
        return $zweck . '__' . substr(hash('sha256', $relPath), 0, 16) . '__' . $lesbar;
    }

    /** Ist dieses Verzeichnis leer? (Ohne '.' und '..'.) */
    private static function istLeer(string $dir): bool {
        $eintraege = @scandir($dir);
        return $eintraege !== false && count(array_diff($eintraege, ['.', '..'])) === 0;
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
    public static function verifyArchiveChecksum(string $zipPath, string $zipName, string $checksumsUrl): void {
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

    public static function downloadToTempFile(string $url): string {
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
    public static function httpGet(string $url, array $headers = [], int $timeout = 30): string {
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
