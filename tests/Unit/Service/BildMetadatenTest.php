<?php
// tests/Unit/Service/BildMetadatenTest.php

namespace Tests\Unit\Service;

use App\Service\BildMetadaten;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Metadaten in Fotos (Audit M21) und ihre Ausrichtung (Audit N81).
 *
 * Die Fixtures werden byteweise gebaut - der Parser braucht kein GD, und
 * die Tests sollen es auch nicht brauchen (CI-Standard). Das Geheimnis in
 * jedem Metadatenblock ist dieselbe Zeichenkette 'GEHEIMER-HOF': Steht sie
 * nach der Bereinigung noch irgendwo in der Datei, ist etwas durchgerutscht,
 * egal in welchem Block.
 */
class BildMetadatenTest extends TestCase {

    private const GEHEIM = 'GEHEIMER-HOF';

    // ------------------------------------------------------------------
    // Bausteine
    // ------------------------------------------------------------------

    private static function seg(int $marker, string $daten): string {
        return "\xFF" . chr($marker) . pack('n', strlen($daten) + 2) . $daten;
    }

    /** TIFF mit Ausrichtung, Kamerahersteller und GPS-Verweis. */
    private static function tiff(int $orientierung, bool $grossEndian = false): string {
        $v = $grossEndian ? 'n' : 'v';
        $V = $grossEndian ? 'N' : 'V';
        $eintraege = [
            [0x010F, 2, 13, 8 + 2 + 3 * 12 + 4],          // Make -> Zeichenkette dahinter
            [0x0112, 3, 1, null],                          // Orientation
            [0x8825, 4, 1, 8 + 2 + 3 * 12 + 4 + 13],      // GPS-IFD
        ];
        $t = ($grossEndian ? 'MM' : 'II') . pack($v, 42) . pack($V, 8) . pack($v, count($eintraege));
        foreach ($eintraege as [$tag, $typ, $anzahl, $wert]) {
            $t .= pack($v, $tag) . pack($v, $typ) . pack($V, $anzahl);
            $t .= $wert === null ? pack($v, $orientierung) . "\0\0" : pack($V, $wert);
        }
        $t .= pack($V, 0);
        $t .= self::GEHEIM . "\0";
        // GPS-IFD: ein Eintrag GPSLatitudeRef = 'N'
        $t .= pack($v, 1) . pack($v, 1) . pack($v, 2) . pack($V, 2) . "N\0\0\0" . pack($V, 0);
        return $t;
    }

    private static function sof0(int $breite, int $hoehe): string {
        return self::seg(0xC0, "\x08" . pack('n', $hoehe) . pack('n', $breite) . "\x03\x01\x22\x00\x02\x11\x01\x03\x11\x01");
    }

    private static function icc(): string {
        return self::seg(0xE2, "ICC_PROFILE\0\x01\x01" . str_repeat("\x42", 20));
    }

    private static function adobe(): string {
        return self::seg(0xEE, "Adobe\x00\x64\x00\x00\x00\x00\x01");
    }

    private static function scanDaten(): string {
        // FF00 (maskiert), RST0 und ein Füll-FF vor dem nächsten Marker.
        return "\x12\x34\xFF\x00\x56\xFF\xD0\x78\x9A";
    }

    private static function sos(): string {
        return self::seg(0xDA, "\x03\x01\x00\x02\x11\x03\x11\x00\x3F\x00");
    }

    /** Das "volle Handy-JPEG" aus dem Testplan (a). */
    private static function vollesJpeg(int $orientierung = 6): string {
        $jfifMitVorschau = "JFIF\0\x01\x01\x00\x00\x48\x00\x48\x01\x01" . "\xAA\xBB\xCC";
        return "\xFF\xD8"
            . self::seg(0xE0, $jfifMitVorschau)
            . self::seg(0xE1, "Exif\0\0" . self::tiff($orientierung))
            . self::seg(0xE1, "http://ns.adobe.com/xap/1.0/\0<x:xmpmeta>" . self::GEHEIM . '</x:xmpmeta>')
            . self::seg(0xED, "Photoshop 3.0\08BIM\x04\x04\0\0\0\0\0\x10" . self::GEHEIM)
            . self::seg(0xFE, 'Kommentar ' . self::GEHEIM)
            . self::icc()
            . self::seg(0xE2, "MPF\0II*\0" . self::GEHEIM)
            . self::seg(0xEB, "JP\0\0jumb" . self::GEHEIM)
            . self::adobe()
            . self::seg(0xDB, "\x00" . str_repeat("\x01", 64))
            . self::sof0(20, 10)
            . self::seg(0xC4, "\x00" . str_repeat("\x00", 16))
            . self::sos()
            . self::scanDaten()
            . "\xFF\xD9"
            . 'MOTIONPHOTO-mp4-' . self::GEHEIM;
    }

