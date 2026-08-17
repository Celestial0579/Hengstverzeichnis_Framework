<?php
// tests/Unit/Helper/ExternalUrlTest.php

namespace Tests\Unit\Helper;

use App\Helper\ExternalUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * App\Helper\ExternalUrl entscheidet, ob ein von Hand eingetragener
 * Website-Freitext als Verweisziel taugt (#293).
 *
 * Der Punkt ist nicht Formatschönheit, sondern das Protokoll:
 * `htmlspecialchars()` kodiert den Attributwert korrekt und lässt
 * `javascript:` dabei unverändert durch. Genau so stand es bis #293 in den
 * Website-Verweisen von Zuchtstationen - eingetragen im Admin-Bereich,
 * ausgegeben auf einer öffentlichen Seite.
 */
class ExternalUrlTest extends TestCase {

    /** @return array<string, array{0: ?string}> */
    public static function unsafeValues(): array {
        return [
            'javascript' => ['javascript:alert(1)'],
            'javascript in Grossbuchstaben' => ['JavaScript:alert(1)'],
            'data-URI' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
            'vbscript' => ['vbscript:msgbox(1)'],
            'file' => ['file:///etc/passwd'],
            'kein Schema' => ['www.example.de'],
            'nur Text' => ['Ruft mich einfach an'],
            'leer' => [''],
            'nur Leerzeichen' => ['   '],
            'null' => [null],
        ];
    }

    #[DataProvider('unsafeValues')]
    public function testRefusesAnythingThatIsNotHttpOrHttps(?string $value): void {
        $this->assertNull(
            ExternalUrl::hrefOrNull($value),
            'Nur http und https duerfen in ein Verweisziel gelangen.'
        );
    }

    /** @return array<string, array{0: string}> */
    public static function safeValues(): array {
        return [
            'https' => ['https://www.example.de'],
            'http' => ['http://example.de/pfad'],
            'mit Port und Abfrage' => ['https://example.de:8443/seite?a=1&b=2'],
            'HTTPS in Grossbuchstaben' => ['HTTPS://example.de'],
        ];
    }

    #[DataProvider('safeValues')]
    public function testAcceptsHttpAndHttps(string $value): void {
        $this->assertSame($value, ExternalUrl::hrefOrNull($value));
    }

    public function testTrimsSurroundingWhitespace(): void {
        $this->assertSame('https://example.de', ExternalUrl::hrefOrNull('  https://example.de  '));
    }
}
