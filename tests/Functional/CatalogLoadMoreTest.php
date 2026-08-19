<?php
// tests/Functional/CatalogLoadMoreTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Nahtloses Nachladen im Katalog (#264).
 *
 * Der AJAX-Pfad ersetzte die Karten bisher ausnahmslos - er war für den
 * Filterwechsel gebaut. Fürs Nachladen kommen zwei Dinge dazu, und beide
 * können still danebengehen:
 *
 * 1. Die Antwort muss verraten, ob es überhaupt weitere Seiten gibt. Ohne
 *    page/total_pages müsste der Client CATALOG_PER_PAGE nachbauen und läge
 *    falsch, sobald der Wert sich ändert.
 * 2. Beim Anhängen darf die Seiten-Navigation NICHT mitkommen - sie steckt in
 *    derselben Teilansicht und landete sonst mitten zwischen den Karten.
 *
 * Und der Weg ohne JavaScript muss unangetastet bleiben: Die serverseitige
 * Seiten-Navigation ist dort der einzige Weg durch den Katalog.
 */
class CatalogLoadMoreTest extends FunctionalTestCase {

    /** Muss über CATALOG_PER_PAGE (24) liegen, damit es eine zweite Seite gibt. */
    private const SEEDED = 30;

    /** @var array<int, int> */
    private array $seededHorseIds = [];

    private string $marker = '';

    protected function setUp(): void {
        parent::setUp();
        $this->marker = 'Nachladeprobe' . uniqid();

        $db = Database::getInstance();
        $stmt = $db->prepare("INSERT INTO horses (name, sex, is_published, created_at) VALUES (?, 'stallion', 1, NOW())");
        for ($i = 0; $i < self::SEEDED; $i++) {
            $stmt->execute([sprintf('%s %02d', $this->marker, $i)]);
            $this->seededHorseIds[] = (int)$db->lastInsertId();
        }
    }

    protected function tearDown(): void {
        $db = Database::getInstance();
        foreach ($this->seededHorseIds as $id) {
            $db->prepare("DELETE FROM horses WHERE id = ?")->execute([$id]);
        }
        $this->seededHorseIds = [];
        parent::tearDown();
    }

    public function testAjaxResponseReportsPaginationSoTheClientNeedNotGuess(): void {
        $data = $this->ajax(['search' => $this->marker]);

        $this->assertSame(self::SEEDED, $data['count'], 'Der Testbestand kommt nicht vollständig durch den Filter.');
        $this->assertSame(1, $data['page']);
        $this->assertSame(2, $data['total_pages'], 'Bei 30 Treffern und 24 je Seite müssen es zwei Seiten sein.');
        $this->assertTrue($data['has_more'], 'Auf Seite 1 von 2 muss has_more gesetzt sein.');

        $second = $this->ajax(['search' => $this->marker, 'page' => 2]);
        $this->assertSame(2, $second['page']);
        $this->assertFalse($second['has_more'], 'Auf der letzten Seite darf has_more nicht mehr gesetzt sein.');
    }

    /**
     * Der Kern des Anhängens: Seite 2 liefert die ÜBRIGEN Pferde, nicht noch
     * einmal dieselben. Ohne diese Zusicherung würde ein Fehler im
     * OFFSET-Pfad beim Nachladen Dubletten erzeugen, die niemandem auffallen,
     * weil die Karten gleich aussehen.
     */
    public function testSecondPageContainsTheRemainingHorsesNotTheSameOnesAgain(): void {
        $first = $this->ajax(['search' => $this->marker, 'append' => 1]);
        $second = $this->ajax(['search' => $this->marker, 'page' => 2, 'append' => 1]);

        $firstNames = $this->horseNamesIn($first['cards_html']);
        $secondNames = $this->horseNamesIn($second['cards_html']);

        $this->assertCount(24, $firstNames, 'Seite 1 sollte CATALOG_PER_PAGE Karten liefern.');
        $this->assertCount(self::SEEDED - 24, $secondNames, 'Seite 2 sollte den Rest liefern.');
        $this->assertSame(
            [],
            array_intersect($firstNames, $secondNames),
            'Seite 2 wiederholt Pferde von Seite 1 - beim Anhängen entstünden Dubletten.'
        );
    }

