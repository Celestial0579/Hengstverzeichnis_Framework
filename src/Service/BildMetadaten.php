<?php
// src/Service/BildMetadaten.php

namespace App\Service;

use App\Helper\AtomicFile;
use App\Helper\HorseImagePath;
use PDO;

/**
 * Entfernt Metadaten aus Pferdefotos und Logos (Audit M21) und liest die
 * Ausrichtung (Audit N81).
 *
 * WARUM. Fotos wurden bis hierhin byteweise so gespeichert und ausgeliefert,
 * wie sie hochgeladen wurden. Ein Handyfoto trägt im EXIF-Block die
 * GPS-Position der Aufnahme, Zeitpunkt, Kameramodell und Seriennummer, oft
 * zusätzlich als XMP. Ein Foto vom eigenen Hof verriet damit die Hofadresse
 * eines Besitzers, dessen Kontaktdaten gar nicht öffentlich sind - jeder
 * Besucher konnte sie mit einem Klick auslesen.
 *
 * WIE. Ein reiner STRUKTURPARSER: Er zerlegt die Datei in ihre Segmente
 * (JPEG), Chunks (PNG, WebP) bzw. Blöcke (GIF) und übernimmt nur, was auf
 * einer Positivliste steht. Die Bilddaten selbst werden byteweise kopiert,
 * nicht dekodiert - deshalb braucht es weder GD noch ext/exif (siehe
 * docs/security.md: kein Bilddecoder im Standard-Image), und die Bildqualität
 * bleibt unverändert. Eine Negativliste ("EXIF raus") liefe jedem neuen
 * Metadatenformat hinterher; die Positivliste lässt Unbekanntes weg.
 *
 * Erhalten bleiben das Farbprofil (ICC) und die AUSRICHTUNG: Ohne sie läge
 * jedes Hochformatfoto vom Handy quer. Sie wird als minimaler EXIF-Eintrag
 * (26 Byte TIFF, nur Tag 0x0112) neu geschrieben - immer an derselben
 * Stelle, damit bereinigen() idempotent ist.
 *
 * Wegfallen, bewusst auch das, was kein EXIF heißt: XMP, IPTC/Photoshop,
 * Kommentare, eingebettete Vorschaubilder (JFIF-Thumbnail, JFXX), MPF,
 * JUMBF/C2PA und alles hinter dem Dateiende-Marker - dort liegen
 * Bewegungsfotos (MP4), HDR-Zusatzbilder (Gain-Maps) und Herstellerdaten,
 * jeweils mit eigenen Metadaten.
 *
 * Jeder Lesezugriff wird vorher gegen die Länge geprüft, jede Schleifenrunde
 * kommt voran: Eine kaputte Datei liefert null, nie eine Warning oder eine
 * Endlosschleife. null heißt beim Upload "abgelehnt", im Bestand "unverändert
 * gelassen und protokolliert".
 */
final class BildMetadaten {

    /** PNG: ancillary Chunks, die bleiben (Farbe, Maße, Animation). */
    private const PNG_BEHALTEN = [
        'tRNS', 'cHRM', 'gAMA', 'iCCP', 'sBIT', 'sRGB', 'cICP', 'mDCv', 'cLLi',
        'bKGD', 'pHYs', 'hIST', 'sPLT', 'acTL', 'fcTL', 'fdAT',
    ];

    /** WebP: Chunks, die bleiben. EXIF wird bei Bedarf neu angehängt. */
    private const WEBP_BEHALTEN = ['VP8X', 'VP8 ', 'VP8L', 'ALPH', 'ANIM', 'ANMF', 'ICCP'];

    /** GIF: Application Extensions, die bleiben (Animation, Farbprofil). */
    private const GIF_ANWENDUNGEN = ['NETSCAPE2.0', 'ANIMEXTS1.0', 'ICCRGBG1012'];

    /** Alle so viele Dateien schreibt bestandBereinigen() den Cursor. */
    private const CURSOR_TAKT = 25;

    private function __construct() {}

    // ------------------------------------------------------------------
    // Öffentliche Schnittstelle
    // ------------------------------------------------------------------

