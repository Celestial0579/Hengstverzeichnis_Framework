<?php
// tests/Functional/BreedingStationAddressTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Strukturierte Adresse der Deckstationen (#256).
 *
 * Bis dahin hatte `breeding_stations` nur ein einziges Freitextfeld `address`
 * (und nicht einmal ein `country`) - eine Station liess sich damit weder nach
 * Ort noch nach Bundesland/Kanton einordnen. Die Einzelfelder sind rein additiv:
 * `address` bleibt bestehen, wird NICHT automatisch zerlegt (der Bestand ist
 * real mehrzeilig, eine Zerlegung wäre geraten - dieselbe Entscheidung wie bei
 * den Personendaten in #188) und weiterhin angezeigt, solange die neuen Felder
 * leer sind.
 *
 * Genau dieser Rückfall ist die heikle Stelle: Ohne ihn wäre beim Ausrollen die
 * Anschrift jeder Bestandsstation schlagartig von der öffentlichen Seite
 * verschwunden, ohne dass jemand etwas gelöscht hätte.
 *
 * Seit #336 liegen die Felder an `contacts` und werden über die gemeinsame
 * Kontaktverwaltung gepflegt; die öffentliche Seite ist /kontakt?id=. Die
 * Anschrift ist damit nicht mehr allein deshalb öffentlich, weil der Datensatz
 * eine Station ist - sie hängt an der Freigabe `contact_public` je Datensatz.
 * Dieser Test führt beide Hälften mit: mit Freigabe steht die Anschrift da,
 * ohne sie nicht, und der Rückfall auf den Freitext gilt in beiden Fällen
 * gleichermaßen.
 */
class BreedingStationAddressTest extends FunctionalTestCase {

    public function testStructuredAddressRoundtripAndLegacyFallback(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $db = Database::getInstance();

        // 1. Stationskontakt mit strukturierter Adresse über das echte Formular
        //    anlegen. Die Freigabe wird ausdrücklich gesetzt: Sie ist seit #336
        //    die Bedingung dafür, dass eine zustellbare Anschrift öffentlich
        //    erscheint - bei der Migration kommt sie als Bestandswert mit, bei
        //    einer Neuanlage muss sie jemand bewusst erteilen.
        $structuredName = "Gestuet Strukturiert {$unique}";
        $form = $admin->get('/admin/contacts/create');
        $response = $admin->post('/admin/contacts/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $structuredName,
            'street' => 'Fjordweg',
            'house_number' => '9x',
            'postal_code' => '2497x',
            'city' => 'Flensburg',
            'state' => 'Schleswig-Holstein',
            'country' => 'DE',
            'contact_public' => '1',
            'is_published' => '1',
        ]);
        $this->assertSame('/admin/contacts?success=created', $response->location());

        $stmt = $db->prepare("SELECT * FROM contacts WHERE name = ?");
        $stmt->execute([$structuredName]);
        $station = $stmt->fetch();
        $this->assertNotFalse($station);
        $this->assertSame('Fjordweg', $station['street']);
        $this->assertSame('9x', $station['house_number']);
        $this->assertSame('2497x', $station['postal_code']);
        $this->assertSame('Flensburg', $station['city']);
        $this->assertSame('Schleswig-Holstein', $station['state']);
        $this->assertSame('DE', $station['country']);

        // 2. Öffentliche Kontaktseite zeigt die strukturierte Adresse.
        $guest = $this->newClient();
        $detail = $guest->get('/kontakt?id=' . $station['id']);
        $this->assertSame(200, $detail->statusCode);
        foreach (['Fjordweg', '9x', '2497x', 'Flensburg', 'Schleswig-Holstein'] as $part) {
            $this->assertStringContainsString($part, $detail->body, "Adressteil '{$part}' fehlt auf der öffentlichen Kontaktseite");
        }

        // 2b. Gegenprobe zur Freigabe. Bis v0.7 war die Anschrift einer Station
        //     bedingungslos öffentlich - eine Geschäftsadresse in einer eigenen
        //     Tabelle ohne PII. Diese Trennung gibt es nicht mehr, und die
        //     strukturierten Felder verschwinden ohne Freigabe wieder. Die
        //     grobe Verortung (Ort, Bundesland, Land) bleibt: Die Trennlinie
        //     verläuft zwischen zustellbarer Anschrift und Verortung, nicht
        //     zwischen Personen und Betrieben.
        $db->prepare("UPDATE contacts SET contact_public = 0 WHERE id = ?")->execute([$station['id']]);
        $ohneFreigabe = $guest->get('/kontakt?id=' . $station['id']);
        $this->assertSame(200, $ohneFreigabe->statusCode);
        $this->assertStringNotContainsString('Fjordweg', $ohneFreigabe->body, 'Die Straße hängt an der Freigabe');
        $this->assertStringNotContainsString('9x', $ohneFreigabe->body, 'Die Hausnummer ebenso');
        $this->assertStringNotContainsString('2497x', $ohneFreigabe->body, 'Und die PLZ');
        $this->assertStringContainsString('Flensburg', $ohneFreigabe->body, 'Der Ort bleibt öffentlich');
        $this->assertStringContainsString('Schleswig-Holstein', $ohneFreigabe->body);
        $db->prepare("UPDATE contacts SET contact_public = 1 WHERE id = ?")->execute([$station['id']]);

        // 3. Leeren speichert NULL, nicht den leeren String.
        $editPage = $admin->get('/admin/contacts/edit?id=' . $station['id']);
        $response = $admin->post('/admin/contacts/update', [
            'csrf_token' => $editPage->formField('csrf_token') ?? '',
            'id' => (string)$station['id'],
            'name' => $structuredName,
            'street' => 'Fjordweg',
            'house_number' => '9x',
            'postal_code' => '2497x',
            'city' => 'Flensburg',
            'state' => '',
            'country' => 'DE',
            'contact_public' => '1',
            'is_published' => '1',
        ]);
        $this->assertSame('/admin/contacts?success=updated', $response->location());
        $stmt = $db->prepare("SELECT state FROM contacts WHERE id = ?");
        $stmt->execute([$station['id']]);
        $this->assertNull($stmt->fetchColumn(), 'Leeres Formularfeld muss NULL speichern');

        // 4. Altbestand: nur das Freitextfeld befüllt, mehrzeilig wie in echten
        //    Daten. Muss weiterhin öffentlich erscheinen - hier gibt es nichts
        //    anderes anzuzeigen. contact_public = 1 gehört zum nachgestellten
        //    Altbestand dazu: Aus `breeding_stations` bringt die Migration den
        //    dortigen Bestandswert mit (siehe ContactPublicTest).
        $legacyName = "Gestuet Altbestand {$unique}";
        $legacyAddress = "Weideweg 1\n24000 Kiel";
        $db->prepare("INSERT INTO contacts (name, address, contact_public, is_published) VALUES (?, ?, 1, 1)")
            ->execute([$legacyName, $legacyAddress]);
        $legacyId = (int)$db->lastInsertId();

        $legacyDetail = $guest->get('/kontakt?id=' . $legacyId);
        $this->assertSame(200, $legacyDetail->statusCode);
        $this->assertStringContainsString('Weideweg 1', $legacyDetail->body, 'Freitext-Adresse aus dem Altbestand muss weiterhin angezeigt werden');
        $this->assertStringContainsString('24000 Kiel', $legacyDetail->body);

        // 5. Sind beide befüllt, gewinnt die strukturierte Adresse - sonst
        //    stünde die Anschrift nach dem Übertragen doppelt auf der Seite.
        $db->prepare("UPDATE contacts SET street = 'Neuer Weg', house_number = '5x', postal_code = '24001x', city = 'Kiel' WHERE id = ?")
            ->execute([$legacyId]);
        $bothDetail = $guest->get('/kontakt?id=' . $legacyId);
        $this->assertSame(200, $bothDetail->statusCode);
        $this->assertStringContainsString('Neuer Weg', $bothDetail->body);
        $this->assertStringNotContainsString(
            'Weideweg 1',
            $bothDetail->body,
            'Sobald die Einzelfelder gepflegt sind, darf der alte Freitext nicht zusätzlich erscheinen.'
        );
    }
}
