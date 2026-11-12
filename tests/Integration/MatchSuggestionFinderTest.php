<?php
// tests/Integration/MatchSuggestionFinderTest.php

namespace Tests\Integration;

use App\Database;
use App\Service\MatchSuggestionFinder;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Prüft App\Service\MatchSuggestionFinder nach der Umstellung auf den
 * SQL-Vorfilter (#215) gegen eine echte Test-Datenbank - und zwar direkt
 * GEGEN DIE ALTE LOGIK: Ein Test-Orakel bildet das frühere Verhalten exakt
 * nach (drei Vollmengen-Abfragen, Geschlechtsfilter in PHP, Bewertung jedes
 * Platzhalters gegen ALLE Kandidaten) und ruft dafür dieselbe, unveränderte
 * private Bewertungsmethode calculateSuggestions() per Reflection auf. Für
 * den konstruierten Bestand (UELN-Treffer, Namens-Treffer, Nicht-Treffer,
 * Geschlechts-Ausschluss, Vorfilter-Treffer unterhalb der Anzeigeschwelle)
 * müssen beide Wege identische Vorschläge liefern - Score, Gründe und
 * Reihenfolge eingeschlossen.
 *
 * Bewusst NICHT Teil des Bestands: Paare, die der Vorfilter konstruktionsbedingt
 * nicht findet (ähnliche Namen mit komplett anderem Anfang UND anderem
 * Klangbild, z. B. "Quantum"/"Kwantum") - dieser Kompromiss ist die Kernidee
 * aus #215 und im Klassenkommentar von MatchSuggestionFinder dokumentiert.
 *
 * Dateiname sortiert alphabetisch nach DatabaseTest.php ("Ma" > "Da") - dessen
 * Anforderung, erster Aufrufer von App\Database::getInstance() im
 * PHPUnit-Prozess zu sein, bleibt damit gewahrt (siehe Klassendoc dort und
 * tests/Integration/DigestServiceTest.php für dieselbe Problematik).
 */
class MatchSuggestionFinderTest extends TestCase {

    use \Tests\Support\Zeilenzugriffe;

    private static PDO $db;

    public static function setUpBeforeClass(): void {
        if (!defined('DB_HOST')) {
            self::markTestSkipped('Keine Test-Datenbank konfiguriert (DB_HOST fehlt) - siehe tests/bootstrap.php.');
        }

        $setupPdo = new PDO(
            "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $setupPdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        foreach ($setupPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $setupPdo->exec("DROP TABLE IF EXISTS `$table`");
        }
        $setupPdo->exec("SET FOREIGN_KEY_CHECKS = 1");

        $schemaFile = __DIR__ . '/../../database/schema.sql';
        try {
            $setupPdo->exec(file_get_contents($schemaFile));
        } catch (PDOException $e) {
            // Ignorieren, analog zu SetupController::provision()
        }

        self::$db = Database::getInstance();
    }

    protected function setUp(): void {
        self::$db->exec("DELETE FROM horses");
    }

    protected function tearDown(): void {
        MatchSuggestionFinder::ohneSuchspaltenFuerTests(false);
    }

    private function insertHorse(array $overrides = []): int {
        $data = array_merge([
            'name' => 'Testpferd ' . uniqid(),
            'ueln' => null, 'foreign_ueln' => null,
            'sire_id' => null, 'sire_name' => null, 'sire_ueln' => null,
            'dam_id' => null, 'dam_name' => null, 'dam_ueln' => null,
            'birth_year' => null, 'color' => null, 'sex' => null,
            'breeding_station_id' => null, 'breeding_station' => null,
            'deleted_at' => null,
        ], $overrides);

        $stmt = self::$db->prepare("
            INSERT INTO horses (name, ueln, foreign_ueln, sire_id, sire_name, sire_ueln, dam_id, dam_name, dam_ueln,
                                birth_year, color, sex, breeding_station_id, breeding_station, deleted_at)
            VALUES (:name, :ueln, :foreign_ueln, :sire_id, :sire_name, :sire_ueln, :dam_id, :dam_name, :dam_ueln,
                    :birth_year, :color, :sex, :breeding_station_id, :breeding_station, :deleted_at)
        ");
        $stmt->execute($data);
        return (int)self::$db->lastInsertId();
    }

    /**
     * Test-Orakel: die Implementierung von findAll() VOR #215 - unverändert
     * übernommene Abfragen und Schleifen des alten Codes, die Bewertung selbst
     * kommt per Reflection aus der (fachlich unveränderten) privaten Methode
     * calculateSuggestions() der Produktivklasse. Läuft absichtlich als
     * PHP-Kreuzprodukt ohne jeden Vorfilter.
     *
     * @return array<int, array<string, mixed>>
     */
    private function legacyFindAll(): array {
        $db = self::$db;

        $sirePlaceholders = $db->query("SELECT id, name, ueln, foreign_ueln, birth_year, color, breeding_station_id, breeding_station, sire_name, sire_ueln FROM horses WHERE deleted_at IS NULL AND sire_id IS NULL AND (sire_name IS NOT NULL OR sire_ueln IS NOT NULL)")->fetchAll();
        $damPlaceholders = $db->query("SELECT id, name, ueln, foreign_ueln, birth_year, color, breeding_station_id, breeding_station, dam_name, dam_ueln FROM horses WHERE deleted_at IS NULL AND dam_id IS NULL AND (dam_name IS NOT NULL OR dam_ueln IS NOT NULL)")->fetchAll();
        $allHorses = $db->query("SELECT id, name, ueln, foreign_ueln, birth_year, color, sex, breeding_station_id, breeding_station, sire_id, dam_id FROM horses WHERE deleted_at IS NULL ORDER BY name ASC")->fetchAll();

        $sireCandidates = array_values(array_filter($allHorses,
            fn($h) => !in_array($h['sex'] ?? null, ['mare', 'gelding'], true)));
        $damCandidates = array_values(array_filter($allHorses,
            fn($h) => !in_array($h['sex'] ?? null, ['stallion', 'gelding'], true)));

        $calculate = (new \ReflectionClass(MatchSuggestionFinder::class))->getMethod('calculateSuggestions');

        $unlinkedMatches = [];
        foreach ($sirePlaceholders as $sp) {
            $suggestions = $calculate->invoke(null, $sp['sire_name'], $sp['sire_ueln'], $sp, $sireCandidates);
            if (!empty($suggestions)) {
                $unlinkedMatches[] = [
                    'child_id' => $sp['id'],
                    'child_name' => $sp['name'],
                    'parent_type' => 'sire',
                    'parent_type_label' => 'Vater',
                    'placeholder_name' => $sp['sire_name'],
                    'placeholder_ueln' => $sp['sire_ueln'],
                    'suggestions' => $suggestions
                ];
            }
        }
        foreach ($damPlaceholders as $dp) {
            $suggestions = $calculate->invoke(null, $dp['dam_name'], $dp['dam_ueln'], $dp, $damCandidates);
            if (!empty($suggestions)) {
                $unlinkedMatches[] = [
                    'child_id' => $dp['id'],
                    'child_name' => $dp['name'],
                    'parent_type' => 'dam',
                    'parent_type_label' => 'Mutter',
                    'placeholder_name' => $dp['dam_name'],
                    'placeholder_ueln' => $dp['dam_ueln'],
                    'suggestions' => $suggestions
                ];
            }
        }

        return $unlinkedMatches;
    }

    /**
     * Schlüsselt eine findAll()-Ergebnisliste nach Rolle+Kind-ID, damit die
     * Gleichheitsprüfung unabhängig von der Ausgabereihenfolge der Einträge
     * ist: Die alten Platzhalter-Abfragen liefen ohne ORDER BY, die neue
     * Implementierung sortiert deterministisch für die Pagination. Innerhalb
     * eines Eintrags (Vorschlagsliste, Scores, Gründe) bleibt die Reihenfolge
     * dagegen Teil des Vergleichs.
     *
     * @param array<int, array<string, mixed>> $entries
     * @return array<string, array<string, mixed>>
     */
    private function keyedByPlaceholder(array $entries): array {
        $keyed = [];
        foreach ($entries as $entry) {
            $key = $entry['parent_type'] . ':' . $entry['child_id'];
            $this->assertArrayNotHasKey($key, $keyed, "Doppelter Platzhalter-Eintrag {$key}");
            $keyed[$key] = $entry;
        }
        ksort($keyed);
        return $keyed;
    }

    /**
     * Baut den konstruierten Bestand auf und liefert die IDs der Platzhalter-
     * Kinder zurück.
     *
     * @return array<string, int>
     */
    private function insertFixture(): array {
        // Kandidaten:
        // - "Quantum": Namens-Treffer für den Platzhalter "Quantom" (SOUNDEX
        //   und Präfix identisch, similar_text ~86 % -> mit Alter/Farbe/
        //   Deckstation über der 45-%-Schwelle).
        $this->insertHorse(['name' => 'Quantum', 'sex' => 'stallion', 'birth_year' => 2005,
            'color' => 'Fuchs', 'breeding_station' => 'Gestüt Nordfjord']);
        // - "Baldur": UELN-Treffer trotz komplett anderen Namens - deckt den
        //   45-Punkte-UELN-Zweig des Vorfilters ab. sex bewusst NULL
        //   (unbekanntes Geschlecht bleibt als Vater-Kandidat zugelassen, #167).
        $this->insertHorse(['name' => 'Baldur', 'ueln' => 'DE001TESTUELN1', 'birth_year' => 2000]);
        // - "Quasar": passiert den Vorfilter (Präfix "Qua"), bleibt aber in der
        //   Bewertung unter der Schwelle -> darf in KEINER Vorschlagsliste
        //   auftauchen (alte wie neue Logik filtern ihn über das Scoring).
        $this->insertHorse(['name' => 'Quasar', 'sex' => 'stallion', 'birth_year' => 2004, 'color' => 'Schimmel']);
        // - "Bella": exakter Namens-Treffer für den Mutter-Platzhalter.
        $this->insertHorse(['name' => 'Bella', 'sex' => 'mare', 'birth_year' => 2008, 'color' => 'Rappe']);
        // - "Quantessa": Stute mit passendem Namens-Präfix - der Geschlechts-
        //   filter (#167) muss sie als Vater-Kandidatin ausschließen.
        $this->insertHorse(['name' => 'Quantessa', 'sex' => 'mare', 'birth_year' => 2006, 'color' => 'Fuchs']);
        // - "Zephyr": Nicht-Treffer - weder UELN noch SOUNDEX noch Präfix
        //   passen zu irgendeinem Platzhalter.
        $this->insertHorse(['name' => 'Zephyr', 'sex' => 'stallion', 'birth_year' => 2003]);

        // Platzhalter-Kinder:
        $ids = [];
        $ids['nameHit'] = $this->insertHorse(['name' => 'Quantom Junior', 'sire_name' => 'Quantom',
            'birth_year' => 2015, 'color' => 'Fuchs', 'breeding_station' => 'Gestüt Nordfjord']);
        $ids['uelnHit'] = $this->insertHorse(['name' => 'Baldur Kind', 'sire_ueln' => 'DE001TESTUELN1',
            'birth_year' => 2012]);
        $ids['damHit'] = $this->insertHorse(['name' => 'Bella Tochter', 'dam_name' => 'Bella',
            'birth_year' => 2015, 'color' => 'Rappe']);
        // Ohne jeden Vorfilter-Kandidaten - taucht weder in findAll() noch in
        // countOpen() auf (und ergab auch früher nie einen Vorschlag).
        $ids['noCandidate'] = $this->insertHorse(['name' => 'Xanadu Kind', 'sire_name' => 'Xanadu']);
        // Mit Vorfilter-Kandidaten ("Qua..."-Präfix), aber ohne Vorschlag über
        // der Schwelle: zählt in countOpen() (dokumentierte Obermenge), fehlt
        // aber in findAll() - in alter wie neuer Logik.
        $ids['belowThreshold'] = $this->insertHorse(['name' => 'Quasimodo Kind', 'sire_name' => 'Quasimodo']);

        return $ids;
    }

    public function testFindAllMatchesLegacyCrossProductLogicExactly(): void {
        $ids = $this->insertFixture();

        $legacy = $this->keyedByPlaceholder($this->legacyFindAll());
        $current = $this->keyedByPlaceholder(MatchSuggestionFinder::findAll());

        // Identische Vorschläge wie die alte Logik - inklusive Scores, Gründen,
        // Kandidaten-Feldern und deren Reihenfolge (assertSame: Typen und
        // Array-Reihenfolge zählen mit).
        $this->assertSame(array_keys($legacy), array_keys($current));
        $this->assertSame($legacy, $current);

        // Und die fachliche Erwartung an den Bestand selbst (schützt davor,
        // dass Orakel und Implementierung im Gleichschritt falsch liegen):
        $this->assertSame(
            ['dam:' . $ids['damHit'], 'sire:' . $ids['nameHit'], 'sire:' . $ids['uelnHit']],
            array_keys($current)
        );

        $nameHit = $current['sire:' . $ids['nameHit']];
        $this->assertSame('Quantom', $nameHit['placeholder_name']);
        $this->assertCount(1, $nameHit['suggestions'], 'Nur "Quantum" darf vorgeschlagen werden - "Quasar" (unter der Schwelle) und "Quantessa" (Stute) nicht');
        $this->assertSame('Quantum', $nameHit['suggestions'][0]['horse']['name']);
        $this->assertContains('✓ Identische Deckstation (Freitext)', $nameHit['suggestions'][0]['reasons']);

        $uelnHit = $current['sire:' . $ids['uelnHit']];
        $this->assertCount(1, $uelnHit['suggestions']);
        $this->assertSame('Baldur', $uelnHit['suggestions'][0]['horse']['name']);
        $this->assertContains('✓ Haupt-UELN übereinstimmend', $uelnHit['suggestions'][0]['reasons']);

        $damHit = $current['dam:' . $ids['damHit']];
        $this->assertCount(1, $damHit['suggestions']);
        $this->assertSame('Bella', $damHit['suggestions'][0]['horse']['name']);

        // Nicht- bzw. Unter-Schwellen-Treffer tauchen nirgends auf.
        $suggestedNames = [];
        foreach ($current as $entry) {
            foreach ($entry['suggestions'] as $suggestion) {
                $suggestedNames[] = $suggestion['horse']['name'];
            }
        }
        $this->assertNotContains('Zephyr', $suggestedNames);
        $this->assertNotContains('Quasar', $suggestedNames);
        $this->assertNotContains('Quantessa', $suggestedNames);
    }

    public function testCountOpenCountsPrefilteredPlaceholdersAsDocumentedSuperset(): void {
        $this->insertFixture();

        // Vier Platzhalter haben mindestens einen Vorfilter-Kandidaten (auch
        // "Quasimodo Kind", dessen Kandidaten unter der Schwelle bleiben);
        // "Xanadu Kind" hat keinen und zählt nicht.
        $this->assertSame(4, MatchSuggestionFinder::countOpen());
        // findAll() liefert dagegen nur die drei Platzhalter MIT Vorschlägen -
        // countOpen() ist die dokumentierte Obermenge für Digest/Pagination.
        $this->assertCount(3, MatchSuggestionFinder::findAll());

        self::$db->exec("DELETE FROM horses");
        $this->assertSame(0, MatchSuggestionFinder::countOpen());
        $this->assertSame([], MatchSuggestionFinder::findAll());
    }

    public function testFindAllPaginatesOverPrefilteredPlaceholders(): void {
        $ids = $this->insertFixture();

        // Deterministische Platzhalter-Reihenfolge (Rolle, Kind-Name, Kind-ID):
        // sire "Baldur Kind", sire "Quantom Junior", sire "Quasimodo Kind",
        // dam "Bella Tochter". Seite 1 (3 Platzhalter) liefert nur 2 Einträge,
        // weil "Quasimodo Kind" nach der Bewertung herausfällt - eine Seite
        // darf also weniger Einträge als Platzhalter enthalten.
        $pageOne = MatchSuggestionFinder::findAll(3, 0);
        $this->assertSame(
            [$ids['uelnHit'], $ids['nameHit']],
            array_map(fn($entry) => (int)$entry['child_id'], $pageOne)
        );

        $pageTwo = MatchSuggestionFinder::findAll(3, 3);
        $this->assertSame(
            [$ids['damHit']],
            array_map(fn($entry) => (int)$entry['child_id'], $pageTwo)
        );

        // Beide Seiten zusammen ergeben exakt das ungeteilte Ergebnis.
        $this->assertSame(
            $this->keyedByPlaceholder(MatchSuggestionFinder::findAll()),
            $this->keyedByPlaceholder(array_merge($pageOne, $pageTwo))
        );
    }

    /**
     * Audit N47: Ein Vorschlag mit unmöglichem Alter bleibt sichtbar, trägt
     * aber 'blockiert' - die View sperrt dann "Jetzt verknüpfen". Das Orakel
     * oben nutzt dieselbe Bewertungsmethode und bleibt deckungsgleich.
     */
    public function testImpossibleAgeSuggestionIsMarkedAsBlocked(): void {
        $kind = $this->insertHorse(['name' => 'Juno Kind', 'birth_year' => 2010, 'sire_name' => 'Juno']);
        $jung = $this->insertHorse(['name' => 'Juno', 'sex' => 'stallion', 'birth_year' => 2012]);
        $kind2 = $this->insertHorse(['name' => 'Kora Kind', 'birth_year' => 2010, 'sire_name' => 'Kora']);
        $alt = $this->insertHorse(['name' => 'Kora', 'sex' => 'stallion', 'birth_year' => 2000]);

        $vorschlaege = [];
        foreach (MatchSuggestionFinder::findAll() as $eintrag) {
            foreach ($eintrag['suggestions'] as $vorschlag) {
                $vorschlaege[(int)$eintrag['child_id'] . '/' . (int)$vorschlag['horse']['id']] = $vorschlag;
            }
        }
        $this->assertArrayHasKey("{$kind}/{$jung}", $vorschlaege, 'Der Vorschlag bleibt sichtbar');
        $this->assertTrue($vorschlaege["{$kind}/{$jung}"]['blockiert'] ?? false);
        $this->assertArrayHasKey("{$kind2}/{$alt}", $vorschlaege);
        $this->assertArrayNotHasKey('blockiert', $vorschlaege["{$kind2}/{$alt}"]);
    }

    // --- #509: Vorfilter über Indizes ---------------------------------------

    /**
     * Kandidat und Kind, die sich nur über EINEN Arm des Vorfilters finden.
     * Farbe, Deckstation und Altersabstand stimmen überein, damit auch ein
     * kurzer Namens-Treffer über die Anzeigeschwelle kommt - sonst sähe man
     * dem Ergebnis nicht an, ob der Arm das Paar gefunden hat.
     *
     * @return int ID des Kindes
     */
    private function paar(string $kandidat, string $geschlecht, string $platzhalter, array $kandidatExtra = [], array $kindExtra = []): int {
        $this->insertHorse(array_merge([
            'name' => $kandidat, 'sex' => $geschlecht, 'birth_year' => 2000,
            'color' => 'Falbe', 'breeding_station' => 'Hof Arm',
        ], $kandidatExtra));

        $rolle = $geschlecht === 'stallion' ? 'sire' : 'dam';
        return $this->insertHorse(array_merge([
            'name' => 'Nachkomme von ' . $platzhalter, "{$rolle}_name" => $platzhalter, 'birth_year' => 2010,
            'color' => 'Falbe', 'breeding_station' => 'Hof Arm',
        ], $kindExtra));
    }

    /**
     * Der Vorfilter läuft seit #509 über die generierten Suchspalten und ist
     * dafür in Arme zerlegt, die je einen Index nutzen. Ohne die Spalten läuft
     * die Fassung aus #215. Beide müssen dieselben Platzhalter zählen und
     * dieselben Vorschläge liefern - hier an dem Bestand des Orakel-Tests und
     * an den Fällen, in denen sich die Zerlegung der Präfixprüfung irren
     * könnte.
     */
    public function testIndexwegUndRueckfallLiefernDasselbe(): void {
        $this->insertFixture();

        $kinder = [
            // Nur das Klangbild stimmt: "Tho…" und "Tor…" beginnen verschieden.
            'klangbild' => $this->paar('Torsten', 'stallion', 'Thorsten'),
            // Der Kandidat ist kürzer als drei Zeichen und der Anfang des Platzhalters.
            'kurzerKandidat' => $this->paar('Bo', 'stallion', 'Bon'),
            // Der Platzhalter ist kürzer als drei Zeichen und der Anfang des Kandidaten.
            'kurzerPlatzhalter' => $this->paar('Ali', 'mare', 'Al'),
            // Beide einbuchstabig und gleich.
            'einZeichen' => $this->paar('X', 'stallion', 'X'),
            // Groß-/Kleinschreibung und Sonderzeichen folgen der Kollation der Spalte.
            'kollation' => $this->paar('ØYVIND', 'stallion', 'øyvind'),
        ];
        // Ausländische UELN, ohne jede Namensangabe.
        $this->insertHorse(['name' => 'Fremdstute', 'sex' => 'mare', 'foreign_ueln' => 'NOR 123']);
        $kinder['fremdUeln'] = $this->insertHorse(['name' => 'Fremdstute Kind', 'dam_ueln' => 'NOR 123']);
        // Ein gelöschter Kandidat zählt nicht - "Xanadu Kind" bleibt ohne Vorschlag.
        $this->insertHorse(['name' => 'Xanadu', 'sex' => 'stallion', 'deleted_at' => '2026-01-01 00:00:00']);
        // Jokerzeichen sind Namensbestandteil: "A_" passt nicht auf "Abc".
        $this->insertHorse(['name' => 'A_', 'sex' => 'stallion']);
        $joker = $this->insertHorse(['name' => 'Nachkomme von Abc', 'sire_name' => 'Abc']);

        $lauf = fn(): array => [
            'zahl' => MatchSuggestionFinder::countOpen(),
            'alle' => $this->keyedByPlaceholder(MatchSuggestionFinder::findAll()),
            'seite' => MatchSuggestionFinder::findAll(4, 2),
        ];

        $mitIndex = $lauf();
        MatchSuggestionFinder::ohneSuchspaltenFuerTests(true);
        $ohneIndex = $lauf();
        MatchSuggestionFinder::ohneSuchspaltenFuerTests(false);

        $this->assertSame($ohneIndex['zahl'], $mitIndex['zahl'], 'countOpen()');
        $this->assertSame($ohneIndex['alle'], $mitIndex['alle'], 'findAll()');
        $this->assertSame($ohneIndex['seite'], $mitIndex['seite'], 'findAll() mit LIMIT/OFFSET');

        // Und nicht nur "beide gleich", sondern auch richtig: Jeder Arm hat
        // sein Paar gefunden.
        $erwartet = [
            'klangbild' => ['sire', 'Torsten'],
            'kurzerKandidat' => ['sire', 'Bo'],
            'kurzerPlatzhalter' => ['dam', 'Ali'],
            'einZeichen' => ['sire', 'X'],
            'kollation' => ['sire', 'ØYVIND'],
            'fremdUeln' => ['dam', 'Fremdstute'],
        ];
        foreach ($erwartet as $fall => [$rolle, $name]) {
            $schluessel = $rolle . ':' . $kinder[$fall];
            $this->assertArrayHasKey($schluessel, $mitIndex['alle'], "Kein Vorschlag im Fall '{$fall}'");
            $this->assertContains(
                $name,
                array_map(static fn(array $v): string => (string)$v['horse']['name'], $mitIndex['alle'][$schluessel]['suggestions']),
                "Fall '{$fall}'"
            );
        }
        $this->assertArrayNotHasKey('sire:' . $joker, $mitIndex['alle']);

        // Ein Kandidat ohne Namen ist der "Anfang" jedes Platzhalters - er
        // macht jeden benannten Platzhalter zu einem mit Kandidaten, auch den
        // zu "Abc", der bisher keinen hatte. Auch das müssen beide Wege
        // gleich sehen.
        $zahlVorher = $mitIndex['zahl'];
        $this->insertHorse(['name' => '']);
        $mitIndex = $lauf();
        MatchSuggestionFinder::ohneSuchspaltenFuerTests(true);
        $ohneIndex = $lauf();
        $this->assertSame($ohneIndex, $mitIndex, 'Mit namenlosem Kandidaten');
        $this->assertGreaterThan($zahlVorher, $mitIndex['zahl']);
    }

    /**
     * Der Kern von #509: Je Platzhalter las der Vorfilter alle Pferde. Bei
     * 40 Platzhaltern und 400 Kandidaten sind das rund 16.000 Zeilen je
     * Zählung - über die Suchspalten ein paar Indexeinstiege je Platzhalter.
     * Gemessen an den Zeilenzugriffen der Verbindung, mit dem Rückfall als
     * Gegenprobe.
     */
    public function testDerVorfilterLiestNichtMehrAllePferdeJePlatzhalter(): void {
        $kandidaten = 400;
        $platzhalter = 40;

        $silben = ['Ba', 'De', 'Fi', 'Go', 'Hu', 'Ka', 'Le', 'Mi', 'No', 'Pu', 'Ra', 'Se', 'Ti', 'Vo', 'Wu', 'Ja', 'Ke', 'Li', 'Mo', 'Nu'];
        $zeilen = [];
        for ($i = 0; $i < $kandidaten; $i++) {
            $name = $silben[$i % 20] . strtolower($silben[intdiv($i, 20) % 20]) . 'r ' . $i;
            $zeilen[] = '(' . self::$db->quote($name) . ", 'stallion', NULL)";
        }
        // Platzhalter, zu denen es keinen Kandidaten gibt: Dann muss jeder Arm
        // bis zum Ende suchen - der ungünstigste Fall für den Vorfilter.
        for ($i = 0; $i < $platzhalter; $i++) {
            $zeilen[] = '(' . self::$db->quote('Kind ' . $i) . ', NULL, ' . self::$db->quote('Zzyq ' . $i) . ')';
        }
        self::$db->exec('INSERT INTO horses (name, sex, sire_name) VALUES ' . implode(', ', $zeilen));
        self::$db->query('ANALYZE TABLE horses')->fetchAll();

        $leer = self::zeilenzugriffeLeer(self::$db);
        $zaehlen = fn(): int => self::zeilenzugriffeVon(self::$db, function (): void {
            $this->assertSame(0, MatchSuggestionFinder::countOpen());
        }) - $leer;

        $mitIndex = $zaehlen();
        MatchSuggestionFinder::ohneSuchspaltenFuerTests(true);
        $ohneIndex = $zaehlen();

        $this->assertGreaterThanOrEqual(
            $platzhalter * $kandidaten,
            $ohneIndex,
            "Gegenprobe: Der Rückfall liest je Platzhalter alle Pferde ({$ohneIndex} Zeilenzugriffe)"
        );
        $this->assertLessThan(
            intdiv($ohneIndex, 5),
            $mitIndex,
            "Über die Suchspalten: {$mitIndex} Zeilenzugriffe, ohne sie {$ohneIndex}"
        );
    }

    /**
     * Die Suchspalten folgen dem Namen von selbst - auch nach einem UPDATE,
     * das an jedem Schreibweg der Anwendung vorbeigeht. Das ist der Grund,
     * warum sie generiert sind und nicht gepflegt.
     */
    public function testDieSuchspaltenFolgenDemNamen(): void {
        $id = $this->insertHorse(['name' => 'Thorsten']);
        $lesen = fn(): array => self::$db->query(
            "SELECT name_soundex = SOUNDEX(name) AS klang, name_prefix3 FROM horses WHERE id = {$id}"
        )->fetch(PDO::FETCH_ASSOC);

        $this->assertSame(['klang' => 1, 'name_prefix3' => 'Tho'], array_map(
            static fn($w) => is_numeric($w) ? (int)$w : $w,
            $lesen()
        ));

        self::$db->exec("UPDATE horses SET name = 'Åse' WHERE id = {$id}");
        $this->assertSame(['klang' => 1, 'name_prefix3' => 'Åse'], array_map(
            static fn($w) => is_numeric($w) ? (int)$w : $w,
            $lesen()
        ));

        $zeile = self::$db->query("SELECT * FROM horses WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        $this->assertArrayNotHasKey('name_soundex', $zeile, 'Unsichtbar: nicht in SELECT *');
        $this->assertArrayNotHasKey('name_prefix3', $zeile);
    }
}
