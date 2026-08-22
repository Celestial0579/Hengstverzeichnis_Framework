<?php
// tests/Unit/Service/HorseCsvImporterTest.php

namespace Tests\Unit\Service;

use App\Service\HorseCsvImporter;
use PHPUnit\Framework\TestCase;

/**
 * Reiner Unit-Test ohne DB/HTTP für HorseCsvImporter::parse() (#49) - deckt
 * die Pflichtspalten-Prüfung, automatische Trennzeichen-Erkennung
 * (Komma/Semikolon) und das Überspringen leerer Zeilen ab. Die
 * DB-abhängige Validierung (UELN-Eindeutigkeit gegen bestehende Pferde)
 * bleibt bewusst ein Functional-Test (siehe HorseCsvImportTest), da sie
 * eine echte Datenbank braucht.
 */
class HorseCsvImporterTest extends TestCase {

    public function testMissingNameColumnIsReportedAsFileLevelError(): void {
        $result = HorseCsvImporter::parse("ueln;color\nDE123;Fuchs\n");

        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('Pflichtspalte', $result['error']);
        $this->assertSame([], $result['rows']);
    }

    public function testSemicolonDelimiterIsAutoDetected(): void {
        $result = HorseCsvImporter::parse("name;color\nQuantum;Fuchs\n");

        $this->assertNull($result['error']);
        $this->assertSame(['name' => 0, 'color' => 1], $result['columnMap']);
        $this->assertSame(['Quantum', 'Fuchs'], $result['rows'][0]);
    }

    public function testCommaDelimiterIsAutoDetected(): void {
        $result = HorseCsvImporter::parse("name,color\nQuantum,Fuchs\n");

        $this->assertNull($result['error']);
        $this->assertSame(['name' => 0, 'color' => 1], $result['columnMap']);
        $this->assertSame(['Quantum', 'Fuchs'], $result['rows'][0]);
    }

    public function testEmptyLinesAreSkipped(): void {
        $result = HorseCsvImporter::parse("name\nQuantum\n\n\nAnderer\n");

        $this->assertNull($result['error']);
        $this->assertCount(2, $result['rows']);
    }

    public function testUtf8BomIsStripped(): void {
        $result = HorseCsvImporter::parse("\xEF\xBB\xBFname\nQuantum\n");

        $this->assertNull($result['error']);
        $this->assertSame(['name' => 0], $result['columnMap']);
    }

    public function testUnknownColumnsAreIgnoredButKnownOnesStillMapped(): void {
        $result = HorseCsvImporter::parse("name;unbekannte_spalte;color\nQuantum;egal;Fuchs\n");

        $this->assertNull($result['error']);
        $this->assertSame(['name' => 0, 'color' => 2], $result['columnMap']);
    }

    /**
     * Die Spaltenliste der Import-Seite muss zu KNOWN_COLUMNS passen (#379).
     *
     * Unbekannte Spalten verwirft der Importer STILL (HorseCsvImporter::parse()).
     * Wer eine Spalte ergaenzt und die Erklaerseite vergisst, baut damit ein
     * Feld, das niemand findet; wer die Seite ergaenzt und KNOWN_COLUMNS
     * vergisst, ein Feld, das schweigend ins Leere laeuft. Beide Richtungen
     * fielen bisher nirgends auf.
     */
    public function testDieImportSeiteNenntGenauDieBekanntenSpalten(): void {
        $seite = (string)file_get_contents(__DIR__ . '/../../../src/Views/admin_import_horses.php');

        $this->assertSame(
            1,
            preg_match('/<strong>Unterstützte Spalten:<\/strong>\s*<code>([^<]+)<\/code>/u', $seite, $treffer),
            'Die Spaltenliste der Import-Seite ist nicht mehr auffindbar - Muster anpassen, nicht den Test loeschen.'
        );

        $genannt = array_map(
            static fn(string $spalte): string => rtrim(trim($spalte), '*'),
            explode(',', $treffer[1])
        );

        $this->assertSame(
            HorseCsvImporter::KNOWN_COLUMNS,
            $genannt,
            'Die Import-Seite und KNOWN_COLUMNS sind auseinandergelaufen.'
        );
    }

    public function testEmptyFileProducesError(): void {
        $result = HorseCsvImporter::parse('');

        $this->assertNotNull($result['error']);
    }
}
