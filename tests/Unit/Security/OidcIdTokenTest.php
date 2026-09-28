<?php
// tests/Unit/Security/OidcIdTokenTest.php

namespace Tests\Unit\Security;

use App\Security\OidcIdToken;
use PHPUnit\Framework\Attributes\DataProvider;
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
        ], entraModus: true));
        $this->assertSame('upn@example.org', OidcIdToken::extractEmail([
            'preferred_username' => 'upn@example.org',
        ], entraModus: true));
        // UPNs ohne E-Mail-Format (z. B. reine Kontonamen) werden verworfen.
        $this->assertNull(OidcIdToken::extractEmail(['preferred_username' => 'DOMAIN\\benutzer'], entraModus: true));
        $this->assertNull(OidcIdToken::extractEmail([], entraModus: true));
        $this->assertNull(OidcIdToken::extractEmail([
            'email' => 'kein-adressformat',
            'email_verified' => true,
        ]));
    }

    /** @return array<string, array{0: bool}> */
    public static function modi(): array {
        return ['generisch' => [false], 'ENTRA' => [true]];
    }

    /**
     * Die E-Mail ist der einzige Anknüpfungspunkt an das lokale Konto. Sagt
     * der Provider ausdrücklich, dass sie unbestätigt ist, wäre sie eine
     * Selbstauskunft: Bei einem IdP mit Selbstregistrierung genügte sonst ein
     * Konto mit der Adresse eines Administrators.
     */
    #[DataProvider('modi')]
    public function testUnverifiedEmailClaimIsRejected(bool $entraModus): void {
        foreach ([false, 'false', 0, '0', null, 'unsinn', [], ['true']] as $unverified) {
            $this->assertNull(
                OidcIdToken::extractEmail([
                    'email' => 'opfer@example.org',
                    'email_verified' => $unverified,
                ], $entraModus),
                'email_verified=' . var_export($unverified, true) . ' muss zur Ablehnung führen'
            );
        }
    }

    #[DataProvider('modi')]
    public function testVerifiedEmailClaimIsAccepted(bool $entraModus): void {
        foreach ([true, 'true', 1, '1'] as $verified) {
            $this->assertSame('nutzer@example.org', OidcIdToken::extractEmail([
                'email' => 'nutzer@example.org',
                'email_verified' => $verified,
            ], $entraModus));
        }
    }

    /**
     * Entra ID sendet den Claim für Geschäftskonten nicht - ein fehlender
     * Claim ist dort keine Aussage und darf funktionierende Installationen
     * nicht aussperren.
     */
    public function testMissingEmailVerifiedClaimStaysAccepted(): void {
        $this->assertSame('nutzer@example.org', OidcIdToken::extractEmail([
            'email' => 'nutzer@example.org',
        ], entraModus: true));
    }

    /**
     * Ein vorhandener, aber unbestätigter email-Claim darf nicht auf den
     * schwächeren preferred_username ausweichen - sonst hebt der Rückfall die
     * Prüfung wieder auf.
     */
    #[DataProvider('modi')]
    public function testUnverifiedEmailDoesNotFallBackToPreferredUsername(bool $entraModus): void {
        $this->assertNull(OidcIdToken::extractEmail([
            'email' => 'opfer@example.org',
            'email_verified' => false,
            'preferred_username' => 'angreifer@example.org',
        ], $entraModus));
    }

    /**
     * Fälle für die M19-Reproduktion: email fehlt/leer/null/Leerraum, jeweils
     * mit email_verified false, 'false', 0 oder fehlend.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function ohneAdresse(): array {
        $emails = ['fehlt' => '__fehlt__', 'leer' => '', 'null' => null, 'leerraum' => '   '];
        $status = ['false' => false, "'false'" => 'false', '0' => 0, 'fehlt' => '__fehlt__'];
        $faelle = [];
        foreach ($emails as $eName => $email) {
            foreach ($status as $sName => $verified) {
                $claims = ['preferred_username' => 'admin@verein.de'];
                if ($email !== '__fehlt__') {
                    $claims['email'] = $email;
                }
                if ($verified !== '__fehlt__') {
                    $claims['email_verified'] = $verified;
                }
                $faelle["email {$eName}, email_verified {$sName}"] = [$claims];
            }
        }
        return $faelle;
    }

    /**
     * Audit M19: Bei Keycloak und Authentik wählt der Benutzer
     * preferred_username selbst. Im generischen Modus darf er nie zur
     * Anmeldeadresse werden - wer sich dort "admin@verein.de" nannte, war
     * sonst lokaler Administrator.
     */
    #[DataProvider('ohneAdresse')]
    public function testGenericModeNeverFallsBackToPreferredUsername(array $claims): void {
        $this->assertNull(OidcIdToken::extractEmail($claims));
        $this->assertNull(OidcIdToken::extractEmail($claims, false));
    }

    public function testEntraModeRejectsUpnFallbackWhenEmailVerifiedFalse(): void {
        foreach ([false, 'false', 0, null] as $unverified) {
            $this->assertNull(OidcIdToken::extractEmail([
                'email_verified' => $unverified,
                'preferred_username' => 'upn@example.org',
            ], entraModus: true));
        }
    }

    public function testEntraModeKeepsUpnFallbackWithoutEmailVerified(): void {
        $this->assertSame('upn@example.org', OidcIdToken::extractEmail([
            'email' => '',
            'preferred_username' => 'upn@example.org',
        ], entraModus: true));
    }

    /** Standardkonforme Provider senden email_verified mit dem Scope email. */
    public function testGenericModeRequiresExplicitlyVerifiedEmail(): void {
        $this->assertNull(OidcIdToken::extractEmail(['email' => 'nutzer@example.org']));
        $this->assertNull(OidcIdToken::extractEmail(['email' => 'nutzer@example.org', 'email_verified' => null]));
        foreach ([true, 'true', 1, '1'] as $verified) {
            $this->assertSame(
                'nutzer@example.org',
                OidcIdToken::extractEmail(['email' => ' nutzer@example.org ', 'email_verified' => $verified])
            );
        }
    }

    /**
     * Nicht-Text-Werte ergeben keine Adresse und keine Warnung
     * ("Array to string conversion" würde PHPUnit als Fehler melden).
     */
    public function testNonStringEmailClaimIsRejected(): void {
        foreach ([['a@example.org'], 42, true, ['x' => 1]] as $wert) {
            $this->assertNull(OidcIdToken::extractEmail(['email' => $wert, 'email_verified' => true]));
            $this->assertNull(OidcIdToken::extractEmail(['email' => $wert], entraModus: true));
        }
        $this->assertNull(OidcIdToken::extractEmail(['preferred_username' => ['upn@example.org']], entraModus: true));
    }

    public function testArrayEmailVerifiedCountsAsFalse(): void {
        $this->assertNull(OidcIdToken::extractEmail([
            'email' => 'nutzer@example.org',
            'email_verified' => [true],
        ], entraModus: true));
    }

    public function testAblehnungsgrundEnthaeltKeineAdresse(): void {
        $grund = OidcIdToken::ablehnungsgrund([
            'email' => '',
            'email_verified' => false,
            'preferred_username' => 'admin@verein.de',
        ], false);
        $this->assertStringNotContainsString('admin@verein.de', $grund);
        $this->assertStringContainsString('email-Claim leer', $grund);
        $this->assertStringContainsString('email_verified=false', $grund);
        $this->assertStringContainsString('Modus=generisch', $grund);

        $grund = OidcIdToken::ablehnungsgrund([
            'email' => 'opfer@example.org',
            'preferred_username' => 'kein-upn',
        ], true);
        $this->assertStringNotContainsString('opfer@example.org', $grund);
        $this->assertStringNotContainsString('kein-upn', $grund);
        $this->assertStringContainsString('email_verified fehlt', $grund);
        $this->assertStringContainsString('Modus=ENTRA', $grund);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: array<int, string>, 2: array<int, string>, 3: ?string}> */
    public static function mfaFaelle(): array {
        return [
            'amr pwd+mfa' => [['amr' => ['pwd', 'mfa']], ['mfa'], [], 'amr=mfa'],
            'amr nur pwd' => [['amr' => ['pwd']], ['mfa'], [], null],
            'amr fehlt' => [[], ['mfa'], [], null],
            'amr Zahl' => [['amr' => 7], ['mfa'], [], null],
            'amr Objekt' => [['amr' => ['x' => ['mfa']]], ['mfa'], [], null],
            'amr als String' => [['amr' => 'mfa'], ['mfa'], [], 'amr=mfa'],
            'acr 1 bei leerer Liste' => [['acr' => '1'], ['mfa'], [], null],
            'acr gold' => [['acr' => 'gold'], ['mfa'], ['gold'], 'acr=gold'],
            'acr als Zahl' => [['acr' => 1], ['mfa'], ['1'], null],
            'pwd in der Liste zählt nie' => [['amr' => ['pwd']], ['pwd', 'mfa'], [], null],
            'Entra TOTP' => [['amr' => ['pwd', 'totp', 'mfa']], ['mfa'], [], 'amr=mfa'],
        ];
    }

    /**
     * @param array<string, mixed> $claims
     * @param array<int, string> $amr
     * @param array<int, string> $acr
     */
    #[DataProvider('mfaFaelle')]
    public function testMfaNachweis(array $claims, array $amr, array $acr, ?string $erwartet): void {
        $this->assertSame($erwartet, OidcIdToken::mfaNachweis($claims, $amr, $acr));
    }
}
