<?php
// tests/Functional/HorseBreedingStationPersistenceTest.php

namespace Tests\Functional;

/**
 * Regressionstest für #214: saveHorsePersons() nullte die Freitext-Deckstation
 * (horses.breeding_station) bei JEDEM Speichern, wenn die Personenzeilen keine
 * Station lieferten - der Normalfall beim Bearbeiten importierter Pferde, denn
 * das Formular kennt kein direktes name="breeding_station"-Feld mehr. Ein per
 * CSV-Import gesetzter Wert ("Gestüt Nordfjord") verschwand so ersatzlos,
 * sobald ein Editor nur die Farbe änderte.
 *
 * Ablauf über den echten HTTP-Flow: Pferd mit Freitext-Station per CSV-Import
 * anlegen, dann bearbeiten (NUR Farbe ändern, ohne breeding_station-Feld und
 * mit leerem Personen-Block, wie es das Formular tut) - die Station muss
 * erhalten bleiben. Gegenprobe: Eine Personenzeile MIT Station muss den Wert
 * weiterhin auf den Hauptdatensatz spiegeln (der Sync ist nur bedingt, nicht
 * abgeschafft).
 *
 * Verifikation direkt in der DB (Database::getInstance() aus dem
 * PHPUnit-Prozess, analog zu FunctionalTestCase::resetTotpReplayGuard()): die
 * Admin-Ansichten zeigen den Freitext-Wert nirgends verlässlich an - genau
 * deshalb blieb der Datenverlust unbemerkt.
 *
 * Alles in einer Testmethode mit einer authenticatedClient()-Sitzung, analog
 * zu HorseMatchingTest/HorseCsvImportTest (Request-Anzahl der Functional-Suite
 * bewusst klein halten).
 */
class HorseBreedingStationPersistenceTest extends FunctionalTestCase {

