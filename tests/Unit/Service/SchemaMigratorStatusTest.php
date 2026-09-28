<?php
// tests/Unit/Service/SchemaMigratorStatusTest.php

namespace Tests\Unit\Service;

use App\Service\SchemaMigrator;
use PHPUnit\Framework\TestCase;

/**
 * Der Laufzustand settings.schema_migration_status (Audit N76), wie ihn das
 * Admin-Dashboard aus den geladenen Einstellungen liest - ohne Datenbank.
 * Kaputtes oder unvollständiges JSON darf das Dashboard nicht stören.
 */
class SchemaMigratorStatusTest extends TestCase {

    private static function einstellungen(mixed $wert): array {
        return [SchemaMigrator::STATUS_SCHLUESSEL => is_string($wert) ? $wert : json_encode($wert)];
    }

    public function testGueltigerStatusWirdGelesen(): void {
        $status = SchemaMigrator::statusAusEinstellungen(self::einstellungen([
            'zustand' => 'offen',
            'von' => 21,
            'ziel' => 23,
            'zeit' => '2026-09-28T10:00:00+00:00',
            'meldungen' => ['366_pferdefotos_aus_dem_webroot: Pferdefotos (#366) …', 42],
        ]));

        $this->assertNotNull($status);
        $this->assertSame('offen', $status['zustand']);
        $this->assertSame(21, $status['von']);
        $this->assertSame(23, $status['ziel']);
        $this->assertSame(['366_pferdefotos_aus_dem_webroot: Pferdefotos (#366) …', '42'], $status['meldungen']);
        $this->assertSame(
            strtotime('2026-09-28T10:00:00+00:00') + SchemaMigrator::WIEDERHOLEN_NACH['offen'],
            SchemaMigrator::naechsterVersuch($status)
        );
    }

    public function testKaputtesJsonIstKeinStatus(): void {
        $this->assertNull(SchemaMigrator::statusAusEinstellungen(self::einstellungen('{kein json')));
        $this->assertNull(SchemaMigrator::statusAusEinstellungen(self::einstellungen('')));
    }

    public function testFehlenderSchluesselIstKeinStatus(): void {
        $this->assertNull(SchemaMigrator::statusAusEinstellungen([]));
        $this->assertNull(SchemaMigrator::statusAusEinstellungen(self::einstellungen([
            'zustand' => 'offen', 'von' => 21, 'zeit' => '2026-09-28T10:00:00+00:00',
        ])));
        $this->assertNull(SchemaMigrator::statusAusEinstellungen(self::einstellungen([
            'zustand' => 'unbekannt', 'von' => 21, 'ziel' => 23, 'zeit' => '2026-09-28T10:00:00+00:00',
        ])));
    }
}
