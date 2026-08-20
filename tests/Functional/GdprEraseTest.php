<?php
// tests/Functional/GdprEraseTest.php

namespace Tests\Functional;

use App\Database;

/**
 * HTTP-Funktionstests für die DSGVO-Verarbeitung (#135): Löschung und
 * Anonymisierung einer Person über /admin/gdpr müssen (a) nur die Person
 * treffen (verknüpfte Pferde bleiben erhalten, nur die horse_persons-Zeilen
 * verschwinden per ON DELETE CASCADE), (b) den Antrag auf "processed" setzen,
 * (c) einen Audit-Log-Eintrag hinterlassen und (d) Nicht-Admins mit 403
 * abweisen (requireAdmin() im GdprController-Konstruktor).
 *
 * Seit #336 stehen Personen und Deckstationen in EINER Tabelle `contacts`.
 * Für diesen Test ändert das zwei Dinge, und beide sind Verschärfungen:
 *
 * 1. Die Spaltenmenge ist gewachsen - `contact_person` und `address` kamen
 *    aus `breeding_stations` dazu. Beides ist personenbezogen (der
 *    Ansprechpartner IST ein Mensch, die Freitext-Anschrift ist zustellbar),
 *    beides muss die Anonymisierung deshalb nullen.
 * 2. Ein Kontakt hängt jetzt über ZWEI Steckplätze an einem Pferd
 *    (horse_persons.contact_id und .station_contact_id) mit verschiedenem
 *    Fremdschlüsselverhalten. Eine Löschung muss beide auflösen - und zwar
 *    unterschiedlich: CASCADE nimmt die Personenzeile mit, SET NULL lässt die
 *    Stationszeile stehen und entfernt nur den Verweis auf den Menschen.
 */
class GdprEraseTest extends FunctionalTestCase {

    use DsgvoFormHelper;

    private function db(): \PDO {
        return Database::getInstance();
    }

    /**
     * Legt Person + verknüpftes Pferd direkt in der DB an und reicht über den
     * echten öffentlichen HTTP-Flow (/dsgvo) einen Löschantrag ein.
     *
     * @return array{personId: int, horseId: int, requestId: int}
     */
    private function createPersonWithHorseAndRequest(string $personName, string $email): array {
        $db = $this->db();

        // Alle strukturierten PII-Felder (#188, state seit #256) befüllen - die
        // Anonymisierung unten muss jedes einzelne davon nullen.
        // phone/mobile/website (#293) gehoeren mit befuellt: Die Pruefung unten
        // leitet die Feldliste aus dem Schema ab, eine leer gelassene neue
        // Spalte waere also schon vor der Anonymisierung NULL und die
        // Zusicherung damit wertlos.
        // Dasselbe gilt seit #336 fuer contact_person und address, die beiden
        // aus breeding_stations uebernommenen Spalten - gerade sie duerfen
        // nicht leer bleiben, sonst pruefte der Test die eine Erweiterung
        // nicht, wegen der die Feldliste ueberhaupt gewachsen ist.
        $stmt = $db->prepare("INSERT INTO contacts (name, contact_person, contact_info, street, house_number, postal_code, city, state, country, address, email, phone, mobile, website, membership_status, is_published) VALUES (?, 'Ansprechpartner Erika Muster', 'Tel. 0170-1234567', 'Musterweg', '3', '12345', 'Musterstadt', 'Schleswig-Holstein', 'DE', 'Weideweg 1\n24000 Kiel', ?, '01234 56789', '0170 1234567', 'https://beispiel.example', 'Mitglied', 1)");
        $stmt->execute([$personName, $email]);
        $personId = (int)$db->lastInsertId();

        $stmt = $db->prepare("INSERT INTO horses (name, is_published) VALUES (?, 1)");
        $stmt->execute(['DSGVO-Testpferd ' . uniqid()]);
        $horseId = (int)$db->lastInsertId();

        $stmt = $db->prepare("INSERT INTO horse_persons (horse_id, contact_id, role) VALUES (?, ?, 'owner')");
        $stmt->execute([$horseId, $personId]);

        // Das DSGVO-Formular ist seit #258 spam-geschuetzt: Die Rechenaufgabe
        // wird geloest (nicht umgangen, siehe DsgvoFormHelper), und die beiden
        // IP-Zaehler werden zurueckgesetzt - alle Tests teilen sich 127.0.0.1.
        self::resetDsgvoRateLimit();
        $public = $this->newClient();
        $dsgvoPage = $public->get('/dsgvo');
        $captchaAnswer = (string)$this->solveCaptcha($dsgvoPage);
        $this->waitForMinimumSolveTime();
        $submitResponse = $public->post('/dsgvo', [
            'csrf_token' => $dsgvoPage->formField('csrf_token') ?? '',
            'name' => $personName,
            'email' => $email,
            'request_type' => 'deletion',
            'message' => 'Bitte alle meine Daten löschen.',
            'captcha' => $captchaAnswer,
        ]);
        $this->assertSame('/dsgvo?success=1', $submitResponse->location(), "DSGVO-Antrag konnte nicht eingereicht werden, Body: {$submitResponse->body}");

        $stmt = $db->prepare("SELECT id FROM gdpr_requests WHERE email = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$email]);
        $requestId = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $requestId, 'DSGVO-Antrag wurde nicht in gdpr_requests gespeichert');

        return ['personId' => $personId, 'horseId' => $horseId, 'requestId' => $requestId];
    }

