<?php
// tests/Functional/CatalogFilterOptionsTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Die Filter-Vorschlagslisten des öffentlichen Katalogs (#412).
 *
 * WAS HIER STEHT UND WARUM ES BISHER FEHLTE. `/katalog` rendert drei
 * `<datalist>`-Blöcke - Züchter, Besitzer, Deckstation -, gespeist aus zwei
 * Abfragen in `PublicController::catalog()`. Getestet war davon nichts:
 * `grep -rn 'breeder_list|owner_list|station_list' tests/` lieferte vor dieser
 * Datei null Treffer. Geprüft war nur, dass die FILTER die
 * Veröffentlichungsgrenze einhalten (CatalogFilterVisibilityTest), nicht die
 * Vorschlagslisten selbst - dabei sind sie dieselbe Sichtbarkeitsfläche: Ein
 * Name, der dort steht, ist öffentlich, auch wenn kein Filter je darauf trifft.
 *
 * DREI FALLEN, DIE EINEN SOLCHEN TEST STILL GRÜN MACHEN:
 *
 * 1. `?ajax=1` erreicht den Code nie. Der AJAX-Zweig endet mit `exit`, BEVOR
 *    die Listen gefüllt werden - genau deshalb wurde er eingeführt (#221).
 *    Beide vorhandenen Katalog-Hilfsmethoden der Suite setzen `ajax=1`; wer
 *    eine davon wiederverwendet, prüft nichts. Hier läuft deshalb immer der
 *    volle Seiten-Render.
 * 2. Dieselben Namen stehen auch auf den Karten, aus einer ganz anderen
 *    Quelle. Ein `assertStringNotContainsString` über den ganzen Rumpf prüfte
 *    also die Karte statt der Liste. Deshalb wird der `<datalist>`-Block
 *    ausgeschnitten - und wenn er fehlt, bricht der Helfer ab, statt eine
 *    leere Menge zu liefern, gegen die jede Abwesenheitszusicherung grün ist.
 * 3. Ein 500er sieht aus wie eine Abwesenheit. Jeder Abruf sichert 200 zu, und
 *    jeder Negativ-Prüfung folgt eine Positiv-Gegenprobe.
 *
 * Gesät wird direkt in der Datenbank - Absicht: Die Zusicherung gilt für die
 * DATENLAGE, nicht für einen bestimmten Weg dorthin. Import, API, Papierkorb
 * und DSGVO-Löschung ändern dieselben Spalten, ohne durch die Kontaktmaske zu
 * gehen.
 */
class CatalogFilterOptionsTest extends FunctionalTestCase {

    /** @var array<int, int> */
    private array $pferde = [];
    /** @var array<int, int> */
    private array $kontakte = [];

    protected function setUp(): void {
        parent::setUp();

        /* Stösst die Ersteinrichtung an. Ohne sie gibt es die Tabelle `horses`
           noch gar nicht, und das Säen unten scheitert mit "Base table doesn't
           exist" - eine Meldung, die nach einem kaputten Schema aussieht statt
           nach einer fehlenden Vorbedingung. Dieselbe Falle wie in
           HorseThumbnailTest; der angemeldete Client wird hier nicht gebraucht,
           die Abrufe laufen bewusst als anonymer Gast. */
        $this->authenticatedClient();
    }

    protected function tearDown(): void {
        $db = Database::getInstance();
        foreach ($this->pferde as $id) {
            $db->prepare('DELETE FROM horses WHERE id = ?')->execute([$id]);
        }
        foreach ($this->kontakte as $id) {
            $db->prepare('DELETE FROM contacts WHERE id = ?')->execute([$id]);
        }
        $this->pferde = $this->kontakte = [];
        parent::tearDown();
    }

    private function seedPferd(string $name, bool $veroeffentlicht): int {
        $db = Database::getInstance();
        $db->prepare("INSERT INTO horses (name, status, is_published) VALUES (?, 'active', ?)")
            ->execute([$name, $veroeffentlicht ? 1 : 0]);
        $id = (int)$db->lastInsertId();
        $this->pferde[] = $id;

        return $id;
    }

    private function seedKontakt(string $name, bool $veroeffentlicht): int {
        $db = Database::getInstance();
        $db->prepare('INSERT INTO contacts (name, is_published) VALUES (?, ?)')
            ->execute([$name, $veroeffentlicht ? 1 : 0]);
        $id = (int)$db->lastInsertId();
        $this->kontakte[] = $id;

        return $id;
    }

    /**
     * Der VOLLE Seiten-Render, mit einem Suchbegriff ohne Treffer: So wird
     * keine einzige Karte gerendert, und kein Kartentext kann eine Zusicherung
     * über die Vorschlagslisten verfälschen.
     */
    private function katalogSeite(): string {
        $antwort = $this->newClient()->get('/katalog?search=' . urlencode('KeinTreffer' . uniqid()));
        $this->assertSame(200, $antwort->statusCode,
            'Der Katalog muss 200 liefern - ein 500er sähe wie eine leere Liste aus. Body: '
            . substr($antwort->body, 0, 300));

        return $antwort->body;
    }

    /**
     * Schneidet GENAU einen `<datalist id="…">`-Block heraus und liefert die
     * dekodierten Werte. Bricht ab, wenn der Block fehlt.
     *
     * @return array<int, string>
     */
    private function datalistWerte(string $rumpf, string $id): array {
        $treffer = preg_match(
            '#<datalist id="' . preg_quote($id, '#') . '">(.*?)</datalist>#s',
            $rumpf,
            $block
        );
        if ($treffer !== 1) {
            self::fail("Die Datalist '{$id}' steht nicht im Seiten-HTML - jede Zusicherung über "
                . 'ihren Inhalt liefe gegen eine leere Menge und wäre grundlos grün.');
        }

        preg_match_all('/value="([^"]*)"/', $block[1], $werte);

        return array_map(
            static fn(string $v): string => html_entity_decode($v, ENT_QUOTES, 'UTF-8'),
            $werte[1]
        );
    }

    /**
     * Die Stationsliste folgt der Veröffentlichung BEIDER Seiten: des Kontakts
     * und des Pferdes, das ihn zur Station macht.
     *
     * Der Pferde-Bezug ist kein Beiwerk. Ohne ihn wäre die Vorschlagsliste ein
     * Existenz-Orakel für zurückgehaltene Datensätze - dieselbe Überlegung wie
     * bei #121/#151.
     */
    public function testDieStationslisteFolgtBeidenVeroeffentlichungen(): void {
        $name = 'Dropdownstation ' . uniqid();
        $kontakt = $this->seedKontakt($name, false);
        $pferd = $this->seedPferd('Stationspferd ' . uniqid(), true);
        Database::getInstance()
            ->prepare('UPDATE horses SET breeding_station_id = ?, breeding_station = ? WHERE id = ?')
            ->execute([$kontakt, $name, $pferd]);

        $this->assertNotContains($name, $this->datalistWerte($this->katalogSeite(), 'station_list'),
            'Ein unveröffentlichter Kontakt darf nicht vorgeschlagen werden.');

        // Positiv-Gegenprobe: Ohne sie wäre die Zusicherung oben auch bei einer
        // völlig kaputten Liste grün.
        Database::getInstance()->prepare('UPDATE contacts SET is_published = 1 WHERE id = ?')->execute([$kontakt]);
        $this->assertContains($name, $this->datalistWerte($this->katalogSeite(), 'station_list'),
            'Veröffentlicht und an einem veröffentlichten Pferd: muss vorgeschlagen werden.');

        // Und die andere Hälfte: Das Pferd zurückziehen nimmt den Vorschlag mit.
        Database::getInstance()->prepare('UPDATE horses SET is_published = 0 WHERE id = ?')->execute([$pferd]);
        $this->assertNotContains($name, $this->datalistWerte($this->katalogSeite(), 'station_list'),
            'Hängt der Kontakt nur noch an unveröffentlichten Pferden, hat er im Vorschlag nichts zu suchen.');
    }

    /**
     * Dieselbe Zusicherung für die Personenliste - und ausdrücklich gegen
     * BEIDE Listen, die sie speist. Eine Änderung, die nur eine von beiden
     * füllt, fiele sonst niemandem auf.
     */
    public function testDiePersonenlisteFolgtVeroeffentlichungUndZuordnung(): void {
        $name = 'Dropdownperson ' . uniqid();
        $kontakt = $this->seedKontakt($name, false);
        $pferd = $this->seedPferd('Personenpferd ' . uniqid(), true);
        $db = Database::getInstance();
        $db->prepare("INSERT INTO horse_persons (horse_id, contact_id, role) VALUES (?, ?, 'breeder')")
            ->execute([$pferd, $kontakt]);

        foreach (['breeder_list', 'owner_list'] as $liste) {
            $this->assertNotContains($name, $this->datalistWerte($this->katalogSeite(), $liste),
                "Unveröffentlicht: darf nicht in {$liste} stehen.");
        }

        $db->prepare('UPDATE contacts SET is_published = 1 WHERE id = ?')->execute([$kontakt]);
        $rumpf = $this->katalogSeite();
        foreach (['breeder_list', 'owner_list'] as $liste) {
            $this->assertContains($name, $this->datalistWerte($rumpf, $liste),
                "Veröffentlicht: muss in {$liste} stehen.");
        }

        $db->prepare('UPDATE horses SET is_published = 0 WHERE id = ?')->execute([$pferd]);
        $this->assertNotContains($name, $this->datalistWerte($this->katalogSeite(), 'breeder_list'),
            'Ein zurückgezogenes Pferd nimmt den Vorschlag mit.');

        // Die Zuordnung selbst löschen: derselbe Effekt, anderer Weg.
        $db->prepare('UPDATE horses SET is_published = 1 WHERE id = ?')->execute([$pferd]);
        $this->assertContains($name, $this->datalistWerte($this->katalogSeite(), 'breeder_list'));
        $db->prepare('DELETE FROM horse_persons WHERE horse_id = ?')->execute([$pferd]);
        $this->assertNotContains($name, $this->datalistWerte($this->katalogSeite(), 'breeder_list'),
            'Ohne Zuordnung zu einem veröffentlichten Pferd kein Vorschlag.');
    }

    /**
     * Der Aktualitätstest, bewusst als eigene Methode.
     *
     * Er hält fest, dass zwischen „Kontakt zurückgezogen" und „Name
     * verschwunden" KEINE Frist liegt. Das ist die Zusicherung, die einen
     * Zwischenspeicher mit Laufzeit hier verbietet: Der typische Anlass ist ein
     * DSGVO-Widerspruch, und eine Liste, die den Namen noch Minuten später
     * zeigt, ist genau das, was der Betreiber gerade abgestellt hat.
     */
    public function testEinZurueckgezogenerKontaktVerschwindetOhneVerzoegerung(): void {
        $name = 'Dropdownwiderspruch ' . uniqid();
        $kontakt = $this->seedKontakt($name, true);
        $pferd = $this->seedPferd('Widerspruchspferd ' . uniqid(), true);
        $db = Database::getInstance();
        $db->prepare("INSERT INTO horse_persons (horse_id, contact_id, role) VALUES (?, ?, 'owner')")
            ->execute([$pferd, $kontakt]);

        $this->assertContains($name, $this->datalistWerte($this->katalogSeite(), 'owner_list'),
            'Vorbedingung: der Name muss zunächst dastehen.');

        $db->prepare('UPDATE contacts SET is_published = 0 WHERE id = ?')->execute([$kontakt]);
        $this->assertNotContains($name, $this->datalistWerte($this->katalogSeite(), 'owner_list'),
            'Sofort, ohne jede Wartezeit.');

        // Und der Papierkorb-Weg, der andere Grund für dasselbe Ergebnis.
        $db->prepare('UPDATE contacts SET is_published = 1 WHERE id = ?')->execute([$kontakt]);
        $this->assertContains($name, $this->datalistWerte($this->katalogSeite(), 'owner_list'));
        $db->prepare('UPDATE contacts SET deleted_at = NOW() WHERE id = ?')->execute([$kontakt]);
        $this->assertNotContains($name, $this->datalistWerte($this->katalogSeite(), 'owner_list'),
            'Ein Kontakt im Papierkorb wird nicht mehr vorgeschlagen.');
    }

    /**
     * Wie datalistWerte(), für das Farb-Auswahlfeld. Bricht ab, wenn der
     * Block fehlt; die leere Option "alle Farben" fällt weg.
     *
     * @return array<int, string>
     */
    private function farbWerte(string $rumpf): array {
        if (preg_match('#<select id="filter-q-color"[^>]*>(.*?)</select>#s', $rumpf, $block) !== 1) {
            self::fail('Das Farb-Auswahlfeld steht nicht im Seiten-HTML.');
        }
        preg_match_all('/value="([^"]*)"/', $block[1], $werte);

        return array_values(array_filter(array_map(
            static fn(string $v): string => html_entity_decode($v, ENT_QUOTES, 'UTF-8'),
            $werte[1]
        ), static fn(string $v): bool => $v !== ''));
    }

    /**
     * Farbe und Rasse nur aus veröffentlichten Pferden (Audit N12). Beide
     * sind Freitext; ein Wert, den nur ein zurückgehaltenes Pferd trägt,
     * verriet dessen Existenz.
     */
    public function testFarbeUndRasseNurVonVeroeffentlichtenPferden(): void {
        $u = uniqid();
        $db = Database::getInstance();
        $oeffentlich = $this->seedPferd("Farbpferd pub {$u}", true);
        $intern = $this->seedPferd("Farbpferd intern {$u}", false);
        $setze = $db->prepare('UPDATE horses SET color = ?, breed = ? WHERE id = ?');
        $setze->execute(["Farbe-pub-{$u}", "Rasse-pub-{$u}", $oeffentlich]);
        $setze->execute(["Farbe-intern-{$u}", "Rasse-intern-{$u}", $intern]);

        $rumpf = $this->katalogSeite();
        $farben = $this->farbWerte($rumpf);
        $rassen = $this->datalistWerte($rumpf, 'breed_list');
        $this->assertContains("Farbe-pub-{$u}", $farben);
        $this->assertContains("Rasse-pub-{$u}", $rassen);
        $this->assertNotContains("Farbe-intern-{$u}", $farben, 'Farbe eines unveröffentlichten Pferds');
        $this->assertNotContains("Rasse-intern-{$u}", $rassen, 'Rasse eines unveröffentlichten Pferds');
        $this->assertStringNotContainsString("-intern-{$u}", $rumpf);

        // Gegenprobe: veröffentlicht erscheint der Wert.
        $db->prepare('UPDATE horses SET is_published = 1 WHERE id = ?')->execute([$intern]);
        $rumpf = $this->katalogSeite();
        $this->assertContains("Farbe-intern-{$u}", $this->farbWerte($rumpf));
        $this->assertContains("Rasse-intern-{$u}", $this->datalistWerte($rumpf, 'breed_list'));
    }

    /**
     * Ohne horses.view der Gast-Gruppe gibt es keine Auswahllisten (Audit
     * N12/M18) - weder Farbe und Rasse noch Kontaktnamen. Bis dahin liefen
     * sie auch bei leerem Katalog.
     */
    public function testOhneHorsesViewKeineAttributlisten(): void {
        $u = uniqid();
        $db = Database::getInstance();
        $pferd = $this->seedPferd("Listenpferd {$u}", true);
        $db->prepare('UPDATE horses SET color = ?, breed = ? WHERE id = ?')
            ->execute(["Farbe-liste-{$u}", "Rasse-liste-{$u}", $pferd]);
        $kontakt = $this->seedKontakt("Listenperson {$u}", true);
        $db->prepare("INSERT INTO horse_persons (horse_id, contact_id, role) VALUES (?, ?, 'breeder')")
            ->execute([$pferd, $kontakt]);

        $admin = $this->authenticatedClient();
        $gast = $this->findBuiltinGroupId($admin, 'Gast');
        try {
            $this->assertContains("Farbe-liste-{$u}", $this->farbWerte($this->katalogSeite()), 'Vorbedingung');

            $this->setGroupPermissions($admin, $gast, ['contacts' => ['view']]);
            $rumpf = $this->katalogSeite();
            $this->assertSame([], $this->farbWerte($rumpf));
            $this->assertSame([], $this->datalistWerte($rumpf, 'breed_list'));
            $this->assertSame([], $this->datalistWerte($rumpf, 'breeder_list'));
            $this->assertSame([], $this->datalistWerte($rumpf, 'station_list'));
            foreach (["Farbe-liste-{$u}", "Rasse-liste-{$u}", "Listenperson {$u}"] as $wert) {
                $this->assertStringNotContainsString($wert, $rumpf);
            }
        } finally {
            $this->setGroupPermissions($admin, $gast, self::GUEST_DEFAULT_PERMISSIONS);
        }
    }

    /**
     * Audit N70: Die Farbe ist eine Auswahlliste aus exakten Werten, der
     * Filter verglich aber per LIKE '%…%'. "Braun" lieferte so auch
     * "Dunkelbraun" und "Braunschimmel", und % bzw. _ wirkten als Joker.
     * search=<eindeutig> grenzt auf die hier gesäten Pferde ein.
     */
    public function testFarbfilterTrifftNurDieGewaehlteFarbe(): void {
        $u = uniqid();
        $db = Database::getInstance();
        $setze = $db->prepare('UPDATE horses SET color = ? WHERE id = ?');
        $pferde = [];
        foreach (['Braun', 'Dunkelbraun', 'Braunschimmel'] as $farbe) {
            $name = "Farbfilter {$farbe} {$u}";
            $setze->execute([$farbe, $this->seedPferd($name, true)]);
            $pferde[$farbe] = $name;
        }

        $gast = $this->newClient();
        $treffer = function (string $farbe) use ($gast, $u): array {
            $antwort = $gast->get('/katalog?ajax=1&search=' . urlencode($u) . '&q_color=' . urlencode($farbe));
            $this->assertSame(200, $antwort->statusCode, 'Body: ' . substr($antwort->body, 0, 300));
            $daten = json_decode($antwort->body, true);
            $this->assertIsArray($daten);
            return $daten;
        };

        // Vorbedingung: Ohne Farbfilter sind alle drei im Katalog.
        $this->assertSame(3, $treffer('')['count']);

        foreach (['Braun', 'braun', 'BRAUN'] as $wert) {
            $daten = $treffer($wert);
            $this->assertSame(1, $daten['count'], "q_color={$wert} darf nur das Pferd mit genau dieser Farbe liefern");
            $this->assertStringContainsString(htmlspecialchars($pferde['Braun']), $daten['cards_html']);
            $this->assertStringNotContainsString(htmlspecialchars($pferde['Dunkelbraun']), $daten['cards_html']);
            $this->assertStringNotContainsString(htmlspecialchars($pferde['Braunschimmel']), $daten['cards_html']);
        }

        $this->assertSame(1, $treffer('Braunschimmel')['count'], 'Gegenprobe: die längere Farbe trifft sich selbst');
        $this->assertSame(0, $treffer('%')['count'], '% darf kein Jokerzeichen sein');
        $this->assertSame(0, $treffer('Brau_')['count'], '_ darf kein Jokerzeichen sein');
        $this->assertSame(0, $treffer('brau')['count'], 'Teilwerte treffen nicht mehr');

        // Voller Seiten-Render: dieselbe Grenze, und die Auswahl bleibt markiert.
        $seite = $gast->get('/katalog?search=' . urlencode($u) . '&q_color=Braun');
        $this->assertSame(200, $seite->statusCode);
        $this->assertStringContainsString(htmlspecialchars($pferde['Braun']), $seite->body);
        $this->assertStringNotContainsString(htmlspecialchars($pferde['Dunkelbraun']), $seite->body);
        $this->assertStringNotContainsString(htmlspecialchars($pferde['Braunschimmel']), $seite->body);
    }
}
