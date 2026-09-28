<?php
// tests/Integration/GroupMembershipTest.php

namespace Tests\Integration;

use App\Permission\GroupMembership;
use PHPUnit\Framework\TestCase;

/**
 * Gruppen zählen nur für aktive Konten (Audit N14).
 *
 * Tiefenverteidigung für Aufrufer, die eine rohe ID übergeben - Addon-Hooks
 * mit $_SESSION['user_id'], API-Schlüssel: Ein gelöschtes oder
 * deaktiviertes Konto hat keine Rechte mehr, auch wenn seine
 * user_groups-Zeilen noch stehen.
 */
class GroupMembershipTest extends TestCase {

    use KontoTestbestand;

    private int $konto;

    protected function setUp(): void {
        $this->konto = $this->kontoAnlegen('gm-' . bin2hex(random_bytes(4)));
        $admin = (int)self::$db->query("SELECT id FROM `groups` WHERE slug = 'admin'")->fetchColumn();
        $this->assertGreaterThan(0, $admin);
        self::$db->prepare("INSERT INTO user_groups (user_id, group_id) VALUES (?, ?)")->execute([$this->konto, $admin]);
    }

    protected function tearDown(): void {
        self::$db->prepare("DELETE FROM user_groups WHERE user_id = ?")->execute([$this->konto]);
        self::$db->prepare("DELETE FROM users WHERE id = ?")->execute([$this->konto]);
    }

    public function testAktiverAdminHatRechte(): void {
        $this->assertTrue(GroupMembership::isAdmin($this->konto));
        $this->assertNotSame([], GroupMembership::groupIds($this->konto));
        $this->assertTrue(GroupMembership::hasPermission($this->konto, 'horses', 'edit'));
    }

