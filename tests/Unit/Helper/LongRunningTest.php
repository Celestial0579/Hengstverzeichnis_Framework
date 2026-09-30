<?php
// tests/Unit/Helper/LongRunningTest.php

namespace Tests\Unit\Helper;

use App\Helper\LongRunning;
use PHPUnit\Framework\TestCase;

/**
 * Rauchtest für LongRunning::allow() (Audit M39). In der CLI ist
 * max_execution_time ohnehin 0 - geprüft wird vor allem, dass der Aufruf
 * keine Warning erzeugt und Verbindungsabbrüche ignoriert.
 */
class LongRunningTest extends TestCase {

    private int $ignoreUserAbort;
    private string $maxExecutionTime;

    protected function setUp(): void {
        $this->ignoreUserAbort = ignore_user_abort();
        $this->maxExecutionTime = (string)ini_get('max_execution_time');
    }

    protected function tearDown(): void {
        ignore_user_abort((bool)$this->ignoreUserAbort);
        @set_time_limit((int)$this->maxExecutionTime);
    }

    public function testAllowIgnoresUserAbortAndLiftsTimeLimit(): void {
        ignore_user_abort(false);
        LongRunning::allow();

        $this->assertSame(1, ignore_user_abort());
        $this->assertSame('0', (string)ini_get('max_execution_time'));
    }
}
