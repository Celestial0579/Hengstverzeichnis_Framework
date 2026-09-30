<?php
// src/Service/GithubAddonRepository.php

namespace App\Service;

/**
 * Class GithubAddonRepository
 *
 * Registry-Client für den Addon-Store (siehe docs/plugin-system-plan.md,
 * Abschnitt 2.7/Phase 3, und App\Controllers\AddonStoreController): lädt den
 * Tarball eines GitHub-Repositories (offizielles Hengstverzeichnis_Addons
 * ebenso wie beliebige, von einem Admin per Link hinzugefügte Quellen),
 * entpackt ihn in ein isoliertes temporäres Verzeichnis und liest dort
 * `plugins/<slug>/plugin.json`-Manifeste (Mehr-Plugin-Repo) bzw. ein
 * `plugin.json` im Repo-Root (Einzel-Plugin-Repo).
 *
 * Sicherheitsmodell: Anders als bei einer kuratierten Registry mit fester
 * Prüfsumme (siehe Plan-Dokument) kann bei einem beliebigen, vom Admin selbst
 * hinzugefügten Repo keine Signatur/Prüfsumme gegen eine dritte, vertrauens-
 * würdige Quelle geprüft werden - die Vertrauensentscheidung liegt bewusst
 * beim Admin, der das Repo hinzufügt (identisch zum bestehenden Modell "nur
 * Plugins aus vertrauenswürdiger Quelle aktivieren", siehe
 * docs/plugin-development.md, Abschnitt "Sicherheitsmodell"). Was diese
 * Klasse dagegen technisch durchsetzt:
 * - Größenlimit (`MAX_TARBALL_BYTES`) und Timeout beim Download - kein
 *   unbegrenztes Herunterladen einer bewusst riesigen Antwort. Diese Grenze
 *   gilt nur für die KOMPRIMIERTEN Bytes.
 * - Entpack-Grenzen gegen Archivbomben (Audit N16): Das gzip wird gestreamt
 *   und mit Byte-Zähler in ein unkomprimiertes Tar entpackt
 *   (`MAX_UNPACKED_BYTES`), danach werden Anzahl (`MAX_ENTRIES`) und
 *   Summe der Einträge geprüft - erst dann wird ausgepackt. Ein
 *   `new PharData('x.tar.gz')` würde das gzip bereits im Konstruktor
 *   vollständig nach sys_get_temp_dir() entpacken, eine Prüfung erst vor
 *   extractTo() käme also zu spät. Arbeitsverzeichnisse werden zusätzlich
 *   per Shutdown-Funktion aufgeräumt, auch nach einem Abbruch durch
 *   `max_execution_time`.
 * - Owner/Repo/Ref werden strikt validiert, bevor sie in eine URL
 *   eingesetzt werden - ausschließlich feste Zielhosts (`api.github.com`),
 *   kein SSRF über einen manipulierten Host-Teil.
 * - Entpacken ausschließlich in ein frisches, zufällig benanntes temporäres
 *   Verzeichnis; jeder entpackte Pfad wird zusätzlich zur eingebauten
 *   Pfad-Traversal-Absicherung von `PharData::extractTo()` per `realpath()`
 *   gegen dieses Zielverzeichnis geprüft, Symlinks werden komplett verworfen
 *   (siehe `verifyExtractedTreeIsSafe()`).
 * - Ein Plugin wird durch das Installieren allein **nie aktiviert** - das
 *   bleibt wie bisher ein separater, bewusster Klick unter `/admin/plugins`
 *   (`App\Plugin\PluginManager`/`PluginController`), inklusive der
 *   bestehenden Fingerabdruck-/Freigabe-Logik dort.
 */
final class GithubAddonRepository {

    /** Sicherheitsobergrenze gegen übermäßig große/entartete Downloads (komprimierte Bytes). */
    private const MAX_TARBALL_BYTES = 20 * 1024 * 1024;

    /**
     * Entpack-Grenzen gegen Archivbomben (Audit N16, Entscheidung D31: feste
     * Konstanten). MAX_UNPACKED_BYTES begrenzt das unkomprimierte Tar und
     * damit auch die Summe aller Dateigrößen, MAX_ENTRIES zählt Dateien und
     * Verzeichnisse. Zum Vergleich das offizielle Addons-Repo @e37f227:
     * 279 Einträge, 2,7 MB entpackt, 0,67 MB als gz.
     */
    private const MAX_UNPACKED_BYTES = 100 * 1024 * 1024;
    private const MAX_ENTRIES = 10000;

    /**
     * Register der per makeTempDir() angelegten, noch nicht aufgeräumten
     * Arbeitsverzeichnisse. Eine Shutdown-Funktion räumt sie ab, falls ein
     * finally-Block nicht mehr läuft (Fatal Error, max_execution_time).
     *
     * @var array<string, true>
     */
    private static array $offeneArbeitsverzeichnisse = [];

    private static bool $aufraeumenRegistriert = false;

