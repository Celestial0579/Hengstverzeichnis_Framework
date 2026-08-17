<?php
// tests/Functional/ContactPublicTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Ausdrückliche Freigabe der Kontaktdaten je Datensatz.
 *
 * Der Unterschied zum Fehler aus #293 ist nicht das Ergebnis, sondern die
 * Absicht: Dort wurde ein als „sonstige Kontaktinformationen" beschriftetes
 * Freitextfeld **versehentlich** öffentlich gerendert. Hier entscheidet die
 * Redaktion je Datensatz und sieht im Formular, was das bedeutet.
 *
 * Die Vorgaben sind bewusst verschieden - Personen `0`, Deckstationen `1` -,
 * und genau das prüft dieser Test mit: Bei Stationen waren Telefon und E-Mail
 * seit jeher öffentlich (Geschäftsadresse), eine Vorgabe von `0` hätte
 * bestehende Angaben stillschweigend versteckt.
 */
class ContactPublicTest extends FunctionalTestCase {

    public function testPersonContactStaysInternalUntilExplicitlyReleased(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $name = "Freigabe {$unique}";

        // Ohne Häkchen - die Vorgabe.
        $form = $admin->get('/admin/persons/create');
        $admin->post('/admin/persons/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $name,
            'city' => 'Kiel',
            'email' => "kontakt-{$unique}@example.com",
            'phone' => '0431 111x',
            'mobile' => '0170 222x',
            'is_published' => '1',
        ]);
        $stmt = $db->prepare("SELECT id, contact_public FROM persons WHERE name = ?");
        $stmt->execute([$name]);
        $person = $stmt->fetch();
        $personId = (int)$person['id'];
        $this->assertSame(0, (int)$person['contact_public'], 'Ohne Häkchen bleibt die Freigabe aus');

        $guest = $this->newClient();
        $seite = $guest->get('/person?id=' . $personId);
        $this->assertSame(200, $seite->statusCode);
        $this->assertStringContainsString('Kiel', $seite->body, 'Die grobe Verortung bleibt öffentlich');
        $this->assertStringNotContainsString("kontakt-{$unique}@example.com", $seite->body, 'E-Mail ohne Freigabe: intern');
        $this->assertStringNotContainsString('0431 111x', $seite->body, 'Telefon ohne Freigabe: intern');
        $this->assertStringNotContainsString('0170 222x', $seite->body, 'Mobil ohne Freigabe: intern');

        // Jetzt mit Häkchen.
        $editPage = $admin->get('/admin/persons/edit?id=' . $personId);
        $response = $admin->post('/admin/persons/update', [
            'csrf_token' => $editPage->formField('csrf_token') ?? '',
            'id' => (string)$personId,
            'name' => $name,
            'city' => 'Kiel',
            'email' => "kontakt-{$unique}@example.com",
            'phone' => '0431 111x',
            'mobile' => '0170 222x',
            'contact_public' => '1',
            'is_published' => '1',
        ]);
        $this->assertSame('/admin/persons?success=updated', $response->location());

        $seite = $this->newClient()->get('/person?id=' . $personId);
        $this->assertStringContainsString("kontakt-{$unique}@example.com", $seite->body, 'Mit Freigabe erscheint die E-Mail');
        $this->assertStringContainsString('0431 111x', $seite->body);
        $this->assertStringContainsString('0170 222x', $seite->body);

        // Und wieder abwählbar - eine Freigabe muss zurücknehmbar sein.
        $editPage = $admin->get('/admin/persons/edit?id=' . $personId);
        $admin->post('/admin/persons/update', [
            'csrf_token' => $editPage->formField('csrf_token') ?? '',
            'id' => (string)$personId,
            'name' => $name,
            'city' => 'Kiel',
            'email' => "kontakt-{$unique}@example.com",
            'is_published' => '1',
        ]);
        $seite = $this->newClient()->get('/person?id=' . $personId);
        $this->assertStringNotContainsString("kontakt-{$unique}@example.com", $seite->body, 'Zurückgenommene Freigabe wirkt sofort');
    }

    /**
     * Deckstationen: Vorgabe 1, damit die Migration nichts wegnimmt, was
     * vorher öffentlich war.
     */
    public function testStationContactIsPublicByDefaultAndCanBeHidden(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $name = "Station Freigabe {$unique}";

        $form = $admin->get('/admin/breeding-stations/create');
        $admin->post('/admin/breeding-stations/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $name,
            'phone' => '04321 999x',
            'email' => "station-{$unique}@example.com",
            'contact_public' => '1',
            'is_published' => '1',
        ]);
        $stmt = $db->prepare("SELECT id FROM breeding_stations WHERE name = ?");
        $stmt->execute([$name]);
        $stationId = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $stationId);

        $seite = $this->newClient()->get('/station?id=' . $stationId);
        $this->assertSame(200, $seite->statusCode);
        $this->assertStringContainsString('04321 999x', $seite->body, 'Stationskontakt ist standardmäßig öffentlich');
        $this->assertStringContainsString("station-{$unique}@example.com", $seite->body);

        // Häkchen heraus - jetzt intern.
        $editPage = $admin->get('/admin/breeding-stations/edit?id=' . $stationId);
        $admin->post('/admin/breeding-stations/update', [
            'csrf_token' => $editPage->formField('csrf_token') ?? '',
            'id' => (string)$stationId,
            'name' => $name,
            'phone' => '04321 999x',
            'email' => "station-{$unique}@example.com",
            'is_published' => '1',
        ]);
        $seite = $this->newClient()->get('/station?id=' . $stationId);
        $this->assertStringNotContainsString('04321 999x', $seite->body, 'Ohne Häkchen bleibt der Stationskontakt intern');
        $this->assertStringNotContainsString("station-{$unique}@example.com", $seite->body);
    }

    /**
     * Bestandsdaten: Eine Station, die es vor der Migration schon gab, muss
     * ihre öffentlichen Kontaktdaten behalten. Eine Freigabe darf nichts
     * wegnehmen, was vorher da war - deshalb die Vorgabe 1.
     */
    public function testExistingStationsKeepTheirPublicContactAfterMigration(): void {
        $db = Database::getInstance();
        $unique = uniqid();
        $name = "Bestandsstation {$unique}";

        // Direkt in die DB, ohne contact_public zu setzen - so sieht eine
        // Zeile aus, die die Migration vorgefunden hat.
        $db->prepare(
            "INSERT INTO breeding_stations (name, phone, email, is_published) VALUES (?, ?, ?, 1)"
        )->execute([$name, '0555 777x', "alt-{$unique}@example.com"]);
        $stmt = $db->prepare("SELECT id, contact_public FROM breeding_stations WHERE name = ?");
        $stmt->execute([$name]);
        $row = $stmt->fetch();

        $this->assertSame(1, (int)$row['contact_public'], 'Der Vorgabewert der Spalte muss 1 sein');

        $seite = $this->newClient()->get('/station?id=' . (int)$row['id']);
        $this->assertStringContainsString('0555 777x', $seite->body, 'Bestandsangaben bleiben öffentlich');
    }
}
