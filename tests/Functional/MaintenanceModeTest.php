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

    /**
     * Der verwaiste Marker über den echten HTTP-Weg: Nach einem harten
     * Abbruch (E_COMPILE_ERROR, FPM-Timeout, getöteter Worker) läuft kein
     * finally mehr und der Marker bleibt liegen. Ohne Verfallsregel antwortet
     * die Installation ab da dauerhaft mit 503 - auch für Admins, und seit
     * das Update unbeaufsichtigt per Cron läuft, sitzt niemand davor.
     *
     * Der Guard muss so einen Marker erkennen, wegräumen und normal
     * ausliefern. Nachgestellt wird das über einen Marker mit einer
     * Prozesskennung, die es auf diesem System nicht gibt.
     */
    public function testOrphanedMarkerOfADeadProcessIsClearedOnRequest(): void {
        file_put_contents(Maintenance::lockFile(), json_encode([
            'grund' => 'Functional-Test: abgestürzter Lauf',
            'seit' => date('c', strtotime('-20 minutes')),
            'pid' => $this->deadPid(),
        ]));
        $this->assertTrue(Maintenance::isActive(), 'Der Marker liegt vor dem Request noch da');

        $response = $this->newClient()->get('/');

        $this->assertNotSame(
            503,
            $response->statusCode,
            'Ein Marker ohne lebenden Prozess darf die Installation nicht dauerhaft sperren'
        );
        $this->assertFalse(
            Maintenance::isActive(),
            'Der Guard muss den verwaisten Marker entfernt haben, nicht nur übergangen'
        );
    }

    /**
     * Die Gegenprobe, und die wichtigere Hälfte: Ein Marker, dessen Prozess
     * noch läuft, bleibt bestehen - egal wie alt er ist. Andernfalls risse die
     * Verfallsregel eine laufende Arbeit auf, also genau der Schaden, gegen den
     * es den Wartungsmodus überhaupt gibt. Der PHPUnit-Prozess selbst dient
     * hier als der zweifelsfrei lebende Halter.
     */
    public function testMarkerOfALivingProcessKeepsBlockingHoweverOld(): void {
        file_put_contents(Maintenance::lockFile(), json_encode([
            'grund' => 'Functional-Test: sehr langer, aber laufender Import',
            'seit' => date('c', strtotime('-3 days')),
            'pid' => getmypid(),
        ]));

        $this->assertSame(503, $this->newClient()->get('/')->statusCode);
        $this->assertTrue(Maintenance::isActive(), 'Der Marker eines lebenden Prozesses bleibt liegen');
    }

    /**
     * Ein von Hand gesetzter Marker (`touch var/wartung.lock`) ist laut
     * Maintenance-Klassendoku ein gültiger Weg, geplante Wartung anzukündigen.
     * Er trägt keine Prozesskennung und darf deshalb nie von selbst verfallen.
     */
    public function testManuallyTouchedMarkerKeepsBlocking(): void {
        file_put_contents(Maintenance::lockFile(), '');

        $this->assertSame(503, $this->newClient()->get('/')->statusCode);
        $this->assertTrue(Maintenance::isActive());
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

    /**
     * Eine Prozesskennung, die es auf diesem System nachweislich nicht gibt.
     * Von der Obergrenze abwärts gesucht: Dort vergibt der Kernel zuletzt.
     */
    private function deadPid(): int {
        $max = 4194304;
        $limit = @file_get_contents('/proc/sys/kernel/pid_max');
        if ($limit !== false && (int)$limit > 0) {
            $max = (int)$limit;
        }
        for ($pid = $max - 1; $pid > $max - 200; $pid--) {
            if (!is_dir('/proc/' . $pid)) {
                return $pid;
            }
        }
        $this->markTestSkipped('Keine freie Prozesskennung gefunden - System ungewoehnlich ausgelastet.');
    }
}
