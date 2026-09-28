<?php
// tests/Integration/EmailVerificationTest.php

namespace Tests\Integration;

use App\Database;
use App\Service\EmailVerification;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Bereinigung unbestätigter Konten (Audit N54, EmailVerification::run()).
 *
 * Die Frist läuft ab `created_at`, nicht ab dem Ablauf des Links - sonst
 * verlängerte jeder Neuversand die Lebensdauer, und wer eine fremde Adresse
 * belegt, hielte sie mit gelegentlichen Anmeldungen für immer. Gegen eine
 * echte MariaDB, weil die Regel aus SQL-Datumsarithmetik besteht.
 */
class EmailVerificationTest extends TestCase {

    private PDO $db;
    private string $praefix = '';

    protected function setUp(): void {
        if (!defined('DB_HOST')) {
            $this->markTestSkipped('Keine Test-Datenbank konfiguriert (DB_HOST fehlt) - siehe tests/bootstrap.php.');
        }
        $this->db = Database::getInstance();
        $this->praefix = 'ev_' . bin2hex(random_bytes(3)) . '_';
    }

    protected function tearDown(): void {
        if ($this->praefix !== '') {
            $this->db->prepare("DELETE FROM users WHERE username LIKE ?")->execute([$this->praefix . '%']);
        }
    }

    /**
     * @param array{alter?: int, ablauf?: ?string, token?: ?string, admin?: bool} $o
     *        alter in Tagen, ablauf als SQL-Ausdruck relativ zu NOW()
     */
    private function konto(string $name, array $o = []): int {
        $token = array_key_exists('token', $o) ? $o['token'] : bin2hex(random_bytes(32));
        $ablauf = array_key_exists('ablauf', $o) ? $o['ablauf'] : 'NOW() - INTERVAL 1 HOUR';
        $stmt = $this->db->prepare(
            "INSERT INTO users (username, email, password_hash, email_verification_token, email_verification_expires_at, created_at)
             VALUES (?, ?, 'x', ?, " . ($ablauf === null ? 'NULL' : $ablauf) . ", NOW() - INTERVAL ? DAY)"
        );
        $stmt->execute([$this->praefix . $name, $this->praefix . $name . '@example.com', $token, $o['alter'] ?? 10]);
        $id = (int)$this->db->lastInsertId();

        if (!empty($o['admin'])) {
            $gruppe = $this->db->query("SELECT id FROM `groups` WHERE slug = 'admin'")->fetchColumn();
            if ($gruppe === false) {
                $this->db->exec("INSERT INTO `groups` (name, slug, is_builtin) VALUES ('Administrator', 'admin', 1)");
                $gruppe = $this->db->lastInsertId();
            }
            $this->db->prepare("INSERT INTO user_groups (user_id, group_id) VALUES (?, ?)")->execute([$id, (int)$gruppe]);
        }
        return $id;
    }

    private function gibt(int $id): bool {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return (int)$stmt->fetchColumn() === 1;
    }

    public function testUnbestaetigtUndUeberDerFristWirdGeloeschtUndGibtDieAdresseFrei(): void {
        $id = $this->konto('alt');
        EmailVerification::run();

        $this->assertFalse($this->gibt($id));
        // Der UNIQUE-Wert ist wieder frei.
        $stmt = $this->db->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, 'x')");
        $stmt->execute([$this->praefix . 'alt', $this->praefix . 'alt@example.com']);
        $this->assertGreaterThan(0, (int)$this->db->lastInsertId());
    }

    /** Regressionsschutz: Ein Neuversand verlängert die Lebensdauer nicht. */
    public function testErneuerterAberAbgelaufenerLinkSchuetztNicht(): void {
        $id = $this->konto('erneuert', ['alter' => 10, 'ablauf' => 'NOW() - INTERVAL 1 MINUTE']);
        EmailVerification::run();
        $this->assertFalse($this->gibt($id));
    }

    public function testGesperrteUndGeloeschteKontenFallenEbenfallsDarunter(): void {
        $id = $this->konto('papierkorb');
        $this->db->prepare("UPDATE users SET deleted_at = NOW(), deactivated_at = NOW() WHERE id = ?")->execute([$id]);
        EmailVerification::run();
        $this->assertFalse($this->gibt($id));
    }

    public function testJungesKontoBleibt(): void {
        $id = $this->konto('jung', ['alter' => 3]);
        EmailVerification::run();
        $this->assertTrue($this->gibt($id));
    }

    public function testGueltigerLinkBleibt(): void {
        $id = $this->konto('gueltig', ['alter' => 10, 'ablauf' => 'NOW() + INTERVAL 1 HOUR']);
        EmailVerification::run();
        $this->assertTrue($this->gibt($id));
    }

    public function testBestaetigtesKontoBleibt(): void {
        $id = $this->konto('bestaetigt', ['token' => null, 'ablauf' => null, 'alter' => 400]);
        EmailVerification::run();
        $this->assertTrue($this->gibt($id));
    }

    public function testAdminBleibt(): void {
        $id = $this->konto('admin', ['admin' => true]);
        EmailVerification::run();
        $this->assertTrue($this->gibt($id));
    }

    public function testTokenOhneAblaufIstNieBestaetigbarUndWirdGeloescht(): void {
        $id = $this->konto('ohneablauf', ['ablauf' => null]);
        EmailVerification::run();
        $this->assertFalse($this->gibt($id));
    }

    public function testVerknuepfteZeilenVerschwindenMit(): void {
        $id = $this->konto('kaskade');
        $this->db->prepare(
            "INSERT INTO email_2fa_codes (user_id, purpose, code_hash, expires_at) VALUES (?, 'login', 'x', NOW() + INTERVAL 5 MINUTE)"
        )->execute([$id]);

        $ergebnis = EmailVerification::run();

        $this->assertFalse($this->gibt($id));
        $this->assertSame(0, $ergebnis['fehlgeschlagen']);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM email_2fa_codes WHERE user_id = ?");
        $stmt->execute([$id]);
        $this->assertSame(0, (int)$stmt->fetchColumn());
    }
}
