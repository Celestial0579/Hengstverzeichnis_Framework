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
            'state' => 'Schleswig-Holstein',
            'country' => 'NO',
            'email' => "person-{$unique}@example.com",
            'membership_status' => 'Nichtmitglied NO',
            'phone' => '01234 5678x',
            'mobile' => '0170 12345x',
            'website' => 'https://beispiel-hof.example/x',
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
        $this->assertSame('Schleswig-Holstein', $person['state']);
        $this->assertSame('NO', $person['country']);
        $this->assertSame("person-{$unique}@example.com", $person['email']);
        $this->assertSame('Nichtmitglied NO', $person['membership_status']);
        $this->assertSame('01234 5678x', $person['phone']);
        $this->assertSame('0170 12345x', $person['mobile']);
        $this->assertSame('https://beispiel-hof.example/x', $person['website']);
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
            'state' => '',
            'country' => 'NO',
            'email' => '',
            'membership_status' => 'Mitglied',
            // Telefon/Mobil/Website (#293) gehen denselben Weg: mitgesendet
            // bleiben sie erhalten, 'mobile' bleibt hier bewusst weg und muss
            // damit - wie email und state - auf NULL fallen.
            'phone' => '01234 5678x',
            'website' => 'https://beispiel-hof.example/x',
            'contact_info' => 'Tel: 0170-0000000',
            'is_published' => '1',
        ]);
        $this->assertSame('/admin/persons?success=updated', $response->location());
        $stmt = $db->prepare("SELECT street, state, email, phone, mobile, website, membership_status FROM persons WHERE id = ?");
        $stmt->execute([$person['id']]);
        $updated = $stmt->fetch();
        $this->assertSame('Fjordallee', $updated['street']);
        $this->assertNull($updated['email'], 'Leeres Formularfeld muss NULL speichern');
        $this->assertNull($updated['state'], 'Auch Bundesland/Kanton muss beim Leeren NULL werden (#256)');
        $this->assertSame('Mitglied', $updated['membership_status']);
        $this->assertSame('01234 5678x', $updated['phone']);
        $this->assertSame('https://beispiel-hof.example/x', $updated['website']);
        $this->assertNull($updated['mobile'], 'Ein nicht mitgesendetes Feld muss auch bei den neuen Spalten NULL werden');

        // E-Mail für die öffentliche Negativ-Prüfung und das Bundesland für die
        // Positiv-Prüfung unten wieder setzen.
        $db->prepare("UPDATE persons SET email = ?, state = 'Schleswig-Holstein' WHERE id = ?")
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

        // 4. Öffentliche Detailseite: Ort/Bundesland/Land/Mitgliedsstatus
        // erscheinen, Straße/Hausnummer/PLZ/E-Mail dürfen NICHT im HTML stehen.
        //
        // Bundesland/Kanton (#256) steht bewusst auf der öffentlichen Seite: Die
        // Trennlinie verläuft nicht bei der Feldanzahl, sondern zwischen
        // zustellbarer Anschrift (intern) und grober geografischer Verortung
        // (öffentlich). Ein Bundesland ist gröber als der ohnehin sichtbare Ort.
        $guest = $this->newClient();
        $detail = $guest->get('/horse?id=' . $horseId);
        $this->assertSame(200, $detail->statusCode);
        $this->assertStringContainsString('Glücksburg, Schleswig-Holstein, NO', $detail->body);
        $this->assertStringContainsString('Mitglied', $detail->body);
        $this->assertStringNotContainsString('Fjordallee', $detail->body, 'Straße ist Admin-only und darf öffentlich nie erscheinen');
        $this->assertStringNotContainsString('7x', $detail->body, 'Hausnummer ist Admin-only');
        $this->assertStringNotContainsString('2496x', $detail->body, 'PLZ ist Admin-only');
        $this->assertStringNotContainsString("person-{$unique}@example.com", $detail->body, 'E-Mail ist Admin-only');

        // Kern von #293: Telefon und Mobil sind zustellbare Angaben und stehen
        // damit auf derselben Seite der Trennlinie wie die E-Mail-Adresse. Das
        // Freitextfeld contact_info wurde bis dahin OEFFENTLICH gerendert,
        // obwohl das Admin-Formular ausdruecklich zu Telefonnummern darin
        // einlud - geschuetzt hat allein is_published.
        $this->assertStringNotContainsString('01234 5678x', $detail->body, 'Telefon ist Admin-only');
        $this->assertStringNotContainsString('0170-0000000', $detail->body, 'contact_info darf oeffentlich nicht mehr erscheinen');
        $this->assertStringNotContainsString('Tel: ', $detail->body, 'Auch die Beschriftung aus contact_info darf nicht durchsickern');

        // Die Website dagegen ist zur Veroeffentlichung bestimmt und wird als
        // Verweis ausgegeben.
        $this->assertStringContainsString('https://beispiel-hof.example/x', $detail->body, 'Die Website gehoert auf die oeffentliche Seite');

        // 4b. Länderflagge (#240): eine Person mit country='Dänemark'
        // erscheint auf der Detailseite mit 🇩🇰 und dem gespeicherten
        // Freitext als title-Tooltip; der ISO-Code 'NO' der ersten Person
        // ergibt 🇳🇴. Beide Personen bleiben dafür verknüpft (der Update-Pfad
        // ersetzt horse_persons komplett).
        $flagPersonName = "Person Flagge {$unique}";
        $form = $admin->get('/admin/persons/create');
        $response = $admin->post('/admin/persons/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $flagPersonName,
            'country' => 'Dänemark',
            'is_published' => '1',
        ]);
        $this->assertSame('/admin/persons?success=created', $response->location());
        $stmt = $db->prepare("SELECT id FROM persons WHERE name = ?");
        $stmt->execute([$flagPersonName]);
        $flagPersonId = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $flagPersonId);

        $editHorse = $admin->get('/admin/horses/edit?id=' . $horseId);
        $response = $admin->post('/admin/horses/update', [
            'csrf_token' => $editHorse->formField('csrf_token') ?? '',
            'id' => (string)$horseId,
            'name' => $horseName,
            'status' => 'active',
            'is_published' => '1',
            'persons[0][person_id]' => (string)$person['id'],
            'persons[0][role]' => 'breeder',
            'persons[1][person_id]' => (string)$flagPersonId,
            'persons[1][role]' => 'owner',
        ]);
        $this->assertSame('/admin/horses?success=updated', $response->location());

        $detail = $guest->get('/horse?id=' . $horseId);
        $this->assertSame(200, $detail->statusCode);
        $this->assertStringContainsString('🇩🇰', $detail->body, "country='Dänemark' muss als 🇩🇰 erscheinen");
        $this->assertStringContainsString('title="Dänemark"', $detail->body, 'Der Länder-Freitext muss als title-Tooltip an der Flagge stehen');
        $this->assertStringContainsString('🇳🇴', $detail->body, "Der direkte ISO-Code 'NO' muss als 🇳🇴 erscheinen");

        // 5. Unveröffentlichte Person: auch die neuen Felder verschwinden
        // komplett von der öffentlichen Seite (#121-Zusicherung gilt weiter).
        $db->prepare("UPDATE persons SET is_published = 0 WHERE id = ?")->execute([$person['id']]);
        $detail = $guest->get('/horse?id=' . $horseId);
        $this->assertStringNotContainsString('Glücksburg', $detail->body, 'Felder unveröffentlichter Personen dürfen öffentlich nicht erscheinen');
        $this->assertStringNotContainsString('Mitglied', $detail->body);
        $this->assertStringNotContainsString('https://beispiel-hof.example/x', $detail->body, 'Auch die Website verschwindet mit der Veröffentlichung');
    }

    /**
     * Ein Website-Freitext ist genau das - Freitext. Steht dort ein
     * `javascript:`-Ziel, darf daraus kein Verweis werden: Das Feld pflegt der
     * Admin-Bereich, ausgegeben wird es auf einer oeffentlichen Seite, ein
     * Redakteurskonto genuegte sonst fuer gespeichertes JavaScript bei jedem
     * Besucher. htmlspecialchars() allein faengt das NICHT ab, es kodiert nur
     * den Attributwert - siehe App\Helper\ExternalUrl.
     */
    public function testMaliciousWebsiteSchemeIsNotLinked(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $personName = "Person Schema {$unique}";
        $horseName = "Pferd Schema {$unique}";

        $form = $admin->get('/admin/persons/create');
        $admin->post('/admin/persons/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $personName,
            'website' => 'javascript:alert(1)',
            'is_published' => '1',
        ]);
        $stmt = $db->prepare("SELECT id FROM persons WHERE name = ?");
        $stmt->execute([$personName]);
        $personId = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $personId, 'Die Person muss angelegt worden sein');

        $form = $admin->get('/admin/horses/create');
        $admin->post('/admin/horses/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $horseName,
            'status' => 'active',
            'is_published' => '1',
            'persons[0][person_id]' => (string)$personId,
            'persons[0][role]' => 'breeder',
        ]);
        $stmt = $db->prepare("SELECT id FROM horses WHERE name = ?");
        $stmt->execute([$horseName]);
        $horseId = (int)$stmt->fetchColumn();

        $detail = $this->newClient()->get('/horse?id=' . $horseId);
        $this->assertSame(200, $detail->statusCode);
        // Der Wert bleibt gespeichert (Freitext-Philosophie), aber er wird
        // nicht zum Verweisziel.
        $this->assertStringNotContainsString('href="javascript:', $detail->body);
        $this->assertStringNotContainsString('javascript:alert', $detail->body);
    }
}
