<?php
// tests/Unit/Service/UpdateAbbruchTest.php

namespace Tests\Unit\Service;

use App\Service\UpdateJournal;
use App\Service\UpdateService;
use App\Service\UpdateSperre;
use PHPUnit\Framework\TestCase;

/**
 * Ein Update, das mitten im Kopieren endet (Audit M45) - nachgestellt in
 * einem Kindprozess (tests/Support/update-abbruch-kind.php), der über die
 * Naht UpdateService::nachJederDateiFuerTests() nach der n-ten Datei
 * abbricht.
 *
 * Vorher sprang ein Zeit- oder Speicherlimit, ein Kompilierfehler oder ein
 * getöteter Worker am catch vorbei; zurück blieb ein Mischstand aus zwei
 * Versionen. Jetzt:
 * - Fatal Error: Rückweg im Shutdown-Handler desselben Prozesses;
 * - SIGKILL: das Journal bleibt, der nächste Lauf bzw. der frühe Haken in
 *   public/index.php rollt zurück - auch wenn vendor/ halb kopiert ist.
 */
class UpdateAbbruchTest extends TestCase {

    private string $ziel;
    private string $vorher;
    private string $zip;

    /** @var array<int, resource> */
    private array $prozesse = [];

    protected function setUp(): void {
        $basis = sys_get_temp_dir() . '/' . uniqid('hengst_abbruch_');
        $this->ziel = $basis . '/ziel';
        $this->vorher = $basis . '/vorher';

        foreach ([$this->ziel, $this->vorher] as $wurzel) {
            mkdir($wurzel . '/config', 0755, true);
            mkdir($wurzel . '/src', 0755, true);
            mkdir($wurzel . '/vendor', 0755, true);
            file_put_contents($wurzel . '/index.php', 'ALT index');
            file_put_contents($wurzel . '/src/A.php', 'ALT A');
            file_put_contents($wurzel . '/vendor/autoload.php', '<?php // ALT autoload');
            file_put_contents($wurzel . '/config/config.php', "<?php define('CORE_VERSION', '1.0.0');");
        }

        // scandir-Reihenfolge: config, index.php, src, vendor. config.php
        // kommt trotzdem erst zuletzt (ZULETZT_KOPIEREN).
        $this->zip = $basis . '/release.zip';
        $zip = new \PharData($this->zip);
        foreach ([
            'p/index.php' => 'NEU index',
            'p/src/A.php' => 'NEU A',
            'p/src/B.php' => 'NEU B',
            'p/src/neu/C.php' => 'NEU C',
            'p/vendor/autoload.php' => '<?php // NEU autoload',
            'p/vendor/composer/autoload_real.php' => '<?php // NEU real',
            'p/config/config.php' => "<?php define('CORE_VERSION', '9.9.9');",
        ] as $pfad => $inhalt) {
            $zip->addFromString($pfad, $inhalt);
        }
    }

    protected function tearDown(): void {
        foreach ($this->prozesse as $prozess) {
            @proc_terminate($prozess, 9);
            @proc_close($prozess);
        }
        UpdateSperre::resetForTests();
        UpdateService::nachJederDateiFuerTests(null);
        $this->entferne(dirname($this->ziel));
    }

    public function testZeitlimitMittenInSrcWirdImSelbenProzessZurueckgerollt(): void {
        [$code, $ausgabe] = $this->kind('anwenden', $this->zip, $this->ziel, '3', 'zeitlimit');

        $this->assertNotSame(0, $code, $ausgabe);
        $this->assertBaumWieVorher();
        $this->assertDirectoryDoesNotExist(UpdateJournal::verzeichnis($this->ziel));
        $abbruch = UpdateJournal::letzterAbbruch($this->ziel);
        $this->assertNotNull($abbruch, $ausgabe);
        $this->assertStringContainsString('Maximum execution time', (string)$abbruch['ursache']);
        $this->assertSame('9.9.9', $abbruch['nach']);
    }

    public function testSpeicherlimitWirdEbensoZurueckgerollt(): void {
        [$code, $ausgabe] = $this->kind('anwenden', $this->zip, $this->ziel, '3', 'speicher');

        $this->assertNotSame(0, $code, $ausgabe);
        $this->assertBaumWieVorher();
        $this->assertDirectoryDoesNotExist(UpdateJournal::verzeichnis($this->ziel));
        $this->assertStringContainsString('Allowed memory size', (string)UpdateJournal::letzterAbbruch($this->ziel)['ursache']);
    }

