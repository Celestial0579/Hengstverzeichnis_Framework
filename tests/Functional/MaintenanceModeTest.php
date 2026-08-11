<?php
// tests/Functional/MaintenanceModeTest.php

namespace Tests\Functional;

use App\Service\Maintenance;

/**
 * Wartungsmodus über den echten HTTP-Weg (#232): Der PHPUnit-Prozess setzt
 * die Marker-Datei var/wartung.lock über App\Service\Maintenance - der
 * php -S-Testserver (siehe PhpBuiltInServer) läuft im selben
 * Arbeitsverzeichnis und sieht daher denselben Marker. Geprüft wird das
 * Verhalten des frühen Bootstrap-Guards in public/index.php: HTTP 503 mit
 * Retry-After und Hinweisseite für JEDEN Request, auch für angemeldete
 * Admins (bewusste Entscheidung, siehe Maintenance::guard()).
 */
class MaintenanceModeTest extends FunctionalTestCase {

    /**
     * Marker auch nach fehlgeschlagenen Assertions zuverlässig entfernen -
     * ein liegen gebliebener Marker würde sonst ALLE nachfolgenden
     * Functional-Tests (und eine lokale Entwicklungsinstanz) mit 503 lahmlegen.
     */
    protected function tearDown(): void {
        Maintenance::disable();
        parent::tearDown();
    }

    public function testActiveMarkerYields503WithRetryAfterEverywhere(): void {
        Maintenance::enable('Functional-Test: simulierter Datenbank-Restore');

        $client = $this->newClient();

        // Öffentliche Startseite: 503 samt Retry-After und Hinweisseite in
        // der Fallback-Sprache (frische Session ohne Sprachwahl -> Deutsch).
        $response = $client->get('/');
        $this->assertSame(503, $response->statusCode);
        $retryAfter = $response->header('Retry-After');
        $this->assertNotNull($retryAfter, 'Retry-After-Header fehlt in der 503-Antwort');
        $this->assertTrue(ctype_digit($retryAfter), "Retry-After sollte Sekunden enthalten, war: {$retryAfter}");
        $this->assertGreaterThan(0, (int)$retryAfter);
        $this->assertSame('no-store', $response->header('Cache-Control'));
        $this->assertStringContainsString('503 - Wartungsmodus', $response->body);
        // Der in enable() hinterlegte Grund ist Betreiber-Diagnose und darf
        // NICHT auf der öffentlichen Hinweisseite erscheinen.
        $this->assertStringNotContainsString('simulierter Datenbank-Restore', $response->body);

        // Der Guard greift VOR dem Router - also auch auf Login- und
        // Admin-Routen, nicht nur auf der Startseite.
        $this->assertSame(503, $client->get('/login')->statusCode);
        $this->assertSame(503, $client->get('/admin')->statusCode);

        // Nach disable() antwortet die App sofort wieder normal.
        Maintenance::disable();
        $this->assertNotSame(503, $this->newClient()->get('/')->statusCode);
    }

    public function testAdminSessionIsAlsoBlocked(): void {
        // Bewusst KEINE Admin-Ausnahme (siehe Maintenance::guard()): Gerade
        // ein Admin-Schreibzugriff zwischen DROP und INSERT ist das
        // Schadensszenario, gegen das der Wartungsmodus existiert.
        $admin = $this->authenticatedClient();
        $this->assertSame(200, $admin->get('/admin')->statusCode);

        Maintenance::enable('Functional-Test: Admin-Sperre');
        $this->assertSame(503, $admin->get('/admin')->statusCode);

        Maintenance::disable();
        $this->assertSame(200, $admin->get('/admin')->statusCode);
    }

    public function testHintPageUsesLocaleFromSession(): void {
        // authenticatedClient() stellt sicher, dass die App provisioniert ist -
        // erst dann liefert `/` eine normale Seite (statt Redirect auf /setup),
        // über die sich die Sprachwahl in der Session verankern lässt.
        $this->authenticatedClient();

        $client = $this->newClient();
        $this->assertSame(200, $client->get('/?lang=en')->statusCode);

        // Die Hinweisseite kommt ohne Datenbank aus, übernimmt aber die
        // zuvor in der Session gespeicherte Sprachwahl (siehe
        // Maintenance::guard(): Translator::init() aus $_SESSION['locale']).
        Maintenance::enable('Functional-Test: Sprachwahl');
        $response = $client->get('/');
        $this->assertSame(503, $response->statusCode);
        $this->assertStringContainsString('503 - Maintenance Mode', $response->body);
    }
}
