<?php
// tests/Functional/TrashedLinkPreservationTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Audit M34: Die Auswahllisten des Pferdeformulars enthielten nur Datensätze
 * außerhalb des Papierkorbs. Eine Verknüpfung auf einen Elternteil oder
 * Kontakt im Papierkorb kam deshalb als '' zurück, und jedes Speichern des
 * Fohlens löschte sie. Jetzt bleibt sie erhalten ("(im Papierkorb)"), neu
 * setzen lässt sich ein Datensatz aus dem Papierkorb aber nicht.
 */
class TrashedLinkPreservationTest extends FunctionalTestCase {

    private function db(): \PDO {
        return Database::getInstance();
    }

    /** Inhalt des <select name="$name">…</select> im Body. */
    private function select(string $body, string $name): string {
        $this->assertSame(1, preg_match('~<select[^>]*name="' . preg_quote($name, '~') . '"[^>]*>(.*?)</select>~s', $body, $m), "Select {$name} fehlt");
        return $m[1];
    }

    /** Wert der ausgewählten Option eines Selects ('' ohne Auswahl). */
    private function auswahl(string $body, string $name): string {
        $select = $this->select($body, $name);
        return preg_match('~<option value="([^"]*)"[^>]*\bselected\b~', $select, $m) ? $m[1] : '';
    }

    private function papierkorb(\Tests\Support\HttpClient $admin, string $art, int $id): void {
        $admin->post("/admin/{$art}/delete", ['csrf_token' => $this->currentCsrfToken($admin), 'id' => (string)$id]);
    }

    private function wiederherstellen(\Tests\Support\HttpClient $admin, string $typ, int $id): void {
        $response = $admin->post('/admin/trash/restore', ['csrf_token' => $this->currentCsrfToken($admin), 'type' => $typ, 'id' => (string)$id]);
        $this->assertSame('/admin/trash?success=restored', $response->location());
    }

    public function testTrashedParentAndContactSurviveSavingTheChild(): void {
        $admin = $this->authenticatedClient();
        $db = $this->db();
        $u = uniqid();

        $db->prepare("INSERT INTO horses (name, sex, birth_year) VALUES (?, 'stallion', 2000)")->execute(["Hengst S {$u}"]);
        $s = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO contacts (name) VALUES (?)")->execute(["Kontakt C {$u}"]);
        $c = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO horses (name, sex, birth_year, sire_id, color) VALUES (?, 'mare', 2012, ?, 'Braun')")->execute(["Fohlen K {$u}", $s]);
        $k = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO horse_persons (horse_id, contact_id, role) VALUES (?, ?, 'owner')")->execute([$k, $c]);

        $this->papierkorb($admin, 'horses', $s);
        $this->papierkorb($admin, 'contacts', $c);
        $this->assertNotNull($db->query("SELECT deleted_at FROM horses WHERE id = {$s}")->fetchColumn());
        $this->assertNotNull($db->query("SELECT deleted_at FROM contacts WHERE id = {$c}")->fetchColumn());

        $edit = $admin->get("/admin/horses/edit?id={$k}");
        $this->assertSame(200, $edit->statusCode);
        $sire = $this->select($edit->body, 'sire_id');
        $this->assertMatchesRegularExpression('~<option value="' . $s . '"\s+selected>\s*Hengst S ' . $u . '.*?\(im Papierkorb\)~s', $sire);
        $this->assertStringNotContainsString("Hengst S {$u}", $this->select($edit->body, 'dam_id'));
        $person = $this->select($edit->body, 'persons[0][contact_id]');
        $this->assertStringContainsString('<option value="' . $c . '" selected>Kontakt C ' . $u . ' (im Papierkorb)</option>', $person);
        $this->assertSame(1, preg_match('~const allContactsData = (.*?);\n~', $edit->body, $m));
        $this->assertStringNotContainsString("Kontakt C {$u}", $m[1], 'Neu wählbar sind nur aktive Kontakte');

        // Speichern wie der Browser: die ausgewählten Werte zurück, Farbe geändert.
        $response = $admin->post('/admin/horses/update', [
            'csrf_token' => $edit->formField('csrf_token') ?? '',
            'id' => (string)$k,
            'name' => "Fohlen K {$u}",
            'sex' => 'mare',
            'birth_year' => '2012',
            'status' => 'active',
            'color' => 'Rappe',
            'sire_id' => $this->auswahl($edit->body, 'sire_id'),
            'dam_id' => $this->auswahl($edit->body, 'dam_id'),
            'persons_present' => '1',
            'persons' => [[
                'contact_id' => $this->auswahl($edit->body, 'persons[0][contact_id]'),
                'role' => 'owner',
                'station_contact_id' => $this->auswahl($edit->body, 'persons[0][station_contact_id]'),
                'from_year' => '', 'until_year' => '',
            ]],
        ]);
        $this->assertSame('/admin/horses?success=updated', $response->location(), $response->body);
        $row = $db->query("SELECT sire_id, color FROM horses WHERE id = {$k}")->fetch();
        $this->assertSame($s, (int)$row['sire_id'], 'Vater im Papierkorb bleibt verknüpft');
        $this->assertSame('Rappe', $row['color']);
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM horse_persons WHERE horse_id = {$k} AND contact_id = {$c}")->fetchColumn(), 'Kontakt im Papierkorb bleibt zugeordnet');

        $this->wiederherstellen($admin, 'horse', $s);
        $this->wiederherstellen($admin, 'contact', $c);
        $this->assertSame($s, (int)$db->query("SELECT sire_id FROM horses WHERE id = {$k}")->fetchColumn());
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM horse_persons WHERE horse_id = {$k} AND contact_id = {$c}")->fetchColumn());

        $db->exec("DELETE FROM horses WHERE id IN ({$k}, {$s})");
        $db->exec("DELETE FROM contacts WHERE id = {$c}");
    }

