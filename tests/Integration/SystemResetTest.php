<?php
// tests/Integration/SystemResetTest.php

namespace Tests\Integration;

use App\Service\InstallEpoch;
use App\Service\SystemReset;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Der Werksreset gegen eine echte Datenbank (Audit N79, M24).
 *
 * Eigene Wegwerf-Datenbank wie SchemaMigratorTest: Der Reset leert alles,
 * und die reguläre Test-Datenbank gehört anderen Klassen.
 */
class SystemResetTest extends TestCase {

    private static PDO $adminPdo;
    private static PDO $pdo;

    private static function testDatenbank(): string {
        return \Tests\Support\WegwerfDatenbank::name('system_reset');
    }

    public static function setUpBeforeClass(): void {
        if (!defined('DB_HOST')) {
            self::markTestSkipped('Keine Test-Datenbank konfiguriert (DB_HOST fehlt) - siehe tests/bootstrap.php.');
        }
        $adminDsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4";
        self::$adminPdo = new PDO($adminDsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        self::$adminPdo->exec("DROP DATABASE IF EXISTS `" . self::testDatenbank() . "`");
        self::$adminPdo->exec("CREATE DATABASE `" . self::testDatenbank() . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        self::$pdo = new PDO(
            "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . self::testDatenbank() . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        try {
            self::$pdo->exec((string)file_get_contents(__DIR__ . '/../../database/schema.sql'));
        } catch (\PDOException $e) {
            // Wie SetupController::provision().
        }
    }

    public static function tearDownAfterClass(): void {
        if (isset(self::$adminPdo)) {
            self::$adminPdo->exec("DROP DATABASE IF EXISTS `" . self::testDatenbank() . "`");
        }
    }

    private static function anzahl(string $tabelle): int {
        return (int)self::$pdo->query("SELECT COUNT(*) FROM `{$tabelle}`")->fetchColumn();
    }

    private function bestandAnlegen(): void {
        $p = self::$pdo;
        foreach ([1, 2, 3] as $i) {
            $p->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, 'x')")
                ->execute(["alt{$i}", "alt{$i}@example.org"]);
        }
        $p->exec("INSERT INTO horses (name) VALUES ('Altpferd 1'), ('Altpferd 2')");
        $p->exec("INSERT INTO contacts (name) VALUES ('Altkontakt 1'), ('Altkontakt 2')");
        $admin = (int)$p->query("SELECT id FROM `groups` WHERE slug = 'admin'")->fetchColumn();
        $p->prepare("INSERT INTO user_groups (user_id, group_id) VALUES (1, ?)")->execute([$admin]);
        $p->exec("INSERT INTO api_keys (user_id, label, token_hash, token_prefix, expires_at) VALUES (1, 'alt', 'hash-alt', 'hv_alt', NOW() + INTERVAL 1 DAY)");
        $p->exec("INSERT INTO login_attempts (identifier, type) VALUES ('alt1@example.org', 'login')");
        $p->exec("INSERT INTO audit_logs (action, category, username) VALUES ('Vorher', 'test', 'SYSTEM')");
        $p->exec("INSERT INTO plugins (slug, enabled, installed_version) VALUES ('verkaufsboerse', 1, '1.0.0')");
        $p->exec("INSERT INTO addon_repos (owner, repo) VALUES ('beispiel', 'addons-reset-test')");
        $p->exec("REPLACE INTO settings (setting_key, setting_value) VALUES ('site_name', 'Altverband'), ('install_epoch', 'alte-epoche')");

        $p->exec("CREATE TABLE plugin_test_reset (id INT AUTO_INCREMENT PRIMARY KEY, horse_id INT NOT NULL, seller_email VARCHAR(100)) ENGINE=InnoDB");
        $p->exec("INSERT INTO plugin_test_reset (horse_id, seller_email) VALUES (1, 'verkaeufer@example.org'), (2, 'b@example.org')");
        $p->exec("CREATE TABLE persons_pre_contacts (id INT PRIMARY KEY, name VARCHAR(100), membership_status VARCHAR(50)) ENGINE=InnoDB");
        $p->exec("INSERT INTO persons_pre_contacts (id, name, membership_status) VALUES (1, 'Alt', 'Mitglied')");
        $p->exec("CREATE TABLE fremd_tabelle (id INT PRIMARY KEY) ENGINE=InnoDB");
        $p->exec("INSERT INTO fremd_tabelle (id) VALUES (1), (2)");
    }

    public function testResetLeertKernAddonsUndAltbestandUndVergibtKeineIdsNeu(): void {
        $this->bestandAnlegen();
        $unveraendert = [];
        foreach (['fremd_tabelle', 'audit_logs', 'groups', 'group_permissions', 'plugins', 'addon_repos'] as $t) {
            $unveraendert[$t] = self::anzahl($t);
            $this->assertGreaterThan(0, $unveraendert[$t], "Vorbedingung: {$t} hat Zeilen");
        }

        $tabellen = SystemReset::tabellen(self::$pdo);
        $this->assertContains('plugin_test_reset', $tabellen['addons']);
        $this->assertNotContains('plugins', $tabellen['addons']);
        $this->assertSame(['persons_pre_contacts'], $tabellen['altbestand']);

        $geleert = SystemReset::truncateAll(self::$pdo);

        $this->assertContains('plugin_test_reset', $geleert);
        $this->assertContains('persons_pre_contacts', $geleert);
        $this->assertNotContains('plugins', $geleert);
        $this->assertNotContains('fremd_tabelle', $geleert);

        foreach (array_merge(SystemReset::TABLES, ['plugin_test_reset', 'persons_pre_contacts']) as $t) {
            if ($t === 'settings') {
                continue;
            }
            $this->assertSame(0, self::anzahl($t), "{$t} muss nach dem Reset leer sein");
        }
        foreach ($unveraendert as $t => $n) {
            $this->assertSame($n, self::anzahl($t), "{$t} muss den Reset überstehen");
        }

        // Nur noch die neue Epoche steht in settings.
        $settings = self::$pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame([InstallEpoch::SETTING], array_keys($settings));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $settings[InstallEpoch::SETTING]);
        $this->assertNotSame('alte-epoche', $settings[InstallEpoch::SETTING]);

        // Die Zähler laufen weiter: keine Kennung wird neu vergeben.
        self::$pdo->exec("INSERT INTO users (username, email, password_hash) VALUES ('neu', 'neu@example.org', 'x')");
        $this->assertSame(4, (int)self::$pdo->lastInsertId());
        self::$pdo->exec("INSERT INTO horses (name) VALUES ('Neupferd')");
        $this->assertSame(3, (int)self::$pdo->lastInsertId());
        self::$pdo->exec("INSERT INTO contacts (name) VALUES ('Neukontakt')");
        $this->assertSame(3, (int)self::$pdo->lastInsertId());

        // Fremdschlüsselprüfung ist wieder an.
        $this->assertSame('1', (string)self::$pdo->query("SELECT @@FOREIGN_KEY_CHECKS")->fetchColumn());
    }
}
