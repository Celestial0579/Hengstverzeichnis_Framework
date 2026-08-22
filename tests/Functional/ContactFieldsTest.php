<?php
// tests/Functional/ContactFieldsTest.php

namespace Tests\Functional;

use App\Database;

/**
 * HTTP-Funktionstests für die strukturierten Kontaktfelder (#188, seit #336 an
 * `contacts`): Adresse, E-Mail und Mitgliedsstatus werden über das echte
 * Formular gespeichert und geändert; auf der Pferde-Detailseite erscheinen NUR
 * Ort, Bundesland, Land und Mitgliedsstatus - Straße, Hausnummer, PLZ und
 * E-Mail dürfen das Admin nie verlassen (Negativ-Assertions).
 *
 * Die Zuordnungszeile eines Pferds kennt bewusst KEINE Ausnahme über
 * `contact_public`: Sie ist keine Kontaktseite. Wer die freigegebenen Daten
 * sehen will, folgt dem Verweis auf /kontakt?id= - und was dort erscheint,
 * hält ContactPublicTest fest.
 */
class ContactFieldsTest extends FunctionalTestCase {

    public function testStructuredFieldsRoundtripAndPublicExposure(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $contactName = "Kontakt Strukturiert {$unique}";
        $horseName = "Kontakt Testpferd {$unique}";
        $db = Database::getInstance();

        // 1. Kontakt mit allen Feldern über das echte Formular anlegen.
        $form = $admin->get('/admin/contacts/create');
        $response = $admin->post('/admin/contacts/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $contactName,
            'street' => 'Fjordweg',
            // 'x' kommt in Hex-Strings (CSRF-Token, Bild-Hashes) nie vor -
            // macht die Negativ-Assertions unten zufallssicher.
            'house_number' => '7x',
            'postal_code' => '2496x',
            'city' => 'Glücksburg',
            'state' => 'Schleswig-Holstein',
            'country' => 'NO',
            'email' => "person-{$unique}@example.com",
            // #349: Das Feld gibt es im Formular nicht mehr. Es wird hier
            // trotzdem MITGESENDET - ein entferntes Feld darf nicht ueber
            // einen von Hand gebauten POST zurueckkommen. Die Spalte bleibt
            // bis zum Release nach v0.9.0 bestehen, waere also beschreibbar,
            // wenn CONTACT_FIELDS sie noch fuehrte.
            'membership_status' => 'Nichtmitglied NO',
            'phone' => '01234 5678x',
            'mobile' => '0170 12345x',
            'website' => 'https://beispiel-hof.example/x',
            'contact_info' => 'Tel: 0170-0000000',
            'is_published' => '1',
        ]);
        $this->assertSame('/admin/contacts?success=created', $response->location());

        $stmt = $db->prepare("SELECT * FROM contacts WHERE name = ?");
        $stmt->execute([$contactName]);
        $contact = $stmt->fetch();
        $this->assertNotFalse($contact);
        $this->assertSame('Fjordweg', $contact['street']);
        $this->assertSame('7x', $contact['house_number']);
        $this->assertSame('2496x', $contact['postal_code']);
        $this->assertSame('Glücksburg', $contact['city']);
        $this->assertSame('Schleswig-Holstein', $contact['state']);
        $this->assertSame('NO', $contact['country']);
        $this->assertSame("person-{$unique}@example.com", $contact['email']);
        $this->assertNull(
            $contact['membership_status'],
            'Das mit #349 entfernte Feld darf ueber einen gebauten POST nicht zurueckkommen'
        );
        $this->assertSame('01234 5678x', $contact['phone']);
        $this->assertSame('0170 12345x', $contact['mobile']);
        $this->assertSame('https://beispiel-hof.example/x', $contact['website']);
        $this->assertSame('Tel: 0170-0000000', $contact['contact_info']);

