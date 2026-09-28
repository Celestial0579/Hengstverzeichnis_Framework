<?php
// src/Helper/ContainerAblage.php

namespace App\Helper;

/**
 * Liegt ein Datenverzeichnis im Container dauerhaft? (Audit M31)
 *
 * Im offiziellen Docker-Image liegt alles, was nicht in einem Volume oder
 * Bind-Mount steckt, im Dateisystem des Containers. Das verschwindet beim
 * nächsten Neuerstellen, also bei jedem `docker compose pull && up -d` und
 * bei jedem Watchtower-Update. Wer das Image mit einer eigenen
 * Compose-Konfiguration ohne `horses_data` betrieb, verlor so nach dem
 * Update die Pferdefotos: Die Migration holte sie aus dem Volume
 * `uploads_data` nach `storage/horses`, und dort lagen sie nur noch im
 * Container.
 *
 * Diese Klasse beantwortet deshalb EINE Frage: Liegt ein Pfad in einem
 * eigenen, benannten Volume oder Bind-Mount? Gelesen wird
 * `/proc/self/mountinfo`, der längste Mountpunkt, der den Pfad enthält,
 * entscheidet.
 *
 * ANONYME VOLUMES gelten bewusst NICHT als dauerhaft. Das Image deklariert
 * `VOLUME /var/www/html/storage/horses` als Rückfall, aber Watchtower und
 * `docker compose down && up` hängen jedes Mal ein NEUES anonymes Volume an.
 * Das alte bleibt verwaist liegen, und `docker volume prune` löscht es.
 * Erkannt werden sie an der Quelle `.../volumes/<64 Hex-Zeichen>/_data`, die
 * Docker und Podman für unbenannte Volumes vergeben. Das ist eine
 * Pfad-Heuristik; ein falsches "nicht dauerhaft" ist aber sicher, weil dann
 * nur NICHT verschoben wird.
 *
 * AKTIV nur mit `HV_CONTAINER=1`, das ausschließlich das Dockerfile setzt.
 * Ohne die Variable (Shared Hosting, VPS, Entwicklung, Testserver) gilt jeder
 * Pfad als dauerhaft, und nichts ändert sich. Bewusst nicht aus
 * `UPDATE_IN_PLACE=0` abgeleitet: Das setzen auch VPS-Betreiber ohne
 * Container.
 *
 * Absichtlich ohne jede Abhängigkeit (kein Autoloader, keine Datenbank):
 * tests/docker/image-smoke.sh lädt die Datei per require im fertigen Image.
 */
final class ContainerAblage {

    /** Eigenes benanntes Volume oder Bind-Mount: überlebt ein Neuerstellen. */
    public const EIGENER_MOUNT = 'eigener_mount';

    /** Unbenanntes Volume (VOLUME im Image ohne Compose-Eintrag): verwaist beim Neuerstellen. */
    public const ANONYMES_VOLUME = 'anonymes_volume';

    /** Kein eigener Mount: Der Pfad liegt im Container-Dateisystem. */
    public const KEIN_MOUNT = 'kein_mount';

    private static ?bool $aktivOverride = null;
    private static ?string $mountinfoOverride = null;

    /** Läuft die Anwendung im offiziellen Container (HV_CONTAINER=1)? */
    public static function aktiv(): bool {
        return self::$aktivOverride ?? (getenv('HV_CONTAINER') === '1');
    }

