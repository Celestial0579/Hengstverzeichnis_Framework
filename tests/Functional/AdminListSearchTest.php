<?php
// tests/Functional/AdminListSearchTest.php

namespace Tests\Functional;

use App\Database;
use Tests\Support\HttpClient;

/**
 * HTTP-Funktionstests für Suche und Seitenblättern der Admin-Listen (Pferde
 * und Kontakte).
 *
 * Der eigentliche Prüfstein ist die GRENZE zwischen den beiden Kontexten:
 * Pferde- und Katalogsuche teilen sich seit dieser Änderung dieselben
 * Bausteine (App\Service\HorseSearchCriteria zum Lesen der Anfrage,
 * App\Service\HorseSearchSql zum Erzeugen der Klausel), die die
 * Sichtbarkeitsregeln des öffentlichen Katalogs (#121/#122/#151) an einem
 * einzigen Schalter führen.
 * Jede Zusicherung existiert deshalb doppelt: Der Admin MUSS den
 * unveröffentlichten Datensatz finden, der anonyme Katalog darf ihn WEITERHIN
 * NICHT finden. Fiele der Schalter irgendwann weg, würde genau eine der
 * beiden Hälften rot - und zwar sofort die richtige.
 *
 * Aus den drei Listen sind mit #336 zwei geworden: `persons` und
 * `breeding_stations` liegen in `contacts`, und /admin/persons wie
 * /admin/breeding-stations leiten dauerhaft auf /admin/contacts um. Die
 * Suchmaske der einen Liste ist die VEREINIGUNG der beiden alten - deshalb
 * werden hier beide Hälften geprüft: der Ansprechpartner (`q_contact`) konnte
 * bisher nur die Stationssuche, "nur Züchter" (`q_breeder_only`) nur die
 * Personensuche. Was durch das Zusammenlegen verlorenginge, wäre nicht die
 * Liste, sondern der jeweils andere Filter.
 *
 * Seeding direkt in der DB (analog CatalogFilterVisibilityTest): Gegenstand
 * ist das LESE-Verhalten der Listen, nicht die Formulare - der direkte Insert
 * spart je Test einen vollen Login+2FA-Roundtrip.
 */
class AdminListSearchTest extends FunctionalTestCase {

    /**
     * Bereits eingeloggte Admin-Sitzung. Sie wird in setUp() geholt, nicht
     * mitten im Test: Der erste authentifizierte Aufruf provisioniert die
     * Anwendung (Setup-Wizard, Schema). Wird diese Klasse als erste
     * ausgeführt, liefe ein Seed-Insert sonst gegen eine leere Datenbank -
     * ein Fehlschlag, der nur von der Reihenfolge der Testklassen abhinge.
     */
    private HttpClient $admin;

    /** @var array<int, int> */
    private array $seededHorseIds = [];
    /** @var array<int, int> */
    private array $seededContactIds = [];

    protected function setUp(): void {
        parent::setUp();
        $this->admin = $this->authenticatedClient();
    }

    /**
     * Aufräumen auch nach fehlgeschlagenen Tests (tearDown läuft immer) -
     * zurückbleibende Datensätze würden sonst die Trefferzahlen und
     * Seitenzahlen späterer Läufe auf der geteilten Test-DB verfälschen.
     *
     * Erst die Pferde, dann die Kontakte: `horse_persons` hängt an beiden,
     * und Zeilen in `contact_id_map` verschwinden über den Fremdschlüssel
     * mit dem Kontakt.
     */
    protected function tearDown(): void {
        $db = Database::getInstance();
        foreach ($this->seededHorseIds as $id) {
            $db->prepare("DELETE FROM horses WHERE id = ?")->execute([$id]);
        }
        foreach ($this->seededContactIds as $id) {
            $db->prepare("DELETE FROM contacts WHERE id = ?")->execute([$id]);
        }
        $this->seededHorseIds = $this->seededContactIds = [];
        parent::tearDown();
    }

    // ---------------------------------------------------------------- Pferde

