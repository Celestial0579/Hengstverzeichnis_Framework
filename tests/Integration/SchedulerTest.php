<?php
// tests/Integration/SchedulerTest.php

namespace Tests\Integration;

use App\Database;
use App\Service\Scheduler;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Prüft App\Service\Scheduler (#67) gegen eine echte Test-Datenbank, da die
 * "fällig?"-Prüfung auf einem in der `settings`-Tabelle persistierten
 * Zuletzt-ausgeführt-Zeitstempel beruht (analog zu PedigreeBuilderTest.php).
 */
class SchedulerTest extends TestCase {

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
        self::$db->exec("DELETE FROM settings WHERE setting_key LIKE 'cron_last_run__%'");
    }

    public function testUnregisteredScheduleRunsNothing(): void {
        $this->assertSame([], Scheduler::runDue());
        $this->assertSame([], Scheduler::registeredTasks());
    }

    public function testNeverRunTaskIsDueImmediately(): void {
        $ran = false;
        Scheduler::register('test.immediate', 3600, function () use (&$ran) {
            $ran = true;
        });

        $results = Scheduler::runDue();

        $this->assertTrue($ran);
        $this->assertSame([['name' => 'test.immediate', 'status' => 'ok']], $results);
        $this->assertNotNull(Scheduler::lastRunAt('test.immediate'));
    }

    public function testTaskIsNotRunAgainBeforeItsIntervalElapses(): void {
        $runCount = 0;
        Scheduler::register('test.interval', 3600, function () use (&$runCount) {
            $runCount++;
        });

        Scheduler::runDue();
        $results = Scheduler::runDue();

        $this->assertSame(1, $runCount);
        $this->assertSame([], $results);
    }

    public function testFailingTaskIsIsolatedAndReportedAsError(): void {
        $otherRan = false;
        Scheduler::register('test.failing', 3600, function () {
            throw new \RuntimeException('absichtlicher Testfehler');
        });
        Scheduler::register('test.after_failure', 3600, function () use (&$otherRan) {
            $otherRan = true;
        });

        $results = Scheduler::runDue();

        $this->assertTrue($otherRan, 'Eine fehlschlagende Aufgabe darf nachfolgende Aufgaben desselben Laufs nicht blockieren');
        $this->assertSame('error', $results[0]['status']);
        $this->assertSame('test.failing', $results[0]['name']);
        $this->assertSame('absichtlicher Testfehler', $results[0]['error']);
        $this->assertSame('ok', $results[1]['status']);
    }

    public function testFailingTaskWithoutRetryOnFailureIsNotRetriedBeforeIntervalElapses(): void {
        Scheduler::register('test.no_retry', 3600, function () {
            throw new \RuntimeException('fail');
        }, retryOnFailure: false);

        Scheduler::runDue();
        $results = Scheduler::runDue();

        $this->assertSame([], $results, 'Ohne retryOnFailure zählt eine fehlgeschlagene Ausführung als "ausgeführt" für dieses Intervall');
    }

    public function testFailingTaskWithRetryOnFailureIsRetriedImmediately(): void {
        $attempts = 0;
        Scheduler::register('test.retry', 3600, function () use (&$attempts) {
            $attempts++;
            throw new \RuntimeException('fail');
        }, retryOnFailure: true);

        Scheduler::runDue();
        $results = Scheduler::runDue();

        $this->assertSame(2, $attempts);
        $this->assertSame('error', $results[0]['status']);
    }

    public function testRegisteredTasksReflectsLastRunTimestamp(): void {
        Scheduler::register('test.visible', 60, function () {});

        $beforeRun = Scheduler::registeredTasks();
        $this->assertNull($beforeRun[0]['lastRunAt']);

        Scheduler::runDue();

        $afterRun = Scheduler::registeredTasks();
        $this->assertSame('test.visible', $afterRun[0]['name']);
        $this->assertSame(60, $afterRun[0]['intervalSeconds']);
        $this->assertIsInt($afterRun[0]['lastRunAt']);
    }
}
