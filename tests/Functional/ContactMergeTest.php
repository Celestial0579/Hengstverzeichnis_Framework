<?php
// tests/Functional/ContactMergeTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Kontaktdubletten zusammenführen (#297, seit #336 auf `contacts`).
 *
 * Dubletten entstehen im Normalbetrieb: `contacts` hat keinen UNIQUE-Index,
 * keine Dublettenwarnung und bewusst keine Formatprüfung
 * („Freitext-Philosophie"). Zwei Redakteure legen denselben Züchter zweimal an.
 *
 * Wer das von Hand nachbaut - Kontakt löschen, Pferde neu zuordnen -, verliert
 * die Zuordnungen dazwischen: `horse_persons.contact_id` trägt
 * `ON DELETE CASCADE`, und `TrashController::emptyTrash()` löscht Kontakte im
 * Papierkorb **hart**. Deshalb ist die Reihenfolge der Kern dieser Aktion und
 * wird hier festgehalten: erst umhängen, dann in den Papierkorb.
 *
 * Seit dem Zusammenlegen hängt an einem Kontakt ein ZWEITER Steckplatz - er
 * kann selbst die Deckstation sein (`horse_persons.station_contact_id`,
 * `horses.breeding_station_id`). Auch der muss mit umziehen; dafür gibt es
 * einen eigenen Test weiter unten.
 */
class ContactMergeTest extends FunctionalTestCase {

    public function testMergeMovesAssignmentsFillsGapsAndTrashesTheSource(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        // Ziel: nur Name und Ort. Quelle: hat zusätzlich E-Mail und Züchter-Kennzeichen.
        $zielId = $this->kontakt($admin, "Familie Ruf {$unique}", ['city' => 'Spechbach']);
        $quelleId = $this->kontakt($admin, "Familie Ruf (Dublette) {$unique}", [
            'city' => 'Spechbach',
            'email' => "ruf-{$unique}@example.com",
            'phone' => '0620 111x',
            'is_breeder' => '1',
        ]);

        $pferdA = $this->pferd($admin, "Merge A {$unique}", $quelleId, 'breeder');
        $pferdB = $this->pferd($admin, "Merge B {$unique}", $quelleId, 'owner');

        $mergePage = $admin->get('/admin/contacts/merge?id=' . $quelleId);
        $this->assertSame(200, $mergePage->statusCode);
        $this->assertStringContainsString("Merge A {$unique}", $mergePage->body, 'Die Vorschau muss die betroffenen Pferde nennen');
        $this->assertStringContainsString("Merge B {$unique}", $mergePage->body);

        $response = $admin->post('/admin/contacts/merge', [
            'csrf_token' => $mergePage->formField('csrf_token') ?? '',
            'source_id' => (string)$quelleId,
            'target_id' => (string)$zielId,
        ]);
        // Die Zahlen stehen im Redirect, damit die Liste sie nennen kann statt
        // nur "erfolgreich" - sie sind der einzige Hinweis darauf, ob die
        // Paarrichtung stimmte (siehe ContactController::merge()).
        $this->assertSame(
            '/admin/contacts?success=merged&merged_moved=2&merged_dropped=0&merged_filled=3&merged_stations=0',
            $response->location(),
            "Zusammenführen fehlgeschlagen: {$response->body}"
        );

        $liste = $admin->get($response->location());
        $this->assertStringContainsString('2 Zuordnungen umgehängt', $liste->body);
        $this->assertStringContainsString('3 leere Felder aus dem', $liste->body);
        $this->assertStringContainsString(
            'Der aufgegebene Datensatz war deutlich',
            $liste->body,
            'Ab drei ergänzten Feldern gehört der Hinweis auf eine verdrehte Paarrichtung dazu.'
        );

        // 1. Die Zuordnungen hängen jetzt am Ziel - und sind NICHT verloren.
        $stmt = $db->prepare("SELECT horse_id, role FROM horse_persons WHERE contact_id = ? ORDER BY horse_id ASC");
        $stmt->execute([$zielId]);
        $zuordnungen = $stmt->fetchAll();
        $this->assertCount(2, $zuordnungen, 'Beide Zuordnungen müssen umgehängt worden sein');
        $this->assertSame([$pferdA, $pferdB], array_map(static fn(array $r): int => (int)$r['horse_id'], $zuordnungen));

        $stmt = $db->prepare("SELECT COUNT(*) FROM horse_persons WHERE contact_id = ?");
        $stmt->execute([$quelleId]);
        $this->assertSame(0, (int)$stmt->fetchColumn(), 'An der Quelle darf nichts zurückbleiben');

        // 2. Leere Felder des Ziels sind ergänzt, gefüllte unverändert.
        $stmt = $db->prepare("SELECT name, city, email, phone, is_breeder FROM contacts WHERE id = ?");
        $stmt->execute([$zielId]);
        $ziel = $stmt->fetch();
        $this->assertSame("Familie Ruf {$unique}", $ziel['name'], 'Der Name des Ziels darf nicht überschrieben werden');
        $this->assertSame('Spechbach', $ziel['city']);
        $this->assertSame("ruf-{$unique}@example.com", $ziel['email'], 'Leere Felder werden aus der Quelle ergänzt');
        $this->assertSame('0620 111x', $ziel['phone']);
        $this->assertSame(1, (int)$ziel['is_breeder'], 'Ein gesetztes Züchter-Kennzeichen bleibt gesetzt');

        // 3. Die Quelle liegt im Papierkorb - und zwar ERST NACH dem Umhängen,
        //    sonst hätte ON DELETE CASCADE die Zuordnungen mitgenommen.
        $stmt = $db->prepare("SELECT deleted_at FROM contacts WHERE id = ?");
        $stmt->execute([$quelleId]);
        $this->assertNotNull($stmt->fetchColumn(), 'Der aufgegebene Kontakt gehört in den Papierkorb');

        // 4. Und das Ganze steht im Audit-Log.
        $stmt = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'Kontakte zusammengeführt' AND details LIKE ?");
        $stmt->execute(['%Quelle ID ' . $quelleId . '%']);
        $this->assertGreaterThan(0, (int)$stmt->fetchColumn());
    }

    /**
     * Der zweite Steckplatz (#336): Ein Kontakt ist nicht nur jemand IN einer
     * Rolle, er kann auch der Ort sein, an dem ein Pferd steht - als
     * `horse_persons.station_contact_id` in der einzelnen Zuordnungszeile und
     * als `horses.breeding_station_id` am Pferd selbst.
     *
     * Beide Verweise müssen mit umziehen. Täten sie es nicht, zeigten sie nach
     * dem Zusammenführen auf einen Datensatz im PAPIERKORB - fachlich schon
     * falsch, und beim Leeren des Papierkorbs endgültig: `breeding_station_id`
     * verschwände mit dem Pferd-Update, `station_contact_id` fiele über
     * ON DELETE SET NULL still auf NULL. Die Stationsangabe wäre weg, ohne dass
     * jemand sie gelöscht hätte.
     *
     * Der Freitext-Spiegel `horses.breeding_station` bleibt dabei bewusst
     * unangetastet - er ist eine eigene Aussage und wird erst beim nächsten
     * Speichern des Pferds nachgeführt.
     */
    public function testMergeAlsoMovesTheStationSlot(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        $zielId = $this->kontakt($admin, "Hof Ziel {$unique}", []);
        $quelleId = $this->kontakt($admin, "Hof Quelle {$unique}", []);

        // Ein Pferd, dessen Zuordnungszeile die Quelle in BEIDEN Steckplätzen
        // nennt: als Besitzer und als Deckstation. Über den Stations-Steckplatz
        // spiegelt HorseController::saveHorsePersons() zugleich
        // horses.breeding_station_id auf den Pferdedatensatz.
        $pferdId = $this->pferd($admin, "Stationspferd {$unique}", $quelleId, 'owner', $quelleId);

        $stmt = $db->prepare("SELECT breeding_station_id FROM horses WHERE id = ?");
        $stmt->execute([$pferdId]);
        $this->assertSame(
            $quelleId,
            (int)$stmt->fetchColumn(),
            'Vorbedingung: Das Pferd muss die Quelle als Deckstation tragen'
        );

        // Die Vorschau muss den zweiten Steckplatz nennen - sonst hielte der
        // Bearbeiter die Liste der Zuordnungen für vollständig.
        $mergePage = $admin->get('/admin/contacts/merge?id=' . $quelleId);
        $this->assertSame(200, $mergePage->statusCode);
        $this->assertStringContainsString('Als Deckstation genannt (1)', $mergePage->body);
        $this->assertStringContainsString("Stationspferd {$unique}", $mergePage->body);

        $response = $admin->post('/admin/contacts/merge', [
            'csrf_token' => $mergePage->formField('csrf_token') ?? '',
            'source_id' => (string)$quelleId,
            'target_id' => (string)$zielId,
        ]);
        // Zwei Stationsverweise: die Zuordnungszeile und das Pferd selbst.
        $this->assertSame(
            '/admin/contacts?success=merged&merged_moved=1&merged_dropped=0&merged_filled=0&merged_stations=2',
            $response->location(),
            "Zusammenführen fehlgeschlagen: {$response->body}"
        );
        // Zwei Teilstücke statt eines Satzes: Die View bricht die Meldung um,
        // zwischen "Kontakt" und "als" steht ein Zeilenumbruch samt Einrückung.
        $listenBody = $admin->get($response->location())->body;
        $this->assertStringContainsString(
            '2 Verweise auf den Kontakt',
            $listenBody,
            'Die Liste muss die umgehängten Stationsverweise nennen'
        );
        $this->assertStringContainsString('als Deckstation umgehängt', $listenBody);

        $stmt = $db->prepare("SELECT contact_id, station_contact_id FROM horse_persons WHERE horse_id = ?");
        $stmt->execute([$pferdId]);
        $zeile = $stmt->fetch();
        $this->assertSame($zielId, (int)$zeile['contact_id'], 'Der Rollen-Steckplatz hängt am Ziel');
        $this->assertSame($zielId, (int)$zeile['station_contact_id'], 'Der Stations-Steckplatz ebenfalls');

        $stmt = $db->prepare("SELECT breeding_station_id FROM horses WHERE id = ?");
        $stmt->execute([$pferdId]);
        $this->assertSame(
            $zielId,
            (int)$stmt->fetchColumn(),
            'Auch die Deckstation am Pferd selbst darf nicht auf den Papierkorb zeigen'
        );

        // Gegenprobe: An der Quelle hängt kein Verweis mehr - weder in der
        // Zuordnungszeile noch am Pferd.
        $stmt = $db->prepare("SELECT COUNT(*) FROM horse_persons WHERE contact_id = ? OR station_contact_id = ?");
        $stmt->execute([$quelleId, $quelleId]);
        $this->assertSame(0, (int)$stmt->fetchColumn());
        $stmt = $db->prepare("SELECT COUNT(*) FROM horses WHERE breeding_station_id = ?");
        $stmt->execute([$quelleId]);
        $this->assertSame(0, (int)$stmt->fetchColumn());
    }

    /**
     * Exakte Doppel werden nicht zweimal angelegt, abweichende Zeiträume
     * dagegen schon: Sie sind Historie, keine Dublette.
     */
    public function testDuplicateAssignmentsAreCollapsedButHistoryIsKept(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        $zielId = $this->kontakt($admin, "Ziel {$unique}", []);
        $quelleId = $this->kontakt($admin, "Quelle {$unique}", []);
        $pferdId = $this->pferd($admin, "Doppelt {$unique}", $zielId, 'owner');

        // Quelle bekommt dieselbe Zuordnung (exaktes Doppel) und eine mit
        // abweichendem Zeitraum.
        $ins = $db->prepare("INSERT INTO horse_persons (horse_id, contact_id, role, from_year, until_year) VALUES (?, ?, 'owner', ?, ?)");
        $ins->execute([$pferdId, $quelleId, null, null]);
        $ins->execute([$pferdId, $quelleId, 1990, 1995]);

        $mergePage = $admin->get('/admin/contacts/merge?id=' . $quelleId);
        $admin->post('/admin/contacts/merge', [
            'csrf_token' => $mergePage->formField('csrf_token') ?? '',
            'source_id' => (string)$quelleId,
            'target_id' => (string)$zielId,
        ]);

        $stmt = $db->prepare("SELECT from_year, until_year FROM horse_persons WHERE contact_id = ? AND horse_id = ? ORDER BY id ASC");
        $stmt->execute([$zielId, $pferdId]);
        $zeilen = $stmt->fetchAll();
        $this->assertCount(2, $zeilen, 'Das exakte Doppel entfällt, der abweichende Zeitraum bleibt');
        $this->assertNull($zeilen[0]['from_year']);
        $this->assertSame(1990, (int)$zeilen[1]['from_year']);
    }

    /** Quelle gleich Ziel ergibt keinen Sinn und darf nichts anfassen. */
    public function testMergingAContactWithItselfIsRefused(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $id = $this->kontakt($admin, "Selbst {$unique}", []);

        $mergePage = $admin->get('/admin/contacts/merge?id=' . $id);
        $response = $admin->post('/admin/contacts/merge', [
            'csrf_token' => $mergePage->formField('csrf_token') ?? '',
            'source_id' => (string)$id,
            'target_id' => (string)$id,
        ]);
        $this->assertSame('/admin/contacts?error=merge_invalid', $response->location());

        $stmt = $db->prepare("SELECT deleted_at FROM contacts WHERE id = ?");
        $stmt->execute([$id]);
        $this->assertNull($stmt->fetchColumn(), 'Der Datensatz darf dabei nicht im Papierkorb landen');
    }

    /**
     * Regression #310: Der Dublettenschlüssel muss die inhaltstragenden
     * Spalten der Zuordnungszeile mitvergleichen.
     *
     * Der Fall ist nicht konstruiert, sondern genau der, für den die Funktion
     * gebaut ist: Bei role='breeder' setzt HorseController::saveHorsePersons()
     * from_year/until_year hart auf NULL - Pferd, Rolle und Zeitraum stimmen
     * bei zwei Züchter-Zuordnungen desselben Pferds also zwangsläufig überein.
     * Verglich der Schlüssel nur diese vier Spalten, galt eine Zeile mit
     * Deckstation oder Herkunftsland als exaktes Doppel einer leeren Zeile -
     * und Schritt 2 löscht hart, was nicht umgehängt wurde. Die Angabe war
     * danach weg, auch aus dem Papierkorb.
     */
    public function testAssignmentsWithStationOrOriginCountryAreNotDroppedAsDuplicates(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        $zielId = $this->kontakt($admin, "Züchter Ziel {$unique}", []);
        $quelleId = $this->kontakt($admin, "Züchter Quelle {$unique}", []);
        // Das Ziel bekommt über das Formular eine Züchter-Zeile ganz ohne
        // Zusatzangaben - der Bezugspunkt für den Dublettenvergleich.
        $pferdId = $this->pferd($admin, "Station Doppel {$unique}", $zielId, 'breeder');

        $station = "Hof Sonnenberg {$unique}";
        $ins = $db->prepare(
            "INSERT INTO horse_persons (horse_id, contact_id, role, breeding_station_text, origin_country, from_year, until_year)
             VALUES (?, ?, 'breeder', ?, ?, NULL, NULL)"
        );
        $ins->execute([$pferdId, $quelleId, $station, null]);  // nur Deckstations-Freitext
        $ins->execute([$pferdId, $quelleId, null, 'NO']);      // nur Herkunftsland
        $ins->execute([$pferdId, $quelleId, null, null]);      // wirklich dieselbe Aussage

        $mergePage = $admin->get('/admin/contacts/merge?id=' . $quelleId);
        $response = $admin->post('/admin/contacts/merge', [
            'csrf_token' => $mergePage->formField('csrf_token') ?? '',
            'source_id' => (string)$quelleId,
            'target_id' => (string)$zielId,
        ]);

        // Beide Hälften der Zusicherung in einer Zahl: zwei Zeilen tragen eine
        // eigene Aussage und werden umgehängt, die dritte ist ein echtes
        // Doppel und darf entfallen.
        $this->assertSame(
            '/admin/contacts?success=merged&merged_moved=2&merged_dropped=1&merged_filled=0&merged_stations=0',
            $response->location(),
            "Zusammenführen fehlgeschlagen: {$response->body}"
        );

        $stmt = $db->prepare(
            "SELECT breeding_station_text, origin_country FROM horse_persons
              WHERE contact_id = ? AND horse_id = ? ORDER BY id ASC"
        );
        $stmt->execute([$zielId, $pferdId]);
        $zeilen = $stmt->fetchAll();
        $this->assertCount(3, $zeilen, 'Eigene Zeile des Ziels plus die zwei umgehängten');
        $this->assertNull($zeilen[0]['breeding_station_text']);
        $this->assertNull($zeilen[0]['origin_country']);
        $this->assertSame($station, $zeilen[1]['breeding_station_text'], 'Die Deckstation darf nicht als Doppel verworfen werden');
        $this->assertNull($zeilen[1]['origin_country']);
        $this->assertNull($zeilen[2]['breeding_station_text']);
        $this->assertSame('NO', $zeilen[2]['origin_country'], 'Das Herkunftsland darf nicht als Doppel verworfen werden');

        $stmt = $db->prepare("SELECT COUNT(*) FROM horse_persons WHERE contact_id = ?");
        $stmt->execute([$quelleId]);
        $this->assertSame(0, (int)$stmt->fetchColumn(), 'An der Quelle darf nichts zurückbleiben');
    }

    /**
     * Die Freigabe der Kontaktdaten wird ausdrücklich NICHT mit aufgefüllt.
     *
     * Sie sieht wie ein Kennzeichen aus wie is_breeder, ist aber der genaue
     * Gegenfall: `contact_public` gilt dem Datensatz, dem sie erteilt wurde.
     * Wer zwei Kontakte zusammenführt, sagt damit nicht, dass die
     * Telefonnummer des behaltenen jetzt öffentlich stehen soll - "aufgefüllt"
     * wäre hier eine stille Veröffentlichung, und seit #336 hängt der ganze
     * Schutz an dieser einen Spalte.
     */
    public function testTheContactReleaseIsNotCarriedOverToTheTarget(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        $zielId = $this->kontakt($admin, "Freigabe Ziel {$unique}", []);
        $quelleId = $this->kontakt($admin, "Freigabe Quelle {$unique}", [
            'phone' => '0711 333x',
            'contact_public' => '1',
        ]);

        $mergePage = $admin->get('/admin/contacts/merge?id=' . $quelleId);
        $admin->post('/admin/contacts/merge', [
            'csrf_token' => $mergePage->formField('csrf_token') ?? '',
            'source_id' => (string)$quelleId,
            'target_id' => (string)$zielId,
        ]);

        $stmt = $db->prepare("SELECT phone, contact_public FROM contacts WHERE id = ?");
        $stmt->execute([$zielId]);
        $ziel = $stmt->fetch();
        $this->assertSame('0711 333x', $ziel['phone'], 'Die Nummer selbst wird als leeres Feld ergänzt');
        $this->assertSame(
            0,
            (int)$ziel['contact_public'],
            'Die Freigabe darf NICHT mitkommen - sonst stünde die eben ergänzte Nummer sofort im Netz'
        );
    }

    /**
     * #312: Das Auswahlfeld ist gedeckelt und durchsuchbar, und es sagt
     * ehrlich, wenn es kürzt.
     *
     * Ohne Deckel wurde jeder Kontakt des Bestands zu einem <option> - bei
     * 20.000 Datensätzen rund 1,1 MB Markup je Seitenaufruf. Ein Deckel, der
     * sich als vollständige Liste ausgibt, wäre allerdings schlimmer als gar
     * keiner: Wer sein Ziel nicht sieht, hielte es für nicht vorhanden.
     * Deshalb wird beides geprüft - die Kürzung UND der Hinweis darauf.
     */
    public function testMergeCandidateListIsCappedAndSearchable(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        $quelleId = $this->kontakt($admin, "Deckel Quelle {$unique}", []);

        // Direkt in die Tabelle: Der Weg über das Formular kostet 55
        // HTTP-Runden und beweist hier nichts zusätzlich.
        //
        // Und danach wieder weg: Die Testdatenbank ist über die ganze Suite
        // geteilt, und die Kontaktliste blättert seit #306 bei 50 Zeilen um.
        // 55 zurückgelassene Datensätze schieben einen fremden Test von
        // Seite 1 - genau das ist in der CI passiert, wo die Datenbank frisch
        // ist und die Zahl deshalb wirklich zählt.
        $grenze = \App\Controllers\ContactController::MERGE_CANDIDATE_LIMIT;
        $ins = $db->prepare("INSERT INTO contacts (name) VALUES (?)");
        $angelegt = [];
        for ($i = 1; $i <= $grenze + 5; $i++) {
            $ins->execute([sprintf('Deckel Kandidat %02d %s', $i, $unique)]);
            $angelegt[] = (int)$db->lastInsertId();
        }

        try {
            $seite = $admin->get('/admin/contacts/merge?id=' . $quelleId);
            $this->assertSame(200, $seite->statusCode);
            $this->assertCount(
                $grenze,
                $this->zielOptionen($seite->body),
                'Das Auswahlfeld darf höchstens MERGE_CANDIDATE_LIMIT Ziele anbieten'
            );
            $this->assertStringContainsString(
                'Die Liste ist auf',
                $seite->body,
                'Eine gekürzte Liste muss das sagen, sonst hält man sie für vollständig'
            );

            // Mit Suchbegriff bleibt genau der eine Treffer übrig - und der
            // Kürzungshinweis verschwindet, weil nichts mehr gekürzt wird.
            $gesucht = sprintf('Deckel Kandidat %02d %s', 7, $unique);
            $gefiltert = $admin->get('/admin/contacts/merge?id=' . $quelleId . '&q=' . urlencode($gesucht));
            $optionen = $this->zielOptionen($gefiltert->body);
            $this->assertCount(1, $optionen, "Die Suche muss genau einen Treffer liefern, Body: {$gefiltert->body}");
            $this->assertStringContainsString($gesucht, $optionen[0]);
            $this->assertStringNotContainsString('Die Liste ist auf', $gefiltert->body);
        } finally {
            $weg = $db->prepare("DELETE FROM contacts WHERE id = ?");
            foreach ($angelegt as $id) {
                $weg->execute([$id]);
            }
        }
    }

    /**
     * #321: Ohne gültigen CSRF-Token passiert nichts.
     *
     * Ein Formular auf einer fremden Seite würde sonst genügen, um beim
     * eingeloggten Redakteur zwei Kontaktdatensätze zu verschmelzen - und
     * das ist nicht rückgängig zu machen, sobald der Papierkorb geleert wird
     * (horse_persons trägt ON DELETE CASCADE).
     */
    public function testMergeIsRefusedWithoutValidCsrfToken(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        $zielId = $this->kontakt($admin, "CSRF Ziel {$unique}", []);
        $quelleId = $this->kontakt($admin, "CSRF Quelle {$unique}", []);
        $pferdId = $this->pferd($admin, "CSRF Pferd {$unique}", $quelleId, 'owner');

        $response = $admin->post('/admin/contacts/merge', [
            'csrf_token' => 'ungültig',
            'source_id' => (string)$quelleId,
            'target_id' => (string)$zielId,
        ]);
        $this->assertSame(403, $response->statusCode, "Erwartet wurde 403, Body: {$response->body}");

        $this->assertUnveraendert($db, $quelleId, $pferdId);
    }

    /**
     * #321: Zusammenführen verlangt zusätzlich das Löschrecht.
     *
     * Der Vorgang legt einen Datensatz still - wer nur bearbeiten darf, darf
     * das nicht. Die Vorschau bleibt bewusst mit contacts.edit erreichbar;
     * genau deshalb ist die Zusatzforderung im POST der eigentliche Schutz und
     * nicht bloß eine Wiederholung der Rechteprüfung der Seite davor.
     *
     * Das Rechte-Modul heißt seit #336 `contacts` und deckt Personen wie
     * Deckstationen ab - `persons` und `breeding_stations` gibt es nicht mehr.
     * Ein Recht auf einen unbekannten Modulnamen wäre wirkungslos, und die
     * Prüfung liefe ins Leere, ohne rot zu werden.
     */
    public function testMergeRequiresTheDeletePermission(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        $zielId = $this->kontakt($admin, "Recht Ziel {$unique}", []);
        $quelleId = $this->kontakt($admin, "Recht Quelle {$unique}", []);
        $pferdId = $this->pferd($admin, "Recht Pferd {$unique}", $quelleId, 'owner');

        $groupId = $this->createCustomGroup($admin, "Kontakte ohne Löschrecht {$unique}");
        $this->setGroupPermissions($admin, $groupId, ['contacts' => ['view', 'edit']]);
        $editor = $this->createAndLoginEditor(
            $admin,
            "mergeeditor{$unique}",
            "merge-editor-{$unique}@example.com",
            [$groupId]
        );

        $mergePage = $editor->get('/admin/contacts/merge?id=' . $quelleId);
        $this->assertSame(
            200,
            $mergePage->statusCode,
            'Die Vorschau ist mit contacts.edit erreichbar - sonst prüft der POST-Test die falsche Hürde'
        );

        $response = $editor->post('/admin/contacts/merge', [
            'csrf_token' => $mergePage->formField('csrf_token') ?? '',
            'source_id' => (string)$quelleId,
            'target_id' => (string)$zielId,
        ]);
        $this->assertSame(403, $response->statusCode, "Erwartet wurde 403, Body: {$response->body}");

        $this->assertUnveraendert($db, $quelleId, $pferdId);
    }

    // ---- Helfer --------------------------------------------------------

    /**
     * Belegt datenbankseitig, dass ein abgelehnter Merge WIRKLICH nichts
     * angefasst hat: Ein 403 sagt nur, was die Antwort war, nicht was
     * vorher schon geschrieben wurde.
     */
    private function assertUnveraendert(\PDO $db, int $quelleId, int $pferdId): void {
        $stmt = $db->prepare("SELECT deleted_at FROM contacts WHERE id = ?");
        $stmt->execute([$quelleId]);
        $this->assertNull($stmt->fetchColumn(), 'Die Quelle darf nicht im Papierkorb gelandet sein');

        $stmt = $db->prepare("SELECT contact_id FROM horse_persons WHERE horse_id = ?");
        $stmt->execute([$pferdId]);
        $this->assertSame($quelleId, (int)$stmt->fetchColumn(), 'Die Zuordnung darf nicht umgehängt worden sein');
    }

    /**
     * Die angebotenen Ziel-Kontakte aus dem Auswahlfeld - ohne den
     * Platzhalter, der value="" trägt.
     *
     * @return array<int, string>
     */
    private function zielOptionen(string $body): array {
        if (!preg_match('#<select id="target_id".*?</select>#s', $body, $treffer)) {
            $this->fail('Auswahlfeld target_id nicht gefunden');
        }
        preg_match_all('#<option value="\d+">(.*?)</option>#s', $treffer[0], $optionen);
        return array_map('trim', $optionen[1]);
    }

    private function createCustomGroup(\Tests\Support\HttpClient $admin, string $name): int {
        $groupsPage = $admin->get('/admin/groups');
        $response = $admin->post('/admin/groups/create', [
            'csrf_token' => $groupsPage->formField('csrf_token') ?? '',
            'name' => $name,
        ]);
        preg_match('/group=(\d+)/', (string)$response->location(), $matches);
        $this->assertNotEmpty($matches, "Konnte neue Gruppen-ID nicht ermitteln, Body: {$response->body}");
        return (int)$matches[1];
    }


    /** @param array<string, string> $felder */
    private function kontakt(\Tests\Support\HttpClient $admin, string $name, array $felder): int {
        $form = $admin->get('/admin/contacts/create');
        $admin->post('/admin/contacts/store', array_merge([
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $name,
        ], $felder));
        $stmt = Database::getInstance()->prepare("SELECT id FROM contacts WHERE name = ?");
        $stmt->execute([$name]);
        $id = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $id, "Kontakt '{$name}' wurde nicht angelegt");
        return $id;
    }

    /**
     * Legt ein Pferd mit EINER Zuordnungszeile an. Die Formularfelder heißen
     * seit #336 wie die Spalten: `contact_id` für den Kontakt in seiner Rolle,
     * `station_contact_id` für den zweiten Steckplatz (die Deckstation).
     */
    private function pferd(
        \Tests\Support\HttpClient $admin,
        string $name,
        int $contactId,
        string $rolle,
        ?int $stationContactId = null
    ): int {
        $form = $admin->get('/admin/horses/create');
        $felder = [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $name,
            'status' => 'active',
            'persons[0][contact_id]' => (string)$contactId,
            'persons[0][role]' => $rolle,
        ];
        if ($stationContactId !== null) {
            $felder['persons[0][station_contact_id]'] = (string)$stationContactId;
        }
        $admin->post('/admin/horses/store', $felder);
        $stmt = Database::getInstance()->prepare("SELECT id FROM horses WHERE name = ?");
        $stmt->execute([$name]);
        $id = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $id, "Pferd '{$name}' wurde nicht angelegt");
        return $id;
    }
}
