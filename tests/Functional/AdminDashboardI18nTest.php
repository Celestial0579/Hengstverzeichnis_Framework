<?php
// tests/Functional/AdminDashboardI18nTest.php

namespace Tests\Functional;

/**
 * HTTP-Funktionstest für den ersten i18n-Schritt im Admin-Bereich (#48):
 * Das Admin-Dashboard nutzt den Translator - per ?lang=en (Session-
 * Übersteuerung, siehe BaseController::initLocale()) erscheint es auf
 * Englisch, in der Standardsprache Deutsch unverändert wie zuvor.
 */
class AdminDashboardI18nTest extends FunctionalTestCase {

    public function testDashboardIsTranslatedViaLangSwitch(): void {
        $admin = $this->authenticatedClient();

        // Standardsprache (de): deutsche Kacheln.
        $german = $admin->get('/admin');
        $this->assertSame(200, $german->statusCode);
        $this->assertStringContainsString('Pferde verwalten', $german->body);
        $this->assertStringContainsString('Verwaltung &amp; Daten', $german->body);

        // Umschalten auf Englisch (bleibt in der Session aktiv).
        $english = $admin->get('/admin?lang=en');
        $this->assertStringContainsString('Manage Horses', $english->body);
        $this->assertStringContainsString('Management &amp; Data', $english->body);
        $this->assertStringContainsString('Sign Out', $english->body);

        // Zurück auf Deutsch, damit nachfolgende Tests unbeeinflusst bleiben.
        $backToGerman = $admin->get('/admin?lang=de');
        $this->assertStringContainsString('Pferde verwalten', $backToGerman->body);
    }

    /**
     * Englisch ist die zweite Pflichtsprache des Kerns (#344) - ?lang=en
     * übersetzt die öffentliche Seite, ohne dass ein Addon nötig wäre.
     */
    public function testDieZweiteKernspracheWirdUeberDenSchalterAusgeliefert(): void {
        $client = $this->newClient();

        $english = $client->get('/?lang=en');
        $this->assertSame(200, $english->statusCode);
        // <html lang="en"> belegt, dass die Locale wirklich aktiv ist.
        $this->assertStringContainsString('lang="en"', $english->body);

        $client->get('/?lang=de');
    }

    /**
     * Und eine Sprache OHNE Addon fällt sauber zurück, statt eine halbe
     * Übersetzung zu zeigen (#344).
     *
     * Bis v0.8 lagen alle zwölf Sprachen im Kern; seither sind zehn davon
     * Addons. `?lang=fr` darf jetzt nicht mehr greifen - und schon gar nicht
     * eine Seite liefern, auf der die Hälfte französisch und die Hälfte
     * deutsch ist.
     */
    public function testEineSpracheOhneAddonFaelltSauberZurueck(): void {
        $client = $this->newClient();

        $franzoesisch = $client->get('/?lang=fr');

        $this->assertSame(200, $franzoesisch->statusCode);
        $this->assertStringContainsString('lang="de"', $franzoesisch->body);
        $this->assertStringNotContainsString('lang="fr"', $franzoesisch->body);

        $client->get('/?lang=de');
    }

    /**
     * Der Sprachumschalter im Footer ist seit #198 ein Dropdown: beschriftet,
     * mit den VERFÜGBAREN Sprachen (Eigennamen), aktiver Locale als selected
     * und einem <noscript>-Absenden-Knopf für Besucher ohne JavaScript.
     *
     * Verfügbar heisst seit #344: Deutsch und Englisch aus dem Kern, alles
     * Weitere nur mit dem passenden Sprach-Addon. Der Umschalter darf keine
     * Sprache anbieten, die beim Klick auf Deutsch zurückfällt - deshalb
     * zählt dieser Test genau zwei.
     */
    public function testFooterLanguageSwitcherIsALabelledDropdown(): void {
        $client = $this->newClient();

        $page = $client->get('/');
        $this->assertSame(200, $page->statusCode);
        $this->assertStringContainsString('<label for="footer-lang-select">', $page->body);
        $this->assertStringContainsString('<select id="footer-lang-select" name="lang"', $page->body);
        $this->assertStringContainsString('<noscript><button type="submit"', $page->body);

        $this->assertSame(
            2,
            substr_count($page->body, '<option value="'),
            'Ohne Sprach-Addon bietet der Umschalter genau die beiden Kernsprachen an (#344)'
        );
        foreach (['Deutsch', 'English'] as $endonym) {
            $this->assertStringContainsString('>' . $endonym . '</option>', $page->body);
        }
        // Und keine Sprache, zu der es keine Datei gibt.
        foreach (['Nederlands', 'Français', 'Svenska'] as $ohneAddon) {
            $this->assertStringNotContainsString('>' . $ohneAddon . '</option>', $page->body);
        }

        // Aktive Locale (de als Default) ist vorausgewählt.
        $this->assertStringContainsString('<option value="de" selected>', $page->body);
    }

    /**
     * Deaktivierte Sprachen (#198, Settings-Schlüssel `active_locales`)
     * verschwinden aus dem Umschalter und werden bei ?lang= nicht
     * angenommen; die Quellsprache de bleibt immer aktiv.
     */
    public function testDeactivatedLocaleDisappearsFromSwitcherAndLangParameter(): void {
        $db = \App\Database::getInstance();
        $db->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('active_locales', 'en') ON DUPLICATE KEY UPDATE setting_value = 'en'");

        try {
            $client = $this->newClient();

            $page = $client->get('/');
            $this->assertSame(
                2,
                substr_count($page->body, '<option value="'),
                'Nur de (immer aktiv) und en dürfen angeboten werden'
            );
            $this->assertStringNotContainsString('>Français</option>', $page->body);

            // Eine deaktivierte Sprache per ?lang= wird verworfen - die Seite
            // bleibt in der Standardsprache.
            $french = $client->get('/?lang=fr');
            $this->assertStringContainsString('lang="de"', $french->body);
            $this->assertStringNotContainsString('lang="fr"', $french->body);
        } finally {
            $db->exec("UPDATE settings SET setting_value = '' WHERE setting_key = 'active_locales'");
        }
    }
}
