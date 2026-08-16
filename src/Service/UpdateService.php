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
 * - Nur manuell im Admin-Bereich anstoßbar (siehe UpdateController) -
 *   bewusst kein unbeaufsichtigter Scheduler-Lauf als erster Schritt.
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
        try {
            self::verifyArchiveChecksum($zipPath, (string)$check['zip_name'], (string)$check['checksums_url']);

            $baseDir = dirname(__DIR__, 2);
            $files = self::applyUpdateArchive($zipPath, $baseDir);
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

        return [
            'from' => $check['current'],
            'to' => $check['latest'],
            'files' => $files,
            'addons' => $addonPhase['results'],
            'addons_ref' => $addonPhase['ref'],
        ];
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
        curl_close($ch);

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