    public function testHorseSearchNarrowsTheList(): void {
        $treffer = $this->token();
        $daneben = $this->token();
        $trefferName = "Suchpferd {$treffer}";
        $danebenName = "Anderes Pferd {$daneben}";
        $this->seedHorse($trefferName, true);
        $this->seedHorse($danebenName, true);

        $body = $this->listBody($this->admin, '/admin/horses?search=' . urlencode($treffer));

        $this->assertStringContainsString(
            htmlspecialchars($trefferName),
            $body,
            'Das passende Pferd muss in der gefilterten Verwaltungsliste stehen'
        );
        $this->assertStringNotContainsString(
            htmlspecialchars($danebenName),
            $body,
            'Ein nicht passendes Pferd darf in der gefilterten Liste NICHT stehen'
        );
    }

    public function testHorseBirthYearRangeFiltersBothDirections(): void {
        $token = $this->token();
        $altName = "Jahrgangspferd alt {$token}";
        $neuName = "Jahrgangspferd neu {$token}";
        $altId = $this->seedHorse($altName, true);
        $neuId = $this->seedHorse($neuName, true);
        $db = Database::getInstance();
        $db->prepare("UPDATE horses SET birth_year = 1990 WHERE id = ?")->execute([$altId]);
        $db->prepare("UPDATE horses SET birth_year = 2010 WHERE id = ?")->execute([$neuId]);

        $abZweitausend = $this->listBody($this->admin, '/admin/horses?search=' . urlencode($token) . '&birth_year_from=2000');
        $this->assertStringContainsString(htmlspecialchars($neuName), $abZweitausend);
        $this->assertStringNotContainsString(
            htmlspecialchars($altName),
            $abZweitausend,
            'birth_year_from=2000 darf den Jahrgang 1990 nicht mehr zeigen'
        );

        $bisZweitausend = $this->listBody($this->admin, '/admin/horses?search=' . urlencode($token) . '&birth_year_to=2000');
        $this->assertStringContainsString(htmlspecialchars($altName), $bisZweitausend);
        $this->assertStringNotContainsString(
            htmlspecialchars($neuName),
            $bisZweitausend,
            'birth_year_to=2000 darf den Jahrgang 2010 nicht mehr zeigen'
        );
    }

    /**
     * Der Züchter-Filter im Admin - und zugleich die Kernzusicherung dieser
     * Änderung: Er trifft auch auf einen UNVERÖFFENTLICHTEN Kontakt. Wer die
     * Verwaltung sieht, soll gerade die Datensätze finden, die noch nicht
     * freigegeben sind.
     */
    public function testAdminBreederFilterFindsUnpublishedBreeder(): void {
        $token = $this->token();
        $zuechterName = "Unveroeffentlichter Zuechter {$token}";
        $pferdName = "Pferd des stillen Zuechters {$token}";
        $fremdName = "Pferd ohne Zuechter {$token}";

        $contactId = $this->seedContact($zuechterName, false);
        $pferdId = $this->seedHorse($pferdName, false);
        $this->seedHorse($fremdName, true);
        Database::getInstance()
            ->prepare("INSERT INTO horse_persons (horse_id, contact_id, role) VALUES (?, ?, 'breeder')")
            ->execute([$pferdId, $contactId]);

        $body = $this->listBody($this->admin, '/admin/horses?q_breeder=' . urlencode($zuechterName));

        $this->assertStringContainsString(
            htmlspecialchars($pferdName),
            $body,
            'Der Admin muss über einen UNVERÖFFENTLICHTEN Züchter suchen können - sonst findet er ausgerechnet das nicht, was er freigeben soll'
        );
        $this->assertStringNotContainsString(
            htmlspecialchars($fremdName),
            $body,
            'Der Züchter-Filter darf nicht wirkungslos sein - ein Pferd ohne diesen Züchter gehört nicht in die Trefferliste'
        );
    }