    /**
     * Die bereinigte Fassung, oder null, wenn Format oder Aufbau nicht
     * lesbar sind. Eine bereits saubere Datei kommt bytegleich zurück.
     */
    public static function bereinigen(string $bytes): ?string {
        $ergebnis = match (self::format($bytes)) {
            'jpeg' => self::jpeg($bytes, false),
            'png' => self::png($bytes, false),
            'webp' => self::webp($bytes, false),
            'gif' => self::gif($bytes),
            default => null,
        };

        return $ergebnis === null ? null : $ergebnis[0];
    }

    /**
     * bereinigen() plus Sicherheitsnetz: getimagesizefromstring() (PHP-Kern,
     * ohne GD) muss für Original und Ergebnis dieselben Maße und denselben
     * Typ melden. Weicht etwas ab, hätte der Parser die Struktur falsch
     * verstanden - dann lieber gar nichts schreiben.
     */
    public static function bereinigeBytes(string $bytes): ?string {
        $neu = self::bereinigen($bytes);
        if ($neu === null) {
            return null;
        }

        $vorher = @getimagesizefromstring($bytes);
        $nachher = $neu === $bytes ? $vorher : @getimagesizefromstring($neu);
        if (!is_array($vorher) || !is_array($nachher)
            || (int)$vorher[0] <= 0 || (int)$vorher[1] <= 0
            || $vorher[0] !== $nachher[0] || $vorher[1] !== $nachher[1] || $vorher[2] !== $nachher[2]) {
            return null;
        }

        return $neu;
    }

    /**
     * Bereinigt eine Datei an Ort und Stelle.
     *
     * Geschrieben wird nur bei einer Änderung, und zwar atomar (neue Datei
     * im selben Verzeichnis, dann rename()): Ein Abbruch mitten im Schreiben
     * darf ein Original, das es nur einmal gibt, nicht halb zurücklassen.
     * Eine EOI-Pflicht gibt es hier bewusst nicht - JPEGs ohne
     * Dateiende-Marker sind verbreitet und werden von Browsern angezeigt.
     *
     * @return 'bereinigt'|'unveraendert'|'unlesbar'|'schreibfehler'
     */
    public static function bereinigeDatei(string $pfad): string {
        $bytes = is_file($pfad) ? @file_get_contents($pfad) : false;
        if ($bytes === false) {
            return 'unlesbar';
        }

        $neu = self::bereinigeBytes($bytes);
        if ($neu === null) {
            return 'unlesbar';
        }
        if ($neu === $bytes) {
            return 'unveraendert';
        }

        $rechte = @fileperms($pfad);
        $modus = $rechte === false ? 0644 : ($rechte & 0777);

        return AtomicFile::write($pfad, $neu, $modus) ? 'bereinigt' : 'schreibfehler';
    }

    /**
     * Die EXIF-Ausrichtung 1-8; im Zweifel 1 ("wie gespeichert").
     * Liest nur die Köpfe, nicht die Bilddaten.
     */
    public static function orientierung(string $bytes): int {
        $ergebnis = match (self::format($bytes)) {
            'jpeg' => self::jpeg($bytes, true),
            'png' => self::png($bytes, true),
            'webp' => self::webp($bytes, true),
            default => null,
        };

        return $ergebnis === null ? 1 : $ergebnis[1];
    }

