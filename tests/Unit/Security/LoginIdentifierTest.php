<?php
// tests/Unit/Security/LoginIdentifierTest.php

namespace Tests\Unit\Security;

use App\Security\LoginIdentifier;
use PHPUnit\Framework\TestCase;

/**
 * Die Anmeldekennung (#348).
 *
 * Die Klasse hat nur eine Aufgabe, aber daran haengt der Rate-Limiter: Wenn
 * zwei Schreibweisen derselben Kennung verschiedene Zeichenketten ergeben,
 * bekommt ein Angreifer zwei Zaehler fuer dasselbe Konto.
 */
class LoginIdentifierTest extends TestCase {

    public function testNormalizeTrimmtUndFaltetKleinschreibung(): void {
        $this->assertSame('anna@example.org', LoginIdentifier::normalize('  Anna@Example.ORG  '));
        $this->assertSame('redakteurin', LoginIdentifier::normalize('Redakteurin'));
    }

    /**
     * Der eigentliche Punkt: strtolower() waere byteweise und liesse Umlaute
     * stehen. Die Datenbank vergleicht in utf8mb4_unicode_ci und faende
     * beide Schreibweisen als dasselbe Konto - der Zaehler muss das auch.
     */
    public function testNormalizeFaeltAuchUmlauteUndScharfesS(): void {
        $this->assertSame(
            LoginIdentifier::normalize('müller'),
            LoginIdentifier::normalize('MÜLLER'),
            'Gross- und Kleinschreibung eines Umlauts muss denselben Zaehler treffen.'
        );
        $this->assertSame(
            LoginIdentifier::normalize('gross'),
            LoginIdentifier::normalize('GROSS')
        );
    }

    public function testNormalizeBegrenztDieLaenge(): void {
        $lang = str_repeat('a', 300);
        $this->assertSame(LoginIdentifier::MAX_LENGTH, mb_strlen(LoginIdentifier::normalize($lang)));
    }

    public function testLooksLikeEmailFragtNurNachDemAtZeichen(): void {
        $this->assertTrue(LoginIdentifier::looksLikeEmail('a@b'));
        $this->assertTrue(LoginIdentifier::looksLikeEmail('kaputt@'), 'Unvollstaendige Adresse ist trotzdem eine Adresse.');
        $this->assertFalse(LoginIdentifier::looksLikeEmail('redakteurin'));
    }

    public function testBenutzernameOhneAtIstInOrdnung(): void {
        $this->assertSame([], LoginIdentifier::usernameErrors('geschaeftsstelle'));
        $this->assertSame([], LoginIdentifier::usernameErrors('  praktikant.2026  '), 'Rand-Leerzeichen zaehlen nicht.');
    }

    public function testBenutzernameMitAtWirdAbgelehnt(): void {
        $fehler = LoginIdentifier::usernameErrors('kunde@example.org');
        $this->assertCount(1, $fehler);
        $this->assertStringContainsString('@', $fehler[0]);
    }

    public function testLeererBenutzernameMeldetGenauEinenFehler(): void {
        // Nicht zwei: Ein leerer Name ist nicht zusaetzlich "zu lang" oder
        // "enthaelt @" - die Meldung soll sagen, was fehlt, und aufhoeren.
        $this->assertCount(1, LoginIdentifier::usernameErrors('   '));
    }

    public function testZuLangerBenutzernameWirdAbgelehnt(): void {
        $fehler = LoginIdentifier::usernameErrors(str_repeat('x', 51));
        $this->assertNotSame([], $fehler);
    }
}
