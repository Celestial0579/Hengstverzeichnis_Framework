<?php
// tests/Unit/Service/IntegritaetTest.php

namespace Tests\Unit\Service;

use App\Service\Integritaet;
use App\Service\UpdateService;
use PHPUnit\Framework\TestCase;

/**
 * Tests für App\Service\Integritaet (#403).
 *
 * Geprüft wird hier ausschließlich die netzfreie Hälfte: der Vergleich gegen
 * die MITGELIEFERTE Liste. Alles, was GitHub braucht - die veröffentlichte
 * Liste und die Reparatur -, hat keinen Platz in der Unit-Suite; ein Test,
 * der stillschweigend ins Netz greift, ist auf einem Rechner ohne Netz kein
 * Fehlschlag, sondern eine Falschaussage.
 *
 * Der wichtigste Test ist trotzdem einer über die AUSSAGEKRAFT: Das Ergebnis
 * muss immer nennen, gegen welche Liste geprüft wurde. Eine Prüfung, die das
 * verschweigt, liest sich grün und ist es nur unter einer Annahme, die
 * niemand ausspricht.
 */
class IntegritaetTest extends TestCase {

    /** @var array<int, string> */
    private array $aufraeumen = [];

    protected function tearDown(): void {
        UpdateService::overrideBaseDirForTests(null);
        foreach ($this->aufraeumen as $dir) {
            $this->removeTree($dir);
        }
        $this->aufraeumen = [];
    }

    // ---- Der heile Fall --------------------------------------------------

    public function testHeilerBaumWirdAlsHeilGemeldet(): void {
        $this->installation([
            'src/App.php'  => "<?php\n// A\n",
            'lang/de.php'  => "<?php\nreturn [];\n",
        ]);

        $ergebnis = Integritaet::pruefe();

        $this->assertTrue($ergebnis['heil']);
        $this->assertSame(2, $ergebnis['geprueft']);
        $this->assertSame([], $ergebnis['geaendert']);
        $this->assertSame([], $ergebnis['fehlt']);
        $this->assertSame([], $ergebnis['zusaetzlich']);
    }

    // ---- Die drei Arten von Abweichung -----------------------------------

    public function testGeaenderteDateiWirdGefunden(): void {
        $wurzel = $this->installation([
            'src/App.php' => "<?php\n// A\n",
            'lang/de.php' => "<?php\nreturn [];\n",
        ]);
        file_put_contents($wurzel . '/src/App.php', "<?php\n// jemand war hier\n");

        $ergebnis = Integritaet::pruefe();

        $this->assertFalse($ergebnis['heil']);
        $this->assertSame(['src/App.php'], $ergebnis['geaendert']);
    }

    public function testFehlendeDateiWirdGefunden(): void {
        $wurzel = $this->installation([
            'src/App.php' => "<?php\n// A\n",
            'lang/de.php' => "<?php\nreturn [];\n",
        ]);
        unlink($wurzel . '/lang/de.php');

        $ergebnis = Integritaet::pruefe();

        $this->assertFalse($ergebnis['heil']);
        $this->assertSame(['lang/de.php'], $ergebnis['fehlt']);
    }

    /**
     * Eine untergeschobene PHP-Datei in `src/` steht in keiner Liste - und
     * sieht genau so aus wie eine Datei, die der Betreiber selbst dort
     * abgelegt hat. Sie wird deshalb GEMELDET, aber nicht als Schaden
     * gewertet und schon gar nicht entfernt.
     */
    public function testZusaetzlicheDateiWirdGemeldetAberNichtAlsSchadenGewertet(): void {
        $wurzel = $this->installation([
            'src/App.php' => "<?php\n// A\n",
        ]);
        file_put_contents($wurzel . '/src/Untergeschoben.php', "<?php\n// nicht von uns\n");

        $ergebnis = Integritaet::pruefe();

        $this->assertSame(['src/Untergeschoben.php'], $ergebnis['zusaetzlich']);
        $this->assertTrue(
            $ergebnis['heil'],
            'Zusätzliche Dateien machen den Baum nicht kaputt - sie sind eine Meldung, kein Befund.'
        );
        $this->assertFileExists($wurzel . '/src/Untergeschoben.php', 'Die Prüfung entfernt nie etwas.');
    }

