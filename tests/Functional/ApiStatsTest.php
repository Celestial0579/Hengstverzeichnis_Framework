<?php
// tests/Functional/ApiStatsTest.php

namespace Tests\Functional;

use App\Database;

/**
 * HTTP-Funktionstests für den Zeitreihen-Endpunkt `/api/stats` (#270, siehe
 * App\Controllers\StatsApiController und docs/api.md).
 *
 * Der Schwerpunkt liegt nicht auf "liefert Zahlen", sondern auf den drei
 * Eigenschaften, die still danebengehen können:
 *
 * 1. **Das eigene Recht greift wirklich.** Die Zahlen sind betriebsintern.
 *    Ein Schlüssel mit Katalogrechten (`horses.view`) darf hier NICHT
 *    durchkommen - sonst verrieten DSGVO-Anfragen und Login-Fehlversuche
 *    ihre Verläufe an jeden Katalog-Integrator.
 * 2. **Lücken werden gefüllt.** Ein Tag ohne Ereignisse muss als 0 in der
 *    Reihe stehen, nicht fehlen - sonst zieht Grafana eine Linie darüber
 *    hinweg und der Tag sieht aus, als hätte er nie stattgefunden.
 * 3. **Kein Request-Text erreicht SQL.** Unbekannte Reihen, Kübelbreiten und
 *    krumme Datumsangaben müssen mit 400 abgewiesen werden statt als
 *    Fragment in einer Abfrage zu landen oder still auf Standardwerte
 *    zurückzufallen.
 */
class ApiStatsTest extends FunctionalTestCase {

    use ApiKeyHelper;

    /**
     * Bezeichnungs-Präfix aller hier angelegten Schlüssel - danach räumt
     * tearDown() auf.
     */
    private const KEY_LABEL_PREFIX = 'ApiStatsTest ';

    /** @var array<int, int> */
    private array $seededHorseIds = [];

    protected function tearDown(): void {
        $db = Database::getInstance();

        if ($this->seededHorseIds !== []) {
            $stmt = $db->prepare('DELETE FROM horses WHERE id = ?');
            foreach ($this->seededHorseIds as $id) {
                $stmt->execute([$id]);
            }
            $this->seededHorseIds = [];
        }

        // ApiKey::MAX_KEYS_PER_USER ist 5, diese Klasse braucht mehr als einen
        // Schlüssel. Ohne Aufräumen liefe der Admin-Benutzer im Lauf der
        // Testmethoden in "limit_reached" - und zwar erst ab der sechsten,
        // also abhängig von der Reihenfolge. Einen Schlüssel über alle Tests
        // hinweg wiederzuverwenden wäre die andere Möglichkeit, würde die
        // Methoden aber aneinanderkoppeln; das ist genau die Bauart, an der
        // in dieser Suite schon ein --filter-Lauf gescheitert ist.
        $db->prepare('DELETE FROM api_keys WHERE label LIKE ?')
            ->execute([self::KEY_LABEL_PREFIX . '%']);

        parent::tearDown();
    }

    /**
     * Ohne Schlüssel gar nichts - identisch zur übrigen API und mit
     * WWW-Authenticate-Hinweis.
     */
    public function testRequiresAnApiKey(): void {
        // Erzwingt die Ersteinrichtung der Testinstanz (ensureProvisioned()
        // hängt an authenticatedClient()). Ohne diesen Aufruf antwortet eine
        // frische Datenbank auf JEDE Route zunächst mit dem Setup-Redirect
        // (302), und dieser rein anonyme Test wäre von der Ausführungs-
        // reihenfolge innerhalb der Suite abhängig - im vollen Lauf grün, als
        // erster Test auf frischer Datenbank rot. Dieselbe Vorkehrung wie in
        // ApiKeyAuthTest::testApiIsUnreachableWithoutValidKey().
        $this->authenticatedClient();

        $response = $this->newClient()->get('/api/stats?metric=horses.created');
        $this->assertSame(401, $response->statusCode);

        $body = json_decode($response->body, true);
        $this->assertSame('unauthorized', $body['error'] ?? null);
    }

    /**
     * Der Kern des Ganzen: Ein gültiger Schlüssel MIT Katalogrecht, aber OHNE
     * `stats.view`, bekommt 403. Ohne diese Trennung hinge die Metrik-Sicht
     * an einem fachfremden Recht.
     */
    public function testCatalogueKeyWithoutStatsPermissionIsRejected(): void {
        $admin = $this->authenticatedClient();
        $token = $this->createApiKey($admin, self::KEY_LABEL_PREFIX . 'Negativprobe ' . uniqid(), ['horses.view']);

        $response = $this->newClient()->get('/api/stats?metric=horses.created', $this->bearer($token));
        $this->assertSame(
            403,
            $response->statusCode,
            'Ein Schlüssel ohne stats.view darf keine Betriebszahlen lesen. Antwort: ' . $response->body
        );

        $body = json_decode($response->body, true);
        $this->assertSame('forbidden', $body['error'] ?? null);
        $this->assertStringNotContainsString(
            'bucket',
            $response->body,
            'Die Ablehnung darf keine Daten mitliefern.'
        );
    }

