<?php
// tests/Unit/Service/ThumbnailsTest.php

namespace Tests\Unit\Service;

use App\Helper\HorseImagePath;
use App\Service\Thumbnails;
use PHPUnit\Framework\TestCase;

/**
 * Verkleinerte Fassungen von Pferdefotos (#397).
 *
 * Der Dienst muss VOR ALLEM eines können: nichts kaputt machen, wenn er
 * nicht kann. Jeder Fehlschlag endet mit `null`, und `null` heisst überall
 * „nimm das Original" — nie „Fehler". Ein fehlendes Vorschaubild ist ein
 * langsamer Seitenaufbau; eine Fehlermeldung wäre ein kaputtes Bild.
 *
 * Deshalb prüfen die meisten Fälle hier nicht, DASS etwas entsteht, sondern
 * dass in den Grenzfällen NICHTS entsteht.
 */
class ThumbnailsTest extends TestCase {

    private string $dir = '';

    protected function setUp(): void {
        if (!Thumbnails::gdVorhanden()) {
            $this->markTestSkipped('Ohne GD gibt es nichts zu verkleinern.');
        }

        $this->dir = sys_get_temp_dir() . '/hv_thumbs_' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0777, true);
        HorseImagePath::overrideForTests($this->dir, $this->dir);
    }

    protected function tearDown(): void {
        HorseImagePath::overrideForTests(null, null);
        Thumbnails::overrideVerfuegbarForTests(null);

        foreach (glob($this->dir . '/*') ?: [] as $datei) {
            @unlink($datei);
        }
        @rmdir($this->dir);
    }

    private function bildAnlegen(string $name, int $breite, int $hoehe): string {
        $bild = imagecreatetruecolor($breite, $hoehe);
        $farbe = imagecolorallocate($bild, 10, 120, 200);
        imagefilledrectangle($bild, 0, 0, $breite, $hoehe, $farbe);
        $pfad = $this->dir . '/' . $name;
        imagejpeg($bild, $pfad, 90);
        unset($bild);   // kein imagedestroy(): seit 8.5 deprecated

        return $pfad;
    }

    /**
     * Eine PNG-ATTRAPPE: ein 45 Byte kleiner Kopf, der Riesenmasse behauptet,
     * dahinter ein dünn besetztes Loch (`ftruncate`) von 24 MB.
     *
     * Zwei Eigenschaften, auf denen die Beweisführung ruht — beide auf diesem
     * Host nachgemessen:
     *
     * 1. `getimagesize()` liest nur den Kopf und meldet 100000x100000, ohne ein
     *    einziges Byte zu allozieren. Genau so kommt die Bombe in `erzeugen()`
     *    an, bevor irgendetwas dekodiert wird.
     * 2. Die Datei belegt 4 KB auf der Platte, `filesize()` meldet aber 24 MB —
     *    und `file_get_contents()` allokiert diese 24 MB wirklich. Das ist der
     *    Hebel, mit dem der Test unterscheiden kann, OB gelesen wurde.
     *
     * Es gibt bewusst KEINEN IDAT-Chunk. Mit gültigem Bildkörper würde GD bei
     * entferntem Schutz `gdImageCreateTrueColor(100000, 100000)` aufrufen — 40 GB,
     * also genau der Absturz, gegen den hier geprüft wird. Ohne IDAT bricht
     * libpng schon in `png_read_info` ab und fordert nichts an.
     */
    private function kopfAttrappe(string $name, int $breite, int $hoehe, int $bytes = 25165824): string {
        $ihdr = 'IHDR' . pack('N2', $breite, $hoehe) . chr(8) . chr(2) . "\0\0\0";
        $kopf = "\x89PNG\r\n\x1a\n" . pack('N', 13) . $ihdr . pack('N', crc32($ihdr) ^ 0xFFFFFFFF);

        $pfad = $this->dir . '/' . $name;
        $fh = fopen($pfad, 'wb');
        self::assertNotFalse($fh, 'Attrappe nicht anlegbar.');
        fwrite($fh, $kopf);
        ftruncate($fh, $bytes);
        fclose($fh);

        return $pfad;
    }

    /** speicherReicht() ist privat - hier bewusst die EINHEIT, nicht der Weg. */
    private function reicht(int $breite, int $hoehe): bool {
        /* Kein setAccessible(): seit PHP 8.1 überflüssig und in 8.5 deprecated,
           und phpunit.xml stellt Deprecations hart. */
        return (bool)(new \ReflectionMethod(Thumbnails::class, 'speicherReicht'))
            ->invoke(null, $breite, $hoehe);
    }

    public function testEineVerkleinerteFassungEntstehtUndPasstInDieKante(): void {
        $pfad = $this->bildAnlegen('horse_1_ab.jpg', 2000, 1500);

        $ziel = Thumbnails::erzeugen($pfad, 'thumb', '/uploads/horses/horse_1_ab.jpg');

        $this->assertNotNull($ziel);
        $this->assertFileExists($ziel);
        [$b, $h] = getimagesize($ziel);
        $this->assertSame(Thumbnails::GROESSEN['thumb'], max($b, $h));
        $this->assertLessThan(filesize($pfad), filesize($ziel), 'Sonst waere nichts gewonnen.');
    }

    public function testDasOriginalBleibtUnangetastet(): void {
        $pfad = $this->bildAnlegen('horse_2_cd.jpg', 1200, 900);
        $vorher = md5_file($pfad);

        Thumbnails::erzeugen($pfad, 'thumb', '/uploads/horses/horse_2_cd.jpg');

        $this->assertSame($vorher, md5_file($pfad));
    }

    public function testEinKleinesBildWirdNichtHochskaliert(): void {
        /* Ein hochskalierter Thumb waere GROESSER als das Original und
           schlechter - hier ist das Original die richtige Antwort. */
        $pfad = $this->bildAnlegen('horse_3_ef.jpg', 100, 80);

        $this->assertNull(Thumbnails::erzeugen($pfad, 'thumb', '/uploads/horses/horse_3_ef.jpg'));
        $this->assertNull(Thumbnails::pfad('/uploads/horses/horse_3_ef.jpg', 'thumb'));
    }

    public function testEineDateiDieKeinBildIstWirdNichtDekodiert(): void {
        $pfad = $this->dir . '/horse_4_gh.jpg';
        file_put_contents($pfad, 'das ist kein Bild, sondern Text');

        $this->assertNull(Thumbnails::erzeugen($pfad, 'horse_4_gh.jpg', 'thumb'));
        $this->assertNull(Thumbnails::erzeugen($pfad, 'thumb', '/uploads/horses/horse_4_gh.jpg'));
    }

    public function testEineUnbekannteGroesseErzeugtNichts(): void {
        $pfad = $this->bildAnlegen('horse_5_ij.jpg', 800, 600);

        $this->assertNull(Thumbnails::erzeugen($pfad, 'riesig', '/uploads/horses/horse_5_ij.jpg'));
        $this->assertNull(Thumbnails::dateiname('/uploads/horses/horse_5_ij.jpg', 'riesig'));
    }

    public function testDerDateinameKannNichtAusDemVerzeichnisAusbrechen(): void {
        /* Der Spaltenwert stammt aus der eigenen Datenbank - aber genau
           darauf hat sich schon manche Anwendung verlassen, bevor ein
           CSV-Import etwas anderes hineinschrieb. */
        foreach (['../../etc/passwd', '..', '.', '', '/etc/passwd'] as $boese) {
            $name = Thumbnails::dateiname($boese, 'thumb');
            if ($name !== null) {
                $this->assertStringNotContainsString('/', $name);
                $this->assertStringNotContainsString('..', $name);
            }
        }
        $this->assertNull(Thumbnails::dateiname('..', 'thumb'));
    }

    public function testEineZweiteAnfrageErzeugtNichtNeu(): void {
        $pfad = $this->bildAnlegen('horse_6_kl.jpg', 1600, 1200);
        $erst = Thumbnails::erzeugen($pfad, 'thumb', '/uploads/horses/horse_6_kl.jpg');
        $this->assertNotNull($erst);
        $stempel = filemtime($erst);

        touch($erst, $stempel - 5);
        $zweit = Thumbnails::erzeugen($pfad, 'thumb', '/uploads/horses/horse_6_kl.jpg');

        $this->assertSame($erst, $zweit);
    }

    public function testEinNeueresOriginalErzwingtEineNeueFassung(): void {
        /* Sonst zeigte ein ausgetauschtes Foto in jeder Liste weiter das
           alte - und niemand sähe, woran es liegt. */
        $pfad = $this->bildAnlegen('horse_7_mn.jpg', 1600, 1200);
        $thumb = Thumbnails::erzeugen($pfad, 'thumb', '/uploads/horses/horse_7_mn.jpg');
        $this->assertNotNull($thumb);
        touch($thumb, time() - 3600);

        $inhaltVorher = md5_file($thumb);
        $this->bildAnlegen('horse_7_mn.jpg', 1200, 400);   // anderes Seitenverhaeltnis
        touch($pfad, time());

        Thumbnails::erzeugen($pfad, 'thumb', '/uploads/horses/horse_7_mn.jpg');
        $this->assertNotSame($inhaltVorher, md5_file($thumb));
    }

    public function testLoeschenNimmtAlleGroessenMit(): void {
        $pfad = $this->bildAnlegen('horse_8_op.jpg', 2000, 1500);
        foreach (array_keys(Thumbnails::GROESSEN) as $groesse) {
            $this->assertNotNull(
                Thumbnails::erzeugen($pfad, $groesse, '/uploads/horses/horse_8_op.jpg')
            );
        }

        Thumbnails::entfernen('/uploads/horses/horse_8_op.jpg');

        foreach (array_keys(Thumbnails::GROESSEN) as $groesse) {
            $this->assertNull(Thumbnails::pfad('/uploads/horses/horse_8_op.jpg', $groesse));
        }
        $this->assertFileExists($pfad, 'Das Original bleibt - geloescht wird es woanders.');
    }

    public function testOhneGdPassiertGarNichts(): void {
        /* Der wichtigste Fall: Das offizielle Docker-Image hat kein GD.
           Dort muss sich die Anwendung exakt wie vorher verhalten. */
        $pfad = $this->bildAnlegen('horse_9_qr.jpg', 2000, 1500);
        Thumbnails::overrideVerfuegbarForTests(false);

        $this->assertFalse(Thumbnails::gdVorhanden());
        $this->assertFalse(Thumbnails::aktiv(['horse_thumbnails' => '1']),
            'Die Einstellung allein darf nichts einschalten, was es nicht gibt.');
        $this->assertNull(Thumbnails::erzeugen($pfad, 'thumb', '/uploads/horses/horse_9_qr.jpg'));
    }

    /**
     * #414: Ein Bild oberhalb der Grenze darf nicht einmal GELESEN werden.
     *
     * WARUM DIE SPEICHERZUSICHERUNG DIE TRAGENDE IST, NICHT DAS assertNull.
     * `erzeugen()` liefert `null` sowohl bei abgewiesener Bombe (Thumbnails.php,
     * `speicherReicht`-Zweig) als auch bei gescheitertem Dekodieren — und eine
     * Kopf-Attrappe ist nie dekodierbar. Ein Test, der nur `assertNull()` prüft,
     * bliebe deshalb auch OHNE jeden Schutz grün und bewiese nichts. Erst das
     * Speicher-Hochwasser unterscheidet die beiden Fälle: Greift der Schutz,
     * steigt es um 0 MB; fällt er weg, holt `file_get_contents()` die vollen
     * 24 MB der Attrappe, und die Zusicherung fällt.
     */
    public function testEinBildUeberDerGrenzeWirdNichtEinmalGelesen(): void {
        $pfad = $this->kopfAttrappe('horse_10_bombe.png', 100000, 100000);

        $masse = getimagesize($pfad);
        $this->assertSame([100000, 100000], [$masse[0] ?? 0, $masse[1] ?? 0],
            'Vorbedingung: der Kopf muss die Riesenmasse melden, sonst prüft der Test etwas anderes.');
        $this->assertGreaterThan(20 * 1024 * 1024, filesize($pfad),
            'Vorbedingung: die Attrappe muss gross GEMELDET werden, sonst ist die Messung stumpf.');

        memory_reset_peak_usage();
        $basis = memory_get_usage(true);
        $ziel = Thumbnails::erzeugen($pfad, 'thumb', '/uploads/horses/horse_10_bombe.png');
        $hochwasser = memory_get_peak_usage(true) - $basis;

        $this->assertNull($ziel, 'Über der Grenze gibt es das Original, kein Vorschaubild.');
        $this->assertFileDoesNotExist($this->dir . '/horse_10_bombe_thumb.jpg',
            'Es darf keine verkleinerte Zieldatei entstanden sein.');
        $this->assertLessThan(4 * 1024 * 1024, $hochwasser,
            'Die Datei darf gar nicht erst in den Speicher gelesen werden - ohne den Schutz '
            . 'hätte file_get_contents() hier 24 MB geholt.');
    }

    /**
     * Dasselbe mit einer glaubhaften Kameragrösse statt eines Extremwerts:
     * 30000x30000 sind 900 Megapixel — ein gültiges, auf der Platte winziges
     * JPEG/PNG, das GD zu einer Allokation von rund 3,6 GB verleiten würde.
     * Das ist das Szenario aus #414 wörtlich.
     */
    public function testAuchEineGlaubhafteRiesengroesseWirdNichtGelesen(): void {
        $pfad = $this->kopfAttrappe('horse_11_bombe.png', 30000, 30000);

        memory_reset_peak_usage();
        $basis = memory_get_usage(true);
        $ziel = Thumbnails::erzeugen($pfad, 'card', '/uploads/horses/horse_11_bombe.png');
        $hochwasser = memory_get_peak_usage(true) - $basis;

        $this->assertNull($ziel);
        $this->assertLessThan(4 * 1024 * 1024, $hochwasser);
    }

    /**
     * Die reine Rechnung hinter der Grenze — ausdrücklich die EINHEIT.
     *
     * Dieser Test bewiese für sich genommen NICHTS über die Anwendung: Fiele der
     * Aufruf in `erzeugen()` weg, bliebe er grün, während die Anwendung offen
     * stünde. Er ergänzt die beiden Tests darüber, er ersetzt sie nicht.
     *
     * `memory_limit` wird nur ANGEHOBEN ('-1'), nie gesenkt: Ein Limit unter dem
     * aktuellen Verbrauch beendet den PHPUnit-Prozess mit einem Fatal Error und
     * reisst den ganzen Lauf mit. Angehoben isoliert es ausserdem MAX_PIXEL von
     * der Speicheranteil-Rechnung — sonst hinge das Ergebnis an der php.ini des
     * jeweiligen Läufers.
     */
    public function testDiePixelgrenzeLiegtGenauAufMaxPixel(): void {
        $alt = (string)ini_get('memory_limit');
        ini_set('memory_limit', '-1');
        try {
            $this->assertTrue($this->reicht(10000, 5000), 'Exakt MAX_PIXEL muss noch durchgehen.');
            $this->assertFalse($this->reicht(10001, 5000), 'Eine Zeile darüber nicht mehr.');
            $this->assertFalse($this->reicht(30000, 30000), 'Das Szenario aus #414.');
            $this->assertFalse($this->reicht(0, 5000), 'Eine Kantenlänge von 0 ist kein Bild.');
            $this->assertFalse($this->reicht(-1, 5000), 'Und ein negatives Produkt erst recht nicht.');
        } finally {
            ini_set('memory_limit', $alt);
        }
    }

    /**
     * Und die zweite Hälfte der Rechnung: Auch UNTERHALB von MAX_PIXEL wird
     * abgewiesen, wenn der Anteil am Speicherlimit nicht reicht. Ohne diesen
     * Fall bliebe die halbe Bedingung ungeprüft.
     */
    public function testEinKnappesSpeicherlimitWeistAuchUnterhalbVonMaxPixelAb(): void {
        if (memory_get_usage(true) > 200 * 1024 * 1024) {
            $this->markTestSkipped('Der Prozess braucht bereits mehr, als der Test einstellen will.');
        }

        $alt = (string)ini_get('memory_limit');
        ini_set('memory_limit', '320M');           // Schranke: 0,6 * 320M = 201,3 MB
        try {
            // 8000x5000 = 40 MP, also UNTER MAX_PIXEL - Bedarf 40e6*4*1,3 = 208 MB.
            $this->assertFalse($this->reicht(8000, 5000),
                'Unter MAX_PIXEL, aber über dem Anteil am Speicherlimit: abweisen.');
            $this->assertTrue($this->reicht(2000, 1500), 'Ein normales Foto passt immer.');
        } finally {
            ini_set('memory_limit', $alt);
        }
    }

    public function testDieEinstellungMussGesetztSein(): void {
        $this->assertTrue(Thumbnails::gdVorhanden(), 'Vorbedingung dieses Tests.');

        $this->assertFalse(Thumbnails::aktiv([]), 'Ohne Angabe: aus.');
        $this->assertFalse(Thumbnails::aktiv(['horse_thumbnails' => '0']));
        $this->assertTrue(Thumbnails::aktiv(['horse_thumbnails' => '1']));
    }
}
