<?php
// src/Helper/MediaUrl.php

namespace App\Helper;

/**
 * Bildet den gespeicherten `horses.image_url`-Wert auf die geschützte
 * Ausliefer-Route ab (#262).
 *
 * Die Spalte bleibt bewusst unverändert bei `/uploads/horses/<datei>`: Sie ist
 * der Speicherort, nicht die Adresse. Ein Umschreiben aller Bestandsdatensätze
 * wäre eine Migration ohne Not - und der Rückweg zur statischen Auslieferung
 * wäre danach versperrt.
 *
 * Für Addons: Wer Pferdefotos rendert, sollte diesen Helfer benutzen. Wird der
 * rohe Spaltenwert ausgegeben, zeigt das Bild weiterhin - dann aber ohne den
 * Einbettungsschutz, weil es am Anwendungscode vorbei als statische Datei
 * ausgeliefert wird.
 *
 * VERSION IN DER ADRESSE (Audit M14). Jede Adresse trägt `&v=<12 hex>`, einen
 * Hash des gespeicherten Dateiwerts. Die Route cacht nur unter passender
 * Version ein Jahr im Browser - vorher hing die Adresse allein an der
 * Pferde-ID, und nach einem Wechsel des Hauptbilds zeigten Browser und
 * Proxies bis zu einem Jahr das alte Foto. Der Dateiwert trägt time() und
 * einen Zufallsanteil; auch eine nach TRUNCATE wiederverwendete ID ergibt
 * deshalb eine neue Adresse. Wer Adressen weiterverarbeitet (API, Addons),
 * behandelt sie als undurchsichtig.
 */
final class MediaUrl {

    /** Version eines gespeicherten Dateiwerts: die ersten 12 Hex-Zeichen von SHA-256. */
    public static function version(string $gespeicherterWert): string {
        return substr(hash('sha256', $gespeicherterWert), 0, 12);
    }

    /** Passt die angefragte Version (`$_GET['v']`) zum gespeicherten Wert? */
    public static function versionPasst(string $gespeicherterWert, mixed $angefragt): bool {
        if (!is_string($angefragt) || $angefragt === '' || $gespeicherterWert === '') {
            return false;
        }
        return hash_equals(self::version($gespeicherterWert), $angefragt);
    }

    /**
     * @param array<string, mixed> $horse Datensatz mit id und image_url
     * @return string|null null, wenn kein Foto hinterlegt ist - der Aufrufer
     *   zeigt dann seinen Platzhalter, wie bisher auch.
     */
    public static function horseImage(array $horse, ?string $groesse = null): ?string {
        if (empty($horse['image_url']) || empty($horse['id'])) {
            return null;
        }

        return '/media/horse-image?id=' . (int)$horse['id'] . self::groessenTeil($groesse)
            . '&v=' . self::version((string)$horse['image_url']);
    }

    /**
     * Der optionale Groessen-Parameter (#397).
     *
     * Er wird NICHT geprueft, ob die Groesse gerade erzeugt werden kann - das
     * entscheidet die Route bei jedem Abruf neu. Eine Adresse, die vom
     * Zustand einer Einstellung abhinge, waere in jedem Zwischenspeicher
     * falsch, sobald der Betreiber sie umlegt. Kennt die Route die Groesse
     * nicht oder ist die Erzeugung aus, liefert sie das Original unter
     * derselben Adresse.
     */
    private static function groessenTeil(?string $groesse): string {
        if ($groesse === null || !isset(\App\Service\Thumbnails::GROESSEN[$groesse])) {
            return '';
        }

        return '&groesse=' . rawurlencode($groesse);
    }

    /**
     * Adresse eines weiteren Mediums (#339).
     *
     * Die Medien-ID genuegt - der Dateiname erscheint in keiner Antwort. Wer
     * Pferdemedien rendert, nimmt diesen Helfer: Der rohe Spaltenwert
     * zeigte auf ein Verzeichnis ausserhalb des Webroots und liefe damit ins
     * Leere, und der Einbettungsschutz der Route entfiele.
     *
     * Mit $dateiname (horse_media.file_name) trägt die Adresse die Version
     * (Audit M14) und darf ein Jahr im Browser bleiben; ohne gilt sie fünf
     * Minuten.
     */
    public static function horseMediaImage(int $mediaId, ?string $groesse = null, ?string $dateiname = null): ?string {
        if ($mediaId <= 0) {
            return null;
        }

        return '/media/horse-media?id=' . $mediaId . self::groessenTeil($groesse)
            . ($dateiname !== null && $dateiname !== '' ? '&v=' . self::version($dateiname) : '');
    }
}
