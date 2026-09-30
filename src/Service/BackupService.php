<?php
// src/Service/BackupService.php

namespace App\Service;

use App\Database;
use App\Helper\LongRunning;
use App\Security\Crypto;

/**
 * Class BackupService
 *
 * Automatisierte externe Backups (#59, #93): sichert die Datenbank
 * periodisch an ein von drei wählbaren Zielen - als Kernfunktion, nicht als
 * optionales Plugin. Baut auf der Cron-/Scheduler-Infrastruktur (#67,
 * App\Service\Scheduler) auf.
 *
 * Drei Ziel-Typen (`backup_target`-Einstellung, siehe TARGET_*-Konstanten):
 * - `s3` (Standard, Ursprungsimplementierung aus #59): S3-kompatibler
 *   Objektspeicher (AWS S3, MinIO, Hetzner Object Storage o. Ä.).
 * - `ftps` (#93): FTPS-Zugang, z. B. bereits beim Hoster vorhanden.
 * - `webdav` (#93): WebDAV, z. B. eine bereits genutzte Nextcloud-/
 *   ownCloud-Instanz des Vereins.
 * Die eigentliche Übertragung ist hinter App\Service\BackupTarget
 * abstrahiert (S3Client/FtpsClient/WebDavClient) - der Rest dieser Klasse
 * (Dump-Erzeugung, Aufbewahrungsrotation, Status-Protokollierung) ist
 * bewusst komplett ziel-unabhängig.
 *
 * Uploads (#233): Opt-in-Einstellung `backup_include_uploads` - zusätzlich
 * zum SQL-Dump wird ein tar-Archiv von public/uploads (Logos/Pferdebilder/
 * Galerie-Dateien, siehe App\Service\TarArchive) ans selbe Ziel hochgeladen,
 * mit derselben Aufbewahrungsrotation wie der SQL-Dump. Standard bleibt
 * "aus": Die Zucht-/Blutliniendaten in der Datenbank sind der eigentlich
 * unwiederbringliche Teil, das Uploads-Archiv kann je nach Bildbestand groß
 * werden.
 *
 * Addon-Ablagen (Audit N27): Das Uploads-Archiv enthält zusätzlich die
 * Verzeichnisse, die Addons in ihrem Datenregister (`owns.directories`, #338)
 * deklarieren - etwa `storage/plugin_gesundheitstests` - unter ihrem Pfad
 * relativ zur Installationswurzel. Einzige Quelle ist
 * PluginManager::datenRegister() aller entdeckten Addons (auch deaktivierter:
 * ihre Tabellen stehen ohnehin im Dump). Vor einer Deinstallation mit
 * Datenlöschung übergibt der PluginManager die zu löschenden Verzeichnisse
 * an run(); ist die Uploads-Option aus, landen sie in einem eigenen Objekt
 * `addondaten-…` mit eigener Rotation.
 */
final class BackupService {

    private const TASK_NAME = 'backup.external';
    private const DEFAULT_INTERVAL_HOURS = 24;
    private const DEFAULT_RETENTION_COUNT = 14;
    private const OBJECT_PREFIX = 'backups/';

    /** Zwischendateien, die länger liegen, gelten als verwaist (Audit M37). */
    private const VERWAISTE_TEMP_DATEIEN_NACH_SEKUNDEN = 86400;

    /** Sperre "eine Sicherung zur Zeit" (siehe run()) und wie lange darauf gewartet wird. */
    private const RUN_LOCK = 'backup:run';
    private const RUN_LOCK_WAIT_SECONDS = 30;

    /** @var array<string, true> Pfad => true, siehe tempFile() */
    private static array $offeneTempDateien = [];
    private static bool $aufraeumenRegistriert = false;

    public const TARGET_S3 = 's3';
    public const TARGET_FTPS = 'ftps';
    public const TARGET_WEBDAV = 'webdav';

    /**
     * Registriert die Backup-Aufgabe beim Scheduler, falls in den
     * Admin-Einstellungen aktiviert und vollständig konfiguriert (siehe
     * isConfigured()) - wird bei jedem Request-Bootstrap aufgerufen
     * (public/index.php), analog zur Registrierung von Plugin-Hooks.
     */
    public static function registerScheduledTask(): void {
        $settings = self::loadSettings();
        if (!self::isConfigured($settings)) {
            return;
        }

        // Beim Lesen geklemmt (Audit N67), damit auch ein früher
        // gespeicherter Riesenwert heilt, ohne Datenschritt.
        $intervalHours = Scheduler::clampIntervalHours($settings['backup_interval_hours'] ?? self::DEFAULT_INTERVAL_HOURS);
        Scheduler::register(self::TASK_NAME, $intervalHours * 3600, [self::class, 'run']);
    }

