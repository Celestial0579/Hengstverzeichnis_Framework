<?php
// tests/Functional/GdprManualMatchingTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Manuelle Personenzuordnung in der DSGVO-Verwaltung (#266).
 *
 * Vorher gab es bei null Automatch-Treffern KEINEN Weg, die betroffene Person
 * trotzdem zu finden: Die Anonymisieren-/Löschen-Schaltflächen steckten in
 * `if (!empty($req['matching_persons']))`, und für Auskunftsanfragen lief gar
 * kein Matching. Die Anfrage blieb dann auf `pending` liegen - bei einem
 * Verfahren, dessen ganzer Zweck die Einhaltung gesetzlicher Fristen ist, der
 * ungünstigste denkbare Ausgang.
 *
 * Die Anfragen werden hier direkt in der Datenbank angelegt, nicht über
 * /dsgvo eingereicht: Der öffentliche Weg ist seit #258 rate-limitiert, und
 * alle Functional-Tests teilen sich 127.0.0.1 (siehe DsgvoFormHelper). Dieser
 * Test braucht den öffentlichen Weg nicht - geprüft wird die Verwaltungsseite.
 */
class GdprManualMatchingTest extends FunctionalTestCase {

    /** @var array<int, int> */
    private array $seededPersonIds = [];
    /** @var array<int, int> */
    private array $seededRequestIds = [];

    protected function tearDown(): void {
        $db = Database::getInstance();
        foreach ($this->seededRequestIds as $id) {
            $db->prepare("DELETE FROM gdpr_requests WHERE id = ?")->execute([$id]);
        }
        foreach ($this->seededPersonIds as $id) {
            $db->prepare("DELETE FROM persons WHERE id = ?")->execute([$id]);
        }
        $this->seededRequestIds = $this->seededPersonIds = [];
        parent::tearDown();
    }

    /**
     * Der Kern von #266: Eine Löschanfrage, deren Name auf KEINEN Datensatz
     * passt, muss trotzdem einen Weg zur Bearbeitung anbieten - und der Block
     * muss aufgeklappt sein, weil er dann der einzige ist.
     */
    public function testDeletionRequestWithoutAutomatchStillOffersAManualSearch(): void {
        $requestId = $this->seedRequest('deletion', 'Niemand Passendes ' . uniqid(), 'kein-treffer-' . uniqid() . '@example.com');

        $body = $this->gdprPage();
        $card = $this->cardFor($body, $requestId);

        $this->assertStringContainsString(
            'Keine direkten Personeneinträge',
            $card,
            'Testaufbau kaputt: Es sollte gerade KEIN Automatch-Treffer entstehen.'
        );
        $this->assertStringContainsString(
            'gdpr-manual-search',
            $card,
            'Ohne Automatch-Treffer fehlt der manuelle Suchblock - die Anfrage bliebe unbearbeitbar.'
        );
        $this->assertMatchesRegularExpression(
            '/<details[^>]*\bopen\b/',
            $card,
            'Bei null Treffern muss der manuelle Suchblock aufgeklappt sein.'
        );
        // Die Aktionen selbst sind vorbereitet - inklusive CSRF-Token, das
        // JavaScript nicht erzeugen könnte.
        $this->assertStringContainsString('/admin/gdpr/anonymize-person', $card);
        $this->assertStringContainsString('/admin/gdpr/delete-person', $card);
        $this->assertStringContainsString('gdpr-selected-id', $card);
    }

    /**
     * Auskunftsanfragen bekamen bisher gar kein Matching (#266). Sie bekommen
     * es jetzt - aber ausdrücklich OHNE Löschen/Anonymisieren: Art. 15 DSGVO
     * verlangt Auskunft, nicht Löschung. Die falsche Rechtsfolge anzubieten
     * wäre schlimmer als gar keine Schaltfläche.
     */
    public function testInfoRequestGetsMatchingButNeverErasureActions(): void {
        $name = 'Auskunftsuchende Person ' . uniqid();
        $personId = $this->seedPerson($name);
        $requestId = $this->seedRequest('info', $name, 'auskunft-' . uniqid() . '@example.com');

        $card = $this->cardFor($this->gdprPage(), $requestId);

        $this->assertStringContainsString(
            '(ID #' . $personId . ')',
            $card,
            'Die Auskunftsanfrage bekommt keinen Automatch-Treffer - vor #266 lief für "info" gar keine Suche.'
        );
        $this->assertStringContainsString(
            '/admin/persons/edit?id=' . $personId,
            $card,
            'Für die Auskunft fehlt der Weg zum Datensatz.'
        );
        $this->assertStringNotContainsString(
            '/admin/gdpr/delete-person',
            $card,
            'Eine Auskunftsanfrage darf keine Löschaktion anbieten.'
        );
        $this->assertStringNotContainsString(
            '/admin/gdpr/anonymize-person',
            $card,
            'Eine Auskunftsanfrage darf keine Anonymisierung anbieten.'
        );
    }