    /**
     * Wie ist $pfad eingebunden? Ein nicht existierender Pfad liefert
     * KEIN_MOUNT: Ohne realpath() lässt sich nichts zuordnen, und im echten
     * Container legt das Image storage/horses immer an.
     */
    public static function art(string $pfad): string {
        $real = realpath($pfad);
        if ($real === false) {
            return self::KEIN_MOUNT;
        }

        $mountinfo = self::$mountinfoOverride ?? @file_get_contents('/proc/self/mountinfo');
        if (is_string($mountinfo) && trim($mountinfo) !== '') {
            return self::artAus($real, $mountinfo);
        }

        // Rückfall ohne lesbare mountinfo: anderes Gerät als / heißt
        // eigener Mount. Anonyme Volumes erkennt dieser Weg NICHT.
        $pfadStat = @stat($real);
        $wurzelStat = @stat('/');
        if ($pfadStat === false || $wurzelStat === false) {
            return self::KEIN_MOUNT;
        }

        return $pfadStat['dev'] !== $wurzelStat['dev'] ? self::EIGENER_MOUNT : self::KEIN_MOUNT;
    }

    /**
     * Reine Auswertung einer mountinfo (siehe proc(5)) für einen bereits
     * aufgelösten Pfad. Öffentlich für die Tests.
     *
     * Feld 4 ist die Wurzel innerhalb der Quelle, Feld 5 der Mountpunkt.
     * Beide kodieren Leerzeichen, Tab, Zeilenumbruch und Backslash oktal
     * (`\040` usw.). Der längste Mountpunkt, der den Pfad enthält, gewinnt;
     * bei zwei Mounts auf demselben Punkt der spätere, denn er verdeckt den
     * früheren.
     */
    public static function artAus(string $realpfad, string $mountinfo): string {
        $pfad = $realpfad === '/' ? '/' : rtrim($realpfad, '/');
        $bester = null;
        $besteWurzel = '';

        foreach (preg_split('/\R/', $mountinfo) ?: [] as $zeile) {
            $felder = explode(' ', trim($zeile));
            if (count($felder) < 5) {
                continue;
            }
            $wurzel = self::dekodiere($felder[3]);
            $punkt = self::dekodiere($felder[4]);
            if ($punkt === '' || $punkt[0] !== '/') {
                continue;
            }
            $punkt = $punkt === '/' ? '/' : rtrim($punkt, '/');

            $enthaelt = $punkt === '/' || $pfad === $punkt || str_starts_with($pfad, $punkt . '/');
            if (!$enthaelt) {
                continue;
            }
            if ($bester === null || strlen($punkt) >= strlen($bester)) {
                $bester = $punkt;
                $besteWurzel = $wurzel;
            }
        }

        if ($bester === null || $bester === '/') {
            return self::KEIN_MOUNT;
        }
        if (preg_match('~/volumes/[0-9a-f]{64}/_data$~', $besteWurzel) === 1) {
            return self::ANONYMES_VOLUME;
        }

        return self::EIGENER_MOUNT;
    }

    /**
     * Darf dort Dauerhaftes abgelegt werden? Außerhalb des Containers immer
     * ja, im Container nur auf einem eigenen benannten Volume oder
     * Bind-Mount.
     */
    public static function istPersistent(string $pfad): bool {
        return !self::aktiv() || self::art($pfad) === self::EIGENER_MOUNT;
    }

    /**
     * Grund für einen Hinweis im Admin-Dashboard: null, wenn alles in
     * Ordnung ist, sonst KEIN_MOUNT oder ANONYMES_VOLUME.
     */
    public static function warnung(string $pfad): ?string {
        if (!self::aktiv()) {
            return null;
        }
        $art = self::art($pfad);

        return $art === self::EIGENER_MOUNT ? null : $art;
    }

    /**
     * Nur für Tests. `null` stellt jeweils den Normalzustand wieder her
     * (HV_CONTAINER bzw. /proc/self/mountinfo).
     */
    public static function overrideForTests(?bool $aktiv, ?string $mountinfo = null): void {
        self::$aktivOverride = $aktiv;
        self::$mountinfoOverride = $mountinfo;
    }

    private static function dekodiere(string $feld): string {
        return (string)preg_replace_callback(
            '/\\\\([0-7]{3})/',
            static fn(array $m): string => chr((int)octdec($m[1])),
            $feld
        );
    }
}