    /**
     * @param array<string, string> $settings
     */
    public static function isConfigured(array $settings): bool {
        if (($settings['backup_enabled'] ?? '') !== '1') {
            return false;
        }

        return match (self::targetType($settings)) {
            self::TARGET_FTPS => trim($settings['backup_ftps_host'] ?? '') !== ''
                && trim($settings['backup_ftps_user'] ?? '') !== ''
                && trim($settings['backup_ftps_pass'] ?? '') !== '',
            self::TARGET_WEBDAV => trim($settings['backup_webdav_url'] ?? '') !== ''
                && trim($settings['backup_webdav_user'] ?? '') !== ''
                && trim($settings['backup_webdav_pass'] ?? '') !== '',
            default => trim($settings['backup_s3_endpoint'] ?? '') !== ''
                && trim($settings['backup_s3_bucket'] ?? '') !== ''
                && trim($settings['backup_s3_access_key'] ?? '') !== ''
                && trim($settings['backup_s3_secret_key'] ?? '') !== '',
        };
    }

    /**
     * @param array<string, string> $settings
     */
    private static function targetType(array $settings): string {
        $target = $settings['backup_target'] ?? self::TARGET_S3;
        return in_array($target, [self::TARGET_S3, self::TARGET_FTPS, self::TARGET_WEBDAV], true)
            ? $target
            : self::TARGET_S3;
    }

    /**
     * Führt einen einzelnen Backup-Lauf durch. Wird vom Scheduler
     * (Cron-Trigger/manueller Admin-Klick), von AdminController::testBackup(),
     * vom Pflicht-Backup vor einem Update (UpdateService) und vor dem Löschen
     * der Daten eines Addons (PluginManager) aufgerufen.
     *
     * Reihenfolge (Audit N65):
     * 1. Datenbank-Dump streamend (#231) in eine Temp-Datei, geprüft
     *    (CheckedFileWriter, Audit M43), hochladen, Temp-Datei sofort löschen.
     * 2. Nur bei aktivierter Option (#233): Uploads-Archiv genauso.
     *    Es enthält auch die Addon-Ablagen aus den Datenregistern (Audit N27).
     * 2a. Ist die Option aus und wurden $pflichtVerzeichnisse übergeben (vor
     *    einer Deinstallation mit Datenlöschung): ein eigenes Objekt
     *    'addondaten-…' nur mit diesen Verzeichnissen.
     * 3. Status: 'ok', oder 'partial', wenn nur ein Datei-Archiv scheiterte
     *    (der Dump liegt dann am Ziel). Jeder andere Fehler: 'error'.
     * 4. Aufbewahrungsrotation - nur nach verifiziertem Upload; ihr Fehler
     *    landet im Audit-Log und macht den Lauf nicht zum Fehlschlag.
     *
     * @throws \RuntimeException bei JEDEM Fehler, auch beim Teilfehler
     *         ('partial'): nicht konfiguriert, eine andere Sicherung läuft
     *         noch, Dump/Archiv/Upload gescheitert. Die Aufrufer verlassen
     *         sich darauf - das Pflicht-Backup vor einem Update bricht ab,
     *         der Scheduler protokolliert den Fehler.
     *
     * @param string[] $pflichtVerzeichnisse Absolute Verzeichnisse, die in
     *        DIESEM Lauf unabhängig von der Uploads-Option gesichert werden
     *        müssen (Audit N27: die Ablage eines Addons vor dem Löschen).
     *        Optional, damit Scheduler (Aufruf ohne Argumente), "Jetzt
     *        sichern" und das Pflicht-Backup vor einem Update unverändert
     *        bleiben.
     */
    public static function run(array $pflichtVerzeichnisse = []): void {
        $settings = self::loadSettings();
        if (!self::isConfigured($settings)) {
            throw new \RuntimeException('Backup ist nicht (vollständig) konfiguriert.');
        }

        // Audit M37: Die Sicherung läuft auch aus dem Web-Request (Cron,
        // "Jetzt sichern", Pflicht-Backup vor Update, Addon-Deinstallation).
        // Ohne php.ini gelten dort 30 s Rechenzeit, gzip eines gewachsenen
        // Bildbestands überschreitet das. Hier statt bei jedem Aufrufer, damit
        // kein Einstiegspunkt es vergisst.
        LongRunning::allow();
        self::verwaisteTempDateienEntfernen();

        // Eine Sicherung zur Zeit. Die Cron-Sperre des Schedulers deckt nur
        // den Cron-Weg ab; "Jetzt sichern", Pflicht-Backup und Deinstallation
        // rufen run() direkt auf. Zwei Läufe in derselben Sekunde hätten
        // denselben Schlüssel, und das Aufräumen eines gescheiterten Uploads
        // (siehe hochladen()) könnte das gute Objekt des anderen Laufs löschen.
        $sperre = DbLock::acquire(self::RUN_LOCK, self::$sperrWartezeitOverride ?? self::RUN_LOCK_WAIT_SECONDS);
        if ($sperre === false) {
            throw new \RuntimeException('Es läuft bereits eine Sicherung - bitte nach deren Ende erneut versuchen.');
        }
        // null: GET_LOCK gibt es auf dieser Plattform nicht (DbLock). Die
        // Sicherung läuft trotzdem - ohne Sicherung wäre schlimmer. Weil sich
        // ein paralleler Lauf dann nicht ausschließen lässt, räumt sie ein
        // halb hochgeladenes Objekt am Ziel aber nicht selbst weg.
        try {
            self::lauf($settings, $sperre === true, $pflichtVerzeichnisse);
        } finally {
            if ($sperre === true) {
                DbLock::release(self::RUN_LOCK);
            }
        }
    }