    /** Ein bereits sauberes JPEG ohne Ausrichtung. */
    private static function sauberesJpeg(): string {
        return "\xFF\xD8"
            . self::seg(0xE0, "JFIF\0\x01\x01\x00\x00\x48\x00\x48\x00\x00")
            . self::seg(0xDB, "\x00" . str_repeat("\x01", 64))
            . self::sof0(20, 10)
            . self::sos()
            . self::scanDaten()
            . "\xFF\xD9";
    }

    private static function pngChunk(string $typ, string $daten): string {
        return pack('N', strlen($daten)) . $typ . $daten . pack('N', crc32($typ . $daten));
    }

    private static function png(?int $orientierung = 8): string {
        $idat = (string)gzcompress("\0\xFF\x00\x00\x00\xFF\x00");
        return "\x89PNG\r\n\x1A\n"
            . self::pngChunk('IHDR', pack('NN', 2, 1) . "\x08\x02\x00\x00\x00")
            . self::pngChunk('tEXt', 'Comment' . "\0" . self::GEHEIM)
            . self::pngChunk('iTXt', 'XML:com.adobe.xmp' . "\0\0\0\0\0" . self::GEHEIM)
            . ($orientierung !== null ? self::pngChunk('eXIf', self::tiff($orientierung, true)) : '')
            . self::pngChunk('tIME', "\x07\xE9\x01\x01\x00\x00\x00")
            . self::pngChunk('gAMA', pack('N', 45455))
            . self::pngChunk('IDAT', $idat)
            . self::pngChunk('IEND', '')
            . 'ANHANG-' . self::GEHEIM;
    }

    private static function riffChunk(string $typ, string $daten): string {
        return $typ . pack('V', strlen($daten)) . $daten . (strlen($daten) % 2 === 1 ? "\0" : '');
    }

    private static function webp(int $orientierung = 3): string {
        $vp8x = chr(0x20 | 0x08 | 0x04) . "\0\0\0" . substr(pack('V', 20 - 1), 0, 3) . substr(pack('V', 10 - 1), 0, 3);
        // VP8L-Kopf: Signatur 0x2F, dann 14 Bit Breite-1, 14 Bit Höhe-1.
        $vp8l = "\x2F" . pack('V', (20 - 1) | ((10 - 1) << 14)) . "\x00\x00";   // 7 Byte: ungerade -> Padding
        $koerper = 'WEBP'
            . self::riffChunk('VP8X', $vp8x)
            . self::riffChunk('ICCP', str_repeat("\x42", 12))
            . self::riffChunk('VP8L', $vp8l)
            . self::riffChunk('EXIF', "Exif\0\0" . self::tiff($orientierung))
            . self::riffChunk('XMP ', '<x:xmpmeta>' . self::GEHEIM . '</x:xmpmeta>!');
        return 'RIFF' . pack('V', strlen($koerper)) . $koerper . 'HINTER-RIFF-' . self::GEHEIM;
    }

    private static function gifUnterbloecke(string $daten): string {
        $aus = '';
        foreach (str_split($daten, 255) as $teil) {
            $aus .= chr(strlen($teil)) . $teil;
        }
        return $aus . "\0";
    }

