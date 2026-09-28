<?php
// tests/Unit/Security/ClientIpTest.php

namespace Tests\Unit\Security;

use App\Security\ClientIp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Deckt die zustandslosen öffentlichen Methoden ab: isValidProxyEntry() und
 * rateLimitKey() mit ausdrücklich übergebener Adresse. resolve()/isHttps() hängen
 * von $_SERVER und einem prozessweiten statischen Cache der TRUSTED_PROXIES-
 * Konstante ab und eignen sich daher nicht für isolierte Unit-Tests ohne
 * Prozess-Isolation (siehe Integrationstest-Ebene in Issue #54).
 */
class ClientIpTest extends TestCase {

    #[DataProvider('validEntryProvider')]
    public function testAcceptsValidEntries(string $entry): void {
        $this->assertTrue(ClientIp::isValidProxyEntry($entry), "Erwartet gültig: {$entry}");
    }

    public static function validEntryProvider(): array {
        return [
            'IPv4' => ['10.0.0.5'],
            'IPv4 loopback' => ['127.0.0.1'],
            'IPv4 CIDR' => ['172.16.0.0/12'],
            'IPv4 CIDR /32' => ['192.168.1.1/32'],
            'IPv4 CIDR /0' => ['0.0.0.0/0'],
            'IPv6' => ['::1'],
            'IPv6 full' => ['2001:db8::1'],
            'IPv6 CIDR' => ['2001:db8::/32'],
            'IPv6 CIDR /128' => ['::1/128'],
        ];
    }

    #[DataProvider('invalidEntryProvider')]
    public function testRejectsInvalidEntries(string $entry): void {
        $this->assertFalse(ClientIp::isValidProxyEntry($entry), "Erwartet ungültig: {$entry}");
    }

    public static function invalidEntryProvider(): array {
        return [
            'leer' => [''],
            'kein IP-Format' => ['not-an-ip'],
            'IPv4 mit Buchstaben' => ['10.0.0.a'],
            'IPv4 CIDR Maske nicht numerisch' => ['10.0.0.0/abc'],
            'IPv4 CIDR Maske zu groß' => ['10.0.0.0/33'],
            'IPv6 CIDR Maske zu groß' => ['::1/129'],
            'CIDR Maske negativ als Text' => ['10.0.0.0/-1'],
            'zu viele Slashes' => ['10.0.0.0/24/extra'],
            'nur Slash' => ['/24'],
            'Domainname statt IP' => ['example.com'],
            'SQL-artige Eingabe' => ["10.0.0.0/24' OR '1'='1"],
        ];
    }

    /**
     * Rate-Limit-Schlüssel (Audit M7): IPv6 zählt je /64, IPv4-gemappte
     * Adressen sind IPv4, und was keine Adresse ist, bleibt unverändert.
     */
    #[DataProvider('rateLimitKeyProvider')]
    public function testRateLimitKey(string $ip, int $praefix, string $erwartet): void {
        $this->assertSame($erwartet, ClientIp::rateLimitKey($ip, $praefix));
    }

    public static function rateLimitKeyProvider(): array {
        return [
            'IPv6 im /64' => ['2001:db8:1:2::1', 64, '2001:db8:1:2::/64'],
            'IPv6 gross geschrieben, anderes Interface' => ['2001:DB8:1:2:ffff:ffff:ffff:ffff', 64, '2001:db8:1:2::/64'],
            'IPv6 anderes /64' => ['2001:db8:1:3::1', 64, '2001:db8:1:3::/64'],
            'IPv6 auf /48' => ['2001:db8:1:2::1', 48, '2001:db8:1::/48'],
            'IPv6 krummer Präfix' => ['2001:db8:1:2ff::1', 55, '2001:db8:1:200::/55'],
            'IPv4 unverändert' => ['203.0.113.7', 64, '203.0.113.7'],
            'IPv4 auch bei /48' => ['203.0.113.7', 48, '203.0.113.7'],
            'IPv4-gemappt wird IPv4' => ['::ffff:203.0.113.7', 64, '203.0.113.7'],
            'Unsinn unverändert' => ['kaputt', 64, 'kaputt'],
            'Zonen-ID unverändert' => ['fe80::1%eth0', 64, 'fe80::1%eth0'],
            'schon gekürzt: idempotent' => ['2001:db8:1:2::/64', 64, '2001:db8:1:2::/64'],
        ];
    }

    public function testRateLimitKeyVerlangtEinenSinnvollenPraefix(): void {
        $this->expectException(\InvalidArgumentException::class);
        ClientIp::rateLimitKey('2001:db8::1', 0);
    }
}
