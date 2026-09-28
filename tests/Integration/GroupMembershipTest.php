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
    }
}
