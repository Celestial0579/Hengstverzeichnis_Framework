<?php
// tests/Unit/Service/CheckedFileWriterTest.php

namespace Tests\Unit\Service;

use App\Service\CheckedFileWriter;
use PHPUnit\Framework\TestCase;

/**
 * Audit M43: gzwrite() meldet einen vollen Datenträger mit 0 statt false,
 * gzclose() meldet trotzdem Erfolg. CheckedFileWriter muss beides erkennen.
 * Der volle Datenträger wird mit /dev/full nachgestellt (Linux); ohne ein
 * beschreibbares /dev/full werden diese Fälle übersprungen.
 */
class CheckedFileWriterTest extends TestCase {

    /** @var array<int, string> */
    private array $dateien = [];

    protected function tearDown(): void {
        foreach ($this->dateien as $pfad) {
            @unlink($pfad);
        }
    }

    private function tempPfad(): string {
        $pfad = (string)tempnam(sys_get_temp_dir(), 'hv-cfw-test-');
        $this->dateien[] = $pfad;
        return $pfad;
    }

    private function requireDevFull(): void {
        if (!is_writable('/dev/full')) {
            $this->markTestSkipped('/dev/full nicht verfügbar - voller Datenträger lässt sich nicht nachstellen.');
        }
    }

    private function requireZlib(): void {
        if (!function_exists('gzopen')) {
            $this->markTestSkipped('zlib-Extension fehlt.');
        }
    }

    public function testGzipGrosserSchreibvorgangAufVollemDatentraegerWirftSchonBeimSchreiben(): void {
        $this->requireDevFull();
        $this->requireZlib();

        $writer = CheckedFileWriter::open('/dev/full', true);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Datenträger oder Kontingent voll');
            $writer->write(random_bytes(200_000));
        } finally {
            $writer->abort();
        }
    }

    public function testGzipKleinerSchreibvorgangAufVollemDatentraegerWirftBeimSchliessen(): void {
        $this->requireDevFull();
        $this->requireZlib();

        $writer = CheckedFileWriter::open('/dev/full', true);
        // 1 KB bleibt im zlib-Puffer - gzwrite() meldet Erfolg.
        $writer->write(random_bytes(1024));
        $this->expectException(\RuntimeException::class);
        $writer->close();
    }

    public function testOhneGzipAufVollemDatentraegerWirft(): void {
        $this->requireDevFull();

        $writer = CheckedFileWriter::open('/dev/full', false);
        $this->expectException(\RuntimeException::class);
        try {
            $writer->write(random_bytes(1024));
            $writer->close();
        } finally {
            $writer->abort();
        }
    }

    public function testRundreiseGzipMitMehrerenUndLeerenChunks(): void {
        $this->requireZlib();
        $pfad = $this->tempPfad();
        $teile = ['', 'Kopf', '', random_bytes(70_000), str_repeat('x', 3), ''];

        $writer = CheckedFileWriter::open($pfad, true, 9);
        $this->assertTrue($writer->isGzip());
        foreach ($teile as $teil) {
            $writer->write($teil);
        }
        $writer->close();

        $this->assertSame(implode('', $teile), gzdecode((string)file_get_contents($pfad)));
    }

    public function testRundreiseOhneGzip(): void {
        $pfad = $this->tempPfad();
        $writer = CheckedFileWriter::open($pfad, false);
        $this->assertFalse($writer->isGzip());
        $writer->write('eins');
        $writer->write('');
        $writer->write('zwei');
        $writer->close();

        $this->assertSame('einszwei', file_get_contents($pfad));
    }

    public function testLeereGzipDateiIstGueltig(): void {
        $this->requireZlib();
        $pfad = $this->tempPfad();
        $writer = CheckedFileWriter::open($pfad, true);
        $writer->close();

        $this->assertSame('', gzdecode((string)file_get_contents($pfad)));
    }

    public function testAssertCompleteErkenntGekuerzteUndVerfaelschteDateien(): void {
        $this->requireZlib();
        $daten = random_bytes(5000);
        $crc = (int)hexdec(hash('crc32b', $daten));
        $pfad = $this->tempPfad();
        file_put_contents($pfad, gzencode($daten));
        $intakt = (string)file_get_contents($pfad);

        // Intakt: keine Ausnahme.
        CheckedFileWriter::assertComplete($pfad, true, strlen($daten), $crc);

        $faelle = [
            'um 3 Byte gekürzt' => substr($intakt, 0, -3),
            'CRC-Byte gekippt' => substr($intakt, 0, -8) . chr(ord($intakt[strlen($intakt) - 8]) ^ 0xFF) . substr($intakt, -7),
            'leer' => '',
            'keine gzip-Kennung' => 'xx' . substr($intakt, 2),
        ];
        foreach ($faelle as $name => $inhalt) {
            file_put_contents($pfad, $inhalt);
            try {
                CheckedFileWriter::assertComplete($pfad, true, strlen($daten), $crc);
                $this->fail("Nicht erkannt: {$name}");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('unvollständig', $e->getMessage(), $name);
            }
        }

        // Falsche Länge bei richtiger Datei.
        file_put_contents($pfad, $intakt);
        $this->expectException(\RuntimeException::class);
        CheckedFileWriter::assertComplete($pfad, true, strlen($daten) + 1, $crc);
    }

    public function testAssertCompleteOhneGzipPrueftDieGroesse(): void {
        $pfad = $this->tempPfad();
        file_put_contents($pfad, 'abc');

        CheckedFileWriter::assertComplete($pfad, false, 3, 0);

        $this->expectException(\RuntimeException::class);
        CheckedFileWriter::assertComplete($pfad, false, 4, 0);
    }

    public function testAbortNachTeilschreibenWirftNichtUndIstIdempotent(): void {
        $this->requireZlib();
        $pfad = $this->tempPfad();
        $writer = CheckedFileWriter::open($pfad, true);
        $writer->write(random_bytes(10_000));
        $writer->abort();
        $writer->abort();
        $this->addToAssertionCount(1);

        $this->expectException(\LogicException::class);
        $writer->write('danach');
    }

    public function testNichtSchreibbarerPfadWirft(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nicht schreibbar');
        CheckedFileWriter::open(sys_get_temp_dir() . '/hv-gibt-es-nicht-' . uniqid() . '/datei', false);
    }
}
