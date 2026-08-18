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

    // ---- Helfer --------------------------------------------------------

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
