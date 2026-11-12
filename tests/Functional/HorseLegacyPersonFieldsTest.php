<?php
// tests/Functional/HorseLegacyPersonFieldsTest.php

namespace Tests\Functional;

use App\Database;
use Tests\Support\HttpClient;

/**
 * Die alten Formularfelder `person_id` und `breeding_station_id` in den
 * Personenzeilen eines Pferds werden nicht mehr angenommen (#347, v0.9.0) -
 * und das ist hier festgehalten (#511).
 *
 * WARUM EIN EIGENER TEST DAFÜR. Die Namen kommen in der Suite dutzendfach vor,
 * aber immer mit Leerstring (Ballast aus der Zeit vor #336) oder auf der
 * obersten Ebene des Formulars (`horses.breeding_station_id`, ein anderes,
 * weiterhin gültiges Feld). Kein Test schickte einen der alten Namen mit einem
 * ECHTEN Wert. Käme der Rückfall zurück - als Revert oder als gut gemeinte
 * Kompatibilitätsbrücke -, bliebe alles grün.
 *
 * WARUM DAS MEHR IST ALS EINE UNERWÜNSCHTE ANNAHME. `person_id`-Werte aus der
 * 0.7-Linie stammen aus dem alten ID-Raum von `persons`; mit #336 wurden sie
 * auf `contacts.id` umgeschlüsselt (dafür gibt es `contact_id_map`). Ein
 * Skript, das heute noch `person_id=417` schickt, meint Person 417 von
 * damals. Nähme der Kern den Wert an, verknüpfte er Kontakt 417 von heute -
 * einen anderen Menschen, als Züchter oder Besitzer eines fremden Pferds. Die
 * Fremdschlüsselprüfung hilft nicht: Die ID ist gültig, sie ist nur die
 * falsche.
 *
 * Jede Zusicherung hat ihre Gegenprobe mit den neuen Namen. Ohne sie wäre der
 * Test auch dann grün, wenn das Formular gar keine Personenzeilen mehr
 * verarbeitete.
 */
class HorseLegacyPersonFieldsTest extends FunctionalTestCase {

    /** @var list<int> */
    private array $kontaktIds = [];

    /** @var list<int> */
    private array $pferdIds = [];

    protected function tearDown(): void {
        $db = Database::getInstance();
        foreach ($this->pferdIds as $id) {
            // horse_persons geht über den Fremdschlüssel mit.
            $db->prepare('DELETE FROM horses WHERE id = ?')->execute([$id]);
        }
        foreach ($this->kontaktIds as $id) {
            $db->prepare('DELETE FROM contacts WHERE id = ?')->execute([$id]);
        }
        $this->pferdIds = [];
        $this->kontaktIds = [];
        parent::tearDown();
    }

    private function kontakt(string $name): int {
        $db = Database::getInstance();
        $db->prepare('INSERT INTO contacts (name, is_published) VALUES (?, 1)')->execute([$name]);
        $id = (int)$db->lastInsertId();
        $this->kontaktIds[] = $id;

        return $id;
    }

    /**
     * Legt ein Pferd über das echte Formular an.
     *
     * @param array<int, array<string, string>> $personen Der persons-Block
     */
    private function pferdAnlegen(HttpClient $admin, string $name, array $personen): int {
        $formular = $admin->get('/admin/horses/create');
        $antwort = $admin->post('/admin/horses/store', [
            'csrf_token' => $formular->formField('csrf_token') ?? '',
            'name' => $name,
            'status' => 'active',
            'persons' => $personen,
        ]);
        $this->assertStringStartsWith('/admin/horses', (string)$antwort->location(), "Anlegen fehlgeschlagen, Body: {$antwort->body}");

        $stmt = Database::getInstance()->prepare('SELECT id FROM horses WHERE name = ?');
        $stmt->execute([$name]);
        $id = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $id, "Pferd '{$name}' nicht gefunden.");
        $this->pferdIds[] = $id;

