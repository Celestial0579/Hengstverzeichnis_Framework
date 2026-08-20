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
 * Seit #336 stehen Personen und Deckstationen in EINER Tabelle, hinter EINEM
 * Formular - und damit gibt es nur noch EINE Vorgabe: `contacts.contact_public`
 * ist 0. Die frühere Vorgabe 1 der Deckstationen entfällt, und das ist der
 * Sinn der Änderung: Ein vorbelegtes Häkchen fällt niemandem auf, und die
 * nächste Privatperson, die jemand über dieses gemeinsame Formular anlegt,
 * stünde sonst mit Telefonnummer im Netz. Seit dem Umbau ist diese eine Spalte
 * der ganze Schutz - vorher trug ihn die Trennung der Tabellen mit.
 *
 * Die Zusicherung, die aus der alten Vorgabe 1 übrig bleibt, ist deshalb eine
 * andere und steht weiter unten: Der BESTAND behält, was er hatte. Die
 * Migration übernimmt `contact_public` zeilenweise (SchemaMigrator, Schritt
 * `336_contacts_uebernahme`) - eine Deckstation, deren Anschrift vor dem Umbau
 * öffentlich stand, steht danach immer noch öffentlich da. Eine Umstellung
 * darf nichts wegnehmen, was vorher da war.
 */
class ContactPublicTest extends FunctionalTestCase {

    public function testPersonContactStaysInternalUntilExplicitlyReleased(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $name = "Freigabe {$unique}";

        // Ohne Häkchen - die Vorgabe.
        $form = $admin->get('/admin/contacts/create');
        $admin->post('/admin/contacts/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $name,
            'city' => 'Kiel',
            'email' => "kontakt-{$unique}@example.com",
            'phone' => '0431 111x',
            'mobile' => '0170 222x',
            'is_published' => '1',
        ]);
        $stmt = $db->prepare("SELECT id, contact_public FROM contacts WHERE name = ?");
        $stmt->execute([$name]);
        $person = $stmt->fetch();
        $personId = (int)$person['id'];
        $this->assertSame(0, (int)$person['contact_public'], 'Ohne Häkchen bleibt die Freigabe aus');

        $guest = $this->newClient();
        $seite = $guest->get('/kontakt?id=' . $personId);
        $this->assertSame(200, $seite->statusCode);
        $this->assertStringContainsString('Kiel', $seite->body, 'Die grobe Verortung bleibt öffentlich');
        $this->assertStringNotContainsString("kontakt-{$unique}@example.com", $seite->body, 'E-Mail ohne Freigabe: intern');
        $this->assertStringNotContainsString('0431 111x', $seite->body, 'Telefon ohne Freigabe: intern');
        $this->assertStringNotContainsString('0170 222x', $seite->body, 'Mobil ohne Freigabe: intern');

