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
    public static function horseImage(array $horse): ?string {
        if (empty($horse['image_url']) || empty($horse['id'])) {
            return null;
        }

        return '/media/horse-image?id=' . (int)$horse['id'];
    }
}
