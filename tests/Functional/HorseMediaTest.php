<?php
// tests/Functional/HorseMediaTest.php

namespace Tests\Functional;

use App\Database;
use Tests\Support\HttpClient;

/**
 * Fotos und Videos je Pferd (#339) über den echten HTTP-Weg.
 *
 * Der Punkt dieser Datei ist die AUSLIEFERUNG. Das Addon `galerie` brachte
 * eine zweite Ausliefer-Route mit, und jede Regel - gültige Sitzung,
 * horses.view, is_published, Referer - musste dort ein zweites Mal richtig
 * sein. Hier wird geprüft, dass die eine Route des Kerns dieselben Regeln
 * anwendet wie die für das Hauptbild.
 */
class HorseMediaTest extends FunctionalTestCase {

    /** @var array<int, int> Angelegte Pferde, die wieder weg müssen. */
    private array $aufraeumen = [];

    protected function tearDown(): void {
        if ($this->aufraeumen !== []) {
            $db = Database::getInstance();
            /* Erst die Dateien, dann die Zeilen: Der Weg über
               /admin/horses/media/delete taugt hier nicht - HorseMedia::loeschen()
               räumt die Datei ab, SOLANGE horses.image_url noch darauf zeigt, und
               lässt sie dann liegen. Ohne das hier blieben je Lauf mehrere MB in
               storage/horses zurück (der Grössen-Test lädt allein 10 MB hoch). */
            $dateien = $db->prepare(
                'SELECT file_name FROM horse_media WHERE horse_id = ? AND file_name IS NOT NULL'
            );
            $loeschen = $db->prepare('DELETE FROM horses WHERE id = ?');
            foreach ($this->aufraeumen as $id) {
                $dateien->execute([$id]);
                foreach ($dateien->fetchAll(\PDO::FETCH_COLUMN) as $wert) {
                    $name = basename((string)$wert);
                    if ($name !== '' && $name !== '.' && $name !== '..') {
                        @unlink(\App\Helper\HorseImagePath::dir() . '/' . $name);
                    }
                }
                $loeschen->execute([$id]);
            }
            $this->aufraeumen = [];
        }
        parent::tearDown();
    }

    /** Ein winziges, gültiges PNG - der Upload prüft den Inhalt, nicht die Endung. */
    private function pngBytes(): string {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
    }

    private function pferdAnlegen(HttpClient $admin, string $name, bool $veroeffentlicht = true): int {
        $form = $admin->get('/admin/horses/create');
        $antwort = $admin->post('/admin/horses/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $name,
            'is_published' => $veroeffentlicht ? '1' : '',
        ]);
        $this->assertSame('/admin/horses', parse_url((string)$antwort->location(), PHP_URL_PATH), "Body: {$antwort->body}");

        $stmt = Database::getInstance()->prepare('SELECT id FROM horses WHERE name = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$name]);
        $id = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $id);
        $this->aufraeumen[] = $id;

