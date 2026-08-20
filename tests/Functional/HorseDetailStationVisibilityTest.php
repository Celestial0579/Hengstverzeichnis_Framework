<?php
// tests/Functional/HorseDetailStationVisibilityTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Deckstationen im Personenblock von /horse (#316).
 *
 * horseDetail() nullt die Stationsfelder des Pferds, wenn der Gast das Recht
 * auf Kontakte nicht besitzt (#122) - und unterdrückt den denormalisierten
 * Freitext `horses.breeding_station`, sobald die Station nicht öffentlich
 * sichtbar ist (#151). Die Zuordnungsabfrage darunter holt `bs.name`/`bs.id`
 * jedoch ein ZWEITES Mal und wurde von beidem nicht erfasst: Der Block
 * „Zucht & Personen" nannte die Station weiterhin, samt Link auf die
 * Kontaktseite, die korrekt mit 404 antwortete.
 *
 * Zwei Wege führen dorthin, und beide werden hier festgehalten:
 *   (a) Der Betreiber entzieht der Gast-Gruppe das Kontakt-Leserecht.
 *   (b) Die Station wird depubliziert - dann fällt `station_name` durch den
 *       gefilterten JOIN weg, und die Anzeige griff auf
 *       `horse_persons.breeding_station_text` derselben Zeile zurück, wo bei
 *       Import und Formular derselbe Name noch einmal steht.
 *
 * Seit #336 ist die Deckstation ein Kontakt wie jeder andere: Sie steht in
 * `contacts`, hängt an `horse_persons.station_contact_id` und wird über
 * `/kontakt?id=` verlinkt. Das Recht heißt `contacts` statt
 * `breeding_stations` - und damit hängen Personenseite und Stationsseite
 * jetzt am SELBEN Recht. Die Zusicherung dieses Tests ändert das nicht: Eine
 * unveröffentlichte Deckstation darf auf der Pferdeseite weder genannt noch
 * verlinkt werden.
 */
class HorseDetailStationVisibilityTest extends FunctionalTestCase {

    /**
     * Die Rechte der Gast-Gruppe, wie database/schema.sql sie seedet
     * (horses.view + contacts.view).
     *
     * Bewusst hier statt über FunctionalTestCase::GUEST_DEFAULT_PERMISSIONS:
     * Diese Konstante nennt noch die vor #336 getrennten Module `persons` und
     * `breeding_stations`. Setzte dieser Test sie, verlöre die Gast-Gruppe
     * `contacts.view` - die Vorbedingung unten wäre nicht mehr erfüllt, und
     * schlimmer: Die Gruppe bliebe für alle folgenden Testklassen der Suite so
     * stehen. (Das Nachziehen der Konstante gehört in die Basisklasse, nicht
     * hierher.)
     *
     * @var array<string, array<int, string>>
     */
    private const GAST_RECHTE = [
        'horses' => ['view'],
        'contacts' => ['view'],
    ];

    /** @var array<int, int> */
    private array $horseIds = [];
    /** @var array<int, int> */
    private array $stationIds = [];

    protected function tearDown(): void {
        $db = Database::getInstance();
        foreach ($this->horseIds as $id) {
            $db->prepare("DELETE FROM horses WHERE id = ?")->execute([$id]);
        }
        foreach ($this->stationIds as $id) {
            $db->prepare("DELETE FROM contacts WHERE id = ?")->execute([$id]);
        }
        $this->horseIds = [];
        $this->stationIds = [];
        parent::tearDown();
    }

    public function testStationInThePersonBlockFollowsThePermissionAndThePublishFlag(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $gastGruppe = $this->findBuiltinGroupId($admin, 'Gast');

        $stationsName = "Musterhof {$unique}";
        $db->prepare("INSERT INTO contacts (name, is_published, created_at) VALUES (?, 1, NOW())")
           ->execute([$stationsName]);
        $stationId = (int)$db->lastInsertId();
        $this->stationIds[] = $stationId;

        $db->prepare("INSERT INTO horses (name, sex, is_published, created_at) VALUES (?, 'stallion', 1, NOW())")
           ->execute(["Stationsprobe {$unique}"]);
        $horseId = (int)$db->lastInsertId();
        $this->horseIds[] = $horseId;

        // Die Zuordnungszeile trägt BEIDES: die Stations-ID und den Namen als
        // Freitext. Genau so entsteht sie aus dem CSV-/v2-Import und aus dem
        // Admin-Formular, das beide Felder nebeneinander rendert.
        $db->prepare(
            "INSERT INTO horse_persons (horse_id, role, station_contact_id, breeding_station_text)
             VALUES (?, 'breeder', ?, ?)"
        )->execute([$horseId, $stationId, $stationsName]);

        $gast = $this->newClient();
        $pfad = '/horse?id=' . $horseId;

        try {
            // Ausgangslage: Mit dem Recht und veröffentlichter Station steht
            // der Name da. Ohne diesen Schritt bewiesen die beiden Fälle
            // darunter nichts - ein Name, der nie erscheint, "verschwindet"
            // auch ohne jeden Schutz.
            $this->setGroupPermissions($admin, $gastGruppe, self::GAST_RECHTE);
            $seite = $gast->get($pfad);
            $this->assertSame(200, $seite->statusCode);
            $this->assertStringContainsString(
                $stationsName,
                $seite->body,
                'Vorbedingung: Mit contacts.view und veröffentlichter Station gehört der Name auf die Seite'
            );

            // (a) Recht entzogen - der Name darf nirgends mehr stehen, auch
            //     nicht über den Freitext derselben Zeile.
            //
            //     Seit #336 ist das EIN Recht für Personen und Deckstationen;
            //     ein "nur persons.view"-Zwischenzustand, den die frühere
            //     Fassung hier setzte, existiert nicht mehr. Der geprüfte
            //     Fall bleibt derselbe: Gast ohne Kontakt-Leserecht.
            $this->setGroupPermissions($admin, $gastGruppe, [
                'horses' => ['view'],
            ]);
            $ohneRecht = $gast->get($pfad);
            $this->assertSame(200, $ohneRecht->statusCode, 'Die Pferdeseite selbst bleibt erreichbar');
            $this->assertStringNotContainsString(
                $stationsName,
                $ohneRecht->body,
                'Ohne contacts.view darf die Station im Personenblock nicht erscheinen'
            );
            $this->assertStringNotContainsString(
                '/kontakt?id=' . $stationId,
                $ohneRecht->body,
                'Und erst recht kein Link auf eine Seite, die dem Gast 404 liefert'
            );

            // (b) Recht wieder da, aber die Station ist depubliziert. Der JOIN
            //     blendet sie aus - der Freitext derselben Zeile darf sie nicht
            //     hintenherum wieder hereinholen.
            $this->setGroupPermissions($admin, $gastGruppe, self::GAST_RECHTE);
            $db->prepare("UPDATE contacts SET is_published = 0 WHERE id = ?")->execute([$stationId]);

            $depubliziert = $gast->get($pfad);
            $this->assertSame(200, $depubliziert->statusCode);
            $this->assertStringNotContainsString(
                $stationsName,
                $depubliziert->body,
                'Eine depublizierte Station darf nicht über breeding_station_text weiterleben'
            );
            // Der Link gehört ausdrücklich dazu: /kontakt?id= liefert für einen
            // unveröffentlichten Kontakt 404, ein Verweis darauf wäre also
            // zugleich nutzlos und ein Hinweis darauf, dass es den Datensatz
            // gibt.
            $this->assertStringNotContainsString(
                '/kontakt?id=' . $stationId,
                $depubliziert->body,
                'Auch der Verweis auf die depublizierte Station muss verschwinden'
            );
        } finally {
            // Die Gast-Gruppe ist geteilter Zustand der ganzen Suite - sie muss
            // auch nach einem Fehlschlag wieder auf den Vorgaben stehen.
            $this->setGroupPermissions($admin, $gastGruppe, self::GAST_RECHTE);
        }
    }

    /**
     * Gegenprobe zur Abgrenzung: Freitext OHNE Stations-Datensatz benennt
     * keinen verborgenen Datensatz und bleibt deshalb stehen (#151). Ohne
     * diesen Fall wäre die naheliegende „Vereinfachung" - jeden Freitext
     * unterdrücken - nicht von der richtigen Lösung zu unterscheiden, und der
     * gesamte Importbestand verlöre seine Stationsangabe.
     */
    public function testFreeTextWithoutAStationRecordSurvives(): void {
        $db = Database::getInstance();
        $this->authenticatedClient();
        $unique = uniqid();

        $db->prepare("INSERT INTO horses (name, sex, is_published, created_at) VALUES (?, 'stallion', 1, NOW())")
           ->execute(["Freitextprobe {$unique}"]);
        $horseId = (int)$db->lastInsertId();
        $this->horseIds[] = $horseId;

        $freitext = "Hof ohne Datensatz {$unique}";
        $db->prepare(
            "INSERT INTO horse_persons (horse_id, role, station_contact_id, breeding_station_text)
             VALUES (?, 'breeder', NULL, ?)"
        )->execute([$horseId, $freitext]);

        $seite = $this->newClient()->get('/horse?id=' . $horseId);
        $this->assertSame(200, $seite->statusCode);
        $this->assertStringContainsString(
            $freitext,
            $seite->body,
            'Freitext ohne Stations-ID verbirgt nichts und muss erhalten bleiben'
        );
    }
}
