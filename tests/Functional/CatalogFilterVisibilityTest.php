<?php
// tests/Functional/CatalogFilterVisibilityTest.php

namespace Tests\Functional;

use App\Database;
use Tests\Support\HttpClient;

/**
 * HTTP-Funktionstests für die Sichtbarkeitsgrenzen der anonymen Katalogfilter
 * (#223): q_breeder/q_owner dürfen nie auf unveröffentlichte Personen treffen
 * (#121) und q_station nie auf den Namen einer unveröffentlichten Deckstation
 * (#151) - sonst wird die bloße Trefferzahl des AJAX-Katalogs zum
 * Existenz-Orakel für Namen, die der Betreiber bewusst depubliziert hat
 * (typischer Fall: DSGVO-Widerspruch). Zusätzlich die bisher nirgends direkt
 * abgesicherte Grundzusicherung, dass ein unveröffentlichtes Pferd weder im
 * Katalog noch auf der Detailseite erscheint.
 *
 * Jeder Negativ-Prüfung folgt eine Positiv-Gegenprobe (Datensatz
 * veröffentlichen -> Treffer): Sie belegt, dass die 0 wirklich von der
 * Sichtbarkeitssperre kommt und nicht von einem funktionslosen Filter.
 *
 * Seeding direkt in der DB (analog GdprEraseTest): Die Tests prüfen das
 * LESE-Verhalten des öffentlichen Katalogs, nicht die Admin-Formulare - der
 * direkte Insert spart pro Test einen vollen Login+2FA-Roundtrip. Die
 * Katalog-Requests selbst laufen bewusst als anonymer Gast, denn genau dessen
 * Sicht ist der Gegenstand der Zusicherung.
 */
class CatalogFilterVisibilityTest extends FunctionalTestCase {

    /** @var array<int, int> */
    private array $seededHorseIds = [];
    /** @var array<int, int> */
    private array $seededPersonIds = [];
    /** @var array<int, int> */
    private array $seededStationIds = [];

    /**
     * Aufräumen auch nach fehlgeschlagenen Tests (tearDown läuft immer):
     * Zurück bleibende unveröffentlichte Testdatensätze würden sonst die
     * Trefferzahlen späterer Läufe auf der geteilten Test-DB verfälschen.
     * horse_persons räumt der ON DELETE CASCADE der Pferde/Personen mit ab.
     */
    protected function tearDown(): void {
        $db = Database::getInstance();
        foreach ($this->seededHorseIds as $id) {
            $db->prepare("DELETE FROM horses WHERE id = ?")->execute([$id]);
        }
        foreach ($this->seededPersonIds as $id) {
            $db->prepare("DELETE FROM persons WHERE id = ?")->execute([$id]);
        }
        foreach ($this->seededStationIds as $id) {
            $db->prepare("DELETE FROM breeding_stations WHERE id = ?")->execute([$id]);
        }
        $this->seededHorseIds = $this->seededPersonIds = $this->seededStationIds = [];
        parent::tearDown();
    }

    public function testBreederFilterNeverMatchesUnpublishedPersons(): void {
        $this->assertPersonFilterRespectsPublicationFlag('breeder', 'q_breeder');
    }

    public function testOwnerFilterNeverMatchesUnpublishedPersons(): void {
        $this->assertPersonFilterRespectsPublicationFlag('owner', 'q_owner');
    }

