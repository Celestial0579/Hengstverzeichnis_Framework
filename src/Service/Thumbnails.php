<?php
// src/Service/Thumbnails.php

namespace App\Service;

use App\Helper\HorseImagePath;

/**
 * Verkleinerte Fassungen von Pferdefotos (#397).
 *
 * WARUM ES DAS BRAUCHT. Die Galerie liefert überall das ORIGINAL aus und
 * verkleinert es per CSS: in der Medientabelle auf 64×64, in der
 * Verwaltungsliste auf 45×45, auf der Kachel auf 120 px Höhe. Ein Foto aus
 * einer Handykamera hat gut und gern 3–5 MB. Eine Seite mit fünfzig Zeilen
 * überträgt fünfzig davon, um sie auf Briefmarkengröße zu skalieren.
 *
 * `session_write_close()` und ETag/304 sind längst da — es bleibt genau der
 * ERSTE Abruf und jeder mit geleertem Zwischenspeicher.
 *
 * WARUM ES ABSCHALTBAR IST, UND ZWAR VOM BETREIBER. Verkleinern heißt Bilder
 * dekodieren, und dafür braucht PHP die Erweiterung GD. Die ist im offiziellen
 * Image NICHT enthalten (`Dockerfile` installiert `pdo_mysql` und `ftp`), und
 * `docs/security.md` macht daraus eine ausdrückliche Zusage. Ein Bilddecoder
 * liest fremde Binärdaten — historisch eine der ergiebigsten Fehlerquellen
 * überhaupt.
 *
 * Deshalb zwei Bedingungen, die BEIDE zutreffen müssen:
 *
 *   1. GD ist vorhanden (kann der Betreiber nicht per Einstellung herbeiführen)
 *   2. der Betreiber hat es eingeschaltet (Vorgabe: aus)
 *
 * Trifft eines nicht zu, verhält sich die Anwendung exakt wie vorher:
 * Originale, keine Fehlermeldung, keine halbe Galerie. Das ist der Grund für
 * die Vorgabe `aus` — ein Update darf das Verhalten eines Bestands nicht
 * ändern, und wer GD zufällig installiert hat, soll nicht überrascht werden.
 */
final class Thumbnails {

    /**
     * Längste Kante je Größe, in Pixeln.
     *
     * `thumb` bedient die Listen (64×64, 45×45) und die Galeriekachel
     * (120 px, unter 480 px dann 96 px) mit Reserve für Bildschirme mit
     * doppelter Punktdichte. `card` bedient die Katalogkarte (180 px) und
     * das Hero-Bild (bis 340 px breit) — ebenfalls mit Reserve.
     */
    public const GROESSEN = ['thumb' => 320, 'card' => 800];

    /**
     * Obergrenze gegen Dekompressionsbomben. Ein Bild wird als
     * Breite × Höhe × 4 Byte im Speicher aufgebaut; bei 50 Megapixeln wären
     * das 200 MB, und der Container fährt 128 MB. Größere Dateien werden
     * unverkleinert ausgeliefert statt den Prozess umzubringen.
     */
    public const MAX_PIXEL = 50_000_000;

    /** Anteil des Speicherlimits, den ein einzelner Aufbau kosten darf. */
    private const SPEICHER_ANTEIL = 0.6;

    private static ?bool $verfuegbarOverride = null;

    private function __construct() {}

    /**
     * Kann diese Installation überhaupt verkleinern?
     *
     * Geprüft werden die zwei Funktionen, die wirklich gebraucht werden -
     * nicht `extension_loaded('gd')`. Eine Erweiterung kann geladen und
     * trotzdem ohne JPEG-Unterstützung gebaut sein; dann scheiterte erst der
     * Aufruf, und zwar mitten in einer Anfrage.
     */
    public static function gdVorhanden(): bool {
        if (self::$verfuegbarOverride !== null) {
            return self::$verfuegbarOverride;
        }

        return function_exists('imagecreatefromstring') && function_exists('imagejpeg');
    }

    /** Nur für Tests, analog HorseImagePath::overrideForTests(). */
    public static function overrideVerfuegbarForTests(?bool $wert): void {
        self::$verfuegbarOverride = $wert;
    }

