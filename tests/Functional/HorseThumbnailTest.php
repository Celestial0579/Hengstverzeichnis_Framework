<?php
// tests/Functional/HorseThumbnailTest.php

namespace Tests\Functional;

use App\Database;
use App\Service\Thumbnails;

/**
 * Vorschaubilder über den echten Weg (#397).
 *
 * WAS HIER GEPRÜFT WIRD, UND WARUM NICHT IM UNIT-TEST. Dass der Dienst ein
 * Bild verkleinern kann, steht in `tests/Unit/Service/ThumbnailsTest.php`.
 * Hier geht es um drei Zusagen, die nur über HTTP zu beweisen sind:
 *
 * 1. **Der Schalter wirkt.** Ohne ihn liefert dieselbe Adresse dieselben
 *    Bytes wie vorher — ein Update darf das Verhalten eines Bestands nicht
 *    ändern. Genau deshalb ist die Vorgabe `aus`.
 * 2. **Die Vorschau ist genauso geschützt wie das Original.** Sie liegt in
 *    derselben Ablage und geht durch dieselbe Route; die Verkleinerung
 *    passiert NACH allen Prüfungen. Ein zweiter Auslieferungsweg mit eigener
 *    Prüfung wäre genau die Doppelung, an der so etwas schiefgeht — und ein
 *    Vorschaubild eines unveröffentlichten Pferdes wäre derselbe Fehler wie
 *    das Original davon, nur kleiner.
 * 3. **Ein unbekannter Größenwert liefert das Original**, keine 404. Die
 *    Adresse steht in ausgelieferten Seiten und in Zwischenspeichern; sie
 *    darf nicht davon abhängen, wie eine Einstellung gerade steht.
 */
class HorseThumbnailTest extends FunctionalTestCase {

    /** @var array<int, int> */
    private array $pferde = [];
    /** @var array<int, string> */
    private array $dateien = [];

    protected function setUp(): void {
        parent::setUp();

        if (!Thumbnails::gdVorhanden()) {
            $this->markTestSkipped('Ohne GD gibt es keine Vorschaubilder.');
        }

        // ZUERST der Admin-Client: Er stoesst die Ersteinrichtung an. Ohne
        // ihn gibt es die Tabelle `horses` noch gar nicht, und das Saeen
        // unten scheitert mit "Base table doesn't exist" - eine Meldung, die
        // nach einem kaputten Schema aussieht statt nach einer fehlenden
        // Vorbedingung. Dieselbe Falle wie in SprachAddonTest.
        $this->authenticatedClient();
    }

    protected function tearDown(): void {
        $db = Database::getInstance();
        foreach ($this->pferde as $id) {
            $db->prepare('DELETE FROM horses WHERE id = ?')->execute([$id]);
        }
        foreach ($this->dateien as $pfad) {
            @unlink($pfad);
            foreach (array_keys(Thumbnails::GROESSEN) as $groesse) {
                @unlink(preg_replace('/\.[^.]+$/', '', $pfad) . '_' . $groesse . '.jpg');
            }
        }
        $db->prepare("DELETE FROM settings WHERE setting_key = 'horse_thumbnails'")->execute();

        parent::tearDown();
    }

    /** Ein Foto, das gross genug ist, dass Verkleinern etwas bringt. */
    private function pferdMitFoto(bool $veroeffentlicht = true): int {
        $dir = \App\Helper\HorseImagePath::dir();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $name = 'thumbprobe_' . uniqid() . '.jpg';
        $pfad = $dir . '/' . $name;

        $bild = imagecreatetruecolor(1800, 1350);
        for ($i = 0; $i < 60; $i++) {
            $farbe = imagecolorallocate($bild, ($i * 4) % 256, (255 - $i * 3) % 256, ($i * 7) % 256);
            imagefilledrectangle($bild, $i * 30, 0, ($i + 1) * 30, 1350, $farbe);
        }
        imagejpeg($bild, $pfad, 92);
        unset($bild);
        $this->dateien[] = $pfad;

        $db = Database::getInstance();
        $db->prepare("INSERT INTO horses (name, sex, image_url, is_published, created_at) VALUES (?, 'stallion', ?, ?, NOW())")
           ->execute(['Vorschauprobe ' . uniqid(), '/uploads/horses/' . $name, $veroeffentlicht ? 1 : 0]);
        $id = (int)$db->lastInsertId();
        $this->pferde[] = $id;

        return $id;
    }