    /**
     * @param array<string, string> $settings
     * @param string[] $pflichtVerzeichnisse siehe run()
     */
    private static function lauf(array $settings, bool $exklusiv, array $pflichtVerzeichnisse = []): void {
        $useGzip = function_exists('gzopen');
        $stamp = gmdate('Y-m-d_His');

        // 1. Datenbank. Jeder Fehler hier - auch beim Aufbau des Clients -
        // wird als Status erfasst, sonst zeigte /admin/backups weiter den
        // letzten Erfolg (Audit N65).
        try {
            $client = self::buildClient($settings);
            $dumpFile = self::tempFile('hv-backup-sql-');
            try {
                self::writeDumpFile($dumpFile, $useGzip);
                // Streamender Upload (#237): Die Ziel-Clients übernehmen die
                // fertige, geprüfte Temp-Datei direkt - der Inhalt liegt nie
                // als Gesamtstring im Speicher.
                self::hochladen(
                    $client,
                    self::OBJECT_PREFIX . 'backup-' . $stamp . ($useGzip ? '.sql.gz' : '.sql'),
                    $dumpFile,
                    $useGzip ? 'application/gzip' : 'application/sql',
                    $exklusiv
                );
            } finally {
                // Sofort weg: Im Temp-Verzeichnis liegt so höchstens eine der
                // beiden Zwischendateien, nie Dump und Archiv zugleich.
                self::tempDateiEntfernen($dumpFile);
            }
        } catch (\Throwable $e) {
            self::recordStatusSicher('error', $e->getMessage());
            throw $e;
        }

        // 2. Uploads-Archiv (#233, Opt-in). Ein Fehler hier nimmt dem
        // bereits gesicherten Dump nichts - er wird gemerkt, nicht sofort
        // geworfen.
        $uploadsFehler = null;
        $archivArt = 'Uploads-Archiv';
        $pflicht = self::archivNamen($pflichtVerzeichnisse);
        if (self::includeUploads($settings)) {
            $uploadsFile = null;
            try {
                $uploadsFile = self::tempFile('hv-backup-uploads-');
                // Das Vollarchiv enthält die Pflichtverzeichnisse ohnehin -
                // sie werden trotzdem ausdrücklich übergeben, falls das
                // Register eines Addons zwischenzeitlich nicht lesbar war.
                self::writeUploadsArchive($uploadsFile, $useGzip, $pflicht);
                self::hochladen(
                    $client,
                    self::OBJECT_PREFIX . 'uploads-' . $stamp . ($useGzip ? '.tar.gz' : '.tar'),
                    $uploadsFile,
                    $useGzip ? 'application/gzip' : 'application/x-tar',
                    $exklusiv
                );
            } catch (\Throwable $e) {
                $uploadsFehler = $e;
            } finally {
                if ($uploadsFile !== null) {
                    self::tempDateiEntfernen($uploadsFile);
                }
            }
        } elseif ($pflicht !== []) {
            // 2a. Addon-Daten vor dem Löschen (Audit N27). Eigenes Präfix
            // statt 'uploads-': In der Rotation zählte ein Teilarchiv sonst
            // als Uploads-Sicherung und verdrängte ein echtes Vollarchiv.
            $addonFile = null;
            try {
                $addonFile = self::tempFile('hv-backup-addondaten-');
                self::writeAddonArchive($addonFile, $useGzip, $pflicht);
                self::hochladen(
                    $client,
                    self::OBJECT_PREFIX . 'addondaten-' . $stamp . ($useGzip ? '.tar.gz' : '.tar'),
                    $addonFile,
                    $useGzip ? 'application/gzip' : 'application/x-tar',
                    $exklusiv
                );
            } catch (\Throwable $e) {
                $uploadsFehler = $e;
                $archivArt = 'Addon-Daten-Archiv';
            } finally {
                if ($addonFile !== null) {
                    self::tempDateiEntfernen($addonFile);
                }
            }
        }

        // 3. Status.
        if ($uploadsFehler === null) {
            self::recordStatusSicher('ok', null);
        } else {
            self::recordStatusSicher(
                'partial',
                $archivArt . ' nicht gesichert (Datenbank-Dump wurde gesichert): ' . $uploadsFehler->getMessage()
            );
        }

        // 4. Aufbewahrungsrotation ist ein separater, nicht-kritischer Schritt:
        // ein bereits erfolgreich hochgeladenes Backup gilt unabhängig davon
        // als Erfolg (Datensicherheit erreicht), ein Rotationsfehler wird nur
        // protokolliert, nicht als Gesamtfehler des Laufs gewertet. Auch nach
        // 'partial': Der neue Dump ist verifiziert am Ziel, und für die Art
        // 'uploads-' kam kein neues Objekt hinzu - dort wird nichts
        // zusätzlich gelöscht.
        try {
            self::applyRetention($client, $settings);
        } catch (\Throwable $e) {
            AuditLogger::log('Backup-Aufbewahrungsrotation fehlgeschlagen', 'settings', $e->getMessage());
        }

        if ($uploadsFehler !== null) {
            throw new \RuntimeException(
                'Datenbank gesichert, ' . $archivArt . ' fehlgeschlagen: ' . $uploadsFehler->getMessage(),
                0,
                $uploadsFehler
            );
        }
    }