    /** @return array{0:string,1:array<string,string>} GIF und die Teile, die bleiben müssen */
    private static function gif(): array {
        $kopf = 'GIF89a' . pack('vv', 2, 1) . "\x80\x00\x00" . "\x00\x00\x00\xFF\xFF\xFF";
        $netscape = "\x21\xFF\x0B" . 'NETSCAPE2.0' . "\x03\x01\x00\x00\x00";
        $gce = "\x21\xF9\x04\x04\x0A\x00\x00\x00";
        $bild = "\x2C" . pack('vvvv', 0, 0, 2, 1) . "\x00" . "\x02" . "\x02\x44\x01\x00";
        $gif = $kopf
            . $netscape
            . "\x21\xFE" . self::gifUnterbloecke('Kommentar ' . self::GEHEIM)
            . "\x21\xFF\x0B" . 'XMP DataXMP' . self::gifUnterbloecke('<x:xmpmeta>' . self::GEHEIM . '</x:xmpmeta>')
            . $gce
            . $bild
            . "\x3B"
            . 'NACH-DEM-TRAILER-' . self::GEHEIM;
        return [$gif, ['kopf' => $kopf, 'netscape' => $netscape, 'gce' => $gce, 'bild' => $bild]];
    }

    // ------------------------------------------------------------------
    // JPEG
    // ------------------------------------------------------------------

    public function testVollesJpegVerliertAlleMetadatenUndBehaeltBildUndFarbe(): void {
        $roh = self::vollesJpeg(6);
        $neu = BildMetadaten::bereinigen($roh);

        $this->assertNotNull($neu);
        $this->assertStringNotContainsString(self::GEHEIM, $neu);
        $this->assertStringNotContainsString('MPF', $neu);
        $this->assertStringNotContainsString('MOTIONPHOTO', $neu);
        $this->assertStringNotContainsString('xmpmeta', $neu);
        $this->assertStringNotContainsString("\xAA\xBB\xCC", $neu, 'Das JFIF-Vorschaubild fällt weg.');
        $this->assertStringContainsString(self::icc(), $neu, 'Das Farbprofil bleibt bytegleich.');
        $this->assertStringContainsString(self::adobe(), $neu, 'Adobe (Farbraum) bleibt bytegleich.');
        $this->assertStringContainsString(self::sos() . self::scanDaten() . "\xFF\xD9", $neu, 'Scan-Daten bytegleich.');
        $this->assertStringStartsWith("\xFF\xD8", $neu);
        $this->assertStringEndsWith("\xFF\xD9", $neu);

        // Ausrichtung bleibt als minimales EXIF direkt nach dem JFIF-Kopf.
        $this->assertSame(6, BildMetadaten::orientierung($roh));
        $this->assertSame(6, BildMetadaten::orientierung($neu));
        $this->assertSame(18 + 2, strpos($neu, "\xFF\xE1"), 'APP1 steht direkt hinter SOI + JFIF.');

        $masse = getimagesizefromstring($neu);
        $this->assertSame([20, 10, IMAGETYPE_JPEG], [$masse[0], $masse[1], $masse[2]]);
        $this->assertNotNull(BildMetadaten::bereinigeBytes($roh));
    }

    public function testProgressivesJpegMitZweiScansBleibtStrukturellErhalten(): void {
        $scan2 = "\x11\xFF\x00\x22";
        $roh = "\xFF\xD8"
            . self::seg(0xDB, "\x00" . str_repeat("\x01", 64))
            . self::seg(0xC2, "\x08" . pack('nn', 10, 20) . "\x01\x01\x11\x00")
            . self::seg(0xC4, "\x00" . str_repeat("\x00", 16))
            . self::sos() . self::scanDaten()
            . self::seg(0xFE, self::GEHEIM)
            . self::seg(0xC4, "\x10" . str_repeat("\x00", 16))
            . self::sos() . $scan2
            . "\xFF\xD9";

        $neu = BildMetadaten::bereinigen($roh);

        $this->assertNotNull($neu);
        $this->assertSame(str_replace(self::seg(0xFE, self::GEHEIM), '', $roh), $neu);
        $this->assertSame(2, substr_count($neu, "\xFF\xDA"));
    }

