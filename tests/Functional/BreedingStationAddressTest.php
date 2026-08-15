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
 */
class BreedingStationAddressTest extends FunctionalTestCase {

    public function testStructuredAddressRoundtripAndLegacyFallback(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $db = Database::getInstance();

        // 1. Station mit strukturierter Adresse über das echte Formular anlegen.
        $structuredName = "Gestuet Strukturiert {$unique}";
        $form = $admin->get('/admin/breeding-stations/create');
        $response = $admin->post('/admin/breeding-stations/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $structuredName,
            'street' => 'Fjordweg',
            'house_number' => '9x',
            'postal_code' => '2497x',
            'city' => 'Flensburg',
            'state' => 'Schleswig-Holstein',
            'country' => 'DE',
            'is_published' => '1',
        ]);
        $this->assertSame('/admin/breeding-stations?success=created', $response->location());

        $stmt = $db->prepare("SELECT * FROM breeding_stations WHERE name = ?");
        $stmt->execute([$structuredName]);
        $station = $stmt->fetch();
        $this->assertNotFalse($station);
        $this->assertSame('Fjordweg', $station['street']);
        $this->assertSame('9x', $station['house_number']);
        $this->assertSame('2497x', $station['postal_code']);
        $this->assertSame('Flensburg', $station['city']);
        $this->assertSame('Schleswig-Holstein', $station['state']);
        $this->assertSame('DE', $station['country']);

        // 2. Öffentliche Stationsseite zeigt die strukturierte Adresse. Anders
        //    als bei Personen ist hier ALLES öffentlich - eine Deckstation ist
        //    eine Geschäftsadresse, keine Privatperson, und ihre Anschrift stand
        //    auch vorher schon vollständig im Freitextfeld auf dieser Seite.
        $guest = $this->newClient();
        $detail = $guest->get('/station?id=' . $station['id']);
        $this->assertSame(200, $detail->statusCode);
        foreach (['Fjordweg', '9x', '2497x', 'Flensburg', 'Schleswig-Holstein'] as $part) {
            $this->assertStringContainsString($part, $detail->body, "Adressteil '{$part}' fehlt auf der öffentlichen Stationsseite");
        }

        // 3. Leeren speichert NULL, nicht den leeren String.
        $editPage = $admin->get('/admin/breeding-stations/edit?id=' . $station['id']);
        $response = $admin->post('/admin/breeding-stations/update', [
            'csrf_token' => $editPage->formField('csrf_token') ?? '',
            'id' => (string)$station['id'],
            'name' => $structuredName,
            'street' => 'Fjordweg',
            'house_number' => '9x',
            'postal_code' => '2497x',
            'city' => 'Flensburg',
            'state' => '',
            'country' => 'DE',
            'is_published' => '1',
        ]);
        $this->assertSame('/admin/breeding-stations?success=updated', $response->location());
        $stmt = $db->prepare("SELECT state FROM breeding_stations WHERE id = ?");
        $stmt->execute([$station['id']]);
        $this->assertNull($stmt->fetchColumn(), 'Leeres Formularfeld muss NULL speichern');

        // 4. Altbestand: nur das Freitextfeld befüllt, mehrzeilig wie in echten
        //    Daten. Muss weiterhin öffentlich erscheinen - hier gibt es nichts
        //    anderes anzuzeigen.
        $legacyName = "Gestuet Altbestand {$unique}";
        $legacyAddress = "Weideweg 1\n24000 Kiel";
        $db->prepare("INSERT INTO breeding_stations (name, address, is_published) VALUES (?, ?, 1)")
            ->execute([$legacyName, $legacyAddress]);
        $legacyId = (int)$db->lastInsertId();

        $legacyDetail = $guest->get('/station?id=' . $legacyId);
        $this->assertSame(200, $legacyDetail->statusCode);
        $this->assertStringContainsString('Weideweg 1', $legacyDetail->body, 'Freitext-Adresse aus dem Altbestand muss weiterhin angezeigt werden');
        $this->assertStringContainsString('24000 Kiel', $legacyDetail->body);

        // 5. Sind beide befüllt, gewinnt die strukturierte Adresse - sonst
        //    stünde die Anschrift nach dem Übertragen doppelt auf der Seite.
        $db->prepare("UPDATE breeding_stations SET street = 'Neuer Weg', house_number = '5x', postal_code = '24001x', city = 'Kiel' WHERE id = ?")
            ->execute([$legacyId]);
        $bothDetail = $guest->get('/station?id=' . $legacyId);
        $this->assertSame(200, $bothDetail->statusCode);
        $this->assertStringContainsString('Neuer Weg', $bothDetail->body);
        $this->assertStringNotContainsString(
            'Weideweg 1',
            $bothDetail->body,
            'Sobald die Einzelfelder gepflegt sind, darf der alte Freitext nicht zusätzlich erscheinen.'
        );
    }
}