    public function testEditingImportedHorseKeepsFreetextBreedingStation(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $horseName = "Bruse Importiert {$unique}";

        // 1. Pferd mit Freitext-Deckstation per CSV-Import anlegen - der einzige
        // reguläre Weg, auf dem horses.breeding_station ohne breeding_station_id
        // befüllt wird (Fehlerszenario aus #214).
        $importForm = $admin->get('/admin/import/horses');
        $previewResponse = $admin->postFile(
            '/admin/import/horses/preview',
            ['csrf_token' => $importForm->formField('csrf_token') ?? ''],
            'csv_file',
            'import.csv',
            "name;birth_year;color;breeding_station\n{$horseName};2010;Braun;Gestüt Nordfjord\n"
        );
        $this->assertSame(200, $previewResponse->statusCode);

        $commitResponse = $admin->post('/admin/import/horses/commit', [
            'csrf_token' => $previewResponse->formField('csrf_token') ?? '',
        ]);
        $this->assertSame(200, $commitResponse->statusCode);
        $this->assertStringContainsString('1 Pferd(e) erfolgreich importiert', strip_tags($commitResponse->body));

        $db = \App\Database::getInstance();
        $stmt = $db->prepare("SELECT id, breeding_station, breeding_station_id, color FROM horses WHERE name = ?");
        $stmt->execute([$horseName]);
        $horse = $stmt->fetch();
        $this->assertNotFalse($horse, 'Importiertes Pferd nicht in der DB gefunden');
        $this->assertSame('Gestüt Nordfjord', $horse['breeding_station'], 'Import sollte die Freitext-Station setzen');
        $horseId = (int)$horse['id'];

        // 2. Bearbeiten wie über das echte Formular: NUR die Farbe ändern. Das
        // Formular übermittelt kein breeding_station-Feld, aber immer einen
        // Personen-Block (Zeile 0 mit leeren Selects) - exakt die Kombination,
        // die den Wert vor #214 still auf NULL zurücksetzte.
        $editPage = $admin->get('/admin/horses/edit?id=' . $horseId);
        $this->assertSame(200, $editPage->statusCode);

        $baseFormData = [
            'csrf_token' => $editPage->formField('csrf_token') ?? '',
            'id' => (string)$horseId,
            'name' => $horseName,
            'birth_year' => '2010',
            'status' => 'active',
            'persons' => [
                ['person_id' => '', 'role' => 'owner', 'breeding_station_id' => '', 'from_year' => '', 'until_year' => ''],
            ],
        ];

        $updateResponse = $admin->post('/admin/horses/update', $baseFormData + ['color' => 'Rappe']);
        $this->assertSame('/admin/horses?success=updated', $updateResponse->location(), "Bearbeiten fehlgeschlagen, Body: {$updateResponse->body}");

        $stmt = $db->prepare("SELECT breeding_station, breeding_station_id, color FROM horses WHERE id = ?");
        $stmt->execute([$horseId]);
        $afterEdit = $stmt->fetch();
        $this->assertSame('Rappe', $afterEdit['color'], 'Die geänderte Farbe muss gespeichert sein');
        $this->assertSame(
            'Gestüt Nordfjord',
            $afterEdit['breeding_station'],
            'Regression #214: die importierte Freitext-Deckstation darf beim Bearbeiten ohne Stations-Angabe nicht verlorengehen'
        );
        $this->assertNull($afterEdit['breeding_station_id']);

        // 3. Gegenprobe: Liefert eine Personenzeile eine Station, muss der Sync
        // auf den Hauptdatensatz weiterhin greifen (die Korrektur macht ihn nur
        // BEDINGT, sie schafft ihn nicht ab). breeding_station_text ist ein
        // reguläres saveHorsePersons()-Feld, auch wenn das Formular dafür
        // aktuell kein Eingabefeld rendert.
        $overwriteResponse = $admin->post('/admin/horses/update', array_replace($baseFormData, [
            'color' => 'Rappe',
            'persons' => [
                ['person_id' => '', 'role' => 'owner', 'breeding_station_id' => '', 'breeding_station_text' => 'Hof Aurora', 'from_year' => '', 'until_year' => ''],
            ],
        ]));
        $this->assertSame('/admin/horses?success=updated', $overwriteResponse->location(), "Gegenprobe fehlgeschlagen, Body: {$overwriteResponse->body}");

        $stmt->execute([$horseId]);
        $afterOverwrite = $stmt->fetch();
        $this->assertSame(
            'Hof Aurora',
            $afterOverwrite['breeding_station'],
            'Eine aus den Personenzeilen ermittelte Station muss weiterhin auf den Hauptdatensatz gespiegelt werden'
        );

        // ---- #295: die Freitext-ZEILE selbst darf nicht verschwinden -------
        //
        // Bis hier prueft der Test nur die Spiegelung nach horses.breeding_station.
        // Die horse_persons-Zeile mit 'Hof Aurora' existiert jetzt; ab hier geht
        // es darum, dass sie ein weiteres Speichern ueberlebt.

        // Jahre setzen - Freitext-Zeilen MIT Jahren sind der Bestandsfall aus
        // dem Import (Beispiel: "Niederlande, 1995-2003").
        $db->prepare("UPDATE horse_persons SET from_year = 1995, until_year = 2003 WHERE horse_id = ?")
            ->execute([$horseId]);

        // T1/T2: Erneut speichern, genau wie es das Formular VOR #295 tat -
        // Personenzeile OHNE breeding_station_text, die uebrigen Felder aber
        // gefuellt, wie das Formular sie zurueckliefert. Die Zeile muss samt
        // Jahren stehen bleiben; vorher fiel sie ersatzlos weg.
        //
        // Die Jahre werden hier bewusst MITGESENDET: Sie haben im Formular ein
        // Feld, ein leerer Wert waere also ein bewusstes Leeren - anders als
        // beim Freitext, fuer den es bis #295 gar kein Feld gab. Genau diese
        // Unterscheidung ist der Kern der Korrektur.
        $admin->post('/admin/horses/update', array_replace($baseFormData, [
            'color' => 'Rappe',
            'persons' => [
                ['person_id' => '', 'role' => 'owner', 'breeding_station_id' => '', 'from_year' => '1995', 'until_year' => '2003'],
            ],
        ]));
        $stmt = $db->prepare("SELECT breeding_station_text, from_year, until_year FROM horse_persons WHERE horse_id = ?");
        $stmt->execute([$horseId]);
        $zeilen = $stmt->fetchAll();
        $this->assertCount(1, $zeilen, 'Die Freitext-Zeile muss ein Speichern ohne das Feld ueberleben (#295)');
        $this->assertSame('Hof Aurora', $zeilen[0]['breeding_station_text']);
        $this->assertSame(1995, (int)$zeilen[0]['from_year'], 'Auch die Jahresangaben gehoeren erhalten');
        $this->assertSame(2003, (int)$zeilen[0]['until_year']);

        // T6: Das Formular liefert das Feld jetzt tatsaechlich - ohne diese
        // Zusicherung koennte es spaeter unbemerkt wieder herausfallen, und
        // genau so ist #295 entstanden.
        $editPage = $admin->get('/admin/horses/edit?id=' . $horseId);
        $this->assertStringContainsString('breeding_station_text', $editPage->body, 'Das Freitextfeld muss im Formular stehen');
        $this->assertStringContainsString('Hof Aurora', $editPage->body, 'Und den gespeicherten Wert zeigen');
        $this->assertStringContainsString('name="persons_present"', $editPage->body, 'Der Marker fehlt - sonst laesst sich die letzte Zeile nicht mehr loeschen');

        // T5: Ein POST ganz OHNE persons-Block ist keine Aussage ueber die
        // Zuordnungen und darf sie nicht anfassen (Skript-POST, Teilformular).
        $ohnePersons = $baseFormData;
        unset($ohnePersons['persons']);
        $admin->post('/admin/horses/update', $ohnePersons + ['color' => 'Falbe']);
        $stmt->execute([$horseId]);
        $this->assertCount(1, $stmt->fetchAll(), 'Ohne persons-Block bleiben die Zuordnungen unangetastet');

        // T3: Ein uebermittelter LEERSTRING loescht dagegen weiterhin bewusst -
        // das ist der Unterschied, auf dem die ganze Korrektur beruht.
        $admin->post('/admin/horses/update', array_replace($baseFormData, [
            'color' => 'Rappe',
            'persons' => [
                ['person_id' => '', 'role' => 'owner', 'breeding_station_id' => '', 'breeding_station_text' => '', 'from_year' => '', 'until_year' => ''],
            ],
        ]));
        $stmt->execute([$horseId]);
        $this->assertCount(0, $stmt->fetchAll(), 'Leer uebermittelt heisst weiterhin loeschen');

        // T7: Der Vorgang protokolliert sich - bis #295 lief er spurlos.
        $log = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'Pferdezuordnungen geändert' AND details LIKE ?");
        $log->execute(['%Pferd ID ' . $horseId . ':%']);
        $this->assertGreaterThan(0, (int)$log->fetchColumn(), 'Geaenderte Zuordnungen gehoeren ins Audit-Log');
    }

