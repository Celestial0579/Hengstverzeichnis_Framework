<?php
// tests/Integration/UpdateRecipientReachabilityTest.php

namespace Tests\Integration;

use App\Database;
use App\Service\UpdateService;
use PHPUnit\Framework\TestCase;

/**
 * UpdateService::hasReachableAdminRecipient() gegen die echte Datenbank.
 *
 * Die zweite Ebene der Zusage „wer automatisch einspielen lässt, muss
 * erfahren, was passiert ist": Mailer::isDeliverable() prüft den Transport,
 * diese Prüfung die Empfänger. Beide braucht es, weil beide einzeln
 * durchgehen können und die Mail trotzdem niemanden erreicht.
 *
 * Anlass ist ein Befund von der Entwicklungsinstanz dieses Hosts: Von vier
 * Admin-Konten zeigten drei auf `@migration.invalid` aus einer
 * Altdatenmigration. SMTP war korrekt eingerichtet, die Benachrichtigung
 * eingeschaltet — und es gab keinen erreichbaren Menschen.
 */
class UpdateRecipientReachabilityTest extends TestCase {

    /** @var array<int, int> Angelegte Benutzer-IDs, werden wieder entfernt. */
    private array $angelegt = [];

    private int $adminGruppe = 0;

    /** @var array<int, int> Vor dem Test vorhandene Admin-Mitgliedschaften. */
    private array $beiseitegelegt = [];

    protected function setUp(): void {
        if (getenv('DB_HOST') === false) {
            $this->markTestSkipped('Braucht die Testdatenbank - DB_HOST & Co. setzen.');
        }

        $db = Database::getInstance();
        $this->adminGruppe = (int)$db->query("SELECT id FROM `groups` WHERE slug = 'admin' LIMIT 1")->fetchColumn();
        $this->assertGreaterThan(0, $this->adminGruppe, 'Ohne Admin-Gruppe prüft der Test nichts.');

        // Vorhandene Admins für die Dauer des Tests aus der Gruppe nehmen und
        // die Mitgliedschaften merken - sonst hinge das Ergebnis am Bestand
        // der geteilten Testdatenbank. Gelöscht wird nur die Zuordnung, nie
        // ein Benutzer; tearDown legt sie unverändert zurück.
        $stmt = $db->prepare("SELECT user_id FROM user_groups WHERE group_id = ?");
        $stmt->execute([$this->adminGruppe]);
        $this->beiseitegelegt = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        $db->prepare("DELETE FROM user_groups WHERE group_id = ?")->execute([$this->adminGruppe]);
    }

    protected function tearDown(): void {
        if ($this->adminGruppe === 0) {
            return;
        }
        $db = Database::getInstance();
        foreach ($this->angelegt as $id) {
            $db->prepare("DELETE FROM user_groups WHERE user_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
        }
        $wieder = $db->prepare("INSERT IGNORE INTO user_groups (user_id, group_id) VALUES (?, ?)");
        foreach ($this->beiseitegelegt as $userId) {
            $wieder->execute([$userId, $this->adminGruppe]);
        }
        $this->beiseitegelegt = [];
        $this->angelegt = [];
    }

    public function testOnlyReservedDomainsCountAsUnreachable(): void {
        // Der Befund von der Dev-Instanz: Nur Adressen, die es per Norm nicht
        // geben kann.
        $this->adminAnlegen('alt1@migration.invalid');
        $this->adminAnlegen('alt2@migration.invalid');
        $this->adminAnlegen('alt3@irgendwas.test');

        $this->assertFalse(
            UpdateService::hasReachableAdminRecipient(),
            'Reservierte Endungen (RFC 2606) sind nie zustellbar - das ist kein erreichbarer Empfänger.'
        );

        // Eine einzige brauchbare Adresse genügt.
        $this->adminAnlegen('echt@example.com');
        $this->assertTrue(UpdateService::hasReachableAdminRecipient());
    }

    public function testAddressWithoutDomainOrDotIsUnreachable(): void {
        $this->adminAnlegen('kaputt-ohne-at');
        $this->adminAnlegen('lokal@rechnername');

        $this->assertFalse(
            UpdateService::hasReachableAdminRecipient(),
            'Ohne @ oder ohne Punkt in der Domain lässt sich nichts zustellen.'
        );
    }

    public function testNoAdminAtAllIsUnreachable(): void {
        $this->assertFalse(
            UpdateService::hasReachableAdminRecipient(),
            'Keine Admin-Konten heißt: niemand, den man benachrichtigen könnte.'
        );
    }

    private function adminAnlegen(string $email): void {
        $db = Database::getInstance();
        $name = 'reachtest_' . bin2hex(random_bytes(5));
        $db->prepare(
            "INSERT INTO users (username, email, password_hash, created_at)
             VALUES (?, ?, ?, NOW())"
        )->execute([$name, $email, password_hash('irrelevant', PASSWORD_DEFAULT)]);

        $id = (int)$db->lastInsertId();
        $this->angelegt[] = $id;
        $db->prepare("INSERT INTO user_groups (user_id, group_id) VALUES (?, ?)")->execute([$id, $this->adminGruppe]);
    }
}
