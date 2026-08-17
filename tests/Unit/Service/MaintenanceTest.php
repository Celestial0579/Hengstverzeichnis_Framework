<?php
// tests/Unit/Service/MaintenanceTest.php

namespace Tests\Unit\Service;

use App\Service\Maintenance;
use PHPUnit\Framework\TestCase;

/**
 * Wartungsmodus-API (#232, siehe App\Service\Maintenance): Marker-Datei
 * anlegen/prüfen/entfernen - bewusst ohne jede Datenbank, denn genau das ist
 * die Zusicherung der Klasse (der Check muss auch bei halb eingespielter
 * Datenbank funktionieren). Der HTTP-Weg (503 + Retry-After über den frühen
 * Bootstrap-Guard) wird separat in tests/Functional/MaintenanceModeTest
 * über eine echte php -S-Instanz abgesichert.
 *
 * Die Tests arbeiten mit der ECHTEN Marker-Datei des Arbeitsverzeichnisses
 * (var/wartung.lock) - tearDown() räumt sie deshalb auch im Fehlerfall
 * zuverlässig ab, damit kein fehlgeschlagener Testlauf die lokale
 * Entwicklungsinstanz dauerhaft in den Wartungsmodus versetzt.
 */
class MaintenanceTest extends TestCase {

    protected function setUp(): void {
        Maintenance::disable();
    }

    protected function tearDown(): void {
        Maintenance::disable();
    }

    public function testInactiveWithoutMarkerFile(): void {
        $this->assertFalse(Maintenance::isActive());
        $this->assertNull(Maintenance::info());
    }

    public function testEnableCreatesMarkerAndActivates(): void {
        Maintenance::enable('Unit-Test: Datenbank-Restore');

        $this->assertTrue(Maintenance::isActive());
        $this->assertFileExists(Maintenance::lockFile());

        $info = Maintenance::info();
        $this->assertNotNull($info);
        $this->assertSame('Unit-Test: Datenbank-Restore', $info['grund']);
        // 'seit' ist ein ISO-8601-Zeitstempel (date('c')) - parsebar und aktuell.
        $seit = strtotime($info['seit']);
        $this->assertNotFalse($seit);
        $this->assertEqualsWithDelta(time(), $seit, 60);
    }

    public function testDisableRemovesMarker(): void {
        Maintenance::enable('Unit-Test: wird gleich wieder beendet');
        $this->assertTrue(Maintenance::isActive());

        Maintenance::disable();

        $this->assertFalse(Maintenance::isActive());
        $this->assertFileDoesNotExist(Maintenance::lockFile());
    }

    public function testDisableWithoutActiveMarkerIsHarmless(): void {
        // disable() steht in Werkzeugen typischerweise im finally-Block und
        // muss auch laufen dürfen, wenn enable() nie erreicht wurde.
        Maintenance::disable();
        Maintenance::disable();
        $this->assertFalse(Maintenance::isActive());
    }

    public function testRepeatedEnableOverwritesReason(): void {
        Maintenance::enable('Erster Grund');
        Maintenance::enable('Zweiter Grund');

        $info = Maintenance::info();
        $this->assertNotNull($info);
        $this->assertSame('Zweiter Grund', $info['grund']);
    }

    public function testManuallyTouchedEmptyMarkerStillActivates(): void {
        // Ein Betreiber darf den Wartungsmodus auch per `touch var/wartung.lock`
        // setzen - isActive() prüft nur die Existenz, info() liefert dann
        // mangels Inhalt null statt zu scheitern.
        file_put_contents(Maintenance::lockFile(), '');

        $this->assertTrue(Maintenance::isActive());
        $this->assertNull(Maintenance::info());
    }

    /**
     * Der verwaiste Marker: Nach einem harten Abbruch (E_COMPILE_ERROR,
     * FPM-Timeout, getöteter Worker) läuft kein finally mehr, der Marker
     * bleibt liegen - und ohne Verfallsregel antwortet die Installation ab da
     * dauerhaft mit 503, auch für Admins. Seit das Update unbeaufsichtigt
     * laufen kann, sitzt niemand davor, der das bemerkt.
     */
    public function testMarkerOfADeadProcessBecomesStaleAfterTheGracePeriod(): void {
        $this->writeMarker('Abgestürzter Lauf', '-20 minutes', $this->deadPid());

        $this->assertTrue(Maintenance::isActive(), 'Der Marker liegt ja noch da');
        $this->assertTrue(Maintenance::isStale(), 'Prozess tot und Marker alt - das ist ein verwaister Marker');
    }

