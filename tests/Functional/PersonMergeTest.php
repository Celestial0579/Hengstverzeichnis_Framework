<?php
// tests/Functional/PersonMergeTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Personendubletten zusammenführen (#297).
 *
 * Personendubletten entstehen im Normalbetrieb: `persons` hat keinen
 * UNIQUE-Index, keine Dublettenwarnung und bewusst keine Formatprüfung
 * („Freitext-Philosophie"). Zwei Redakteure legen denselben Züchter zweimal an.
 *
 * Wer das von Hand nachbaut - Person löschen, Pferde neu zuordnen -, verliert
 * die Zuordnungen dazwischen: `horse_persons.person_id` trägt
 * `ON DELETE CASCADE`, und `TrashController::emptyTrash()` löscht Personen im
 * Papierkorb **hart**. Deshalb ist die Reihenfolge der Kern dieser Aktion und
 * wird hier festgehalten: erst umhängen, dann in den Papierkorb.
 */
class PersonMergeTest extends FunctionalTestCase {

    public function testMergeMovesAssignmentsFillsGapsAndTrashesTheSource(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        // Ziel: nur Name und Ort. Quelle: hat zusätzlich E-Mail und Züchter-Kennzeichen.
        $zielId = $this->person($admin, "Familie Ruf {$unique}", ['city' => 'Spechbach']);
        $quelleId = $this->person($admin, "Familie Ruf (Dublette) {$unique}", [
            'city' => 'Spechbach',
            'email' => "ruf-{$unique}@example.com",
            'phone' => '0620 111x',
            'is_breeder' => '1',
        ]);

        $pferdA = $this->pferd($admin, "Merge A {$unique}", $quelleId, 'breeder');
        $pferdB = $this->pferd($admin, "Merge B {$unique}", $quelleId, 'owner');

        $mergePage = $admin->get('/admin/persons/merge?id=' . $quelleId);
        $this->assertSame(200, $mergePage->statusCode);
        $this->assertStringContainsString("Merge A {$unique}", $mergePage->body, 'Die Vorschau muss die betroffenen Pferde nennen');
        $this->assertStringContainsString("Merge B {$unique}", $mergePage->body);

        $response = $admin->post('/admin/persons/merge', [
            'csrf_token' => $mergePage->formField('csrf_token') ?? '',
            'source_id' => (string)$quelleId,
            'target_id' => (string)$zielId,
        ]);
        // Die Zahlen stehen im Redirect, damit die Liste sie nennen kann statt
        // nur "erfolgreich" - sie sind der einzige Hinweis darauf, ob die
        // Paarrichtung stimmte (siehe PersonController::merge()).
        $this->assertSame(
            '/admin/persons?success=merged&merged_moved=2&merged_dropped=0&merged_filled=3',
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
        $stmt = $db->prepare("SELECT horse_id, role FROM horse_persons WHERE person_id = ? ORDER BY horse_id ASC");
        $stmt->execute([$zielId]);
        $zuordnungen = $stmt->fetchAll();
        $this->assertCount(2, $zuordnungen, 'Beide Zuordnungen müssen umgehängt worden sein');
        $this->assertSame([$pferdA, $pferdB], array_map(static fn(array $r): int => (int)$r['horse_id'], $zuordnungen));

        $stmt = $db->prepare("SELECT COUNT(*) FROM horse_persons WHERE person_id = ?");
        $stmt->execute([$quelleId]);
        $this->assertSame(0, (int)$stmt->fetchColumn(), 'An der Quelle darf nichts zurückbleiben');

        // 2. Leere Felder des Ziels sind ergänzt, gefüllte unverändert.
        $stmt = $db->prepare("SELECT name, city, email, phone, is_breeder FROM persons WHERE id = ?");
        $stmt->execute([$zielId]);
        $ziel = $stmt->fetch();
        $this->assertSame("Familie Ruf {$unique}", $ziel['name'], 'Der Name des Ziels darf nicht überschrieben werden');
        $this->assertSame('Spechbach', $ziel['city']);
        $this->assertSame("ruf-{$unique}@example.com", $ziel['email'], 'Leere Felder werden aus der Quelle ergänzt');
        $this->assertSame('0620 111x', $ziel['phone']);
        $this->assertSame(1, (int)$ziel['is_breeder'], 'Ein gesetztes Züchter-Kennzeichen bleibt gesetzt');

        // 3. Die Quelle liegt im Papierkorb - und zwar ERST NACH dem Umhängen,
        //    sonst hätte ON DELETE CASCADE die Zuordnungen mitgenommen.
        $stmt = $db->prepare("SELECT deleted_at FROM persons WHERE id = ?");
        $stmt->execute([$quelleId]);
        $this->assertNotNull($stmt->fetchColumn(), 'Die aufgegebene Person gehört in den Papierkorb');

        // 4. Und das Ganze steht im Audit-Log.
        $stmt = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'Personen zusammengeführt' AND details LIKE ?");
        $stmt->execute(['%Quelle ID ' . $quelleId . '%']);
        $this->assertGreaterThan(0, (int)$stmt->fetchColumn());
    }

    /**
     * Exakte Doppel werden nicht zweimal angelegt, abweichende Zeiträume
     * dagegen schon: Sie sind Historie, keine Dublette.
     */
    public function testDuplicateAssignmentsAreCollapsedButHistoryIsKept(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        $zielId = $this->person($admin, "Ziel {$unique}", []);
        $quelleId = $this->person($admin, "Quelle {$unique}", []);
        $pferdId = $this->pferd($admin, "Doppelt {$unique}", $zielId, 'owner');

        // Quelle bekommt dieselbe Zuordnung (exaktes Doppel) und eine mit
        // abweichendem Zeitraum.
        $ins = $db->prepare("INSERT INTO horse_persons (horse_id, person_id, role, from_year, until_year) VALUES (?, ?, 'owner', ?, ?)");
        $ins->execute([$pferdId, $quelleId, null, null]);
        $ins->execute([$pferdId, $quelleId, 1990, 1995]);

        $mergePage = $admin->get('/admin/persons/merge?id=' . $quelleId);
        $admin->post('/admin/persons/merge', [
            'csrf_token' => $mergePage->formField('csrf_token') ?? '',
            'source_id' => (string)$quelleId,
            'target_id' => (string)$zielId,
        ]);

        $stmt = $db->prepare("SELECT from_year, until_year FROM horse_persons WHERE person_id = ? AND horse_id = ? ORDER BY id ASC");
        $stmt->execute([$zielId, $pferdId]);
        $zeilen = $stmt->fetchAll();
        $this->assertCount(2, $zeilen, 'Das exakte Doppel entfällt, der abweichende Zeitraum bleibt');
        $this->assertNull($zeilen[0]['from_year']);
        $this->assertSame(1990, (int)$zeilen[1]['from_year']);
    }

    /** Quelle gleich Ziel ergibt keinen Sinn und darf nichts anfassen. */
    public function testMergingAPersonWithItselfIsRefused(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $id = $this->person($admin, "Selbst {$unique}", []);

        $mergePage = $admin->get('/admin/persons/merge?id=' . $id);
        $response = $admin->post('/admin/persons/merge', [
            'csrf_token' => $mergePage->formField('csrf_token') ?? '',
            'source_id' => (string)$id,
            'target_id' => (string)$id,
        ]);
        $this->assertSame('/admin/persons?error=merge_invalid', $response->location());

        $stmt = $db->prepare("SELECT deleted_at FROM persons WHERE id = ?");
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

        $zielId = $this->person($admin, "Züchter Ziel {$unique}", []);
        $quelleId = $this->person($admin, "Züchter Quelle {$unique}", []);
        // Das Ziel bekommt über das Formular eine Züchter-Zeile ganz ohne
        // Zusatzangaben - der Bezugspunkt für den Dublettenvergleich.
        $pferdId = $this->pferd($admin, "Station Doppel {$unique}", $zielId, 'breeder');

        $station = "Hof Sonnenberg {$unique}";
        $ins = $db->prepare(
            "INSERT INTO horse_persons (horse_id, person_id, role, breeding_station_text, origin_country, from_year, until_year)
             VALUES (?, ?, 'breeder', ?, ?, NULL, NULL)"
        );
        $ins->execute([$pferdId, $quelleId, $station, null]);  // nur Deckstations-Freitext
        $ins->execute([$pferdId, $quelleId, null, 'NO']);      // nur Herkunftsland
        $ins->execute([$pferdId, $quelleId, null, null]);      // wirklich dieselbe Aussage

        $mergePage = $admin->get('/admin/persons/merge?id=' . $quelleId);
        $response = $admin->post('/admin/persons/merge', [
            'csrf_token' => $mergePage->formField('csrf_token') ?? '',
            'source_id' => (string)$quelleId,
            'target_id' => (string)$zielId,
        ]);

        // Beide Hälften der Zusicherung in einer Zahl: zwei Zeilen tragen eine
        // eigene Aussage und werden umgehängt, die dritte ist ein echtes
        // Doppel und darf entfallen.
        $this->assertSame(
            '/admin/persons?success=merged&merged_moved=2&merged_dropped=1&merged_filled=0',
            $response->location(),
            "Zusammenführen fehlgeschlagen: {$response->body}"
        );

        $stmt = $db->prepare(
            "SELECT breeding_station_text, origin_country FROM horse_persons
              WHERE person_id = ? AND horse_id = ? ORDER BY id ASC"
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

        $stmt = $db->prepare("SELECT COUNT(*) FROM horse_persons WHERE person_id = ?");
        $stmt->execute([$quelleId]);
        $this->assertSame(0, (int)$stmt->fetchColumn(), 'An der Quelle darf nichts zurückbleiben');
    }

    /**
     * #312: Das Auswahlfeld ist gedeckelt und durchsuchbar, und es sagt
     * ehrlich, wenn es kürzt.
     *
     * Ohne Deckel wurde jede Person des Bestands zu einem <option> - bei
     * 20.000 Datensätzen rund 1,1 MB Markup je Seitenaufruf. Ein Deckel, der
     * sich als vollständige Liste ausgibt, wäre allerdings schlimmer als gar
     * keiner: Wer sein Ziel nicht sieht, hielte es für nicht vorhanden.
     * Deshalb wird beides geprüft - die Kürzung UND der Hinweis darauf.
     */
    public function testMergeCandidateListIsCappedAndSearchable(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        $quelleId = $this->person($admin, "Deckel Quelle {$unique}", []);

        // Direkt in die Tabelle: Der Weg über das Formular kostet 55
        // HTTP-Runden und beweist hier nichts zusätzlich.
        $grenze = \App\Controllers\PersonController::MERGE_CANDIDATE_LIMIT;
        $ins = $db->prepare("INSERT INTO persons (name) VALUES (?)");
        for ($i = 1; $i <= $grenze + 5; $i++) {
            $ins->execute([sprintf('Deckel Kandidat %02d %s', $i, $unique)]);
        }

        $seite = $admin->get('/admin/persons/merge?id=' . $quelleId);
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
        $gefiltert = $admin->get('/admin/persons/merge?id=' . $quelleId . '&q=' . urlencode($gesucht));
        $optionen = $this->zielOptionen($gefiltert->body);
        $this->assertCount(1, $optionen, "Die Suche muss genau einen Treffer liefern, Body: {$gefiltert->body}");
        $this->assertStringContainsString($gesucht, $optionen[0]);
        $this->assertStringNotContainsString('Die Liste ist auf', $gefiltert->body);
    }

    /**
     * #321: Ohne gültigen CSRF-Token passiert nichts.
     *
     * Ein Formular auf einer fremden Seite würde sonst genügen, um beim
     * eingeloggten Redakteur zwei Personendatensätze zu verschmelzen - und
     * das ist nicht rückgängig zu machen, sobald der Papierkorb geleert wird
     * (horse_persons trägt ON DELETE CASCADE).
     */
    public function testMergeIsRefusedWithoutValidCsrfToken(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        $zielId = $this->person($admin, "CSRF Ziel {$unique}", []);
        $quelleId = $this->person($admin, "CSRF Quelle {$unique}", []);
        $pferdId = $this->pferd($admin, "CSRF Pferd {$unique}", $quelleId, 'owner');

        $response = $admin->post('/admin/persons/merge', [
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
     * das nicht. Die Vorschau bleibt bewusst mit persons.edit erreichbar;
     * genau deshalb ist die Zusatzforderung im POST der eigentliche Schutz und
     * nicht bloß eine Wiederholung der Rechteprüfung der Seite davor.
     */
    public function testMergeRequiresTheDeletePermission(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        $zielId = $this->person($admin, "Recht Ziel {$unique}", []);
        $quelleId = $this->person($admin, "Recht Quelle {$unique}", []);
        $pferdId = $this->pferd($admin, "Recht Pferd {$unique}", $quelleId, 'owner');

        $groupId = $this->createCustomGroup($admin, "Personen ohne Löschrecht {$unique}");
        $this->setGroupPermissions($admin, $groupId, ['persons' => ['view', 'edit']]);
        $editor = $this->createAndLoginEditor(
            $admin,
            "mergeeditor{$unique}",
            "merge-editor-{$unique}@example.com",
            [$groupId]
        );

        $mergePage = $editor->get('/admin/persons/merge?id=' . $quelleId);
        $this->assertSame(
            200,
            $mergePage->statusCode,
            'Die Vorschau ist mit persons.edit erreichbar - sonst prüft der POST-Test die falsche Hürde'
        );

        $response = $editor->post('/admin/persons/merge', [
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
        $stmt = $db->prepare("SELECT deleted_at FROM persons WHERE id = ?");
        $stmt->execute([$quelleId]);
        $this->assertNull($stmt->fetchColumn(), 'Die Quelle darf nicht im Papierkorb gelandet sein');

        $stmt = $db->prepare("SELECT person_id FROM horse_persons WHERE horse_id = ?");
        $stmt->execute([$pferdId]);
        $this->assertSame($quelleId, (int)$stmt->fetchColumn(), 'Die Zuordnung darf nicht umgehängt worden sein');
    }

    /**
     * Die angebotenen Ziel-Personen aus dem Auswahlfeld - ohne den
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
    private function person(\Tests\Support\HttpClient $admin, string $name, array $felder): int {
        $form = $admin->get('/admin/persons/create');
        $admin->post('/admin/persons/store', array_merge([
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $name,
        ], $felder));
        $stmt = Database::getInstance()->prepare("SELECT id FROM persons WHERE name = ?");
        $stmt->execute([$name]);
        $id = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $id, "Person '{$name}' wurde nicht angelegt");
        return $id;
    }

    private function pferd(\Tests\Support\HttpClient $admin, string $name, int $personId, string $rolle): int {
        $form = $admin->get('/admin/horses/create');
        $admin->post('/admin/horses/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $name,
            'status' => 'active',
            'persons[0][person_id]' => (string)$personId,
            'persons[0][role]' => $rolle,
        ]);
        $stmt = Database::getInstance()->prepare("SELECT id FROM horses WHERE name = ?");
        $stmt->execute([$name]);
        $id = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $id, "Pferd '{$name}' wurde nicht angelegt");
        return $id;
    }
}