    public function testOhneExifOderMitAusrichtungEinsEntstehtKeinApp1(): void {
        $this->assertStringNotContainsString("\xFF\xE1", (string)BildMetadaten::bereinigen(self::vollesJpeg(1)));

        $ohne = str_replace(self::seg(0xE1, "Exif\0\0" . self::tiff(6)), '', self::vollesJpeg(6));
        $this->assertStringNotContainsString("\xFF\xE1", (string)BildMetadaten::bereinigen($ohne));
        $this->assertSame(1, BildMetadaten::orientierung($ohne));
    }

    public function testIdempotentUndSaubereDateiBleibtBytegleich(): void {
        $einmal = (string)BildMetadaten::bereinigen(self::vollesJpeg(6));
        $this->assertSame($einmal, BildMetadaten::bereinigen($einmal));

        $sauber = self::sauberesJpeg();
        $this->assertSame($sauber, BildMetadaten::bereinigen($sauber));

        foreach ([self::png(8), self::webp(3), self::gif()[0]] as $roh) {
            $einmal = (string)BildMetadaten::bereinigen($roh);
            $this->assertSame($einmal, BildMetadaten::bereinigen($einmal));
        }
    }

    /** @return array<string, array{0:string}> */
    public static function kaputteDateien(): array {
        $jpeg = self::vollesJpeg(6);
        return [
            'leer' => [''],
            'nur SOI' => ["\xFF\xD8"],
            'unbekanntes Format' => ['BM' . str_repeat("\0", 40)],
            'Segment ueber das Dateiende' => ["\xFF\xD8\xFF\xE1\x40\x00Exif"],
            'Laengenfeld unter 2' => ["\xFF\xD8\xFF\xE1\x00\x01" . self::sof0(20, 10)],
            'Muell zwischen Segmenten' => ["\xFF\xD8" . 'xx' . self::sof0(20, 10)],
            'zweites SOI' => ["\xFF\xD8\xFF\xD8" . self::sof0(20, 10)],
            'JPEG abgeschnitten im Laengenfeld' => [substr($jpeg, 0, 5)],
            'PNG ohne IHDR' => ["\x89PNG\r\n\x1A\n" . self::pngChunk('IDAT', 'x')],
            'PNG Chunk ueber das Ende' => [substr(self::png(), 0, 40)],
            'PNG ohne IDAT' => ["\x89PNG\r\n\x1A\n" . self::pngChunk('IHDR', pack('NN', 2, 1) . "\x08\x02\x00\x00\x00") . self::pngChunk('IEND', '')],
            'WebP RIFF-Laenge zu gross' => ['RIFF' . pack('V', 999) . 'WEBPVP8X'],
            'WebP ohne Bilddaten' => ['RIFF' . pack('V', 4 + 8 + 10) . 'WEBP' . self::riffChunk('VP8X', str_repeat("\0", 10))],
            'GIF abgeschnitten' => [substr(self::gif()[0], 0, 30)],
            'GIF unbekannter Block' => ['GIF89a' . pack('vv', 1, 1) . "\x00\x00\x00" . "\x99"],
        ];
    }

    #[DataProvider('kaputteDateien')]
    public function testKaputteStrukturenErgebenNullOhneWarning(string $roh): void {
        $this->assertNull(BildMetadaten::bereinigen($roh));
        $this->assertNull(BildMetadaten::bereinigeBytes($roh));
        $this->assertSame(1, BildMetadaten::orientierung($roh));
    }

    public function testJpegOhneDateiendeWirdBereinigtNichtAbgelehnt(): void {
        $roh = substr(self::vollesJpeg(6), 0, -strlen("\xFF\xD9" . 'MOTIONPHOTO-mp4-' . self::GEHEIM));
        $this->assertStringEndsNotWith("\xFF\xD9", $roh);

        $neu = BildMetadaten::bereinigeBytes($roh);

        $this->assertNotNull($neu);
        $this->assertStringNotContainsString(self::GEHEIM, $neu);
        $this->assertStringEndsWith(self::scanDaten(), $neu, 'Der Rest wird übernommen.');

        $pfad = sys_get_temp_dir() . '/hv_bm_' . bin2hex(random_bytes(5)) . '.jpg';
        file_put_contents($pfad, $roh);
        try {
            $this->assertSame('bereinigt', BildMetadaten::bereinigeDatei($pfad), 'Keine EOI-Pflicht für Originale.');
            $this->assertSame($neu, file_get_contents($pfad));
        } finally {
            @unlink($pfad);
        }
    }

