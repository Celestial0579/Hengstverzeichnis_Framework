<?php
// tests/Functional/CatalogContactVisibilityTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Der Katalog beachtet das Gast-Recht „Kontakte → Lesen“ (Audit M18).
 *
 * Pferdeseite und Kontaktseite blendeten Züchter, Besitzer und
 * Deckstationen ohne `contacts.view` aus (HorseDetailStationVisibilityTest,
 * ContactPublicTest). /katalog prüfte das Recht nie: Die Namen standen auf
 * jeder Karte, beim Nachladen, in den Vorschlagslisten der Filter (sogar ohne
 * `horses.view`), und die Kontaktfilter taugten als Namens-Orakel.
 *
 * Ohne das Recht gilt jetzt die Kontaktsperre aus HorseSearchSql: Die Namen
 * kommen gar nicht erst an, und die Kontaktfilter treffen nichts
 * (fail-closed). Ein vorgefilterter Aufruf bekommt einen Hinweis, statt
 * kommentarlos „Keine Treffer“ zu zeigen.
 *
 * Wie in den Schwester-Tests folgt jeder Abwesenheit eine Positivprobe, und
 * die Rechte der Gast-Gruppe - geteilter Zustand der ganzen Suite - werden in
 * finally zurückgesetzt.
 */
class CatalogContactVisibilityTest extends FunctionalTestCase {

    /** 24 Füllpferde, damit H und F beim Nachladen auf Seite 2 stehen. */
    private const FUELLER = 24;

    /** @var array<int, int> */
    private array $pferde = [];
    /** @var array<int, int> */
    private array $kontakte = [];

    private string $marke = '';
    private string $station = '';
    private string $zuechter = '';
    private string $besitzer = '';
    private string $halter = '';
    private string $freitext = '';

