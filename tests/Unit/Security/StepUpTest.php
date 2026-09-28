<?php
// tests/Unit/Security/StepUpTest.php

namespace Tests\Unit\Security;

use App\Security\SecondFactors;
use App\Security\StepUp;
use PHPUnit\Framework\TestCase;

/**
 * Die Step-up-Freigabe (#112, Audit M15, M17, N10) ohne Datenbank.
 *
 * Geprüft wird, was allein an der Sitzung hängt: für welches Konto die
 * Freigabe gilt, wie lange, wohin sie zurückführt und welcher Code zählt.
 * Die Codeprüfung selbst (TOTP-Zeitschlitz, Mailcode) prüft
 * tests/Integration/StepUpTest.php gegen MariaDB.
 */
class StepUpTest extends TestCase {

    protected function setUp(): void {
        $_SESSION = [];
    }

    protected function tearDown(): void {
        $_SESSION = [];
    }

    public function testOhneMarkierungIstNichtsFrisch(): void {
        $this->assertFalse(StepUp::frisch(7));
        $this->assertNull(StepUp::gueltigBis(7));
    }

    public function testMarkierenGiltFuerGenauDiesesKonto(): void {
        StepUp::markieren(7);

        $this->assertTrue(StepUp::frisch(7));
        $this->assertFalse(
            StepUp::frisch(8),
            'Der Nachweis des einen Kontos darf die Änderung eines anderen nicht bezahlen.'
        );
    }

    public function testDieFreigabeLaeuftNachZehnMinutenAb(): void {
        $_SESSION['twofa_reauth'] = ['user_id' => 7, 'at' => time() - StepUp::TTL - 1];
        $this->assertFalse(StepUp::frisch(7));

        $_SESSION['twofa_reauth'] = ['user_id' => 7, 'at' => time() - StepUp::TTL + 5];
        $this->assertTrue(StepUp::frisch(7));
    }

    public function testGueltigBisNenntDasEndeDesFensters(): void {
        $jetzt = time();
        StepUp::markieren(7);

        $bis = StepUp::gueltigBis(7);
        $this->assertNotNull($bis);
        $this->assertGreaterThanOrEqual($jetzt + StepUp::TTL, $bis);
        $this->assertLessThanOrEqual(time() + StepUp::TTL, $bis);
        $this->assertNull(StepUp::gueltigBis(8));
    }

    /** Kaputte Sitzungswerte sind keine Freigabe - auch keine für Konto 0. */
    public function testUnvollstaendigeMarkeZaehltNicht(): void {
        $_SESSION['twofa_reauth'] = time();
        $this->assertFalse(StepUp::frisch(7));

        $_SESSION['twofa_reauth'] = ['user_id' => 7];
        $this->assertFalse(StepUp::frisch(7));

        $_SESSION['twofa_reauth'] = ['user_id' => 0, 'at' => time()];
        $this->assertFalse(StepUp::frisch(0));
    }

    public function testVerbrauchenLoeschtDieFreigabe(): void {
        StepUp::markieren(7);
        StepUp::verbrauchen();

        $this->assertFalse(StepUp::frisch(7));
    }

    public function testOhneFaktorIstNichtsZuBestaetigen(): void {
        $this->assertTrue(StepUp::erfuellt(7, []));
        $this->assertFalse(StepUp::erfuellt(7, [SecondFactors::PASSKEY]));

        StepUp::markieren(7);
        $this->assertTrue(StepUp::erfuellt(7, [SecondFactors::PASSKEY]));
        $this->assertFalse(StepUp::erfuellt(8, [SecondFactors::TOTP]));
    }

    /**
     * Nur die bekannten Ziele. Ein frei übergebener Pfad wäre eine offene
     * Weiterleitung hinter einer Passwortabfrage.
     */
    public function testZielKenntNurDieListe(): void {
        $this->assertSame('/2fa/setup', StepUp::ziel(null));
        $this->assertSame('/2fa/setup', StepUp::ziel('setup'));
        $this->assertSame('/profil', StepUp::ziel('profil'));
        $this->assertSame('/profil#passkeys', StepUp::ziel('passkeys'));
        $this->assertSame('/profil#email', StepUp::ziel('email'));

        foreach (['https://evil.example', '//evil.example', '/admin', 'PROFIL', ''] as $fremd) {
            $this->assertSame('/2fa/setup', StepUp::ziel($fremd), "'{$fremd}' darf kein eigenes Ziel ergeben.");
            $this->assertSame('setup', StepUp::fuer($fremd));
        }
    }

    /**
     * Welcher Code zählt - dieselbe Weiche für die Prüfung und die Felder
     * der Seite. TOTP geht vor, der Mailcode nur ohne TOTP, ein reines
     * Passkey-Konto tippt keinen Code.
     */
    public function testCodeArtFolgtDenFaktoren(): void {
        $this->assertSame(SecondFactors::TOTP, StepUp::codeArt([SecondFactors::TOTP]));
        $this->assertSame(SecondFactors::TOTP, StepUp::codeArt([SecondFactors::TOTP, SecondFactors::EMAIL]));
        $this->assertSame(SecondFactors::TOTP, StepUp::codeArt([SecondFactors::PASSKEY, SecondFactors::TOTP]));
        $this->assertSame(SecondFactors::EMAIL, StepUp::codeArt([SecondFactors::EMAIL]));
        $this->assertSame(SecondFactors::EMAIL, StepUp::codeArt([SecondFactors::PASSKEY, SecondFactors::EMAIL]));
        $this->assertNull(StepUp::codeArt([SecondFactors::PASSKEY]));
        $this->assertNull(StepUp::codeArt([]));
    }

    /** Ohne passenden Faktor besteht die Codeprüfung nie - ganz ohne Datenbank. */
    public function testReinesPasskeyKontoBestehtDieCodepruefungNie(): void {
        $this->assertFalse(StepUp::codePruefen(7, [], [SecondFactors::PASSKEY], '123456', '123456'));
        $this->assertFalse(StepUp::codePruefen(7, [], [], '123456', '123456'));
    }
}
