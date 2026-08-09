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
        // Die gültige Zeile nutzt den deutschen Geschlechts-Alias "Stute" (#165),
        // der beim Import auf den kanonischen ENUM-Wert 'mare' abgebildet wird.
        $validName = "CSV Import Testpferd {$unique}";
        $validUeln = 'DE' . substr($unique, -9) . 'CSV';
        $csv = "name;ueln;birth_year;color;sex;breed;status\n"
            . "{$validName};{$validUeln};2017;Fuchs;Stute;Fjordpferd;active\n"
            . ";;2017;Rappe;;;active\n" // Name fehlt -> ungültig
            . "Ungueltiges Jahr {$unique};;zwanzigsiebzehn;Schimmel;;;active\n" // ungültiges Geburtsjahr -> ungültig
            . "Ungueltiges Geschlecht {$unique};;2017;Fuchs;Zwitter;;active\n"; // ungültiges Geschlecht -> ungültig

        $previewResponse = $admin->postFile(
            '/admin/import/horses/preview',
            ['csrf_token' => $formPage->formField('csrf_token') ?? ''],
            'csv_file',
            'import.csv',
            $csv
        );

        $this->assertSame(200, $previewResponse->statusCode);
        $this->assertStringContainsString('1 von 4 Zeilen', $this->stripHtml($previewResponse->body), "Vorschau sollte 1 gültige von 4 Zeilen zeigen (nur die erste Zeile ist fehlerfrei), Body: {$previewResponse->body}");
        $this->assertStringContainsString($validName, $previewResponse->body);
        $this->assertStringContainsString('Name fehlt', $previewResponse->body);
        $this->assertStringContainsString('ungültig', $previewResponse->body);
        $this->assertStringContainsString('Geschlecht &#039;Zwitter&#039; ist ungültig', $previewResponse->body);

        // Ohne gesetzte Veröffentlichen-Checkbox: importierte Pferde bleiben - wie jedes
        // neu angelegte Pferd - standardmäßig unveröffentlicht (is_published = 0). Die
        // optionale Opt-in-Checkbox (nur mit horses.publish) wird hier bewusst NICHT
        // mitgesendet; die DB-Verifikation erfolgt deshalb über die Backend-Liste.
        $commitResponse = $admin->post('/admin/import/horses/commit', [
            'csrf_token' => $previewResponse->formField('csrf_token') ?? '',
        ]);

        $this->assertSame(200, $commitResponse->statusCode);
        $commitText = $this->stripHtml($commitResponse->body);
        $this->assertStringContainsString('1 Pferd(e) erfolgreich importiert', $commitText, "Body: {$commitResponse->body}");
        $this->assertStringContainsString('3 Zeile(n) wegen Fehlern übersprungen', $commitText);

        // Tatsächlich in der DB gelandet: genau die eine gültige Zeile. Verifiziert
        // über die Backend-Liste statt über die öffentliche API, da importierte Pferde
        // (wie jedes neu angelegte Pferd) standardmäßig UNVERÖFFENTLICHT sind
        // (is_published = 0) und daher bewusst nicht über die publish-gated
        // öffentliche API/Katalog erscheinen - die Backend-Liste zeigt dagegen alle
        // Pferde unabhängig vom Veröffentlichungsstatus.
        $adminList = $admin->get('/admin/horses');
        $this->assertSame(200, $adminList->statusCode);
        $this->assertStringContainsString(htmlspecialchars($validName), $adminList->body, "Die eine gültige Zeile sollte importiert worden und in der Backend-Liste sichtbar sein, Body: {$adminList->body}");
        $this->assertStringContainsString(htmlspecialchars($validUeln), $adminList->body);
        // Die fehlerhaften Zeilen (Name fehlt / ungültiges Jahr / ungültiges
        // Geschlecht) dürfen NICHT importiert worden sein.
        $this->assertStringNotContainsString('Ungueltiges Jahr ' . $unique, $adminList->body);
        $this->assertStringNotContainsString('Ungueltiges Geschlecht ' . $unique, $adminList->body);

        // Der deutsche Alias "Stute" muss kanonisch als 'mare' gespeichert sein,
        // die Rasse als Freitext (#165/#163).
        $stmt = \App\Database::getInstance()->prepare("SELECT sex, breed FROM horses WHERE name = ?");
        $stmt->execute([$validName]);
        $row = $stmt->fetch();
        $this->assertSame('mare', $row['sex']);
        $this->assertSame('Fjordpferd', $row['breed']);
    }

    private function stripHtml(string $html): string {
        return trim(preg_replace('/\s+/', ' ', strip_tags($html)));
    }
}