    /**
     * Lädt eine geprüfte Zwischendatei hoch. Scheitert das bei WebDAV oder
     * FTPS, kann am Ziel ein angefangenes Objekt liegen - in der Rotation
     * zählte es als neuestes und verdrängte ein intaktes. Deshalb wird der
     * eigene, gerade erzeugte Schlüssel best effort wieder gelöscht (Audit
     * N65), aber nur, wenn dieser Lauf exklusiv ist (siehe run()). Ein
     * S3-PUT ist atomar, dort entfällt der Schritt.
     */
    private static function hochladen(BackupTarget $client, string $key, string $file, string $contentType, bool $exklusiv): void {
        try {
            $client->putObjectFromFile($key, $file, $contentType);
        } catch (\Throwable $e) {
            if ($exklusiv && ($client instanceof WebDavClient || $client instanceof FtpsClient)) {
                try {
                    $client->deleteObject($key);
                } catch (\Throwable $ignoriert) {
                    // Best effort - die eigentliche Ursache ist $e.
                }
            }
            throw $e;
        }
    }

    /**
     * Aufbewahrungsrotation, getrennt je Backup-Art (#233): SQL-Dumps
     * (`backup-…`), Uploads-Archive (`uploads-…`) und die Addon-Daten vor
     * einer Deinstallation (`addondaten-…`, Audit N27) werden unabhängig
     * voneinander auf die konfigurierte Anzahl gehalten - sonst würde ein
     * Lauf mit beiden Objekten die effektive Dump-Aufbewahrung halbieren.
     * Uploads-Archive rotieren auch dann weiter, wenn die Option inzwischen
     * deaktiviert ist (es kommen dann schlicht keine neuen hinzu).
     *
     * @param array<string, string> $settings
     */
    private static function applyRetention(BackupTarget $client, array $settings): void {
        $keepCount = max(1, (int)($settings['backup_retention_count'] ?? self::DEFAULT_RETENTION_COUNT));
        $objects = $client->listObjects(self::OBJECT_PREFIX);

        foreach (['backup-', 'uploads-', 'addondaten-'] as $kindPrefix) {
            // listObjects() liefert aufsteigend nach Schlüssel sortiert - durch
            // das "<Art>-<ISO-Zeitstempel>"-Namensschema entspricht das je Art
            // der chronologischen Reihenfolge, älteste zuerst.
            $kind = array_values(array_filter(
                $objects,
                fn(array $object) => str_starts_with($object['key'], self::OBJECT_PREFIX . $kindPrefix)
            ));
            $excess = count($kind) - $keepCount;
            for ($i = 0; $i < $excess; $i++) {
                $client->deleteObject($kind[$i]['key']);
            }
        }
    }

