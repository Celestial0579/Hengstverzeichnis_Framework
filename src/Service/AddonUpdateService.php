<?php
// src/Service/AddonUpdateService.php

namespace App\Service;

use App\Database;
use App\Plugin\PluginManager;

/**
 * Addon-Updates aus dem OFFIZIELLEN Repo (#197, Stufe 2). Zwei Wege:
 *
 *  1. updateAddon(): manuelles Update eines einzelnen Addons von der
 *     Update-Seite - innerhalb der laufenden Kern-Linie (ein neuer
 *     Addons-Patch-Release vX.Y.z+1 ist per Versionsschema kompatibel zum
 *     laufenden Kern X.Y).
 *  2. updateOfficialAddonsAfterCoreUpdate(): die Addon-Phase des
 *     Kern-Updates (Reihenfolge Pflicht-Backup -> Kern -> Addons, siehe
 *     UpdateService::performUpdate()) - zieht alle aus dem offiziellen Repo
 *     installierten Addons auf den zur ZIEL-Linie passenden Release-Stand
 *     mit. Addon-Fehler brechen das Kern-Update nicht ab (Ergebnisliste).
 *
 * Bezugsquelle ist AUSSCHLIESSLICH der beste Release-Tag vX.Y.z zur
 * Kern-Linie (GithubAddonRepository::bestReleaseTagForCoreLine). Existiert
 * (noch) keiner, wird das Update mit sprechender Meldung VERWEIGERT - der
 * frühere Fallback auf den konfigurierten Ref/Branch-HEAD hätte bedeutet,
 * dass ein automatischer Vorgang ungeprüften, jederzeit veränderlichen
 * main-Code einspielt (#212: ein einziger untergeschobener Commit im
 * Addons-Repo liefe damit auf jeder Installation, die auf "Aktualisieren"
 * klickt). Ein bewusster Branch-Bezug bleibt möglich - aber nur als
 * manuelle Admin-Aktion über den Addon-Store (AddonStoreController), nicht
 * hier. Nach erfolgreichem Update wird plugins.source auf
 * "owner/repo@<tag>" gepinnt: Daran erkennt der PluginManager, dass der
 * neue Stand aus einem Release stammt, und akzeptiert den Versionswechsel
 * automatisch (sonst Wiederfreigabe-Pflicht).
 *
 * Bewusst NICHT hier: Fremd-Repos (bleiben manuell über den Store -
 * automatisches Einspielen ungeprüften Fremdcodes wäre ein Rückschritt),
 * ein unbeaufsichtigter Scheduler-Lauf (zurückgestellt) und jede Änderung
 * an der Freigabe-Logik: Nach einem Update greift unverändert die
 * Re-Approval-Kette des PluginManagers (neue Manifest-Version wird
 * automatisch akzeptiert, veränderter Code ohne Versionswechsel fail-closed).
 */
final class AddonUpdateService {

    /** "0.4.2" -> "0.4"; null bei unbrauchbarer Version. */
    public static function coreLine(string $version): ?string {
        return preg_match('/^v?(\d+)\.(\d+)\./', $version, $m) ? $m[1] . '.' . $m[2] : null;
    }

    /**
     * Entscheidet, mit welchem Bezugspunkt ein AUTOMATISCHES Update arbeiten
     * darf (#212): nur mit einem Release-Tag der Kern-Linie. null heißt
     * "kein Release vorhanden ODER GitHub nicht erreichbar" - beides führt
     * bewusst zur Verweigerung statt zum Branch-HEAD, denn ein Branch ist
     * jederzeit veränderlich und ohne Prüfsumme/Signatur nicht von einem
     * untergeschobenen Stand unterscheidbar. Reine Funktion (kein Netz,
     * keine DB), damit die Verweigerung isoliert testbar ist.
     *
     * @return array{ref: ?string, error: ?string} genau eines von beiden gesetzt
     */
    public static function resolveAutoUpdateRef(?string $bestReleaseTag, string $line): array {
        if ($bestReleaseTag === null) {
            return [
                'ref' => null,
                'error' => "Für die Kern-Linie {$line} existiert (noch) kein Addon-Release oder GitHub ist nicht erreichbar. "
                    . 'Automatische Updates aus einem Branch-Stand sind nicht zulässig - '
                    . 'ein bewusster Branch-Bezug bleibt manuell über den Addon-Store möglich.',
            ];
        }
        return ['ref' => $bestReleaseTag, 'error' => null];
    }

