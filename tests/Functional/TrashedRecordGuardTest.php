<?php
// tests/Functional/TrashedRecordGuardTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Papierkorb-Datensätze lassen sich nicht mehr überschreiben (#296).
 *
 * Eine in den Papierkorb gelegte Person blieb über die direkte URL
 * `/admin/contacts/edit?id=…` bearbeitbar: Sie blieb gelöscht, bekam aber neue
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
 *
 * Seit #336 gibt es nur noch EINE Kontakttabelle und einen ContactController.
 * Der frühere zweite Test ("Deckstationen haben dasselbe Muster und damit
 * denselben Schutz") prüfte einen zweiten Controller mit gleichem Code; den
 * gibt es nicht mehr, die Zusicherung ist damit strukturell erfüllt statt
 * geprüft. An seiner Stelle steht jetzt die Frage, die der Umbau NEU
 * aufgeworfen hat: Ein Pferd hängt über ZWEI Steckplätze an einem Kontakt
 * (horse_persons.contact_id, ON DELETE CASCADE, und .station_contact_id,
 * ON DELETE SET NULL), und beide müssen sich beim endgültigen Löschen aus
 * dem Papierkorb unterschiedlich - und jeder für sich richtig - verhalten.
 * Ein Pferd darf danach in KEINEM der beiden Steckplätze auf einen nicht
 * mehr existierenden Kontakt zeigen.
 */
class TrashedRecordGuardTest extends FunctionalTestCase {

    public function testTrashedContactStaysReadableButCannotBeSaved(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $name = "Papierkorbprobe {$unique}";

        // contact_person und address kamen mit #336 aus breeding_stations
        // dazu. Sie werden hier mitgesetzt und unten mitgeprüft, weil
        // update() seit dem Umbau die GESAMTE Feldmenge schreibt: Ein Guard,
        // der zu spät greift, räumte auch die Felder leer, die das
        // abgeschickte Formular gar nicht enthielt.
        $form = $admin->get('/admin/contacts/create');
        $admin->post('/admin/contacts/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $name,
            'city' => 'Ursprungsstadt',
            'contact_person' => 'Erika Muster',
            'address' => 'Weideweg 1',
        ]);
        $stmt = $db->prepare("SELECT id FROM contacts WHERE name = ?");
        $stmt->execute([$name]);
        $personId = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $personId);

        // In den Papierkorb legen (weich löschen).
        $db->prepare("UPDATE contacts SET deleted_at = NOW() WHERE id = ?")->execute([$personId]);

        // 1. Anzeigen bleibt möglich - das ist der DSGVO-Weg.
        $editPage = $admin->get('/admin/contacts/edit?id=' . $personId);
        $this->assertSame(200, $editPage->statusCode, 'Der DSGVO-Auskunftsweg verlinkt hierher und muss offen bleiben');
        $this->assertStringContainsString($name, $editPage->body);
        $this->assertStringContainsString(
            'Papierkorb',
            $editPage->body,
            'Der Zustand muss sichtbar sein - genau daran fehlte es'
        );

        // 2. Speichern wird abgelehnt, und der Datensatz bleibt unverändert.
        $response = $admin->post('/admin/contacts/update', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'id' => (string)$personId,
            'name' => 'Heimlich umbenannt',
            'city' => 'Fremdstadt',
        ]);
        $this->assertSame('/admin/contacts?error=deleted', $response->location(), 'Der Schreibversuch darf nicht als Erfolg gemeldet werden');

        $stmt = $db->prepare("SELECT name, city, contact_person, address, deleted_at FROM contacts WHERE id = ?");
        $stmt->execute([$personId]);
        $row = $stmt->fetch();
        $this->assertSame($name, $row['name'], 'Der Name darf sich nicht geändert haben');
        $this->assertSame('Ursprungsstadt', $row['city']);
        // Die beiden Felder standen im abgewiesenen POST gar nicht drin. Wären
        // sie danach leer, hätte der Schreibversuch die Zeile trotzdem
        // angefasst - der stillste denkbare Datenverlust.
        $this->assertSame('Erika Muster', $row['contact_person']);
        $this->assertSame('Weideweg 1', $row['address']);
        $this->assertNotNull($row['deleted_at'], 'Und gelöscht bleibt gelöscht');

        // 3. Nach dem Wiederherstellen greift der Schutz nicht mehr.
        $db->prepare("UPDATE contacts SET deleted_at = NULL WHERE id = ?")->execute([$personId]);
        $editPage = $admin->get('/admin/contacts/edit?id=' . $personId);
        $response = $admin->post('/admin/contacts/update', [
            'csrf_token' => $editPage->formField('csrf_token') ?? '',
            'id' => (string)$personId,
            'name' => $name,
            'city' => 'Neustadt',
        ]);
        $this->assertSame('/admin/contacts?success=updated', $response->location());
        $stmt->execute([$personId]);
        $this->assertSame('Neustadt', $stmt->fetch()['city']);
    }

    /**
     * #336: Wird ein Kontakt endgültig aus dem Papierkorb gelöscht, darf
     * danach KEIN Pferd mehr auf ihn zeigen - über keinen der beiden
     * Steckplätze.
     *
     * Beide zeigen jetzt auf dieselbe Tabelle und verhalten sich trotzdem
     * verschieden, und das ist Absicht:
     *
     *   contact_id          ON DELETE CASCADE  - ohne den Menschen sagt die
     *                                            Zuordnung nichts mehr aus,
     *                                            die Zeile fällt weg.
     *   station_contact_id  ON DELETE SET NULL - "dieses Pferd stand
     *                                            irgendwo" bleibt wahr, der
     *                                            Freitext trägt es weiter.
     *
     * Der praktische Anlass: TrashController::emptyTrash() und
     * permanentDelete() löschen Kontakte HART, während anderswo ein
     * Bearbeitungsformular mit eingefrorener Auswahl offen steht. Wären beide
     * Steckplätze auf CASCADE gesetzt, verschwände beim Löschen eines
     * Deckstations-Kontakts stillschweigend die halbe Besitzhistorie der
     * daran hängenden Pferde. Wären beide auf SET NULL, bliebe eine
     * Zuordnungszeile ohne Person, ohne Station und ohne Aussage stehen.
     */
    public function testPermanentDeleteResolvesBothContactSlots(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        // Ein einziger Kontakt, der bei einem Pferd die Person und bei einem
        // zweiten die Deckstation ist - genau der Fall, den die getrennten
        // Tabellen früher gar nicht erlaubten.
        $contactName = "Doppelrolle {$unique}";
        $form = $admin->get('/admin/contacts/create');
        $admin->post('/admin/contacts/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $contactName,
        ]);
        $stmt = $db->prepare("SELECT id FROM contacts WHERE name = ?");
        $stmt->execute([$contactName]);
        $contactId = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $contactId);

        $horseIds = [];
        foreach (['Person', 'Station'] as $rolle) {
            $form = $admin->get('/admin/horses/create');
            $felder = [
                'csrf_token' => $form->formField('csrf_token') ?? '',
                'name' => "Doppelrolle {$rolle} {$unique}",
                'status' => 'active',
                'persons[0][role]' => 'owner',
            ];
            if ($rolle === 'Person') {
                $felder['persons[0][contact_id]'] = (string)$contactId;
            } else {
                $felder['persons[0][station_contact_id]'] = (string)$contactId;
                $felder['persons[0][breeding_station_text]'] = 'Gestüt Doppelrolle';
            }
            $admin->post('/admin/horses/store', $felder);
            $stmt = $db->prepare("SELECT id FROM horses WHERE name = ?");
            $stmt->execute(["Doppelrolle {$rolle} {$unique}"]);
            $horseIds[$rolle] = (int)$stmt->fetchColumn();
            $this->assertGreaterThan(0, $horseIds[$rolle]);
        }

        // Testaufbau belegen: Vor dem Löschen hängen beide Steckplätze.
        $stmt = $db->prepare("SELECT COUNT(*) FROM horse_persons WHERE contact_id = ?");
        $stmt->execute([$contactId]);
        $this->assertSame(1, (int)$stmt->fetchColumn(), 'Testaufbau kaputt: Personen-Zuordnung fehlt');
        $stmt = $db->prepare("SELECT COUNT(*) FROM horse_persons WHERE station_contact_id = ?");
        $stmt->execute([$contactId]);
        $this->assertSame(1, (int)$stmt->fetchColumn(), 'Testaufbau kaputt: Stations-Zuordnung fehlt');

        // In den Papierkorb und dann endgültig löschen - über den echten Weg,
        // nicht per DELETE aus dem Test heraus: Geprüft wird ja, dass der
        // Controller sich auf die Fremdschlüssel verlassen DARF.
        $admin->post('/admin/contacts/delete', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'id' => (string)$contactId,
        ]);
        $stmt = $db->prepare("SELECT deleted_at FROM contacts WHERE id = ?");
        $stmt->execute([$contactId]);
        $this->assertNotNull($stmt->fetchColumn(), 'Der Kontakt sollte zunächst im Papierkorb liegen');

        $purge = $admin->post('/admin/trash/permanent-delete', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'type' => 'contact',
            'id' => (string)$contactId,
        ]);
        $this->assertSame(
            '/admin/trash?success=purged',
            $purge->location(),
            "Endgültiges Löschen fehlgeschlagen, Body: {$purge->body}"
        );

        $stmt = $db->prepare("SELECT COUNT(*) FROM contacts WHERE id = ?");
        $stmt->execute([$contactId]);
        $this->assertSame(0, (int)$stmt->fetchColumn());

        // Steckplatz 1 (CASCADE): Zeile weg, Pferd bleibt.
        $stmt = $db->prepare("SELECT COUNT(*) FROM horse_persons WHERE horse_id = ?");
        $stmt->execute([$horseIds['Person']]);
        $this->assertSame(0, (int)$stmt->fetchColumn(), 'Die Personen-Zuordnung muss per CASCADE mitfallen');
        $stmt = $db->prepare("SELECT COUNT(*) FROM horses WHERE id = ?");
        $stmt->execute([$horseIds['Person']]);
        $this->assertSame(1, (int)$stmt->fetchColumn(), 'Das Pferd selbst darf NICHT mitgelöscht werden');

        // Steckplatz 2 (SET NULL): Zeile bleibt, Verweis ist getilgt, die
        // Aussage steht weiter im Freitext.
        $stmt = $db->prepare("SELECT station_contact_id, breeding_station_text FROM horse_persons WHERE horse_id = ?");
        $stmt->execute([$horseIds['Station']]);
        $stationRow = $stmt->fetch();
        $this->assertIsArray($stationRow, 'Die Stations-Zuordnung darf NICHT mitfallen');
        $this->assertNull($stationRow['station_contact_id'], 'Der Verweis auf den gelöschten Kontakt muss auf NULL gehen');
        $this->assertSame('Gestüt Doppelrolle', $stationRow['breeding_station_text']);

        // Und der zweite Weg zur Deckstation: horses.breeding_station_id wird
        // beim Speichern aus dem Zuordnungsblock gespiegelt und zeigt seit
        // #336 ebenfalls auf contacts.
        $stmt = $db->prepare("SELECT breeding_station_id FROM horses WHERE id = ?");
        $stmt->execute([$horseIds['Station']]);
        $this->assertNull(
            $stmt->fetch()['breeding_station_id'],
            'horses.breeding_station_id darf nach dem Löschen nicht auf eine tote Kennung zeigen'
        );
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
        $form = $admin->get('/admin/contacts/create');
        $admin->post('/admin/contacts/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $personName,
        ]);
        $stmt = $db->prepare("SELECT id FROM contacts WHERE name = ?");
        $stmt->execute([$personName]);
        $personId = (int)$stmt->fetchColumn();

        $form = $admin->get('/admin/horses/create');
        $admin->post('/admin/horses/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $name,
            'status' => 'active',
            'color' => 'Falbe',
            'persons[0][contact_id]' => (string)$personId,
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

        $stmt = $db->prepare("SELECT contact_id FROM horse_persons WHERE horse_id = ?");
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
     * #317: Eine unbekannte Kontakt-Kennung reisst nicht mehr den ganzen
     * Speichervorgang mit.
     *
     * saveHorsePersons() löscht erst alle Zuordnungen des Pferds und fügt sie
     * dann einzeln neu ein. Schlug ein INSERT fehl, war das DELETE bereits
     * festgeschrieben: Das Pferd stand ohne jede Zuordnung da, der Request
     * endete mit 500, und weil die Ausnahme auch das Änderungs-Protokoll
     * übersprang, blieb nicht einmal ein Hinweis darauf, dass es die
     * Zuordnungen gab.
     *
     * Der Auslöser braucht keine Boshaftigkeit: Die Kontaktauswahl im
     * Formular ist beim Öffnen der Seite eingefroren, und
     * TrashController::emptyTrash() löscht Kontakte HART. Wer zwischen
     * Öffnen und Speichern den Papierkorb leert, hat den Fall erzeugt.
     *
     * Seit #336 gibt es ZWEI Steckplätze, die beide auf `contacts` zeigen.
     * Geprüft werden deshalb beide - eine Absicherung, die nur für
     * contact_id greift, ließe den Stations-Steckplatz weiter in den
     * Fremdschlüssel laufen, und der Schaden wäre exakt derselbe.
     *
     * Die Zeile selbst überlebt die tote Kennung nur, wenn danach noch etwas
     * in ihr steht: Der Freitext trägt die Stationsaussage weiter, eine Zeile
     * ohne alles fällt weg.
     */
    public function testUnknownContactIdInEitherSlotDoesNotWipeTheOtherAssignments(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        $personName = "Bestandshalter {$unique}";
        $form = $admin->get('/admin/contacts/create');
        $admin->post('/admin/contacts/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $personName,
        ]);
        $stmt = $db->prepare("SELECT id FROM contacts WHERE name = ?");
        $stmt->execute([$personName]);
        $personId = (int)$stmt->fetchColumn();

        $name = "Verwaiste Zuordnung {$unique}";
        $form = $admin->get('/admin/horses/create');
        $admin->post('/admin/horses/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $name,
            'status' => 'active',
            'persons[0][contact_id]' => (string)$personId,
            'persons[0][role]' => 'owner',
        ]);
        $stmt = $db->prepare("SELECT id FROM horses WHERE name = ?");
        $stmt->execute([$name]);
        $horseId = (int)$stmt->fetchColumn();

        // Eine ID, die es sicher nicht gibt - wie nach einem geleerten
        // Papierkorb.
        $verschwunden = (int)$db->query("SELECT COALESCE(MAX(id), 0) + 1000 FROM contacts")->fetchColumn();

        $editPage = $admin->get('/admin/horses/edit?id=' . $horseId);
        $response = $admin->post('/admin/horses/update', [
            'csrf_token' => $editPage->formField('csrf_token') ?? '',
            'id' => (string)$horseId,
            'name' => $name,
            'status' => 'active',
            // Steckplatz 1 tot, sonst nichts in der Zeile -> fällt weg.
            'persons[0][contact_id]' => (string)$verschwunden,
            'persons[0][role]' => 'breeder',
            // Die gültige Zeile, die den Vorgang überstehen muss.
            'persons[1][contact_id]' => (string)$personId,
            'persons[1][role]' => 'owner',
            // Steckplatz 2 tot, aber der Freitext trägt die Aussage -> bleibt
            // ohne den Verweis stehen.
            'persons[2][station_contact_id]' => (string)$verschwunden,
            'persons[2][breeding_station_text]' => 'Restgestüt',
            'persons[2][role]' => 'keeper',
            // Steckplatz 2 tot und nichts sonst -> fällt weg.
            'persons[3][station_contact_id]' => (string)$verschwunden,
            'persons[3][role]' => 'keeper',
        ]);

        $this->assertSame(
            '/admin/horses?success=updated',
            $response->location(),
            "Eine verwaiste ID darf den Speichervorgang nicht mehr abbrechen: {$response->body}"
        );

        $stmt = $db->prepare("SELECT contact_id, station_contact_id, breeding_station_text, role FROM horse_persons WHERE horse_id = ? ORDER BY id ASC");
        $stmt->execute([$horseId]);
        $zeilen = $stmt->fetchAll();
        $this->assertCount(
            2,
            $zeilen,
            'Die beiden leergelaufenen Zeilen fallen weg (sie tragen ausser der toten ID nichts), '
            . 'die gültige und die mit Freitext bleiben'
        );

        $this->assertSame($personId, (int)$zeilen[0]['contact_id']);
        $this->assertSame('owner', $zeilen[0]['role']);

        $this->assertNull(
            $zeilen[1]['station_contact_id'],
            'Die tote Stations-Kennung muss auf NULL gehen statt in den Fremdschlüssel zu laufen'
        );
        $this->assertSame('Restgestüt', $zeilen[1]['breeding_station_text']);
        $this->assertNull($zeilen[1]['contact_id']);

        // Und die Gegenprobe, dass wirklich nichts Totes durchgekommen ist:
        // Auf $verschwunden darf danach kein einziger Steckplatz zeigen.
        $stmt = $db->prepare("SELECT COUNT(*) FROM horse_persons WHERE contact_id = ? OR station_contact_id = ?");
        $stmt->execute([$verschwunden, $verschwunden]);
        $this->assertSame(0, (int)$stmt->fetchColumn());
    }

    /**
     * Nebenbefund aus #296: Die Zuordnungszahl neben einem Kontakt zählte auch
     * gelöschte Pferde mit und war damit eine Obermenge dessen, was ein
     * Bearbeiter tatsächlich zu sehen bekommt.
     *
     * Die Kontaktliste führt seit #336 zwei Zahlen nebeneinander -
     * "Zuordnungen" (horse_persons) und "Pferde" (horses.breeding_station_id,
     * die Kennzahl der alten Stationsliste). Geprüft wird hier weiterhin die
     * erste; sie ist die, die den Papierkorb-Filter trägt.
     */
    public function testHorseCountIgnoresTrashedHorses(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $personName = "Zaehlprobe {$unique}";

        $form = $admin->get('/admin/contacts/create');
        $admin->post('/admin/contacts/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $personName,
        ]);
        $stmt = $db->prepare("SELECT id FROM contacts WHERE name = ?");
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
                'persons[0][contact_id]' => (string)$personId,
                'persons[0][role]' => 'owner',
            ]);
            $stmt = $db->prepare("SELECT id FROM horses WHERE name = ?");
            $stmt->execute(["Zaehl {$suffix} {$unique}"]);
            $horseIds[$suffix] = (int)$stmt->fetchColumn();
        }
        $db->prepare("UPDATE horses SET deleted_at = NOW() WHERE id = ?")->execute([$horseIds['Geloescht']]);

        $list = $admin->get('/admin/contacts');
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
