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

    public function testSqlAusdruckNenntAlleVerfahrenUndDenAlias(): void {
        $sql = SecondFactors::sqlHasAnyFactor('x');
        $this->assertStringContainsString('x.totp_enabled', $sql);
        $this->assertStringContainsString('x.email_2fa_enabled', $sql);
        // Seit #353 auch die Passkeys - sonst zaehlte die Fristenlogik aus
        // #358 ein geschuetztes Konto als ungeschuetzt und legte es still.
        $this->assertStringContainsString('user_passkeys', $sql);
        $this->assertStringContainsString('pk.user_id = x.id', $sql);
    }

    /**
     * ALL und COLUMNS sind die Liste, an der sich jedes neue Verfahren
     * anmelden muss. Laufen sie auseinander, findet fromRow() einen Faktor
     * nicht, den forUser() zu selektieren glaubt.
     *
     * SEIT #353 GIBT ES GENAU EINE AUSNAHME, und sie ist begründet: Passkeys
     * haben keine Spalte in `users`, sondern eine eigene Tabelle - ein Konto
     * kann mehrere haben und jeden einzeln entziehen. Ein Schalter in `users`
     * wäre eine zweite Wahrheit neben der Tabelle und könnte auseinanderlaufen;
     * die Zahl der Schlüssel IST hier der Schalter.
     *
     * Die Ausnahme steht deshalb NAMENTLICH da und nicht als aufgeweichte
     * Zählung. Wer ein weiteres spaltenloses Verfahren einführt, muss sie
     * ergänzen - und dabei begründen, warum sein Verfahren auch eines ist.
     */
    public function testListeUndSpaltenPassenZusammen(): void {
        $ohneSpalte = [SecondFactors::PASSKEY];

        $mitSpalte = array_values(array_diff(SecondFactors::ALL, $ohneSpalte));

        $this->assertCount(
            count($mitSpalte),
            SecondFactors::COLUMNS,
            'Jedes Verfahren mit Material in users braucht seine Spalte in COLUMNS - '
            . 'sonst selektiert forUser() etwas, das fromRow() nicht auswertet.'
        );
    }

    /** Und die Ausnahme selbst: Der Passkey MUSS in ALL stehen, aber nicht in COLUMNS. */
    public function testPasskeyStehtInAllAberHatKeineSpalte(): void {
        $this->assertContains(SecondFactors::PASSKEY, SecondFactors::ALL);
        $this->assertNotContains('passkeys', SecondFactors::COLUMNS);
        $this->assertSame(
            SecondFactors::PASSKEY,
            SecondFactors::ALL[0],
            'Reihenfolge = Staerke: Der Passkey ist der einzige Faktor, der gegen Phishing traegt.'
        );
    }
}
