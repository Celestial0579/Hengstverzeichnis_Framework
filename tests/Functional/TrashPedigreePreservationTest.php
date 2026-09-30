<?php
// tests/Functional/TrashPedigreePreservationTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Audit N58: Das endgültige Löschen eines Elterntiers löschte bisher per
 * ON DELETE SET NULL die Abstammungsangabe aller Nachkommen - beim Verknüpfen
 * war der Freitext (sire_name/sire_ueln) geleert worden, danach stand dort
 * gar nichts mehr. Jetzt übernimmt TrashController Name und UELN als
 * Freitext, bevor das DELETE läuft, und der Papierkorb nennt die Zahl der
 * Nachkommen vorher.
 *
 * Alles in einer Methode, weil die Schritte aufeinander aufbauen.
 */
class TrashPedigreePreservationTest extends FunctionalTestCase {

    private function db(): \PDO {
        return Database::getInstance();
    }

    /** @return array<string, mixed> */
    private function pferd(int $id): array {
        $stmt = $this->db()->prepare("SELECT * FROM horses WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: [];
    }

    private function inDenPapierkorb(\Tests\Support\HttpClient $admin, int $id): void {
        $response = $admin->post('/admin/horses/delete', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'id' => (string)$id,
        ]);
        $this->assertSame(302, $response->statusCode);
        $this->assertNotNull($this->pferd($id)['deleted_at'], "Pferd {$id} sollte im Papierkorb liegen");
    }

