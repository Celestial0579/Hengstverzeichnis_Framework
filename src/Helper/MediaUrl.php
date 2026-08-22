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
 */
final class MediaUrl {

    /**
     * @param array<string, mixed> $horse Datensatz mit id und image_url
     * @return string|null null, wenn kein Foto hinterlegt ist - der Aufrufer
     *   zeigt dann seinen Platzhalter, wie bisher auch.
     */
    public static function horseImage(array $horse, ?string $groesse = null): ?string {
        if (empty($horse['image_url']) || empty($horse['id'])) {
            return null;
        }

        return '/media/horse-image?id=' . (int)$horse['id'] . self::groessenTeil($groesse);
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
     */
    public static function horseMediaImage(int $mediaId, ?string $groesse = null): ?string {
        return $mediaId > 0
            ? '/media/horse-media?id=' . $mediaId . self::groessenTeil($groesse)
            : null;
    }
}
