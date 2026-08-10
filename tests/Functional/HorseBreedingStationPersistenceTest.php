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
    }
}
