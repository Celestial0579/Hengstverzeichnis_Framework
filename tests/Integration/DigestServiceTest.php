<?php
// tests/Integration/DigestServiceTest.php

namespace Tests\Integration;

use App\Database;
use App\Service\DigestService;
use App\Service\Scheduler;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Prüft App\Service\DigestService (#52) gegen eine echte Test-Datenbank:
 * Zählung offener Match-Vorschläge (App\Service\MatchSuggestionFinder) und
 * bald ablaufender Papierkorb-Fristen, das bewusste Nicht-Versenden ohne
 * etwas zu berichten, sowie die Scheduler-Registrierung (#67).
 *
 * Dateiname bewusst nicht mit "B"/"D" + Buchstabe vor "at" beginnend (z. B.
 * "DatabaseDigestTest.php"): würde alphabetisch vor DatabaseTest.php laufen
 * und dessen Anforderung brechen, der erste Aufrufer von
 * App\Database::getInstance() im gesamten PHPUnit-Prozess zu sein (siehe
 * Klassendoc dort sowie tests/Integration/DumpAndRestoreTest.php für
 * dieselbe Problematik) - "DigestServiceTest.php" sortiert alphabetisch
 * nach "DatabaseTest.php" ("Da" < "Di").
 *
 * Ein tatsächlicher E-Mail-Versand gegen einen echten/simulierten SMTP-
 * Server ist bewusst nicht Teil dieses Tests: App\Service\Mailer nutzt
 * einen bereits bestehenden, ungeänderten SMTP-Client (siehe
 * Mailer::sendViaSmtp()), diese Tests decken nur die neue Zähl-/
 * Entscheidungslogik von DigestService ab. Ohne konfigurierten SMTP-Server
 * gibt Mailer::send() kontrolliert `false` zurück (siehe dortige
 * Konfigurationsprüfung) - DigestService::run() muss das als
 * "vollständiger Fehlschlag" erkennen, wenn tatsächlich etwas zu berichten
 * war.
 */
class DigestServiceTest extends TestCase {

    private static PDO $db;

    public static function setUpBeforeClass(): void {
        if (!defined('DB_HOST')) {
            self::markTestSkipped('Keine Test-Datenbank konfiguriert (DB_HOST fehlt) - siehe tests/bootstrap.php.');
        }

        $setupPdo = new PDO(
            "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $setupPdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        foreach ($setupPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $setupPdo->exec("DROP TABLE IF EXISTS `$table`");
        }
        $setupPdo->exec("SET FOREIGN_KEY_CHECKS = 1");

        $schemaFile = __DIR__ . '/../../database/schema.sql';
        try {
            $setupPdo->exec(file_get_contents($schemaFile));
        } catch (PDOException $e) {
            // Ignorieren, analog zu SetupController::provision()
        }

        self::$db = Database::getInstance();
    }

    protected function setUp(): void {
        Scheduler::resetForTests();
        self::$db->exec("DELETE FROM settings WHERE setting_key LIKE 'digest_%' OR setting_key LIKE 'cron_last_run__%'");
        self::$db->exec("DELETE FROM horses");
        self::$db->exec("DELETE FROM persons");
        self::$db->exec("DELETE FROM breeding_stations");
        self::$db->exec("DELETE FROM users WHERE role IN ('admin', 'editor')");
    }

    private function insertHorse(array $overrides = []): int {
        $data = array_merge([
            'name' => 'Testpferd ' . uniqid(),
            'ueln' => null,
            'sire_id' => null, 'sire_name' => null, 'sire_ueln' => null,
            'dam_id' => null, 'dam_name' => null, 'dam_ueln' => null,
            'birth_year' => null,
            'deleted_at' => null,
        ], $overrides);

        $stmt = self::$db->prepare("
            INSERT INTO horses (name, ueln, sire_id, sire_name, sire_ueln, dam_id, dam_name, dam_ueln, birth_year, deleted_at)
            VALUES (:name, :ueln, :sire_id, :sire_name, :sire_ueln, :dam_id, :dam_name, :dam_ueln, :birth_year, :deleted_at)
        ");
        $stmt->execute($data);
        return (int)self::$db->lastInsertId();
    }

    private function insertAdminUser(string $email): void {
        $stmt = self::$db->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, 'x', 'admin')");
        $stmt->execute(['digest-admin-' . uniqid(), $email]);
    }

    public function testRunWithNothingToReportRecordsOkStatusWithoutSending(): void {
        $this->insertAdminUser('admin@example.com');

        DigestService::run();

        $status = self::$db->query("SELECT setting_value FROM settings WHERE setting_key = 'digest_last_status'")->fetchColumn();
        $sentCount = self::$db->query("SELECT setting_value FROM settings WHERE setting_key = 'digest_last_sent_count'")->fetchColumn();
        $this->assertSame('ok', $status);
        $this->assertSame('0', $sentCount);
    }

    public function testRunWithOpenMatchSuggestionCountsThemAndThrowsWithoutMailConfig(): void {
        $this->insertAdminUser('admin@example.com');

        // Exakte UELN-Übereinstimmung statt Namens-Ähnlichkeit: garantiert
        // deterministisch hasUelnMatch=true in MatchSuggestionFinder
        // (45 von 45 möglichen Punkten allein dafür, unabhängig von
        // Fließkomma-Ähnlichkeitswerten anderer Kriterien).
        $this->insertHorse(['name' => 'Quantum', 'ueln' => 'DE001TESTM01', 'birth_year' => 2005]);
        $this->insertHorse(['name' => 'Quantom Junior', 'sire_ueln' => 'DE001TESTM01', 'birth_year' => 2015]);

        // Kein SMTP konfiguriert -> Mailer::send() liefert kontrolliert false
        // für jeden Empfänger -> DigestService muss das als vollständigen
        // Fehlschlag werten und werfen.
        $this->expectException(\RuntimeException::class);

        try {
            DigestService::run();
        } finally {
            $status = self::$db->query("SELECT setting_value FROM settings WHERE setting_key = 'digest_last_status'")->fetchColumn();
            $this->assertSame('error', $status);
        }
    }

    public function testCountsExpiringTrashItemsWithinWarningWindowOnly(): void {
        $this->insertAdminUser('admin@example.com');

        // Zu jung (5 Tage) - noch nicht im Warnfenster.
        $this->insertHorse(['name' => 'Zu jung gelöscht', 'deleted_at' => date('Y-m-d H:i:s', strtotime('-5 days'))]);
        // Im Warnfenster (25 Tage, zwischen 23 und 30 Tagen).
        $this->insertHorse(['name' => 'Im Warnfenster', 'deleted_at' => date('Y-m-d H:i:s', strtotime('-25 days'))]);
        // Bereits über 30 Tage - nicht mehr "bald ablaufend", sondern schon erreicht.
        $this->insertHorse(['name' => 'Schon abgelaufen', 'deleted_at' => date('Y-m-d H:i:s', strtotime('-35 days'))]);
        // Nicht gelöscht - zählt nicht.
        $this->insertHorse(['name' => 'Aktiv']);

        $reflection = new \ReflectionClass(DigestService::class);
        $method = $reflection->getMethod('countExpiringTrashItems');
        $method->setAccessible(true);

        $this->assertSame(1, $method->invoke(null));
    }

    public function testRegisterScheduledTaskIsNoOpWhenDisabled(): void {
        $stmt = self::$db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('digest_enabled', '0')");
        $stmt->execute();

        DigestService::registerScheduledTask();

        $this->assertSame([], Scheduler::registeredTasks());
    }

    public function testRegisterScheduledTaskRegistersWhenEnabled(): void {
        $stmt = self::$db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('digest_enabled', '1'), ('digest_interval_hours', '12')");
        $stmt->execute();

        DigestService::registerScheduledTask();

        $tasks = Scheduler::registeredTasks();
        $this->assertCount(1, $tasks);
        $this->assertSame('digest.admin_editor', $tasks[0]['name']);
        $this->assertSame(12 * 3600, $tasks[0]['intervalSeconds']);
    }
}