    /**
     * Beim Anhängen darf die Seiten-Navigation nicht mitkommen; ohne das Flag
     * muss sie weiterhin da sein, weil sie ohne JavaScript der einzige Weg ist.
     */
    public function testPaginationTravelsOnlyWithoutTheAppendFlag(): void {
        $appended = $this->ajax(['search' => $this->marker, 'append' => 1]);
        $this->assertStringNotContainsString(
            'data-catalog-pagination',
            $appended['cards_html'],
            'Beim Anhängen käme die Seiten-Navigation mit und landete zwischen den Karten.'
        );

        $replaced = $this->ajax(['search' => $this->marker]);
        $this->assertStringContainsString(
            'data-catalog-pagination',
            $replaced['cards_html'],
            'Ohne append-Flag muss die Seiten-Navigation erhalten bleiben.'
        );
    }

    /**
     * Fortschrittliche Verbesserung, nicht Voraussetzung: Ohne JavaScript darf
     * kein Bedienelement sichtbar sein, das nichts tut - und die
     * serverseitige Seiten-Navigation muss stehen bleiben.
     */
    public function testLoadMoreControlsAreHiddenUntilJavaScriptEnablesThem(): void {
        $body = $this->catalogPage(['search' => $this->marker]);

        $this->assertMatchesRegularExpression(
            '/<div id="catalog-load-more-area"[^>]*\bhidden\b/',
            $body,
            'Der Nachlade-Block muss ohne JavaScript ausgeblendet bleiben.'
        );
        $this->assertStringContainsString('id="catalog-scroll-sentinel"', $body);
        $this->assertStringContainsString(
            'data-catalog-pagination',
            $body,
            'Ohne JavaScript ist die serverseitige Seiten-Navigation der einzige Weg durch den Katalog.'
        );
        $this->assertStringContainsString(
            'data-total-pages="2"',
            $body,
            'Der Anfangszustand des Nachladens steht nicht im HTML - der Client bräuchte sonst eine Extra-Anfrage.'
        );
    }

    /**
     * Der Knopf ist die Bedienung, der Scroll-Auslöser nur Bequemlichkeit -
     * also muss der Knopf beschriftet und übersetzt sein. Ein fehlender
     * Sprachschlüssel fiele sonst erst im Betrieb als leerer Knopf auf.
     */
    public function testLoadMoreButtonIsLabelledInEveryShippedLanguage(): void {
        $keys = ['catalog.load_more', 'catalog.load_more_status', 'catalog.load_more_done', 'catalog.load_more_error'];

        foreach (glob(__DIR__ . '/../../lang/*.php') as $file) {
            $translations = require $file;
            foreach ($keys as $key) {
                $this->assertArrayHasKey(
                    $key,
                    $translations,
                    "Sprachdatei " . basename($file) . " kennt '{$key}' nicht."
                );
                $this->assertNotSame('', trim((string)$translations[$key]), basename($file) . ": '{$key}' ist leer.");
            }
            foreach (['catalog.load_more_status' => ['{loaded}', '{total}'], 'catalog.load_more_done' => ['{total}']] as $key => $placeholders) {
                foreach ($placeholders as $placeholder) {
                    $this->assertStringContainsString(
                        $placeholder,
                        (string)$translations[$key],
                        basename($file) . ": '{$key}' verliert den Platzhalter {$placeholder}."
                    );
                }
            }
        }
    }

    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    /**
     * #320: Beim Nachladen faellt das COUNT(*) weg - und die Antwort sagt das,
     * statt eine Zahl zu erfinden.
     *
     * Das COUNT lief ueber den dreifachen Selbst-JOIN und bei jedem
     * Scrollschritt erneut, obwohl der Client die Zahl laengst hat und sie
     * sich zwischen zwei Seiten derselben Treffermenge nicht aendert. Beim
     * Durchscrollen eines Bestands von 3200 Pferden sind das gut 130
     * Wiederholungen derselben Abfrage.
     *
     * has_more darf davon NICHT abhaengen: Es kommt jetzt daher, dass eine
     * Zeile mehr geholt wird als angezeigt - dieselbe Auskunft, ohne den
     * Zaehler.
     */
    public function testAppendSkipsTheHitCountButStillReportsWhetherMoreFollows(): void {
        $ersteSeite = $this->ajax(['search' => $this->marker, 'append' => 1]);

        $this->assertNull($ersteSeite['count'], 'Beim Anhaengen wird die Trefferzahl nicht ermittelt');
        $this->assertNull($ersteSeite['count_text'], 'Und dann darf auch kein Text dazu behauptet werden');
        $this->assertTrue($ersteSeite['has_more'], 'Nach Seite 1 von 2 muss es weitergehen');
        $this->assertCount(24, $this->horseNamesIn($ersteSeite['cards_html']));

        $letzteSeite = $this->ajax(['search' => $this->marker, 'page' => 2, 'append' => 1]);
        $this->assertNull($letzteSeite['count']);
        $this->assertFalse($letzteSeite['has_more'], 'Auf der letzten Seite darf has_more nicht gesetzt sein');
        $this->assertCount(self::SEEDED - 24, $this->horseNamesIn($letzteSeite['cards_html']));

        // Der volle Weg (Filterwechsel, kein append) liefert sie weiterhin -
        // sonst wuesste der Client die Zahl nie.
        $ersetzend = $this->ajax(['search' => $this->marker]);
        $this->assertSame(self::SEEDED, $ersetzend['count']);
        $this->assertNotNull($ersetzend['count_text']);
    }