    // ------------------------------------------------------------------
    // PNG, WebP, GIF
    // ------------------------------------------------------------------

    public function testPngVerliertTextUndExifUndBekommtEinGueltigesMinimalesExif(): void {
        $roh = self::png(8);
        $neu = BildMetadaten::bereinigeBytes($roh);

        $this->assertNotNull($neu);
        $this->assertStringNotContainsString(self::GEHEIM, $neu);
        $this->assertStringNotContainsString('tEXt', $neu);
        $this->assertStringNotContainsString('tIME', $neu);
        $this->assertStringContainsString('gAMA', $neu);
        $this->assertStringEndsWith('IEND' . pack('N', crc32('IEND')), $neu);
        $this->assertSame(8, BildMetadaten::orientierung($neu));

        // eXIf direkt hinter IHDR, mit korrekter CRC.
        $pos = 8 + 25;
        $n = unpack('N', $neu, $pos)[1];
        $this->assertSame('eXIf', substr($neu, $pos + 4, 4));
        $daten = substr($neu, $pos + 8, $n);
        $this->assertSame(26, $n);
        $this->assertSame(crc32('eXIf' . $daten), unpack('N', $neu, $pos + 8 + $n)[1]);

        $ohne = BildMetadaten::bereinigen(self::png(null));
        $this->assertStringNotContainsString('eXIf', (string)$ohne);
    }

    public function testWebpFlagsGroesseUndPadding(): void {
        $roh = self::webp(3);
        $neu = BildMetadaten::bereinigeBytes($roh);

        $this->assertNotNull($neu);
        $this->assertStringNotContainsString(self::GEHEIM, $neu);
        $this->assertStringNotContainsString('XMP ', $neu);
        $this->assertSame(strlen($neu) - 8, unpack('V', $neu, 4)[1], 'RIFF-Größe neu gesetzt, nichts dahinter.');
        $this->assertSame(0, strlen($neu) % 2, 'Chunks mit Padding.');
        $flags = ord($neu[20]);
        $this->assertSame(0x20 | 0x08, $flags, 'ICC bleibt, XMP-Bit weg, EXIF-Bit für das neue EXIF.');
        $this->assertStringContainsString('ICCP', $neu);
        $this->assertStringEndsWith('EXIF' . pack('V', 26) . substr($neu, -26), $neu);
        $this->assertSame(3, BildMetadaten::orientierung($neu));

        $ohne = (string)BildMetadaten::bereinigen(self::webp(1));
        $this->assertStringNotContainsString('EXIF', $ohne);
        $this->assertSame(0x20, ord($ohne[20]));
    }

    public function testGifVerliertKommentarXmpUndAnhangUndBehaeltAnimationUndBild(): void {
        [$roh, $teile] = self::gif();
        $neu = BildMetadaten::bereinigeBytes($roh);

        $this->assertNotNull($neu);
        $this->assertStringNotContainsString(self::GEHEIM, $neu);
        $this->assertStringNotContainsString('XMP DataXMP', $neu);
        $this->assertSame($teile['kopf'] . $teile['netscape'] . $teile['gce'] . $teile['bild'] . "\x3B", $neu);
        $this->assertSame(1, BildMetadaten::orientierung($neu));
    }

    // ------------------------------------------------------------------
    // TIFF-Leser
    // ------------------------------------------------------------------

