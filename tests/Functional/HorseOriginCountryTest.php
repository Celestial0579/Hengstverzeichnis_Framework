<?php
// tests/Functional/HorseOriginCountryTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Herkunftsland ohne bekannte Person (#294).
 *
 * Das Altsystem kannte die Aussage „der Züchter ist nicht bekannt, aber er kam
 * aus Norwegen". Im Datenmodell gab es dafür kein Feld: Eine
 * horse_persons-Zeile brauchte eine Person ODER eine Deckstation. Der
 * Migrationsbestand hat sich mit **Platzhalter-Personen** beholfen
 * („Nichtmitglied NO", „Dänemark", „Ausland") - in der Dev-Instanz 171 von 672
 * Züchter-Zuordnungen.
 *
 * Das ist mehr als ein Schönheitsfehler: Die Kontakttabelle (bis v0.7
 * `persons`, seit #336 `contacts`) enthält personenbezogene Daten und hängt an
 * DSGVO-Anonymisierung und Papierkorb; veröffentlichte Platzhalter erscheinen
 * im Katalog als echter Züchtername.
 *
 * Die Formularfelder heißen seit #336 `contact_id` und `station_contact_id`
 * wie die Spalten - dieser Test schickt die Namen, die das Formular auch
 * wirklich rendert (src/Views/admin_horse_form.php).
 */
class HorseOriginCountryTest extends FunctionalTestCase {

    public function testOriginCountryAloneKeepsTheRowAndIsShownWithoutAPerson(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $horseName = "Herkunft {$unique}";

        // Zeile OHNE Person und OHNE Deckstation - vor #294 wäre sie beim
        // Speichern ersatzlos verworfen worden.
        $form = $admin->get('/admin/horses/create');
        $admin->post('/admin/horses/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $horseName,
            'status' => 'active',
            'is_published' => '1',
            'persons' => [
                ['contact_id' => '', 'role' => 'breeder', 'station_contact_id' => '', 'origin_country' => 'NO'],
            ],
        ]);

        $stmt = $db->prepare("SELECT id FROM horses WHERE name = ?");
        $stmt->execute([$horseName]);
        $horseId = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $horseId);

        $stmt = $db->prepare("SELECT contact_id, role, origin_country FROM horse_persons WHERE horse_id = ?");
        $stmt->execute([$horseId]);
        $zeilen = $stmt->fetchAll();
        $this->assertCount(1, $zeilen, 'Die Herkunftszeile muss gespeichert werden (#294)');
        $this->assertNull($zeilen[0]['contact_id'], 'Und zwar OHNE Platzhalter-Person');
        $this->assertSame('NO', $zeilen[0]['origin_country']);
        $this->assertSame('breeder', $zeilen[0]['role']);

        // Es darf dabei keine Person entstanden sein - genau darum geht es.
        $this->assertSame(
            0,
            (int)$db->query("SELECT COUNT(*) FROM contacts WHERE name IN ('NO', 'Norwegen', 'Nichtmitglied NO')")->fetchColumn(),
            'Für eine Herkunftsangabe darf kein Kontaktdatensatz angelegt werden'
        );

        // Öffentlich erscheint die Zeile, aber ohne erfundenen Namen.
        $detail = $this->newClient()->get('/horse?id=' . $horseId);
        $this->assertSame(200, $detail->statusCode);
        $this->assertStringContainsString('unbekannt', $detail->body, 'Die Zeile muss sichtbar sein');
        $this->assertStringContainsString('NO', $detail->body);

        // Und sie überlebt ein erneutes Speichern (Zusammenspiel mit #295).
        $editPage = $admin->get('/admin/horses/edit?id=' . $horseId);
        $this->assertStringContainsString('origin_country', $editPage->body, 'Das Formular muss das Feld anbieten');
        $admin->post('/admin/horses/update', [
            'csrf_token' => $editPage->formField('csrf_token') ?? '',
            'id' => (string)$horseId,
            'name' => $horseName,
            'status' => 'active',
            'persons' => [
                ['contact_id' => '', 'role' => 'breeder', 'station_contact_id' => '', 'origin_country' => 'NO'],
            ],
        ]);
        $stmt->execute([$horseId]);
        $this->assertCount(1, $stmt->fetchAll(), 'Die Herkunftszeile muss ein weiteres Speichern überstehen');
    }

    /**
     * Gegenprobe: Eine Zeile ganz ohne Angabe bleibt ungültig. Die dritte
     * Alternative erweitert die Regel, sie schafft sie nicht ab - sonst
     * entstünden bei jedem Speichern leere Zeilen.
     */
    public function testCompletelyEmptyRowIsStillDiscarded(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $horseName = "Leerzeile {$unique}";

        $form = $admin->get('/admin/horses/create');
        $admin->post('/admin/horses/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $horseName,
            'status' => 'active',
            'persons' => [
                ['contact_id' => '', 'role' => 'owner', 'station_contact_id' => '', 'origin_country' => ''],
            ],
        ]);

        $stmt = $db->prepare("SELECT id FROM horses WHERE name = ?");
        $stmt->execute([$horseName]);
        $horseId = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM horse_persons WHERE horse_id = ?");
        $stmt->execute([$horseId]);
        $this->assertSame(0, (int)$stmt->fetchColumn(), 'Eine Zeile ohne jede Angabe bleibt ungültig');
    }
}