    private const TIMEOUT_SECONDS = 20;

    /**
     * Grobe, aber ausreichend strikte Validierung eines GitHub-Owner- oder
     * Repo-Namens, BEVOR er in eine URL eingesetzt wird - verhindert, dass
     * ein manipulierter Wert die URL-Struktur verlässt (z. B. "/", "@", ":").
     */
    public static function isValidOwnerOrRepo(string $value): bool {
        return preg_match('/^[A-Za-z0-9._-]{1,100}$/', $value) === 1;
    }

    private static function isValidRef(string $ref): bool {
        return preg_match('#^[A-Za-z0-9._/-]{1,200}$#', $ref) === 1;
    }

    /**
     * Parst eine GitHub-URL (https://github.com/<owner>/<repo>, optional mit
     * ".git"-Suffix) oder eine kurze "owner/repo"-Eingabe. Gibt null zurück,
     * wenn das Format nicht erkannt wird oder owner/repo die Namensregeln
     * verletzen.
     *
     * @return array{owner: string, repo: string}|null
     */
    public static function parseOwnerRepo(string $input): ?array {
        $input = trim($input);
        $input = (string)preg_replace('#\.git/?$#i', '', $input);
        $input = rtrim($input, '/');

        if (preg_match('#^(?:https?://)?(?:www\.)?github\.com/([^/\s]+)/([^/\s]+)$#i', $input, $m)) {
            $owner = $m[1];
            $repo = $m[2];
        } elseif (preg_match('#^([^/\s]+)/([^/\s]+)$#', $input, $m)) {
            $owner = $m[1];
            $repo = $m[2];
        } else {
            return null;
        }

        if (!self::isValidOwnerOrRepo($owner) || !self::isValidOwnerOrRepo($repo)) {
            return null;
        }

        return ['owner' => $owner, 'repo' => $repo];
    }

    /**
     * Lädt den Tarball eines Repos herunter und liest daraus die verfügbaren
     * Plugin-Manifeste (ohne etwas dauerhaft zu installieren).
     *
     * @return array{ok: bool, plugins: array<int, array<string, mixed>>, error: ?string}
     */
    public static function fetchCatalog(string $owner, string $repo, ?string $ref): array {
        $tarPath = self::downloadTarball($owner, $repo, $ref);
        if ($tarPath === null) {
            return ['ok' => false, 'plugins' => [], 'error' => 'Download fehlgeschlagen (Repository/Branch nicht gefunden, GitHub nicht erreichbar oder Antwort zu groß).'];
        }

        try {
            return self::scanTarballFile($tarPath);
        } finally {
            self::removeWorkDir(dirname($tarPath));
        }
    }

    /**
     * Lädt den Tarball eines Repos herunter und installiert genau ein
     * Plugin (per Slug) daraus nach `$pluginsDir/<slug>/`. Aktiviert das
     * Plugin NICHT - das bleibt ein separater Schritt unter /admin/plugins.
     *
     * @return array{ok: bool, error: ?string, version: ?string}
     */
    public static function installPlugin(string $owner, string $repo, ?string $ref, string $slug, string $pluginsDir, bool $overwrite = false): array {
        $tarPath = self::downloadTarball($owner, $repo, $ref);
        if ($tarPath === null) {
            return ['ok' => false, 'error' => 'Download fehlgeschlagen (Repository/Branch nicht gefunden, GitHub nicht erreichbar oder Antwort zu groß).', 'version' => null];
        }

        try {
            return self::installFromTarballFile($tarPath, $slug, $pluginsDir, $overwrite);
        } finally {
            self::removeWorkDir(dirname($tarPath));
        }
    }

    /**
     * Kernlogik von fetchCatalog(), aber auf einer bereits lokal vorliegenden
     * .tar.gz-Datei - bewusst von download() getrennt, damit sie in Tests
     * ohne echten Netzwerkzugriff gegen ein lokal gebautes Test-Tarball
     * (inkl. böswilliger Einträge) geprüft werden kann.
     *
     * @return array{ok: bool, plugins: array<int, array<string, mixed>>, error: ?string}
     */
    public static function scanTarballFile(string $tarGzPath): array {
        $workDir = self::makeTempDir();
        if ($workDir === null) {
            return ['ok' => false, 'plugins' => [], 'error' => 'Temporäres Verzeichnis konnte nicht angelegt werden.'];
        }

        try {
            $fehler = self::extractSafely($tarGzPath, $workDir);
            if ($fehler !== null) {
                return ['ok' => false, 'plugins' => [], 'error' => $fehler];
            }

            $repoRoot = self::findRepoRoot($workDir);
            $plugins = [];

            $pluginsSubdir = $repoRoot . '/plugins';
            if (is_dir($pluginsSubdir)) {
                foreach (scandir($pluginsSubdir) ?: [] as $entry) {
                    if ($entry === '.' || $entry === '..' || !is_dir($pluginsSubdir . '/' . $entry)) {
                        continue;
                    }
                    $manifest = self::readManifestFile($pluginsSubdir . '/' . $entry . '/plugin.json', $entry);
                    if ($manifest !== null) {
                        $plugins[] = $manifest;
                    }
                }
            }

            // Einzel-Plugin-Repo: ein plugin.json direkt im Repo-Root - nur
            // relevant, wenn nicht bereits über plugins/<slug>/ gefunden.
            if (empty($plugins)) {
                $rootManifest = self::readManifestFile($repoRoot . '/plugin.json', null);
                if ($rootManifest !== null) {
                    $plugins[] = $rootManifest;
                }
            }

            usort($plugins, fn(array $a, array $b) => strcmp((string)$a['slug'], (string)$b['slug']));

            return ['ok' => true, 'plugins' => $plugins, 'error' => null];
        } finally {
            self::removeWorkDir($workDir);
        }
    }