    public function testPurgedParentsLeaveNameAndUelnAsPlaceholderInTheirOffspring(): void {
        $admin = $this->authenticatedClient();
        $db = $this->db();
        $u = substr(md5(uniqid('', true)), 0, 12);

        $uelnS = 'DE' . strtoupper(substr($u, 0, 12)) . 'X';        // genau 15 Zeichen
        $uelnM = 'DE' . strtoupper($u) . 'MMMMMM';                  // 20 Zeichen - passt nicht in dam_ueln
        $this->assertSame(15, strlen($uelnS));
        $this->assertSame(20, strlen($uelnM));

        $insert = $db->prepare("INSERT INTO horses (name, ueln, sex, birth_year, sire_id, dam_id) VALUES (?, ?, ?, ?, ?, ?)");
        $insert->execute(["Vater {$u}", $uelnS, 'stallion', 2000, null, null]);
        $s = (int)$db->lastInsertId();
        $insert->execute(["Mutter {$u}", $uelnM, 'mare', 2001, null, null]);
        $m = (int)$db->lastInsertId();
        $insert->execute(["Fohlen1 {$u}", null, 'mare', 2012, $s, null]);
        $k1 = (int)$db->lastInsertId();
        $insert->execute(["Fohlen2 {$u}", null, 'stallion', 2013, null, $m]);
        $k2 = (int)$db->lastInsertId();

        // (5) vorweg - Negativfall zu N57: in den Papierkorb, wiederherstellen,
        // dann der alte "Endgültig löschen"-Knopf aus einem veralteten Tab.
        $this->inDenPapierkorb($admin, $s);
        $restore = $admin->post('/admin/trash/restore', [
            'csrf_token' => $this->currentCsrfToken($admin), 'type' => 'horse', 'id' => (string)$s,
        ]);
        $this->assertSame('/admin/trash?success=restored', $restore->location());
        $alt = $admin->post('/admin/trash/permanent-delete', [
            'csrf_token' => $this->currentCsrfToken($admin), 'type' => 'horse', 'id' => (string)$s,
        ]);
        $this->assertSame('/admin/trash?error=not_in_trash', $alt->location());
        $this->assertNotSame([], $this->pferd($s), 'Das wiederhergestellte Pferd bleibt bestehen');
        $this->assertSame($s, (int)$this->pferd($k1)['sire_id'], 'Das Fohlen behält die Verknüpfung');
        $this->assertNull($this->pferd($k1)['sire_name'], 'Kein Freitext für ein Pferd, das stehen bleibt');

        // (1) S und M in den Papierkorb: Der Papierkorb nennt die Nachkommen
        // in der Zeile und im Bestätigungstext.
        $this->inDenPapierkorb($admin, $s);
        $this->inDenPapierkorb($admin, $m);
        $trash = $admin->get('/admin/trash');
        $this->assertSame(200, $trash->statusCode);
        $this->assertStringContainsString('1 Nachkomme verweist auf dieses Pferd', $trash->body);
        $this->assertStringContainsString('1 Nachkommen behalten Name und UELN als Freitext; die Verknüpfung entfällt.', $trash->body);

        // (2) Admin löscht S endgültig: K1 trägt Name und UELN als Freitext.
        $purge = $admin->post('/admin/trash/permanent-delete', [
            'csrf_token' => $this->currentCsrfToken($admin), 'type' => 'horse', 'id' => (string)$s,
        ]);
        $this->assertSame('/admin/trash?success=purged', $purge->location());
        $this->assertSame([], $this->pferd($s));
        $kind = $this->pferd($k1);
        $this->assertNull($kind['sire_id']);
        $this->assertSame("Vater {$u}", $kind['sire_name']);
        $this->assertSame($uelnS, $kind['sire_ueln']);
        $stmt = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'Element endgültig gelöscht' AND details = ?");
        $stmt->execute(["Typ: horse, ID: {$s} – 1 Nachkommen: Abstammung als Freitext übernommen"]);
        $this->assertSame(1, (int)$stmt->fetchColumn(), 'Das Audit nennt die Zahl der Nachkommen');

        // (3) Papierkorb leeren: K2 trägt den Namen, die 20-stellige UELN passt
        // nicht in dam_ueln (VARCHAR(15)) und bleibt leer - kein 500.
        $empty = $admin->post('/admin/trash/empty', ['csrf_token' => $this->currentCsrfToken($admin)]);
        $this->assertSame('/admin/trash?success=emptied', $empty->location());
        $this->assertSame([], $this->pferd($m));
        $kind2 = $this->pferd($k2);
        $this->assertNull($kind2['dam_id']);
        $this->assertSame("Mutter {$u}", $kind2['dam_name']);
        $this->assertNull($kind2['dam_ueln']);
        $stmt = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'Papierkorb geleert (Admin)' AND details LIKE ?");
        $stmt->execute(['%Nachkommen: Abstammung als Freitext übernommen']);
        $this->assertGreaterThanOrEqual(1, (int)$stmt->fetchColumn());

        // (4) Ein neues Pferd mit S.ueln verknüpft K1 über autoLinkMatches wieder.
        $form = $admin->get('/admin/horses/create');
        $store = $admin->post('/admin/horses/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => "Vater neu {$u}",
            'ueln' => $uelnS,
            'sex' => 'stallion',
            'birth_year' => '2000',
            'status' => 'active',
        ]);
        $this->assertSame('/admin/horses?success=created', $store->location());
        $stmt = $db->prepare("SELECT id FROM horses WHERE ueln = ?");
        $stmt->execute([$uelnS]);
        $neu = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $neu);
        $this->assertSame($neu, (int)$this->pferd($k1)['sire_id'], 'Der Freitext-Platzhalter wird erneut verknüpft');

        $db->prepare("DELETE FROM horses WHERE id IN (?, ?, ?)")->execute([$k1, $k2, $neu]);
    }

    /** Freitext, der schon am Nachkommen steht, wird nicht überschrieben. */
    public function testExistingPlaceholderTextIsKept(): void {
        $admin = $this->authenticatedClient();
        $db = $this->db();
        $u = uniqid();

        $db->prepare("INSERT INTO horses (name, sex, deleted_at) VALUES (?, 'stallion', NOW())")->execute(["Alt {$u}"]);
        $s = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO horses (name, sire_id, sire_name) VALUES (?, ?, ?)")->execute(["Kind {$u}", $s, "Handschrift {$u}"]);
        $k = (int)$db->lastInsertId();

        $purge = $admin->post('/admin/trash/permanent-delete', [
            'csrf_token' => $this->currentCsrfToken($admin), 'type' => 'horse', 'id' => (string)$s,
        ]);
        $this->assertSame('/admin/trash?success=purged', $purge->location());
        $kind = $this->pferd($k);
        $this->assertNull($kind['sire_id']);
        $this->assertSame("Handschrift {$u}", $kind['sire_name']);
        $db->prepare("DELETE FROM horses WHERE id = ?")->execute([$k]);
    }
}
