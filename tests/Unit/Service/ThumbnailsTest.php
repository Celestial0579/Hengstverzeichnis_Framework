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

    public function testDieEinstellungMussGesetztSein(): void {
        $this->assertTrue(Thumbnails::gdVorhanden(), 'Vorbedingung dieses Tests.');

        $this->assertFalse(Thumbnails::aktiv([]), 'Ohne Angabe: aus.');
        $this->assertFalse(Thumbnails::aktiv(['horse_thumbnails' => '0']));
        $this->assertTrue(Thumbnails::aktiv(['horse_thumbnails' => '1']));
    }
}