    /**
     * Gemeinsamer Ablauf für Züchter- und Besitzer-Filter: Die beiden
     * EXISTS-Unterabfragen in PublicController::catalog() sind strukturgleich,
     * unterscheiden sich aber im hartkodierten role-Wert - beide Rollen müssen
     * deshalb einzeln belegt sein, ein Copy-Paste-Fehler in nur einer der
     * Unterabfragen soll rot werden.
     */
    private function assertPersonFilterRespectsPublicationFlag(string $role, string $queryParam): void {
        $db = Database::getInstance();
        $unique = uniqid();
        $personName = "Orakel Person {$role} {$unique}";
        $horseName = "Orakel Pferd {$role} {$unique}";

        $personId = $this->seedPerson($personName, false);
        $horseId = $this->seedHorse($horseName, true);
        $db->prepare("INSERT INTO horse_persons (horse_id, person_id, role) VALUES (?, ?, ?)")
            ->execute([$horseId, $personId, $role]);

        // 1. Unveröffentlichte Person: Der Filter darf weder eine Trefferzahl
        // noch die zugeordneten Pferdekarten liefern - schon count > 0 allein
        // bestätigte einem Anonymen, dass der Name im System existiert.
        $guest = $this->newClient();
        $payload = $this->catalogAjax($guest, $queryParam . '=' . urlencode($personName));
        $this->assertSame(
            0,
            $payload['count'],
            "{$queryParam} auf eine unveröffentlichte Person darf keine Treffer melden (Existenz-Orakel, #121)"
        );
        $this->assertStringNotContainsString(
            htmlspecialchars($horseName),
            $payload['cards_html'],
            'Auch die Pferdekarten dürfen die Zuordnung zur unveröffentlichten Person nicht verraten'
        );

        // 2. Gegenprobe: Dieselbe Person veröffentlicht -> genau ein Treffer.
        $db->prepare("UPDATE persons SET is_published = 1 WHERE id = ?")->execute([$personId]);
        $payload = $this->catalogAjax($guest, $queryParam . '=' . urlencode($personName));
        $this->assertSame(
            1,
            $payload['count'],
            "{$queryParam} muss auf eine veröffentlichte Person treffen - sonst wäre die 0 oben nur ein kaputter Filter"
        );
        $this->assertStringContainsString(htmlspecialchars($horseName), $payload['cards_html']);
    }

    public function testStationFilterNeverMatchesUnpublishedStationName(): void {
        $db = Database::getInstance();
        $unique = uniqid();
        $stationName = "Geheime Station {$unique}";
        $freetextName = "Freitexthof {$unique}";

        $stationId = $this->seedStation($stationName, false);

        // Pferd mit Stations-VERKNÜPFUNG: horses.breeding_station ist bei
        // gesetzter breeding_station_id eine denormalisierte Kopie des
        // Stationsnamens (HorseController::saveHorsePersons() schreibt beide
        // Spalten gemeinsam fort) - der Seed bildet genau diesen Zustand nach.
        // Die Freitext-Klausel des Filters greift nur bei
        // breeding_station_id IS NULL; träfe sie hier, läge der Name der
        // unveröffentlichten Station über die Kopie doch wieder offen.
        $linkedHorseName = "Orakel Pferd Station {$unique}";
        $linkedHorseId = $this->seedHorse($linkedHorseName, true);
        $db->prepare("UPDATE horses SET breeding_station_id = ?, breeding_station = ? WHERE id = ?")
            ->execute([$stationId, $stationName, $linkedHorseId]);

        // Pferd mit REINEM Freitext (keine breeding_station_id) - z. B. aus
        // dem CSV-Import. Dieser Wert gehört zum Pferd selbst, nicht zu einem
        // depublizierbaren Stationsdatensatz, und muss filterbar bleiben.
        $freetextHorseName = "Freitext Pferd Station {$unique}";
        $freetextHorseId = $this->seedHorse($freetextHorseName, true);
        $db->prepare("UPDATE horses SET breeding_station = ? WHERE id = ?")
            ->execute([$freetextName, $freetextHorseId]);

        // 1. Unveröffentlichte Station: kein Treffer, keine Karten.
        $guest = $this->newClient();
        $payload = $this->catalogAjax($guest, 'q_station=' . urlencode($stationName));
        $this->assertSame(
            0,
            $payload['count'],
            'q_station auf eine unveröffentlichte Station darf keine Treffer melden (Existenz-Orakel, #151)'
        );
        $this->assertStringNotContainsString(htmlspecialchars($linkedHorseName), $payload['cards_html']);

        // 2. Freitext-Gegenprobe: Der Schutz gilt Stations-DATENSÄTZEN, nicht
        // dem Freitext - eine zu breite "Reparatur" (etwa breeding_station
        // pauschal aus dem Filter nehmen) soll hier rot werden.
        $payload = $this->catalogAjax($guest, 'q_station=' . urlencode($freetextName));
        $this->assertSame(
            1,
            $payload['count'],
            'Reiner Freitext ohne Stations-Verknüpfung muss weiterhin filterbar sein - der Schutz darf nicht zu breit greifen'
        );
        $this->assertStringContainsString(htmlspecialchars($freetextHorseName), $payload['cards_html']);

        // 3. Gegenprobe: Dieselbe Station veröffentlicht -> das verknüpfte
        // Pferd wird über bs.name gefunden.
        $db->prepare("UPDATE breeding_stations SET is_published = 1 WHERE id = ?")->execute([$stationId]);
        $payload = $this->catalogAjax($guest, 'q_station=' . urlencode($stationName));
        $this->assertSame(
            1,
            $payload['count'],
            'q_station muss auf eine veröffentlichte Station treffen - sonst wäre die 0 oben nur ein kaputter Filter'
        );
        $this->assertStringContainsString(htmlspecialchars($linkedHorseName), $payload['cards_html']);
    }