    /**
     * Ist die Erzeugung eingeschaltet UND möglich?
     *
     * @param array<string, mixed>|null $settings Bereits geladene Einstellungen;
     *   ohne Angabe wird die Einstellung selbst geholt. Der Parameter ist
     *   nicht Bequemlichkeit: Die Auslieferungsroute hat sie ohnehin schon,
     *   und eine zweite Abfrage je Bild wäre genau die Sorte Kosten, die
     *   dieses Issue senken will.
     */
    public static function aktiv(?array $settings = null): bool {
        if (!self::gdVorhanden()) {
            return false;
        }

        if ($settings !== null) {
            return (string)($settings['horse_thumbnails'] ?? '0') === '1';
        }

        try {
            $stmt = \App\Database::getInstance()->prepare(
                'SELECT setting_value FROM settings WHERE setting_key = ?'
            );
            $stmt->execute(['horse_thumbnails']);
            return (string)$stmt->fetchColumn() === '1';
        } catch (\Throwable) {
            // Vor dem Setup gibt es die Tabelle nicht. Ohne Aussage: aus.
            return false;
        }
    }

    /**
     * Dateiname der verkleinerten Fassung zu einem Original.
     *
     * Immer `.jpg`, unabhängig vom Original: Eine Vorschau ist ein
     * Wegwerfbild, und JPEG ist das einzige Format, das GD in jeder
     * Bauvariante schreiben kann. Die Endung im Namen ist auch der Grund,
     * warum die Auslieferung den Typ nicht erraten muss.
     */
    public static function dateiname(string $original, string $groesse): ?string {
        if (!isset(self::GROESSEN[$groesse])) {
            return null;
        }

        $name = basename(parse_url($original, PHP_URL_PATH) ?? '');
        if ($name === '' || $name === '.' || $name === '..') {
            return null;
        }

        return pathinfo($name, PATHINFO_FILENAME) . '_' . $groesse . '.jpg';
    }

    /** Pfad einer VORHANDENEN verkleinerten Fassung, sonst null. */
    public static function pfad(string $original, string $groesse): ?string {
        $name = self::dateiname($original, $groesse);
        if ($name === null) {
            return null;
        }

        $voll = HorseImagePath::dir() . '/' . $name;
        return is_file($voll) ? $voll : null;
    }

    /**
     * Erzeugt die verkleinerte Fassung, wenn nötig. Liefert ihren Pfad oder
     * null - und null heißt immer nur „nimm das Original", nie „Fehler".
     *
     * Geschrieben wird AUSSCHLIESSLICH nach HorseImagePath::dir(), auch wenn
     * das Original noch im alten Ort im Webroot liegt (#366). Eine neue Datei
     * dort abzulegen machte die Verschiebung an einer neuen Stelle rückgängig.
     */
    public static function erzeugen(string $originalPfad, string $groesse, string $originalName): ?string {
        if (!self::gdVorhanden() || !isset(self::GROESSEN[$groesse])) {
            return null;
        }

        $ziel = self::dateiname($originalName, $groesse);
        if ($ziel === null) {
            return null;
        }

        $verzeichnis = HorseImagePath::dir();
        if (!is_dir($verzeichnis)) {
            return null;
        }
        $zielPfad = $verzeichnis . '/' . $ziel;

        // Schon da und nicht älter als das Original.
        if (is_file($zielPfad) && filemtime($zielPfad) >= (filemtime($originalPfad) ?: 0)) {
            return $zielPfad;
        }

        $masse = @getimagesize($originalPfad);
        if (!is_array($masse) || empty($masse[0]) || empty($masse[1])) {
            return null;                       // kein Bild - nicht dekodieren
        }
        [$breite, $hoehe] = [(int)$masse[0], (int)$masse[1]];

        $kante = self::GROESSEN[$groesse];
        if (max($breite, $hoehe) <= $kante) {
            // Ein hochskalierter Thumb wäre größer als das Original und
            // schlechter. Das Original ist hier die richtige Antwort.
            return null;
        }

        if (!self::speicherReicht($breite, $hoehe)) {
            return null;
        }

        $rohdaten = @file_get_contents($originalPfad);
        if ($rohdaten === false) {
            return null;
        }

        $quelle = @imagecreatefromstring($rohdaten);
        unset($rohdaten);
        if ($quelle === false) {
            return null;
        }

        // Kein imagedestroy(): seit PHP 8.0 wirkungslos (GdImage ist ein
        // Objekt und wird eingesammelt), seit 8.5 deprecated - und
        // phpunit.xml stellt Deprecations hart. unset() reicht und sagt
        // dasselbe.
        $faktor = $kante / max($breite, $hoehe);
        $neu = @imagescale($quelle, max(1, (int)round($breite * $faktor)),
                           max(1, (int)round($hoehe * $faktor)));
        unset($quelle);
        if ($neu === false) {
            return null;
        }

        // Auf eine weisse Flaeche legen: PNG und WebP koennen Transparenz,
        // JPEG nicht - ohne diesen Schritt wuerden durchsichtige Stellen
        // schwarz.
        $flach = @imagecreatetruecolor(imagesx($neu), imagesy($neu));
        if ($flach !== false) {
            $weiss = imagecolorallocate($flach, 255, 255, 255);
            if ($weiss !== false) {
                imagefilledrectangle($flach, 0, 0, imagesx($neu), imagesy($neu), $weiss);
            }
            imagecopy($flach, $neu, 0, 0, 0, 0, imagesx($neu), imagesy($neu));
        }

        $erfolg = @imagejpeg($flach !== false ? $flach : $neu, $zielPfad, 82);
        unset($flach, $neu);

        if (empty($erfolg) || !is_file($zielPfad)) {
            @unlink($zielPfad);
            return null;
        }

        @chmod($zielPfad, 0644);
        return $zielPfad;
    }