    public function testDeletePersonRemovesOnlyPersonAndMarksRequestProcessed(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $fixture = $this->createPersonWithHorseAndRequest("DSGVO Löschperson {$unique}", "gdpr-delete-{$unique}@example.com");
        $db = $this->db();

        // Zweiter Steckplatz (#336): Derselbe Kontakt ist bei einem WEITEREN
        // Pferd die Deckstation. Der Fremdschlüssel darauf ist ON DELETE SET
        // NULL, nicht CASCADE - die Aussage "dieses Pferd stand irgendwo"
        // gehört zur Pferdehistorie und überlebt die Löschung, während der
        // Mensch dahinter verschwindet. Ohne diese Zeile prüfte der Test nur
        // den halben Fremdschlüsselsatz.
        $stmt = $db->prepare("INSERT INTO horses (name, is_published) VALUES (?, 1)");
        $stmt->execute(["DSGVO-Stationspferd {$unique}"]);
        $stationHorseId = (int)$db->lastInsertId();
        $stmt = $db->prepare("INSERT INTO horse_persons (horse_id, contact_id, role, station_contact_id, breeding_station_text) VALUES (?, NULL, 'keeper', ?, 'Gestüt Musterstadt')");
        $stmt->execute([$stationHorseId, $fixture['personId']]);
        $stationRowId = (int)$db->lastInsertId();
        // Und über den zweiten Weg, den ein Kontakt als Deckstation nimmt:
        // horses.breeding_station_id zeigt seit #336 ebenfalls auf contacts.
        $db->prepare("UPDATE horses SET breeding_station_id = ? WHERE id = ?")
           ->execute([$fixture['personId'], $stationHorseId]);

        // Die Anfrage taucht in der Admin-Übersicht mit der gefundenen Person auf.
        $overview = $admin->get('/admin/gdpr');
        $this->assertStringContainsString("DSGVO Löschperson {$unique}", $overview->body);

        $deleteResponse = $admin->post('/admin/gdpr/delete-person', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'person_id' => (string)$fixture['personId'],
            'request_id' => (string)$fixture['requestId'],
        ]);
        $this->assertSame(
            '/admin/gdpr?success=deleted&person_id=' . $fixture['personId'],
            $deleteResponse->location(),
            "Löschung fehlgeschlagen, Body: {$deleteResponse->body}"
        );

        // Kontakt weg, Pferd bleibt, Verknüpfung per Cascade entfernt.
        $stmt = $db->prepare("SELECT COUNT(*) FROM contacts WHERE id = ?");
        $stmt->execute([$fixture['personId']]);
        $this->assertSame(0, (int)$stmt->fetchColumn(), 'Kontakt hätte gelöscht werden müssen');

        $stmt = $db->prepare("SELECT COUNT(*) FROM horses WHERE id = ?");
        $stmt->execute([$fixture['horseId']]);
        $this->assertSame(1, (int)$stmt->fetchColumn(), 'Das verknüpfte Pferd darf NICHT mitgelöscht werden');

