<?php
// tests/Functional/PersonFieldsTest.php

namespace Tests\Functional;

use App\Database;

/**
 * HTTP-Funktionstests für die strukturierten Personenfelder (#188): Adresse,
 * E-Mail und Mitgliedsstatus werden über das echte Formular gespeichert und
 * geändert; öffentlich (Pferde-Detailseite) erscheinen NUR Ort, Land und
 * Mitgliedsstatus - Straße, Hausnummer, PLZ und E-Mail dürfen das Admin
 * nie verlassen (Negativ-Assertions).
 */
class PersonFieldsTest extends FunctionalTestCase {

    public function testStructuredFieldsRoundtripAndPublicExposure(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $personName = "Person Strukturiert {$unique}";
        $horseName = "Personen Testpferd {$unique}";
        $db = Database::getInstance();

        // 1. Person mit allen Feldern über das echte Formular anlegen.
        $form = $admin->get('/admin/persons/create');
        $response = $admin->post('/admin/persons/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $personName,
            'street' => 'Fjordweg',
            // 'x' kommt in Hex-Strings (CSRF-Token, Bild-Hashes) nie vor -
            // macht die Negativ-Assertions unten zufallssicher.
            'house_number' => '7x',
            'postal_code' => '2496x',
            'city' => 'Glücksburg',
            'country' => 'NO',
            'email' => "person-{$unique}@example.com",
            'membership_status' => 'Nichtmitglied NO',
            'contact_info' => 'Tel: 0170-0000000',
            'is_published' => '1',
        ]);
        $this->assertSame('/admin/persons?success=created', $response->location());

        $stmt = $db->prepare("SELECT * FROM persons WHERE name = ?");
        $stmt->execute([$personName]);
        $person = $stmt->fetch();
        $this->assertNotFalse($person);
        $this->assertSame('Fjordweg', $person['street']);
        $this->assertSame('7x', $person['house_number']);
        $this->assertSame('2496x', $person['postal_code']);
        $this->assertSame('Glücksburg', $person['city']);
        $this->assertSame('NO', $person['country']);
        $this->assertSame("person-{$unique}@example.com", $person['email']);
        $this->assertSame('Nichtmitglied NO', $person['membership_status']);
        $this->assertSame('Tel: 0170-0000000', $person['contact_info']);

        // 2. Update: Feld ändern und eines leeren (leer -> NULL).
        $editPage = $admin->get('/admin/persons/edit?id=' . $person['id']);
        $response = $admin->post('/admin/persons/update', [
            'csrf_token' => $editPage->formField('csrf_token') ?? '',
            'id' => (string)$person['id'],
            'name' => $personName,
            'street' => 'Fjordallee',
            'house_number' => '7x',
            'postal_code' => '2496x',
            'city' => 'Glücksburg',
            'country' => 'NO',
            'email' => '',
            'membership_status' => 'Mitglied',
            'contact_info' => 'Tel: 0170-0000000',
            'is_published' => '1',
        ]);
        $this->assertSame('/admin/persons?success=updated', $response->location());
        $stmt = $db->prepare("SELECT street, email, membership_status FROM persons WHERE id = ?");
        $stmt->execute([$person['id']]);
        $updated = $stmt->fetch();
        $this->assertSame('Fjordallee', $updated['street']);
        $this->assertNull($updated['email'], 'Leeres Formularfeld muss NULL speichern');
        $this->assertSame('Mitglied', $updated['membership_status']);

        // E-Mail für die öffentliche Negativ-Prüfung unten wieder setzen.
        $db->prepare("UPDATE persons SET email = ? WHERE id = ?")
            ->execute(["person-{$unique}@example.com", $person['id']]);

        // 3. Pferd anlegen und die Person als Züchter zuordnen.
        $form = $admin->get('/admin/horses/create');
        $response = $admin->post('/admin/horses/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $horseName,
            'status' => 'active',
            'is_published' => '1',
            'persons[0][person_id]' => (string)$person['id'],
            'persons[0][role]' => 'breeder',
        ]);
        $this->assertSame('/admin/horses?success=created', $response->location());
        $stmt = $db->prepare("SELECT id FROM horses WHERE name = ?");
        $stmt->execute([$horseName]);
        $horseId = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $horseId);

        // 4. Öffentliche Detailseite: Ort/Land/Mitgliedsstatus erscheinen,
        // Straße/Hausnummer/PLZ/E-Mail dürfen NICHT im HTML stehen.
        $guest = $this->newClient();
        $detail = $guest->get('/horse?id=' . $horseId);
        $this->assertSame(200, $detail->statusCode);
        $this->assertStringContainsString('Glücksburg, NO', $detail->body);
        $this->assertStringContainsString('Mitglied', $detail->body);
        $this->assertStringNotContainsString('Fjordallee', $detail->body, 'Straße ist Admin-only und darf öffentlich nie erscheinen');
        $this->assertStringNotContainsString('7x', $detail->body, 'Hausnummer ist Admin-only');
        $this->assertStringNotContainsString('2496x', $detail->body, 'PLZ ist Admin-only');
        $this->assertStringNotContainsString("person-{$unique}@example.com", $detail->body, 'E-Mail ist Admin-only');

        // 5. Unveröffentlichte Person: auch die neuen Felder verschwinden
        // komplett von der öffentlichen Seite (#121-Zusicherung gilt weiter).
        $db->prepare("UPDATE persons SET is_published = 0 WHERE id = ?")->execute([$person['id']]);
        $detail = $guest->get('/horse?id=' . $horseId);
        $this->assertStringNotContainsString('Glücksburg', $detail->body, 'Felder unveröffentlichter Personen dürfen öffentlich nicht erscheinen');
        $this->assertStringNotContainsString('Mitglied', $detail->body);
    }
}
