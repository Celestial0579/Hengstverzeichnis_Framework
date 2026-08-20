<?php
// src/Service/AddonOverview.php

namespace App\Service;

use App\Database;
use App\Plugin\PluginManager;

/**
 * Gemeinsame Datenbasis für "Addons mitdenken" (#197, Stufe 1): verbindet
 * die lokal installierten Plugins (PluginManager-Discovery) mit dem
 * Katalog des OFFIZIELLEN Addon-Repos und beantwortet die Fragen der
 * Update-Seite und des Dashboards:
 *
 *   - Welche Addon-Updates sind offen? (installierte vs. Katalog-Version,
 *     dieselbe Logik wie das Store-Badge)
 *   - Passt jedes Addon zur LAUFENDEN Kern-Version - und zur ZIELversion
 *     eines anstehenden Kern-Updates? (PluginManager::incompatibilityReason,
 *     parametrisiert; deckt den bisher stummen Skip beim Laden auf)
 *
 * Bewusst NUR aus dem Katalog-Cache (addon_repos.cached_catalog_json,
 * 15-Minuten-TTL): Diese Klasse fragt selbst nie GitHub. Aufgefrischt wird
 * der Cache von ihren Aufrufern - beim Store-Aufruf bzw. ?refresh=1 für alle
 * Repos, und seit #290 über AddonUpdateService::refreshOfficialCatalog()
 * auch von der Update-Seite und dem Cron-Lauf für das offizielle Repo.
 * Bleibt der Cache leer (Netz-/DB-Fehler), liefert officialCatalogFromCache()
 * 'available' => false statt einer Falschaussage.
 */
final class AddonOverview {

    /**
     * Katalog des offiziellen Repos aus dem Cache - nie aus dem Netz.
     *
     * @return array{available: bool, cachedAt: ?string, bySlug: array<string, array<string, mixed>>}
     */
    public static function officialCatalogFromCache(): array {
        $none = ['available' => false, 'cachedAt' => null, 'bySlug' => []];
        try {
            $db = Database::getInstance();
            $row = $db->query("SELECT cached_catalog_json, cached_at FROM addon_repos WHERE is_official = 1 LIMIT 1")->fetch();
        } catch (\Throwable $e) {
            return $none; // Setup-Modus/ohne DB: keine Aussage, kein Fehler
        }
        if (!$row || $row['cached_catalog_json'] === null) {
            return $none;
        }
        $decoded = json_decode((string)$row['cached_catalog_json'], true);
        if (!is_array($decoded)) {
            return $none;
        }
        $bySlug = [];
        foreach ($decoded as $entry) {
            if (is_array($entry) && is_string($entry['slug'] ?? null)) {
                $bySlug[$entry['slug']] = $entry;
            }
        }
        return ['available' => true, 'cachedAt' => $row['cached_at'], 'bySlug' => $bySlug];
    }

    /**
     * Eine Zeile je installiertem Plugin, angereichert um Katalog-Version und
     * Kompatibilität gegen die laufende sowie optional eine Ziel-Kern-Version.
     *
     * @return array<int, array{
     *   slug: string, name: string, installedVersion: string,
     *   availableVersion: ?string, hasUpdate: bool, enabled: bool,
     *   manifestError: ?string, reasonCurrent: ?string, reasonTarget: ?string,
     *   availableSupportsTarget: ?bool
     * }>
     */
    public static function rows(?string $targetVersion = null): array {
        $manager = PluginManager::getInstance();
        $catalog = self::officialCatalogFromCache();
        $current = defined('CORE_VERSION') ? CORE_VERSION : '';

        $rows = [];
        foreach ($manager->getDiscoveredPlugins() as $slug => $info) {
            $manifest = is_array($info['manifest'] ?? null) ? $info['manifest'] : [];
            $installedVersion = (string)($manifest['version'] ?? '');
            $catalogEntry = $catalog['bySlug'][$slug] ?? null;
            $availableVersion = is_string($catalogEntry['version'] ?? null) ? $catalogEntry['version'] : null;

            // Dieselbe Update-Erkennung wie das Store-Badge ($hasUpdate in
            // admin_addon_store.php): Katalog-Version strikt neuer als die
            // installierte.
            $hasUpdate = $availableVersion !== null
                && $installedVersion !== ''
                && version_compare($availableVersion, $installedVersion, '>');

            $manifestError = $info['error'] ?? null;
            $rows[] = [
                'slug' => (string)$slug,
                'name' => (string)($manifest['name'] ?? $slug),
                'installedVersion' => $installedVersion,
                'availableVersion' => $availableVersion,
                'hasUpdate' => $hasUpdate,
                'enabled' => $manager->isEnabled((string)$slug),
                'manifestError' => is_string($manifestError) ? $manifestError : null,
                'reasonCurrent' => $manifestError === null
                    ? PluginManager::incompatibilityReason($manifest, $current)
                    : null,
                // Entscheidend (#197): gegen die ZIELversion des anstehenden
                // Kern-Updates prüfen, nicht nur gegen die laufende - sonst
                // verschwindet ein Addon nach dem Update kommentarlos.
                'reasonTarget' => ($manifestError === null && $targetVersion !== null && $targetVersion !== '')
                    ? PluginManager::incompatibilityReason($manifest, $targetVersion)
                    : null,
                // Gäbe es für die Zielversion überhaupt eine passende
                // Addon-Fassung? (#364)
                //
                // Das ist die Frage, an der sich entscheidet, ob ein Update
                // Aufsicht braucht: Nicht der Versionssprung an sich ist das
                // Problem, sondern ein veraltetes Addon, für das es keinen
                // Ersatz gibt. Wo eine passende Fassung bereitliegt, zieht die
                // Addon-Phase sie nach dem Kern von selbst mit.
                //
                // null heisst ausdrücklich "keine Aussage" - kein Katalog, kein
                // Eintrag, keine Zielversion. Die Aufrufer behandeln das wie
                // "kein Update möglich", also strenger: "konnte nicht prüfen"
                // ist nicht "geprüft, ist in Ordnung".
                'availableSupportsTarget' => self::availableSupportsTarget($catalogEntry, $targetVersion),
            ];
        }
        return $rows;
    }

    /**
     * Unterstützt die im Katalog verfügbare Fassung eines Addons die
     * Zielversion des anstehenden Kern-Updates? (#364)
     *
     * @param array<string, mixed>|null $catalogEntry
     */
    private static function availableSupportsTarget(?array $catalogEntry, ?string $targetVersion): ?bool {
        if ($catalogEntry === null || $targetVersion === null || $targetVersion === '') {
            return null;
        }
        // Der Katalog führt core_compatibility und core_supported_max mit
        // (Whitelist in GithubAddonRepository) - genau die beiden Felder, die
        // incompatibilityReason() braucht.
        if (!isset($catalogEntry['core_supported_max'], $catalogEntry['core_compatibility'])) {
            return null;
        }
        return PluginManager::incompatibilityReason($catalogEntry, $targetVersion) === null;
    }

    /**
     * Anzahl offener Addon-Updates - netzwerkfrei, für das Dashboard-Badge.
     * Ohne (brauchbaren) Katalog-Cache bewusst 0: "keine Aussage" darf nicht
     * wie "Updates offen" aussehen.
     */
    public static function openUpdateCount(): int {
        $count = 0;
        foreach (self::rows() as $row) {
            if ($row['hasUpdate']) {
                $count++;
            }
        }
        return $count;
    }
}