        return $id;
    }

    /**
     * Speichert ein vorhandenes Pferd über das echte Formular.
     *
     * @param array<int, array<string, string>> $personen Der persons-Block
     */
    private function pferdSpeichern(HttpClient $admin, int $id, string $name, array $personen): void {
        $formular = $admin->get('/admin/horses/edit?id=' . $id);
        $antwort = $admin->post('/admin/horses/update', [
            'csrf_token' => $formular->formField('csrf_token') ?? '',
            'id' => (string)$id,
            'name' => $name,
            'status' => 'active',
            'persons' => $personen,
        ]);
        $this->assertSame('/admin/horses?success=updated', $antwort->location(), "Speichern fehlgeschlagen, Body: {$antwort->body}");
    }

    /**
     * Die Zuordnungen eines Pferds als [Kontakt, Station, Rolle], in der
     * Reihenfolge der Zeilen.
     *
     * @return list<array{0: ?int, 1: ?int, 2: string}>
     */
    private function zuordnungen(int $pferdId): array {
        $stmt = Database::getInstance()->prepare(
            'SELECT contact_id, station_contact_id, role FROM horse_persons WHERE horse_id = ? ORDER BY id'
        );
        $stmt->execute([$pferdId]);

        return array_map(
            static fn(array $z): array => [
                $z['contact_id'] === null ? null : (int)$z['contact_id'],
                $z['station_contact_id'] === null ? null : (int)$z['station_contact_id'],
                (string)$z['role'],
            ],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /** Die Station, die das Pferd selbst als aktuelle Deckstation führt (Spiegel aus den Zeilen). */
    private function gespiegelteStation(int $pferdId): ?int {
        $stmt = Database::getInstance()->prepare('SELECT breeding_station_id FROM horses WHERE id = ?');
        $stmt->execute([$pferdId]);
        $wert = $stmt->fetchColumn();

        return $wert === null || $wert === false ? null : (int)$wert;
    }

    public function testBeimAnlegenVerknuepfenDieAltenNamenNichts(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $person = $this->kontakt("Altfeld Person {$unique}");
        $station = $this->kontakt("Altfeld Station {$unique}");

        // Alte Namen, ECHTE Werte - beide Kontakte gibt es, und beide dürften
        // im Formular gewählt werden.
        $alt = $this->pferdAnlegen($admin, "Altfeld Pferd {$unique}", [[
            'person_id' => (string)$person,
            'role' => 'owner',
            'breeding_station_id' => (string)$station,
        ]]);

        // Die Zeile trägt ohne die beiden Werte nichts und entfällt ganz.
        $this->assertSame(
            [],
            $this->zuordnungen($alt),
            'person_id und breeding_station_id in einer Personenzeile dürfen nichts verknüpfen.'
        );
        $this->assertNull($this->gespiegelteStation($alt), 'Auch der Spiegel am Pferd bleibt leer.');

        // Gegenprobe: dieselben Werte unter den neuen Namen ergeben genau die
        // eine Zuordnung, die oben nicht entstehen durfte.
        $neu = $this->pferdAnlegen($admin, "Neufeld Pferd {$unique}", [[
            'contact_id' => (string)$person,
            'role' => 'owner',
            'station_contact_id' => (string)$station,
        ]]);

        $this->assertSame([[$person, $station, 'owner']], $this->zuordnungen($neu));
        $this->assertSame($station, $this->gespiegelteStation($neu));
    }

    public function testBeimBearbeitenErsetzenDieAltenNamenNichts(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $person = $this->kontakt("Bestand Person {$unique}");
        $station = $this->kontakt("Bestand Station {$unique}");
        $fremdePerson = $this->kontakt("Fremde Person {$unique}");
        $fremdeStation = $this->kontakt("Fremde Station {$unique}");
        $name = "Bestandspferd {$unique}";

        $pferd = $this->pferdAnlegen($admin, $name, [[
            'contact_id' => (string)$person,
            'role' => 'owner',
            'station_contact_id' => (string)$station,
        ]]);
        $this->assertSame([[$person, $station, 'owner']], $this->zuordnungen($pferd));

        // Neue UND alte Namen in derselben Zeile, mit verschiedenen Werten:
        // Es gelten die neuen. Genau diese Mischung schickt ein Skript, das
        // nur zur Hälfte umgestellt wurde.
        $this->pferdSpeichern($admin, $pferd, $name, [[
            'contact_id' => (string)$person,
            'person_id' => (string)$fremdePerson,
            'role' => 'owner',
            'station_contact_id' => (string)$station,
            'breeding_station_id' => (string)$fremdeStation,
        ]]);
        $this->assertSame(
            [[$person, $station, 'owner']],
            $this->zuordnungen($pferd),
            'Die alten Namen dürfen die neuen nicht überstimmen.'
        );

        // Nur die alten Namen: Die Zeile trägt damit nichts mehr und entfällt -
        // auf keinen Fall zeigt sie auf die fremden Kontakte.
        $this->pferdSpeichern($admin, $pferd, $name, [[
            'person_id' => (string)$fremdePerson,
            'role' => 'owner',
            'breeding_station_id' => (string)$fremdeStation,
        ]]);
        $this->assertSame([], $this->zuordnungen($pferd));
        $this->assertNotSame($fremdeStation, $this->gespiegelteStation($pferd));

        // Gegenprobe: Unter den neuen Namen lässt sich genau dieser Wechsel
        // speichern.
        $this->pferdSpeichern($admin, $pferd, $name, [[
            'contact_id' => (string)$fremdePerson,
            'role' => 'owner',
            'station_contact_id' => (string)$fremdeStation,
        ]]);
        $this->assertSame([[$fremdePerson, $fremdeStation, 'owner']], $this->zuordnungen($pferd));
        $this->assertSame($fremdeStation, $this->gespiegelteStation($pferd));
    }
}