    public function testUnpublishedHorseIsNeitherInCatalogNorOnDetailPage(): void {
        $db = Database::getInstance();
        $unique = uniqid();
        $horseName = "Unveroeffentlichtes Pferd {$unique}";
        $horseId = $this->seedHorse($horseName, false);

        // 1. Katalog: Weder die Trefferzahl noch die Karten dürfen das
        // unveröffentlichte Pferd zeigen - search=$unique isoliert von
        // fremden Testdaten auf der geteilten DB.
        $guest = $this->newClient();
        $payload = $this->catalogAjax($guest, 'search=' . urlencode($unique));
        $this->assertSame(0, $payload['count'], 'Ein unveröffentlichtes Pferd darf im Katalog nicht auftauchen');
        $this->assertStringNotContainsString(htmlspecialchars($horseName), $payload['cards_html']);

        // 2. Detailseite: wie ein nicht existierendes Pferd behandeln (404) -
        // eine abweichende Antwort (403, Redirect, leere 200) wäre selbst
        // wieder ein Existenz-Orakel für die ID.
        $detail = $guest->get('/horse?id=' . $horseId);
        $this->assertSame(404, $detail->statusCode, 'Die Detailseite eines unveröffentlichten Pferdes muss 404 liefern');
        $this->assertStringNotContainsString(htmlspecialchars($horseName), $detail->body);

        // 3. Gegenprobe: veröffentlicht -> Katalogtreffer und Detailseite 200.
        $db->prepare("UPDATE horses SET is_published = 1 WHERE id = ?")->execute([$horseId]);
        $payload = $this->catalogAjax($guest, 'search=' . urlencode($unique));
        $this->assertSame(1, $payload['count'], 'Das veröffentlichte Pferd muss im Katalog erscheinen');
        $this->assertStringContainsString(htmlspecialchars($horseName), $payload['cards_html']);

        $detail = $guest->get('/horse?id=' . $horseId);
        $this->assertSame(200, $detail->statusCode);
        $this->assertStringContainsString(htmlspecialchars($horseName), $detail->body);
    }

    /**
     * Ruft den AJAX-Katalog als Gast auf und liefert das dekodierte JSON
     * (count/cards_html, siehe PublicController::catalog(), AJAX-Zweig).
     *
     * @return array{count: int, cards_html: string}
     */
    private function catalogAjax(HttpClient $guest, string $query): array {
        $response = $guest->get('/katalog?ajax=1&' . $query);
        $this->assertSame(200, $response->statusCode, "AJAX-Katalog nicht erreichbar, Body: {$response->body}");
        $payload = json_decode($response->body, true);
        $this->assertIsArray($payload, "AJAX-Katalog lieferte kein JSON, Body: {$response->body}");
        $this->assertArrayHasKey('count', $payload);
        $this->assertArrayHasKey('cards_html', $payload);
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

    private function seedPerson(string $name, bool $published): int {
        $db = Database::getInstance();
        $db->prepare("INSERT INTO persons (name, is_published) VALUES (?, ?)")
            ->execute([$name, $published ? 1 : 0]);
        $id = (int)$db->lastInsertId();
        $this->seededPersonIds[] = $id;
        return $id;
    }

    private function seedStation(string $name, bool $published): int {
        $db = Database::getInstance();
        $db->prepare("INSERT INTO breeding_stations (name, is_published) VALUES (?, ?)")
            ->execute([$name, $published ? 1 : 0]);
        $id = (int)$db->lastInsertId();
        $this->seededStationIds[] = $id;
        return $id;
    }
}