        return $id;
    }

    private function bildHochladen(HttpClient $admin, int $horseId, string $caption = ''): int {
        $seite = $admin->get('/admin/horses/edit?id=' . $horseId);
        $antwort = $admin->postFile(
            '/admin/horses/media/add',
            [
                'csrf_token' => $seite->formField('csrf_token') ?? '',
                'horse_id' => (string)$horseId,
                'caption' => $caption,
            ],
            'media_image',
            'foto.png',
            $this->pngBytes(),
            'image/png'
        );
        $this->assertStringContainsString('media=media_added', (string)$antwort->location(), "Body: {$antwort->body}");

        $stmt = Database::getInstance()->prepare(
            'SELECT id FROM horse_media WHERE horse_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$horseId]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Ein Upload OHNE Erfolgszusicherung — für die Ablehnungsfälle.
     *
     * `bildHochladen()` sichert Erfolg schon in sich zu und taugt hier nicht.
     * `video_url` wird bewusst NICHT mitgesendet: Der Controller greift nach
     * einer abgelehnten Datei auf den Video-Link zurück, ein mitgeschickter
     * gültiger Link liesse also trotzdem eine Zeile entstehen.
     */
    private function medienUpload(
        HttpClient $admin,
        int $horseId,
        string $dateiname,
        string $inhalt,
        string $gemeldeterTyp = 'image/png'
    ): \Tests\Support\HttpResponse {
        return $admin->postFile(
            '/admin/horses/media/add',
            [
                'csrf_token' => $this->csrfTokenFrom($admin, '/admin/horses/edit?id=' . $horseId),
                'horse_id' => (string)$horseId,
            ],
            'media_image',
            $dateiname,
            $inhalt,
            $gemeldeterTyp
        );
    }

    private function medienZeilen(int $horseId): int {
        $stmt = Database::getInstance()->prepare('SELECT COUNT(*) FROM horse_media WHERE horse_id = ?');
        $stmt->execute([$horseId]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * #413: Beide Ablehnungswege des Bild-Uploads — falscher Inhalt und
     * Übergrösse — und der Nachweis, dass dabei KEINE Medienzeile entsteht.
     *
     * WARUM DIE ZUSICHERUNGEN SO GENAU SIND. „Nicht erfolgreich" ist hier
     * wertlos: Ein CSRF-Fehler antwortet mit 403 ganz ohne Weiterleitung, ein
     * unbekanntes Pferd leitet auf `/admin/horses` um, und eine PHP-Warnung
     * verhinderte den Location-Header überhaupt. Alle drei sähen aus wie eine
     * erfolgreiche Abweisung. Deshalb wird auf 302 UND den exakten Zielwert
     * `media=media_invalid` zugesichert, und zusätzlich auf die Zeilenzahl
     * dieses einen Pferdes.
     *
     * Und weil `$_FILES['media_image']` auch dann fehlt, wenn der Feldname
     * falsch geschrieben ist — was ebenfalls zu `media_invalid` führte, ohne
     * die Prüfung je zu erreichen —, steht am Ende eine Positivkontrolle mit
     * demselben Aufruf und gültigen Bytes.
     */
    public function testEinAlsBildGetarnterFremdinhaltWirdAbgelehnt(): void {
        $admin = $this->authenticatedClient();
        $pferd = $this->pferdAnlegen($admin, 'Uploadablehnung ' . uniqid());

        /* Name und gemeldeter Typ lügen bewusst: Nur so beweist der Test,
           dass der INHALT entscheidet und nicht $_FILES['type'] des Clients. */
        $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg">'
             . '<script>alert(1)</script></svg>';
        $antwort = $this->medienUpload($admin, $pferd, 'foto.png', $svg, 'image/png');

        $this->assertSame(302, $antwort->statusCode,
            'Keine Weiterleitung heisst: etwas VOR der Inhaltsprüfung hat gegriffen '
            . '(CSRF, Recht, Warnung). Body: ' . substr($antwort->body, 0, 300));
        $this->assertSame(
            '/admin/horses/edit?id=' . $pferd . '&media=media_invalid',
            (string)$antwort->location()
        );
        $this->assertSame(0, $this->medienZeilen($pferd),
            'Ein abgelehnter Upload darf keine Medienzeile hinterlassen.');

        // Positivkontrolle: derselbe Weg, gültige Bytes - und es entsteht eine Zeile.
        $this->assertGreaterThan(0, $this->bildHochladen($admin, $pferd));
        $this->assertSame(1, $this->medienZeilen($pferd));
    }

    /**
     * #413, zweiter Ablehnungsweg: die Grössengrenze der Anwendung.
     *
     * Die Nutzlast MUSS ein echter PNG-Kopf mit Füllung sein. Mit beliebigem
     * Müll erfolgte die Ablehnung schon an der Inhaltsprüfung — die
     * Grössengrenze bliebe ungeprüft, und eine Gegenprobe, die sie entfernt,
     * bliebe grün.
     *
     * Der Grenzfall am Ende ist der Selbstschutz gegen PHPs eigene Grenzen:
     * Läge `upload_max_filesize` unter 5 MB, käme die Datei mit
     * UPLOAD_ERR_INI_SIZE an und würde schon davor abgewiesen — der erste Teil
     * wäre dann aus dem falschen Grund grün. Kommt genau MAX_BYTES nicht durch,
     * wird dieser Test rot statt still zu lügen. (PhpBuiltInServer setzt die
     * Werte des offiziellen Images.)
     */
    public function testEineZuGrosseBilddateiWirdAbgelehnt(): void {
        $admin = $this->authenticatedClient();
        $pferd = $this->pferdAnlegen($admin, 'Uploadgroesse ' . uniqid());

        $png = $this->pngBytes();
        $zuGross = $png . str_repeat('A', \App\Service\HorseMedia::MAX_BYTES + 1 - strlen($png));
        $this->assertSame(\App\Service\HorseMedia::MAX_BYTES + 1, strlen($zuGross));

        $antwort = $this->medienUpload($admin, $pferd, 'gross.png', $zuGross);

        $this->assertSame(302, $antwort->statusCode,
            'Body: ' . substr($antwort->body, 0, 300));
        $this->assertSame(
            '/admin/horses/edit?id=' . $pferd . '&media=media_invalid',
            (string)$antwort->location()
        );
        $this->assertSame(0, $this->medienZeilen($pferd));

        // Genau MAX_BYTES muss durchgehen - sonst hat PHP schon davor verworfen.
        $genau = $png . str_repeat('A', \App\Service\HorseMedia::MAX_BYTES - strlen($png));
        $grenze = $this->medienUpload($admin, $pferd, 'grenze.png', $genau);
        $this->assertSame(
            '/admin/horses/edit?id=' . $pferd . '&media=media_added',
            (string)$grenze->location(),
            'Genau 5 MB muss die Anwendung annehmen. Kommt hier media_invalid, hat PHP die '
            . 'Datei selbst verworfen (upload_max_filesize/post_max_size) - dann hat der Fall '
            . 'darüber die 5-MB-Grenze der Anwendung nie erreicht.'
        );
        $this->assertSame(1, $this->medienZeilen($pferd));
    }

    public function testEinHochgeladenesBildWirdHauptbildUndIstAuslieferbar(): void {
        $admin = $this->authenticatedClient();
        $pferd = $this->pferdAnlegen($admin, 'Medienhengst ' . uniqid());
        $medium = $this->bildHochladen($admin, $pferd, 'Sommerweide');

        $this->assertGreaterThan(0, $medium);

        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT is_main, file_name FROM horse_media WHERE id = ?');
        $stmt->execute([$medium]);
        $zeile = $stmt->fetch();
        $this->assertSame(1, (int)$zeile['is_main'], 'Das erste Bild wird das Hauptbild.');

        $stmt = $db->prepare('SELECT image_url FROM horses WHERE id = ?');
        $stmt->execute([$pferd]);
        $this->assertSame($zeile['file_name'], $stmt->fetchColumn(), 'horses.image_url muss mitziehen.');

        // Die Datei kommt über die geschützte Route heraus, mit Bild-MIME.
        $antwort = $admin->get('/media/horse-media?id=' . $medium);
        $this->assertSame(200, $antwort->statusCode);
        $this->assertStringContainsString('image/png', (string)$antwort->header('Content-Type'));
    }

    /**
     * Die eigentliche Zusicherung: Das Medium eines UNVERÖFFENTLICHTEN
     * Pferdes ist für Gäste nicht vorhanden. Genau dieser Fall war beim
     * Addon-Vorgänger einmal offen, weil die Datei statisch auslieferbar war.
     */
    public function testMedienUnveroeffentlichterPferdeSindFuerGaesteNichtDa(): void {
        $admin = $this->authenticatedClient();
        $pferd = $this->pferdAnlegen($admin, 'Verborgen ' . uniqid(), false);
        $medium = $this->bildHochladen($admin, $pferd);

        $this->assertSame(200, $admin->get('/media/horse-media?id=' . $medium)->statusCode);

        $gast = $this->newClient();
        $this->assertSame(404, $gast->get('/media/horse-media?id=' . $medium)->statusCode);
    }

    public function testDasHauptbildLaesstSichWechselnUndZiehtImageUrlNach(): void {
        $admin = $this->authenticatedClient();
        $pferd = $this->pferdAnlegen($admin, 'Zweibild ' . uniqid());
        $this->bildHochladen($admin, $pferd);
        $zweites = $this->bildHochladen($admin, $pferd);

        $seite = $admin->get('/admin/horses/edit?id=' . $pferd);
        $antwort = $admin->post('/admin/horses/media/main', [
            'csrf_token' => $seite->formField('csrf_token') ?? '',
            'horse_id' => (string)$pferd,
            'media_id' => (string)$zweites,
        ]);
        $this->assertStringContainsString('media=media_main', (string)$antwort->location(), "Body: {$antwort->body}");

        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT h.image_url = m.file_name AS passt FROM horses h JOIN horse_media m ON m.id = ? WHERE h.id = ?'
        );
        $stmt->execute([$zweites, $pferd]);
        $this->assertSame(1, (int)$stmt->fetchColumn());
    }

    /**
     * Ein Medium eines FREMDEN Pferdes darf sich über die eigene
     * Pferdeseite nicht löschen lassen.
     */
    public function testEinFremdesMediumLaesstSichNichtUeberEinAnderesPferdLoeschen(): void {
        $admin = $this->authenticatedClient();
        $einmalig = uniqid();
        $meins = $this->pferdAnlegen($admin, 'Meins ' . $einmalig);
        $fremd = $this->pferdAnlegen($admin, 'Fremd ' . $einmalig);
        $fremdesMedium = $this->bildHochladen($admin, $fremd);

        $seite = $admin->get('/admin/horses/edit?id=' . $meins);
        $antwort = $admin->post('/admin/horses/media/delete', [
            'csrf_token' => $seite->formField('csrf_token') ?? '',
            'horse_id' => (string)$meins,
            'media_id' => (string)$fremdesMedium,
        ]);
        $this->assertStringContainsString('media=media_invalid', (string)$antwort->location());

        $stmt = Database::getInstance()->prepare('SELECT COUNT(*) FROM horse_media WHERE id = ?');
        $stmt->execute([$fremdesMedium]);
        $this->assertSame(1, (int)$stmt->fetchColumn(), 'Das fremde Medium muss es noch geben.');
    }

    public function testDieMedienpflegeIstCsrfGeschuetzt(): void {
        $admin = $this->authenticatedClient();
        $pferd = $this->pferdAnlegen($admin, 'CSRF ' . uniqid());

        $antwort = $admin->post('/admin/horses/media/add', [
            'csrf_token' => 'ungueltig',
            'horse_id' => (string)$pferd,
            'video_url' => 'https://vimeo.com/12345',
        ]);
        $this->assertSame(403, $antwort->statusCode);
    }

    /**
     * Ein Video-Link landet in einem href auf der öffentlichen Seite -
     * `javascript:` gehört dort nicht hin.
     */
    public function testEinUnbrauchbarerVideoLinkWirdAbgelehnt(): void {
        $admin = $this->authenticatedClient();
        $pferd = $this->pferdAnlegen($admin, 'Videolink ' . uniqid());

        $seite = $admin->get('/admin/horses/edit?id=' . $pferd);
        $antwort = $admin->post('/admin/horses/media/add', [
            'csrf_token' => $seite->formField('csrf_token') ?? '',
            'horse_id' => (string)$pferd,
            'video_url' => 'javascript:alert(1)',
        ]);

        $this->assertStringContainsString('media=media_invalid', (string)$antwort->location());
        $stmt = Database::getInstance()->prepare('SELECT COUNT(*) FROM horse_media WHERE horse_id = ?');
        $stmt->execute([$pferd]);
        $this->assertSame(0, (int)$stmt->fetchColumn());
    }

    /**
     * Auf der öffentlichen Detailseite erscheinen die WEITEREN Medien - das
     * Hauptbild steht schon oben und wäre sonst zweimal auf einer Seite.
     */
    public function testDieDetailseiteZeigtDieWeiterenMedienOhneDasHauptbild(): void {
        $admin = $this->authenticatedClient();
        $pferd = $this->pferdAnlegen($admin, 'Galeriehengst ' . uniqid());
        $haupt = $this->bildHochladen($admin, $pferd);
        $zweites = $this->bildHochladen($admin, $pferd, 'Im Winter');

        $gast = $this->newClient();
        $seite = $gast->get('/horse?id=' . $pferd);

        $this->assertSame(200, $seite->statusCode);
        $this->assertStringContainsString('/media/horse-media?id=' . $zweites, $seite->body);
        $this->assertStringNotContainsString(
            '/media/horse-media?id=' . $haupt,
            $seite->body,
            'Das Hauptbild steht oben und gehoert nicht noch einmal in die Galerie.'
        );
        $this->assertStringContainsString('Im Winter', $seite->body);
        $this->assertStringContainsString('/js/horse-gallery.js', $seite->body);
    }

    /**
     * Ohne Medien wird das Lightbox-Skript nicht geladen - es gäbe nichts
     * anzuklicken.
     */
    public function testOhneMedienKeinGalerieSkript(): void {
        $admin = $this->authenticatedClient();
        $pferd = $this->pferdAnlegen($admin, 'Bildlos ' . uniqid());

        $seite = $this->newClient()->get('/horse?id=' . $pferd);

        $this->assertSame(200, $seite->statusCode);
        $this->assertStringNotContainsString('/js/horse-gallery.js', $seite->body);
    }
}