        // Jetzt mit Häkchen.
        $editPage = $admin->get('/admin/contacts/edit?id=' . $personId);
        $response = $admin->post('/admin/contacts/update', [
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
        $this->assertSame('/admin/contacts?success=updated', $response->location());

        $seite = $this->newClient()->get('/kontakt?id=' . $personId);
        $this->assertStringContainsString("kontakt-{$unique}@example.com", $seite->body, 'Mit Freigabe erscheint die E-Mail');
        $this->assertStringContainsString('0431 111x', $seite->body);
        $this->assertStringContainsString('0170 222x', $seite->body);

        // Und wieder abwählbar - eine Freigabe muss zurücknehmbar sein.
        $editPage = $admin->get('/admin/contacts/edit?id=' . $personId);
        $admin->post('/admin/contacts/update', [
            'csrf_token' => $editPage->formField('csrf_token') ?? '',
            'id' => (string)$personId,
            'name' => $name,
            'city' => 'Kiel',
            'email' => "kontakt-{$unique}@example.com",
            'is_published' => '1',
        ]);
        $seite = $this->newClient()->get('/kontakt?id=' . $personId);
        $this->assertStringNotContainsString("kontakt-{$unique}@example.com", $seite->body, 'Zurückgenommene Freigabe wirkt sofort');
    }

    /**
     * Ein Kontakt vom Zuschnitt einer Deckstation - Ansprechpartner und
     * Geschäftsanschrift - geht seit #336 denselben Weg: Auch hier ist die
     * Vorgabe 0, und auch hier erscheint erst mit dem Häkchen etwas.
     *
     * Das ist die Stelle, an der sich die Änderung entscheidet. Bis v0.7 lag
     * dieser Datensatz in `breeding_stations` mit Vorgabe 1, weil eine
     * Geschäftsadresse ohnehin öffentlich ist. Das Formular, über das er jetzt
     * entsteht, ist aber dasselbe, mit dem jemand eine Privatperson anlegt -
     * eine vorbelegte Freigabe könnte nicht wissen, welcher der beiden Fälle
     * gerade vor ihr sitzt. Geprüft wird deshalb beides: die sichere Vorgabe
     * UND dass die Freigabe die stationstypischen Felder wirklich öffnet.
     */
    public function testStationLikeContactNeedsAnExplicitReleaseToo(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $name = "Station Freigabe {$unique}";
        $ansprechpartner = "Frau Beispiel {$unique}";

        $form = $admin->get('/admin/contacts/create');
        $admin->post('/admin/contacts/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $name,
            'contact_person' => $ansprechpartner,
            'address' => "Stallweg 3x\n24000 Kiel",
            'phone' => '04321 999x',
            'email' => "station-{$unique}@example.com",
            'is_published' => '1',
        ]);
        $stmt = $db->prepare("SELECT id, contact_public FROM contacts WHERE name = ?");
        $stmt->execute([$name]);
        $station = $stmt->fetch();
        $stationId = (int)$station['id'];
        $this->assertGreaterThan(0, $stationId);
        $this->assertSame(
            0,
            (int)$station['contact_public'],
            'Auch ein neu angelegter Stationskontakt ist ohne Häkchen nicht freigegeben - die alte Vorgabe 1 gibt es nicht mehr'
        );

        $seite = $this->newClient()->get('/kontakt?id=' . $stationId);
        $this->assertSame(200, $seite->statusCode);
        $this->assertStringNotContainsString('04321 999x', $seite->body, 'Ohne Freigabe bleibt auch der Stationskontakt intern');
        $this->assertStringNotContainsString("station-{$unique}@example.com", $seite->body);
        $this->assertStringNotContainsString($ansprechpartner, $seite->body, 'Der Ansprechpartner ist eine zustellbare Angabe');
        $this->assertStringNotContainsString('Stallweg 3x', $seite->body, 'Die Anschrift ebenso');

        // Häkchen hinein - jetzt öffentlich, samt der Felder, die es nur bei
        // Betrieben gibt (Ansprechpartner, Freitext-Anschrift).
        $editPage = $admin->get('/admin/contacts/edit?id=' . $stationId);
        $response = $admin->post('/admin/contacts/update', [
            'csrf_token' => $editPage->formField('csrf_token') ?? '',
            'id' => (string)$stationId,
            'name' => $name,
            'contact_person' => $ansprechpartner,
            'address' => "Stallweg 3x\n24000 Kiel",
            'phone' => '04321 999x',
            'email' => "station-{$unique}@example.com",
            'contact_public' => '1',
            'is_published' => '1',
        ]);
        $this->assertSame('/admin/contacts?success=updated', $response->location());

        $seite = $this->newClient()->get('/kontakt?id=' . $stationId);
        $this->assertStringContainsString('04321 999x', $seite->body, 'Mit Freigabe erscheint der Stationskontakt');
        $this->assertStringContainsString("station-{$unique}@example.com", $seite->body);
        $this->assertStringContainsString($ansprechpartner, $seite->body);
        $this->assertStringContainsString('Stallweg 3x', $seite->body);
    }

