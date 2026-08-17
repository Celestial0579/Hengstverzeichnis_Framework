<?php
// tests/Unit/Service/HorseSearchFilterSqlSafetyTest.php

namespace Tests\Unit\Service;

use App\Service\HorseSearchFilter;
use PHPUnit\Framework\TestCase;

/**
 * Die Zusicherung, auf der die zusammengesetzte Abfrage in
 * HorseController::index() und PublicController::catalog() beruht:
 *
 *   whereSql() und joinSql() bestehen AUSSCHLIESSLICH aus Literalen des
 *   Quelltexts. Kein Zeichen aus der Anfrage landet je darin - Werte gehen
 *   ausnahmslos über params() und damit durch gebundene Platzhalter.
 *
 * Warum das einen eigenen Test verdient: Der Baustein liest die Anfrage und
 * liefert SQL. Diese beiden Aufgaben in einer Klasse sind bequem, aber genau
 * die Bauform, bei der ein späterer "kleiner" Zusatz ("hier reicht doch
 * schnell ein Spaltenname aus dem Request") eine Injektionslücke aufreißt -
 * unbemerkt, weil die Abfrage weiterhin aussieht wie vorher.
 *
 * Die statische Analyse (Semgrep, tainted-sql-string) kann das nicht
 * unterscheiden und meldet die Interpolation an den Aufrufstellen an. Dort
 * steht deshalb ein begründetes nosemgrep - und hier der Nachweis, dass die
 * Begründung stimmt und stimmen bleibt. Ein Kommentar allein wäre eine
 * Behauptung; dieser Test ist die Prüfung.
 */
class HorseSearchFilterSqlSafetyTest extends TestCase {

    /**
     * Ein Angriffsversuch in JEDEM gelesenen Parameter. Die Zeichenketten
     * sind so gewählt, dass sie in der Ausgabe sofort auffielen.
     *
     * @return array<string, string>
     */
    private static function feindseligeAnfrage(): array {
        $boese = "x' OR 1=1 -- ";
        $anfrage = [];
        foreach (HorseSearchFilter::FILTER_KEYS as $key) {
            $anfrage[$key] = $boese . $key;
        }
        // Die beiden Auswahlfelder haben eine Weißliste; hier bewusst mit
        // einem Wert daneben, damit sie als "nicht gesetzt" durchlaufen.
        return $anfrage;
    }

    /**
     * @param bool $nurOeffentlich beide Kontexte, denn sie erzeugen
     *        unterschiedliche Klauseln
     */
    #[\PHPUnit\Framework\Attributes\TestWith([true])]
    #[\PHPUnit\Framework\Attributes\TestWith([false])]
    public function testNoRequestCharacterEverReachesTheSql(bool $nurOeffentlich): void {
        $filter = HorseSearchFilter::fromRequest(self::feindseligeAnfrage(), $nurOeffentlich, 1);

        $sql = $filter->whereSql() . ' ' . $filter->joinSql() . ' ' . $filter->personAggregateJoin();

        $this->assertStringNotContainsString('OR 1=1', $sql, 'Ein Anfragewert ist in die Klausel geraten.');
        $this->assertStringNotContainsString('--', $sql, 'Kein Kommentarzeichen in der Klausel.');
        $this->assertStringNotContainsString(';', $sql, 'Kein Semikolon - eine zweite Anweisung wäre hier nie zu erklären.');

        foreach (HorseSearchFilter::FILTER_KEYS as $key) {
            $this->assertStringNotContainsString(
                $key,
                $filter->whereSql(),
                "Der Anfrageschlüssel '{$key}' darf nicht in der Klausel auftauchen."
            );
        }

        // Der eigentliche Beweis, schärfer als jede Zeichensuche: Dieselben
        // Filter mit völlig anderen WERTEN ergeben eine byte-identische
        // Klausel. Die Form der Abfrage hängt also allein davon ab, WELCHE
        // Felder gesetzt sind - nie davon, was darin steht.
        $harmlos = [];
        foreach (HorseSearchFilter::FILTER_KEYS as $key) {
            $harmlos[$key] = 'Bella';
        }
        $zwilling = HorseSearchFilter::fromRequest($harmlos, $nurOeffentlich, 1);

        $this->assertSame($zwilling->whereSql(), $filter->whereSql());
        $this->assertSame($zwilling->joinSql(), $filter->joinSql());
        $this->assertSame($zwilling->personAggregateJoin(), $filter->personAggregateJoin());
    }

    /**
     * Die Gegenprobe zum Test oben: Die feindseligen Werte sind nicht etwa
     * verschwunden, sie stehen als gebundene Parameter bereit. Ohne diese
     * Hälfte wäre "kein Wert in der Klausel" auch dann erfüllt, wenn der
     * Filter schlicht nichts täte.
     */
    public function testTheHostileValuesArrivedAsBoundParametersInstead(): void {
        $filter = HorseSearchFilter::fromRequest(self::feindseligeAnfrage(), false, null);
        $params = $filter->params();

        $this->assertNotEmpty($params, 'Die Filter müssen greifen, sonst prüft der Test oben nichts.');
        $treffer = array_filter($params, static fn(mixed $p): bool => is_string($p) && str_contains($p, 'OR 1=1'));
        $this->assertNotEmpty($treffer, 'Die Werte gehören in die Parameterliste, gebunden statt eingesetzt.');

        // Die Zahl der Platzhalter muss zur Zahl der Parameter passen -
        // laufen sie auseinander, bindet PDO die Werte an die falschen
        // Stellen, und der Filter liefert stillschweigend Unsinn.
        $this->assertSame(
            substr_count($filter->whereSql(), '?'),
            count($params),
            'Platzhalter und Parameter müssen sich exakt entsprechen.'
        );
    }

    /**
     * Dieselbe Prüfung für den Veröffentlichungs-Filter der Verwaltung: Auch
     * er ist ein gebundener Parameter, keine interpolierte Zahl.
     */
    public function testPublishedFilterIsBoundToo(): void {
        $ohne = HorseSearchFilter::fromRequest([], false, null);
        $mit = HorseSearchFilter::fromRequest([], false, 0);

        $this->assertStringNotContainsString('is_published = 0', $mit->whereSql());
        $this->assertStringContainsString('h.is_published = ?', $mit->whereSql());
        $this->assertSame([0], $mit->params());
        $this->assertSame([], $ohne->params());
    }
}
