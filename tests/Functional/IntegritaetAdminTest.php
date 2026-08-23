<?php
// tests/Functional/IntegritaetAdminTest.php

namespace Tests\Functional;

use App\Service\Integritaet;

/**
 * HTTP-Funktionstests für die Unversehrtheitsprüfung des Codebaums (#403).
 *
 * Warum das einen Functional-Test braucht, obwohl IntegritaetTest die Logik
 * netzfrei abdeckt: Ein Unit-Test auf eine Funktion beweist nicht, dass sie je
 * aufgerufen wird. Genau diese Lücke war #344 - die Registrierung war getestet,
 * nur rief sie niemand auf. Hier geht der Test denselben Weg wie ein Admin:
 * Seite aufrufen, Knopf drücken, Ergebnis lesen.
 *
 * Geprüft wird ausschließlich der Weg gegen die MITGELIEFERTE Liste. Der Weg
 * gegen die veröffentlichte bräuchte GitHub; ein Funktionstest, der still ins
 * Netz greift, ist auf einem Rechner ohne Netz kein Fehlschlag, sondern eine
 * Falschaussage.
 */
class IntegritaetAdminTest extends FunctionalTestCase {

    public function testAbschnittIstFuerAdminsSichtbar(): void {
        $admin = $this->authenticatedClient();

        $seite = $admin->get('/admin/updates');

        $this->assertSame(200, $seite->statusCode);
        $this->assertStringContainsString('Unversehrtheit des Codebaums', $seite->body);
        $this->assertStringContainsString('Gegen mitgelieferte Liste prüfen', $seite->body);
        $this->assertStringContainsString('Gegen veröffentlichte Liste prüfen', $seite->body);
    }

    /**
     * Der Unterschied zwischen den beiden Quellen muss in der OBERFLÄCHE
     * stehen, nicht in einer Fussnote im Code. Wer nur die mitgelieferte
     * Liste prüft und das für eine Manipulationserkennung hält, hat eine
     * falsche Zusicherung - und die ist schlimmer als gar keine.
     */
    public function testDieOberflaecheBenenntDieAussagekraftBeiderQuellen(): void {
        $admin = $this->authenticatedClient();

        $body = $admin->get('/admin/updates')->body;

        // Kurze, zusammenhaengende Wendungen - der View bricht Zeilen um,
        // eine laengere Phrase traefe deshalb je nach Umbruch oder nicht.
        $this->assertStringContainsString('Der Unterschied ist wesentlich', $body);
        $this->assertStringContainsString('Verzeichnisbaum wie die geprüften Dateien', $body);
        $this->assertStringContainsString('bei GitHub geholt und liegt damit außerhalb', $body);
    }

    public function testPruefungBrauchtEinAdminKonto(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $redakteur = $this->createAndLoginEditor(
            $admin,
            "integ{$unique}",
            "integ-{$unique}@example.com"
        );

        $this->assertSame(403, $redakteur->get('/admin/updates')->statusCode);
        $this->assertSame(403, $redakteur->post('/admin/updates/integritaet', [])->statusCode);
    }

    public function testPruefungUndReparaturBrauchenEinCsrfToken(): void {
        $admin = $this->authenticatedClient();

        $this->assertSame(403, $admin->post('/admin/updates/integritaet', ['quelle' => 'mitgeliefert'])->statusCode);
        $this->assertSame(403, $admin->post('/admin/updates/reparieren', ['pfade' => ['src/App.php']])->statusCode);
    }

    /**
     * Der ganze Weg: Knopf drücken, Weiterleitung folgen, Ergebnis lesen.
     *
     * Die Testinstallation hat keine mitgelieferte Liste - sie läuft aus dem
     * Arbeitsverzeichnis, nicht aus einem Release-Zip. Genau das ist ein
     * echter Zustand (jede Installation vor Einführung der Prüfung hat ihn),
     * und die Antwort darauf muss "nicht geprüft" lauten, nicht "heil".
     */
    public function testOhneMitgelieferteListeMeldetDieSeiteNichtGeprueft(): void {
        $admin = $this->authenticatedClient();

        $antwort = $admin->post('/admin/updates/integritaet', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'quelle' => 'mitgeliefert',
        ]);

        $this->assertSame('/admin/updates#integritaet', $antwort->location());

        $seite = $admin->get('/admin/updates');
        $this->assertStringContainsString('Nicht geprüft', $seite->body);
        $this->assertStringNotContainsString('Keine Abweichung', $seite->body);
    }

    /**
     * Und mit Liste: Die Seite muss den heilen Fall als heil melden UND
     * dazusagen, woran gemessen wurde.
     *
     * Die Liste wird dafür kurzzeitig in die laufende Installation gelegt und
     * hinterher wieder entfernt - sie beschreibt nur die paar Dateien, die
     * der Test selbst nennt, und ist deshalb harmlos.
     */
    public function testMitMitgelieferterListeMeldetDieSeiteDasErgebnis(): void {
        $admin = $this->authenticatedClient();
        $wurzel = dirname(__DIR__, 2);
        $liste = $wurzel . '/' . Integritaet::MANIFEST;

        if (file_exists($liste)) {
            $this->markTestSkipped('Diese Installation bringt bereits eine Liste mit.');
        }

        $probe = 'src/Service/Baumordnung.php';
        file_put_contents(
            $liste,
            "# Testliste\n" . hash_file('sha256', $wurzel . '/' . $probe) . '  ' . $probe . "\n"
        );

        try {
            $admin->post('/admin/updates/integritaet', [
                'csrf_token' => $this->currentCsrfToken($admin),
                'quelle' => 'mitgeliefert',
            ]);

            $seite = $admin->get('/admin/updates')->body;

            $this->assertStringContainsString('Keine Abweichung', $seite);
            $this->assertStringContainsString('mitgelieferten</strong> Liste', $seite);
            $this->assertStringContainsString(
                'selben Dateibaum',
                $seite,
                'Auch im grünen Fall muss dastehen, was diese Messung NICHT findet.'
            );
        } finally {
            @unlink($liste);
        }
    }

    /**
     * Eine Datei, die es im Release nicht gibt, wird gemeldet - aber weder
     * entfernt noch als Schaden gewertet. Sie könnte vom Betreiber sein.
     */
    public function testZusaetzlicheDateiWirdGenanntUndBleibtLiegen(): void {
        $admin = $this->authenticatedClient();
        $wurzel = dirname(__DIR__, 2);
        $liste = $wurzel . '/' . Integritaet::MANIFEST;

        if (file_exists($liste)) {
            $this->markTestSkipped('Diese Installation bringt bereits eine Liste mit.');
        }

        $probe = 'src/Service/Baumordnung.php';
        file_put_contents(
            $liste,
            "# Testliste\n" . hash_file('sha256', $wurzel . '/' . $probe) . '  ' . $probe . "\n"
        );

        try {
            $admin->post('/admin/updates/integritaet', [
                'csrf_token' => $this->currentCsrfToken($admin),
                'quelle' => 'mitgeliefert',
            ]);

            $seite = $admin->get('/admin/updates')->body;

            // src/ ist voll von Dateien, die diese Minimalliste nicht kennt.
            $this->assertStringContainsString('Programmverzeichnissen', $seite);
            $this->assertStringContainsString('nie entfernt', $seite);
            $this->assertFileExists(
                $wurzel . '/src/Service/Integritaet.php',
                'Die Prüfung darf nie etwas entfernen.'
            );
        } finally {
            @unlink($liste);
        }
    }
}