    /**
     * T4: Bei mehreren Zeilen muss der Bestandstext an der richtigen Position
     * landen - die Zuordnung laeuft ueber den Index, und das Formular vergibt
     * ihn in derselben Reihenfolge, in der edit() die Zeilen rendert
     * (ORDER BY hp.id ASC).
     */
    public function testFreetextStationsKeepTheirPositionAcrossRows(): void {
        $admin = $this->authenticatedClient();
        $db = \App\Database::getInstance();
        $unique = uniqid();
        $horseName = "Positionsprobe {$unique}";

        $form = $admin->get('/admin/horses/create');
        $admin->post('/admin/horses/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $horseName,
            'status' => 'active',
            'persons' => [
                ['person_id' => '', 'role' => 'owner', 'breeding_station_id' => '', 'breeding_station_text' => 'Station Alpha', 'from_year' => '', 'until_year' => ''],
                ['person_id' => '', 'role' => 'owner', 'breeding_station_id' => '', 'breeding_station_text' => 'Station Beta', 'from_year' => '', 'until_year' => ''],
            ],
        ]);
        $stmt = $db->prepare("SELECT id FROM horses WHERE name = ?");
        $stmt->execute([$horseName]);
        $horseId = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $horseId);

        $editPage = $admin->get('/admin/horses/edit?id=' . $horseId);
        $base = [
            'csrf_token' => $editPage->formField('csrf_token') ?? '',
            'id' => (string)$horseId,
            'name' => $horseName,
            'status' => 'active',
        ];

        // Beide Zeilen ohne das Feld zurueckschicken: beide Texte bleiben, in
        // derselben Reihenfolge.
        $admin->post('/admin/horses/update', $base + ['persons' => [
            ['person_id' => '', 'role' => 'owner', 'breeding_station_id' => '', 'from_year' => '', 'until_year' => ''],
            ['person_id' => '', 'role' => 'owner', 'breeding_station_id' => '', 'from_year' => '', 'until_year' => ''],
        ]]);
        $stmt = $db->prepare("SELECT breeding_station_text FROM horse_persons WHERE horse_id = ? ORDER BY id ASC");
        $stmt->execute([$horseId]);
        $this->assertSame(['Station Alpha', 'Station Beta'], $stmt->fetchAll(\PDO::FETCH_COLUMN));

        // Und wenn der Editor die erste Zeile loescht, bleibt die zweite - der
        // Index 1 zieht weiterhin den richtigen Bestandstext.
        $editPage = $admin->get('/admin/horses/edit?id=' . $horseId);
        $admin->post('/admin/horses/update', array_replace($base, [
            'csrf_token' => $editPage->formField('csrf_token') ?? '',
            'persons' => [
                1 => ['person_id' => '', 'role' => 'owner', 'breeding_station_id' => '', 'from_year' => '', 'until_year' => ''],
            ],
        ]));
        $stmt->execute([$horseId]);
        $this->assertSame(['Station Beta'], $stmt->fetchAll(\PDO::FETCH_COLUMN), 'Die Loeschgeste muss wirksam bleiben');
    }
}
