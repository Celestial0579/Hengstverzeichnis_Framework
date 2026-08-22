<?php
// tests/Functional/HorseBirthDatePrecisionTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Genauigkeit des Geburtsdatums (#379).
 *
 * WORUM ES GEHT. `birth_date` trug im Altbestand bei 887 von 1885 Pferden den
 * 1. Januar - nicht als Geburtstag, sondern als Platzhalter für ein bloß
 * bekanntes Jahr. Die Monatsverteilung schließt einen Zufall aus: 888 im
 * Januar gegen 11 im Februar, und Fjordpferde fohlen im Frühjahr. Die
 * Detailseite zeigte das trotzdem als „01.01.1976" - eine Tagesangabe, die
 * keine Quelle hergibt.
 *
 * Es ist ausdrücklich KEIN Datenfehler: Der Platzhalter steckt schon in der
 * Migrationsquelle, und die Crawler-Belege (rimondo, haststam) tragen ihn
 * selbst. Das Datum bleibt deshalb gespeichert; ausgegeben wird die
 * Genauigkeit.
 *
 * WAS DIESER TEST FESTHÄLT, und warum jeder Fall einzeln nötig ist:
 *
 *  1. Mit `precision=year` zeigt die öffentliche Seite das JAHR - und das
 *     Datum steht weiter in der Datenbank. Das ist die Zusage des Issues.
 *  2. Ohne Angabe bleibt es tagesgenau, AUCH am 1. Januar. Dieser Fall ist
 *     der eigentliche Wächter: Er sperrt die naheliegende Abkürzung aus,
 *     jedes `-01-01` als Jahresangabe zu lesen. Die träfe die Pferde mit,
 *     die wirklich an dem Tag geboren sind, und niemandem fiele es auf.
 *  3. Wird das Datum geleert, fällt die Genauigkeit auf `day` zurück. Eine
 *     Genauigkeit ohne Datum ist bedeutungslos, und sie bliebe sonst still
 *     stehen, bis jemand ein echtes Datum einträgt - das dann als Jahr
 *     erschiene.
 *
 * Die Gegenprobe zu Fall 1 ist die vorhandene Zusage in
 * HorseLifecycleFieldsTest::…: ein Pferd mit dem 13.06.1994 muss weiterhin
 * „13.06.1994" zeigen.
 */
class HorseBirthDatePrecisionTest extends FunctionalTestCase {

    /** @return array<string, mixed> */
    private function zeile(int $horseId): array {
        $stmt = Database::getInstance()->prepare(
            'SELECT birth_date, birth_date_precision, birth_year FROM horses WHERE id = ?'
        );
        $stmt->execute([$horseId]);
        $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($zeile, "Pferd #{$horseId} nicht gefunden.");

        return $zeile;
    }

    private function anlegen(string $name, array $extra): int {
        $admin = $this->authenticatedClient();
        $form = $admin->get('/admin/horses/create');
        $antwort = $admin->post('/admin/horses/store', array_merge([
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $name,
            'status' => 'active',
            'is_published' => '1',
        ], $extra));
        $this->assertSame(
            '/admin/horses?success=created',
            $antwort->location(),
            "Anlegen von '{$name}' fehlgeschlagen, Body: {$antwort->body}"
        );

        $stmt = Database::getInstance()->prepare('SELECT id FROM horses WHERE name = ?');
        $stmt->execute([$name]);
        $id = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $id);

