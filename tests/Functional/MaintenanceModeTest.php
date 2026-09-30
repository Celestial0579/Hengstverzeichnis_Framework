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
        Maintenance::resetForTests();
        $wurzel = dirname(__DIR__, 2);
        $this->entferne($wurzel . '/var/update-journal');
        @unlink($wurzel . '/var/update-abbruch.json');
        @unlink($wurzel . '/var/abbruch-probe.txt');
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

    // ---- Inhaber-Sperre und früher Rückweg-Haken (Audit N72, M45) --------

    /**
     * Der FPM-Fall: Nach einem Fatal Error lebt der Worker mit derselben PID
     * weiter. Ein Marker im neuen Format, dessen Inhaber-Sperre niemand mehr
     * hält, ist verwaist - auch wenn die PID lebt (hier: der Testprozess)
     * und der Marker frisch ist.
     */
    public function testVerwaisterMarkerEinesLebendenWorkersWirdFreigegeben(): void {
        $this->schreibeMarkerNeuesFormat();

        $this->assertNotSame(503, $this->newClient()->get('/')->statusCode);
        $this->assertFalse(Maintenance::isActive(), 'Der Guard hat den verwaisten Marker entfernt');
    }

    public function testGehalteneInhaberSperreSperrtWeiter(): void {
        $griff = fopen(Maintenance::inhaberDatei(), 'c');
        flock($griff, LOCK_SH);
        try {
            $this->schreibeMarkerNeuesFormat();

            $this->assertSame(503, $this->newClient()->get('/')->statusCode);
            $this->assertTrue(Maintenance::isActive());
        } finally {
            flock($griff, LOCK_UN);
            fclose($griff);
        }
    }

    /**
     * Ein Update wurde beim Kopieren hart beendet: Journal, Sicherung und
     * Rückweg-Skript liegen, der Marker ohne lebenden Inhaber auch. Der frühe
     * Haken in public/index.php rollt zurück, bevor die Anwendung startet;
     * danach räumt der Guard den Marker weg.
     */
    public function testFruehHakenRolltVerwaistesJournalZurueck(): void {
        $wurzel = dirname(__DIR__, 2);
        $this->legeJournalAn('NEU (halb eingespielt)', 'ALT (vor dem Update)');
        $this->schreibeMarkerNeuesFormat();

        $response = $this->newClient()->get('/');

        $this->assertNotSame(503, $response->statusCode);
        $this->assertSame('ALT (vor dem Update)', file_get_contents($wurzel . '/var/abbruch-probe.txt'));
        $this->assertFileDoesNotExist($wurzel . '/var/update-journal/journal.jsonl');
        $this->assertDirectoryDoesNotExist($wurzel . '/var/update-journal');
        $this->assertFileExists($wurzel . '/var/update-abbruch.json');
        $this->assertFalse(Maintenance::isActive());
    }

    /** Hält ein anderer Prozess die Update-Sperre, gibt es nur 503. */
    public function testJournalBeiGehaltenerUpdateSperreLiefert503(): void {
        $wurzel = dirname(__DIR__, 2);
        $this->legeJournalAn('NEU (halb eingespielt)', 'ALT (vor dem Update)');
        $vorher = file_get_contents($wurzel . '/var/update-journal/journal.jsonl');

        $griff = fopen($wurzel . '/var/update.lock', 'c+');
        flock($griff, LOCK_EX);
        try {
            $response = $this->newClient()->get('/');
        } finally {
            flock($griff, LOCK_UN);
            fclose($griff);
        }

        $this->assertSame(503, $response->statusCode);
        $this->assertNotNull($response->header('Retry-After'));
        $this->assertSame($vorher, file_get_contents($wurzel . '/var/update-journal/journal.jsonl'));
        $this->assertSame('NEU (halb eingespielt)', file_get_contents($wurzel . '/var/abbruch-probe.txt'));
    }

    private function schreibeMarkerNeuesFormat(): void {
        file_put_contents(Maintenance::lockFile(), json_encode([
            'grund' => 'Functional-Test: Fatal im Worker',
            'seit' => date('c'),
            'pid' => getmypid(),
            'token' => str_repeat('f', 32),
            'lebenszeichen' => 'flock',
        ]));
    }

    private function legeJournalAn(string $jetzt, string $gesichert): void {
        $wurzel = dirname(__DIR__, 2);
        $verzeichnis = $wurzel . '/var/update-journal';
        mkdir($verzeichnis . '/sicherungen', 0700, true);
        copy($wurzel . '/src/Service/update-rueckweg.php', $verzeichnis . '/rueckweg.php');
        file_put_contents($verzeichnis . '/sicherungen/probe', $gesichert);
        file_put_contents($wurzel . '/var/abbruch-probe.txt', $jetzt);
        file_put_contents(
            $verzeichnis . '/journal.jsonl',
            json_encode(['art' => 'kopf', 'format' => 1, 'zweck' => 'Kern-Update', 'von' => '1.0.0', 'nach' => '9.9.9', 'pid' => 0, 'seit' => date('c')]) . "\n"
            . json_encode(['art' => 'restore', 'sicherung' => 'probe', 'original' => 'var/abbruch-probe.txt']) . "\n"
        );
    }

    private function entferne(string $pfad): void {
        if (is_file($pfad) || is_link($pfad)) {
            @unlink($pfad);
            return;
        }
        if (!is_dir($pfad)) {
            return;
        }
        foreach (array_diff(scandir($pfad) ?: [], ['.', '..']) as $eintrag) {
            $this->entferne($pfad . '/' . $eintrag);
        }
        @rmdir($pfad);
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