    /**
     * Entfernt alle verkleinerten Fassungen eines Originals.
     *
     * Gehört zu jedem Löschen dazu: Sonst bliebe je gelöschtem Medium eine
     * Waise liegen, und die fiele erst auf, wenn jemand die Ablage zählt.
     */
    public static function entfernen(string $original): void {
        foreach (array_keys(self::GROESSEN) as $groesse) {
            $name = self::dateiname($original, $groesse);
            if ($name === null) {
                continue;
            }
            foreach ([HorseImagePath::dir(), HorseImagePath::legacyDir()] as $verzeichnis) {
                $voll = $verzeichnis . '/' . $name;
                if (is_file($voll)) {
                    @unlink($voll);
                }
            }
        }
    }

    /**
     * Reicht das Speicherlimit für den Aufbau?
     *
     * GD baut ein Bild als Breite × Höhe × 4 Byte auf. Ein 24-Megapixel-Foto
     * braucht damit rund 96 MB, und das Referenz-Image fährt 128 MB. Ohne
     * diese Schranke stirbt der Prozess mitten in einer Anfrage - und zwar
     * beim Ausliefern eines Bildes, also an einer Stelle, an der ein
     * Fehlschlag nur ein fehlendes Vorschaubild sein dürfte.
     */
    private static function speicherReicht(int $breite, int $hoehe): bool {
        $pixel = $breite * $hoehe;
        if ($pixel <= 0 || $pixel > self::MAX_PIXEL) {
            return false;
        }

        $limit = self::limitInBytes();
        if ($limit === null) {
            return true;                       // kein Limit gesetzt
        }

        // Quelle und Ziel liegen kurzzeitig gleichzeitig im Speicher; der
        // Aufschlag deckt das grob ab.
        $bedarf = (int)($pixel * 4 * 1.3);
        return $bedarf < (int)($limit * self::SPEICHER_ANTEIL);
    }

    private static function limitInBytes(): ?int {
        $roh = trim((string)ini_get('memory_limit'));
        if ($roh === '' || $roh === '-1') {
            return null;
        }

        $einheit = strtolower(substr($roh, -1));
        $zahl = (int)$roh;
        return match ($einheit) {
            'g' => $zahl * 1024 * 1024 * 1024,
            'm' => $zahl * 1024 * 1024,
            'k' => $zahl * 1024,
            default => $zahl,
        };
    }
}