    /**
     * Gegenprobe zur vorigen Zusicherung: Derselbe unveröffentlichte Züchter
     * bleibt für den anonymen Katalog unsichtbar. Der gemeinsame Baustein darf
     * die Zusagen aus #121/#122/#151 um kein Jota gelockert haben - schon eine
     * Trefferzahl > 0 wäre ein Existenz-Orakel für den Namen.
     */
    public function testPublicCatalogStillHidesTheSameUnpublishedBreeder(): void {
        $token = $this->token();
        $zuechterName = "Stiller Zuechter Katalog {$token}";
        $pferdName = "Katalogpferd {$token}";

        $contactId = $this->seedContact($zuechterName, false);
        // Das Pferd selbst ist veröffentlicht - nur der Kontakt nicht. Genau
        // dieser Fall macht den Filter zum Orakel, wenn er nicht greift.
        $pferdId = $this->seedHorse($pferdName, true);
        $db = Database::getInstance();
        $db->prepare("INSERT INTO horse_persons (horse_id, contact_id, role) VALUES (?, ?, 'breeder')")
            ->execute([$pferdId, $contactId]);

        $gast = $this->newClient();
        $payload = $this->catalogAjax($gast, 'q_breeder=' . urlencode($zuechterName));
        $this->assertSame(
            0,
            $payload['count'],
            'q_breeder auf einen unveröffentlichten Kontakt darf im Katalog weiterhin keine Treffer melden (#121)'
        );
        $this->assertStringNotContainsString(htmlspecialchars($pferdName), $payload['cards_html']);

        // Positiv-Gegenprobe: veröffentlicht -> genau ein Treffer. Sonst wäre
        // die 0 oben nur ein kaputter Filter.
        $db->prepare("UPDATE contacts SET is_published = 1 WHERE id = ?")->execute([$contactId]);
        $payload = $this->catalogAjax($gast, 'q_breeder=' . urlencode($zuechterName));
        $this->assertSame(1, $payload['count']);
        $this->assertStringContainsString(htmlspecialchars($pferdName), $payload['cards_html']);
    }

    /** Der Admin sieht unveröffentlichte Pferde, gelöschte hingegen nicht. */
    public function testAdminListShowsUnpublishedButNotDeletedHorses(): void {
        $token = $this->token();
        $stillName = "Stilles Pferd {$token}";
        $papierkorbName = "Geloeschtes Pferd {$token}";
        $this->seedHorse($stillName, false);
        $papierkorbId = $this->seedHorse($papierkorbName, false);
        Database::getInstance()
            ->prepare("UPDATE horses SET deleted_at = NOW() WHERE id = ?")
            ->execute([$papierkorbId]);

        $body = $this->listBody($this->admin, '/admin/horses?search=' . urlencode($token));

        $this->assertStringContainsString(htmlspecialchars($stillName), $body);
        $this->assertStringNotContainsString(
            htmlspecialchars($papierkorbName),
            $body,
            'Gelöschte Pferde gehören in den Papierkorb, nicht in die Verwaltungsliste'
        );
    }

    /**
     * Blättern: Bei mehr Treffern als der Seitengröße erscheint eine zweite
     * Seite, und sie zeigt andere Zeilen als die erste.
     */
    public function testHorsePaginationShowsASecondPageWithOtherRows(): void {
        $token = $this->token();
        $anzahl = \App\Controllers\HorseController::PER_PAGE + 1;
        // Führende Nullen, damit die alphabetische Sortierung (ORDER BY name)
        // der Zählung entspricht - sonst läge "Blaetterpferd 10" vor
        // "Blaetterpferd 9" und der Test prüfte etwas anderes, als er behauptet.
        for ($i = 1; $i <= $anzahl; $i++) {
            $this->seedHorse(sprintf('Blaetterpferd %s %03d', $token, $i), true);
        }
        $erstesName = sprintf('Blaetterpferd %s %03d', $token, 1);
        $letztesName = sprintf('Blaetterpferd %s %03d', $token, $anzahl);

        $seiteEins = $this->listBody($this->admin, '/admin/horses?search=' . urlencode($token));
        $this->assertStringContainsString(htmlspecialchars($erstesName), $seiteEins);
        $this->assertStringNotContainsString(
            htmlspecialchars($letztesName),
            $seiteEins,
            'Die erste Seite darf höchstens PER_PAGE Zeilen zeigen - der Datensatz dahinter gehört auf Seite 2'
        );
        $this->assertStringContainsString('Seite 1 von 2', $seiteEins, 'Die Blätter-Leiste muss die Seitenzahl nennen');

        $seiteZwei = $this->listBody($this->admin, '/admin/horses?search=' . urlencode($token) . '&page=2');
        $this->assertStringContainsString(htmlspecialchars($letztesName), $seiteZwei);
        $this->assertStringNotContainsString(
            htmlspecialchars($erstesName),
            $seiteZwei,
            'Seite 2 muss andere Zeilen zeigen als Seite 1'
        );
        $this->assertStringContainsString('Seite 2 von 2', $seiteZwei);
    }