    public function testSigkillHinterlaesstJournalDasDerNaechsteAufrufZurueckrollt(): void {
        $this->kindBisBereitDannKill('4');

        $this->assertTrue(UpdateJournal::offen($this->ziel), 'Nach SIGKILL bleibt das Journal liegen');
        $this->assertSame('NEU index', file_get_contents($this->ziel . '/index.php'), 'Mischstand vor dem Rückweg');

        $this->assertSame('zurueckgerollt', UpdateJournal::verwaistesZurueckrollen($this->ziel));
        $this->assertBaumWieVorher();
    }

    public function testSigkillDannErneutesEinspielenRolltErstZurueckUndSpieltDannSauberEin(): void {
        $this->kindBisBereitDannKill('4');

        $kopiert = UpdateService::applyUpdateArchive($this->zip, $this->ziel);

        $this->assertSame(7, $kopiert);
        $this->assertSame('NEU B', file_get_contents($this->ziel . '/src/B.php'));
        $this->assertStringContainsString('9.9.9', (string)file_get_contents($this->ziel . '/config/config.php'));
        $this->assertFalse(UpdateJournal::offen($this->ziel));
        $this->assertNotNull(UpdateJournal::letzterAbbruch($this->ziel), 'Der Abbruch bleibt als Hinweis stehen');
    }

    /**
     * Der Fall, für den der Rückweg klassenfrei ist: Nach dem Kill ist
     * vendor/autoload.php unbrauchbar (hier: Syntaxfehler). Ein zweiter
     * Prozess, der NUR das abgelegte Skript lädt - wie der frühe Haken in
     * public/index.php -, rollt trotzdem zurück.
     */
    public function testRueckwegOhneAutoloaderTrotzKaputtemVendor(): void {
        $this->kindBisBereitDannKill('6');
        $this->assertSame('<?php // NEU autoload', file_get_contents($this->ziel . '/vendor/autoload.php'));
        file_put_contents($this->ziel . '/vendor/autoload.php', '<?php this is not php');

        [$code, $ausgabe] = $this->kind('rueckweg', $this->ziel);

        $this->assertSame(0, $code, $ausgabe);
        $this->assertStringContainsString('ERGEBNIS=zurueckgerollt', $ausgabe);
        $this->assertBaumWieVorher();
    }

    /** Hält ein anderer Prozess die Sperre, rollt niemand dazwischen. */
    public function testZweiterRueckwegWaehrendGehaltenerSperreIstBelegt(): void {
        $this->kindBisBereitDannKill('4');

        $prozess = proc_open(
            [PHP_BINARY, __DIR__ . '/../../Support/update-abbruch-kind.php', 'sperre', $this->ziel],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->prozesse[] = $prozess;
        $this->assertSame("BEREIT\n", fgets($pipes[1]));

        $this->assertSame('belegt', UpdateJournal::verwaistesZurueckrollen($this->ziel));
        [, $ausgabe] = $this->kind('rueckweg', $this->ziel);
        $this->assertStringContainsString('ERGEBNIS=belegt', $ausgabe);
        $this->assertSame('NEU index', file_get_contents($this->ziel . '/index.php'), 'Nichts angefasst');
        $this->assertTrue(UpdateJournal::offen($this->ziel));
    }

    private function assertBaumWieVorher(): void {
        foreach (['index.php', 'src/A.php', 'vendor/autoload.php', 'config/config.php'] as $datei) {
            $this->assertFileEquals($this->vorher . '/' . $datei, $this->ziel . '/' . $datei, $datei);
        }
        $this->assertFileDoesNotExist($this->ziel . '/src/B.php');
        $this->assertDirectoryDoesNotExist($this->ziel . '/src/neu');
        $this->assertDirectoryDoesNotExist($this->ziel . '/vendor/composer');
    }

    private function kindBisBereitDannKill(string $abDatei): void {
        $prozess = proc_open(
            [PHP_BINARY, __DIR__ . '/../../Support/update-abbruch-kind.php', 'anwenden', $this->zip, $this->ziel, $abDatei, 'kill'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($prozess);
        $this->assertSame("BEREIT\n", fgets($pipes[1]));
        proc_terminate($prozess, 9);
        proc_close($prozess);
    }

    /** @return array{0: int, 1: string} */
    private function kind(string ...$argumente): array {
        $prozess = proc_open(
            array_merge([PHP_BINARY, __DIR__ . '/../../Support/update-abbruch-kind.php'], $argumente),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($prozess);
        $ausgabe = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        return [proc_close($prozess), $ausgabe];
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
}