        // 2. Update: Feld ändern und eines leeren (leer -> NULL).
        $editPage = $admin->get('/admin/contacts/edit?id=' . $contact['id']);
        $response = $admin->post('/admin/contacts/update', [
            'csrf_token' => $editPage->formField('csrf_token') ?? '',
            'id' => (string)$contact['id'],
            'name' => $contactName,
            'street' => 'Fjordallee',
            'house_number' => '7x',
            'postal_code' => '2496x',
            'city' => 'Glücksburg',
            'state' => '',
            'country' => 'NO',
            'email' => '',
            'membership_status' => 'Mitglied',   // #349, siehe oben - auch update() darf es nicht annehmen
            // Telefon/Mobil/Website (#293) gehen denselben Weg: mitgesendet
            // bleiben sie erhalten, 'mobile' bleibt hier bewusst weg und muss
            // damit - wie email und state - auf NULL fallen.
            'phone' => '01234 5678x',
            'website' => 'https://beispiel-hof.example/x',
            'contact_info' => 'Tel: 0170-0000000',
            'is_published' => '1',
        ]);
        $this->assertSame('/admin/contacts?success=updated', $response->location());
        $stmt = $db->prepare("SELECT street, state, email, phone, mobile, website, membership_status FROM contacts WHERE id = ?");
        $stmt->execute([$contact['id']]);
        $updated = $stmt->fetch();
        $this->assertSame('Fjordallee', $updated['street']);
        $this->assertNull($updated['email'], 'Leeres Formularfeld muss NULL speichern');
        $this->assertNull($updated['state'], 'Auch Bundesland/Kanton muss beim Leeren NULL werden (#256)');
        $this->assertNull($updated['membership_status'], 'Auch update() nimmt das entfernte Feld nicht an (#349)');
        $this->assertSame('01234 5678x', $updated['phone']);
        $this->assertSame('https://beispiel-hof.example/x', $updated['website']);
        $this->assertNull($updated['mobile'], 'Ein nicht mitgesendetes Feld muss auch bei den neuen Spalten NULL werden');

        // E-Mail für die öffentliche Negativ-Prüfung und das Bundesland für die
        // Positiv-Prüfung unten wieder setzen. Zusammen mit contact_public = 1:
        // Die Zuordnungszeile eines Pferds zeigt die zustellbaren Angaben auch
        // MIT Freigabe nicht - ohne sie prüften die Negativ-Assertions unten
        // nur, dass die Spalten leer sind.
        $db->prepare("UPDATE contacts SET email = ?, state = 'Schleswig-Holstein', contact_public = 1 WHERE id = ?")
            ->execute(["person-{$unique}@example.com", $contact['id']]);