    /**
     * Bereinigt alle referenzierten Pferdefotos im Bestand.
     *
     * Für den einmaligen Datenschritt `bildmetadaten_entfernen` und für
     * Werkzeuge, die Fotos am Upload vorbei in die Ablage legen (Addon
     * `datenmigration` nach einem Import, Zurückspielen alter Sicherungen).
     *
     * Erfasst werden horses.image_url und horse_media.file_name (Bilder),
     * auch von Pferden im Papierkorb - der kann wiederherstellen. Das UNION
     * nimmt die Datei, die Haupt- und Galeriebild zugleich ist
     * (syncMainImage), nur einmal. Eine fehlende Datei wird still
     * übersprungen.
     *
     * Die Vorschaubilder einer Datei gehen mit, wenn sie umgeschrieben wurde
     * oder eine Ausrichtung ungleich 1 trägt - auch wenn sie selbst nicht
     * lesbar war: Vorschauen aus der Zeit vor Audit N81 liegen gedreht.
     *
     * $cursorSchluessel: settings-Schlüssel für die Wiederaufnahme. Er steht
     * für "alles bis hierher ist erledigt" und wird alle 25 Dateien und beim
     * Abbruch geschrieben, nach einem vollständigen fehlerfreien Durchlauf
     * gelöscht. Nach einem Schreibfehler rückt er nicht mehr vor - der
     * nächste Lauf nimmt die Datei erneut, die übrigen sind dann schon sauber
     * und kosten nur das Lesen. null = ohne Wiederaufnahme, ein Durchlauf.
     *
     * $zeitbudget: Sekunden, nach denen der Lauf hinter der aktuellen Datei
     * anhält (vollstaendig = false). null = ohne Grenze.
     *
     * 'unlesbar' und 'fehler' gehen zusätzlich je Datei per error_log()
     * hinaus: Auf dem impliziten Migrationsweg sieht niemand die
     * Schrittliste.
     *
     * @return array{bereinigt:int, unveraendert:int, vorschauen:int,
     *               unlesbar:list<string>, fehler:list<string>, vollstaendig:bool}
     */
    public static function bestandBereinigen(PDO $pdo, ?string $cursorSchluessel = null, ?float $zeitbudget = null): array {
        $ergebnis = [
            'bereinigt' => 0,
            'unveraendert' => 0,
            'vorschauen' => 0,
            'unlesbar' => [],
            'fehler' => [],
            'vollstaendig' => true,
        ];

        $cursor = $cursorSchluessel === null ? '' : self::cursorLesen($pdo, $cursorSchluessel);
        $cursorFest = false;   // nach einem Schreibfehler rückt er nicht mehr vor
        $start = microtime(true);
        $seitDemSchreiben = 0;
        $position = $cursor;

        // Binär vergleichen und sortieren: Unter einer _ci-Kollation wären
        // 'A.jpg' und 'a.jpg' gleich (das UNION nähme nur eine), und der
        // Cursor-Vergleich folgte einer anderen Ordnung als PHP.
        $stmt = $pdo->prepare(
            "SELECT w FROM (
                 SELECT CAST(image_url AS BINARY) AS w FROM horses
                  WHERE image_url IS NOT NULL AND image_url <> ''
                 UNION
                 SELECT CAST(file_name AS BINARY) FROM horse_media
                  WHERE type = 'image' AND file_name IS NOT NULL AND file_name <> ''
             ) t WHERE w > CAST(? AS BINARY) ORDER BY w LIMIT 200"
        );

        while (true) {
            $stmt->execute([$position]);
            $werte = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if ($werte === []) {
                break;
            }

            foreach ($werte as $wert) {
                $wert = (string)$wert;
                $position = $wert;

                $pfad = HorseImagePath::datei($wert);
                if ($pfad !== null) {
                    $bytes = @file_get_contents($pfad);
                    $status = self::bereinigeDatei($pfad);
                    $gedreht = $bytes !== false && self::orientierung($bytes) !== 1;
                    unset($bytes);

                    if ($status === 'bereinigt') {
                        $ergebnis['bereinigt']++;
                    } elseif ($status === 'unveraendert') {
                        $ergebnis['unveraendert']++;
                    } elseif ($status === 'unlesbar') {
                        $ergebnis['unlesbar'][] = $wert;
                        error_log('BildMetadaten: nicht lesbar, Metadaten NICHT entfernt: ' . $wert);
                    } else {
                        $ergebnis['fehler'][] = $wert;
                        $cursorFest = true;
                        error_log('BildMetadaten: Schreibfehler, Metadaten NICHT entfernt: ' . $wert);
                    }

                    if ($status === 'bereinigt' || $gedreht) {
                        Thumbnails::entfernen($wert, false);
                        $ergebnis['vorschauen']++;
                    }
                }

                if (!$cursorFest) {
                    $cursor = $wert;
                    if ($cursorSchluessel !== null && ++$seitDemSchreiben >= self::CURSOR_TAKT) {
                        self::cursorSchreiben($pdo, $cursorSchluessel, $cursor);
                        $seitDemSchreiben = 0;
                    }
                }

                if ($zeitbudget !== null && microtime(true) - $start >= $zeitbudget) {
                    // Noch etwas übrig? Sonst ist der Lauf trotzdem fertig.
                    $stmt->execute([$position]);
                    if ($stmt->fetchColumn() !== false) {
                        $stmt->closeCursor();
                        $ergebnis['vollstaendig'] = false;
                        if ($cursorSchluessel !== null) {
                            self::cursorSchreiben($pdo, $cursorSchluessel, $cursor);
                        }
                        return $ergebnis;
                    }
                    $stmt->closeCursor();
                    break 2;
                }
            }
        }