    /**
     * Kernlogik auf einem bereits lokal vorliegenden Tarball - netzwerkfrei
     * und damit isoliert testbar (Muster: UpdateService::applyUpdateArchive).
     * Verweigert das Ersetzen, wenn das NEUE Manifest nicht zur gegebenen
     * Kern-Version passt (inkl. Pflicht-Obergrenze) - ein Update darf ein
     * funktionierendes Addon nicht durch ein inkompatibles ersetzen.
     *
     * @return array{ok: bool, error: ?string, from: ?string, to: ?string}
     */
    public static function updateAddonFromTarball(string $slug, string $tarPath, string $pluginsDir, string $coreVersion): array {
        $scan = \App\Service\GithubAddonRepository::scanTarballFile($tarPath);
        if (!$scan['ok']) {
            return ['ok' => false, 'error' => $scan['error'] ?? 'Tarball unlesbar.', 'from' => null, 'to' => null];
        }

        $target = null;
        foreach ($scan['plugins'] as $entry) {
            if (($entry['slug'] ?? null) === $slug) {
                $target = $entry;
                break;
            }
        }
        if ($target === null) {
            return ['ok' => false, 'error' => "Addon '{$slug}' ist im bezogenen Stand nicht (mehr) enthalten.", 'from' => null, 'to' => null];
        }

        $reason = PluginManager::incompatibilityReason($target, $coreVersion);
        if ($reason !== null) {
            return ['ok' => false, 'error' => "Update verweigert: neuer Stand {$reason}.", 'from' => null, 'to' => null];
        }

        $installedManifestFile = rtrim($pluginsDir, '/') . '/' . $slug . '/plugin.json';
        $installed = is_file($installedManifestFile)
            ? json_decode((string)@file_get_contents($installedManifestFile), true)
            : null;
        $from = is_array($installed) && is_string($installed['version'] ?? null) ? $installed['version'] : null;

        $result = \App\Service\GithubAddonRepository::installFromTarballFile($tarPath, $slug, $pluginsDir, true);
        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'], 'from' => $from, 'to' => null];
        }