    public function testSearchFindsPersonsByNameEmailAndContactInfo(): void {
        $marker = uniqid();
        $personId = $this->seedPerson('Suchbar Meier ' . $marker, 'meier-' . $marker . '@example.com');

        foreach (['Suchbar Meier ' . $marker, 'meier-' . $marker, $marker] as $term) {
            $hits = $this->search($term);
            $ids = array_column($hits, 'id');
            $this->assertContains(
                $personId,
                $ids,
                "Die Suche nach '{$term}' findet die Person nicht."
            );
        }
    }

    /**
     * Die Vorgabe aus dem Issue-Kommentar: kein Auswahlfeld, das den kompletten
     * Personenbestand lädt (dieselbe Falle wie Addons#87). Belegt durch die
     * Mindestlänge und den Trefferdeckel.
     */
    public function testSearchIsBoundedByMinimumLengthAndResultLimit(): void {
        $this->assertSame([], $this->search('a'), 'Ein einzelnes Zeichen darf keine Trefferliste auslösen.');
        $this->assertSame([], $this->search(''), 'Eine leere Suche darf keine Trefferliste auslösen.');

        // 55 gleichnamige Personen: Der Deckel liegt bei 50.
        $marker = 'Deckelprobe' . uniqid();
        for ($i = 0; $i < 55; $i++) {
            $this->seedPerson($marker . ' Nummer ' . $i);
        }

        $hits = $this->search($marker);
        $this->assertCount(
            50,
            $hits,
            'Die Suche liefert mehr als SEARCH_LIMIT Treffer - genau die Falle aus Addons#87.'
        );
    }

    /**
     * Ein weich gelöschter Datensatz ist aus der Oberfläche verschwunden, seine
     * personenbezogenen Daten stehen aber weiter in der Tabelle. Würde die
     * Suche ihn ausblenden, entstünde die Lücke, die niemandem auffällt: kein
     * Treffer, Anfrage abgehakt, Daten weiterhin da.
     */
    public function testSearchAlsoFindsSoftDeletedPersonsAndMarksThem(): void {
        $marker = 'Weichgeloescht' . uniqid();
        $personId = $this->seedPerson($marker . ' Person');
        Database::getInstance()
            ->prepare("UPDATE persons SET deleted_at = NOW() WHERE id = ?")
            ->execute([$personId]);

        $hits = $this->search($marker);
        $this->assertCount(1, $hits, 'Ein weich gelöschter Datensatz muss für eine Löschanfrage auffindbar bleiben.');
        $this->assertTrue($hits[0]['is_deleted'], 'Der Treffer muss als bereits gelöscht gekennzeichnet sein.');
    }

    /**
     * Die Antwort enthält personenbezogene Daten. Der Konstruktor von
     * GdprController erzwingt Anmeldung und Admin-Rolle - dass die neue Action
     * das erbt, ist eine Zusicherung, kein Zufall.
     */
    public function testSearchIsNotReachableWithoutAdminSession(): void {
        $response = $this->newClient()->get('/admin/gdpr/search-persons?q=test');

        $this->assertNotSame(
            200,
            $response->statusCode,
            'Die Personensuche liefert PII an eine nicht angemeldete Sitzung.'
        );
    }

    public function testSearchSendsNoStoreSoPiiIsNotCached(): void {
        $response = $this->adminClient()->get('/admin/gdpr/search-persons?q=zz');

        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString(
            'no-store',
            strtolower((string)$response->header('Cache-Control')),
            'PII-Antworten dürfen nicht zwischengespeichert werden.'
        );
    }

    /**
     * #318: Zwei Zeichen lösen keine Suche mehr aus.
     *
     * Ein Fragment wie "an" trifft bei 20.000 Personen praktisch den gesamten
     * Bestand. Der Deckel schneidet die ANTWORT auf 50 Zeilen zurecht, die
     * Datenbank hat den Rest aber vorher trotzdem angefasst - und für die
     * Zuordnung einer DSGVO-Anfrage ist so ein Treffer ohnehin wertlos.
     */
    public function testTwoCharactersNoLongerTriggerASearch(): void {
        $marker = 'Ab' . uniqid();
        $this->seedPerson($marker . ' Kurzsuche');

        $this->assertSame([], $this->search('Ab'), 'Zwei Zeichen dürfen keine Trefferliste mehr auslösen.');
        $this->assertNotSame([], $this->search(substr($marker, 0, 5)), 'Ab drei Zeichen muss die Suche wieder greifen.');
    }

    /**
     * #318: Die Suche läuft jetzt zweistufig - erst Präfix, dann bei Bedarf
     * die teure Enthält-Suche. Beide Mengen müssen ankommen, und der
     * Präfixtreffer gehört nach vorn: Wer mit dem Suchbegriff BEGINNT, ist der
     * wahrscheinlichere Treffer.
     */
    public function testPrefixHitsComeFirstButSubstringHitsStillArrive(): void {
        $marker = 'Zwei' . uniqid();

        // Beginnt mit dem Suchbegriff -> Präfixstufe.
        $vorn = $this->seedPerson($marker . ' Praefixtreffer');
        // Enthält ihn nur -> zweite Stufe.
        $hinten = $this->seedPerson('Enthaelt ' . $marker . ' mittendrin');

        $treffer = $this->search($marker);
        $ids = array_column($treffer, 'id');

        $this->assertContains($vorn, $ids, 'Der Präfixtreffer fehlt.');
        $this->assertContains($hinten, $ids, 'Der Enthält-Treffer fehlt - die zweite Stufe greift nicht.');
        $this->assertSame(
            $vorn,
            $ids[0],
            'Der Präfixtreffer gehört an die erste Stelle.'
        );
        $this->assertSame(
            count($ids),
            count(array_unique($ids)),
            'Kein Treffer darf doppelt vorkommen - die beiden Stufen überschneiden sich.'
        );
    }