    /** @return array<string, array{0: string}> */
    public static function gesperrt(): array {
        return ['gelöscht' => ['deleted_at'], 'deaktiviert' => ['deactivated_at']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('gesperrt')]
    public function testGesperrterAdminHatKeineRechte(string $spalte): void {
        self::$db->prepare("UPDATE users SET `{$spalte}` = NOW() WHERE id = ?")->execute([$this->konto]);

        $this->assertFalse(GroupMembership::isAdmin($this->konto));
        $this->assertSame([], GroupMembership::groupIds($this->konto));
        $this->assertFalse(GroupMembership::hasPermission($this->konto, 'horses', 'edit'));
        $this->assertFalse(GroupMembership::hasPermission($this->konto, 'horses', 'view'));
        $this->assertFalse(GroupMembership::hasInternalAccess($this->konto, 'horses'));
    }

    // ------------------------------------------------------------------
    // Öffentliche Untergrenze (Audit N61) und interne Einsicht (M10/M13)
    // ------------------------------------------------------------------

    /** Konto ohne jede Gruppe. */
    private function mitglied(): int {
        self::$db->prepare("DELETE FROM user_groups WHERE user_id = ?")->execute([$this->konto]);
        return $this->konto;
    }

    private function gastId(): int {
        return (int)self::$db->query("SELECT id FROM `groups` WHERE slug = 'public'")->fetchColumn();
    }

    /** @param list<array{0:string,1:string}> $rechte */
    private function gruppeMit(array $rechte): int {
        $slug = 'gm-gruppe-' . bin2hex(random_bytes(4));
        self::$db->prepare("INSERT INTO `groups` (slug, name) VALUES (?, ?)")->execute([$slug, $slug]);
        $id = (int)self::$db->lastInsertId();
        $insert = self::$db->prepare("INSERT INTO group_permissions (group_id, module, action) VALUES (?, ?, ?)");
        foreach ($rechte as [$modul, $aktion]) {
            $insert->execute([$id, $modul, $aktion]);
        }
        self::$db->prepare("INSERT INTO user_groups (user_id, group_id) VALUES (?, ?)")->execute([$this->konto, $id]);
        return $id;
    }

    /** (a) Addon-Vertrag unverändert, öffentlich gilt die Gast-Untergrenze. */
    public function testMitgliedOhneGruppenSiehtOeffentlichWieEinGast(): void {
        $uid = $this->mitglied();

        $this->assertFalse(GroupMembership::hasPermission($uid, 'horses', 'view'), 'hasPermission() bleibt explizit');
        $this->assertNotContains($this->gastId(), GroupMembership::groupIds($uid), 'Kein Mitglied der Gast-Gruppe');
        $this->assertTrue(GroupMembership::hasPublicPermission($uid, 'horses', 'view'));
        $this->assertTrue(GroupMembership::hasPublicPermission($uid, 'contacts', 'view'));
    }

    /** (b) Ohne Gast-Recht keine Untergrenze. */
    public function testOhneGastrechtKeineUntergrenze(): void {
        $uid = $this->mitglied();
        self::$db->prepare("DELETE FROM group_permissions WHERE group_id = ? AND module = 'horses'")->execute([$this->gastId()]);
        try {
            $this->assertFalse(GroupMembership::hasPublicPermission($uid, 'horses', 'view'));
            $this->assertTrue(GroupMembership::hasPublicPermission($uid, 'contacts', 'view'));
        } finally {
            self::$db->prepare("INSERT IGNORE INTO group_permissions (group_id, module, action) VALUES (?, 'horses', 'view')")
                ->execute([$this->gastId()]);
        }
    }

    /** (c) Eine Altzeile mit Schreibrecht an der Gast-Gruppe öffnet nichts (#218). */
    public function testGastUntergrenzeGiltNurFuerLeserechte(): void {
        $uid = $this->mitglied();
        self::$db->prepare("INSERT IGNORE INTO group_permissions (group_id, module, action) VALUES (?, 'horses', 'edit')")
            ->execute([$this->gastId()]);
        try {
            $this->assertFalse(GroupMembership::guestHasPermission('horses', 'edit'));
            $this->assertFalse(GroupMembership::hasPublicPermission($uid, 'horses', 'edit'));
        } finally {
            self::$db->prepare("DELETE FROM group_permissions WHERE group_id = ? AND module = 'horses' AND action = 'edit'")
                ->execute([$this->gastId()]);
        }
    }

    /** (d) Für Gäste ist die öffentliche Prüfung dieselbe wie bisher. */
    public function testFuerGaesteGleichHasPermission(): void {
        foreach ([['horses', 'view'], ['contacts', 'view'], ['horses', 'edit'], ['stats', 'view']] as [$modul, $aktion]) {
            $this->assertSame(
                GroupMembership::hasPermission(null, $modul, $aktion),
                GroupMembership::hasPublicPermission(null, $modul, $aktion),
                "{$modul}.{$aktion}"
            );
        }
    }

    /** Interne Einsicht: Admin, internal oder ein Bearbeitungsrecht - view allein nicht. */
    public function testInterneEinsicht(): void {
        $this->assertTrue(GroupMembership::hasInternalAccess($this->konto, 'horses'), 'Admin');

        $uid = $this->mitglied();
        $this->assertFalse(GroupMembership::hasInternalAccess($uid, 'horses'), 'ohne Gruppe');
        $this->assertFalse(GroupMembership::hasInternalAccess(null, 'horses'), 'Gast');

        $gruppe = $this->gruppeMit([['horses', 'view'], ['contacts', 'view']]);
        try {
            $this->assertFalse(GroupMembership::hasInternalAccess($uid, 'horses'), 'nur view');
            $this->assertFalse(GroupMembership::hasInternalAccess($uid, 'contacts'), 'nur view');

            $insert = self::$db->prepare("INSERT INTO group_permissions (group_id, module, action) VALUES (?, ?, ?)");
            $insert->execute([$gruppe, 'horses', 'edit']);
            $insert->execute([$gruppe, 'contacts', 'internal']);
            $this->assertTrue(GroupMembership::hasInternalAccess($uid, 'horses'), 'edit ohne internal');
            $this->assertTrue(GroupMembership::hasInternalAccess($uid, 'contacts'), 'internal');

            // Ein create-Recht allein ist bewusst keine interne Einsicht.
            self::$db->prepare("DELETE FROM group_permissions WHERE group_id = ? AND module = 'horses' AND action = 'edit'")->execute([$gruppe]);
            $insert->execute([$gruppe, 'horses', 'create']);
            $this->assertFalse(GroupMembership::hasInternalAccess($uid, 'horses'), 'create');
        } finally {
            self::$db->prepare("DELETE FROM `groups` WHERE id = ?")->execute([$gruppe]);
        }
    }
}