    /** Betreiberdaten in einem KERN-Verzeichnis tauchen nicht als "zusätzlich" auf. */
    public function testBetreiberdatenTauchenNichtAlsZusaetzlichAuf(): void {
        $wurzel = $this->installation([
            'public/index.php' => "<?php\n",
        ]);
        mkdir($wurzel . '/public/uploads', 0755, true);
        file_put_contents($wurzel . '/public/uploads/bild.jpg', 'JPEG');

        $ergebnis = Integritaet::pruefe();

        $this->assertSame([], $ergebnis['zusaetzlich']);
    }

    // ---- Aussagekraft ----------------------------------------------------

    /**
     * Das Ergebnis muss immer sagen, woran gemessen wurde - und bei der
     * mitgelieferten Liste dazu, was diese Messung NICHT findet.
     */
    public function testDieQuelleDesSollwertsStehtImErgebnis(): void {
        $this->installation(['src/App.php' => "<?php\n"]);

        $ergebnis = Integritaet::pruefe();

        $this->assertSame(Integritaet::QUELLE_MITGELIEFERT, $ergebnis['quelle']);
        $this->assertNotNull($ergebnis['hinweis']);
        $this->assertStringContainsString(
            'selben Dateibaum',
            $ergebnis['hinweis'],
            'Der Hinweis muss die Einschränkung benennen: Wer Datei und Liste ändern kann, bleibt unentdeckt.'
        );
    }

    /**
     * Ohne Liste gibt es kein Ergebnis - und schon gar kein gutes. "Konnte
     * nicht prüfen" und "geprüft, ist heil" sind verschiedene Aussagen.
     */
    public function testOhneListeIstDasErgebnisNichtHeilSondernUngeprueft(): void {
        $wurzel = $this->tempVerzeichnis();
        mkdir($wurzel . '/src', 0755, true);
        file_put_contents($wurzel . '/src/App.php', "<?php\n");
        UpdateService::overrideBaseDirForTests($wurzel);

        $ergebnis = Integritaet::pruefe();

        $this->assertSame(Integritaet::QUELLE_FEHLT, $ergebnis['quelle']);
        $this->assertFalse($ergebnis['heil'], 'Ohne Liste darf nichts als heil gemeldet werden.');
        $this->assertSame(0, $ergebnis['geprueft']);
        $this->assertNotNull($ergebnis['hinweis']);
    }

    // ---- Format der Liste ------------------------------------------------

    /** Kommentare und Leerzeilen werden überlesen, kaputte Zeilen ignoriert. */
    public function testListenformat(): void {
        $wurzel = $this->tempVerzeichnis();
        mkdir($wurzel . '/src', 0755, true);
        file_put_contents($wurzel . '/src/App.php', "<?php\n");
        file_put_contents(
            $wurzel . '/' . Integritaet::MANIFEST,
            "# Kopfzeile\n\n"
            . hash('sha256', "<?php\n") . "  src/App.php\n"
            . "voellig kaputte zeile\n"
            . "deadbeef  zu kurzer hash\n"
        );
        UpdateService::overrideBaseDirForTests($wurzel);

        $ergebnis = Integritaet::pruefe();

        $this->assertSame(1, $ergebnis['geprueft'], 'Nur die eine gültige Zeile zählt.');
        $this->assertTrue($ergebnis['heil']);
    }

    // ---- Hilfen ----------------------------------------------------------

    /**
     * Legt eine Installation mit passender mitgelieferter Liste an.
     *
     * @param array<string, string> $dateien
     */
    private function installation(array $dateien): string {
        $wurzel = $this->tempVerzeichnis();
        $zeilen = ['# Testliste'];

        foreach ($dateien as $pfad => $inhalt) {
            $voll = $wurzel . '/' . $pfad;
            if (!is_dir(dirname($voll))) {
                mkdir(dirname($voll), 0755, true);
            }
            file_put_contents($voll, $inhalt);
            $zeilen[] = hash('sha256', $inhalt) . '  ' . $pfad;
        }

        file_put_contents($wurzel . '/' . Integritaet::MANIFEST, implode("\n", $zeilen) . "\n");
        UpdateService::overrideBaseDirForTests($wurzel);

        return $wurzel;
    }

    private function tempVerzeichnis(): string {
        $dir = rtrim(sys_get_temp_dir(), '/') . '/hv_integritaet_' . bin2hex(random_bytes(6));
        mkdir($dir, 0755, true);
        $this->aufraeumen[] = $dir;
        return $dir;
    }

    private function removeTree(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $eintrag) {
            $eintrag->isDir() ? @rmdir($eintrag->getPathname()) : @unlink($eintrag->getPathname());
        }
        @rmdir($dir);
    }
}
