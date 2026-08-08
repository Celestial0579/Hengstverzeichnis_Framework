<?php
// tests/Unit/Security/OidcDiscoveryTest.php

namespace Tests\Unit\Security;

use App\Security\OidcDiscovery;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests für App\Security\OidcDiscovery::parse() - die netzfreie
 * Validierung des Discovery-Dokuments für den generischen OIDC-Modus.
 * Fail-closed ist hier der Vertrag: Jede Abweichung (falscher Issuer,
 * fehlender Endpunkt, unverschlüsselter Endpunkt außerhalb von Loopback)
 * muss den Login-Versuch abbrechen, nie stillschweigend durchgehen.
 */
class OidcDiscoveryTest extends TestCase {

    private const ISSUER = 'https://auth.example.org/application/o/hengstverzeichnis/';

    /** Authentik-artiges, gültiges Discovery-Dokument. */
    private function validDocument(array $overrides = []): string {
        return json_encode(array_merge([
            'issuer' => self::ISSUER,
            'authorization_endpoint' => 'https://auth.example.org/application/o/authorize/',
            'token_endpoint' => 'https://auth.example.org/application/o/token/',
            'jwks_uri' => 'https://auth.example.org/application/o/hengstverzeichnis/jwks/',
            'response_types_supported' => ['code'],
        ], $overrides));
    }

    public function testValidAuthentikDocumentReturnsBothEndpoints(): void {
        $endpoints = OidcDiscovery::parse($this->validDocument(), self::ISSUER);
        $this->assertSame('https://auth.example.org/application/o/authorize/', $endpoints['authorization_endpoint']);
        $this->assertSame('https://auth.example.org/application/o/token/', $endpoints['token_endpoint']);
    }

    public function testIssuerMismatchIsRejected(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('issuer');
        OidcDiscovery::parse($this->validDocument(), 'https://andere.example.org/application/o/hengstverzeichnis/');
    }

    public function testTrailingSlashDifferenceIsRejected(): void {
        // RFC 8414: exakter Vergleich - ein fehlender Slash ist ein anderer Issuer.
        $this->expectException(\RuntimeException::class);
        OidcDiscovery::parse($this->validDocument(), rtrim(self::ISSUER, '/'));
    }

    public function testMissingTokenEndpointIsRejected(): void {
        $doc = json_decode($this->validDocument(), true);
        unset($doc['token_endpoint']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('token_endpoint');
        OidcDiscovery::parse(json_encode($doc), self::ISSUER);
    }

    public function testHttpEndpointOnPublicHostIsRejected(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('https');
        OidcDiscovery::parse(
            $this->validDocument(['token_endpoint' => 'http://auth.example.org/application/o/token/']),
            self::ISSUER
        );
    }

    public function testHttpOnLoopbackIsAccepted(): void {
        // Lokale Tests/Dev-Setups: http nur auf Loopback erlaubt.
        $issuer = 'http://127.0.0.1:9000/application/o/test/';
        $doc = json_encode([
            'issuer' => $issuer,
            'authorization_endpoint' => 'http://127.0.0.1:9000/application/o/authorize/',
            'token_endpoint' => 'http://127.0.0.1:9000/application/o/token/',
        ]);
        $endpoints = OidcDiscovery::parse($doc, $issuer);
        $this->assertSame('http://127.0.0.1:9000/application/o/token/', $endpoints['token_endpoint']);
    }

    public function testBrokenJsonIsRejected(): void {
        $this->expectException(\RuntimeException::class);
        OidcDiscovery::parse('{kaputt', self::ISSUER);
    }
}
