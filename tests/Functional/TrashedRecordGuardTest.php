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