        if ($cursorSchluessel !== null) {
            if ($ergebnis['fehler'] === []) {
                $pdo->prepare('DELETE FROM settings WHERE setting_key = ?')->execute([$cursorSchluessel]);
            } else {
                self::cursorSchreiben($pdo, $cursorSchluessel, $cursor);
            }
        }

        return $ergebnis;
    }

    // ------------------------------------------------------------------
    // Formate
    // ------------------------------------------------------------------

    private static function format(string $b): ?string {
        if (strncmp($b, "\xFF\xD8", 2) === 0) {
            return 'jpeg';
        }
        if (strncmp($b, "\x89PNG\r\n\x1A\n", 8) === 0) {
            return 'png';
        }
        if (strlen($b) >= 12 && strncmp($b, 'RIFF', 4) === 0 && substr($b, 8, 4) === 'WEBP') {
            return 'webp';
        }
        if (strncmp($b, 'GIF87a', 6) === 0 || strncmp($b, 'GIF89a', 6) === 0) {
            return 'gif';
        }
        return null;
    }

    /**
     * JPEG: Segmentschleife ab SOI.
     *
     * Nicht-APP-Marker (SOFn, DHT, DQT, DRI, DAC, DNL, SOS …) bleiben. Von
     * den APPn bleiben nur JFIF (APP0, ohne Vorschaubild), ICC (APP2) und
     * Adobe (APP14, Farbraumangabe - ohne sie kippen CMYK-Farben). Nach
     * jedem SOS wird der entropiekodierte Bereich bytegleich übernommen bis
     * zum nächsten echten Marker (progressive JPEGs haben mehrere Scans).
     *
     * @return array{0:string,1:int}|null
     */
    private static function jpeg(string $b, bool $nurKopf): ?array {
        $laenge = strlen($b);
        $teile = ["\xFF\xD8"];
        $einfuegen = 1;          // Stelle für das minimale APP1
        $o = null;
        $scan = false;
        $pos = 2;

        while ($pos < $laenge) {
            if ($b[$pos] !== "\xFF") {
                return self::abbruch($nurKopf, $o);
            }
            // Füll-Bytes 0xFF vor einem Marker überspringen.
            $m = $pos + 1;
            while ($m < $laenge && $b[$m] === "\xFF") {
                $m++;
            }
            if ($m >= $laenge) {
                break;           // abgeschnitten nach Füll-Bytes: Rest übernehmen = nichts
            }
            $marker = ord($b[$m]);
            $pos = $m + 1;

            if ($marker === 0xD9) {                       // EOI: Ende, Anhang entfällt
                $teile[] = "\xFF\xD9";
                break;
            }
            if ($marker === 0x00 || $marker === 0xD8) {
                return self::abbruch($nurKopf, $o);
            }
            if (($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) {
                $teile[] = "\xFF" . chr($marker);         // Marker ohne Länge
                continue;
            }

            if ($pos + 2 > $laenge) {
                return self::abbruch($nurKopf, $o);
            }
            $segLaenge = (ord($b[$pos]) << 8) | ord($b[$pos + 1]);
            if ($segLaenge < 2 || $pos + $segLaenge > $laenge) {
                return self::abbruch($nurKopf, $o);
            }
            $daten = (string)substr($b, $pos + 2, $segLaenge - 2);
            $segment = "\xFF" . chr($marker) . substr($b, $pos, $segLaenge);
            $pos += $segLaenge;

            if ($marker === 0xE0) {
                // JFIF-Kopf: 5 Byte Kennung, Version, Einheit, Dichte (14 Byte).
                // Xthumb/Ythumb auf 0 - das eingebettete Vorschaubild fällt weg.
                if (strncmp($daten, "JFIF\0", 5) === 0 && strlen($daten) >= 14) {
                    if (count($teile) === 1) {
                        $einfuegen = 2;
                    }
                    $teile[] = "\xFF\xE0\x00\x10" . substr($daten, 0, 12) . "\x00\x00";
                }
                continue;
            }
            if ($marker === 0xE1) {
                if ($o === null && strncmp($daten, "Exif\0\0", 6) === 0) {
                    $o = self::tiffOrientierung(substr($daten, 6));
                }
                continue;
            }
            if ($marker === 0xE2) {
                if (strncmp($daten, "ICC_PROFILE\0", 12) === 0) {
                    $teile[] = $segment;
                }
                continue;
            }
            if ($marker === 0xEE) {
                if (strncmp($daten, 'Adobe', 5) === 0) {
                    $teile[] = $segment;
                }
                continue;
            }
            if (($marker >= 0xE3 && $marker <= 0xEF) || $marker === 0xFE) {
                continue;                                 // übrige APPn, COM
            }

            $teile[] = $segment;

            if ($marker === 0xDA) {
                $scan = true;
                if ($nurKopf) {
                    return ['', $o ?? 1];
                }
                // Entropiekodierte Daten: FF00 (maskiertes FF), RST0-7 und
                // Füll-FF gehören dazu; der nächste andere Marker beendet sie.
                $start = $pos;
                while (true) {
                    $ff = strpos($b, "\xFF", $pos);
                    if ($ff === false || $ff + 1 >= $laenge) {
                        $pos = $laenge;
                        break;
                    }
                    $n = ord($b[$ff + 1]);
                    if ($n === 0x00 || ($n >= 0xD0 && $n <= 0xD7)) {
                        $pos = $ff + 2;
                        continue;
                    }
                    if ($n === 0xFF) {
                        $pos = $ff + 1;
                        continue;
                    }
                    $pos = $ff;
                    break;
                }
                $teile[] = (string)substr($b, $start, $pos - $start);
            }
        }

        $o ??= 1;
        if ($nurKopf) {
            return ['', $o];
        }
        if (!$scan) {
            return null;                                  // kein einziger Scan: kein Bild
        }
        if ($o !== 1) {
            $tiff = self::minimalExif($o);
            array_splice($teile, $einfuegen, 0, ["\xFF\xE1" . pack('n', 2 + 6 + strlen($tiff)) . "Exif\0\0" . $tiff]);
        }

        return [implode('', $teile), $o];
    }

    /**
     * PNG: kritische Chunks bleiben, ancillary nur von der Positivliste.
     * tEXt/zTXt/iTXt, eXIf, tIME und alles Unbekannte fallen weg.
     *
     * @return array{0:string,1:int}|null
     */
    private static function png(string $b, bool $nurKopf): ?array {
        $laenge = strlen($b);
        $pos = 8;
        $teile = [substr($b, 0, 8)];
        $o = null;
        $erster = true;
        $idat = false;

        while ($pos < $laenge) {
            if ($pos + 12 > $laenge) {
                return self::abbruch($nurKopf, $o);
            }
            $n = unpack('N', $b, $pos)[1];
            $typ = substr($b, $pos + 4, 4);
            if (!ctype_alpha($typ) || $n > $laenge - $pos - 12) {
                return self::abbruch($nurKopf, $o);
            }
            $anfang = $pos;
            $pos += 12 + $n;

            if ($erster && $typ !== 'IHDR') {
                return self::abbruch($nurKopf, $o);
            }
            $erster = false;

            if ($typ === 'eXIf') {
                if ($o === null) {
                    $daten = (string)substr($b, $anfang + 8, $n);
                    $o = self::tiffOrientierung(strncmp($daten, "Exif\0\0", 6) === 0 ? substr($daten, 6) : $daten);
                }
                continue;
            }
            if ($typ === 'IDAT') {
                $idat = true;
            }

            // Bit 5 des ersten Buchstabens: 0 = kritisch (IHDR, PLTE, IDAT, IEND).
            $kritisch = (ord($typ[0]) & 0x20) === 0;
            if (!$nurKopf && ($kritisch || in_array($typ, self::PNG_BEHALTEN, true))) {
                $teile[] = substr($b, $anfang, 12 + $n);
            }
            if ($typ === 'IEND') {
                break;
            }
        }

        $o ??= 1;
        if ($nurKopf) {
            return ['', $o];
        }
        if (!$idat) {
            return self::abbruch($nurKopf, $o);
        }
        if ($o !== 1) {
            $tiff = self::minimalExif($o);
            array_splice($teile, 2, 0, [pack('N', strlen($tiff)) . 'eXIf' . $tiff . pack('N', crc32('eXIf' . $tiff))]);
        }

        return [implode('', $teile), $o];
    }

    /**
     * WebP (RIFF): Chunks von der Positivliste, EXIF bei Bedarf minimal am
     * Ende. Die VP8X-Kennbits für XMP und EXIF werden nachgeführt, die
     * RIFF-Größe neu gesetzt; Daten hinter der RIFF-Länge entfallen.
     *
     * @return array{0:string,1:int}|null
     */
    private static function webp(string $b, bool $nurKopf): ?array {
        $laenge = strlen($b);
        $o = null;
        $riffEnde = 8 + unpack('V', $b, 4)[1];
        if ($riffEnde > $laenge || $riffEnde < 12) {
            return self::abbruch($nurKopf, $o);
        }

        $pos = 12;
        $teile = [];
        $vp8x = null;
        while ($pos < $riffEnde) {
            if ($pos + 8 > $riffEnde) {
                return self::abbruch($nurKopf, $o);
            }
            $typ = substr($b, $pos, 4);
            $n = unpack('V', $b, $pos + 4)[1];
            if ($n > $riffEnde - $pos - 8) {
                return self::abbruch($nurKopf, $o);
            }
            $daten = (string)substr($b, $pos + 8, $n);
            $pos += 8 + $n + ($n & 1);

            if ($typ === 'EXIF') {
                if ($o === null) {
                    $o = self::tiffOrientierung(strncmp($daten, "Exif\0\0", 6) === 0 ? substr($daten, 6) : $daten);
                }
                continue;
            }
            if (!in_array($typ, self::WEBP_BEHALTEN, true)) {
                continue;
            }
            if ($typ === 'VP8X') {
                if ($n < 10 || $vp8x !== null) {
                    return self::abbruch($nurKopf, $o);
                }
                $vp8x = count($teile);
            }
            $teile[] = [$typ, $daten];
        }
        $bild = false;
        foreach ($teile as [$typ]) {
            $bild = $bild || $typ === 'VP8 ' || $typ === 'VP8L' || $typ === 'ANMF';
        }
        if (!$bild) {
            return self::abbruch($nurKopf, $o);
        }

        $o ??= 1;
        if ($nurKopf) {
            return ['', $o];
        }

        // Ohne VP8X (einfaches Format) kann eine Datei kein EXIF tragen.
        $exif = $o !== 1 && $vp8x !== null;
        if ($vp8x !== null) {
            $flags = ord($teile[$vp8x][1][0]) & ~0x0C;
            if ($exif) {
                $flags |= 0x08;
            }
            $teile[$vp8x][1][0] = chr($flags);
        }
        if ($exif) {
            $teile[] = ['EXIF', self::minimalExif($o)];
        }

        $koerper = 'WEBP';
        foreach ($teile as [$typ, $daten]) {
            $koerper .= $typ . pack('V', strlen($daten)) . $daten . (strlen($daten) & 1 ? "\0" : '');
        }

        return ['RIFF' . pack('V', strlen($koerper)) . $koerper, $o];
    }

    /**
     * GIF: Blockschleife. Bilddaten, Graphic Control, Plain Text und die
     * Animations-Anwendungen bleiben; Kommentare, XMP (als Application
     * Extension "XMP DataXMP") und alles nach dem Trailer fallen weg. Eine
     * Ausrichtung gibt es bei GIF nicht.
     *
     * @return array{0:string,1:int}|null
     */
    private static function gif(string $b): ?array {
        $laenge = strlen($b);
        if ($laenge < 13) {
            return null;
        }
        $pos = 13;
        $gepackt = ord($b[10]);
        if ($gepackt & 0x80) {
            $pos += 3 * (2 << ($gepackt & 0x07));
        }
        if ($pos > $laenge) {
            return null;
        }
        $teile = [substr($b, 0, $pos)];

        while ($pos < $laenge) {
            $kennung = ord($b[$pos]);
            if ($kennung === 0x3B) {
                $teile[] = "\x3B";
                break;
            }
            if ($kennung === 0x2C) {
                if ($pos + 10 > $laenge) {
                    return null;
                }
                $ende = $pos + 10;
                $bildGepackt = ord($b[$pos + 9]);
                if ($bildGepackt & 0x80) {
                    $ende += 3 * (2 << ($bildGepackt & 0x07));
                }
                $ende += 1;   // LZW-Mindestcodelänge
                if ($ende > $laenge) {
                    return null;
                }
                $ende = self::gifUnterbloecke($b, $ende);
                if ($ende === null) {
                    return null;
                }
                $teile[] = substr($b, $pos, $ende - $pos);
                $pos = $ende;
                continue;
            }
            if ($kennung === 0x21) {
                if ($pos + 2 > $laenge) {
                    return null;
                }
                $label = ord($b[$pos + 1]);
                $ende = self::gifUnterbloecke($b, $pos + 2);
                if ($ende === null) {
                    return null;
                }
                $behalten = $label === 0xF9 || $label === 0x01;
                if ($label === 0xFF && $pos + 3 < $laenge && ord($b[$pos + 2]) === 11 && $pos + 14 <= $laenge) {
                    $behalten = in_array(substr($b, $pos + 3, 11), self::GIF_ANWENDUNGEN, true);
                }
                if ($behalten) {
                    $teile[] = substr($b, $pos, $ende - $pos);
                }
                $pos = $ende;
                continue;
            }
            return null;
        }

        return [implode('', $teile), 1];
    }

    /**
     * Strukturfehler: bereinigen() lehnt ab (null), orientierung() bleibt
     * bei dem, was bis dahin gelesen war - eine kaputte Datei mit
     * Ausrichtung 6 hat gedrehte Altvorschauen (Audit N81).
     *
     * @return array{0:string,1:int}|null
     */
    private static function abbruch(bool $nurKopf, ?int $o): ?array {
        return $nurKopf ? ['', $o ?? 1] : null;
    }

    /** Position hinter der Unterblockfolge (bis einschließlich des 0-Blocks) oder null. */
    private static function gifUnterbloecke(string $b, int $pos): ?int {
        $laenge = strlen($b);
        while (true) {
            if ($pos >= $laenge) {
                return null;
            }
            $n = ord($b[$pos]);
            $pos += 1 + $n;
            if ($n === 0) {
                return $pos;
            }
        }
    }

    // ------------------------------------------------------------------
    // TIFF (EXIF)
    // ------------------------------------------------------------------

    /**
     * Die Ausrichtung aus einem TIFF-Block (EXIF ohne "Exif\0\0"): II/MM,
     * Magic 42, IFD0-Eintrag 0x0112 vom Typ SHORT. Jeder Offset wird gegen
     * die Länge geprüft; im Zweifel 1.
     */
    public static function tiffOrientierung(string $t): int {
        $laenge = strlen($t);
        if ($laenge < 8) {
            return 1;
        }
        $bo = substr($t, 0, 2);
        if ($bo !== 'II' && $bo !== 'MM') {
            return 1;
        }
        $u16 = static fn(int $p): int => unpack($bo === 'II' ? 'v' : 'n', $t, $p)[1];
        $u32 = static fn(int $p): int => unpack($bo === 'II' ? 'V' : 'N', $t, $p)[1];

        if ($u16(2) !== 42) {
            return 1;
        }
        $ifd = $u32(4);
        if ($ifd < 8 || $ifd > $laenge - 2) {
            return 1;
        }
        $anzahl = $u16($ifd);
        for ($i = 0; $i < $anzahl; $i++) {
            $e = $ifd + 2 + 12 * $i;
            if ($e + 12 > $laenge) {
                break;
            }
            if ($u16($e) !== 0x0112) {
                continue;
            }
            if ($u16($e + 2) !== 3 || $u32($e + 4) < 1) {
                return 1;
            }
            $wert = $u16($e + 8);
            return $wert >= 1 && $wert <= 8 ? $wert : 1;
        }

        return 1;
    }

    /** 26 Byte TIFF (little endian): ein IFD0 mit genau dem Eintrag 0x0112. */
    private static function minimalExif(int $o): string {
        return 'II' . pack('vV', 42, 8)
            . pack('v', 1)
            . pack('vvVvv', 0x0112, 3, 1, $o, 0)
            . pack('V', 0);
    }

    // ------------------------------------------------------------------
    // Cursor
    // ------------------------------------------------------------------

    private static function cursorLesen(PDO $pdo, string $schluessel): string {
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute([$schluessel]);
        $wert = $stmt->fetchColumn();
        return $wert === false || $wert === null ? '' : (string)$wert;
    }

    private static function cursorSchreiben(PDO $pdo, string $schluessel, string $wert): void {
        $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        )->execute([$schluessel, $wert]);
    }
}
