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
        // Zeile 2 nutzt die neuen Spalten (#188): deutsches Datumsformat,
        // Stockmaß, Legacy-Status 'deceased' und Todesjahr. Zeile 3 prüft den
        // deceased-Alias 'ja'.
        $legacyName = "CSV Legacy Verstorben {$unique}";
        $aliasName = "CSV Verstorben Alias {$unique}";
        // Genauigkeit des Geburtsdatums (#379): "Jahr" grossgeschrieben, um
        // nebenbei die Case-Insensitivitaet der Aliasse festzuhalten.
        $jahrName = "CSV Nur Jahr {$unique}";
        $csv = "name;ueln;birth_year;birth_date;birth_date_precision;color;sex;breed;height_cm;status;deceased;death_year\n"
            . "{$validName};{$validUeln};2017;02.04.2017;;Fuchs;Stute;Fjordpferd;146;active;;\n"
            . "{$legacyName};;;13.06.1994;;Braun;;;;deceased;;2018\n"
            . "{$aliasName};;1990;;;Rappe;;;;inactive;ja;\n"
            . "{$jahrName};;;01.01.1976;Jahr;Falb;;;;active;;\n"
            . ";;2017;;;Rappe;;;;active;;\n" // Name fehlt -> ungültig
            . "Ungueltiges Jahr {$unique};;zwanzigsiebzehn;;;Schimmel;;;;active;;\n" // ungültiges Geburtsjahr -> ungültig
            . "Ungueltiges Geschlecht {$unique};;2017;;;Fuchs;Zwitter;;;active;;\n" // ungültiges Geschlecht -> ungültig
            . "Datum Konflikt {$unique};;2001;2017-04-02;;;;;;active;;\n" // birth_date vs birth_year -> ungültig
            . "Stockmass Zwerg {$unique};;2017;;;;;;30;active;;\n" // Stockmaß außerhalb 50-250 -> ungültig
            . "Tod vor Geburt {$unique};;2017;;;;;;;active;;1990\n" // death_year < birth_year -> ungültig
            . "Verstorben Kaputt {$unique};;2017;;;;;;;active;vielleicht;\n" // ungültiger deceased-Wert -> ungültig
            . "Genauigkeit Kaputt {$unique};;;01.01.1980;ungefaehr;;;;;active;;\n" // ungültige Genauigkeit -> ungültig
            . "Genauigkeit Ohne Datum {$unique};;1980;;jahr;;;;;active;;\n"; // Genauigkeit ohne Datum -> ungültig

        $previewResponse = $admin->postFile(
            '/admin/import/horses/preview',
            ['csrf_token' => $formPage->formField('csrf_token') ?? ''],
            'csv_file',
            'import.csv',
            $csv
        );

        $this->assertSame(200, $previewResponse->statusCode);
        $this->assertStringContainsString('4 von 13 Zeilen', $this->stripHtml($previewResponse->body), "Vorschau sollte 4 gültige von 13 Zeilen zeigen, Body: {$previewResponse->body}");
        $this->assertStringContainsString($validName, $previewResponse->body);
        $this->assertStringContainsString('Name fehlt', $previewResponse->body);
        $this->assertStringContainsString('ungültig', $previewResponse->body);
        $this->assertStringContainsString('Geschlecht &#039;Zwitter&#039; ist ungültig', $previewResponse->body);
        $this->assertStringContainsString('widersprechen sich', $previewResponse->body);
        $this->assertStringContainsString('Stockmaß &#039;30&#039; ist ungültig', $previewResponse->body);
        $this->assertStringContainsString('liegt vor dem Geburtsjahr', $previewResponse->body);
        $this->assertStringContainsString('Verstorben-Angabe &#039;vielleicht&#039; ist ungültig', $previewResponse->body);
        $this->assertStringContainsString('Genauigkeit &#039;ungefaehr&#039; ist ungültig', $previewResponse->body);
        $this->assertStringContainsString('ohne Geburtsdatum', $previewResponse->body);

        // Ohne gesetzte Veröffentlichen-Checkbox: importierte Pferde bleiben - wie jedes
        // neu angelegte Pferd - standardmäßig unveröffentlicht (is_published = 0). Die
        // optionale Opt-in-Checkbox (nur mit horses.publish) wird hier bewusst NICHT
        // mitgesendet; die DB-Verifikation erfolgt deshalb über die Backend-Liste.
        $commitResponse = $admin->post('/admin/import/horses/commit', [
            'csrf_token' => $previewResponse->formField('csrf_token') ?? '',
        ]);

        $this->assertSame(200, $commitResponse->statusCode);
        $commitText = $this->stripHtml($commitResponse->body);
        $this->assertStringContainsString('4 Pferd(e) erfolgreich importiert', $commitText, "Body: {$commitResponse->body}");
        $this->assertStringContainsString('9 Zeile(n) wegen Fehlern übersprungen', $commitText);

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
        // die Rasse als Freitext (#165/#163); Datum/Stockmaß aus den neuen
        // Spalten (#188), birth_year aus dem Datum abgeleitet.
        $db = \App\Database::getInstance();
        $stmt = $db->prepare("SELECT sex, breed, birth_date, birth_year, height_cm FROM horses WHERE name = ?");
        $stmt->execute([$validName]);
        $row = $stmt->fetch();
        $this->assertSame('mare', $row['sex']);
        $this->assertSame('Fjordpferd', $row['breed']);
        $this->assertSame('2017-04-02', $row['birth_date'], 'Deutsches Datumsformat 02.04.2017 muss als ISO gespeichert werden');
        $this->assertSame(2017, (int)$row['birth_year']);
        $this->assertSame(146, (int)$row['height_cm']);

        // Genauigkeit (#379): ohne Spaltenwert bleibt es tagesgenau, mit
        // "Jahr" wird daraus 'year' - und das Datum bleibt trotzdem stehen.
        // Das beweist, dass ImportController den Wert wirklich SCHREIBT und
        // der Importer ihn nicht nur validiert.
        $stmt = $db->prepare("SELECT birth_date, birth_date_precision FROM horses WHERE name = ?");
        $stmt->execute([$validName]);
        $this->assertSame('day', (string)$stmt->fetch()['birth_date_precision'], 'Ohne Spaltenwert bleibt es tagesgenau.');

        $stmt->execute([$jahrName]);
        $row = $stmt->fetch();
        $this->assertSame('year', (string)$row['birth_date_precision'], 'Der Alias "Jahr" muss case-insensitiv auf year abgebildet werden.');
        $this->assertSame('1976-01-01', (string)$row['birth_date'], 'Das Quelldatum bleibt erhalten - ausgegeben wird nur anders.');

        // Legacy status=deceased -> inactive + is_deceased=1, Todesjahr gesetzt.
        $stmt = $db->prepare("SELECT status, is_deceased, death_year, birth_year FROM horses WHERE name = ?");
        $stmt->execute([$legacyName]);
        $row = $stmt->fetch();
        $this->assertSame('inactive', $row['status'], "Alt-Wert status=deceased muss wie der Migrations-Backfill zu 'inactive' werden");
        $this->assertSame(1, (int)$row['is_deceased']);
        $this->assertSame(2018, (int)$row['death_year']);
        $this->assertSame(1994, (int)$row['birth_year'], 'birth_year muss aus birth_date abgeleitet werden');

        // deceased-Alias 'ja' ohne Todesjahr.
        $stmt->execute([$aliasName]);
        $row = $stmt->fetch();
        $this->assertSame('inactive', $row['status']);
        $this->assertSame(1, (int)$row['is_deceased']);
        $this->assertNull($row['death_year']);
    }

    private function stripHtml(string $html): string {
        return trim(preg_replace('/\s+/', ' ', strip_tags($html)));
    }
}
