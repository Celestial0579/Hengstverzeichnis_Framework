<?php
// tests/Unit/Helper/CountryFlagTest.php

namespace Tests\Unit\Helper;

use App\Helper\CountryFlag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * App\Helper\CountryFlag (#240): tolerantes Mapping des persons.country-
 * Freitexts (deutsche/englische Ländernamen, direkte ISO-alpha-2-Codes)
 * auf ein Flaggen-Emoji aus zwei Regional-Indicator-Codepoints.
 * Unbekanntes oder Leeres muss null ergeben (fail-quiet, keine Flagge) -
 * insbesondere darf ein beliebiges Buchstabenpaar ("XX") nie als sichtbare
 * Regional-Indicator-Zeichen durchrutschen.
 */
class CountryFlagTest extends TestCase {

    /** @return array<string, array{0: string, 1: string}> */
    public static function knownCountryProvider(): array {
        return [
            // Deutsche Ländernamen, inkl. Umlauten und ae/oe-Umschreibung
            'Dänemark (deutsch, Umlaut)' => ['Dänemark', '🇩🇰'],
            'Daenemark (Umlaut umschrieben)' => ['Daenemark', '🇩🇰'],
            'Deutschland' => ['Deutschland', '🇩🇪'],
            'Österreich' => ['Österreich', '🇦🇹'],
            'Frankreich' => ['Frankreich', '🇫🇷'],
            'Großbritannien' => ['Großbritannien', '🇬🇧'],
            'Türkei' => ['Türkei', '🇹🇷'],

            // Englische Ländernamen
            'Denmark (englisch)' => ['Denmark', '🇩🇰'],
            'Germany' => ['Germany', '🇩🇪'],
            'Netherlands' => ['Netherlands', '🇳🇱'],
            'United States (mehrteilig)' => ['United States', '🇺🇸'],
            'New Zealand (mehrteilig)' => ['New Zealand', '🇳🇿'],

            // Direkte ISO-alpha-2-Codes, groß wie klein
            'ISO-Code DK' => ['DK', '🇩🇰'],
            'ISO-Code no (kleingeschrieben)' => ['no', '🇳🇴'],
            'ISO-Code CH' => ['CH', '🇨🇭'],

            // Groß-/Kleinschreibung und Randbereiche des Freitexts
            'DÄNEMARK (Versalien mit Umlaut)' => ['DÄNEMARK', '🇩🇰'],
            'dänemark (kleingeschrieben)' => ['dänemark', '🇩🇰'],
            'Whitespace außen' => ['  Dänemark  ', '🇩🇰'],
        ];
    }

    #[DataProvider('knownCountryProvider')]
    public function testKnownCountriesMapToFlagEmoji(string $input, string $expectedFlag): void {
        $this->assertSame($expectedFlag, CountryFlag::emoji($input));
    }

    /** @return array<string, array{0: string|null}> */
    public static function unknownCountryProvider(): array {
        return [
            'null' => [null],
            'Leerstring' => [''],
            'nur Whitespace' => ['   '],
            'unbekannter Name' => ['Atlantis'],
            'Tippfehler' => ['Dänemakr'],
            'unbekannter Zwei-Buchstaben-Code' => ['XX'],
            'dreistelliger ISO-Code (nicht unterstützt)' => ['DEU'],
            'Zahlen' => ['42'],
        ];
    }

    #[DataProvider('unknownCountryProvider')]
    public function testUnknownOrEmptyYieldsNull(?string $input): void {
        $this->assertNull(CountryFlag::emoji($input), 'Unbekanntes/leeres Land muss null ergeben (keine Flagge statt Platzhalter)');
    }

    /**
     * Das Emoji besteht wirklich aus den zwei Regional-Indicator-Codepoints
     * des ISO-Codes (🇩🇰 = U+1F1E9 U+1F1F0) - nicht aus den ASCII-Buchstaben.
     */
    public function testEmojiIsBuiltFromRegionalIndicatorCodepoints(): void {
        $flag = CountryFlag::emoji('Dänemark');
        $this->assertNotNull($flag);
        $this->assertSame(2, mb_strlen($flag, 'UTF-8'));
        $this->assertSame(0x1F1E9, mb_ord(mb_substr($flag, 0, 1, 'UTF-8'), 'UTF-8')); // 🇩 (D)
        $this->assertSame(0x1F1F0, mb_ord(mb_substr($flag, 1, 1, 'UTF-8'), 'UTF-8')); // 🇰 (K)
    }
}
