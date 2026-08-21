<?php
// tests/Unit/Security/SecondFactorsTest.php

namespace Tests\Unit\Security;

use App\Security\SecondFactors;
use PHPUnit\Framework\TestCase;

/**
 * Die eine Stelle, die weiss, welche zweiten Faktoren es gibt (#354).
 *
 * Geprueft wird hier ohne Datenbank: fromRow() ist die Auswertung einer
 * bereits geholten Zeile, sqlHasAnyFactor() nur der erzeugte Ausdruck. Die
 * Wirkung im echten SQL prueft DormantAccountServiceTest.
 */
class SecondFactorsTest extends TestCase {

    public function testKeineSpaltenGesetztHeisstKeinFaktor(): void {
        $this->assertSame([], SecondFactors::fromRow(['totp_enabled' => 0, 'email_2fa_enabled' => 0]));
    }

    public function testTotpUndMailcodeWerdenBeideErkannt(): void {
        $this->assertSame([SecondFactors::TOTP], SecondFactors::fromRow(['totp_enabled' => 1, 'email_2fa_enabled' => 0]));
        $this->assertSame([SecondFactors::EMAIL], SecondFactors::fromRow(['totp_enabled' => 0, 'email_2fa_enabled' => 1]));
        $this->assertSame(
            [SecondFactors::TOTP, SecondFactors::EMAIL],
            SecondFactors::fromRow(['totp_enabled' => 1, 'email_2fa_enabled' => 1]),
            'Die Reihenfolge ist die Reihenfolge der Staerke - die Anmeldung waehlt daraus den ersten.'
        );
    }

    /**
     * Fehlende Spalten duerfen nicht in eine Warnung laufen: Nicht jede
     * Abfrage im Kern holt beide, und ein "undefined array key" waere unter
     * failOnWarning ein roter Lauf statt einer Antwort.
     */
    public function testFehlendeSpaltenGeltenAlsNichtGesetzt(): void {
        $this->assertSame([], SecondFactors::fromRow([]));
        $this->assertSame([SecondFactors::TOTP], SecondFactors::fromRow(['totp_enabled' => 1]));
    }

    public function testSqlAusdruckNenntBeideVerfahrenUndDenAlias(): void {
        $sql = SecondFactors::sqlHasAnyFactor('x');
        $this->assertStringContainsString('x.totp_enabled', $sql);
        $this->assertStringContainsString('x.email_2fa_enabled', $sql);
    }

    /**
     * ALL und COLUMNS sind die Liste, an der sich jedes neue Verfahren
     * anmelden muss. Laufen sie auseinander, findet fromRow() einen Faktor
     * nicht, den forUser() zu selektieren glaubt.
     */
    public function testListeUndSpaltenPassenZusammen(): void {
        $this->assertCount(count(SecondFactors::ALL), SecondFactors::COLUMNS);
    }
}