    /**
     * Bestandsdaten: Eine Deckstation, die es vor der Zusammenführung schon
     * gab, muss ihre öffentlichen Kontaktdaten behalten. Die Migration nimmt
     * `contact_public` zeilenweise mit (SchemaMigrator, Schritt
     * `336_contacts_uebernahme`), sie darf nichts wegnehmen, was vorher da war.
     *
     * Nachgestellt wird genau das Ergebnis dieses Schritts: eine Zeile in
     * `contacts` mit übernommener Freigabe plus ihr Eintrag in
     * `contact_id_map`. Beides gehört zusammen - die alte Adresse
     * `/station?id=` steht in Suchmaschinen und wird über die Abbildung
     * dauerhaft weitergeleitet. Ein Bestandsbesucher soll auf demselben Weg
     * dieselben Angaben vorfinden wie vor dem Umbau.
     *
     * Die Gegenprobe steht mit im Test: Eine NEU angelegte Zeile ohne Angabe
     * bekommt die sichere Vorgabe 0. Der übernommene Wert ist eine Aussage
     * über den Bestand, nicht über die Spalte.
     */
    public function testMigratedStationKeepsItsPublicContactWhileTheDefaultIsSafe(): void {
        $db = Database::getInstance();
        $unique = uniqid();
        $bestandName = "Bestandsstation {$unique}";
        $neuName = "Neuer Kontakt {$unique}";

        // 1. Die Vorgabe der Spalte, ohne jede Angabe: 0.
        $db->prepare("INSERT INTO contacts (name, phone, is_published) VALUES (?, ?, 1)")
            ->execute([$neuName, '0555 000x']);
        $neuId = (int)$db->lastInsertId();
        $stmt = $db->prepare("SELECT contact_public FROM contacts WHERE id = ?");
        $stmt->execute([$neuId]);
        $this->assertSame(0, (int)$stmt->fetchColumn(), 'Der Vorgabewert der Spalte muss 0 sein');
        $this->assertStringNotContainsString(
            '0555 000x',
            $this->newClient()->get('/kontakt?id=' . $neuId)->body,
            'Ohne Freigabe steht die Nummer nicht im Netz'
        );

        // 2. Der Bestand: So sieht die Zeile aus, die die Migration aus einer
        //    Deckstation mit öffentlicher Geschäftsadresse gemacht hat -
        //    contact_public = 1 übernommen, alte Kennung in contact_id_map.
        //    Die alte Kennung ist bewusst hoch gewählt: Sie stammt aus der
        //    früheren Tabelle breeding_stations und hat mit der neuen
        //    Kontakt-ID nichts zu tun.
        $alteStationsId = random_int(100000, 999999);
        $db->prepare(
            "INSERT INTO contacts (name, phone, email, contact_public, is_published) VALUES (?, ?, ?, 1, 1)"
        )->execute([$bestandName, '0555 777x', "alt-{$unique}@example.com"]);
        $bestandId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO contact_id_map (old_type, old_id, contact_id) VALUES ('station', ?, ?)")
            ->execute([$alteStationsId, $bestandId]);

        $stmt = $db->prepare("SELECT contact_public FROM contacts WHERE id = ?");
        $stmt->execute([$bestandId]);
        $this->assertSame(1, (int)$stmt->fetchColumn(), 'Der übernommene Bestandswert darf nicht auf die Vorgabe zurückfallen');

        $guest = $this->newClient();
        $seite = $guest->get('/kontakt?id=' . $bestandId);
        $this->assertSame(200, $seite->statusCode);
        $this->assertStringContainsString('0555 777x', $seite->body, 'Bestandsangaben bleiben öffentlich');
        $this->assertStringContainsString("alt-{$unique}@example.com", $seite->body);

        // 3. Und der alte Weg dorthin funktioniert weiter: /station?id= leitet
        //    dauerhaft (301) auf die Kontaktseite um. Ohne das wäre die
        //    Zusicherung aus 2. für jeden wertlos, der über einen alten Link
        //    oder eine Suchmaschine kommt.
        $alt = $guest->get('/station?id=' . $alteStationsId);
        $this->assertSame(301, $alt->statusCode, 'Die alte Stationsadresse muss dauerhaft weiterleiten');
        $this->assertSame('/kontakt?id=' . $bestandId, $alt->location());
    }
}
