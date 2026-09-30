<?php
// tests/Unit/Service/SchedulerIntervalTest.php

namespace Tests\Unit\Service;

use App\Service\Scheduler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Intervallgrenzen für Digest und Backup (Audit N67). Ein Riesenwert ließ
 * `$stunden * 3600` überlaufen; Scheduler::register() bekam einen float und
 * warf bei JEDEM Request - die ganze Installation stand.
 */
class SchedulerIntervalTest extends TestCase {

    /**
     * @return array<string, array{0:mixed, 1:int}>
     */
    public static function values(): array {
        return [
            'normal' => ['24', 24],
            'null Stunden' => ['0', 1],
            'negativ' => ['-5', 1],
            'kein Zahlwert' => ['abc', 1],
            'Zahl mit Rest' => ['12abc', 1],
            'null' => [null, 1],
            'leer' => ['', 1],
            'riesig (passt in int)' => ['9999999999999999', 8760],
            'riesig (über int)' => ['99999999999999999999', 8760],
            'Exponent' => ['1e30', 8760],
            'unendlich' => ['1e400', 8760],
            'knapp drüber' => [8761, 8760],
            'Grenze' => ['8760', 8760],
            'Leerraum' => [' 12 ', 12],
            'Nachkommastelle' => ['1.9', 1],
            'int' => [6, 6],
        ];
    }

    #[DataProvider('values')]
    public function testClampIntervalHours(mixed $raw, int $expected): void {
        $this->assertSame($expected, Scheduler::clampIntervalHours($raw));
    }

    public function testMaximumTimesAnHourFitsIntoAnInt(): void {
        $this->assertSame(31536000, Scheduler::MAX_INTERVAL_HOURS * 3600);
    }
}
