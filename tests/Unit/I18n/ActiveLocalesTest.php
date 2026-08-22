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
 *
 * DIE DRITTE SPRACHE KOMMT SEIT #344 AUS EINEM ADDON. Der Kern bringt nur noch
 * Deutsch und Englisch mit; `fr` wird hier über denselben Erweiterungspunkt
 * angemeldet, den ein Sprach-Addon benutzt. Damit prüfen diese Tests
 * zusätzlich, dass eine Addon-Sprache in `activeLocales()` und
 * `resolveRequestLocale()` wirklich ankommt - vorher wäre das ein blinder
 * Fleck gewesen: Die Auslagerung könnte den Umschalter stillgelegt haben, und
 * die Suite bliebe grün.
 */
class ActiveLocalesTest extends TestCase {

    private string $sprachVerzeichnis = '';

    protected function setUp(): void {
        // resolveRequestLocale() liest $_GET/$_SESSION - sauber starten,
        // damit kein anderer Test Reste hinterlässt oder vorfindet.
        unset($_GET['lang'], $_SESSION['locale']);

        $this->sprachVerzeichnis = sys_get_temp_dir() . '/hv_locale_' . bin2hex(random_bytes(5));
        mkdir($this->sprachVerzeichnis, 0777, true);
        file_put_contents(
            $this->sprachVerzeichnis . '/fr.php',
            "<?php\nreturn ['nav.home' => 'Accueil'];\n"
        );
        Translator::registerCoreLocale('fr', $this->sprachVerzeichnis);
    }

    protected function tearDown(): void {
        unset($_GET['lang'], $_SESSION['locale']);
        Translator::resetForTests();

        if ($this->sprachVerzeichnis !== '' && is_dir($this->sprachVerzeichnis)) {
            foreach (glob($this->sprachVerzeichnis . '/*') ?: [] as $datei) {
                @unlink($datei);
            }
            @rmdir($this->sprachVerzeichnis);
        }
    }

    /**
     * Der Erweiterungspunkt selbst: Ohne Addon gibt es `fr` nicht, mit Addon
     * schon - und die Texte kommen dann auch wirklich aus dessen Datei.
     */
    public function testEineAddonSpracheWirdVerfuegbarUndLiefertTexte(): void {
        Translator::resetForTests();
        $this->assertArrayNotHasKey('fr', Translator::getAvailableLocales(), 'Ohne Addon keine dritte Sprache.');

        Translator::registerCoreLocale('fr', $this->sprachVerzeichnis);
        $this->assertArrayHasKey('fr', Translator::getAvailableLocales());
        $this->assertSame('Français', Translator::getAvailableLocales()['fr'], 'Den Namen liefert der Kern.');

        Translator::init('fr');
        $this->assertSame('Accueil', Translator::t('nav.home'));
    }

    /**
     * Ein Addon darf eine KERN-Sprache nicht überschreiben.
     *
     * Sonst brächte ein Sprach-Addon `de.php` mit und ersetzte damit die
     * Quellsprache - und der Rückfall, auf dem die ganze Kette ruht, käme aus
     * einem Addon. Was im Kern liegt, gilt.
     */
    public function testEinAddonUeberschreibtKeineKernsprache(): void {
        $eigen = sys_get_temp_dir() . '/hv_locale_kern_' . bin2hex(random_bytes(5));
        mkdir($eigen, 0777, true);
        file_put_contents($eigen . '/de.php', "<?php\nreturn ['nav.home' => 'ENTFUEHRT'];\n");

        try {
            Translator::registerCoreLocale('de', $eigen);
            Translator::init('de');

            $this->assertNotSame('ENTFUEHRT', Translator::t('nav.home'));
            $this->assertSame(
                (require Translator::coreLangDir() . '/de.php')['nav.home'],
                Translator::t('nav.home')
            );
        } finally {
            @unlink($eigen . '/de.php');
            @rmdir($eigen);
        }
    }

    /** Deutsch und Englisch bleiben im Kern - ohne jedes Addon. */
    public function testKernSprachenBleibenOhneAddonVerfuegbar(): void {
        Translator::resetForTests();

        $this->assertSame(['de', 'en'], array_keys(Translator::getAvailableLocales()));
    }

    /**
     * Ein fehlender Schlüssel in einer Addon-Sprache fällt auf Deutsch
     * zurück - sichtbar bleibt Text, nie ein leerer Platz.
     */
    public function testFehlenderSchluesselFaelltAufDeutschZurueck(): void {
        Translator::init('fr');

        $this->assertSame(
            Translator::t('nav.catalog', [], 'core'),
            (require Translator::coreLangDir() . '/de.php')['nav.catalog']
        );
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
        // Weder de noch die Standardsprache (fr) stehen in der Liste - beide
        // müssen trotzdem aktiv bleiben, sonst wäre die Oberfläche sprachlos.
        $active = Translator::activeLocales(['active_locales' => 'en', 'language' => 'fr']);

        $this->assertArrayHasKey('de', $active);
        $this->assertArrayHasKey('fr', $active);
        $this->assertArrayHasKey('en', $active);
    }

    /**
     * Eine eingestellte Sprache OHNE Datei wird nicht aktiv - sie böte sonst
     * eine Auswahl an, die überall auf Deutsch zurückfällt.
     *
     * Der Fall entsteht mit #344 real: Wer auf Schwedisch lief und den Kern
     * hebt, ohne `sprache-sv` zu installieren, steht genau hier.
     */
    public function testEineSpracheOhneDateiWirdNichtAktiv(): void {
        $active = Translator::activeLocales(['active_locales' => 'en,sv', 'language' => 'sv']);

        $this->assertArrayNotHasKey('sv', $active);
        $this->assertArrayHasKey('de', $active, 'Deutsch bleibt - die Oberflaeche darf nie sprachlos sein.');
    }

    /**
     * Und sie wird GEMELDET, nicht verschwiegen: #344 haelt ausdruecklich
     * fest, dass bestehende Installationen nicht stumm auf Deutsch fallen
     * duerfen.
     */
    public function testEineFehlendeSpracheWirdBenanntUndNichtVerschwiegen(): void {
        $fehlend = Translator::fehlendeSprachen(['active_locales' => 'en,sv,nl', 'language' => 'sv']);

        $this->assertSame(['sv' => 'Svenska', 'nl' => 'Nederlands'], $fehlend);
    }

    /** Ein Tippfehler ist kein fehlendes Sprach-Addon. */
    public function testUnbekannteCodesStehenNichtInDerWarnung(): void {
        $this->assertSame([], Translator::fehlendeSprachen(['active_locales' => 'klingonisch,xx']));
    }

    /** Was da ist, fehlt nicht. */
    public function testVorhandeneSprachenStehenNichtInDerWarnung(): void {
        $this->assertSame([], Translator::fehlendeSprachen(['active_locales' => 'de,en,fr', 'language' => 'fr']));
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
