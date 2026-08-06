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
     * Per Umgebungsvariable übersteuerbar (Tests/Staging), Default: offizielle
     * Releases dieses Projekts.
     */
    private const DEFAULT_RELEASES_URL = 'https://api.github.com/repos/Celestial0579/Hengstverzeichnis_Framework/releases/latest';

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
     * Prüft das neueste GitHub-Release gegen die laufende Version.
     *
     * @return array{current:string, latest:string, update_available:bool, zip_url:?string, html_url:?string}
     * @throws \RuntimeException bei Netzwerk-/API-Fehlern
     */
    public static function checkForUpdate(): array {
        $release = self::fetchLatestRelease();
        $latest = self::normalizeVersion((string)($release['tag_name'] ?? ''));
        if ($latest === '') {
            throw new \RuntimeException('Antwort der Release-API enthielt keinen Versions-Tag.');
        }

        $zipUrl = null;
        foreach ((array)($release['assets'] ?? []) as $asset) {
            $name = (string)($asset['name'] ?? '');
            if (preg_match('/^hengstverzeichnis-framework-.*\.zip$/', $name) === 1) {
                $zipUrl = (string)($asset['browser_download_url'] ?? '');
                break;
            }
        }

        return [
            'current' => self::currentVersion(),
            'latest' => $latest,
            'update_available' => self::isNewer($latest, self::currentVersion()),
            'zip_url' => $zipUrl !== '' ? $zipUrl : null,
            'html_url' => isset($release['html_url']) ? (string)$release['html_url'] : null,
        ];
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
            throw new \RuntimeException("Kein Update verfügbar: Version {$check['current']} ist aktuell (neuestes Release: {$check['latest']}).");
        }
        if (empty($check['zip_url'])) {
            throw new \RuntimeException('Das neueste Release enthält kein Shared-Hosting-Zip als Asset.');
        }

        // Pflicht-Backup: wirft bei jedem Fehler und bricht das Update damit ab.
        AuditLogger::log('Update: Pflicht-Backup wird ausgeführt', 'update', "Vor Update auf {$check['latest']}");
        BackupService::run();

        $zipPath = self::downloadToTempFile($check['zip_url']);
        try {
            $baseDir = dirname(__DIR__, 2);
            $files = self::applyUpdateArchive($zipPath, $baseDir);
        } finally {
            @unlink($zipPath);
        }

        AuditLogger::log('Update angewendet', 'update', "Von {$check['current']} auf {$check['latest']}, {$files} Dateien aktualisiert");

        return ['from' => $check['current'], 'to' => $check['latest'], 'files' => $files];
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

        try {
            self::extractArchive($zipPath, $extractDir);

            // Wurzel des entpackten Codes ermitteln: entweder genau ein
            // Verzeichnis (git archive --prefix) oder direkt die Dateien.
            $entries = array_values(array_diff(scandir($extractDir) ?: [], ['.', '..']));
            $sourceDir = (count($entries) === 1 && is_dir($extractDir . '/' . $entries[0]))
                ? $extractDir . '/' . $entries[0]
                : $extractDir;

            return self::copyTree($sourceDir, rtrim($targetDir, '/'), '');
        } finally {
            self::removeTree($extractDir);
        }
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

    private static function copyTree(string $sourceDir, string $targetDir, string $relative): int {
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
                if (!is_dir($dst) && !mkdir($dst, 0755, true) && !is_dir($dst)) {
                    throw new \RuntimeException("Verzeichnis konnte nicht angelegt werden: {$relPath}");
                }
                $copied += self::copyTree($sourceDir, $targetDir, $relPath);
            } else {
                if (!copy($src, $dst)) {
                    throw new \RuntimeException("Datei konnte nicht kopiert werden: {$relPath}");
                }
                $copied++;
            }
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

    private static function releasesUrl(): string {
        $override = getenv('UPDATE_RELEASES_URL');
        return $override !== false && $override !== '' ? $override : self::DEFAULT_RELEASES_URL;
    }

    /**
     * @return array<string, mixed>
     */
    private static function fetchLatestRelease(): array {
        $raw = self::httpGet(self::releasesUrl(), ['Accept: application/vnd.github+json']);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Antwort der Release-API war kein gültiges JSON.');
        }
        return $data;
    }

    private static function downloadToTempFile(string $url): string {
        $body = self::httpGet($url, ['Accept: application/octet-stream'], 300);
        $tmp = tempnam(sys_get_temp_dir(), 'hengst_update_zip_');
        if ($tmp === false || file_put_contents($tmp, $body) === false) {
            throw new \RuntimeException('Release-Zip konnte nicht zwischengespeichert werden.');
        }
        return $tmp;
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