    public function testTrashedRecordsCannotBeNewlyLinked(): void {
        $admin = $this->authenticatedClient();
        $db = $this->db();
        $u = uniqid();

        $db->prepare("INSERT INTO horses (name, deleted_at) VALUES (?, NOW())")->execute(["Papierkorb S {$u}"]);
        $s = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO contacts (name, deleted_at) VALUES (?, NOW())")->execute(["Papierkorb C {$u}"]);
        $c = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO horses (name) VALUES (?)")->execute(["Anderes Pferd {$u}"]);
        $anderes = (int)$db->lastInsertId();

        $form = $admin->get('/admin/horses/create');
        $response = $admin->post('/admin/horses/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => "Neu {$u}", 'status' => 'active', 'sire_id' => (string)$s,
        ]);
        $this->assertSame('/admin/horses?error=parent_in_trash', $response->location());
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM horses WHERE name = " . $db->quote("Neu {$u}"))->fetchColumn());

        $basis = [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'id' => (string)$anderes, 'name' => "Anderes Pferd {$u}", 'status' => 'active',
        ];
        $response = $admin->post('/admin/horses/update', $basis + ['dam_id' => (string)$s]);
        $this->assertSame('/admin/horses?error=parent_in_trash', $response->location());
        $this->assertNull($db->query("SELECT dam_id FROM horses WHERE id = {$anderes}")->fetchColumn());

        // Kontakt im Papierkorb, bisher nicht zugeordnet: Die Zeile bleibt
        // (Herkunftsland), aber ohne Kontakt.
        $response = $admin->post('/admin/horses/update', $basis + [
            'persons_present' => '1',
            'persons' => [['contact_id' => (string)$c, 'role' => 'owner', 'origin_country' => 'NO', 'from_year' => '', 'until_year' => '']],
        ]);
        $this->assertSame('/admin/horses?success=updated', $response->location());
        $zeile = $db->query("SELECT contact_id, origin_country FROM horse_persons WHERE horse_id = {$anderes}")->fetch();
        $this->assertNotFalse($zeile);
        $this->assertNull($zeile['contact_id']);
        $this->assertSame('NO', $zeile['origin_country']);

        $db->exec("DELETE FROM horses WHERE id IN ({$anderes}, {$s})");
        $db->exec("DELETE FROM contacts WHERE id = {$c}");
    }

    /**
     * Eine erhaltene Station im Papierkorb wird nicht neu nach
     * horses.breeding_station gespiegelt (abgestimmt mit M11).
     */
    public function testTrashedStationIsNotMirroredIntoTheHorse(): void {
        $admin = $this->authenticatedClient();
        $db = $this->db();
        $u = uniqid();

        $db->prepare("INSERT INTO contacts (name, deleted_at) VALUES (?, NOW())")->execute(["Station im Papierkorb {$u}"]);
        $station = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO horses (name, breeding_station) VALUES (?, ?)")->execute(["Stationspferd {$u}", "Bisher {$u}"]);
        $pferd = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO horse_persons (horse_id, role, station_contact_id) VALUES (?, 'keeper', ?)")->execute([$pferd, $station]);

        $edit = $admin->get("/admin/horses/edit?id={$pferd}");
        $this->assertStringContainsString("Station im Papierkorb {$u} (im Papierkorb)", $this->select($edit->body, 'persons[0][station_contact_id]'));
        $response = $admin->post('/admin/horses/update', [
            'csrf_token' => $edit->formField('csrf_token') ?? '',
            'id' => (string)$pferd, 'name' => "Stationspferd {$u}", 'status' => 'active',
            'persons_present' => '1',
            'persons' => [[
                'contact_id' => '', 'role' => 'keeper',
                'station_contact_id' => $this->auswahl($edit->body, 'persons[0][station_contact_id]'),
                'from_year' => '', 'until_year' => '',
            ]],
        ]);
        $this->assertSame('/admin/horses?success=updated', $response->location());
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM horse_persons WHERE horse_id = {$pferd} AND station_contact_id = {$station}")->fetchColumn());
        $this->assertSame("Bisher {$u}", $db->query("SELECT breeding_station FROM horses WHERE id = {$pferd}")->fetchColumn());

        $db->exec("DELETE FROM horses WHERE id = {$pferd}");
        $db->exec("DELETE FROM contacts WHERE id = {$station}");
    }
}
