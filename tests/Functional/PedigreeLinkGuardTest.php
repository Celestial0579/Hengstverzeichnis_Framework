<?php
// tests/Functional/PedigreeLinkGuardTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Audit N47: Match-Werkzeug (linkMatch) und automatische Verknüpfung
 * (autoLinkMatches) umgingen die Abstammungsprüfungen des Formulars -
 * Geschlecht, Alter, Vater ≠ Mutter - und kannten keine Zyklusprüfung.
 */
class PedigreeLinkGuardTest extends FunctionalTestCase {

    private function db(): \PDO {
        return Database::getInstance();
    }

    /** Legt ein Pferd direkt an; $felder überschreibt die Vorgaben. */
    private function pferd(array $felder): int {
        $felder += ['name' => 'Pferd ' . uniqid(), 'sex' => null, 'birth_year' => null];
        $spalten = array_keys($felder);
        $this->db()->prepare(
            "INSERT INTO horses (" . implode(', ', $spalten) . ") VALUES (" . implode(', ', array_fill(0, count($spalten), '?')) . ")"
        )->execute(array_values($felder));
        return (int)$this->db()->lastInsertId();
    }

    /** @return array<string, mixed> */
    private function zeile(int $id): array {
        $stmt = $this->db()->prepare("SELECT * FROM horses WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: [];
    }

    private function link(\Tests\Support\HttpClient $admin, int $kind, string $typ, int $eltern): ?string {
        return $admin->post('/admin/matches/link', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'child_id' => (string)$kind, 'parent_type' => $typ, 'parent_horse_id' => (string)$eltern,
        ])->location();
    }

    private function anlegen(\Tests\Support\HttpClient $admin, array $felder): int {
        $form = $admin->get('/admin/horses/create');
        $response = $admin->post('/admin/horses/store', $felder + [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'status' => 'active',
        ]);
        $this->assertSame('/admin/horses?success=created', $response->location(), $response->body);
        $stmt = $this->db()->prepare("SELECT id FROM horses WHERE name = ?");
        $stmt->execute([$felder['name']]);
        return (int)$stmt->fetchColumn();
    }

    public function testLinkMatchAppliesTheFormChecks(): void {
        $admin = $this->authenticatedClient();
        $u = uniqid();
        $ids = [];

        // (1) Kandidat jünger als das Kind.
        $kind = $ids[] = $this->pferd(['name' => "Kind1 {$u}", 'birth_year' => 2010, 'sire_name' => "Jungspund {$u}"]);
        $jung = $ids[] = $this->pferd(['name' => "Jungspund {$u}", 'sex' => 'stallion', 'birth_year' => 2015]);
        $this->assertSame('/admin/matches?error=sire_not_older', $this->link($admin, $kind, 'sire', $jung));
        $this->assertNull($this->zeile($kind)['sire_id']);
        $this->assertSame("Jungspund {$u}", $this->zeile($kind)['sire_name']);

        // (2) Zyklus ohne Geburtsjahre: A ist Vater von B, A.sire := B.
        $a = $ids[] = $this->pferd(['name' => "A {$u}", 'sex' => 'stallion']);
        $b = $ids[] = $this->pferd(['name' => "B {$u}", 'sex' => 'stallion', 'sire_id' => $a]);
        $this->assertSame('/admin/matches?error=pedigree_cycle', $this->link($admin, $a, 'sire', $b));
        $this->assertNull($this->zeile($a)['sire_id']);

        // (3) Mutter := geschlechtsloses Pferd, das schon Vater des Kindes ist.
        $ohne = $ids[] = $this->pferd(['name' => "Ohne {$u}"]);
        $kind3 = $ids[] = $this->pferd(['name' => "Kind3 {$u}", 'sire_id' => $ohne]);
        $this->assertSame('/admin/matches?error=same_sire_and_dam', $this->link($admin, $kind3, 'dam', $ohne));
        $this->assertNull($this->zeile($kind3)['dam_id']);

        // (4) Kandidat im Papierkorb.
        $trash = $ids[] = $this->pferd(['name' => "Trash {$u}", 'sex' => 'mare', 'deleted_at' => date('Y-m-d H:i:s')]);
        $kind4 = $ids[] = $this->pferd(['name' => "Kind4 {$u}"]);
        $this->assertSame('/admin/matches?error=parent_in_trash', $this->link($admin, $kind4, 'dam', $trash));

        // (5) Kind im Papierkorb - Schreibschutz #322.
        $mutter = $ids[] = $this->pferd(['name' => "Mutter {$u}", 'sex' => 'mare']);
        $kind5 = $ids[] = $this->pferd(['name' => "Kind5 {$u}", 'dam_name' => 'Frei', 'deleted_at' => date('Y-m-d H:i:s')]);
        $this->assertSame('/admin/matches?error=child_in_trash', $this->link($admin, $kind5, 'dam', $mutter));
        $this->assertNull($this->zeile($kind5)['dam_id']);
        $this->assertSame('Frei', $this->zeile($kind5)['dam_name']);

        // (6) Ungültiger parent_type meldet keinen Erfolg.
        $this->assertSame('/admin/matches?error=invalid', $this->link($admin, $kind4, 'foo', $mutter));
        $this->assertSame('/admin/matches?error=invalid', $this->link($admin, 0, 'dam', $mutter));

        // Gegenprobe: ein gültiger Link geht weiter durch.
        $this->assertSame('/admin/matches?success=linked', $this->link($admin, $kind4, 'dam', $mutter));
        $this->assertSame($mutter, (int)$this->zeile($kind4)['dam_id']);

        $this->db()->exec("UPDATE horses SET sire_id = NULL, dam_id = NULL WHERE id IN (" . implode(',', $ids) . ")");
        $this->db()->exec("DELETE FROM horses WHERE id IN (" . implode(',', $ids) . ")");
    }

