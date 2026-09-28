<?php
// tests/Unit/Security/BaseUrlTest.php

namespace Tests\Unit\Security;

use App\Security\BaseUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Die feste Stamm-URL für Token-Links (Audit M6).
 *
 * Kern: Ohne settings.base_url, APP_URL und TRUSTED_HOSTS gibt es KEINE Basis
 * - der Host-Header allein (hier evil.example) darf nie in einen Reset-Link
 * gelangen. tests/bootstrap.php definiert weder APP_URL noch TRUSTED_HOSTS
 * als Konstante; beide Quellen laufen hier über putenv().
 */
class BaseUrlTest extends TestCase {

    private string|false $appUrlVorher = false;
    private string|false $trustedHostsVorher = false;

    protected function setUp(): void {
        $this->appUrlVorher = getenv('APP_URL');
        $this->trustedHostsVorher = getenv('TRUSTED_HOSTS');
        putenv('APP_URL');
        putenv('TRUSTED_HOSTS');
        unset($_SERVER['HTTP_HOST'], $_SERVER['HTTPS']);
    }

    protected function tearDown(): void {
        putenv($this->appUrlVorher === false ? 'APP_URL' : 'APP_URL=' . $this->appUrlVorher);
        putenv($this->trustedHostsVorher === false ? 'TRUSTED_HOSTS' : 'TRUSTED_HOSTS=' . $this->trustedHostsVorher);
        unset($_SERVER['HTTP_HOST'], $_SERVER['HTTPS']);
    }

    public function testOhneFesteQuelleKeinLinkAusDemHostHeader(): void {
        $_SERVER['HTTP_HOST'] = 'evil.example';

        $this->assertNull(BaseUrl::forLinks(null));
        $this->assertNull(BaseUrl::forLinks(''));
        $this->assertNull(BaseUrl::fixed(null));
        $this->assertFalse(BaseUrl::isTrusted(null));
        $this->assertFalse(BaseUrl::isTrusted('   '));
    }

    public function testSettingGewinntGegenDenHostHeader(): void {
        $_SERVER['HTTP_HOST'] = 'evil.example';
        putenv('APP_URL=https://env.example');

        $this->assertSame('https://verband.example/', BaseUrl::forLinks('https://verband.example/'));
        $this->assertSame('https://verband.example/', BaseUrl::forLinks('https://verband.example'));
        $this->assertTrue(BaseUrl::isTrusted('https://verband.example/'));
    }

    public function testEnvAppUrlOhneSetting(): void {
        $_SERVER['HTTP_HOST'] = 'evil.example';
        putenv('APP_URL=http://127.0.0.1:8080');

        // Lokale Adressen sind in APP_URL gewollt (Tests, Entwicklung).
        $this->assertSame('http://127.0.0.1:8080/', BaseUrl::forLinks(''));
        $this->assertSame('http://127.0.0.1:8080/', BaseUrl::fixed(null));
        $this->assertTrue(BaseUrl::isTrusted(null));
    }

    public function testUnbrauchbaresAppUrlIstKeineBasis(): void {
        $_SERVER['HTTP_HOST'] = 'evil.example';
        putenv('APP_URL=verband.example');
        $this->assertNull(BaseUrl::forLinks(null));

        putenv('APP_URL=javascript:alert(1)');
        $this->assertNull(BaseUrl::forLinks(null));
    }

    public function testAllowlistLaesstNurGelisteteHostsZu(): void {
        putenv('TRUSTED_HOSTS=verband.example');

        $_SERVER['HTTP_HOST'] = 'verband.example';
        $this->assertSame('http://verband.example/', BaseUrl::forLinks(null));

        $_SERVER['HTTPS'] = 'on';
        $this->assertSame('https://verband.example/', BaseUrl::forLinks(null));

        $_SERVER['HTTP_HOST'] = 'evil.example';
        $this->assertNull(BaseUrl::forLinks(null));

        // Die Warnung im Dashboard hängt nicht an der einzelnen Anfrage.
        $this->assertTrue(BaseUrl::isTrusted(null));
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function eingaben(): array {
        return [
            'javascript' => ['javascript:alert(1)', null],
            'ftp' => ['ftp://verband.example', null],
            'localhost' => ['http://localhost:8080', null],
            'LOCALHOST' => ['https://LOCALHOST/', null],
            'privat 10.x' => ['http://10.0.0.1', null],
            'loopback' => ['http://127.0.0.1', null],
            'ipv6 loopback' => ['http://[::1]:8080', null],
            'schemalos' => ['verband.example', null],
            'leer' => ['', null],
            'https' => ['https://x.example', 'https://x.example/'],
            'https mit Slash' => ['https://x.example/', 'https://x.example/'],
            'http mit Pfad' => ['http://x.example/verzeichnis/', 'http://x.example/verzeichnis/'],
            'öffentliche IP' => ['https://93.184.216.34', 'https://93.184.216.34/'],
        ];
    }

    #[DataProvider('eingaben')]
    public function testNormalize(string $eingabe, ?string $erwartet): void {
        $this->assertSame($erwartet, BaseUrl::normalize($eingabe));
    }

    public function testVorschlagNurFuerOeffentlicheHosts(): void {
        $_SERVER['HTTP_HOST'] = 'localhost:8080';
        $this->assertNull(BaseUrl::suggestion());

        $_SERVER['HTTP_HOST'] = '192.168.1.20';
        $this->assertNull(BaseUrl::suggestion());

        $_SERVER['HTTP_HOST'] = 'verband.example';
        $this->assertSame('http://verband.example/', BaseUrl::suggestion());

        unset($_SERVER['HTTP_HOST']);
        $this->assertNull(BaseUrl::suggestion());
    }
}