    public function testTiffLeserMitBeidenBytefolgenUndGrenzfaellen(): void {
        for ($o = 1; $o <= 8; $o++) {
            $this->assertSame($o, BildMetadaten::tiffOrientierung(self::tiff($o)));
            $this->assertSame($o, BildMetadaten::tiffOrientierung(self::tiff($o, true)));
        }
        $this->assertSame(1, BildMetadaten::tiffOrientierung(self::tiff(9)), 'Außerhalb 1-8 gilt 1.');
        $this->assertSame(1, BildMetadaten::tiffOrientierung(''));
        $this->assertSame(1, BildMetadaten::tiffOrientierung('XX' . substr(self::tiff(6), 2)));
        $this->assertSame(1, BildMetadaten::tiffOrientierung('II' . pack('v', 43) . substr(self::tiff(6), 4)));
        $this->assertSame(1, BildMetadaten::tiffOrientierung('II*' . "\0" . pack('V', 99999)));
        $this->assertSame(1, BildMetadaten::tiffOrientierung(substr(self::tiff(6), 0, 20)), 'Abgeschnittener IFD');
        // Falscher Typ (LONG statt SHORT)
        $t = self::tiff(6);
        $t[8 + 2 + 12 + 2] = "\x04";
        $this->assertSame(1, BildMetadaten::tiffOrientierung($t));
    }

    // ------------------------------------------------------------------
    // Dateien
    // ------------------------------------------------------------------

    public function testEinEchtesGdJpegBleibtNachDerBereinigungDekodierbar(): void {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            $this->markTestSkipped('Ohne GD.');
        }
        $bild = imagecreatetruecolor(40, 30);
        imagefilledrectangle($bild, 0, 0, 39, 29, (int)imagecolorallocate($bild, 200, 10, 10));
        ob_start();
        imagejpeg($bild, null, 90);
        $jpeg = (string)ob_get_clean();
        unset($bild);

        $mitExif = "\xFF\xD8" . self::seg(0xE1, "Exif\0\0" . self::tiff(6)) . substr($jpeg, 2) . 'TRAILER' . self::GEHEIM;
        $neu = BildMetadaten::bereinigeBytes($mitExif);

        $this->assertNotNull($neu);
        $this->assertStringNotContainsString(self::GEHEIM, $neu);
        $dekodiert = @imagecreatefromstring($neu);
        $this->assertNotFalse($dekodiert);
        $this->assertSame([40, 30], [imagesx($dekodiert), imagesy($dekodiert)]);
        $this->assertSame(6, BildMetadaten::orientierung($neu));
    }

    public function testBereinigeDateiSchreibtNurBeiAenderungUndOhneTempReste(): void {
        $dir = sys_get_temp_dir() . '/hv_bm_' . bin2hex(random_bytes(5));
        mkdir($dir);
        try {
            $sauber = $dir . '/sauber.jpg';
            file_put_contents($sauber, self::sauberesJpeg());
            touch($sauber, time() - 100);
            clearstatcache();
            $mtime = filemtime($sauber);

            $this->assertSame('unveraendert', BildMetadaten::bereinigeDatei($sauber));
            clearstatcache();
            $this->assertSame($mtime, filemtime($sauber), 'Eine saubere Datei wird nicht angefasst.');

            $roh = $dir . '/roh.jpg';
            file_put_contents($roh, self::vollesJpeg(6));
            chmod($roh, 0640);
            $this->assertSame('bereinigt', BildMetadaten::bereinigeDatei($roh));
            $this->assertStringNotContainsString(self::GEHEIM, (string)file_get_contents($roh));
            clearstatcache();
            $this->assertSame(0640, fileperms($roh) & 0777, 'Die Rechte bleiben.');

            // Der Parser "versteht" die Struktur, aber getimagesize() findet
            // keine Maße (kein SOF) - das Sicherheitsnetz greift, das
            // Original bleibt unangetastet.
            $kaputt = $dir . '/kaputt.jpg';
            $inhalt = "\xFF\xD8" . self::seg(0xFE, self::GEHEIM) . self::sos() . self::scanDaten() . "\xFF\xD9";
            file_put_contents($kaputt, $inhalt);
            $this->assertSame('unlesbar', BildMetadaten::bereinigeDatei($kaputt));
            $this->assertSame($inhalt, file_get_contents($kaputt));

            $this->assertSame('unlesbar', BildMetadaten::bereinigeDatei($dir . '/fehlt.jpg'));
            $this->assertSame([], glob($dir . '/.*.tmp') ?: []);
        } finally {
            foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $datei) {
                if (is_file($datei)) {
                    unlink($datei);
                }
            }
            rmdir($dir);
        }
    }
}
