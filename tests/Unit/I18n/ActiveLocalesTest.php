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
 *
 * Dazu Translator::resolveRequestLocale() (#220): die EINE Auswahlregel für
 * BaseController UND PluginPage - ?lang= zählt nur für aktive Sprachen, eine
 * inaktiv gewordene Session-Wahl fällt auf die Standardsprache zurück und
 * wird dabei aus der Session ENTFERNT.
 */
class ActiveLocalesTest extends TestCase {

    protected function setUp(): void {
        // resolveRequestLocale() liest $_GET/$_SESSION - sauber starten,
        // damit kein anderer Test Reste hinterlässt oder vorfindet.
        unset($_GET['lang'], $_SESSION['locale']);
    }

    protected function tearDown(): void {
        unset($_GET['lang'], $_SESSION['locale']);
    }

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

    public function testResolveTakesActiveLangParameterAndPersistsIt(): void {
        $_GET['lang'] = 'fr';

        $locale = Translator::resolveRequestLocale(['active_locales' => 'en,fr', 'language' => 'de']);

        $this->assertSame('fr', $locale);
        $this->assertSame('fr', $_SESSION['locale'], 'Aktive ?lang=-Wahl muss die Navigation überdauern.');
    }

    public function testResolveIgnoresInactiveLangParameter(): void {
        // Kern von #220: pl ist VERFÜGBAR, aber vom Betreiber deaktiviert -
        // ?lang=pl darf weder wirken noch in der Session landen.
        $_GET['lang'] = 'pl';

        $locale = Translator::resolveRequestLocale(['active_locales' => 'en', 'language' => 'de']);

        $this->assertSame('de', $locale);
        $this->assertArrayNotHasKey('locale', $_SESSION ?? [], 'Eine inaktive ?lang=-Wahl darf nicht persistiert werden.');
    }

    public function testResolveFallsBackAndCleansStaleSessionLocale(): void {
        // Session-Wahl aus der Zeit VOR der Deaktivierung: Rückfall auf die
        // Standardsprache UND Bereinigung, damit der veraltete Wert nicht
        // auf jeder Folgeseite erneut geprüft werden muss.
        $_SESSION['locale'] = 'pl';

        $locale = Translator::resolveRequestLocale(['active_locales' => 'en', 'language' => 'de']);

        $this->assertSame('de', $locale);
        $this->assertArrayNotHasKey('locale', $_SESSION, 'Die inaktiv gewordene Session-Wahl muss entfernt werden.');
    }

    public function testResolveKeepsActiveSessionLocale(): void {
        $_SESSION['locale'] = 'en';

        $locale = Translator::resolveRequestLocale(['active_locales' => 'en,fr', 'language' => 'de']);

        $this->assertSame('en', $locale);
        $this->assertSame('en', $_SESSION['locale']);
    }

    public function testResolveUsesDefaultLanguageWithoutSessionOrParameter(): void {
        $locale = Translator::resolveRequestLocale(['language' => 'sv']);

        $this->assertSame('sv', $locale);
        $this->assertArrayNotHasKey('locale', $_SESSION ?? [], 'Die Standardsprache gehört nicht in die Session.');
    }
}
