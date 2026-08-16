<?php
// tests/Unit/Security/OidcIdTokenTest.php

namespace Tests\Unit\Security;

use App\Security\OidcIdToken;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests für App\Security\OidcIdToken (#42): Claim-Validierung
 * (aud/iss/exp) und E-Mail-Extraktion des EntraID-SSO-Logins.
 */
class OidcIdTokenTest extends TestCase {

    private const CLIENT_ID = 'client-123';
    private const TENANT_ID = 'tenant-abc';
    private const ISSUER = 'https://login.microsoftonline.com/tenant-abc/v2.0';

    private function makeJwt(array $claims): string {
        $encode = fn(array $data) => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
        return $encode(['alg' => 'RS256', 'typ' => 'JWT']) . '.' . $encode($claims) . '.' . $encode(['sig' => 'x']);
    }

    private function validClaims(): array {
        return [
            'aud' => self::CLIENT_ID,
            'iss' => 'https://login.microsoftonline.com/' . self::TENANT_ID . '/v2.0',
            'exp' => time() + 3600,
            'preferred_username' => 'user@example.org',
        ];
    }

    public function testValidTokenReturnsClaims(): void {
        $jwt = $this->makeJwt($this->validClaims());
        $claims = OidcIdToken::parseAndValidate($jwt, self::CLIENT_ID, self::ISSUER);
        $this->assertSame('user@example.org', $claims['preferred_username']);
    }

    public function testWrongAudienceIsRejected(): void {
        $claims = $this->validClaims();
        $claims['aud'] = 'andere-app';
        $this->expectException(\RuntimeException::class);
        OidcIdToken::parseAndValidate($this->makeJwt($claims), self::CLIENT_ID, self::ISSUER);
    }

    public function testWrongTenantIssuerIsRejected(): void {
        $claims = $this->validClaims();
        $claims['iss'] = 'https://login.microsoftonline.com/fremder-tenant/v2.0';
        $this->expectException(\RuntimeException::class);
        OidcIdToken::parseAndValidate($this->makeJwt($claims), self::CLIENT_ID, self::ISSUER);
    }

    public function testGenericIssuerWithTrailingSlashIsAcceptedExactly(): void {
        // Authentik-Issuer enden auf '/' - der exakte Vergleich muss sie
        // unverändert akzeptieren.
        $issuer = 'https://auth.example.org/application/o/hengstverzeichnis/';
        $claims = $this->validClaims();
        $claims['iss'] = $issuer;
        $result = OidcIdToken::parseAndValidate($this->makeJwt($claims), self::CLIENT_ID, $issuer);
        $this->assertSame($issuer, $result['iss']);
    }

    public function testTrailingSlashDifferenceIsRejected(): void {
        // Exakter Vergleich: konfiguriert MIT Slash, Token OHNE - abgelehnt.
        $claims = $this->validClaims();
        $claims['iss'] = 'https://auth.example.org/application/o/hengstverzeichnis';
        $this->expectException(\RuntimeException::class);
        OidcIdToken::parseAndValidate(
            $this->makeJwt($claims),
            self::CLIENT_ID,
            'https://auth.example.org/application/o/hengstverzeichnis/'
        );
    }

    public function testExpiredTokenIsRejected(): void {
        $claims = $this->validClaims();
        $claims['exp'] = time() - 3600;
        $this->expectException(\RuntimeException::class);
        OidcIdToken::parseAndValidate($this->makeJwt($claims), self::CLIENT_ID, self::ISSUER);
    }

    public function testMalformedTokenIsRejected(): void {
        $this->expectException(\RuntimeException::class);
        OidcIdToken::parseAndValidate('kein-jwt', self::CLIENT_ID, self::ISSUER);
    }

    public function testExtractEmailPrefersEmailClaimAndValidatesFormat(): void {
        $this->assertSame('mail@example.org', OidcIdToken::extractEmail([
            'email' => 'mail@example.org',
            'preferred_username' => 'upn@example.org',
        ]));
        $this->assertSame('upn@example.org', OidcIdToken::extractEmail([
            'preferred_username' => 'upn@example.org',
        ]));
        // UPNs ohne E-Mail-Format (z. B. reine Kontonamen) werden verworfen.
        $this->assertNull(OidcIdToken::extractEmail(['preferred_username' => 'DOMAIN\\benutzer']));
        $this->assertNull(OidcIdToken::extractEmail([]));
    }

    /**
     * Die E-Mail ist der einzige Anknüpfungspunkt an das lokale Konto. Sagt
     * der Provider ausdrücklich, dass sie unbestätigt ist, wäre sie eine
     * Selbstauskunft: Bei einem IdP mit Selbstregistrierung genügte sonst ein
     * Konto mit der Adresse eines Administrators.
     */
    public function testUnverifiedEmailClaimIsRejected(): void {
        foreach ([false, 'false', 0, '0'] as $unverified) {
            $this->assertNull(
                OidcIdToken::extractEmail([
                    'email' => 'opfer@example.org',
                    'email_verified' => $unverified,
                ]),
                'email_verified=' . var_export($unverified, true) . ' muss zur Ablehnung führen'
            );
        }
    }

    public function testVerifiedEmailClaimIsAccepted(): void {
        foreach ([true, 'true', 1, '1'] as $verified) {
            $this->assertSame('nutzer@example.org', OidcIdToken::extractEmail([
                'email' => 'nutzer@example.org',
                'email_verified' => $verified,
            ]));
        }
    }

    /**
     * Entra ID sendet den Claim für Geschäftskonten nicht - ein fehlender
     * Claim ist keine Aussage und darf funktionierende Installationen nicht
     * aussperren.
     */
    public function testMissingEmailVerifiedClaimStaysAccepted(): void {
        $this->assertSame('nutzer@example.org', OidcIdToken::extractEmail([
            'email' => 'nutzer@example.org',
        ]));
    }

    /**
     * Ein vorhandener, aber unbestätigter email-Claim darf nicht auf den
     * schwächeren preferred_username ausweichen - sonst hebt der Rückfall die
     * Prüfung wieder auf.
     */
    public function testUnverifiedEmailDoesNotFallBackToPreferredUsername(): void {
        $this->assertNull(OidcIdToken::extractEmail([
            'email' => 'opfer@example.org',
            'email_verified' => false,
            'preferred_username' => 'angreifer@example.org',
        ]));
    }
}
