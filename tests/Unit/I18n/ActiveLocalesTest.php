<?php
// tests/Unit/I18n/ActiveLocalesTest.php

namespace Tests\Unit\I18n;

use App\I18n\Translator;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests für Translator::activeLocales() (#198): Der Betreiber kann
 * einzelne Sprachen deaktivieren (Settings-Schlüssel `active_locales`),
 * ohne dass Sprachdateien entfernt werden - mit zwei harten Garantien:
 * die Quellsprache (de) und die konfigurierte Standardsprache sind immer
 * aktiv, und ohne Konfiguration sind ALLE verfügbaren Sprachen aktiv.
 */
class ActiveLocalesTest extends TestCase {

    public function testAllAvailableActiveWithoutConfiguration(): void {
        $this->assertSame(Translator::getAvailableLocales(), Translator::activeLocales([]));
        $this->assertSame(Translator::getAvailableLocales(), Translator::activeLocales(['active_locales' => '']));
        $this->assertSame(Translator::getAvailableLocales(), Translator::activeLocales(['active_locales' => '   ']));
    }

    public function testFiltersToConfiguredSubset(): void {
        $active = Translator::activeLocales(['active_locales' => 'en,fr', 'language' => 'de']);

        $this->assertSame(['de', 'en', 'fr'], array_keys($active));
        // Anzeigenamen bleiben die Eigennamen aus der Registrierung.
        $this->assertSame('Français', $active['fr']);
    }

    public function testFallbackAndDefaultLanguageAreAlwaysActive(): void {
        // Weder de noch die Standardsprache (sv) stehen in der Liste - beide
        // müssen trotzdem aktiv bleiben, sonst wäre die Oberfläche sprachlos.
        $active = Translator::activeLocales(['active_locales' => 'en', 'language' => 'sv']);

        $this->assertArrayHasKey('de', $active);
        $this->assertArrayHasKey('sv', $active);
        $this->assertArrayHasKey('en', $active);
        $this->assertArrayNotHasKey('fr', $active);
    }

    public function testUnknownCodesAreDropped(): void {
        $active = Translator::activeLocales(['active_locales' => 'en,xx,klingonisch, ,fr']);

        $this->assertSame(['de', 'en', 'fr'], array_keys($active));
    }
}