    private function schalter(bool $an): void {
        $db = Database::getInstance();
        $db->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES ('horse_thumbnails', ?)
             ON DUPLICATE KEY UPDATE setting_value = ?"
        )->execute([$an ? '1' : '0', $an ? '1' : '0']);
    }

    public function testOhneSchalterLiefertDieAdresseDasOriginal(): void {
        $id = $this->pferdMitFoto();
        $this->schalter(false);
        $gast = $this->newClient();

        $original = $gast->get('/media/horse-image?id=' . $id);
        $mitGroesse = $gast->get('/media/horse-image?id=' . $id . '&groesse=thumb');

        $this->assertSame(200, $original->statusCode);
        $this->assertSame(200, $mitGroesse->statusCode);
        $this->assertSame(
            strlen($original->body),
            strlen($mitGroesse->body),
            'Ohne Schalter muss dieselbe Datei kommen - ein Update darf einen Bestand nicht veraendern.'
        );
    }

    public function testMitSchalterKommtEineKleinereFassung(): void {
        $id = $this->pferdMitFoto();
        $this->schalter(true);
        $gast = $this->newClient();

        $original = $gast->get('/media/horse-image?id=' . $id);
        $klein = $gast->get('/media/horse-image?id=' . $id . '&groesse=thumb');

        $this->assertSame(200, $klein->statusCode);
        $this->assertLessThan(
            strlen($original->body),
            strlen($klein->body),
            'Sonst waere nichts gewonnen - genau das ist der Zweck des Issues.'
        );

        // Und das Original bleibt unter seiner eigenen Adresse erreichbar.
        $this->assertSame(200, $original->statusCode);
        $this->assertGreaterThan(0, strlen($original->body));
    }

    public function testEinUnbekannterGroessenwertLiefertDasOriginal(): void {
        $id = $this->pferdMitFoto();
        $this->schalter(true);
        $gast = $this->newClient();

        $original = $gast->get('/media/horse-image?id=' . $id);
        $unsinn = $gast->get('/media/horse-image?id=' . $id . '&groesse=riesengross');

        $this->assertSame(200, $unsinn->statusCode, 'Keine 404 - die Adresse steht in Zwischenspeichern.');
        $this->assertSame(strlen($original->body), strlen($unsinn->body));
    }

    public function testDieVorschauIstGenausoGeschuetztWieDasOriginal(): void {
        $id = $this->pferdMitFoto(false);          // unveroeffentlicht
        $this->schalter(true);
        $gast = $this->newClient();

        $this->assertSame(
            404,
            $gast->get('/media/horse-image?id=' . $id . '&groesse=thumb')->statusCode,
            'Ein Vorschaubild eines unveroeffentlichten Pferdes ist derselbe Fehler wie das Original davon.'
        );
        $this->assertSame(404, $gast->get('/media/horse-image?id=' . $id)->statusCode);

        // Der Admin sieht beides - sonst pruefte der Test nur eine kaputte Route.
        $admin = $this->authenticatedClient();
        $this->assertSame(200, $admin->get('/media/horse-image?id=' . $id . '&groesse=thumb')->statusCode);
    }

    public function testEinFremderRefererWirdAuchBeiDerVorschauAbgewiesen(): void {
        $id = $this->pferdMitFoto();
        $this->schalter(true);
        $gast = $this->newClient();

        $antwort = $gast->get(
            '/media/horse-image?id=' . $id . '&groesse=thumb',
            ['Referer' => 'https://fremde-seite.example/klau']
        );

        $this->assertSame(403, $antwort->statusCode);
    }

    /**
     * Mit dem geloeschten Medium gehen seine Vorschaubilder (#397).
     *
     * Dieser Test geht ueber `HorseMedia::loeschen()` und nicht ueber
     * `Thumbnails::entfernen()` - genau das ist der Punkt. Die Gegenprobe
     * zeigte: Nimmt man den Aufruf in `dateiEntfernen()` heraus, bleibt
     * jeder Test gruen, weil sie alle die Funktion direkt pruefen. Ein
     * Unit-Test auf eine Aufraeumfunktion beweist nicht, dass sie jemals
     * gerufen wird - dieselbe Luecke wie bei #344.
     */
    public function testGeloeschteMedienNehmenIhreVorschaubilderMit(): void {
        $id = $this->pferdMitFoto();
        $this->schalter(true);

        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT image_url FROM horses WHERE id = ?');
        $stmt->execute([$id]);
        $spaltenwert = (string)$stmt->fetchColumn();

        $medienId = \App\Service\HorseMedia::hinzufuegen($id, $spaltenwert, null, null, 10);
        $this->assertGreaterThan(0, $medienId);

        // Abrufen erzeugt das Vorschaubild.
        $this->assertSame(200, $this->newClient()
            ->get('/media/horse-media?id=' . $medienId . '&groesse=thumb')->statusCode);
        $thumb = \App\Service\Thumbnails::pfad($spaltenwert, 'thumb');
        $this->assertNotNull($thumb, 'Vorbedingung: das Vorschaubild muss entstanden sein.');
        $this->assertFileExists($thumb);

        // Das Hauptbild zeigt noch auf dieselbe Datei - dateiEntfernen() darf
        // sie deshalb NICHT anfassen, die Vorschau aber schon nicht mehr
        // brauchen. Also erst das Hauptbild loesen.
        $db->prepare('UPDATE horses SET image_url = NULL WHERE id = ?')->execute([$id]);

        $this->assertTrue(\App\Service\HorseMedia::loeschen($medienId));

        $this->assertNull(
            \App\Service\Thumbnails::pfad($spaltenwert, 'thumb'),
            'Sonst bleibt je geloeschtem Medium eine Waise in der Ablage liegen.'
        );
    }

    /**
     * Die Galerie-Kachel zeigt die kleine Fassung, die Lightbox das Original.
     *
     * Ohne `data-gross` nähme die Lightbox die Adresse aus dem `<img>` — und
     * das wäre seit dieser Änderung ein hochskaliertes Vorschaubild. Der
     * Schalter hätte dann die Grossansicht mit verschlechtert.
     */
    public function testDieLightboxBekommtDasOriginalMitgegeben(): void {
        $id = $this->pferdMitFoto();
        $this->schalter(true);

        $seite = $this->newClient()->get('/horse?id=' . $id);
        $this->assertSame(200, $seite->statusCode);

        $this->assertStringContainsString('groesse=card', $seite->body, 'Das Hero-Bild nimmt die mittlere Groesse.');
    }
}
