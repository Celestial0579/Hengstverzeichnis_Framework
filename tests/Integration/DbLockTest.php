<?php
// tests/Integration/DbLockTest.php

namespace Tests\Integration;

use App\Database;
use App\Service\DbLock;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Benannte Sperren über GET_LOCK (Audit M38) gegen eine echte Datenbank:
 * Die Sperre gehört der Verbindung, eine zweite Verbindung bekommt sie
 * nicht.
 */
class DbLockTest extends TestCase {

    private static ?PDO $other = null;

    public static function setUpBeforeClass(): void {
        if (!defined('DB_HOST')) {
            self::markTestSkipped('Keine Test-Datenbank konfiguriert (DB_HOST fehlt) - siehe tests/bootstrap.php.');
        }
        Database::getInstance();
        self::$other = new PDO(
            "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    public static function tearDownAfterClass(): void {
        self::$other = null;
    }

    protected function tearDown(): void {
        DbLock::simulateUnavailableForTests(false);
        DbLock::release('test.dblock');
        self::$other?->query("SELECT RELEASE_ALL_LOCKS()");
    }

    public function testAcquireIsHeldRelease(): void {
        $this->assertFalse(DbLock::isHeld('test.dblock'));

        $this->assertTrue(DbLock::acquire('test.dblock'));
        $this->assertTrue(DbLock::isHeld('test.dblock'));

        DbLock::release('test.dblock');
        $this->assertFalse(DbLock::isHeld('test.dblock'));
    }

    public function testSecondConnectionDoesNotGetAHeldLock(): void {
        $this->assertTrue(DbLock::acquire('test.dblock'));

        $stmt = self::$other->prepare("SELECT GET_LOCK(?, 0)");
        $stmt->execute([DbLock::lockName('test.dblock')]);
        $this->assertSame(0, (int)$stmt->fetchColumn());

        DbLock::release('test.dblock');
        $stmt->execute([DbLock::lockName('test.dblock')]);
        $this->assertSame(1, (int)$stmt->fetchColumn());

        // Umgekehrt: Hält die andere Verbindung, meldet acquire() "belegt".
        $this->assertFalse(DbLock::acquire('test.dblock'));
    }

    public function testLockNameIsShortDeterministicAndBoundToTheDatabase(): void {
        $name = DbLock::lockName(str_repeat('sehr-lang', 30));

        $this->assertLessThanOrEqual(64, strlen($name));
        $this->assertSame($name, DbLock::lockName(str_repeat('sehr-lang', 30)));
        $this->assertNotSame(DbLock::lockName('a'), DbLock::lockName('b'));
        $this->assertSame('hv_' . substr(hash('sha256', DB_NAME . "\0" . 'a'), 0, 40), DbLock::lockName('a'));
        $this->assertNotSame(
            'hv_' . substr(hash('sha256', 'andere_datenbank' . "\0" . 'a'), 0, 40),
            DbLock::lockName('a'),
            'Zwei Installationen auf einem Server dürfen sich nicht sperren'
        );
    }

    public function testUnavailableIsReportedAsNull(): void {
        DbLock::simulateUnavailableForTests(true);

        $this->assertNull(DbLock::acquire('test.dblock'));
        $this->assertNull(DbLock::isHeld('test.dblock'));
    }
}