    /** Mit `stats.view` im Scope geht es - dieselbe Route, nur anderes Recht. */
    public function testStatsScopeGrantsAccess(): void {
        $token = $this->statsToken();

        $response = $this->newClient()->get('/api/stats?metric=horses.created', $this->bearer($token));
        $this->assertSame(200, $response->statusCode, $response->body);

        $body = json_decode($response->body, true);
        $this->assertIsArray($body['data'] ?? null);
        $this->assertSame('horses.created', $body['meta']['metric'] ?? null);
        $this->assertSame('day', $body['meta']['interval'] ?? null);
    }

    /** Ohne ?metric= gibt es den Katalog - damit die Datenquelle einrichtbar ist. */
    public function testWithoutMetricItListsWhatExists(): void {
        $token = $this->statsToken();

        $response = $this->newClient()->get('/api/stats', $this->bearer($token));
        $this->assertSame(200, $response->statusCode, $response->body);

        $body = json_decode($response->body, true);
        $metrics = array_column($body['data'] ?? [], 'metric');
        $this->assertContains('horses.created', $metrics);
        $this->assertContains('gdpr_requests.created', $metrics);
        $this->assertContains('login_attempts.created', $metrics);
        $this->assertSame(['day', 'week', 'month'], $body['meta']['intervals'] ?? null);
    }

    /**
     * Frisch angelegte Pferde müssen im Tageskübel von heute auftauchen, und
     * die Reihe muss lückenlos sein - jeder Tag des Zeitraums genau einmal.
     */
    public function testCountsTodaysHorsesAndFillsEveryDayInBetween(): void {
        $token = $this->statsToken();

        $before = $this->todayValue($token, 'horses.created');
        $this->seedHorses(3);
        $after = $this->todayValue($token, 'horses.created');

        $this->assertSame(
            $before + 3,
            $after,
            'Drei neu angelegte Pferde müssen im heutigen Kübel ankommen.'
        );

        // Lückenlosigkeit über einen Zeitraum, in dem es garantiert leere Tage gibt.
        $from = (new \DateTimeImmutable('today'))->modify('-9 days');
        $to = new \DateTimeImmutable('today');
        $body = $this->stats($token, [
            'metric' => 'horses.created',
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ]);

        $buckets = array_column($body['data'], 'bucket');
        $this->assertCount(10, $buckets, 'Zehn Tage Zeitraum = zehn Kübel.');
        $this->assertSame(array_unique($buckets), $buckets, 'Kein Kübel doppelt.');

        $expected = [];
        for ($cursor = $from; $cursor <= $to; $cursor = $cursor->modify('+1 day')) {
            $expected[] = $cursor->format('Y-m-d');
        }
        $this->assertSame($expected, $buckets, 'Die Reihe muss jeden Tag enthalten, auch die leeren.');
        $this->assertSame(10, $body['meta']['buckets']);
    }

    /** Gröbere Kübel: Monatsraster beginnt am Monatsersten. */
    public function testMonthIntervalSnapsToTheFirstOfTheMonth(): void {
        $token = $this->statsToken();

        $body = $this->stats($token, [
            'metric' => 'horses.created',
            'interval' => 'month',
            'from' => (new \DateTimeImmutable('today'))->modify('-2 months')->format('Y-m-d'),
            'to' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
        ]);

        foreach ($body['data'] as $point) {
            $this->assertStringEndsWith(
                '-01',
                $point['bucket'],
                'Monatskübel müssen auf dem Monatsersten liegen, sonst trifft die Lückenfüllung das Raster der Datenbank nicht.'
            );
        }
        $this->assertSame('month', $body['meta']['interval']);
    }