        $stmt = $db->prepare("SELECT COUNT(*) FROM horse_persons WHERE contact_id = ?");
        $stmt->execute([$fixture['personId']]);
        $this->assertSame(0, (int)$stmt->fetchColumn(), 'horse_persons-Zeilen müssen per ON DELETE CASCADE verschwinden');

        // Steckplatz 1 (contact_id, CASCADE): Die ganze Zuordnungszeile ist
        // weg - ohne den Menschen sagt sie nichts mehr aus.
        $stmt = $db->prepare("SELECT COUNT(*) FROM horse_persons WHERE horse_id = ?");
        $stmt->execute([$fixture['horseId']]);
        $this->assertSame(0, (int)$stmt->fetchColumn(), 'Die Personen-Zuordnung fällt mit dem Kontakt');

        // Steckplatz 2 (station_contact_id, SET NULL): Die Zeile BLEIBT, nur
        // der Verweis auf den Menschen ist getilgt. Der Freitext trägt die
        // Aussage weiter - genau dafür ist das andere Fremdschlüsselverhalten
        // im Schema begründet.
        $stmt = $db->prepare("SELECT station_contact_id, breeding_station_text FROM horse_persons WHERE id = ?");
        $stmt->execute([$stationRowId]);
        $stationRow = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray(
            $stationRow,
            'Die Stationszeile darf NICHT mitgelöscht werden - sie ist Pferdehistorie, kein personenbezogenes Datum'
        );
        $this->assertNull($stationRow['station_contact_id'], 'Der Verweis auf den gelöschten Kontakt muss auf NULL gehen');
        $this->assertSame('Gestüt Musterstadt', $stationRow['breeding_station_text']);

        // Dasselbe für horses.breeding_station_id, das seit #336 ebenfalls auf
        // contacts zeigt (ON DELETE SET NULL).
        $stmt = $db->prepare("SELECT breeding_station_id FROM horses WHERE id = ?");
        $stmt->execute([$stationHorseId]);
        $stationHorse = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($stationHorse, 'Das Stationspferd darf NICHT mitgelöscht werden');
        $this->assertNull(
            $stationHorse['breeding_station_id'],
            'horses.breeding_station_id muss auf NULL gehen, nicht das Pferd mitreißen'
        );

        // Antrag als erledigt markiert.
        $stmt = $db->prepare("SELECT status FROM gdpr_requests WHERE id = ?");
        $stmt->execute([$fixture['requestId']]);
        $this->assertSame('processed', $stmt->fetchColumn());