    /**
     * #320: Die Zuechter-/Besitzernamen werden jetzt in einer zweiten Abfrage
     * NACH dem LIMIT aufgeloest statt ueber eine materialisierte Ableitung in
     * der paginierten Abfrage. Auf der Karte muss davon nichts fehlen - und
     * die Sichtbarkeitsregel (#121) muss dieselbe geblieben sein: ein
     * unveroeffentlichter Zuechter erscheint nicht.
     */
    public function testPersonNamesOnCardsSurviveTheSecondQueryAndKeepTheirVisibilityRule(): void {
        $db = Database::getInstance();
        $unique = uniqid();

        $db->prepare("INSERT INTO persons (name, is_published) VALUES (?, 1)")
           ->execute(["Sichtbarer Zuechter {$unique}"]);
        $sichtbar = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO persons (name, is_published) VALUES (?, 0)")
           ->execute(["Verborgener Besitzer {$unique}"]);
        $verborgen = (int)$db->lastInsertId();

        $pferdId = $this->seededHorseIds[0];
        $db->prepare("INSERT INTO horse_persons (horse_id, person_id, role) VALUES (?, ?, 'breeder')")
           ->execute([$pferdId, $sichtbar]);
        $db->prepare("INSERT INTO horse_persons (horse_id, person_id, role) VALUES (?, ?, 'owner')")
           ->execute([$pferdId, $verborgen]);

        try {
            $data = $this->ajax(['search' => $this->marker]);
            $this->assertStringContainsString(
                "Sichtbarer Zuechter {$unique}",
                $data['cards_html'],
                'Der Zuechtername gehoert weiterhin auf die Karte'
            );
            $this->assertStringNotContainsString(
                "Verborgener Besitzer {$unique}",
                $data['cards_html'],
                'Ein unveroeffentlichter Name darf im oeffentlichen Katalog nicht erscheinen (#121)'
            );
        } finally {
            $db->prepare("DELETE FROM horse_persons WHERE horse_id = ?")->execute([$pferdId]);
            $db->prepare("DELETE FROM persons WHERE id IN (?, ?)")->execute([$sichtbar, $verborgen]);
        }
    }

    private function ajax(array $query): array {
        $query['ajax'] = 1;
        $response = $this->newClient()->get('/katalog?' . http_build_query($query));
        $this->assertSame(200, $response->statusCode, '/katalog (AJAX) lieferte nicht 200.');

        $data = json_decode($response->body, true);
        $this->assertIsArray($data, "AJAX-Antwort ist kein JSON: {$response->body}");
        $this->assertTrue($data['success'] ?? false, 'AJAX-Antwort meldet keinen Erfolg.');
        return $data;
    }

    private function catalogPage(array $query): string {
        $response = $this->newClient()->get('/katalog?' . http_build_query($query));
        $this->assertSame(200, $response->statusCode, '/katalog lieferte nicht 200.');
        return $response->body;
    }

    /** @return array<int, string> */
    private function horseNamesIn(string $html): array {
        preg_match_all('/' . preg_quote($this->marker, '/') . ' \d{2}/', $html, $matches);
        return array_values(array_unique($matches[0]));
    }
}
