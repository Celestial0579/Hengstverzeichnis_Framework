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
}
