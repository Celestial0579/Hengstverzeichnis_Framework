<?php
// src/Helper/HorseImagePath.php

namespace App\Helper;

/**
 * Wo die Pferdefotos liegen (#366).
 *
 * Sie lagen bis v0.8.0 unter `public/uploads/horses/` - also IM Webroot. Damit
 * lieferte der Webserver jede Datei direkt aus, komplett am Anwendungscode
 * vorbei: `public/.htaccess` leitet nur bei `!-f` auf den Front Controller um,
 * eine existierende Bilddatei erreicht ihn nie. Die Sichtbarkeitsprüfung in
 * App\Controllers\MediaController war für genau den Fall wirkungslos, für den
 * sie geschrieben wurde - das Foto eines depublizierten Pferdes blieb unter
 * seinem unveränderten Dateinamen abrufbar.
 *
 * Deshalb liegt die Ablage jetzt unter `storage/horses/`, außerhalb des
 * Webroots - dasselbe, was das Addon `galerie` von Anfang an tut
 * (storage/plugin_galerie). Es gibt keinen statischen Weg mehr, der zu
 * schützen wäre; die Auslieferung läuft ausschließlich über
 * `/media/horse-image`.
 *
 * Der Spaltenwert `horses.image_url` bleibt bewusst `/uploads/horses/<datei>`:
 * Aufgelöst wird ohnehin nur der basename() (siehe MediaController), und ein
 * Umschreiben aller Bestandszeilen wäre eine Migration ohne Not. Siehe auch
 * MediaUrl.
 *
 * Diese Klasse ist die EINZIGE Stelle, die beide Verzeichnisse kennt. Upload,
 * Auslieferung, Sicherung und Migration fragen hier - sonst driften sie
 * auseinander, und eine Sicherung, die das halbe Verzeichnis vergisst, fällt
 * erst beim Zurückspielen auf.
 */
final class HorseImagePath {

    private static ?string $dirOverride = null;
    private static ?string $legacyDirOverride = null;
    /** @var array<int, string>|null */
    private static ?array $galerieDirsOverride = null;

    /** Ablageort außerhalb des Webroots. Hierhin wird geschrieben. */
    public static function dir(): string {
        return self::$dirOverride ?? dirname(__DIR__, 2) . '/storage/horses';
    }

    /**
     * Der alte Ort im Webroot. Wird nur noch GELESEN, als Rückfall für
     * Instanzen, deren Verschiebung (SchemaMigrator, Schritt
     * `366_pferdefotos_aus_dem_webroot`) noch nicht oder nur teilweise
     * gelaufen ist. Ohne diesen Rückfall zeigte eine Instanz zwischen Update
     * und erstem Seitenaufruf keine Fotos mehr.
     */
    public static function legacyDir(): string {
        return self::$legacyDirOverride ?? dirname(__DIR__, 2) . '/public/uploads/horses';
    }

    /**
     * Die Ablagen des abgelösten Addons `galerie` (#339).
     *
     * Werden nur noch GELESEN - von der Übernahme im SchemaMigrator, die die
     * Dateien nach dir() holt. Zwei Orte, weil das Addon selbst einmal
     * umgezogen ist: ältere Stände liegen noch im Webroot.
     *
     * @return array<int, string>
     */
    public static function galerieLegacyDirs(): array {
        if (self::$galerieDirsOverride !== null) {
            return self::$galerieDirsOverride;
        }

        return [
            dirname(__DIR__, 2) . '/storage/plugin_galerie',
            dirname(__DIR__, 2) . '/public/uploads/plugin_galerie',
        ];
    }

    /** @param array<int, string>|null $dirs */
    public static function overrideGalerieLegacyDirsForTests(?array $dirs): void {
        self::$galerieDirsOverride = $dirs;
    }

    /**
     * Nur für Tests (analog BackupService::overrideUploadsDirForTests()).
     * `null` stellt den Normalzustand wieder her.
     */
    public static function overrideForTests(?string $dir, ?string $legacyDir = null): void {
        self::$dirOverride = $dir;
        self::$legacyDirOverride = $legacyDir;
    }
}