        // Die Löschung hinterlässt eine Audit-Log-Spur (#135).
        $stmt = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'DSGVO: Person endgültig gelöscht' AND details LIKE ?");
        $stmt->execute(['%Person ID ' . $fixture['personId'] . '%']);
        $this->assertGreaterThan(0, (int)$stmt->fetchColumn(), 'DSGVO-Löschung muss im Audit-Log protokolliert werden');
    }

    public function testAnonymizePersonKeepsHorseLinks(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $fixture = $this->createPersonWithHorseAndRequest("DSGVO Anonperson {$unique}", "gdpr-anon-{$unique}@example.com");
        $db = $this->db();

        // E-Mail-only-Suche (#188): ein Antrag ohne Namen muss die Person über
        // die contacts.email-Spalte finden (der Antragsteller kennt seinen
        // Datensatz-Namen oft nicht). Direkter INSERT, weil das öffentliche
        // Formular den Namen verlangt; der Suchpfad im GdprController ist
        // derselbe.
        $stmt = $db->prepare("INSERT INTO gdpr_requests (name, email, request_type, message, status) VALUES ('', ?, 'deletion', 'Suche per E-Mail', 'pending')");
        $stmt->execute(["gdpr-anon-{$unique}@example.com"]);
        $emailOnlyRequestId = (int)$db->lastInsertId();
        // Den namensbasierten Fixture-Antrag währenddessen auf 'processed'
        // stellen - nur offene Anträge bekommen Personen-Treffer. Als
        // Nachweis dient das person_id-Formularfeld des Treffer-Blocks (der
        // Personenname allein stünde schon als Antragsname auf der Seite und
        // wäre nicht aussagekräftig).
        $db->prepare("UPDATE gdpr_requests SET status = 'processed' WHERE id = ?")->execute([$fixture['requestId']]);
        $overview = $admin->get('/admin/gdpr');
        $this->assertStringContainsString(
            '<input type="hidden" name="person_id" value="' . $fixture['personId'] . '">',
            $overview->body,
            'Person muss über die E-Mail-Spalte gefunden werden, wenn der Antrag keinen Namen nennt'
        );
        $db->prepare("DELETE FROM gdpr_requests WHERE id = ?")->execute([$emailOnlyRequestId]);
        $db->prepare("UPDATE gdpr_requests SET status = 'pending' WHERE id = ?")->execute([$fixture['requestId']]);

        // Testaufbau belegen, BEVOR anonymisiert wird: Eine Zusicherung
        // "danach NULL" ist wertlos, wenn das Feld vorher schon NULL war.
        // Gerade für die beiden mit #336 dazugekommenen Spalten - sie sind der
        // Grund, warum dieser Test überhaupt angefasst wurde.
        $stmt = $db->prepare("SELECT contact_person, address FROM contacts WHERE id = ?");
        $stmt->execute([$fixture['personId']]);
        $vorher = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotNull($vorher['contact_person'], 'Testaufbau kaputt: contact_person war nie gefüllt');
        $this->assertNotNull($vorher['address'], 'Testaufbau kaputt: address war nie gefüllt');

        $anonResponse = $admin->post('/admin/gdpr/anonymize-person', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'person_id' => (string)$fixture['personId'],
            'request_id' => (string)$fixture['requestId'],
        ]);
        $this->assertSame(
            '/admin/gdpr?success=anonymized&person_id=' . $fixture['personId'],
            $anonResponse->location(),
            "Anonymisierung fehlgeschlagen, Body: {$anonResponse->body}"
        );

        $stmt = $db->prepare("SELECT * FROM contacts WHERE id = ?");
        $stmt->execute([$fixture['personId']]);
        $person = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('Anonymisierte Person (#' . $fixture['personId'] . ')', $person['name']);

        // Die beiden Spalten, die contacts gegenüber persons DAZUBEKOMMEN hat
        // (#336), ausdrücklich beim Namen genannt. Die schemagetriebene
        // Schleife unten deckt sie zwar mit ab - aber sie deckt sie nur so
        // lange ab, wie niemand sie in $nonPii schiebt, und genau das wäre
        // hier die naheliegende Abkürzung: Beide klingen nach
        // Betriebsstammdaten. Sie sind es nicht. Der Ansprechpartner eines
        // Betriebs IST ein Mensch, und die alte Freitext-Anschrift ist
        // zustellbar wie street/postal_code.
        $this->assertNull($person['contact_person'], 'contacts.contact_person ist personenbezogen und muss geleert werden');
        $this->assertNull($person['address'], 'contacts.address ist eine zustellbare Anschrift und muss geleert werden');

        // Die Anonymisierung muss ALLE personenbezogenen Felder leeren - ein
        // vergessenes Feld wäre ein stiller DSGVO-Leak: Die Aktion meldet
        // weiterhin Erfolg, die Daten stehen aber noch da.
        //
        // Die Feldliste wird deshalb aus dem Schema abgeleitet und NICHT
        // aufgezählt. Eine aufgezählte Liste prüft nur, was jemand beim
        // Schreiben des Tests kannte; eine neue Spalte in contacts wäre
        // automatisch ungeprüft - genau so wäre `state` (#256) durchgerutscht,
        // und genau so wären contact_person und address (#336) durchgerutscht.
        // Hier fällt jede künftige Spalte sofort auf, solange sie nicht
        // ausdrücklich als nicht-personenbezogen ausgenommen wird.
        // is_breeder (#293) steht bewusst hier: Das Kennzeichen sagt "diese
        // Person züchtet", nicht WER sie ist - an einer bereits auf
        // "Anonymisierte Person (#id)" umbenannten Zeile identifiziert es
        // niemanden. Die Spalte ist zudem NOT NULL und liesse sich gar nicht
        // nullen. Genau wie is_published gehoert sie damit zum Datensatz, nicht
        // zur Person.
        // contact_public gehört wie is_published und is_breeder zum Datensatz,
        // nicht zur Person: Es sagt, ob Kontaktdaten gezeigt werden dürfen -
        // nicht, WER jemand ist. Die Anonymisierung nullt die Kontaktdaten
        // selbst, damit ist die Frage ohnehin gegenstandslos. Beide Spalten
        // sind zudem NOT NULL.
        // updated_at kam mit contacts dazu (persons hatte die Spalte nicht):
        // ein technischer Änderungsstempel, den MariaDB selbst pflegt (ON
        // UPDATE CURRENT_TIMESTAMP) und der über den Menschen nichts aussagt.
        $nonPii = ['id', 'name', 'is_published', 'is_breeder', 'contact_public', 'created_at', 'updated_at', 'deleted_at'];
        $piiColumns = array_values(array_diff(array_keys($person), $nonPii));
        $this->assertNotEmpty($piiColumns, 'Spaltenliste von contacts konnte nicht ermittelt werden.');
        // Gegenprobe zur Ableitung selbst: Die beiden #336-Spalten müssen in
        // der abgeleiteten Menge WIRKLICH vorkommen. Sonst prüfte die Schleife
        // unten sie stillschweigend nicht mehr, weil jemand sie in $nonPii
        // verschoben hat - und der Test bliebe grün.
        $this->assertContains('contact_person', $piiColumns);
        $this->assertContains('address', $piiColumns);
        foreach ($piiColumns as $field) {
            $this->assertNull(
                $person[$field],
                "contacts.{$field} muss nach der Anonymisierung NULL sein. Ist das Feld nicht "
                . "personenbezogen, gehört es ausdrücklich in die \$nonPii-Liste dieses Tests - "
                . "und nicht stillschweigend übergangen."
            );
        }

        // Die Pferd-Verknüpfung bleibt bei der Anonymisierung erhalten.
        $stmt = $db->prepare("SELECT COUNT(*) FROM horse_persons WHERE contact_id = ?");
        $stmt->execute([$fixture['personId']]);
        $this->assertSame(1, (int)$stmt->fetchColumn());

        $stmt = $db->prepare("SELECT status FROM gdpr_requests WHERE id = ?");
        $stmt->execute([$fixture['requestId']]);
        $this->assertSame('processed', $stmt->fetchColumn());

        $stmt = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'DSGVO: Person anonymisiert' AND details LIKE ?");
        $stmt->execute(['%Person ID ' . $fixture['personId'] . '%']);
        $this->assertGreaterThan(0, (int)$stmt->fetchColumn(), 'Anonymisierung muss im Audit-Log protokolliert werden');
    }

    public function testNonAdminIsRejectedWithForbidden(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        $db = $this->db();
        $stmt = $db->prepare("INSERT INTO contacts (name, is_published) VALUES (?, 1)");
        $stmt->execute(["DSGVO Schutzperson {$unique}"]);
        $personId = (int)$db->lastInsertId();

        // Nicht-Admin ohne jede Gruppenzugehörigkeit: requireAdmin() im
        // Konstruktor muss sowohl die Übersicht als auch die Löschaktion sperren.
        $editor = $this->createAndLoginEditor($admin, "gdprtester{$unique}", "gdpr-editor-{$unique}@example.com", []);

        $this->assertSame(403, $editor->get('/admin/gdpr')->statusCode);

        $deleteResponse = $editor->post('/admin/gdpr/delete-person', [
            'csrf_token' => $editor->get('/dsgvo')->formField('csrf_token') ?? '',
            'person_id' => (string)$personId,
            'request_id' => '0',
        ]);
        $this->assertSame(403, $deleteResponse->statusCode, 'Nicht-Admins dürfen keine DSGVO-Löschung auslösen');

        $stmt = $db->prepare("SELECT COUNT(*) FROM contacts WHERE id = ?");
        $stmt->execute([$personId]);
        $this->assertSame(1, (int)$stmt->fetchColumn(), 'Kontakt darf durch den abgewiesenen Request nicht gelöscht sein');
    }
}
