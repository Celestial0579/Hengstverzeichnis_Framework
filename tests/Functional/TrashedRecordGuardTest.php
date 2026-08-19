<?php
// tests/Functional/TrashedRecordGuardTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Papierkorb-Datensätze lassen sich nicht mehr überschreiben (#296).
 *
 * Eine in den Papierkorb gelegte Person blieb über die direkte URL
 * `/admin/persons/edit?id=…` bearbeitbar: Sie blieb gelöscht, bekam aber neue
 * Werte - und die Oberfläche wies an keiner Stelle darauf hin. Praktisch
 * passiert das über ein Lesezeichen oder einen alten Link.
 *
 * Der naheliegende Fix wäre falsch. `AND deleted_at IS NULL` schon in der
 * SELECT-Abfrage von edit() würde den DSGVO-Auskunftsweg kappen:
 * `admin_gdpr.php` verlinkt genau auf diese Route, und die Suche dort nimmt
 * weich gelöschte Treffer ausdrücklich mit (siehe den Kommentar im
 * GdprController - wer Löschung verlangt, hat Anspruch auch auf diese Daten).
 * Wer den Datensatz nicht mehr öffnen kann, kann auch nicht prüfen, was noch
 * drinsteht.
 *
 * Deshalb die Trennung, die dieser Test festhält: **anzeigen ja, speichern
 * nein**. Beide Hälften werden geprüft - die zweite ist die eigentliche
 * Korrektur, die erste verhindert, dass jemand sie später "vereinfacht".
 */
class TrashedRecordGuardTest extends FunctionalTestCase {