        return ['ok' => true, 'error' => null, 'from' => $from, 'to' => $result['version']];
    }

    /**
     * Manuelles Update eines einzelnen Addons aus dem offiziellen Repo,
     * innerhalb der laufenden Kern-Linie (Anstoß: Update-Seite).
     *
     * @return array{ok: bool, error: ?string, from: ?string, to: ?string, ref: ?string}
     */
    public static function updateAddon(string $slug): array {
        $fail = static fn(string $error): array => ['ok' => false, 'error' => $error, 'from' => null, 'to' => null, 'ref' => null];

        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
            return $fail('Ungültiger Addon-Slug.');
        }
        $official = self::officialRepoRow();
        if ($official === null) {
            return $fail('Kein offizielles Addon-Repo registriert.');
        }
        $sourceInfo = self::installedSource($slug);
        if ($sourceInfo === null || !self::isOfficialSource($sourceInfo, $official)) {
            // Fremd-Repos und manuell kopierte Addons bleiben ausdrücklich
            // manuell (Store bzw. Dateisystem) - siehe Klassenkommentar.
            return $fail('Dieses Addon stammt nicht aus dem offiziellen Repo - Updates laufen dort über den Addon-Store bzw. manuell.');
        }

        $coreVersion = defined('CORE_VERSION') ? CORE_VERSION : '';
        $line = self::coreLine($coreVersion);
        if ($line === null) {
            return $fail('Laufende Kern-Version ist nicht bestimmbar.');
        }

        // Kein Fallback auf den konfigurierten Ref/Branch-HEAD mehr (#212):
        // ohne Release-Tag zur Kern-Linie wird verweigert, siehe
        // resolveAutoUpdateRef() und Klassenkommentar.
        $resolved = self::resolveAutoUpdateRef(
            \App\Service\GithubAddonRepository::bestReleaseTagForCoreLine(
                (string)$official['owner'],
                (string)$official['repo'],
                $line
            ),
            $line
        );
        if ($resolved['error'] !== null) {
            return $fail($resolved['error']);
        }
        $ref = $resolved['ref'];

        $tarPath = \App\Service\GithubAddonRepository::downloadTarballFor(
            (string)$official['owner'],
            (string)$official['repo'],
            $ref
        );
        if ($tarPath === null) {
            return $fail('Download des offiziellen Addon-Stands fehlgeschlagen (GitHub nicht erreichbar?).');
        }

        try {
            $result = self::updateAddonFromTarball($slug, $tarPath, self::pluginsDir(), $coreVersion);
        } finally {
            \App\Service\GithubAddonRepository::deleteWorkDirOf($tarPath);
        }

        if ($result['ok']) {
            self::pinInstalledSource($slug, $official, (string)$ref);
            self::runInstallHookIfAvailable($slug);
            AuditLogger::log(
                'Addon aktualisiert',
                'plugin',
                "Slug: {$slug}, von " . ($result['from'] ?? '?') . " auf " . ($result['to'] ?? '?')
                . ", Bezug: {$ref}"
            );
        }
        return $result + ['ref' => $ref];
    }

    /**
     * Addon-Phase des Kern-Updates: alle aus dem offiziellen Repo
     * installierten Addons auf den zur neuen Kern-Linie passenden Stand
     * ziehen. EIN Download für alle Addons; Fehler einzelner Addons werden
     * gesammelt zurückgegeben und brechen nichts ab.
     *
     * @return array{ref: ?string, results: array<int, array{slug: string, ok: bool, error: ?string, from: ?string, to: ?string}>}
     */
    public static function updateOfficialAddonsAfterCoreUpdate(string $newCoreVersion): array {
        $official = self::officialRepoRow();
        $line = self::coreLine($newCoreVersion);
        if ($official === null || $line === null) {
            return ['ref' => null, 'results' => []];
        }

        $slugs = self::installedOfficialSlugs($official);
        if ($slugs === []) {
            return ['ref' => null, 'results' => []];
        }

        // Wie in updateAddon(): ohne Release-Tag zur ZIEL-Linie wird die
        // gesamte Addon-Phase verweigert (#212) - gerade der unbeaufsichtigte
        // Lauf nach einem Kern-Update darf nie auf einen Branch-HEAD
        // zurückfallen. Die Verweigerung erscheint je Addon in der
        // Ergebnisliste, damit sie im Update-Protokoll sichtbar ist.
        $resolved = self::resolveAutoUpdateRef(
            \App\Service\GithubAddonRepository::bestReleaseTagForCoreLine(
                (string)$official['owner'],
                (string)$official['repo'],
                $line
            ),
            $line
        );
        if ($resolved['error'] !== null) {
            $results = array_map(static fn(string $slug): array => [
                'slug' => $slug, 'ok' => false,
                'error' => $resolved['error'],
                'from' => null, 'to' => null,
            ], $slugs);
            return ['ref' => null, 'results' => $results];
        }
        $ref = $resolved['ref'];

        $tarPath = \App\Service\GithubAddonRepository::downloadTarballFor(
            (string)$official['owner'],
            (string)$official['repo'],
            $ref
        );
        if ($tarPath === null) {
            $results = array_map(static fn(string $slug): array => [
                'slug' => $slug, 'ok' => false,
                'error' => 'Download des offiziellen Addon-Stands fehlgeschlagen.',
                'from' => null, 'to' => null,
            ], $slugs);
            return ['ref' => $ref, 'results' => $results];
        }

        $results = [];
        try {
            foreach ($slugs as $slug) {
                $one = self::updateAddonFromTarball($slug, $tarPath, self::pluginsDir(), $newCoreVersion);
                if ($one['ok']) {
                    self::pinInstalledSource($slug, $official, (string)$ref);
                    self::runInstallHookIfAvailable($slug);
                }
                $results[] = ['slug' => $slug] + $one;
                AuditLogger::log(
                    $one['ok'] ? 'Addon nach Kern-Update mitgezogen' : 'Addon-Update nach Kern-Update fehlgeschlagen',
                    'plugin',
                    "Slug: {$slug}, " . ($one['ok']
                        ? 'von ' . ($one['from'] ?? '?') . ' auf ' . ($one['to'] ?? '?') . ", Bezug: {$ref}"
                        : (string)$one['error'])
                );
            }
        } finally {
            \App\Service\GithubAddonRepository::deleteWorkDirOf($tarPath);
        }

        return ['ref' => $ref, 'results' => $results];
    }

    /**
     * Fasst die Ergebnisliste von updateOfficialAddonsAfterCoreUpdate() für
     * die Anzeige zusammen. Mehrere Addons scheitern regelmäßig am selben
     * Grund (fehlt der Release-Tag zur Ziel-Linie, trifft es alle) - die
     * Gründe werden deshalb entdoppelt, die betroffenen Slugs aber
     * vollständig genannt. Bewusst nicht zu EINEM Grund zusammengefasst:
     * Unterschiedliche Ursachen dürfen nicht unter einer Meldung verschwinden.
     *
     * @param array<int, array{slug: string, ok: bool, error: ?string}> $results
     * @return array{reasons: array<int, string>, slugs: array<int, string>}
     */
    public static function summarizeFailures(array $results): array {
        $failed = array_filter($results, static fn(array $r): bool => !(bool)$r['ok']);

        return [
            'reasons' => array_values(array_unique(array_map(
                static fn(array $r): string => (string)($r['error'] ?? 'Unbekannter Fehler.'),
                $failed
            ))),
            'slugs' => array_values(array_column($failed, 'slug')),
        ];
    }

    /**
     * Frischt den Katalog-Cache des offiziellen Repos auf, sofern seine TTL
     * abgelaufen ist (#290). Bewusst NUR das offizielle Repo - Fremd-Repos
     * bleiben Sache des Addon-Stores, ein automatisierter Abruf dort wäre
     * ein Netzwerkzugriff ohne Nutzen für Update-Seite und Cron-Prüfung.
     *
     * Fehler werden geschluckt: Der Aufrufer arbeitet danach mit dem (dann
     * eben älteren) Cache weiter - AddonOverview::officialCatalogFromCache()
     * hat dafür seinen eigenen Fallback. Ein nicht erreichbares GitHub darf
     * weder die Update-Seite noch einen Cron-Lauf scheitern lassen.
     */
    public static function refreshOfficialCatalog(bool $force = false): void {
        try {
            $db = Database::getInstance();
            $repoRow = $db->query(
                "SELECT *, " . \App\Controllers\AddonStoreController::CACHE_AGE_SELECT
                . " FROM addon_repos WHERE is_official = 1 LIMIT 1"
            )->fetch();
            if ($repoRow !== false) {
                \App\Controllers\AddonStoreController::catalogForRepo($db, $repoRow, $force);
            }
        } catch (\Throwable $e) {
            // bewusst geschluckt, siehe PHPDoc
        }
    }

    // ---- Helfer --------------------------------------------------------

    private static function pluginsDir(): string {
        return __DIR__ . '/../../plugins';
    }

    /**
     * Pinnt die Herkunft des soeben installierten Stands auf
     * "owner/repo@<tag>" (#212): plugins.source dokumentiert damit nicht nur
     * das Repo, sondern den EXAKTEN, unveränderlichen Release-Bezug. Der
     * PluginManager nutzt genau dieses Muster als Grundlage der
     * Auto-Accept-Regel bei Versionswechseln - ein Stand ohne @tag-Pin
     * (Branch-Bezug, manuelle Kopie) bleibt wiederfreigabepflichtig.
     * Fehler hier brechen das Update nicht ab: Der Code liegt bereits
     * korrekt auf der Platte, schlimmstenfalls fehlt die Freigabe-Abkürzung.
     *
     * @param array<string, mixed> $official
     */
    private static function pinInstalledSource(string $slug, array $official, string $ref): void {
        try {
            $stmt = Database::getInstance()->prepare("UPDATE plugins SET source = ? WHERE slug = ?");
            $stmt->execute([$official['owner'] . '/' . $official['repo'] . '@' . $ref, $slug]);
        } catch (\Throwable $e) {
            // bewusst geschluckt, siehe PHPDoc
        }
    }

    /**
     * Ruft nach erfolgreichem Update den Install-Hook des Plugins auf
     * (Migrationen u. Ä. laufen so direkt beim Update, nicht erst beim
     * nächsten manuellen Aktivieren). Der method_exists-Guard überbrückt
     * die Übergangszeit, in der PluginManager::runInstallHook() noch nicht
     * gemergt ist - danach ist er wirkungslos und kann entfallen.
     */
    private static function runInstallHookIfAvailable(string $slug): void {
        $manager = PluginManager::getInstance();
        if (method_exists($manager, 'runInstallHook')) {
            $manager->runInstallHook($slug);
        }
    }

    /** @return array<string, mixed>|null */
    private static function officialRepoRow(): ?array {
        try {
            $row = Database::getInstance()
                ->query("SELECT owner, repo, ref FROM addon_repos WHERE is_official = 1 LIMIT 1")
                ->fetch();
        } catch (\Throwable $e) {
            return null;
        }
        return $row === false ? null : $row;
    }

    private static function installedSource(string $slug): ?string {
        try {
            $stmt = Database::getInstance()->prepare("SELECT source FROM plugins WHERE slug = ?");
            $stmt->execute([$slug]);
            $value = $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return null;
        }
        return is_string($value) ? $value : null;
    }

    /** @param array<string, mixed> $official */
    private static function isOfficialSource(string $source, array $official): bool {
        $prefix = $official['owner'] . '/' . $official['repo'];
        return $source === $prefix || str_starts_with($source, $prefix . '@');
    }

    /**
     * Slugs aller installierten Addons, deren `plugins.source` auf das
     * offizielle Repo zeigt (der Store schreibt die Herkunft beim
     * Installieren). Manuell kopierte Addons (source NULL) bleiben außen vor.
     *
     * @param array<string, mixed> $official
     * @return array<int, string>
     */
    private static function installedOfficialSlugs(array $official): array {
        try {
            $rows = Database::getInstance()->query("SELECT slug, source FROM plugins WHERE source IS NOT NULL")->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
        $slugs = [];
        $discovered = PluginManager::getInstance()->getDiscoveredPlugins();
        foreach ($rows as $row) {
            $slug = (string)$row['slug'];
            if (!self::isOfficialSource((string)$row['source'], $official)) {
                continue;
            }
            // Nur tatsächlich (noch) im Dateisystem vorhandene Addons.
            if (isset($discovered[$slug])) {
                $slugs[] = $slug;
            }
        }
        sort($slugs);
        return $slugs;
    }
}