        return $id;
    }

    public function testNurDasJahrBekanntZeigtDasJahrUndBehaeltDasDatum(): void {
        $einmalig = uniqid();
        $id = $this->anlegen("Platzhalter {$einmalig}", [
            'birth_date' => '1976-01-01',
            'birth_date_precision' => 'year',
        ]);

        $zeile = $this->zeile($id);
        $this->assertSame(
            '1976-01-01',
            (string)$zeile['birth_date'],
            'Das Quelldatum muss erhalten bleiben - es kommt beim naechsten Abgleich ohnehin zurueck.'
        );
        $this->assertSame('year', (string)$zeile['birth_date_precision']);
        $this->assertSame(1976, (int)$zeile['birth_year'], 'Das Jahr wird weiterhin aus dem Datum abgeleitet.');

        $gast = $this->newClient();
        $seite = $gast->get('/horse?id=' . $id);
        $this->assertSame(200, $seite->statusCode);

        $this->assertStringContainsString('1976', $seite->body);
        $this->assertStringNotContainsString(
            '01.01.1976',
            $seite->body,
            'Die Seite darf keinen Tag behaupten, den keine Quelle hergibt (#379).'
        );

        // Auch die Beschriftung muss ehrlich sein: "Geburtsdatum: 1976" waere
        // nur die halbe Korrektur.
        $this->assertStringContainsString('Geburtsjahr', $seite->body);
    }

    public function testEineEchteNeujahrsgeburtBleibtTagesgenau(): void {
        $einmalig = uniqid();
        $id = $this->anlegen("Neujahr {$einmalig}", [
            'birth_date' => '1976-01-01',
            // Genauigkeit bewusst NICHT mitgeschickt - so sieht jeder
            // Bestandsdatensatz aus.
        ]);

        $this->assertSame(
            'day',
            (string)$this->zeile($id)['birth_date_precision'],
            'Ohne Angabe muss die Vorgabe day gelten, sonst aendert das Update stillschweigend jeden Bestand.'
        );

        $gast = $this->newClient();
        $seite = $gast->get('/horse?id=' . $id);

        $this->assertStringContainsString(
            '01.01.1976',
            $seite->body,
            'Wer wirklich am 1. Januar geboren ist, behaelt sein Datum - genau deshalb gibt es keine Heuristik auf -01-01.'
        );
    }

    /**
     * Der Datumsbereichsfilter ist OEFFENTLICH erreichbar (#379).
     *
     * Das Eingabefeld gibt es nur im Adminbereich (admin_horses.php), aber
     * `PublicController::catalog()` baut dieselben Kriterien wie der Admin -
     * `birth_date_from`/`birth_date_to` stehen in der oeffentlichen
     * Filter-Weissliste. Eine Abfrage vom 01.01.1976 bis zum 01.01.1976
     * lieferte deshalb exakt die Platzhalter und saehe aus wie eine Aussage
     * ueber Neujahrsgeburten.
     *
     * Geprueft wird beides in EINER Abfrage: Das jahresgenaue Pferd faellt
     * heraus, das tagesgenaue bleibt drin. Nur das erste zu pruefen liesse
     * offen, ob der Filter ueberhaupt noch etwas findet.
     */
    public function testDerOeffentlicheDatumsbereichUeberspringtPlatzhalter(): void {
        $einmalig = uniqid();
        $platzhalter = "Bereich Platzhalter {$einmalig}";
        $echt = "Bereich Echt {$einmalig}";

        $this->anlegen($platzhalter, [
            'birth_date' => '1976-01-01',
            'birth_date_precision' => 'year',
        ]);
        $this->anlegen($echt, [
            'birth_date' => '1976-01-01',
        ]);

        $gast = $this->newClient();
        $treffer = $gast->get('/katalog?birth_date_from=1976-01-01&birth_date_to=1976-01-01');
        $this->assertSame(200, $treffer->statusCode);

        $this->assertStringContainsString(
            htmlspecialchars($echt),
            $treffer->body,
            'Ein tagesgenaues Datum muss der Datumsbereich weiterhin finden.'
        );
        $this->assertStringNotContainsString(
            htmlspecialchars($platzhalter),
            $treffer->body,
            'Ein Platzhalter-Datum darf keine tagesgenaue Frage beantworten (#379).'
        );

        // Und die ehrliche Frage findet beide: birth_year_from/to ist der Weg
        // fuer jahrgenaue Suchen und bleibt davon unberuehrt.
        $nachJahr = $gast->get('/katalog?birth_year_from=1976&birth_year_to=1976');
        $this->assertStringContainsString(htmlspecialchars($platzhalter), $nachJahr->body);
        $this->assertStringContainsString(htmlspecialchars($echt), $nachJahr->body);
    }

    public function testOhneDatumFaelltDieGenauigkeitZurueck(): void {
        $einmalig = uniqid();
        $name = "Geleert {$einmalig}";
        $id = $this->anlegen($name, [
            'birth_date' => '1976-01-01',
            'birth_date_precision' => 'year',
        ]);

        $admin = $this->authenticatedClient();
        $formular = $admin->get('/admin/horses/edit?id=' . $id);
        $antwort = $admin->post('/admin/horses/update', [
            'csrf_token' => $formular->formField('csrf_token') ?? '',
            'id' => (string)$id,
            'name' => $name,
            'status' => 'active',
            'is_published' => '1',
            'birth_year' => '1976',
            'birth_date' => '',
            // Die Genauigkeit steht noch im Formular und wird mitgesendet -
            // ohne Datum darf sie trotzdem nicht stehenbleiben.
            'birth_date_precision' => 'year',
        ]);
        $this->assertSame('/admin/horses?success=updated', $antwort->location(), "Body: {$antwort->body}");

        $zeile = $this->zeile($id);
        $this->assertNull($zeile['birth_date']);
        $this->assertSame(
            'day',
            (string)$zeile['birth_date_precision'],
            'Eine Genauigkeit ohne Datum ist bedeutungslos und wirkte spaeter still weiter.'
        );
        $this->assertSame(1976, (int)$zeile['birth_year']);

        $gast = $this->newClient();
        $this->assertStringContainsString('1976', $gast->get('/horse?id=' . $id)->body);
    }
}
