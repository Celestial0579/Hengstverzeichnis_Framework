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
        $claims = OidcIdToken::parseAndValidate($jwt, self::CLIENT_ID, self::TENANT_ID);
        $this->assertSame('user@example.org', $claims['preferred_username']);
    }

    public function testWrongAudienceIsRejected(): void {
        $claims = $this->validClaims();
        $claims['aud'] = 'andere-app';
        $this->expectException(\RuntimeException::class);
        OidcIdToken::parseAndValidate($this->makeJwt($claims), self::CLIENT_ID, self::TENANT_ID);
    }

    public function testWrongTenantIssuerIsRejected(): void {
        $claims = $this->validClaims();
        $claims['iss'] = 'https://login.microsoftonline.com/fremder-tenant/v2.0';
        $this->expectException(\RuntimeException::class);
        OidcIdToken::parseAndValidate($this->makeJwt($claims), self::CLIENT_ID, self::TENANT_ID);
    }

    public function testExpiredTokenIsRejected(): void {
        $claims = $this->validClaims();
        $claims['exp'] = time() - 3600;
        $this->expectException(\RuntimeException::class);
        OidcIdToken::parseAndValidate($this->makeJwt($claims), self::CLIENT_ID, self::TENANT_ID);
    }

    public function testMalformedTokenIsRejected(): void {
        $this->expectException(\RuntimeException::class);
        OidcIdToken::parseAndValidate('kein-jwt', self::CLIENT_ID, self::TENANT_ID);
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
}