    public function testTrashedPersonStaysReadableButCannotBeSaved(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $name = "Papierkorbprobe {$unique}";

        $form = $admin->get('/admin/persons/create');
        $admin->post('/admin/persons/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $name,
            'city' => 'Ursprungsstadt',
        ]);
        $stmt = $db->prepare("SELECT id FROM persons WHERE name = ?");
        $stmt->execute([$name]);
        $personId = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $personId);

        // In den Papierkorb legen (weich löschen).
        $db->prepare("UPDATE persons SET deleted_at = NOW() WHERE id = ?")->execute([$personId]);

        // 1. Anzeigen bleibt möglich - das ist der DSGVO-Weg.
        $editPage = $admin->get('/admin/persons/edit?id=' . $personId);
        $this->assertSame(200, $editPage->statusCode, 'Der DSGVO-Auskunftsweg verlinkt hierher und muss offen bleiben');
        $this->assertStringContainsString($name, $editPage->body);
        $this->assertStringContainsString(
            'Papierkorb',
            $editPage->body,
            'Der Zustand muss sichtbar sein - genau daran fehlte es'
        );

        // 2. Speichern wird abgelehnt, und der Datensatz bleibt unverändert.
        $response = $admin->post('/admin/persons/update', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'id' => (string)$personId,
            'name' => 'Heimlich umbenannt',
            'city' => 'Fremdstadt',
        ]);
        $this->assertSame('/admin/persons?error=deleted', $response->location(), 'Der Schreibversuch darf nicht als Erfolg gemeldet werden');

        $stmt = $db->prepare("SELECT name, city, deleted_at FROM persons WHERE id = ?");
        $stmt->execute([$personId]);
        $row = $stmt->fetch();
        $this->assertSame($name, $row['name'], 'Der Name darf sich nicht geändert haben');
        $this->assertSame('Ursprungsstadt', $row['city']);
        $this->assertNotNull($row['deleted_at'], 'Und gelöscht bleibt gelöscht');

        // 3. Nach dem Wiederherstellen greift der Schutz nicht mehr.
        $db->prepare("UPDATE persons SET deleted_at = NULL WHERE id = ?")->execute([$personId]);
        $editPage = $admin->get('/admin/persons/edit?id=' . $personId);
        $response = $admin->post('/admin/persons/update', [
            'csrf_token' => $editPage->formField('csrf_token') ?? '',
            'id' => (string)$personId,
            'name' => $name,
            'city' => 'Neustadt',
        ]);
        $this->assertSame('/admin/persons?success=updated', $response->location());
        $stmt->execute([$personId]);
        $this->assertSame('Neustadt', $stmt->fetch()['city']);
    }

    /**
     * Deckstationen haben dasselbe Muster und damit denselben Schutz.
     */
    public function testTrashedBreedingStationCannotBeSaved(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $name = "Station Papierkorb {$unique}";

        $form = $admin->get('/admin/breeding-stations/create');
        $admin->post('/admin/breeding-stations/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $name,
            'city' => 'Ursprungsstadt',
        ]);
        $stmt = $db->prepare("SELECT id FROM breeding_stations WHERE name = ?");
        $stmt->execute([$name]);
        $stationId = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $stationId);

        $db->prepare("UPDATE breeding_stations SET deleted_at = NOW() WHERE id = ?")->execute([$stationId]);

        $admin->post('/admin/breeding-stations/update', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'id' => (string)$stationId,
            'name' => 'Heimlich umbenannt',
            'city' => 'Fremdstadt',
        ]);

        $stmt = $db->prepare("SELECT name, city FROM breeding_stations WHERE id = ?");
        $stmt->execute([$stationId]);
        $row = $stmt->fetch();
        $this->assertSame($name, $row['name'], 'Auch eine gelöschte Station darf nicht überschrieben werden');
        $this->assertSame('Ursprungsstadt', $row['city']);
    }

    /**
     * #322: Pferde bekommen denselben Schutz - er fehlte ihnen als einzigen.
     *
     * Bei Personen und Deckstationen kam der Schreibschutz mit #296, der
     * HorseController blieb dabei unangetastet. Ausgerechnet dort hängt am
     * meisten daran: Ein Speichern am Papierkorb-Datensatz löscht über
     * remove_image die Bilddatei physisch von der Platte und baut
     * horse_persons und horse_registrations komplett neu auf. Nach einem
     * späteren "Wiederherstellen" kehrte ein stillschweigend veränderter
     * Datensatz zurück - mit einem Audit-Eintrag "Pferd aktualisiert", aber
     * ohne jeden Hinweis auf den Papierkorb-Zustand.
     *
     * Geprüft wird deshalb nicht nur die Ablehnung, sondern ausdrücklich auch,
     * dass die Nebenwirkungen ausgeblieben sind: Der Guard muss VOR der
     * Bildbehandlung und vor den beiden save-Methoden greifen, ein
     * "AND deleted_at IS NULL" allein am UPDATE käme zu spät.
     */
    public function testTrashedHorseStaysReadableButCannotBeSaved(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $name = "Papierkorbpferd {$unique}";

        $personName = "Papierkorbpferd Halter {$unique}";
        $form = $admin->get('/admin/persons/create');
        $admin->post('/admin/persons/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $personName,
        ]);
        $stmt = $db->prepare("SELECT id FROM persons WHERE name = ?");
        $stmt->execute([$personName]);
        $personId = (int)$stmt->fetchColumn();

        $form = $admin->get('/admin/horses/create');
        $admin->post('/admin/horses/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $name,
            'status' => 'active',
            'color' => 'Falbe',
            'persons[0][person_id]' => (string)$personId,
            'persons[0][role]' => 'owner',
        ]);
        $stmt = $db->prepare("SELECT id FROM horses WHERE name = ?");
        $stmt->execute([$name]);
        $horseId = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $horseId);

        $db->prepare("UPDATE horses SET deleted_at = NOW() WHERE id = ?")->execute([$horseId]);

        // 1. Anzeigen bleibt möglich - und sagt jetzt, woran man ist.
        $editPage = $admin->get('/admin/horses/edit?id=' . $horseId);
        $this->assertSame(200, $editPage->statusCode);
        $this->assertStringContainsString($name, $editPage->body);
        $this->assertStringContainsString(
            'Papierkorb',
            $editPage->body,
            'Der Zustand muss im Formular stehen - sonst füllt jemand es aus und erfährt erst beim Absenden davon'
        );

        // 2. Speichern wird abgelehnt.
        $response = $admin->post('/admin/horses/update', [
            'csrf_token' => $editPage->formField('csrf_token') ?? '',
            'id' => (string)$horseId,
            'name' => 'Heimlich umbenannt',
            'status' => 'active',
            'color' => 'Schimmel',
            'is_published' => '1',
            'remove_image' => '1',
        ]);
        $this->assertSame(
            '/admin/horses?error=deleted',
            $response->location(),
            "Der Schreibversuch darf nicht als Erfolg gemeldet werden: {$response->body}"
        );

        // 3. Und zwar wirklich folgenlos - inklusive der Kindtabelle, die die
        //    save-Methoden sonst geleert und neu aufgebaut hätten.
        $stmt = $db->prepare("SELECT name, color, is_published, deleted_at FROM horses WHERE id = ?");
        $stmt->execute([$horseId]);
        $row = $stmt->fetch();
        $this->assertSame($name, $row['name'], 'Der Name darf sich nicht geändert haben');
        $this->assertSame('Falbe', $row['color']);
        $this->assertSame(0, (int)$row['is_published'], 'Ein Papierkorb-Datensatz darf nicht veröffentlicht werden');
        $this->assertNotNull($row['deleted_at'], 'Und gelöscht bleibt gelöscht');

        $stmt = $db->prepare("SELECT person_id FROM horse_persons WHERE horse_id = ?");
        $stmt->execute([$horseId]);
        $this->assertSame(
            $personId,
            (int)$stmt->fetchColumn(),
            'Die Zuordnungen dürfen nicht neu aufgebaut worden sein - der Guard muss vor saveHorsePersons() greifen'
        );

        // 4. Nach dem Wiederherstellen greift der Schutz nicht mehr.
        $db->prepare("UPDATE horses SET deleted_at = NULL WHERE id = ?")->execute([$horseId]);
        $editPage = $admin->get('/admin/horses/edit?id=' . $horseId);
        $response = $admin->post('/admin/horses/update', [
            'csrf_token' => $editPage->formField('csrf_token') ?? '',
            'id' => (string)$horseId,
            'name' => $name,
            'status' => 'active',
            'color' => 'Schimmel',
        ]);
        $this->assertSame('/admin/horses?success=updated', $response->location());
        $stmt->execute([$horseId]);
        $stmt2 = $db->prepare("SELECT color FROM horses WHERE id = ?");
        $stmt2->execute([$horseId]);
        $this->assertSame('Schimmel', $stmt2->fetchColumn());
    }

    /**
     * #317: Eine unbekannte person_id reisst nicht mehr den ganzen
     * Speichervorgang mit.
     *
     * saveHorsePersons() löscht erst alle Zuordnungen des Pferds und fügt sie
     * dann einzeln neu ein. Schlug ein INSERT fehl, war das DELETE bereits
     * festgeschrieben: Das Pferd stand ohne jede Zuordnung da, der Request
     * endete mit 500, und weil die Ausnahme auch das Änderungs-Protokoll
     * übersprang, blieb nicht einmal ein Hinweis darauf, dass es die
     * Zuordnungen gab.
     *
     * Der Auslöser braucht keine Boshaftigkeit: Die Personenauswahl im
     * Formular ist beim Öffnen der Seite eingefroren, und
     * TrashController::emptyTrash() löscht Personen HART. Wer zwischen
     * Öffnen und Speichern den Papierkorb leert, hat den Fall erzeugt.
     */
    public function testUnknownPersonIdDoesNotWipeTheOtherAssignments(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        $personName = "Bestandshalter {$unique}";
        $form = $admin->get('/admin/persons/create');
        $admin->post('/admin/persons/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $personName,
        ]);
        $stmt = $db->prepare("SELECT id FROM persons WHERE name = ?");
        $stmt->execute([$personName]);
        $personId = (int)$stmt->fetchColumn();

        $name = "Verwaiste Zuordnung {$unique}";
        $form = $admin->get('/admin/horses/create');
        $admin->post('/admin/horses/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $name,
            'status' => 'active',
            'persons[0][person_id]' => (string)$personId,
            'persons[0][role]' => 'owner',
        ]);
        $stmt = $db->prepare("SELECT id FROM horses WHERE name = ?");
        $stmt->execute([$name]);
        $horseId = (int)$stmt->fetchColumn();

        // Eine ID, die es sicher nicht gibt - wie nach einem geleerten
        // Papierkorb.
        $verschwunden = (int)$db->query("SELECT COALESCE(MAX(id), 0) + 1000 FROM persons")->fetchColumn();

        $editPage = $admin->get('/admin/horses/edit?id=' . $horseId);
        $response = $admin->post('/admin/horses/update', [
            'csrf_token' => $editPage->formField('csrf_token') ?? '',
            'id' => (string)$horseId,
            'name' => $name,
            'status' => 'active',
            'persons[0][person_id]' => (string)$verschwunden,
            'persons[0][role]' => 'breeder',
            'persons[1][person_id]' => (string)$personId,
            'persons[1][role]' => 'owner',
        ]);

        $this->assertSame(
            '/admin/horses?success=updated',
            $response->location(),
            "Eine verwaiste ID darf den Speichervorgang nicht mehr abbrechen: {$response->body}"
        );

        $stmt = $db->prepare("SELECT person_id, role FROM horse_persons WHERE horse_id = ? ORDER BY id ASC");
        $stmt->execute([$horseId]);
        $zeilen = $stmt->fetchAll();
        $this->assertCount(
            1,
            $zeilen,
            'Die verwaiste Zeile fällt weg (sie trägt ausser der toten ID nichts), die gültige bleibt'
        );
        $this->assertSame($personId, (int)$zeilen[0]['person_id']);
        $this->assertSame('owner', $zeilen[0]['role']);
    }

    /**
     * Nebenbefund aus #296: Die Zuordnungszahl neben einer Person zählte auch
     * gelöschte Pferde mit und war damit eine Obermenge dessen, was ein
     * Bearbeiter tatsächlich zu sehen bekommt.
     */
    public function testHorseCountIgnoresTrashedHorses(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $personName = "Zaehlprobe {$unique}";

        $form = $admin->get('/admin/persons/create');
        $admin->post('/admin/persons/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $personName,
        ]);
        $stmt = $db->prepare("SELECT id FROM persons WHERE name = ?");
        $stmt->execute([$personName]);
        $personId = (int)$stmt->fetchColumn();

        // Zwei Pferde zuordnen, eines davon in den Papierkorb.
        $horseIds = [];
        foreach (['Lebt', 'Geloescht'] as $suffix) {
            $form = $admin->get('/admin/horses/create');
            $admin->post('/admin/horses/store', [
                'csrf_token' => $form->formField('csrf_token') ?? '',
                'name' => "Zaehl {$suffix} {$unique}",
                'status' => 'active',
                'persons[0][person_id]' => (string)$personId,
                'persons[0][role]' => 'owner',
            ]);
            $stmt = $db->prepare("SELECT id FROM horses WHERE name = ?");
            $stmt->execute(["Zaehl {$suffix} {$unique}"]);
            $horseIds[$suffix] = (int)$stmt->fetchColumn();
        }
        $db->prepare("UPDATE horses SET deleted_at = NOW() WHERE id = ?")->execute([$horseIds['Geloescht']]);

        $list = $admin->get('/admin/persons');
        $this->assertSame(200, $list->statusCode);
        // Die Zeile dieser Person aus der Liste herausschneiden und darin die
        // Zahl prüfen - ein Muster über die ganze Seite träfe sonst irgendeine
        // andere "1 Zuordnungen".
        $pos = strpos($list->body, $personName);
        $this->assertNotFalse($pos, 'Die Person muss in der Liste stehen');
        $zeile = substr($list->body, $pos, 2000);
        $this->assertStringContainsString(
            '1 Zuordnungen',
            $zeile,
            'Nur das nicht gelöschte Pferd darf mitgezählt werden'
        );
        $this->assertStringNotContainsString('2 Zuordnungen', $zeile);
    }
}