    /**
     * Die Blätter-Links tragen die Suche mit. Ohne das führte "Weiter" zurück
     * auf die ungefilterte Gesamtliste, und Blättern wäre wertlos.
     */
    public function testPaginationLinksKeepTheSearchTerm(): void {
        $token = $this->token();
        for ($i = 1; $i <= \App\Controllers\HorseController::PER_PAGE + 1; $i++) {
            $this->seedHorse(sprintf('Linkpferd %s %03d', $token, $i), true);
        }

        $body = $this->listBody($this->admin, '/admin/horses?search=' . urlencode($token));

        $this->assertStringContainsString(
            '/admin/horses?search=' . urlencode($token) . '&amp;page=2',
            $body,
            'Der Weiter-Link muss den Suchbegriff behalten'
        );
    }

    /**
     * Ein Klick auf die Veröffentlichungs-Leiste darf die Suche nicht
     * wegwerfen - die Leiste baute ihre Links bis dahin ohne die übrigen
     * Parameter.
     */
    public function testSearchSurvivesClickOnPublishFilter(): void {
        $token = $this->token();
        $sichtbarName = "Filterpferd sichtbar {$token}";
        $stillName = "Filterpferd still {$token}";
        $fremdName = "Filterpferd fremd " . $this->token();
        $this->seedHorse($sichtbarName, true);
        $this->seedHorse($stillName, false);
        $this->seedHorse($fremdName, false);

        $body = $this->listBody($this->admin, '/admin/horses?search=' . urlencode($token));

        $ziel = $this->extractHref($body, '/admin/horses?search=' . urlencode($token) . '&amp;published=0');
        $this->assertNotNull(
            $ziel,
            'Die Leiste "Nicht veröffentlicht" muss den Suchbegriff im Link mitführen'
        );

        $gefiltert = $this->listBody($this->admin, $ziel);
        $this->assertStringContainsString(
            htmlspecialchars($stillName),
            $gefiltert,
            'Nach dem Klick müssen die unveröffentlichten Treffer DER SUCHE erscheinen'
        );
        $this->assertStringNotContainsString(
            htmlspecialchars($sichtbarName),
            $gefiltert,
            'published=0 muss weiterhin die veröffentlichten Datensätze ausblenden'
        );
        $this->assertStringNotContainsString(
            htmlspecialchars($fremdName),
            $gefiltert,
            'Der Suchbegriff muss den Klick überleben - sonst stünde hier die ganze Liste'
        );
    }

    // -------------------------------------------------------------- Kontakte

    public function testContactSearchNarrowsTheList(): void {
        $treffer = $this->token();
        $daneben = $this->token();
        $trefferName = "Suchkontakt {$treffer}";
        $danebenName = "Anderer Kontakt {$daneben}";
        $this->seedContact($trefferName, true);
        $this->seedContact($danebenName, true);

        $body = $this->listBody($this->admin, '/admin/contacts?search=' . urlencode($treffer));

        $this->assertStringContainsString(htmlspecialchars($trefferName), $body);
        $this->assertStringNotContainsString(
            htmlspecialchars($danebenName),
            $body,
            'Ein nicht passender Kontakt darf in der gefilterten Liste NICHT stehen'
        );
    }

    /** Der Admin findet auch unveröffentlichte Kontakte - Ort-Filter als Beispiel. */
    public function testAdminFindsUnpublishedContactByCity(): void {
        $token = $this->token();
        $name = "Stiller Kontakt {$token}";
        $ort = "Stillhausen {$token}";
        $contactId = $this->seedContact($name, false);
        Database::getInstance()
            ->prepare("UPDATE contacts SET city = ? WHERE id = ?")
            ->execute([$ort, $contactId]);

        $body = $this->listBody($this->admin, '/admin/contacts?q_city=' . urlencode($ort));

        $this->assertStringContainsString(
            htmlspecialchars($name),
            $body,
            'Der Admin muss unveröffentlichte Kontakte über den Ort finden'
        );
    }

