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
            $stmt = $db->prepare('DELETE FROM horses WHERE id = ?');
            foreach ($this->aufraeumen as $id) {
                $stmt->execute([$id]);
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
