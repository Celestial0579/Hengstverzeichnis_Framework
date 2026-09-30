<?php
// tests/Integration/SchedulerTest.php

namespace Tests\Integration;

use App\Database;
use App\Service\DbLock;
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

    /** Zweite, unabhängige Verbindung - spielt einen parallelen Cron-Aufruf. */
    private static ?PDO $other = null;

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
        DbLock::simulateUnavailableForTests(false);
        self::$db->exec("DELETE FROM settings WHERE setting_key LIKE 'cron_last_run__%' OR setting_key LIKE 'cron_status__%'");
    }

    protected function tearDown(): void {
        DbLock::simulateUnavailableForTests(false);
        if (self::$other !== null) {
            self::$other->query("SELECT RELEASE_ALL_LOCKS()");
        }
    }

    private static function other(): PDO {
        if (self::$other === null) {
            self::$other = new PDO(
                "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        }
        return self::$other;
    }

    private static function holdLockElsewhere(string $taskName): void {
        $stmt = self::other()->prepare("SELECT GET_LOCK(?, 0)");
        $stmt->execute([DbLock::lockName('cron:' . $taskName)]);
        self::assertSame(1, (int)$stmt->fetchColumn(), 'Zweite Verbindung bekam die Sperre nicht');
    }

    private static function releaseLockElsewhere(string $taskName): void {
        self::other()->prepare("SELECT RELEASE_LOCK(?)")->execute([DbLock::lockName('cron:' . $taskName)]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function storedStatus(string $taskName): ?array {
        $stmt = self::$db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([\App\Helper\BoundedKey::fit('cron_status__', $taskName)]);
        $raw = $stmt->fetchColumn();
        return is_string($raw) ? json_decode($raw, true) : null;
    }

    private static function storeSetting(string $key, string $value): void {
        self::$db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
            ->execute([$key, $value]);
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

    /**
     * Audit M38: Hält ein anderer Cron-Aufruf die Sperre der Aufgabe, läuft
     * sie nicht ein zweites Mal parallel, sondern wird übersprungen.
     */
    public function testTaskIsSkippedWhileAnotherConnectionHoldsItsLock(): void {
        $runs = 0;
        Scheduler::register('test.locked', 3600, function () use (&$runs) {
            $runs++;
        });

        self::holdLockElsewhere('test.locked');
        $results = Scheduler::runDue();

        $this->assertSame(0, $runs);
        $this->assertSame('skipped', $results[0]['status']);
        $this->assertSame('test.locked', $results[0]['name']);
        $this->assertNull(Scheduler::lastRunAt('test.locked'));

        self::releaseLockElsewhere('test.locked');
        $this->assertSame([['name' => 'test.locked', 'status' => 'ok']], Scheduler::runDue());
        $this->assertSame(1, $runs);
    }

    /**
     * Audit M38/M39: Der Zeitstempel steht schon, während der Callback läuft
     * - ein paralleler Aufruf (oder der nächste nach einem Fatal) sieht die
     * Aufgabe nicht mehr als fällig.
     */
    public function testTimestampIsClaimedBeforeCallbackRuns(): void {
        $seenByOther = false;
        Scheduler::register('test.claim', 3600, function () use (&$seenByOther) {
            $stmt = self::other()->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
            $stmt->execute(['cron_last_run__test.claim']);
            $seenByOther = $stmt->fetchColumn();
        });

        Scheduler::runDue();

        $this->assertIsString($seenByOther, 'Während des Callbacks muss der Claim für andere Verbindungen sichtbar sein');
        $this->assertEqualsWithDelta(time(), (int)$seenByOther, 5);
    }

    public function testRetryOnFailureRestoresPreviousTimestamp(): void {
        $previous = time() - 7200;
        self::storeSetting('cron_last_run__test.restore', (string)$previous);
        Scheduler::register('test.restore', 3600, function () {
            throw new \RuntimeException('fail');
        }, retryOnFailure: true);

        Scheduler::runDue();

        $this->assertSame($previous, Scheduler::lastRunAt('test.restore'));
    }

    public function testRetryOnFailureWithoutPreviousRunRemovesTheClaim(): void {
        Scheduler::register('test.restore_none', 3600, function () {
            throw new \RuntimeException('fail');
        }, retryOnFailure: true);

        Scheduler::runDue();

        $this->assertNull(Scheduler::lastRunAt('test.restore_none'));
    }

    /**
     * Ist GET_LOCK auf der Plattform nicht nutzbar, darf das nicht dazu
     * führen, dass gar keine Aufgabe mehr läuft - dann trägt der Claim.
     */
    public function testTaskRunsWithClaimOnlyWhenLockUnavailable(): void {
        DbLock::simulateUnavailableForTests(true);
        $runs = 0;
        Scheduler::register('test.nolock', 3600, function () use (&$runs) {
            $runs++;
        });

        $first = Scheduler::runDue();
        $second = Scheduler::runDue();

        $this->assertSame(1, $runs);
        $this->assertSame([['name' => 'test.nolock', 'status' => 'ok']], $first);
        $this->assertSame([], $second);
    }

    public function testStatusIsRecordedForOkAndError(): void {
        Scheduler::register('test.status_ok', 3600, function () {});
        Scheduler::register('test.status_error', 3600, function () {
            throw new \RuntimeException(str_repeat('ü', 600));
        });

        Scheduler::runDue();

        $ok = self::storedStatus('test.status_ok');
        $this->assertSame('ok', $ok['status']);
        $this->assertIsInt($ok['startedAt']);
        $this->assertIsInt($ok['finishedAt']);
        $this->assertNull($ok['error']);

        $error = self::storedStatus('test.status_error');
        $this->assertSame('error', $error['status']);
        $this->assertSame(500, mb_strlen($error['error']), 'Fehlertext wird auf 500 Zeichen gekürzt');

        $tasks = array_column(Scheduler::registeredTasks(), null, 'name');
        $this->assertSame('ok', $tasks['test.status_ok']['state']);
        $this->assertSame('error', $tasks['test.status_error']['state']);
        $this->assertFalse($tasks['test.status_ok']['running']);
    }

    public function testForgetRemovesAllSchedulerKeys(): void {
        $longName = 'test.' . str_repeat('vergessen', 6);
        Scheduler::register('test.forget', 3600, function () {});
        Scheduler::register($longName, 3600, function () {});
        Scheduler::runDue();
        $this->assertNotNull(Scheduler::lastRunAt('test.forget'));
        $this->assertNotNull(self::storedStatus($longName));

        Scheduler::forget('test.forget');
        Scheduler::forget($longName);

        $this->assertNull(Scheduler::lastRunAt('test.forget'));
        $this->assertNull(self::storedStatus('test.forget'));
        $this->assertNull(Scheduler::lastRunAt($longName));
        $this->assertNull(self::storedStatus($longName));
    }

    public function testRegisteredTasksReportsRunningWhileLockHeld(): void {
        Scheduler::register('test.running', 3600, function () {});
        self::storeSetting('cron_status__test.running', json_encode(['status' => 'running', 'startedAt' => time() - 600, 'finishedAt' => null, 'error' => null]));
        self::holdLockElsewhere('test.running');

        $task = Scheduler::registeredTasks()[0];

        $this->assertTrue($task['running']);
        $this->assertSame('running', $task['state']);
    }

    /**
     * Audit M39: SIGKILL oder request_terminate_timeout hinterlassen den
     * Status "running", aber keine Sperre. Das wird als Abbruch angezeigt -
     * nicht aber in den ersten Sekunden eines Laufs.
     */
    public function testRunningStatusWithFreeLockAndOldStartIsReportedAsAborted(): void {
        Scheduler::register('test.killed', 3600, function () {});
        Scheduler::register('test.starting', 3600, function () {});
        self::storeSetting('cron_status__test.killed', json_encode(['status' => 'running', 'startedAt' => time() - 600, 'finishedAt' => null, 'error' => null]));
        self::storeSetting('cron_status__test.starting', json_encode(['status' => 'running', 'startedAt' => time() - 5, 'finishedAt' => null, 'error' => null]));

        $tasks = array_column(Scheduler::registeredTasks(), null, 'name');

        $this->assertFalse($tasks['test.killed']['running']);
        $this->assertSame('running', $tasks['test.killed']['status']);
        $this->assertSame('aborted', $tasks['test.killed']['state']);
        $this->assertSame('running', $tasks['test.starting']['state']);

        DbLock::simulateUnavailableForTests(true);
        $unknown = array_column(Scheduler::registeredTasks(), null, 'name');
        $this->assertNull($unknown['test.killed']['running']);
        $this->assertSame('unknown', $unknown['test.killed']['state']);
    }

    /**
     * Audit N74: Ein Name über 35 Zeichen sprengte `setting_key`. Im
     * Strict-Mode warf das im catch-Zweig und riss die folgenden Aufgaben
     * mit; jetzt wird der Schlüssel gehasht.
     */
    public function testOverlongTaskNameIsRecordedAndDoesNotBlockFollowingTasks(): void {
        $longName = 'ein-addon-mit-langem-namen.taegliche-aufgabe';
        $this->assertGreaterThan(35, strlen($longName));
        $longRuns = 0;
        $afterRuns = 0;
        Scheduler::register($longName, 3600, function () use (&$longRuns) {
            $longRuns++;
        });
        Scheduler::register('test.danach', 3600, function () use (&$afterRuns) {
            $afterRuns++;
        });

        $first = Scheduler::runDue();
        Scheduler::runDue();

        $this->assertSame(1, $longRuns);
        $this->assertSame(1, $afterRuns);
        $this->assertSame('ok', $first[0]['status']);
        $this->assertNotNull(Scheduler::lastRunAt($longName));

        $keys = self::$db->query("SELECT setting_key FROM settings WHERE setting_key LIKE 'cron_%'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($keys as $key) {
            $this->assertLessThanOrEqual(50, mb_strlen($key));
        }
    }

    public function testRunExclusiveSharesTheTaskLock(): void {
        $ran = false;
        self::holdLockElsewhere('test.exclusive');
        $this->assertFalse(Scheduler::runExclusive('test.exclusive', function () use (&$ran) {
            $ran = true;
        }));
        $this->assertFalse($ran);

        self::releaseLockElsewhere('test.exclusive');
        $heldInside = null;
        $this->assertTrue(Scheduler::runExclusive('test.exclusive', function () use (&$ran, &$heldInside) {
            $ran = true;
            $heldInside = DbLock::isHeld('cron:test.exclusive');
        }));
        $this->assertTrue($ran);
        $this->assertTrue($heldInside);
        $this->assertFalse(DbLock::isHeld('cron:test.exclusive'), 'Die Sperre wird danach freigegeben');
        $this->assertNull(Scheduler::lastRunAt('test.exclusive'), 'runExclusive setzt keinen Zeitstempel');
    }
}
