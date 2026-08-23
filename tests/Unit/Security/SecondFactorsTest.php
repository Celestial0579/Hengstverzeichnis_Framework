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

    /**
     * SEIT #353 GEHOERT passkey_count IN JEDE ZEILE, die hier hineingeht.
     *
     * Nicht aus Umstaendlichkeit: Passkeys stehen als einziges Verfahren
     * nicht in der users-Zeile. Wer eine Zeile baut und das Feld weglaesst,
     * hat die Frage "hat dieses Konto einen Passkey" nicht beantwortet -
     * und die erste Fassung antwortete dann stillschweigend mit "nein".
     * Genau so meldete sich ein Passkey-only-Konto mit dem Passwort allein
     * an. fromRow() wirft deshalb, wenn weder passkey_count noch id dasteht.
     *
     * Die Tests hier setzen das Feld ausdruecklich auf 0 - das ist die
     * Aussage "diese Zeile behauptet nichts ueber Passkeys", und sie steht
     * jetzt da, statt vorausgesetzt zu werden.
     */
    public function testKeineSpaltenGesetztHeisstKeinFaktor(): void {
        $this->assertSame([], SecondFactors::fromRow([
            'totp_enabled' => 0, 'email_2fa_enabled' => 0, 'passkey_count' => 0,
        ]));
    }

    public function testTotpUndMailcodeWerdenBeideErkannt(): void {
        $ohnePasskey = ['passkey_count' => 0];
        $this->assertSame([SecondFactors::TOTP], SecondFactors::fromRow($ohnePasskey + ['totp_enabled' => 1, 'email_2fa_enabled' => 0]));
        $this->assertSame([SecondFactors::EMAIL], SecondFactors::fromRow($ohnePasskey + ['totp_enabled' => 0, 'email_2fa_enabled' => 1]));
        $this->assertSame(
            [SecondFactors::TOTP, SecondFactors::EMAIL],
            SecondFactors::fromRow($ohnePasskey + ['totp_enabled' => 1, 'email_2fa_enabled' => 1]),
            'Die Reihenfolge ist die Reihenfolge der Staerke - die Anmeldung waehlt daraus den ersten.'
        );
    }

    /** Und der Passkey steht vorn, weil er als einziger gegen Phishing traegt. */
    public function testPasskeyStehtVorDenAnderen(): void {
        $this->assertSame(
            [SecondFactors::PASSKEY, SecondFactors::TOTP, SecondFactors::EMAIL],
            SecondFactors::fromRow(['passkey_count' => 2, 'totp_enabled' => 1, 'email_2fa_enabled' => 1])
        );
    }

    /**
     * Fehlende TOTP-/Mailcode-Spalten duerfen nicht in eine Warnung laufen:
     * Nicht jede Abfrage im Kern holt beide, und ein "undefined array key"
     * waere unter failOnWarning ein roter Lauf statt einer Antwort.
     *
     * Fuer passkey_count gilt das AUSDRUECKLICH NICHT - siehe unten.
     */
    public function testFehlendeSpaltenGeltenAlsNichtGesetzt(): void {
        $this->assertSame([], SecondFactors::fromRow(['passkey_count' => 0]));
        $this->assertSame(
            [SecondFactors::TOTP],
            SecondFactors::fromRow(['passkey_count' => 0, 'totp_enabled' => 1])
        );
    }

    /**
     * Eine Zeile ohne passkey_count UND ohne id wird abgewiesen.
     *
     * Der Unterschied zu den anderen Spalten ist Absicht: Ein fehlendes
     * totp_enabled bedeutet nachweislich "nicht eingeschaltet", denn der
     * Schalter steht in derselben Zeile. Ein fehlendes passkey_count bedeutet
     * gar nichts - die Auskunft liegt in einer anderen Tabelle. Sie
     * stillschweigend als "kein Passkey" zu lesen, hat eine Anmeldung
     * durchgelassen.
     */
    public function testZeileOhnePasskeyAuskunftWirdAbgewiesen(): void {
        $this->expectException(\RuntimeException::class);
        SecondFactors::fromRow(['totp_enabled' => 1]);
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