    /**
     * Der Ansprechpartner-Filter - die eine Hälfte der vereinigten Suchmaske.
     * Ihn kannte bis v0.7 nur die Stationsliste; nach dem Zusammenlegen wäre
     * er der Filter, der beim Aufräumen am ehesten unter den Tisch fiele. Bei
     * einem Betrieb steht der gesuchte Name aber oft genau dort und nicht im
     * Namensfeld.
     */
    public function testAdminFindsUnpublishedContactByContactPerson(): void {
        $token = $this->token();
        $name = "Stille Station {$token}";
        $ansprechpartner = "Frau Beispiel {$token}";
        $contactId = $this->seedContact($name, false);
        Database::getInstance()
            ->prepare("UPDATE contacts SET contact_person = ? WHERE id = ?")
            ->execute([$ansprechpartner, $contactId]);

        $body = $this->listBody($this->admin, '/admin/contacts?q_contact=' . urlencode($ansprechpartner));

        $this->assertStringContainsString(
            htmlspecialchars($name),
            $body,
            'Der Admin muss unveröffentlichte Kontakte über den Ansprechpartner finden'
        );
    }

    /**
     * "Nur Züchter" - die andere Hälfte der vereinigten Suchmaske, bis v0.7
     * nur in der Personenliste. Der Filter liest das redaktionelle Kennzeichen
     * contacts.is_breeder, nicht die Zuordnungen mit role='breeder'
     * (schema.sql: verschiedene Aussagen).
     */
    public function testContactBreederOnlyFilter(): void {
        $token = $this->token();
        $zuechterName = "Zuechterin {$token}";
        $sonstName = "Nichtzuechter {$token}";
        $zuechterId = $this->seedContact($zuechterName, true);
        $this->seedContact($sonstName, true);
        Database::getInstance()
            ->prepare("UPDATE contacts SET is_breeder = 1 WHERE id = ?")
            ->execute([$zuechterId]);

        $body = $this->listBody($this->admin, '/admin/contacts?search=' . urlencode($token) . '&q_breeder_only=1');

        $this->assertStringContainsString(htmlspecialchars($zuechterName), $body);
        $this->assertStringNotContainsString(
            htmlspecialchars($sonstName),
            $body,
            '"Nur Züchter" muss Kontakte ohne das Kennzeichen ausblenden'
        );
    }

    /**
     * Herkunftsfilter (#336): Die beiden früheren Listen bleiben als SICHT auf
     * die eine Liste erhalten, aufgelöst über `contact_id_map`. Das ist der
     * Ersatz für die getrennten Adressen /admin/persons und
     * /admin/breeding-stations - wer bisher "alle Deckstationen" ansehen
     * konnte, kann das weiterhin.
     *
     * Nach dem Umbau neu angelegte Kontakte haben keine Herkunft und
     * erscheinen nur unter "Alle"; auch das wird hier festgehalten, denn eine
     * Sicht, die stillschweigend neue Datensätze verschluckt, wäre irreführend
     * - die Liste sagt es dazu.
     */
    public function testOriginFilterSeparatesFormerPersonsAndStations(): void {
        $token = $this->token();
        $db = Database::getInstance();
        $personName = "Herkunft Person {$token}";
        $stationName = "Herkunft Station {$token}";
        $neuName = "Herkunft Neuling {$token}";

        $personId = $this->seedContact($personName, true);
        $stationId = $this->seedContact($stationName, true);
        $this->seedContact($neuName, true);

        // Die alten Kennungen sind bewusst nicht die neuen: Personen behielten
        // bei der Migration ihre ID, Deckstationen bekamen neue - hier zählt
        // allein, dass die Abbildung existiert.
        $map = $db->prepare("INSERT INTO contact_id_map (old_type, old_id, contact_id) VALUES (?, ?, ?)");
        $map->execute(['person', random_int(100000, 999999), $personId]);
        $map->execute(['station', random_int(100000, 999999), $stationId]);

        $stationen = $this->listBody($this->admin, '/admin/contacts?search=' . urlencode($token) . '&q_origin=station');
        $this->assertStringContainsString(htmlspecialchars($stationName), $stationen);
        $this->assertStringNotContainsString(
            htmlspecialchars($personName),
            $stationen,
            'Die Sicht "aus dem Deckstationsbestand" darf keine Personen zeigen'
        );
        $this->assertStringNotContainsString(
            htmlspecialchars($neuName),
            $stationen,
            'Ein nach dem Umbau angelegter Kontakt hat keine Herkunft und gehört in keine der beiden Sichten'
        );

        $personen = $this->listBody($this->admin, '/admin/contacts?search=' . urlencode($token) . '&q_origin=person');
        $this->assertStringContainsString(htmlspecialchars($personName), $personen);
        $this->assertStringNotContainsString(htmlspecialchars($stationName), $personen);

        // Ohne Filter sind alle drei da - sonst prüften die Negativ-Aussagen
        // oben nur, dass die Suche selbst nichts findet.
        $alle = $this->listBody($this->admin, '/admin/contacts?search=' . urlencode($token));
        foreach ([$personName, $stationName, $neuName] as $name) {
            $this->assertStringContainsString(htmlspecialchars($name), $alle);
        }
    }