    /**
     * @param array<string, string> $settings
     */
    private static function includeUploads(array $settings): bool {
        return ($settings['backup_include_uploads'] ?? '') === '1';
    }

    /**
     * Schreibt den Datenbank-Dump streamend (#231) in die Zieldatei -
     * gzip-komprimiert, sofern die zlib-Extension vorhanden ist.
     */
    private static function writeDumpFile(string $path, bool $gzip): void {
        // Jeder Schreibvorgang und der gzip-Abschluss werden geprüft (Audit
        // M43) - gzwrite() meldet einen vollen Datenträger sonst nicht, und
        // eine abgeschnittene Datei ginge als Sicherung durch.
        $writer = CheckedFileWriter::open($path, $gzip, 9);
        try {
            DatabaseDumper::dumpTo(function (string $chunk) use ($writer): void {
                $writer->write($chunk);
            });
        } catch (\Throwable $e) {
            $writer->abort();
            throw $e;
        }
        $writer->close();
    }

    /**
     * Baut das tar(.gz)-Archiv des Uploads-Verzeichnisses streamend in die
     * Zieldatei (#233). Ein fehlendes oder leeres Uploads-Verzeichnis ergibt
     * ein gültiges leeres Archiv - der Lauf bleibt damit deterministisch,
     * statt je nach Instanzzustand Objekte auszulassen.
     */
    /**
     * @param array<string, string> $pflicht Archivname => absoluter Pfad (siehe archivNamen())
     */
    private static function writeUploadsArchive(string $path, bool $gzip, array $pflicht = []): void {
        $archive = TarArchive::create($path, $gzip);
        try {
            self::archivFuellen($archive, $pflicht);
            // close() prüft den Abschluss der Datei (Audit M43).
            $archive->close();
        } catch (\Throwable $e) {
            $archive->abort();
            throw $e;
        }
    }

    /**
     * @param array<string, string> $pflicht Archivname => absoluter Pfad
     */
    private static function archivFuellen(TarArchive $archive, array $pflicht = []): void {
        $dir = self::uploadsDir();
        if (is_dir($dir)) {
            $archive->addDirectoryTree($dir, 'uploads');
        }
        // Pferdefotos liegen seit #366 außerhalb des Webroots und damit
        // außerhalb von public/uploads. Ohne diese zweite Zeile enthielte
        // "Hochgeladene Dateien mitsichern" plötzlich keine Pferdefotos mehr -
        // und das fiele erst beim Zurückspielen auf. Sie landen im Archiv an
        // ihrer alten Stelle (uploads/horses), damit der Inhalt derselbe
        // bleibt wie vor der Verschiebung.
        $horses = \App\Helper\HorseImagePath::dir();
        if (is_dir($horses)) {
            $archive->addDirectoryTree($horses, 'uploads/horses');
        }
        // Addon-Ablagen aus den Datenregistern (Audit N27). Dasselbe Muster
        // wie oben bei #366: Liegen Dateien außerhalb von public/uploads,
        // fehlen sie sonst in "Hochgeladene Dateien mitsichern" - und die
        // Tabellenzeilen im Dump zeigen nach dem Zurückspielen ins Leere.
        foreach (self::verzeichnisseZusammenfuehren(self::addonVerzeichnisse(), $pflicht) as $name => $abs) {
            if (is_dir($abs)) {
                $archive->addDirectoryTree($abs, $name);
            }
        }
    }

