<?php
// tests/Functional/HorseSearchEndpointTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Der gemeinsame Suchendpunkt für Pferde (#341, Addons#125).
 *
 * WARUM ES DIESEN TEST GIBT. Er entstand als Nachtrag, und zwar aus einem
 * konkreten Anlass: Der Controller und das JavaScript lagen fertig im Repo,
 * die Route in `public/index.php` fehlte - und niemandem fiel es auf. Ein
 * Addon-Strang stiess erst beim Umstellen darauf und mass einen 404. Ein
 * Endpunkt ohne Test kann unregistriert sein, ohne dass irgendetwas rot wird;
 * die Klassen existieren ja, `php -l` ist zufrieden, und die Unit-Suite fasst
 * Routen nicht an.
 *
 * Geprüft wird deshalb zuerst das Banalste: dass es die Route gibt.
 *
 * Danach das, was sieben Addon-Kopien je einzeln falsch machen konnten:
 * die Rechteprüfung, der Deckel gegen den Bestandsabzug, das Maskieren der
 * SQL-Platzhalter und die Beschränkung auf die zwei Felder, die eine
 * Auswahlliste braucht.
 */
class HorseSearchEndpointTest extends FunctionalTestCase {

    private const PFAD = '/admin/horses/search';

    /** @var array<int, int> */
    private array $angelegt = [];

    protected function tearDown(): void {
        $db = Database::getInstance();
        foreach ($this->angelegt as $id) {
            $db->prepare('DELETE FROM horses WHERE id = ?')->execute([$id]);
        }
        $this->angelegt = [];
        parent::tearDown();
    }

    public function testDieRouteIstUeberhauptRegistriert(): void {
        $admin = $this->authenticatedClient();

        $antwort = $admin->get(self::PFAD . '?q=zz');

        $this->assertNotSame(
            404,
            $antwort->statusCode,
            'Der Suchendpunkt ist nicht registriert. Genau das ist beim Bau von #341 passiert: '
            . 'Controller und JavaScript lagen im Repo, die Zeile in public/index.php fehlte.'
        );
        $this->assertSame(200, $antwort->statusCode);
    }

    public function testLiefertNurKennungUndBeschriftung(): void {
        $this->seedHorse('Rogar S', 'DE-TEST-1', 2010, 'stallion', 'falb');
        $admin = $this->authenticatedClient();

        $antwort = $admin->get(self::PFAD . '?q=Rogar');
        $treffer = json_decode($antwort->body, true);

        $this->assertIsArray($treffer);
        $this->assertNotEmpty($treffer, 'Der angelegte Hengst muss gefunden werden.');
        $this->assertSame(
            ['id', 'label'],
            array_keys($treffer[0]),
            'Ein Suchendpunkt ist eine bequeme Stelle, um an Daten zu kommen - was hier nicht '
            . 'ausgeliefert wird, kann auch nicht abfliessen.'
        );
        $this->assertStringContainsString('Rogar S', $treffer[0]['label']);
        $this->assertStringContainsString('DE-TEST-1', $treffer[0]['label'], 'Namensgleichheit ist real - die Nummer unterscheidet.');
    }

    /**
     * Der Deckel ist der Schutz: Ohne ihn wäre `?q=a` ein Ein-Klick-Vollexport
     * des Pferdebestands über einen Endpunkt, der für eine Auswahlliste
     * gedacht ist.
     */
    public function testKurzeAnfragenLiefernNichts(): void {
        $this->seedHorse('Aaa Test', 'DE-TEST-2', 2011, 'mare', 'braun');
        $admin = $this->authenticatedClient();

        $antwort = $admin->get(self::PFAD . '?q=A');

        $this->assertSame([], json_decode($antwort->body, true), 'Ein einzelner Buchstabe ist keine Suche.');
    }

    /**
     * `%` und `_` sind in LIKE Platzhalter. Fünf der sieben Addon-Kopien
     * maskierten sie, zwei nicht - beim Zusammenlegen darf die strengere
     * Fassung gewinnen, nicht die nachlässige.
     */
    public function testPlatzhalterWerdenMaskiert(): void {
        $this->seedHorse('Maskentest', 'DE-TEST-3', 2012, 'mare', 'rappe');
        $admin = $this->authenticatedClient();

        $antwort = $admin->get(self::PFAD . '?q=' . urlencode('%%'));

        $this->assertSame(
            [],
            json_decode($antwort->body, true),
            'Unmaskiert wäre "%%" eine Suche nach allem - der Deckel fängt das ab, die Anfrage liefe trotzdem über den Gesamtbestand.'
        );
    }

    /**
     * Der Filter, den die Addons tatsächlich brauchen (#54): Der
     * Verpaarungsrechner bietet als Vater nur Hengste an. Ohne ihn schlug der
     * gemeinsame Endpunkt zu einer Mutter einen Hengst vor.
     */
    public function testGeschlechtsfilterGreift(): void {
        $this->seedHorse('Filterhengst', 'DE-TEST-4', 2013, 'stallion', 'falb');
        $this->seedHorse('Filterstute', 'DE-TEST-5', 2014, 'mare', 'falb');
        $admin = $this->authenticatedClient();

        $nurStuten = json_decode($admin->get(self::PFAD . '?q=Filter&geschlecht=mare')->body, true);
        $namen = array_column($nurStuten, 'label');

        $this->assertNotEmpty($namen);
        foreach ($namen as $name) {
            $this->assertStringNotContainsString(
                'Filterhengst',
                $name,
                'Ein Hengst darf nicht als Mutter vorgeschlagen werden.'
            );
        }
    }

    public function testOhneAnmeldungKeineTreffer(): void {
        $this->seedHorse('Geheimpferd', 'DE-TEST-6', 2015, 'mare', 'braun');

        $antwort = $this->newClient()->get(self::PFAD . '?q=Geheim');

        $this->assertNotSame(
            200,
            $antwort->statusCode,
            'Der Endpunkt liegt hinter derselben Sitzungsprüfung wie der übrige Adminbereich.'
        );
        $this->assertStringNotContainsString('Geheimpferd', $antwort->body);
    }

    private function seedHorse(string $name, string $ueln, int $jahr, string $sex, string $farbe): int {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO horses (name, ueln, birth_year, sex, color, status, is_published)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([$name, $ueln, $jahr, $sex, $farbe, 'active']);
        $id = (int)$db->lastInsertId();
        $this->angelegt[] = $id;
        return $id;
    }
}