        // 3. Pferd anlegen und den Kontakt als Züchter zuordnen. Das
        //    Formularfeld heißt seit #336 contact_id wie die Spalte.
        $form = $admin->get('/admin/horses/create');
        $response = $admin->post('/admin/horses/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $horseName,
            'status' => 'active',
            'is_published' => '1',
            'persons[0][contact_id]' => (string)$contact['id'],
            'persons[0][role]' => 'breeder',
        ]);
        $this->assertSame('/admin/horses?success=created', $response->location());
        $stmt = $db->prepare("SELECT id FROM horses WHERE name = ?");
        $stmt->execute([$horseName]);
        $horseId = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $horseId);

        // 4. Öffentliche Detailseite: Ort/Bundesland/Land erscheinen,
        // Straße/Hausnummer/PLZ/E-Mail dürfen NICHT im HTML stehen.
        // Der Mitgliedsstatus stand bis v0.8 mit in dieser Zeile und ist mit
        // #349 entfallen - geprüft in
        // testPublicContactPageIsReachableFromHorseAndHidesInternalFields().
        //
        // Bundesland/Kanton (#256) steht bewusst auf der öffentlichen Seite: Die
        // Trennlinie verläuft nicht bei der Feldanzahl, sondern zwischen
        // zustellbarer Anschrift (intern) und grober geografischer Verortung
        // (öffentlich). Ein Bundesland ist gröber als der ohnehin sichtbare Ort.
        $guest = $this->newClient();
        $detail = $guest->get('/horse?id=' . $horseId);
        $this->assertSame(200, $detail->statusCode);
        $this->assertStringContainsString('Glücksburg, Schleswig-Holstein, NO', $detail->body);
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

        // 4b. Länderflagge (#240): ein Kontakt mit country='Dänemark'
        // erscheint auf der Detailseite mit 🇩🇰 und dem gespeicherten
        // Freitext als title-Tooltip; der ISO-Code 'NO' des ersten Kontakts
        // ergibt 🇳🇴. Beide Kontakte bleiben dafür verknüpft (der Update-Pfad
        // ersetzt horse_persons komplett).
        $flagContactName = "Kontakt Flagge {$unique}";
        $form = $admin->get('/admin/contacts/create');
        $response = $admin->post('/admin/contacts/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $flagContactName,
            'country' => 'Dänemark',
            'is_published' => '1',
        ]);
        $this->assertSame('/admin/contacts?success=created', $response->location());
        $stmt = $db->prepare("SELECT id FROM contacts WHERE name = ?");
        $stmt->execute([$flagContactName]);
        $flagContactId = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $flagContactId);

        $editHorse = $admin->get('/admin/horses/edit?id=' . $horseId);
        $response = $admin->post('/admin/horses/update', [
            'csrf_token' => $editHorse->formField('csrf_token') ?? '',
            'id' => (string)$horseId,
            'name' => $horseName,
            'status' => 'active',
            'is_published' => '1',
            'persons[0][contact_id]' => (string)$contact['id'],
            'persons[0][role]' => 'breeder',
            'persons[1][contact_id]' => (string)$flagContactId,
            'persons[1][role]' => 'owner',
        ]);
        $this->assertSame('/admin/horses?success=updated', $response->location());

        $detail = $guest->get('/horse?id=' . $horseId);
        $this->assertSame(200, $detail->statusCode);
        $this->assertStringContainsString('🇩🇰', $detail->body, "country='Dänemark' muss als 🇩🇰 erscheinen");
        $this->assertStringContainsString('title="Dänemark"', $detail->body, 'Der Länder-Freitext muss als title-Tooltip an der Flagge stehen');
        $this->assertStringContainsString('🇳🇴', $detail->body, "Der direkte ISO-Code 'NO' muss als 🇳🇴 erscheinen");

        // 5. Unveröffentlichter Kontakt: auch die neuen Felder verschwinden
        // komplett von der öffentlichen Seite (#121-Zusicherung gilt weiter).
        $db->prepare("UPDATE contacts SET is_published = 0 WHERE id = ?")->execute([$contact['id']]);
        $detail = $guest->get('/horse?id=' . $horseId);
        $this->assertStringNotContainsString('Glücksburg', $detail->body, 'Felder unveröffentlichter Kontakte dürfen öffentlich nicht erscheinen');
        $this->assertStringNotContainsString('Mitglied', $detail->body);
        $this->assertStringNotContainsString('https://beispiel-hof.example/x', $detail->body, 'Auch die Website verschwindet mit der Veröffentlichung');
    }

    /**
     * Die oeffentliche Kontaktseite (#293, seit #336 unter /kontakt) und der
     * Weg dorthin: Ein Besucher klickt auf der Pferdeseite den Namen an und
     * landet auf dem Kontakt.
     *
     * Geprueft wird beides - dass die Seite die oeffentlichen Angaben zeigt
     * UND dass sie die internen NICHT zeigt. Der Controller waehlt die Spalten
     * einzeln aus; ein spaeteres 'SELECT *' waere genau der Rueckfall in den
     * Fehler, den #293 behoben hat - und seit die Trennung der Tabellen
     * weggefallen ist, gaebe es dann auch keine zweite Huerde mehr.
     */
    public function testPublicContactPageIsReachableFromHorseAndHidesInternalFields(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $contactName = "Kontakt Seite {$unique}";
        $horseName = "Pferd Seite {$unique}";

        $form = $admin->get('/admin/contacts/create');
        $admin->post('/admin/contacts/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $contactName,
            'city' => 'Flensburg',
            'country' => 'DE',
            'website' => 'https://zuchthof.example/x',
            'email' => "geheim-{$unique}@example.com",
            'phone' => '0999 111x',
            'mobile' => '0888 222x',
            'street' => 'Geheimweg',
            'contact_info' => 'Interne Notiz Zebra',
            'is_breeder' => '1',
            'is_published' => '1',
        ]);
        $stmt = $db->prepare("SELECT id FROM contacts WHERE name = ?");
        $stmt->execute([$contactName]);
        $contactId = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $contactId);

        // #349: Die SPALTE gibt es noch - sie faellt erst im Release nach
        // v0.9.0, damit ein Betreiber die Werte sichern kann. Ein
        // BESTANDSWERT darf deshalb genau ab jetzt nirgends mehr nach aussen
        // dringen: nicht auf der Kontaktseite und nicht in der Personenzeile
        // der Pferdeseite. Der Wert wird direkt in die Tabelle geschrieben,
        // weil das Formular ihn nicht mehr annimmt (siehe oben).
        $altwert = "Mitgliedsmarker-{$unique}";
        $db->prepare("UPDATE contacts SET membership_status = ? WHERE id = ?")->execute([$altwert, $contactId]);

        $form = $admin->get('/admin/horses/create');
        $admin->post('/admin/horses/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $horseName,
            'status' => 'active',
            'is_published' => '1',
            'persons[0][contact_id]' => (string)$contactId,
            'persons[0][role]' => 'breeder',
        ]);
        $stmt = $db->prepare("SELECT id FROM horses WHERE name = ?");
        $stmt->execute([$horseName]);
        $horseId = (int)$stmt->fetchColumn();

        $guest = $this->newClient();

        // 1. Von der Pferdeseite fuehrt ein Verweis zum Kontakt.
        $detail = $guest->get('/horse?id=' . $horseId);
        $this->assertSame(200, $detail->statusCode);
        $this->assertStringContainsString('/kontakt?id=' . $contactId, $detail->body, 'Der Kontaktname muss verlinkt sein');
        $this->assertStringNotContainsString(
            $altwert,
            $detail->body,
            'Die Personenzeile der Pferdeseite fuehrt den Mitgliedsstatus seit #349 nicht mehr'
        );

        // 2. Die Kontaktseite zeigt die oeffentlichen Angaben ...
        $page = $guest->get('/kontakt?id=' . $contactId);
        $this->assertSame(200, $page->statusCode);
        $this->assertStringContainsString($contactName, $page->body);
        $this->assertStringContainsString('Flensburg', $page->body);
        $this->assertStringContainsString('https://zuchthof.example/x', $page->body);
        $this->assertStringNotContainsString(
            $altwert,
            $page->body,
            'Ein Bestandswert in membership_status darf die Kontaktseite nicht mehr erreichen (#349)'
        );
        // ... das Zuechter-Kennzeichen ...
        $this->assertStringContainsString('Züchter', $page->body, 'Das Kennzeichen gehoert auf die Seite');
        // ... und das zugeordnete Pferd.
        $this->assertStringContainsString($horseName, $page->body);
        $this->assertStringContainsString('/horse?id=' . $horseId, $page->body);

        // 3. Und keine einzige interne Angabe. Der Kontakt wurde ohne Haekchen
        //    angelegt, ist also nicht freigegeben - die zustellbaren Felder
        //    kommen gar nicht erst aus der Datenbank.
        $this->assertStringNotContainsString("geheim-{$unique}@example.com", $page->body, 'E-Mail ist ohne Freigabe intern');
        $this->assertStringNotContainsString('0999 111x', $page->body, 'Telefon ist ohne Freigabe intern');
        $this->assertStringNotContainsString('0888 222x', $page->body, 'Mobil ist ohne Freigabe intern');
        $this->assertStringNotContainsString('Geheimweg', $page->body, 'Strasse ist ohne Freigabe intern');
        $this->assertStringNotContainsString('Interne Notiz Zebra', $page->body, 'contact_info ist intern');

        // 3b. Mit Freigabe kommen die zustellbaren Angaben dazu - contact_info
        //     aber NICHT. Es ist das einzige Feld, das nie oeffentlich wird
        //     (siehe docs/kontaktliste-umstellung.md, Datenschutz-Grenze):
        //     Das Admin-Formular laedt ausdruecklich dazu ein, dort Notizen
        //     abzulegen, und genau daran ist #293 gescheitert. Ohne diese
        //     Zusicherung waere die Freigabe ein Schalter, der ein
        //     unspezifisches Freitextfeld mit ins Netz nimmt.
        $db->prepare("UPDATE contacts SET contact_public = 1 WHERE id = ?")->execute([$contactId]);
        $freigegeben = $guest->get('/kontakt?id=' . $contactId);
        $this->assertStringContainsString("geheim-{$unique}@example.com", $freigegeben->body, 'Mit Freigabe erscheint die E-Mail');
        $this->assertStringContainsString('0999 111x', $freigegeben->body);
        $this->assertStringContainsString('Geheimweg', $freigegeben->body);
        $this->assertStringNotContainsString(
            'Interne Notiz Zebra',
            $freigegeben->body,
            'contact_info bleibt auch MIT Freigabe intern - es ist von keiner Freigabe erfasst'
        );

        // 4. Unveroeffentlichte Kontakte haben keine Seite.
        $db->prepare("UPDATE contacts SET is_published = 0 WHERE id = ?")->execute([$contactId]);
        $this->assertSame(404, $guest->get('/kontakt?id=' . $contactId)->statusCode);

        // 5. Und der Verweis auf der Pferdeseite verschwindet mit ihr.
        $detail = $guest->get('/horse?id=' . $horseId);
        $this->assertStringNotContainsString('/kontakt?id=' . $contactId, $detail->body);
    }

    /**
     * Ein Website-Freitext ist genau das - Freitext. Steht dort ein
     * `javascript:`-Ziel, darf daraus kein Verweis werden: Das Feld pflegt der
     * Admin-Bereich, ausgegeben wird es auf einer oeffentlichen Seite, ein
     * Redakteurskonto genuegte sonst fuer gespeichertes JavaScript bei jedem
     * Besucher. htmlspecialchars() allein faengt das NICHT ab, es kodiert nur
     * den Attributwert - siehe App\Helper\ExternalUrl.
     *
     * Seit #336 gibt es davor eine ERSTE Huerde: Beim Zusammenlegen der beiden
     * Formulare hat die strengere Fassung gewonnen, und die stammt aus dem
     * Stationsformular - eine Adresse ohne http:// oder https:// wird gar
     * nicht mehr gespeichert. Beide Huerden werden hier geprueft, und zwar
     * getrennt: Die Ausgabe ist die entscheidende, denn im BESTAND stehen
     * solche Werte weiterhin. Die Personen kannten die Pruefung bis v0.7
     * nicht, und der CSV-Import prueft ohnehin nicht - was einmal in der
     * Tabelle steht, bleibt dort, bis es jemand anfasst.
     */
    public function testMaliciousWebsiteSchemeIsNotLinked(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $contactName = "Kontakt Schema {$unique}";
        $horseName = "Pferd Schema {$unique}";

        // 1. Erste Huerde: Das Formular nimmt die Adresse gar nicht erst an.
        $form = $admin->get('/admin/contacts/create');
        $abgelehnt = $admin->post('/admin/contacts/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $contactName,
            'website' => 'javascript:alert(1)',
            'is_published' => '1',
        ]);
        $this->assertStringContainsString(
            'Website muss eine gültige Adresse',
            $abgelehnt->body,
            'Die strengere Website-Pruefung aus dem Stationsformular gilt seit #336 fuer alle Kontakte'
        );
        $stmt = $db->prepare("SELECT COUNT(*) FROM contacts WHERE name = ?");
        $stmt->execute([$contactName]);
        $this->assertSame(0, (int)$stmt->fetchColumn(), 'Ein abgelehntes Formular darf nichts anlegen');

        // 2. Zweite Huerde, die eigentliche: derselbe Wert im BESTAND. So sieht
        //    eine Zeile aus, die aus `persons` uebernommen wurde (dort gab es
        //    die Pruefung nie) oder die aus einem CSV-Import stammt.
        $db->prepare("INSERT INTO contacts (name, website, is_published) VALUES (?, 'javascript:alert(1)', 1)")
            ->execute([$contactName]);
        $contactId = (int)$db->lastInsertId();

        $form = $admin->get('/admin/horses/create');
        $admin->post('/admin/horses/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $horseName,
            'status' => 'active',
            'is_published' => '1',
            'persons[0][contact_id]' => (string)$contactId,
            'persons[0][role]' => 'breeder',
        ]);
        $stmt = $db->prepare("SELECT id FROM horses WHERE name = ?");
        $stmt->execute([$horseName]);
        $horseId = (int)$stmt->fetchColumn();

        $guest = $this->newClient();
        $detail = $guest->get('/horse?id=' . $horseId);
        $this->assertSame(200, $detail->statusCode);
        // Der Wert bleibt gespeichert (Freitext-Philosophie), aber er wird
        // nicht zum Verweisziel.
        $this->assertStringNotContainsString('href="javascript:', $detail->body);
        $this->assertStringNotContainsString('javascript:alert', $detail->body);

        // Dieselbe Zusicherung auf der Kontaktseite: Dort steht die Website in
        // einer eigenen Zeile, also in einem zweiten Ausgabepfad - und der
        // zaehlt genauso. Bis #336 war das eine andere View.
        $page = $guest->get('/kontakt?id=' . $contactId);
        $this->assertSame(200, $page->statusCode);
        $this->assertStringNotContainsString('href="javascript:', $page->body);
        $this->assertStringNotContainsString('javascript:alert', $page->body);
    }
}