    /**
     * Das Archiv `addondaten-…` (Audit N27): nur die übergebenen
     * Verzeichnisse, unter denselben Namen wie im Uploads-Archiv.
     *
     * @param array<string, string> $verzeichnisse Archivname => absoluter Pfad
     */
    private static function writeAddonArchive(string $path, bool $gzip, array $verzeichnisse): void {
        $archive = TarArchive::create($path, $gzip);
        try {
            foreach (self::verzeichnisseZusammenfuehren([], $verzeichnisse) as $name => $abs) {
                if (is_dir($abs)) {
                    $archive->addDirectoryTree($abs, $name);
                }
            }
            $archive->close();
        } catch (\Throwable $e) {
            $archive->abort();
            throw $e;
        }
    }

    /**
     * Die Ablageverzeichnisse aller entdeckten Addons laut Datenregister
     * (Audit N27), als Archivname => absoluter Pfad.
     *
     * - Einzige Quelle ist PluginManager::datenRegister(): Die Pfade dort
     *   sind per realpath geprüft, liegen in der Installation und sind keine
     *   geschützten Orte (#338).
     * - Alle entdeckten Addons zählen, auch deaktivierte: Deaktivieren lässt
     *   die Daten stehen, und ihre Tabellen sind ohnehin im Dump.
     * - Ein Fehler hier darf die Sicherung nicht abbrechen - er landet im
     *   Audit-Log, die Liste bleibt leer.
     *
     * @return array<string, string>
     */
    private static function addonVerzeichnisse(): array {
        try {
            if (self::$addonDirsOverride !== null) {
                $liste = self::$addonDirsOverride instanceof \Closure
                    ? (self::$addonDirsOverride)()
                    : self::$addonDirsOverride;
                return is_array($liste) ? $liste : [];
            }

            $manager = \App\Plugin\PluginManager::getInstance();
            $entdeckt = $manager->getDiscoveredPlugins();
            if ($entdeckt === []) {
                // Vor PluginManager::boot() ist die Liste leer. Im Web- und
                // Cron-Pfad ist boot() gelaufen (public/index.php); liegt
                // trotzdem ein Addon da, soll das auffallen statt still
                // nichts zu sichern.
                if ((glob(self::wurzel() . '/plugins/*/plugin.json') ?: []) !== []) {
                    AuditLogger::log(
                        'Sicherung: Addon-Verzeichnisse nicht ermittelbar',
                        'settings',
                        'Die Addons waren in diesem Aufruf nicht geladen - Addon-Ablagen (owns.directories) fehlen in diesem Archiv.'
                    );
                }
                return [];
            }

            $pfade = [];
            foreach (array_keys($entdeckt) as $slug) {
                foreach ($manager->datenRegister((string)$slug)['directories'] as $abs) {
                    $pfade[] = $abs;
                }
            }
            return self::archivNamen($pfade);
        } catch (\Throwable $e) {
            try {
                AuditLogger::log('Sicherung: Addon-Verzeichnisse nicht ermittelbar', 'settings', $e->getMessage());
            } catch (\Throwable $ignoriert) {
                // Protokoll nicht schreibbar - die Sicherung geht vor.
            }
            return [];
        }
    }

    /**
     * Archivnamen für absolute Verzeichnisse: der Pfad relativ zur
     * Installationswurzel (`storage/plugin_gesundheitstests`), damit sich das
     * Archiv dort wieder entpacken lässt. Was außerhalb liegt (nur in Tests
     * denkbar - das Register lässt es nicht zu), landet unter
     * `addondaten/<Name>`.
     *
     * @param string[] $pfade
     * @return array<string, string> Archivname => absoluter Pfad
     */
    private static function archivNamen(array $pfade): array {
        $wurzel = realpath(self::wurzel());
        $namen = [];
        foreach ($pfade as $abs) {
            if (!is_string($abs) || $abs === '') {
                continue;
            }
            $echt = realpath($abs);
            if ($echt === false) {
                continue;
            }
            $name = ($wurzel !== false && str_starts_with($echt, $wurzel . '/'))
                ? substr($echt, strlen($wurzel) + 1)
                : 'addondaten/' . basename($echt);
            $namen[$name] = $echt;
        }
        return $namen;
    }

