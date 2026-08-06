<?php
// tests/Functional/HorseCsvImportTest.php

namespace Tests\Functional;

/**
 * HTTP-Funktionstests für den CSV-Bulk-Import von Pferden (#49, siehe
 * App\Controllers\ImportController und App\Service\HorseCsvImporter):
 * Vorschau mit gemischt gültigen/ungültigen Zeilen, dass nur die gültigen
 * beim tatsächlichen Import übernommen werden, Behandlung doppelter UELNs
 * innerhalb einer Datei (DB-abhängig, daher hier statt in
 * tests/Unit/Service/HorseCsvImporterTest.php - dort die reine, DB-freie
 * Parsing-Logik), sowie CSRF-Pflicht.
 *
 * Bewusst ALLE Szenarien in EINER Testmethode mit einer einzigen
 * authenticatedClient()-Sitzung und möglichst wenigen HTTP-Roundtrips
 * (analog zu HorseMatchingTest) - jede eigene authenticatedClient()-Instanz
 * durchläuft einen vollständigen Login+2FA-Roundtrip, und die vollständige
 * Functional-Suite reagiert empfindlich auf die Gesamtzahl an Requests
 * (siehe Issue zur bekannten Test-Harness-Fragilität).
 */
class HorseCsvImportTest extends FunctionalTestCase {

    public function testCsvImportPreviewValidationAndCommit(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        // CSRF-Pflicht auf dem Vorschau-Endpunkt.
        $csrfCheck = $admin->postFile(
            '/admin/import/horses/preview',
            ['csrf_token' => 'ungueltig'],
            'csv_file',
            'import.csv',
            "name\nTest\n"
        );
        $this->assertSame(403, $csrfCheck->statusCode);

        $formPage = $admin->get('/admin/import/horses');

        // Doppelte UELN innerhalb derselben Datei -> nur der erste Treffer gültig
        // (DB-abhängige Prüfung, siehe Klassenkommentar).
        $duplicateUeln = 'DE' . substr($unique, -9) . 'DUP';
        $duplicateCheck = $admin->postFile(
            '/admin/import/horses/preview',
            ['csrf_token' => $formPage->formField('csrf_token') ?? ''],
            'csv_file',
            'import.csv',
            "name;ueln\nErstes Pferd {$unique};{$duplicateUeln}\nZweites Pferd {$unique};{$duplicateUeln}\n"
        );
        $this->assertSame(200, $duplicateCheck->statusCode);
        $this->assertStringContainsString('1 von 2 Zeilen', $this->stripHtml($duplicateCheck->body));
        $this->assertStringContainsString('mehrfach in dieser Datei', $duplicateCheck->body);

        // Hauptszenario: gemischt gültige/ungültige Zeilen -> Vorschau, dann Commit.
        $validName = "CSV Import Testpferd {$unique}";
        $validUeln = 'DE' . substr($unique, -9) . 'CSV';
        $csv = "name;ueln;birth_year;color;status\n"
            . "{$validName};{$validUeln};2017;Fuchs;active\n"
            . ";;2017;Rappe;active\n" // Name fehlt -> ungültig
            . "Ungueltiges Jahr {$unique};;zwanzigsiebzehn;Schimmel;active\n"; // ungültiges Geburtsjahr -> ungültig

        $previewResponse = $admin->postFile(
            '/admin/import/horses/preview',
            ['csrf_token' => $formPage->formField('csrf_token') ?? ''],
            'csv_file',
            'import.csv',
            $csv
        );

        $this->assertSame(200, $previewResponse->statusCode);
        $this->assertStringContainsString('1 von 3 Zeilen', $this->stripHtml($previewResponse->body), "Vorschau sollte 1 gültige von 3 Zeilen zeigen (nur die erste Zeile ist fehlerfrei), Body: {$previewResponse->body}");
        $this->assertStringContainsString($validName, $previewResponse->body);
        $this->assertStringContainsString('Name fehlt', $previewResponse->body);
        $this->assertStringContainsString('ungültig', $previewResponse->body);

        $commitResponse = $admin->post('/admin/import/horses/commit', [
            'csrf_token' => $previewResponse->formField('csrf_token') ?? '',
        ]);

        $this->assertSame(200, $commitResponse->statusCode);
        $commitText = $this->stripHtml($commitResponse->body);
        $this->assertStringContainsString('1 Pferd(e) erfolgreich importiert', $commitText, "Body: {$commitResponse->body}");
        $this->assertStringContainsString('2 Zeile(n) wegen Fehlern übersprungen', $commitText);

        // Tatsächlich in der DB gelandet: genau die eine gültige Zeile, per
        // API-Suche über das gemeinsame $unique-Suffix aller drei Testzeilen
        // verifiziert (ein einziger Request statt je einem für "gefunden"
        // und "nicht gefunden").
        $lookup = $admin->get('/api/horses?search=' . urlencode($unique));
        $lookupBody = json_decode($lookup->body, true);
        $this->assertSame(1, $lookupBody['meta']['total'], "Nur die eine gültige Zeile sollte importiert worden sein, Body: {$lookup->body}");
        $this->assertSame($validName, $lookupBody['data'][0]['name']);
        $this->assertSame($validUeln, $lookupBody['data'][0]['ueln']);
    }

    private function stripHtml(string $html): string {
        return trim(preg_replace('/\s+/', ' ', strip_tags($html)));
    }
}