    protected function setUp(): void {
        parent::setUp();
        $this->authenticatedClient(); // Ersteinrichtung, siehe CatalogFilterOptionsTest

        $u = uniqid();
        $this->marke = "KS{$u}";
        $this->station = "Sperrstation {$u}";
        $this->zuechter = "Sperrzuechter {$u}";
        $this->besitzer = "Sperrbesitzer {$u}";
        $this->halter = "Sperrhalter {$u}";
        $this->freitext = "Freihof {$u}";

        $db = Database::getInstance();
        $kontakt = $db->prepare('INSERT INTO contacts (name, is_published) VALUES (?, 1)');
        $ids = [];
        foreach ([$this->station, $this->zuechter, $this->besitzer, $this->halter] as $name) {
            $kontakt->execute([$name]);
            $ids[$name] = (int)$db->lastInsertId();
            $this->kontakte[] = $ids[$name];
        }

        $pferd = $db->prepare(
            "INSERT INTO horses (name, status, is_published, breeding_station_id, breeding_station) VALUES (?, 'active', 1, ?, ?)"
        );
        for ($i = 0; $i < self::FUELLER; $i++) {
            $pferd->execute([sprintf('%s Aa %02d', $this->marke, $i), null, null]);
            $this->pferde[] = (int)$db->lastInsertId();
        }
        // H: verknüpfte Station samt Namenskopie in horses.breeding_station.
        $pferd->execute(["{$this->marke} Hengst", $ids[$this->station], $this->station]);
        $h = (int)$db->lastInsertId();
        $this->pferde[] = $h;
        // F: echte Freitext-Station ohne Datensatz.
        $pferd->execute(["{$this->marke} Freitext", null, $this->freitext]);
        $this->pferde[] = (int)$db->lastInsertId();

        $rolle = $db->prepare('INSERT INTO horse_persons (horse_id, contact_id, role) VALUES (?, ?, ?)');
        $rolle->execute([$h, $ids[$this->zuechter], 'breeder']);
        $rolle->execute([$h, $ids[$this->besitzer], 'owner']);
        $rolle->execute([$h, $ids[$this->halter], 'keeper']);
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

    /** @return array<int, string> */
    private function namen(): array {
        return [$this->station, $this->zuechter, $this->besitzer, $this->halter];
    }

    /** @param array<string, string|int> $query */
    private function seite(array $query): string {
        $antwort = $this->newClient()->get('/katalog?' . http_build_query($query));
        $this->assertSame(200, $antwort->statusCode, 'Katalog: ' . substr($antwort->body, 0, 300));
        return $antwort->body;
    }

    /**
     * @param array<string, string|int> $query
     * @return array<string, mixed>
     */
    private function ajax(array $query): array {
        $query['ajax'] = 1;
        $antwort = $this->newClient()->get('/katalog?' . http_build_query($query));
        $this->assertSame(200, $antwort->statusCode);
        $daten = json_decode($antwort->body, true);
        $this->assertIsArray($daten, "Kein JSON: {$antwort->body}");
        return $daten;
    }

    /** Werte einer Datalist oder null, wenn es sie nicht gibt. */
    private function datalist(string $rumpf, string $id): ?array {
        if (preg_match('#<datalist id="' . preg_quote($id, '#') . '">(.*?)</datalist>#s', $rumpf, $block) !== 1) {
            return null;
        }
        preg_match_all('/value="([^"]*)"/', $block[1], $werte);
        return array_map(static fn(string $v): string => html_entity_decode($v, ENT_QUOTES, 'UTF-8'), $werte[1]);
    }

    public function testKontakteFolgenDemGastrecht(): void {
        $admin = $this->authenticatedClient();
        $gast = $this->findBuiltinGroupId($admin, 'Gast');
        $hengst = ['q_name' => "{$this->marke} Hengst"];

        try {
            // (1) Vorbedingung mit contacts.view: Namen auf der Karte und in
            //     den Vorschlagslisten.
            $this->setGroupPermissions($admin, $gast, self::GUEST_DEFAULT_PERMISSIONS);
            $rumpf = $this->seite($hengst);
            foreach ([$this->station, $this->zuechter, $this->besitzer] as $name) {
                $this->assertStringContainsString($name, $rumpf, "Vorbedingung: {$name} auf der Karte");
            }
            $this->assertContains($this->zuechter, $this->datalist($rumpf, 'breeder_list') ?? []);
            $this->assertContains($this->besitzer, $this->datalist($rumpf, 'owner_list') ?? []);
            $this->assertContains($this->station, $this->datalist($rumpf, 'station_list') ?? []);

            // (2) Nur horses.view.
            $this->setGroupPermissions($admin, $gast, ['horses' => ['view']]);
            $rumpf = $this->seite($hengst);
            $this->assertStringContainsString("{$this->marke} Hengst", $rumpf, 'Das Pferd selbst bleibt sichtbar');
            foreach ($this->namen() as $name) {
                $this->assertStringNotContainsString($name, $rumpf, "{$name} darf ohne contacts.view nicht erscheinen");
            }
            $this->assertNull($this->datalist($rumpf, 'breeder_list'), 'Kein Züchterfeld ohne contacts.view');
            $this->assertNull($this->datalist($rumpf, 'owner_list'), 'Kein Besitzerfeld ohne contacts.view');
            $this->assertSame([], $this->datalist($rumpf, 'station_list'), 'Das Stationsfeld bleibt, seine Liste ist leer');
            $this->assertStringNotContainsString('catalog-contact-filter-note', $rumpf, 'Ohne Kontaktfilter kein Hinweis');
            $this->assertStringContainsString($this->freitext, $this->seite(['q_name' => "{$this->marke} Freitext"]),
                'Eine Freitext-Station ist kein Kontakt und bleibt sichtbar');

            // (3) Nachladen: dieselbe Regel, auch beim Anhängen von Seite 2.
            $daten = $this->ajax($hengst);
            $this->assertSame(1, $daten['count']);
            foreach ($this->namen() as $name) {
                $this->assertStringNotContainsString($name, $daten['cards_html']);
            }
            $daten = $this->ajax(['q_name' => $this->marke, 'append' => 1, 'page' => 2]);
            $this->assertStringContainsString("{$this->marke} Hengst", $daten['cards_html'], 'Seite 2 trägt H (Positivprobe)');
            foreach ($this->namen() as $name) {
                $this->assertStringNotContainsString($name, $daten['cards_html']);
            }

            // (4) Kein Orakel: Jeder Kontaktfilter trifft nichts.
            foreach ([
                'q_breeder' => $this->zuechter,
                'q_owner' => $this->besitzer,
                'q_keeper' => $this->halter,
                'q_station' => $this->station,
                'search' => $this->zuechter,
            ] as $filter => $wert) {
                $this->assertSame(0, $this->ajax([$filter => $wert])['count'], "{$filter} trifft ohne contacts.view nichts");
            }
            $this->assertSame(1, $this->ajax(['q_station' => $this->freitext])['count'], 'Freitext-Station trifft weiter');
            $this->assertSame(1, $this->ajax(['search' => $this->freitext])['count'], 'auch über den Suchbegriff');

            // (5) Vorgefilterter Aufruf, etwa aus einer Einbettung: Hinweis
            //     statt kommentarloser Leere, der Filterwert wird nicht
            //     wiederholt.
            $rumpf = $this->seite(['embed' => 1, 'q_breeder' => $this->zuechter]);
            $this->assertStringContainsString('catalog-contact-filter-note', $rumpf, 'Hinweis auf den gesperrten Filter');
            $this->assertStringNotContainsString($this->zuechter, $rumpf);

            // (6) Weder horses.view noch contacts.view: keine einzige
            //     Vorschlagsoption, kein Name.
            $this->setGroupPermissions($admin, $gast, []);
            $rumpf = $this->seite(['search' => 'KeinTreffer' . uniqid()]);
            $this->assertSame([], $this->datalist($rumpf, 'station_list'));
            $this->assertSame([], $this->datalist($rumpf, 'breed_list'));
            foreach ($this->namen() as $name) {
                $this->assertStringNotContainsString($name, $rumpf);
            }

            // Gegenprobe: Mit wiederhergestelltem Recht trifft der Filter.
            $this->setGroupPermissions($admin, $gast, self::GUEST_DEFAULT_PERMISSIONS);
            $this->assertSame(1, $this->ajax(['q_breeder' => $this->zuechter])['count']);
            $this->assertStringContainsString($this->zuechter, $this->ajax($hengst)['cards_html']);
        } finally {
            $this->setGroupPermissions($admin, $gast, self::GUEST_DEFAULT_PERMISSIONS);
        }
    }
}