    /**
     * Führt Verzeichnislisten zusammen: doppelte Namen einmal, und ein
     * Verzeichnis INNERHALB eines bereits aufgenommenen entfällt - sonst
     * stünden seine Dateien zweimal im Archiv.
     *
     * @param array<string, string> ...$listen Archivname => absoluter Pfad
     * @return array<string, string>
     */
    private static function verzeichnisseZusammenfuehren(array ...$listen): array {
        $alle = [];
        foreach ($listen as $liste) {
            foreach ($liste as $name => $abs) {
                $alle[(string)$name] = (string)$abs;
            }
        }
        ksort($alle, SORT_STRING);
        $ergebnis = [];
        foreach ($alle as $name => $abs) {
            foreach ($ergebnis as $schonName => $schonAbs) {
                if (str_starts_with($name . '/', $schonName . '/') || str_starts_with($abs . '/', $schonAbs . '/')) {
                    continue 2;
                }
            }
            $ergebnis[$name] = $abs;
        }
        return $ergebnis;
    }

    private static function wurzel(): string {
        return dirname(__DIR__, 2);
    }

    /** @var array<string, string>|\Closure|null */
    private static array|\Closure|null $addonDirsOverride = null;

    /**
     * Nur für Tests: die Addon-Ablagen festlegen (Archivname => absoluter
     * Pfad), statt sie aus den Datenregistern zu lesen - analog
     * overrideUploadsDirForTests(). Eine Closure wird bei jedem Lauf
     * aufgerufen (etwa um einen Fehler zu simulieren). `null` stellt den
     * Normalzustand wieder her.
     *
     * @param array<string, string>|\Closure|null $dirs
     */
    public static function overrideAddonDirsForTests(array|\Closure|null $dirs): void {
        self::$addonDirsOverride = $dirs;
    }

    private static ?string $uploadsDirOverride = null;

    /**
     * Nur für Tests: das zu sichernde Uploads-Verzeichnis umbiegen (analog
     * Scheduler::resetForTests()), damit Integrationstests nicht vom echten
     * public/uploads des Arbeitsverzeichnisses abhängen. `null` stellt den
     * Normalzustand wieder her.
     */
    public static function overrideUploadsDirForTests(?string $dir): void {
        self::$uploadsDirOverride = $dir;
    }

    private static ?int $sperrWartezeitOverride = null;

    /**
     * Nur für Tests: wie lange run() auf eine laufende Sicherung wartet.
     * `null` stellt den Normalzustand wieder her.
     */
    public static function overrideLockWaitForTests(?int $seconds): void {
        self::$sperrWartezeitOverride = $seconds;
    }

    private static function uploadsDir(): string {
        return self::$uploadsDirOverride ?? dirname(__DIR__, 2) . '/public/uploads';
    }

