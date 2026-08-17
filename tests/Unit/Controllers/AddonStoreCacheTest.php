<?php
// tests/Unit/Controllers/AddonStoreCacheTest.php

namespace Tests\Unit\Controllers;

use App\Controllers\AddonStoreController;
use PHPUnit\Framework\TestCase;

/**
 * Netzwerk- und datenbankfreie Tests der TTL-Grenze des Katalog-Caches
 * (AddonStoreController::isCacheFresh(), #290). Sie entscheidet, ob die
 * Update-Seite und der Cron-Lauf GitHub überhaupt fragen - eine zu weiche
 * Grenze zeigt veraltete Addon-Versionen an, eine zu harte belastet jeden
 * Seitenaufruf mit einem Netzwerkzugriff.
 */
class AddonStoreCacheTest extends TestCase {

    public function testFreshAgeCountsAsFresh(): void {
        $this->assertTrue(AddonStoreController::isCacheFresh(0, false));
        $this->assertTrue(AddonStoreController::isCacheFresh(60, false));
    }

    public function testAgeBeyondTtlIsStale(): void {
        $this->assertFalse(AddonStoreController::isCacheFresh(1200, false));
    }

    /** Erstbefüllung: ohne Zeitstempel gibt es nichts, was frisch sein könnte. */
    public function testMissingAgeIsStale(): void {
        $this->assertFalse(AddonStoreController::isCacheFresh(null, false));
    }

    /** „Katalog neu laden" im Store muss die TTL überstimmen können. */
    public function testForceRefreshOverridesFreshAge(): void {
        $this->assertFalse(AddonStoreController::isCacheFresh(0, true));
    }

    /**
     * Die Grenze liegt bei 900 Sekunden: knapp darunter frisch, knapp
     * darüber abgelaufen. Ohne diesen Fall bliebe unbemerkt, wenn jemand die
     * Konstante versehentlich in eine andere Einheit umrechnet.
     */
    public function testBoundaryAroundFifteenMinutes(): void {
        $this->assertTrue(AddonStoreController::isCacheFresh(899, false));
        $this->assertFalse(AddonStoreController::isCacheFresh(900, false));
        $this->assertFalse(AddonStoreController::isCacheFresh(901, false));
    }

    /**
     * Regression (#290): Das Alter kommt aus TIMESTAMPDIFF und ist damit
     * eine Zahl auf der Uhr des Datenbankservers - nicht mehr ein von PHP
     * interpretierter Zeitstempel. Zuvor las strtotime() den von MySQL NOW()
     * geschriebenen Wert in der PHP-Zeitzone; lief die Datenbank auf UTC und
     * PHP auf Europe/Berlin, ergab das dauerhaft zwei Stunden Alter und der
     * Cache galt IMMER als abgelaufen - jeder Store-Aufruf lud den kompletten
     * Tarball neu. Ein Alter von 7200 wäre unter der alten Rechnung der
     * Normalfall gewesen und muss klar abgelaufen sein, ein frisch
     * geschriebener Eintrag (0) klar frisch.
     */
    public function testAgeIsIndependentOfPhpTimezone(): void {
        $previous = date_default_timezone_get();
        try {
            foreach (['UTC', 'Europe/Berlin', 'Pacific/Auckland'] as $timezone) {
                date_default_timezone_set($timezone);
                $this->assertTrue(
                    AddonStoreController::isCacheFresh(0, false),
                    "Frischer Cache muss in Zeitzone {$timezone} frisch bleiben"
                );
                $this->assertFalse(
                    AddonStoreController::isCacheFresh(7200, false),
                    "Zwei Stunden alter Cache muss in Zeitzone {$timezone} abgelaufen sein"
                );
            }
        } finally {
            date_default_timezone_set($previous);
        }
    }
}