    public function testSuggestionWithImpossibleAgeCannotBeLinkedWithOneClick(): void {
        $admin = $this->authenticatedClient();
        $u = uniqid();
        $kind = $this->pferd(['name' => "000 Blockkind {$u}", 'birth_year' => 2010, 'sire_name' => "Blockvater {$u}"]);
        $jung = $this->pferd(['name' => "Blockvater {$u}", 'sex' => 'stallion', 'birth_year' => 2012]);

        $seite = $admin->get('/admin/matches');
        $this->assertSame(200, $seite->statusCode);
        $pos = strpos($seite->body, 'name="parent_horse_id" value="' . $jung . '"');
        $this->assertNotFalse($pos, 'Der Vorschlag bleibt sichtbar');
        $this->assertMatchesRegularExpression('~^.{0,400}?<button type="submit"[^>]*\bdisabled\b[^>]*title="Elternteil nicht älter als das Fohlen~s', substr($seite->body, $pos));

        $this->db()->exec("DELETE FROM horses WHERE id IN ({$kind}, {$jung})");
    }

    public function testAutoLinkAppliesTheFormChecks(): void {
        $admin = $this->authenticatedClient();
        $db = $this->db();
        $u = uniqid();
        $ids = [];

        // (7) sire_name = dam_name; eine Stute dieses Namens wird nur Mutter.
        $kind7 = $ids[] = $this->pferd(['name' => "Kind7 {$u}", 'sire_name' => "Kim-{$u}", 'dam_name' => "Kim-{$u}"]);
        $kim = $ids[] = $this->anlegen($admin, ['name' => "Kim-{$u}", 'sex' => 'mare']);
        $this->assertNull($this->zeile($kind7)['sire_id']);
        $this->assertSame("Kim-{$u}", $this->zeile($kind7)['sire_name'], 'Platzhalter bleibt');
        $this->assertSame($kim, (int)$this->zeile($kind7)['dam_id']);

        // (8) UELN-Zweig mit Altersprüfung.
        $x = 'X' . substr(md5($u), 0, 12);
        $kind8 = $ids[] = $this->pferd(['name' => "Kind8 {$u}", 'birth_year' => 2010, 'sire_ueln' => $x]);
        $vater = $ids[] = $this->anlegen($admin, ['name' => "Vater8 {$u}", 'sex' => 'stallion', 'birth_year' => '2015', 'ueln' => $x]);
        $this->assertNull($this->zeile($kind8)['sire_id'], 'Jüngerer Hengst wird nicht verknüpft');
        $form = $admin->get("/admin/horses/edit?id={$vater}");
        $response = $admin->post('/admin/horses/update', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'id' => (string)$vater, 'name' => "Vater8 {$u}", 'sex' => 'stallion', 'birth_year' => '2000', 'ueln' => $x, 'status' => 'active',
        ]);
        $this->assertSame('/admin/horses?success=updated', $response->location());
        $this->assertSame($vater, (int)$this->zeile($kind8)['sire_id'], 'Mit plausiblem Jahrgang wird verknüpft');

        // (9) Zyklus: C trägt sire_ueln Y; H wird mit sire_id=C und ueln=Y angelegt.
        $y = 'Y' . substr(md5($u), 0, 12);
        $c = $ids[] = $this->pferd(['name' => "C9 {$u}", 'sex' => 'stallion', 'sire_ueln' => $y]);
        $h = $ids[] = $this->anlegen($admin, ['name' => "H9 {$u}", 'sex' => 'stallion', 'ueln' => $y, 'sire_id' => (string)$c]);
        $this->assertSame($c, (int)$this->zeile($h)['sire_id']);
        $this->assertNull($this->zeile($c)['sire_id'], 'Kein Kreis im Stammbaum');
        $this->assertSame($y, $this->zeile($c)['sire_ueln']);

        // (10) Geschlechtsloses Pferd; Kind mit sire_name = dam_name: nur Vater.
        $kind10 = $ids[] = $this->pferd(['name' => "Kind10 {$u}", 'sire_name' => "Odin-{$u}", 'dam_name' => "Odin-{$u}"]);
        $odin = $ids[] = $this->anlegen($admin, ['name' => "Odin-{$u}"]);
        $this->assertSame($odin, (int)$this->zeile($kind10)['sire_id']);
        $this->assertNull($this->zeile($kind10)['dam_id'], 'Vater und Mutter nicht dasselbe Pferd');
        $this->assertSame("Odin-{$u}", $this->zeile($kind10)['dam_name']);

        $db->exec("UPDATE horses SET sire_id = NULL, dam_id = NULL WHERE id IN (" . implode(',', $ids) . ")");
        $db->exec("DELETE FROM horses WHERE id IN (" . implode(',', $ids) . ")");
    }
}