    /**
     * Die wichtigere Hälfte: Eine LAUFENDE Arbeit darf ihre Sperre niemals
     * verlieren - das wäre genau der Schaden, gegen den es den Wartungsmodus
     * gibt. Alter allein genügt daher nicht.
     */
    public function testMarkerOfALivingProcessNeverBecomesStaleHoweverOld(): void {
        // Die eigene Kennung: dieser Prozess läuft zweifelsfrei.
        $this->writeMarker('Sehr langer, aber laufender Import', '-3 days', getmypid());

        $this->assertFalse(
            Maintenance::isStale(),
            'Solange der setzende Prozess lebt, bleibt gesperrt - egal wie alt der Marker ist'
        );
    }

    /** Toter Prozess, aber frischer Marker: Die Schonfrist läuft noch. */
    public function testRecentMarkerOfADeadProcessIsKept(): void {
        $this->writeMarker('Gerade eben abgestürzt', '-1 minute', $this->deadPid());

        $this->assertFalse(Maintenance::isStale());
    }

    /**
     * Ein von Hand gesetzter Marker (`touch var/wartung.lock`) ist laut
     * Klassendoku ein gültiger Weg, geplante Wartung anzukündigen. Er trägt
     * keine Prozesskennung - und darf deshalb nie von selbst verfallen,
     * sonst endete die geplante Wartung nach einer Viertelstunde von allein.
     */
    public function testManuallyTouchedMarkerNeverBecomesStale(): void {
        file_put_contents(Maintenance::lockFile(), '');
        $this->assertFalse(Maintenance::isStale());

        // Auch die Altfassung ohne 'pid' (Marker aus einer früheren Version)
        // bleibt bestehen - im Zweifel gesperrt.
        $this->writeMarker('Marker aus einer alten Fassung', '-20 minutes', null);
        $this->assertFalse(Maintenance::isStale());
    }

    /** enable() hinterlegt die eigene Prozesskennung - Grundlage von isStale(). */
    public function testEnableRecordsTheOwnProcessId(): void {
        Maintenance::enable('Unit-Test: Kennung');

        $info = Maintenance::info();
        $this->assertNotNull($info);
        $this->assertSame(getmypid(), $info['pid']);
    }

    // Dass guard() einen verwaisten Marker tatsächlich wegräumt und wieder
    // ausliefert, lässt sich hier nicht zeigen: Unter CLI ist guard() ein
    // No-Op (siehe testGuardIsNoOpUnderCli). Der Nachweis steht deshalb in
    // tests/Functional/MaintenanceModeTest.php, wo ein echter HTTP-Request
    // läuft.

    public function testGuardIsNoOpUnderCli(): void {
        // Unter CLI (PHPUnit läuft als CLI-Prozess) darf der Guard weder den
        // Prozess beenden noch Ausgaben erzeugen - die Werkzeuge, die den
        // Wartungsmodus setzen, müssen ja weiterarbeiten können.
        Maintenance::enable('Unit-Test: Guard unter CLI');

        $this->expectOutputString('');
        Maintenance::guard();

        // Wären wir hier nicht angekommen, hätte guard() den Prozess beendet.
        $this->assertTrue(Maintenance::isActive());
    }

    // ---- Helfer --------------------------------------------------------

    /**
     * Schreibt einen Marker mit frei wählbarem Alter und frei wählbarer
     * Prozesskennung - die drei Größen, aus denen isStale() sein Urteil bildet.
     * Absichtlich von Hand statt über enable(), das immer "jetzt" und die
     * eigene Kennung setzt.
     */
    private function writeMarker(string $grund, string $alter, ?int $pid): void {
        $payload = ['grund' => $grund, 'seit' => date('c', strtotime($alter))];
        if ($pid !== null) {
            $payload['pid'] = $pid;
        }
        file_put_contents(Maintenance::lockFile(), json_encode($payload));
    }

    /**
     * Eine Prozesskennung, die es auf diesem System nachweislich nicht gibt.
     * Von der Obergrenze abwärts gesucht: Dort vergibt der Kernel zuletzt,
     * die Zahlen sind also mit Abstand am seltensten belegt.
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
        $this->markTestSkipped('Keine freie Prozesskennung gefunden - System ungewöhnlich ausgelastet.');
    }
}