    public function testContactPaginationShowsASecondPage(): void {
        $token = $this->token();
        $anzahl = \App\Controllers\ContactController::PER_PAGE + 1;
        for ($i = 1; $i <= $anzahl; $i++) {
            $this->seedContact(sprintf('Blaetterkontakt %s %03d', $token, $i), true);
        }
        $erstesName = sprintf('Blaetterkontakt %s %03d', $token, 1);
        $letztesName = sprintf('Blaetterkontakt %s %03d', $token, $anzahl);

        $seiteEins = $this->listBody($this->admin, '/admin/contacts?search=' . urlencode($token));
        $this->assertStringContainsString(htmlspecialchars($erstesName), $seiteEins);
        $this->assertStringNotContainsString(htmlspecialchars($letztesName), $seiteEins);

        $seiteZwei = $this->listBody($this->admin, '/admin/contacts?search=' . urlencode($token) . '&page=2');
        $this->assertStringContainsString(htmlspecialchars($letztesName), $seiteZwei);
        $this->assertStringNotContainsString(htmlspecialchars($erstesName), $seiteZwei);
    }

    /**
     * Die alten Listen-Adressen bleiben erreichbar (#336): Lesezeichen und
     * verschickte Links dürfen nicht ins Leere laufen. Dass es 301 ist und
     * nicht 302, ist Absicht - die Umleitung ist dauerhaft.
     */
    public function testLegacyListRoutesRedirectPermanently(): void {
        foreach (['/admin/persons', '/admin/breeding-stations'] as $alt) {
            $response = $this->admin->get($alt);
            $this->assertSame(301, $response->statusCode, "{$alt} muss dauerhaft weiterleiten");
            $this->assertSame('/admin/contacts', $response->location(), "{$alt} muss auf die Kontaktliste zeigen");
        }
    }

    // ----------------------------------------------------------------- Hilfen

    /** Kurze, rein alphanumerische Kennung - sie steht auch in Query-Strings. */
    private function token(): string {
        return 'sut' . bin2hex(random_bytes(5));
    }

    private function listBody(HttpClient $admin, string $path): string {
        $response = $admin->get($path);
        $this->assertSame(200, $response->statusCode, "Liste {$path} nicht erreichbar, Body: {$response->body}");
        return $response->body;
    }

    /**
     * Sucht im HTML einen Link, dessen href mit $prefix beginnt, und liefert
     * ihn dekodiert zurück (die Views schreiben &amp; statt &).
     */
    private function extractHref(string $body, string $prefix): ?string {
        if (!preg_match('/href="(' . preg_quote($prefix, '/') . '[^"]*)"/', $body, $treffer)) {
            return null;
        }
        return html_entity_decode($treffer[1], ENT_QUOTES, 'UTF-8');
    }

    /**
     * @return array{count: int, cards_html: string}
     */
    private function catalogAjax(HttpClient $gast, string $query): array {
        $response = $gast->get('/katalog?ajax=1&' . $query);
        $this->assertSame(200, $response->statusCode, "AJAX-Katalog nicht erreichbar, Body: {$response->body}");
        $payload = json_decode($response->body, true);
        $this->assertIsArray($payload, "AJAX-Katalog lieferte kein JSON, Body: {$response->body}");
        return $payload;
    }

    private function seedHorse(string $name, bool $published): int {
        $db = Database::getInstance();
        $db->prepare("INSERT INTO horses (name, status, is_published) VALUES (?, 'active', ?)")
            ->execute([$name, $published ? 1 : 0]);
        $id = (int)$db->lastInsertId();
        $this->seededHorseIds[] = $id;
        return $id;
    }

    private function seedContact(string $name, bool $published): int {
        $db = Database::getInstance();
        $db->prepare("INSERT INTO contacts (name, is_published) VALUES (?, ?)")
            ->execute([$name, $published ? 1 : 0]);
        $id = (int)$db->lastInsertId();
        $this->seededContactIds[] = $id;
        return $id;
    }
}
