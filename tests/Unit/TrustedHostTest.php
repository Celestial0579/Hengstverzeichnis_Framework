<?php
// tests/Unit/TrustedHostTest.php

namespace Tests\Unit;

use App\Security\TrustedHost;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests für App\Security\TrustedHost (Issue #116): Der Host-Header darf
 * nur validiert (und, falls TRUSTED_HOSTS konfiguriert ist, nur allowlisted)
 * in absolute URLs einfließen - sonst droht Reset-Link-Poisoning über einen
 * gefälschten Host:-Header.
 */
class TrustedHostTest extends TestCase {

    protected function setUp(): void {
        unset($_SERVER['HTTP_HOST']);
        putenv('TRUSTED_HOSTS');
    }

    protected function tearDown(): void {
        unset($_SERVER['HTTP_HOST']);
        putenv('TRUSTED_HOSTS');
    }

    public function testMissingHostHeaderResolvesToEmptyString(): void {
        $this->assertSame('', TrustedHost::resolve());
    }

    public function testSyntacticallyValidHostIsReturnedWithoutAllowlist(): void {
        $_SERVER['HTTP_HOST'] = 'verzeichnis.example.org';
        $this->assertSame('verzeichnis.example.org', TrustedHost::resolve());
    }

    public function testHostWithPortIsReturnedIncludingPort(): void {
        $_SERVER['HTTP_HOST'] = 'localhost:8080';
        $this->assertSame('localhost:8080', TrustedHost::resolve());
    }

    public function testIpv6LiteralIsAccepted(): void {
        $_SERVER['HTTP_HOST'] = '[::1]:8767';
        $this->assertSame('[::1]:8767', TrustedHost::resolve());
    }

    /**
     * Header-Injection-Versuche (Sonderzeichen, eingebettete URLs, Whitespace)
     * dürfen nie durchgereicht werden.
     */
    public function testSyntacticallyInvalidHostsAreRejected(): void {
        $invalid = [
            'evil.example/foo',
            'host with space',
            'a@b.example',
            "attacker.example\r\nX-Injected: 1",
            'http://attacker.example',
            '-leadinghyphen.example',
            'trailingdot.example.:80x',
        ];
        foreach ($invalid as $host) {
            $_SERVER['HTTP_HOST'] = $host;
            $this->assertSame('', TrustedHost::resolve(), "Host '{$host}' hätte verworfen werden müssen");
        }
    }

    public function testAllowlistedHostIsAcceptedCaseInsensitively(): void {
        putenv('TRUSTED_HOSTS=Verzeichnis.Example.org, other.example');
        $_SERVER['HTTP_HOST'] = 'verzeichnis.example.ORG';
        $this->assertSame('verzeichnis.example.ORG', TrustedHost::resolve());
    }

    public function testNonAllowlistedHostIsRejected(): void {
        putenv('TRUSTED_HOSTS=verzeichnis.example.org');
        $_SERVER['HTTP_HOST'] = 'attacker.example';
        $this->assertSame('', TrustedHost::resolve());
    }

    public function testAllowlistMatchIgnoresPort(): void {
        putenv('TRUSTED_HOSTS=verzeichnis.example.org');
        $_SERVER['HTTP_HOST'] = 'verzeichnis.example.org:8443';
        $this->assertSame('verzeichnis.example.org:8443', TrustedHost::resolve());
    }

    public function testLeadingDotEntryMatchesSubdomainsAndBareDomain(): void {
        putenv('TRUSTED_HOSTS=.example.org');

        $_SERVER['HTTP_HOST'] = 'foo.example.org';
        $this->assertSame('foo.example.org', TrustedHost::resolve());

        $_SERVER['HTTP_HOST'] = 'example.org';
        $this->assertSame('example.org', TrustedHost::resolve());

        // Suffix-Angriff: "evilexample.org" endet NICHT auf ".example.org"
        $_SERVER['HTTP_HOST'] = 'evilexample.org';
        $this->assertSame('', TrustedHost::resolve());
    }
}
