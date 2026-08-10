<?php
// tests/Unit/Helper/ColorContrastTest.php

namespace Tests\Unit\Helper;

use App\Helper\ColorContrast;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests für App\Helper\ColorContrast (#196): Hex-Parsing, WCAG-Ratio
 * und vor allem die Garantie, dass readableTextOn() für JEDE Fläche eine
 * Textfarbe mit mindestens 4,5:1 liefert - das ist die Zusicherung, auf der
 * Footer und Buttons mit admin-konfigurierbarer Markenfarbe aufbauen.
 */
class ColorContrastTest extends TestCase {

    public function testParseHexAcceptsSanitizerFormats(): void {
        $this->assertSame([255, 255, 255], ColorContrast::parseHex('#fff'));
        $this->assertSame([255, 255, 255], ColorContrast::parseHex('#ffff'));
        $this->assertSame([44, 62, 80], ColorContrast::parseHex('#2c3e50'));
        $this->assertSame([44, 62, 80], ColorContrast::parseHex('#2c3e50ff'));
        $this->assertSame([0, 0, 0], ColorContrast::parseHex('#000'));
    }

    public function testParseHexRejectsInvalidValues(): void {
        $this->assertNull(ColorContrast::parseHex('2c3e50'));      // ohne #
        $this->assertNull(ColorContrast::parseHex('#gg0000'));     // keine Hex-Ziffern
        $this->assertNull(ColorContrast::parseHex('#12345'));      // 5 Stellen
        $this->assertNull(ColorContrast::parseHex('#1234567'));    // 7 Stellen
        $this->assertNull(ColorContrast::parseHex(''));
        $this->assertNull(ColorContrast::parseHex('rebeccapurple'));
    }

    public function testContrastRatioKnownValues(): void {
        // Schwarz/Weiß ist das definierte Maximum von 21:1.
        $this->assertEqualsWithDelta(21.0, ColorContrast::contrastRatio('#000000', '#ffffff'), 0.01);
        // Identische Farben: 1:1.
        $this->assertEqualsWithDelta(1.0, ColorContrast::contrastRatio('#2c3e50', '#2c3e50'), 0.001);
        // Reihenfolge ist egal.
        $this->assertSame(
            ColorContrast::contrastRatio('#ffffff', '#2c3e50'),
            ColorContrast::contrastRatio('#2c3e50', '#ffffff')
        );
        // Der dokumentierte Begleitton --secondary-btn-bg wurde für weißen
        // Text mit 5,26:1 gewählt (#169) - die Rechnung muss das reproduzieren.
        $this->assertEqualsWithDelta(5.26, ColorContrast::contrastRatio('#ffffff', '#0e7a66'), 0.05);
    }

    public function testContrastRatioReturnsNullOnInvalidInput(): void {
        $this->assertNull(ColorContrast::contrastRatio('#zzz', '#ffffff'));
        $this->assertNull(ColorContrast::contrastRatio('#ffffff', ''));
    }

    /**
     * Die zentrale Zusicherung: Für jede Fläche erreicht die gelieferte
     * Textfarbe mindestens 4,5:1 (WCAG AA). Geprüft über das volle
     * Websafe-Raster (216 Farben) plus die Grauachse in 5er-Schritten -
     * das deckt insbesondere die Umschaltgrenze Weiß->Schwarz ab, an der
     * ein fest gewählter dunkler Ton scheitern würde.
     */
    public function testReadableTextOnGuaranteesAaForAnyBackground(): void {
        $steps = [0x00, 0x33, 0x66, 0x99, 0xcc, 0xff];
        foreach ($steps as $r) {
            foreach ($steps as $g) {
                foreach ($steps as $b) {
                    $bg = sprintf('#%02x%02x%02x', $r, $g, $b);
                    $fg = ColorContrast::readableTextOn($bg);
                    $ratio = ColorContrast::contrastRatio($fg, $bg);
                    $this->assertNotNull($ratio);
                    $this->assertGreaterThanOrEqual(
                        4.5,
                        $ratio,
                        "readableTextOn({$bg}) = {$fg} erreicht nur {$ratio}:1"
                    );
                }
            }
        }
        for ($v = 0; $v <= 255; $v += 5) {
            $bg = sprintf('#%02x%02x%02x', $v, $v, $v);
            $fg = ColorContrast::readableTextOn($bg);
            $ratio = ColorContrast::contrastRatio($fg, $bg);
            $this->assertGreaterThanOrEqual(4.5, $ratio, "Grauwert {$bg}: {$fg} erreicht nur {$ratio}:1");
        }
    }

    public function testReadableTextOnPrefersWhiteOnDarkBrandColors(): void {
        // Die ausgelieferte Default-Primärfarbe und die vom Betreiber
        // gemeldeten Vereinsblau-Töne sind dunkel genug für Weiß.
        foreach (['#2c3e50', '#2a52be', '#4b6bba'] as $brand) {
            $this->assertSame('#ffffff', ColorContrast::readableTextOn($brand), $brand);
        }
        // Helle Markenfarben kippen auf Schwarz - darunter auch die
        // Default-SEKUNDÄRfarbe #18bc9c: Weiß erreicht auf ihr nur 2,41:1
        // (der dokumentierte Messwert aus #169).
        foreach (['#18bc9c', '#f1c40f', '#ffffff', '#8fd4c1'] as $brand) {
            $this->assertSame('#000000', ColorContrast::readableTextOn($brand), $brand);
        }
    }

    public function testReadableTextOnFallsBackToWhiteOnInvalidInput(): void {
        // Passend zur dunklen Default-Primärfarbe und zum bisherigen
        // Verhalten (color: white).
        $this->assertSame('#ffffff', ColorContrast::readableTextOn(''));
        $this->assertSame('#ffffff', ColorContrast::readableTextOn('not-a-color'));
    }
}
