<?php
// tests/Unit/Service/UpdateJournalTest.php

namespace Tests\Unit\Service;

use App\Service\UpdateJournal;
use App\Service\UpdateSperre;
use PHPUnit\Framework\TestCase;

/**
 * Das persistente Rückweg-Journal (Audit M45) und der klassenfreie Rückweg
 * (src/Service/update-rueckweg.php), ohne echten Abbruch - die Kindprozess-
 * Fälle stehen in UpdateAbbruchTest.
 */
class UpdateJournalTest extends TestCase {

    private string $wurzel;

    /** @var array<int, resource> */
    private array $prozesse = [];

    protected function setUp(): void {
        $this->wurzel = sys_get_temp_dir() . '/' . uniqid('hengst_journal_');
        mkdir($this->wurzel, 0755, true);
    }

    protected function tearDown(): void {
        foreach ($this->prozesse as $prozess) {
            @proc_terminate($prozess, 9);
            @proc_close($prozess);
        }
        UpdateSperre::resetForTests();
        $this->entferne($this->wurzel);
        @unlink(dirname($this->wurzel) . '/' . basename($this->wurzel) . '-aussen.txt');
    }

    public function testOeffnenNurUnterDerSperre(): void {
        $this->expectException(\LogicException::class);
        UpdateJournal::oeffnen($this->wurzel, ['zweck' => 'Test']);
    }

    public function testSicherungenSind0700UndRueckwegLiegtVorDemErstenEintrag(): void {
        $journal = $this->oeffne();
        try {
            $verzeichnis = UpdateJournal::verzeichnis($this->wurzel);
            $this->assertSame(0700, fileperms($verzeichnis) & 0777);
            $this->assertSame(0700, fileperms($journal->sicherungsVerzeichnis()) & 0777);
            $this->assertFileEquals(UpdateJournal::rueckwegVorlage(), $verzeichnis . '/' . UpdateJournal::RUECKWEG);

            $gelesen = UpdateJournal::lesen($this->wurzel);
            $this->assertSame('kopf', $gelesen['kopf']['art']);
            $this->assertSame([], $gelesen['eintraege']);
        } finally {
            $journal->abschliessen();
            UpdateSperre::freigeben($this->wurzel);
        }
        $this->assertDirectoryDoesNotExist(UpdateJournal::verzeichnis($this->wurzel));
    }

    public function testKeinsOhneJournal(): void {
        $this->assertSame('keins', UpdateJournal::verwaistesZurueckrollen($this->wurzel));
    }

    /**
     * Ein Kill mitten im Schreiben hinterlässt eine abgeschnittene letzte
     * Zeile. Zu ihr gehört noch keine Dateioperation - sie wird verworfen,
     * der Rest zurückgerollt. Dabei entstehen auch fehlende Elternordner
     * wieder (eine Sicherung aus einem inzwischen entfernten Verzeichnis).
     */
    public function testAbgeschnitteneZeileUndFehlendeElternordner(): void {
        mkdir($this->wurzel . '/lang/de', 0755, true);
        file_put_contents($this->wurzel . '/lang/de/text.php', 'ALT');
        file_put_contents($this->wurzel . '/neu.php', 'NEU');

        $journal = $this->oeffne();
        copy($this->wurzel . '/lang/de/text.php', $journal->sicherungsVerzeichnis() . '/sicherung-text');
        $journal->anhaengen('restore', $this->wurzel . '/lang/de/text.php', $journal->sicherungsVerzeichnis() . '/sicherung-text');
        $journal->anhaengen('created', $this->wurzel . '/neu.php');
        // Das "Update" hat lang/ inzwischen komplett ersetzt bzw. entfernt.
        unlink($this->wurzel . '/lang/de/text.php');
        rmdir($this->wurzel . '/lang/de');
        rmdir($this->wurzel . '/lang');
        file_put_contents(UpdateJournal::journalDatei($this->wurzel), '{"art":"created","pfad":"ab', FILE_APPEND);
        UpdateSperre::freigeben($this->wurzel);

        $this->assertSame(1, UpdateJournal::lesen($this->wurzel)['verworfen']);
        $this->assertSame('zurueckgerollt', UpdateJournal::verwaistesZurueckrollen($this->wurzel));

        $this->assertSame('ALT', file_get_contents($this->wurzel . '/lang/de/text.php'));
        $this->assertFileDoesNotExist($this->wurzel . '/neu.php');
        $this->assertFalse(UpdateJournal::offen($this->wurzel));
        $this->assertDirectoryDoesNotExist(UpdateJournal::verzeichnis($this->wurzel));

        $abbruch = UpdateJournal::letzterAbbruch($this->wurzel);
        $this->assertNotNull($abbruch);
        $this->assertFalse($abbruch['protokolliert']);
        $this->assertTrue($abbruch['vollstaendig']);
    }

    /**
     * Das Journal ist Dateiinhalt. Ein Eintrag, der aus der Wurzel oder aus
     * sicherungen/ herausführt, wird verworfen - der Rückweg löscht und
     * überschreibt nur innerhalb der Installation.
     */
    public function testPfadeAusserhalbDerWurzelWerdenVerworfen(): void {
        $aussen = dirname($this->wurzel) . '/' . basename($this->wurzel) . '-aussen.txt';
        file_put_contents($aussen, 'FREMD');

        $journal = $this->oeffne();
        $datei = UpdateJournal::journalDatei($this->wurzel);
        file_put_contents($datei, json_encode(['art' => 'created', 'pfad' => '../' . basename($aussen)]) . "\n", FILE_APPEND);
        file_put_contents($datei, json_encode(['art' => 'created', 'pfad' => $aussen]) . "\n", FILE_APPEND);
        file_put_contents($datei, json_encode(['art' => 'restore', 'sicherung' => '../../etc/passwd', 'original' => 'x.php']) . "\n", FILE_APPEND);
        UpdateSperre::freigeben($this->wurzel);
        unset($journal);

        $this->assertSame(3, UpdateJournal::lesen($this->wurzel)['verworfen']);
        $this->assertSame('zurueckgerollt', UpdateJournal::verwaistesZurueckrollen($this->wurzel));
        $this->assertSame('FREMD', file_get_contents($aussen));
        $this->assertFileDoesNotExist($this->wurzel . '/x.php');
    }

    /** Hält ein anderer Prozess die Sperre, fasst der Rückweg nichts an. */
    public function testBelegtWennEinAndererProzessDieSperreHaelt(): void {
        file_put_contents($this->wurzel . '/neu.php', 'NEU');
        $journal = $this->oeffne();
        $journal->anhaengen('created', $this->wurzel . '/neu.php');
        UpdateSperre::freigeben($this->wurzel);

        $prozess = proc_open(
            [PHP_BINARY, __DIR__ . '/../../Support/update-abbruch-kind.php', 'sperre', $this->wurzel],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->prozesse[] = $prozess;
        $this->assertSame("BEREIT\n", fgets($pipes[1]));

        $this->assertSame('belegt', UpdateJournal::verwaistesZurueckrollen($this->wurzel));
        $this->assertFileExists($this->wurzel . '/neu.php');
        $this->assertTrue(UpdateJournal::offen($this->wurzel));
    }

    private function oeffne(): UpdateJournal {
        UpdateSperre::erwerben($this->wurzel, 'Test');
        return UpdateJournal::oeffnen($this->wurzel, ['zweck' => 'Test', 'von' => '1.0.0', 'nach' => '9.9.9']);
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
