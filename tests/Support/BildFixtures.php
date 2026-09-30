<?php
// tests/Support/BildFixtures.php

namespace Tests\Support;

/**
 * Kleine, byteweise gebaute Bilddateien mit Metadaten (Audit M21) - ohne GD
 * und ohne Fixture-Dateien im Repo. Jedes Metadatum trägt dasselbe
 * Geheimnis, damit ein einziges assertStringNotContainsString() jeden
 * durchgerutschten Block findet.
 */
final class BildFixtures {

    public const GEHEIM = 'GEHEIMER-HOF';

    private static function seg(int $marker, string $daten): string {
        return "\xFF" . chr($marker) . pack('n', strlen($daten) + 2) . $daten;
    }

    /** TIFF mit Ausrichtung und dem Geheimnis als Kamerahersteller. */
    public static function tiff(int $orientierung): string {
        return 'II' . pack('vV', 42, 8) . pack('v', 2)
            . pack('vvVV', 0x010F, 2, 13, 8 + 2 + 2 * 12 + 4)
            . pack('vvVvv', 0x0112, 3, 1, $orientierung, 0)
            . pack('V', 0)
            . self::GEHEIM . "\0";
    }

    /**
     * Ein JPEG, das getimagesize() lesen kann (GD dekodiert es nicht), mit
     * EXIF (Ausrichtung, Geheimnis), XMP, Kommentar und Anhang hinter EOI.
     */
    public static function jpeg(int $orientierung = 6, int $breite = 20, int $hoehe = 10): string {
        return "\xFF\xD8"
            . self::seg(0xE0, "JFIF\0\x01\x01\x00\x00\x48\x00\x48\x00\x00")
            . self::seg(0xE1, "Exif\0\0" . self::tiff($orientierung))
            . self::seg(0xE1, "http://ns.adobe.com/xap/1.0/\0<x:xmpmeta>" . self::GEHEIM . '</x:xmpmeta>')
            . self::seg(0xFE, 'Kommentar ' . self::GEHEIM)
            . self::seg(0xDB, "\x00" . str_repeat("\x01", 64))
            . self::seg(0xC0, "\x08" . pack('nn', $hoehe, $breite) . "\x03\x01\x22\x00\x02\x11\x01\x03\x11\x01")
            . self::seg(0xDA, "\x03\x01\x00\x02\x11\x03\x11\x00\x3F\x00")
            . "\x12\x34\xFF\x00\x56"
            . "\xFF\xD9"
            . 'MOTIONPHOTO-' . self::GEHEIM;
    }

    /**
     * Ein ECHTES, dekodierbares JPEG (braucht GD) mit EXIF-Ausrichtung,
     * Geheimnis und Anhang.
     */
    public static function gdJpeg(int $breite, int $hoehe, int $orientierung = 6): string {
        $bild = imagecreatetruecolor($breite, $hoehe);
        imagefilledrectangle($bild, 0, 0, $breite - 1, (int)($hoehe / 2), (int)imagecolorallocate($bild, 255, 0, 0));
        imagefilledrectangle($bild, 0, (int)($hoehe / 2) + 1, $breite - 1, $hoehe - 1, (int)imagecolorallocate($bild, 0, 0, 255));
        ob_start();
        imagejpeg($bild, null, 90);
        $jpeg = (string)ob_get_clean();
        unset($bild);

        return "\xFF\xD8" . self::seg(0xE1, "Exif\0\0" . self::tiff($orientierung)) . substr($jpeg, 2)
            . 'MOTIONPHOTO-' . self::GEHEIM;
    }

    /** Ein JPEG, dessen Aufbau der Parser nicht lesen kann (Müll zwischen Segmenten), mit Ausrichtung 6. */
    public static function unlesbaresJpeg(): string {
        return "\xFF\xD8" . self::seg(0xE1, "Exif\0\0" . self::tiff(6)) . 'MUELL' . self::GEHEIM;
    }

    /** Ein GIF mit Kommentar, XMP-Anwendungsblock und Anhang hinter dem Trailer. */
    public static function gif(): string {
        $unterbloecke = static fn(string $d): string => chr(strlen($d)) . $d . "\0";
        return 'GIF89a' . pack('vv', 2, 1) . "\x80\x00\x00" . "\x00\x00\x00\xFF\xFF\xFF"
            . "\x21\xFF\x0B" . 'NETSCAPE2.0' . "\x03\x01\x00\x00\x00"
            . "\x21\xFE" . $unterbloecke('Kommentar ' . self::GEHEIM)
            . "\x21\xFF\x0B" . 'XMP DataXMP' . $unterbloecke('<x:xmpmeta>' . self::GEHEIM . '</x:xmpmeta>')
            . "\x21\xF9\x04\x04\x0A\x00\x00\x00"
            . "\x2C" . pack('vvvv', 0, 0, 2, 1) . "\x00" . "\x02" . "\x02\x44\x01\x00"
            . "\x3B"
            . 'NACH-DEM-TRAILER-' . self::GEHEIM;
    }
}
