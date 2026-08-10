<?php
// src/Helper/ColorContrast.php

namespace App\Helper;

/**
 * WCAG-Kontrastrechnung für die admin-konfigurierbaren Markenfarben (#196).
 *
 * Die Primär-/Sekundärfarbe ist zur Laufzeit frei wählbar (Admin ->
 * Einstellungen, Sanitizer /^#[0-9a-fA-F]{3,8}$/ in AdminController).
 * Überall, wo Text direkt auf einer solchen Fläche liegt (Footer, .btn),
 * kann deshalb keine fest gewählte Textfarbe Kontrast garantieren - sie
 * muss aus der tatsächlich konfigurierten Fläche berechnet werden.
 *
 * readableTextOn() liefert Weiß oder Schwarz, je nachdem, was auf der
 * gegebenen Fläche besser lesbar ist. Diese Zwei-Wege-Wahl ist bewusst:
 * Für JEDE Hintergrundfarbe erreicht die bessere der beiden Optionen
 * mindestens 4,58:1 (Schnittpunkt bei relativer Luminanz 0,1791) - ein
 * fester dunkler Ersatzton statt Schwarz könnte das Minimum von 4,5:1
 * an der Umschaltgrenze wieder reißen.
 */
final class ColorContrast {

    /**
     * Zerlegt einen Hex-Farbwert in [R, G, B] (0-255). Akzeptiert die vom
     * Sanitizer erlaubten Formen #rgb, #rgba, #rrggbb und #rrggbbaa; ein
     * Alpha-Kanal wird für die Kontrastrechnung ignoriert (die Flächen, um
     * die es hier geht, sind deckend).
     *
     * @return array{0: int, 1: int, 2: int}|null null bei ungültigem Wert
     */
    public static function parseHex(string $hex): ?array {
        if (!preg_match('/^#([0-9a-fA-F]{3,8})$/', trim($hex), $m)) {
            return null;
        }
        $digits = $m[1];
        $len = strlen($digits);

        if ($len === 3 || $len === 4) {
            return [
                (int)hexdec(str_repeat($digits[0], 2)),
                (int)hexdec(str_repeat($digits[1], 2)),
                (int)hexdec(str_repeat($digits[2], 2)),
            ];
        }
        if ($len === 6 || $len === 8) {
            return [
                (int)hexdec(substr($digits, 0, 2)),
                (int)hexdec(substr($digits, 2, 2)),
                (int)hexdec(substr($digits, 4, 2)),
            ];
        }
        // 5 oder 7 Stellen: vom Sanitizer erlaubt, aber kein gültiges Farbformat.
        return null;
    }

    /**
     * Relative Luminanz nach WCAG 2.1 (0 = Schwarz, 1 = Weiß).
     *
     * @param array{0: int, 1: int, 2: int} $rgb
     */
    public static function relativeLuminance(array $rgb): float {
        $channels = [];
        foreach ($rgb as $value) {
            $c = $value / 255;
            $channels[] = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }
        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /**
     * WCAG-Kontrastverhältnis zweier Hex-Farben (1:1 bis 21:1).
     * null, wenn eine der beiden Farben nicht parsebar ist.
     */
    public static function contrastRatio(string $colorA, string $colorB): ?float {
        $a = self::parseHex($colorA);
        $b = self::parseHex($colorB);
        if ($a === null || $b === null) {
            return null;
        }
        $la = self::relativeLuminance($a);
        $lb = self::relativeLuminance($b);
        $lighter = max($la, $lb);
        $darker = min($la, $lb);
        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * Lesbare Textfarbe für die gegebene Fläche: Weiß, solange Weiß das
     * AA-Minimum von 4,5:1 erreicht, sonst Schwarz (erreicht dann immer
     * >= 4,5:1, siehe Klassenkommentar). Ungültige Eingaben fallen auf
     * Weiß zurück - passend zum bisherigen Verhalten (color: white) und
     * zur dunklen Default-Primärfarbe.
     */
    public static function readableTextOn(string $background): string {
        $ratio = self::contrastRatio('#ffffff', $background);
        if ($ratio === null || $ratio >= 4.5) {
            return '#ffffff';
        }
        return '#000000';
    }
}