    /**
     * Legt eine Zwischendatei an und merkt sie sich (Audit M37). Eine
     * Shutdown-Funktion löscht alle noch gemerkten Dateien - sie läuft auch
     * nach einem Fatal Error (Zeit- oder Speicherlimit) und nach exit, wo
     * ein finally übersprungen wird.
     */
    private static function tempFile(string $prefix): string {
        $path = @tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw new \RuntimeException('Konnte keine temporäre Backup-Datei anlegen.');
        }
        self::$offeneTempDateien[$path] = true;
        if (!self::$aufraeumenRegistriert) {
            self::$aufraeumenRegistriert = true;
            register_shutdown_function(static function (): void {
                self::offeneTempDateienLoeschen();
            });
        }
        return $path;
    }

    private static function tempDateiEntfernen(string $path): void {
        @unlink($path);
        unset(self::$offeneTempDateien[$path]);
    }

    private static function offeneTempDateienLoeschen(): void {
        foreach (array_keys(self::$offeneTempDateien) as $path) {
            self::tempDateiEntfernen($path);
        }
    }

    /**
     * Entfernt Zwischendateien, die kein Shutdown mehr aufräumen konnte
     * (SIGKILL, request_terminate_timeout von PHP-FPM, Stromausfall). Nur
     * eigene Präfixe und nur älter als VERWAISTE_TEMP_DATEIEN_NACH_SEKUNDEN -
     * weit über jeder realistischen Laufzeit, damit ein paralleler Lauf
     * (etwa eine zweite Instanz mit gemeinsamem /tmp) keine Datei verliert.
     * Fehler werden bewusst ignoriert, das Aufräumen ist nicht kritisch.
     */
    private static function verwaisteTempDateienEntfernen(): void {
        $grenze = time() - self::VERWAISTE_TEMP_DATEIEN_NACH_SEKUNDEN;
        foreach (glob(sys_get_temp_dir() . '/hv-backup-*') ?: [] as $path) {
            $name = basename($path);
            if (!str_starts_with($name, 'hv-backup-sql-')
                && !str_starts_with($name, 'hv-backup-uploads-')
                && !str_starts_with($name, 'hv-backup-addondaten-')) {
                continue;
            }
            $mtime = @filemtime($path);
            if (is_file($path) && $mtime !== false && $mtime < $grenze) {
                @unlink($path);
            }
        }
    }

    /**
     * @param array<string, string> $settings
     */
    private static function buildClient(array $settings): BackupTarget {
        return match (self::targetType($settings)) {
            self::TARGET_FTPS => new FtpsClient(
                trim($settings['backup_ftps_host'] ?? ''),
                max(1, (int)($settings['backup_ftps_port'] ?? 21)),
                trim($settings['backup_ftps_user'] ?? ''),
                Crypto::decrypt($settings['backup_ftps_pass'] ?? '') ?? '',
                trim($settings['backup_ftps_path'] ?? '')
            ),
            self::TARGET_WEBDAV => new WebDavClient(
                rtrim(trim($settings['backup_webdav_url'] ?? ''), '/'),
                trim($settings['backup_webdav_user'] ?? ''),
                Crypto::decrypt($settings['backup_webdav_pass'] ?? '') ?? ''
            ),
            default => new S3Client(
                trim($settings['backup_s3_endpoint'] ?? ''),
                trim($settings['backup_s3_region'] ?? '') ?: 'us-east-1',
                trim($settings['backup_s3_bucket'] ?? ''),
                trim($settings['backup_s3_access_key'] ?? ''),
                Crypto::decrypt($settings['backup_s3_secret_key'] ?? '') ?? '',
                ($settings['backup_s3_path_style'] ?? '') === '1',
                // Standard: HTTPS (AWS S3 erzwingt es ohnehin). Abschaltbar für
                // selbstgehostetes MinIO/Object Storage ohne TLS in einem
                // vertrauenswürdigen internen Netz - Standardwert bei fehlendem
                // Setting bewusst "an" (sicherer Default).
                ($settings['backup_s3_use_https'] ?? '1') !== '0'
            ),
        };
    }

    /**
     * recordStatus() ohne Ausnahme: Ein Fehler beim Protokollieren (etwa
     * Datenbank weg) darf nie die eigentliche Ursache verdecken.
     */
    private static function recordStatusSicher(string $status, ?string $error): void {
        try {
            self::recordStatus($status, $error);
        } catch (\Throwable $e) {
            error_log("BackupService: Status '{$status}' nicht gespeichert: " . $e->getMessage() . ($error !== null ? " (Ursache: {$error})" : ''));
        }
    }

    private static function recordStatus(string $status, ?string $error): void {
        $db = Database::getInstance();
        $values = [
            'backup_last_status' => $status,
            'backup_last_run_at' => (string)time(),
            'backup_last_error' => $error ?? '',
        ];
        foreach ($values as $key => $value) {
            $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$key, $value, $value]);
        }

        AuditLogger::log(
            match ($status) {
                'ok' => 'Externes Backup erfolgreich',
                'partial' => 'Externes Backup teilweise fehlgeschlagen',
                default => 'Externes Backup fehlgeschlagen',
            },
            'settings',
            $error
        );
    }

    /**
     * @return array<string, string>
     */
    private static function loadSettings(): array {
        try {
            $db = Database::getInstance();
            $stmt = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'backup_%'");
            $settings = [];
            foreach ($stmt->fetchAll() as $row) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            return $settings;
        } catch (\Throwable $e) {
            // Fail-safe analog zu BaseController::loadSettings(): registerScheduledTask()
            // wird bei jedem Request-Bootstrap aufgerufen, auch bevor die Datenbank
            // eingerichtet ist (Setup-Assistent) - dann gilt Backup schlicht als nicht
            // konfiguriert, statt den gesamten Request mit einer Exception abzubrechen.
            return [];
        }
    }
}
