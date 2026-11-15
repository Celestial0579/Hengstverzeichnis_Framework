<?php
// tests/Unit/Security/OidcPkceTest.php

namespace Tests\Unit\Security;

use App\Security\OidcPkce;
use PHPUnit\Framework\TestCase;

/**
 * PKCE (RFC 7636, S256) für den OIDC-Login (GHSA-g8jp-xvcw-m27p).
 */
class OidcPkceTest extends TestCase {

    /** Testvektor aus RFC 7636, Anhang B. */
    public function testChallengeEntsprichtDemRfcTestvektor(): void {
        $this->assertSame(
            'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            OidcPkce::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk')
        );
    }

    public function testVerifierHatLaengeUndZeichensatzNachRfc(): void {
        $verifier = OidcPkce::verifier();
        // RFC 7636, 4.1: 43 bis 128 Zeichen aus [A-Z a-z 0-9 - . _ ~].
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier);
        $this->assertSame(43, strlen($verifier));
    }

    public function testVerifierIstJedesMalNeu(): void {
        $werte = array_map(static fn(): string => OidcPkce::verifier(), range(1, 20));
        $this->assertCount(20, array_unique($werte));
    }

    public function testMethodeIstS256(): void {
        $this->assertSame('S256', OidcPkce::METHODE);
    }
}