    /**
     * #318: horse_count kommt jetzt aus einer Unterabfrage statt aus
     * LEFT JOIN + GROUP BY. Die Zahl muss dieselbe bleiben, sonst hätte der
     * Umbau die Zuordnungsanzeige der DSGVO-Maske still verfälscht.
     */
    public function testHorseCountSurvivesTheQueryRewrite(): void {
        $db = Database::getInstance();
        $marker = 'Zaehlprobe' . uniqid();

        $ohne = $this->seedPerson($marker . ' ohne Pferde');
        $mit = $this->seedPerson($marker . ' mit zwei Pferden');

        $pferdIds = [];
        foreach (['A', 'B'] as $suffix) {
            $db->prepare("INSERT INTO horses (name, sex, is_published, created_at) VALUES (?, 'stallion', 0, NOW())")
               ->execute(["{$marker} Pferd {$suffix}"]);
            $pferdIds[] = (int)$db->lastInsertId();
        }
        foreach ($pferdIds as $pferdId) {
            $db->prepare("INSERT INTO horse_persons (horse_id, person_id, role) VALUES (?, ?, 'owner')")
               ->execute([$pferdId, $mit]);
        }

        try {
            $treffer = array_column($this->search($marker), null, 'id');
            $this->assertSame(0, $treffer[$ohne]['horse_count'] ?? null);
            $this->assertSame(2, $treffer[$mit]['horse_count'] ?? null);
        } finally {
            foreach ($pferdIds as $pferdId) {
                $db->prepare("DELETE FROM horses WHERE id = ?")->execute([$pferdId]);
            }
        }
    }

    // ------------------------------------------------------------------

    private function adminClient(): \Tests\Support\HttpClient {
        return $this->authenticatedClient();
    }

    private function gdprPage(): string {
        $response = $this->adminClient()->get('/admin/gdpr');
        $this->assertSame(200, $response->statusCode, '/admin/gdpr lieferte nicht 200.');
        return $response->body;
    }

    /** @return array<int, array<string, mixed>> */
    private function search(string $q): array {
        $response = $this->adminClient()->get('/admin/gdpr/search-persons?q=' . urlencode($q));
        $this->assertSame(200, $response->statusCode, "Suche nach '{$q}' lieferte nicht 200.");
        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded, "Suche nach '{$q}' lieferte kein JSON-Array: {$response->body}");
        return $decoded;
    }

    /**
     * Schneidet die Karte einer einzelnen Anfrage aus der Seite. Ohne das
     * würden Zusicherungen versehentlich von einer FREMDEN Anfrage erfüllt,
     * die zufällig auf derselben Seite steht.
     */
    private function cardFor(string $body, int $requestId): string {
        // Anker ist das data-Attribut des manuellen Suchblocks, nicht das
        // request_id-Formularfeld: Letzteres gibt es bei Auskunftsanfragen
        // bewusst nicht, weil dort keine Lösch-/Anonymisierungsformulare stehen.
        $needle = 'data-request-id="' . $requestId . '"';
        $pos = strpos($body, $needle);
        $this->assertNotFalse($pos, "Anfrage #{$requestId} steht nicht auf der ersten Seite von /admin/gdpr.");

        // Kartengrenzen: von der vorhergehenden bis zur nächsten Karte.
        $start = strrpos(substr($body, 0, $pos), '<div style="border: 1px solid #e0e0e0');
        $this->assertNotFalse($start, 'Kartenanfang nicht gefunden.');
        $next = strpos($body, '<div style="border: 1px solid #e0e0e0', $pos);

        return $next === false ? substr($body, $start) : substr($body, $start, $next - $start);
    }

    private function seedPerson(string $name, ?string $email = null): int {
        $db = Database::getInstance();
        $db->prepare("INSERT INTO persons (name, email, is_published) VALUES (?, ?, 1)")
           ->execute([$name, $email]);
        $id = (int)$db->lastInsertId();
        $this->seededPersonIds[] = $id;
        return $id;
    }

    private function seedRequest(string $type, string $name, string $email): int {
        $db = Database::getInstance();
        $db->prepare(
            "INSERT INTO gdpr_requests (name, email, request_type, message, status, created_at)
             VALUES (?, ?, ?, 'Testanfrage', 'pending', NOW())"
        )->execute([$name, $email, $type]);
        $id = (int)$db->lastInsertId();
        $this->seededRequestIds[] = $id;
        return $id;
    }
}