    /**
     * Alles, was nicht in der Whitelist steht, ist ein 400 - und zwar mit
     * Hinweis, was gültig wäre, statt still auf einen Standard zu fallen.
     */
    public function testRejectsAnythingOutsideTheWhitelist(): void {
        $token = $this->statsToken();
        $client = $this->newClient();

        $faelle = [
            'unbekannte Reihe' => ['metric' => 'horses.created; DROP TABLE horses', 'error' => 'unknown_metric'],
            'unbekannte Kübelbreite' => ['metric' => 'horses.created', 'interval' => 'jahrzehnt', 'error' => 'unknown_interval'],
            'krummes Datum' => ['metric' => 'horses.created', 'from' => '01.02.2026', 'error' => 'invalid_date'],
            'nicht existenter Tag' => ['metric' => 'horses.created', 'from' => '2026-02-31', 'error' => 'invalid_date'],
            'relative Angabe' => ['metric' => 'horses.created', 'to' => 'yesterday', 'error' => 'invalid_date'],
            'verdrehter Zeitraum' => ['metric' => 'horses.created', 'from' => '2026-05-01', 'to' => '2026-04-01', 'error' => 'invalid_range'],
            'Filter ohne Filterspalte' => ['metric' => 'horses.created', 'filter' => 'egal', 'error' => 'filter_not_supported'],
        ];

        foreach ($faelle as $name => $fall) {
            $erwarteterFehler = $fall['error'];
            unset($fall['error']);

            $response = $client->get('/api/stats?' . http_build_query($fall), $this->bearer($token));
            $this->assertSame(400, $response->statusCode, "{$name}: erwartet 400, Antwort: {$response->body}");

            $body = json_decode($response->body, true);
            $this->assertSame($erwarteterFehler, $body['error'] ?? null, "{$name}: falscher Fehlercode.");
        }

        // Die Tabelle steht noch - der Injektionsversuch oben ist nirgends gelandet.
        $this->assertSame(
            200,
            $client->get('/api/stats?metric=horses.created', $this->bearer($token))->statusCode
        );
    }

    /**
     * Ein absurd großer Zeitraum wird abgelehnt, BEVOR die Datenbank ihn
     * ausführt - die Lückenfüllung würde die Zeilen sonst auch ohne jeden
     * Datensatz erzeugen.
     */
    public function testRefusesRangesThatWouldExplodeIntoTooManyBuckets(): void {
        $token = $this->statsToken();

        // Gut fünfeinhalb Jahre: in Tageskübeln über der Grenze, in
        // Monatskübeln (rund 66) klar darunter. Damit prüft der Test genau
        // das, wozu die Fehlermeldung rät - und nicht bloß, dass irgendein
        // absurder Zeitraum abgewiesen wird.
        $range = [
            'metric' => 'horses.created',
            'from' => (new \DateTimeImmutable('today'))->modify('-2000 days')->format('Y-m-d'),
            'to' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
        ];

        $response = $this->newClient()->get(
            '/api/stats?' . http_build_query($range + ['interval' => 'day']),
            $this->bearer($token)
        );
        $this->assertSame(400, $response->statusCode, $response->body);
        $body = json_decode($response->body, true);
        $this->assertSame('range_too_large', $body['error'] ?? null);

        // Gröber gruppiert ist derselbe Zeitraum in Ordnung.
        $ok = $this->newClient()->get(
            '/api/stats?' . http_build_query($range + ['interval' => 'month']),
            $this->bearer($token)
        );
        $this->assertSame(200, $ok->statusCode, $ok->body);
        $okBody = json_decode($ok->body, true);
        $this->assertLessThanOrEqual(1500, $okBody['meta']['buckets']);
        $this->assertGreaterThan(60, $okBody['meta']['buckets'], 'Rund 66 Monatskübel erwartet.');
    }

    /** Antworten dieses Endpunkts dürfen nie in einem geteilten Cache landen. */
    public function testResponseIsNotCacheable(): void {
        $token = $this->statsToken();
        $response = $this->newClient()->get('/api/stats?metric=horses.created', $this->bearer($token));

        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString(
            'no-store',
            (string)$response->header('Cache-Control'),
            'Rechtegebundene Zahlen gehören nicht in einen Proxy-Cache.'
        );
    }

    // ----------------------------------------------------------------- Helfer

    /** Schlüssel mit genau dem Recht, das dieser Endpunkt verlangt. */
    private function statsToken(): string {
        return $this->createApiKey($this->authenticatedClient(), self::KEY_LABEL_PREFIX . uniqid(), ['stats.view']);
    }

    /**
     * @param array<string, string> $query
     * @return array<string, mixed>
     */
    private function stats(string $token, array $query): array {
        $response = $this->newClient()->get('/api/stats?' . http_build_query($query), $this->bearer($token));
        $this->assertSame(200, $response->statusCode, $response->body);

        $body = json_decode($response->body, true);
        $this->assertIsArray($body, 'Antwort ist kein JSON: ' . $response->body);
        return $body;
    }

    private function todayValue(string $token, string $metric): int {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $body = $this->stats($token, ['metric' => $metric, 'from' => $today, 'to' => $today]);

        foreach ($body['data'] as $point) {
            if ($point['bucket'] === $today) {
                return (int)$point['value'];
            }
        }

        $this->fail('Der heutige Kübel fehlt in der Antwort: ' . json_encode($body));
    }

    private function seedHorses(int $count): void {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            "INSERT INTO horses (name, sex, is_published, created_at) VALUES (?, 'stallion', 1, NOW())"
        );
        $marker = 'Statistikprobe' . uniqid();
        for ($i = 0; $i < $count; $i++) {
            $stmt->execute([sprintf('%s %02d', $marker, $i)]);
            $this->seededHorseIds[] = (int)$db->lastInsertId();
        }
    }
}