    /**
     * Kernlogik von installPlugin(), aber auf einer bereits lokal
     * vorliegenden .tar.gz-Datei (siehe scanTarballFile()).
     *
     * @return array{ok: bool, error: ?string, version: ?string}
     */
    public static function installFromTarballFile(string $tarGzPath, string $slug, string $pluginsDir, bool $overwrite): array {
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
            return ['ok' => false, 'error' => 'Ungültiger Plugin-Slug.', 'version' => null];
        }

        $workDir = self::makeTempDir();
        if ($workDir === null) {
            return ['ok' => false, 'error' => 'Temporäres Verzeichnis konnte nicht angelegt werden.', 'version' => null];
        }

        try {
            $fehler = self::extractSafely($tarGzPath, $workDir);
            if ($fehler !== null) {
                return ['ok' => false, 'error' => $fehler, 'version' => null];
            }

            $repoRoot = self::findRepoRoot($workDir);

            $sourceDir = $repoRoot . '/plugins/' . $slug;
            $manifest = is_dir($sourceDir) ? self::readManifestFile($sourceDir . '/plugin.json', $slug) : null;

            if ($manifest === null) {
                // Fallback: Einzel-Plugin-Repo, Repo-Wurzel selbst ist das Plugin.
                $sourceDir = $repoRoot;
                $manifest = self::readManifestFile($repoRoot . '/plugin.json', $slug);
            }

            if ($manifest === null || !is_dir($sourceDir)) {
                return ['ok' => false, 'error' => "Plugin '{$slug}' wurde im Repository nicht gefunden.", 'version' => null];
            }

            $targetDir = rtrim($pluginsDir, '/') . '/' . $slug;
            if (is_dir($targetDir) && !$overwrite) {
                return ['ok' => false, 'error' => 'already_installed', 'version' => null];
            }

            // Atomares Ersetzen statt "löschen, dann kopieren" (#219): Der
            // frühere Ablauf löschte den installierten Stand VOR dem Kopieren -
            // schlug das Kopieren fehl (volle Platte, Quota, Rechte), war das
            // Addon unwiederbringlich weg, und gerade der unbeaufsichtigte
            // overwrite=true-Lauf nach einem Kern-Update meldete trotzdem
            // Erfolg. Deshalb: erst vollständig in ein Staging-Verzeichnis
            // kopieren, dann den alten Stand per rename() beiseitelegen, das
            // Staging per rename() aktivieren und erst zum Schluss das Backup
            // löschen - jeder Fehlschlag davor lässt den alten Stand intakt
            // bzw. rollt ihn zurück. Die Namen "<slug>.new-…"/"<slug>.bak-…"
            // sind bewusst gewählt: Der Punkt verletzt das Slug-Muster
            // ^[a-z0-9][a-z0-9-]*$ von PluginManager::validateManifest(),
            // solche Verzeichnisse können also nie als Plugin entdeckt oder
            // geladen werden, selbst wenn ein Absturz sie liegen lässt. Der
            // Zufallsanteil verhindert Kollisionen mit Resten früherer Läufe.
            $stagingDir = $targetDir . '.new-' . bin2hex(random_bytes(4));
            $backupDir = $targetDir . '.bak-' . bin2hex(random_bytes(4));

            if (!self::copyDirRecursive($sourceDir, $stagingDir)) {
                self::deleteDirRecursive($stagingDir);
                return ['ok' => false, 'error' => 'Kopieren nach plugins/ fehlgeschlagen (Dateisystem-Rechte/Speicherplatz prüfen) - der installierte Stand bleibt unangetastet.', 'version' => null];
            }

            if (is_dir($targetDir) && !@rename($targetDir, $backupDir)) {
                self::deleteDirRecursive($stagingDir);
                return ['ok' => false, 'error' => 'Alter Addon-Stand konnte nicht beiseitegelegt werden - Update abgebrochen, der installierte Stand bleibt unangetastet.', 'version' => null];
            }

            if (!@rename($stagingDir, $targetDir)) {
                // Rollback: Der alte Stand liegt noch vollständig im Backup -
                // zurückschieben, bevor der Fehler gemeldet wird.
                if (is_dir($backupDir)) {
                    @rename($backupDir, $targetDir);
                }
                self::deleteDirRecursive($stagingDir);
                return ['ok' => false, 'error' => 'Neuer Addon-Stand konnte nicht aktiviert werden - der bisherige Stand wurde wiederhergestellt.', 'version' => null];
            }

            self::deleteDirRecursive($backupDir);

            return ['ok' => true, 'error' => null, 'version' => $manifest['version']];
        } finally {
            self::removeWorkDir($workDir);
        }
    }

    /**
     * Öffentlicher Zugriff auf den Tarball-Download für den
     * AddonUpdateService (#197, Stufe 2) - dieselbe Sicherheits- und
     * Größenprüfung wie beim Store-Install. Der Aufrufer räumt mit
     * deleteWorkDirOf() auf.
     */
    public static function downloadTarballFor(string $owner, string $repo, ?string $ref): ?string {
        return self::downloadTarball($owner, $repo, $ref);
    }

    /** Räumt das Arbeitsverzeichnis eines per downloadTarballFor() geholten Tarballs ab. */
    public static function deleteWorkDirOf(string $tarPath): void {
        self::removeWorkDir(dirname($tarPath));
    }

    /**
     * Standard-URL der Releases-Liste eines Addon-Repos ({owner}/{repo}
     * werden ersetzt). Bewusst die Liste statt /latest - das erlaubt die
     * Auswahl je Kern-Linie. Übersteuerbar per ADDON_RELEASES_URL
     * (Tests/Staging), Muster wie UpdateService::releasesUrl().
     */
    private const RELEASES_URL_TEMPLATE = 'https://api.github.com/repos/{owner}/{repo}/releases?per_page=30';

    public static function releasesUrlFor(string $owner, string $repo): string {
        $override = getenv('ADDON_RELEASES_URL');
        if (is_string($override) && $override !== '') {
            return $override;
        }
        return str_replace(
            ['{owner}', '{repo}'],
            [rawurlencode($owner), rawurlencode($repo)],
            self::RELEASES_URL_TEMPLATE
        );
    }

    /**
     * Wählt aus einer Releases-Liste das beste Tag zur Kern-Linie "X.Y"
     * (#197, Stufe 2/3): Tags der Form vX.Y.z (führendes v optional) mit
     * exakt passender Linie, höchste Patch-Stelle gewinnt; Drafts und
     * Prereleases werden übersprungen. Netzwerkfrei und damit isoliert
     * testbar - Muster: UpdateService::selectBestRelease().
     *
     * @param array<int, mixed> $releases dekodierte GitHub-Releases-Liste
     */
    public static function selectBestReleaseTagForCoreLine(array $releases, string $coreLine): ?string {
        if (!preg_match('/^\d+\.\d+$/', $coreLine)) {
            return null;
        }
        $bestTag = null;
        $bestPatch = -1;
        foreach ($releases as $release) {
            if (!is_array($release) || !empty($release['draft']) || !empty($release['prerelease'])) {
                continue;
            }
            $tag = $release['tag_name'] ?? null;
            if (!is_string($tag) || !preg_match('/^v?(\d+)\.(\d+)\.(\d+)$/', $tag, $m)) {
                continue;
            }
            if ($m[1] . '.' . $m[2] !== $coreLine) {
                continue;
            }
            $patch = (int)$m[3];
            if ($patch > $bestPatch) {
                $bestPatch = $patch;
                $bestTag = $tag;
            }
        }
        return $bestTag;
    }

    /**
     * Bestes Release-Tag des Repos zur Kern-Linie - genau eine
     * GitHub-Abfrage. null, wenn (noch) kein passendes Release existiert
     * oder GitHub nicht erreichbar ist. Was der Aufrufer daraus macht,
     * hängt vom Kontext ab (#212): AUTOMATISCHE Updates
     * (AddonUpdateService) verweigern bei null, statt auf einen
     * veränderlichen Branch-HEAD zurückzufallen; nur der Store-Install
     * (AddonStoreController, bewusste Admin-Aktion) darf den Branch-Stand
     * als Fallback verwenden.
     */
    public static function bestReleaseTagForCoreLine(string $owner, string $repo, string $coreLine): ?string {
        if (!self::isValidOwnerOrRepo($owner) || !self::isValidOwnerOrRepo($repo)) {
            return null;
        }
        $json = self::httpGetJson(self::releasesUrlFor($owner, $repo));
        return is_array($json) ? self::selectBestReleaseTagForCoreLine($json, $coreLine) : null;
    }

    /** @return mixed dekodiertes JSON oder null */
    private static function httpGetJson(string $url): mixed {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::TIMEOUT_SECONDS,
                'follow_location' => 1,
                'max_redirects' => 5,
                'header' => "User-Agent: Hengstverzeichnis-Framework-AddonStore\r\nAccept: application/vnd.github+json\r\n",
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return null;
        }
        $status = self::extractStatusCode($http_response_header ?? []);
        if ($status === null || $status >= 300) {
            return null;
        }
        return json_decode($body, true);
    }

    /**
     * Lädt den Tarball über die GitHub-API (nicht direkt codeload.github.com,
     * da die API ohne Ref automatisch den tatsächlichen Standard-Branch
     * verwendet - "main" vs. "master" muss so nicht geraten werden;
     * `follow_location` folgt dem 302-Redirect der API zum eigentlichen
     * Download transparent). Gibt den Pfad zur heruntergeladenen Datei
     * zurück (der Aufrufer muss `dirname($pfad)` rekursiv löschen).
     */
    private static function downloadTarball(string $owner, string $repo, ?string $ref): ?string {
        if (!self::isValidOwnerOrRepo($owner) || !self::isValidOwnerOrRepo($repo)) {
            return null;
        }
        if ($ref !== null && $ref !== '' && !self::isValidRef($ref)) {
            return null;
        }

        $url = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/tarball';
        if ($ref !== null && $ref !== '') {
            $url .= '/' . implode('/', array_map('rawurlencode', explode('/', $ref)));
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::TIMEOUT_SECONDS,
                'follow_location' => 1,
                'max_redirects' => 5,
                // GitHub lehnt Anfragen ohne User-Agent ab (403).
                'header' => "User-Agent: Hengstverzeichnis-Framework-AddonStore\r\nAccept: application/vnd.github+json\r\n",
                'ignore_errors' => true,
            ],
        ]);

        $in = @fopen($url, 'rb', false, $context);
        if ($in === false) {
            return null;
        }

        $statusCode = self::extractStatusCode($http_response_header ?? []);
        if ($statusCode === null || $statusCode >= 300) {
            fclose($in);
            return null;
        }

        $workDir = self::makeTempDir();
        if ($workDir === null) {
            fclose($in);
            return null;
        }
        $tarPath = $workDir . '/archive.tar.gz';

        $out = @fopen($tarPath, 'wb');
        if ($out === false) {
            fclose($in);
            self::removeWorkDir($workDir);
            return null;
        }

        $written = 0;
        $tooLarge = false;
        while (!feof($in)) {
            $chunk = fread($in, 65536);
            if ($chunk === false) {
                break;
            }
            $written += strlen($chunk);
            if ($written > self::MAX_TARBALL_BYTES) {
                $tooLarge = true;
                break;
            }
            fwrite($out, $chunk);
        }
        fclose($in);
        fclose($out);

        if ($tooLarge) {
            self::removeWorkDir($workDir);
            return null;
        }

        return $tarPath;
    }

    /**
     * @param array<int, string> $headerLines
     */
    private static function extractStatusCode(array $headerLines): ?int {
        $statusCode = null;
        foreach ($headerLines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches)) {
                $statusCode = (int)$matches[1];
            }
        }
        return $statusCode;
    }

    /**
     * Entpackt eine .tar.gz-Datei in $destDir und prüft danach den
     * kompletten entpackten Baum (siehe verifyExtractedTreeIsSafe()) -
     * zusätzlich zu PharData::extractTo()s eigener, seit Langem etablierter
     * Absicherung gegen "..'"-Pfade (defense in depth, siehe Klassen-PHPDoc).
     *
     * Schutz vor Archivbomben (Audit N16): `new PharData($tarGz)` entpackt
     * das gesamte gzip schon im Konstruktor in eine Temp-Datei - eine
     * Größenprüfung danach käme zu spät. Deshalb zuerst gestreamt und
     * byte-begrenzt nach `<eigenes Arbeitsverzeichnis>/archive.tar`
     * entpacken (nicht in $destDir, sonst sähe findRepoRoot() das Tar),
     * dann dieses unkomprimierte Tar explizit als TAR öffnen, Anzahl und
     * Summe der Einträge prüfen und erst dann auspacken.
     *
     * @return string|null null bei Erfolg, sonst ein deutscher Fehlertext
     */
    private static function extractSafely(string $tarGzPath, string $destDir): ?string {
        $beschaedigt = 'Archiv konnte nicht sicher entpackt werden (beschädigt oder manipuliert).';

        $tarDir = self::makeTempDir();
        if ($tarDir === null) {
            return 'Temporäres Verzeichnis konnte nicht angelegt werden.';
        }
        $tarPath = $tarDir . '/archive.tar';
        $phar = null;

        try {
            $fehler = self::gunzipBegrenzt($tarGzPath, $tarPath, self::MAX_UNPACKED_BYTES);
            if ($fehler !== null) {
                return $fehler;
            }

            try {
                // Format explizit: ein unter diesem Namen liegender Zip-Inhalt
                // würde so nie mit Kompression pro Eintrag gelesen.
                $phar = new \PharData($tarPath, 0, null, \Phar::TAR);
            } catch (\Throwable $e) {
                return $beschaedigt;
            }

            $fehler = self::pruefeEintragsgrenzen($phar, self::MAX_ENTRIES, self::MAX_UNPACKED_BYTES);
            if ($fehler !== null) {
                return $fehler;
            }

            try {
                $phar->extractTo($destDir, null, true);
            } catch (\Throwable $e) {
                return $beschaedigt;
            }
        } finally {
            $phar = null;
            self::removeWorkDir($tarDir);
        }

        return self::verifyExtractedTreeIsSafe($destDir) ? null : $beschaedigt;
    }

    /**
     * Entpackt $gzPath gestreamt nach $tarPath und bricht ab, sobald mehr
     * als $maxBytes unkomprimierte Bytes anfallen.
     *
     * Bewusst über inflate_init()/inflate_add() statt gzopen()/gzread():
     * gzread() liefert bei einer abgeschnittenen Datei stillschweigend den
     * lesbaren Teil, nur inflate_get_status() zeigt, ob das gzip-Ende samt
     * Prüfsumme erreicht wurde. Die Eingabe wird in kleinen Stücken (8 KB)
     * gelesen, damit auch das maximale deflate-Verhältnis (~1:1000) je
     * Schritt nur wenige MB Speicher kostet. Mehrteilige gzip-Dateien werden
     * unterstützt; eine Datei ohne gzip-Kennung wird unverändert (ebenfalls
     * begrenzt) übernommen, wie es gzopen() täte.
     *
     * @return string|null null bei Erfolg, sonst ein deutscher Fehlertext
     *                     (die Zieldatei ist dann entfernt)
     */
    private static function gunzipBegrenzt(string $gzPath, string $tarPath, int $maxBytes): ?string {
        if (!function_exists('inflate_init')) {
            return 'Archiv kann nicht entpackt werden: Die PHP-Erweiterung zlib fehlt.';
        }
        $beschaedigt = 'Archiv konnte nicht sicher entpackt werden (beschädigt oder manipuliert).';
        $zuGross = sprintf(
            'Archiv ist entpackt größer als %d MB - abgelehnt (Schutz vor Archivbomben).',
            intdiv($maxBytes, 1024 * 1024)
        );

        $in = @fopen($gzPath, 'rb');
        if ($in === false) {
            return $beschaedigt;
        }
        $out = @fopen($tarPath, 'wb');
        if ($out === false) {
            fclose($in);
            return 'Temporäre Datei zum Entpacken konnte nicht angelegt werden.';
        }

        $fehler = null;
        $geschrieben = 0;
        $schreibe = static function (string $daten) use ($out, $maxBytes, $zuGross, &$geschrieben): ?string {
            if ($daten === '') {
                return null;
            }
            $geschrieben += strlen($daten);
            if ($geschrieben > $maxBytes) {
                return $zuGross;
            }
            if (fwrite($out, $daten) !== strlen($daten)) {
                return 'Archiv konnte nicht entpackt werden (Speicherplatz im temporären Verzeichnis prüfen).';
            }
            return null;
        };

        try {
            $kopf = (string)fread($in, 2);
            $istGzip = $kopf === "\x1f\x8b";
            $puffer = $kopf;
            $ctx = null;
            $zugefuehrt = 0;

            while (true) {
                if ($puffer === '') {
                    if (feof($in)) {
                        break;
                    }
                    $gelesen = fread($in, 8192);
                    if ($gelesen === false) {
                        $fehler = $beschaedigt;
                        break;
                    }
                    if ($gelesen === '') {
                        continue;
                    }
                    $puffer = $gelesen;
                }

                if (!$istGzip) {
                    $fehler = $schreibe($puffer);
                    $puffer = '';
                    if ($fehler !== null) {
                        break;
                    }
                    continue;
                }

                if ($ctx === null) {
                    // Nach dem Ende eines Teils folgende Füll-Nullbytes ignorieren.
                    if (trim($puffer, "\0") === '' && $zugefuehrt > 0) {
                        $puffer = '';
                        continue;
                    }
                    $ctx = inflate_init(ZLIB_ENCODING_GZIP);
                    $zugefuehrt = 0;
                }

                $stueck = $puffer;
                $puffer = '';
                $ergebnis = @inflate_add($ctx, $stueck, ZLIB_SYNC_FLUSH);
                if ($ergebnis === false) {
                    $fehler = $beschaedigt;
                    break;
                }
                $zugefuehrt += strlen($stueck);
                $fehler = $schreibe($ergebnis);
                if ($fehler !== null) {
                    break;
                }

                if (inflate_get_status($ctx) === ZLIB_STREAM_END) {
                    // Nicht verbrauchte Bytes gehören zum nächsten Teil.
                    $rest = $zugefuehrt - inflate_get_read_len($ctx);
                    if ($rest > 0) {
                        $puffer = substr($stueck, -$rest);
                    }
                    $ctx = null;
                }
            }

            // Endet die Eingabe mitten in einem gzip-Teil, ist die Datei
            // abgeschnitten oder beschädigt (gzip-Prüfsumme nie erreicht).
            if ($fehler === null && $istGzip && $ctx !== null) {
                $fehler = $beschaedigt;
            }
        } catch (\Throwable $e) {
            $fehler = $beschaedigt;
        } finally {
            fclose($in);
            fclose($out);
        }

        if ($fehler !== null) {
            @unlink($tarPath);
        }
        return $fehler;
    }

    /**
     * Prüft Anzahl und Gesamtgröße der Einträge eines geöffneten Tars, bevor
     * etwas ausgepackt wird. Zuerst die billige Gesamtzahl über count(),
     * dann Zählen und Aufsummieren über den Iterator.
     *
     * @return string|null null wenn innerhalb der Grenzen, sonst ein deutscher Fehlertext
     */
    private static function pruefeEintragsgrenzen(\PharData $phar, int $maxEntries, int $maxBytes): ?string {
        $zuViele = sprintf(
            'Archiv enthält mehr als %s Einträge - abgelehnt.',
            number_format($maxEntries, 0, ',', '.')
        );
        $zuGross = sprintf(
            'Archiv ist entpackt größer als %d MB - abgelehnt (Schutz vor Archivbomben).',
            intdiv($maxBytes, 1024 * 1024)
        );

        try {
            if ($phar->count() > $maxEntries) {
                return $zuViele;
            }

            $anzahl = 0;
            $summe = 0;
            foreach (new \RecursiveIteratorIterator($phar, \RecursiveIteratorIterator::SELF_FIRST) as $eintrag) {
                /** @var \PharFileInfo $eintrag */
                if (++$anzahl > $maxEntries) {
                    return $zuViele;
                }
                if ($eintrag->isFile()) {
                    $summe += (int)$eintrag->getSize();
                    if ($summe > $maxBytes) {
                        return $zuGross;
                    }
                }
            }
        } catch (\Throwable $e) {
            return 'Archiv konnte nicht sicher entpackt werden (beschädigt oder manipuliert).';
        }

        return null;
    }

    private static function verifyExtractedTreeIsSafe(string $destDir): bool {
        $realDest = realpath($destDir);
        if ($realDest === false) {
            return false;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($destDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            if ($fileInfo->isLink()) {
                // Symlinks komplett verweigern - könnten (je nach Ziel) aus
                // $destDir hinausverweisen und beim späteren Kopieren
                // Dateien außerhalb des vorgesehenen Bereichs offenlegen.
                return false;
            }
            $real = realpath($fileInfo->getPathname());
            if ($real === false || !str_starts_with($real, $realDest . DIRECTORY_SEPARATOR)) {
                return false;
            }
        }

        return true;
    }

    /**
     * GitHub-Tarballs verpacken den gesamten Inhalt in einem einzelnen
     * Wurzelordner (z. B. "Hengstverzeichnis_Addons-abc1234/") - hier wird
     * dieser Ordner ermittelt, damit `plugins/...` bzw. `plugin.json`
     * relativ zur eigentlichen Repo-Wurzel gesucht werden.
     */
    private static function findRepoRoot(string $extractedDir): string {
        $entries = array_values(array_diff(scandir($extractedDir) ?: [], ['.', '..']));
        if (count($entries) === 1 && is_dir($extractedDir . '/' . $entries[0])) {
            return $extractedDir . '/' . $entries[0];
        }
        return $extractedDir;
    }

    /**
     * Liest und validiert ein plugin.json - dieselben Pflichtfelder/Formate
     * wie App\Plugin\PluginManager::validateManifest(), bewusst eigenständig
     * gehalten, da der Store nur eine Vorschau braucht (keine Kompatibilitäts-
     * Prüfung gegen CORE_VERSION, das entscheidet erst PluginManager beim
     * tatsächlichen Laden nach der Installation).
     *
     * @return array<string, mixed>|null
     */
    private static function readManifestFile(string $manifestFile, ?string $expectedSlug): ?array {
        if (!is_file($manifestFile)) {
            return null;
        }
        $raw = @file_get_contents($manifestFile);
        if ($raw === false) {
            return null;
        }
        $manifest = json_decode($raw, true);
        if (!is_array($manifest)) {
            return null;
        }

        foreach (['slug', 'name', 'version', 'core_compatibility'] as $field) {
            if (empty($manifest[$field]) || !is_string($manifest[$field])) {
                return null;
            }
        }
        // Pflicht-Obergrenze (#197, Stufe 2): Ein Manifest ohne
        // core_supported_max fliegt aus dem Katalog und ist damit nicht
        // installierbar - dieselbe Durchsetzung wie in
        // PluginManager::validateManifest() für den manuellen Weg.
        $max = $manifest['core_supported_max'] ?? null;
        if (!is_string($max) || !preg_match('/^\d+\.\d+$/', $max)) {
            return null;
        }
        // description/author müssen Text sein, wenn gesetzt - dieselbe Regel
        // wie PluginManager::validateManifest() (Audit N84). Sonst würde der
        // Store ein Addon anbieten, das nach der Installation als "Ungültiges
        // Manifest" gilt.
        foreach (['description', 'author'] as $field) {
            if (isset($manifest[$field]) && !is_string($manifest[$field])) {
                return null;
            }
        }
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $manifest['slug'])) {
            return null;
        }
        if ($expectedSlug !== null && $manifest['slug'] !== $expectedSlug) {
            return null;
        }

        return [
            'slug' => $manifest['slug'],
            'name' => $manifest['name'],
            'version' => $manifest['version'],
            'core_compatibility' => $manifest['core_compatibility'],
            // Obergrenze der unterstützten Kern-Linie (#197) - muss durch die
            // Whitelist, sonst sehen Update-Seite/Store das Feld im Katalog nie.
            'core_supported_max' => $max,
            'description' => is_string($manifest['description'] ?? null) ? $manifest['description'] : '',
            'author' => is_string($manifest['author'] ?? null) ? $manifest['author'] : '',
            'hooks' => is_array($manifest['hooks'] ?? null) ? array_values(array_filter($manifest['hooks'], 'is_string')) : [],
        ];
    }

    private static function makeTempDir(): ?string {
        $dir = sys_get_temp_dir() . '/hengst_addon_' . bin2hex(random_bytes(16));
        if (!@mkdir($dir, 0700, true)) {
            return null;
        }
        self::$offeneArbeitsverzeichnisse[$dir] = true;
        if (!self::$aufraeumenRegistriert) {
            // Closure statt [self::class, '...']: die Methode ist privat.
            register_shutdown_function(static function (): void {
                self::raeumeArbeitsverzeichnisseAuf();
            });
            self::$aufraeumenRegistriert = true;
        }
        return $dir;
    }

    /** Löscht ein per makeTempDir() angelegtes Arbeitsverzeichnis und trägt es aus dem Register aus. */
    private static function removeWorkDir(string $dir): void {
        self::deleteDirRecursive($dir);
        unset(self::$offeneArbeitsverzeichnisse[$dir]);
    }

    /**
     * Shutdown-Aufräumen (Audit N16): entfernt alle noch registrierten
     * Arbeitsverzeichnisse, etwa wenn ein Request während des Entpackens an
     * max_execution_time scheiterte und die finally-Blöcke nicht mehr liefen.
     */
    private static function raeumeArbeitsverzeichnisseAuf(): void {
        foreach (array_keys(self::$offeneArbeitsverzeichnisse) as $dir) {
            self::removeWorkDir($dir);
        }
    }

    /**
     * Löscht ein Verzeichnis samt Inhalt. Bewusst OHNE
     * RecursiveDirectoryIterator und mit einer is_link()-Prüfung VOR is_dir():
     * so wird garantiert nie in ein (potenziell aus dem Zielbaum
     * hinausverweisendes) Symlink-Verzeichnis abgestiegen - der Symlink selbst
     * wird entfernt, sein Ziel nie. Diese Garantie hängt damit nicht mehr am
     * impliziten Verhalten von RecursiveDirectoryIterator::hasChildren()
     * (folgt Symlinks per Default nicht), sondern ist lokal in dieser Methode
     * ablesbar.
     *
     * Relevant, weil deleteDirRecursive() auch in finally-Zweigen läuft, wenn
     * verifyExtractedTreeIsSafe() gerade einen Symlink ABGELEHNT hat - der zu
     * löschende Baum kann also noch ungeprüfte Symlinks enthalten (defense in
     * depth; unlink() auf einen Symlink entfernt ohnehin nur den Link, nie
     * dessen Ziel).
     */
    private static function deleteDirRecursive(string $dir): void {
        // is_link() zuerst: is_dir() folgt Symlinks und wäre für einen
        // Symlink-auf-Verzeichnis true - dann würde ein Abstieg/rmdir auf das
        // ZIEL statt auf den Link wirken.
        if (is_link($dir) || !is_dir($dir)) {
            @unlink($dir);
            return;
        }

        $entries = @scandir($dir);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                self::deleteDirRecursive($dir . DIRECTORY_SEPARATOR . $entry);
            }
        }

        @rmdir($dir);
    }

    private static function copyDirRecursive(string $source, string $target): bool {
        if (!is_dir($target) && !@mkdir($target, 0755, true)) {
            return false;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isLink()) {
                continue; // sollte nach verifyExtractedTreeIsSafe() nie vorkommen - zur Sicherheit trotzdem übersprungen.
            }
            $relative = substr($item->getPathname(), strlen($source) + 1);
            $destPath = $target . '/' . $relative;
            if ($item->isDir()) {
                if (!is_dir($destPath) && !@mkdir($destPath, 0755, true)) {
                    return false;
                }
            } elseif (!@copy($item->getPathname(), $destPath)) {
                return false;
            }
        }

        return true;
    }
}
