<?php
// tests/Unit/Service/HorseSearchSqlSafetyTest.php

namespace Tests\Unit\Service;

use App\Service\HorseSearchCondition;
use App\Service\HorseSearchCriteria;
use App\Service\HorseSearchSql;
use PHPUnit\Framework\TestCase;

/**
 * Die Zusicherung, auf der die zusammengesetzte Abfrage in
 * HorseController::index() und PublicController::catalog() beruht:
 *
 *   whereSql() und joinSql() bestehen AUSSCHLIESSLICH aus Literalen des
 *   Quelltexts. Kein Zeichen aus der Anfrage landet je darin - Werte gehen
 *   ausnahmslos über params() und damit durch gebundene Platzhalter.
 *
 * Warum das einen eigenen Test verdient: Bis zur Aufteilung las EIN Baustein
 * (HorseSearchFilter) die Anfrage und lieferte SQL. Diese beiden Aufgaben in
 * einer Klasse sind bequem, aber genau die Bauform, bei der ein späterer
 * "kleiner" Zusatz ("hier reicht doch schnell ein Spaltenname aus dem
 * Request") eine Injektionslücke aufreißt - unbemerkt, weil die Abfrage
 * weiterhin aussieht wie vorher. Semgrep (tainted-sql-string) meldete die
 * Interpolation an den Aufrufstellen folgerichtig an.
 *
 * Seither sind es zwei Bausteine: HorseSearchCriteria liest und bindet,
 * HorseSearchSql erzeugt. Dieser Test prüft beide Hälften und zusätzlich die
 * Naht dazwischen - denn eine Trennung, die niemand nachprüft, wächst wieder
 * zusammen.
 */
class HorseSearchSqlSafetyTest extends TestCase {

    /**
     * Ein Angriffsversuch in JEDEM gelesenen Parameter. Die Zeichenketten
     * sind so gewählt, dass sie in der Ausgabe sofort auffielen.
     *
     * @return array<string, string>
     */
    private static function feindseligeAnfrage(): array {
        $boese = "x' OR 1=1 -- ";
        $anfrage = [];
        foreach (HorseSearchCriteria::FILTER_KEYS as $key) {
            $anfrage[$key] = $boese . $key;
        }
        // Die beiden Auswahlfelder haben eine Weißliste; hier bewusst mit
        // einem Wert daneben, damit sie als "nicht gesetzt" durchlaufen.
        return $anfrage;
    }

    /**
     * Beide Hälften so zusammengesetzt, wie die Controller es tun.
     *
     * @param array<string, mixed> $request
     * @return array{HorseSearchSql, HorseSearchCriteria}
     */
    private static function suche(array $request, bool $nurOeffentlich, ?int $publishedFilter = null): array {
        $sql = new HorseSearchSql($nurOeffentlich);
        $criteria = HorseSearchCriteria::fromRequest($request, $nurOeffentlich, $publishedFilter);
        $criteria->applyTo($sql);

        return [$sql, $criteria];
    }

    /**
     * @param bool $nurOeffentlich beide Kontexte, denn sie erzeugen
     *        unterschiedliche Klauseln
     */
    #[\PHPUnit\Framework\Attributes\TestWith([true])]
    #[\PHPUnit\Framework\Attributes\TestWith([false])]
    public function testNoRequestCharacterEverReachesTheSql(bool $nurOeffentlich): void {
        [$sqlBau] = self::suche(self::feindseligeAnfrage(), $nurOeffentlich, 1);

        $sql = $sqlBau->whereSql() . ' ' . $sqlBau->joinSql() . ' ' . $sqlBau->personAggregateJoin();

        $this->assertStringNotContainsString('OR 1=1', $sql, 'Ein Anfragewert ist in die Klausel geraten.');
        $this->assertStringNotContainsString('--', $sql, 'Kein Kommentarzeichen in der Klausel.');
        $this->assertStringNotContainsString(';', $sql, 'Kein Semikolon - eine zweite Anweisung wäre hier nie zu erklären.');

        foreach (HorseSearchCriteria::FILTER_KEYS as $key) {
            $this->assertStringNotContainsString(
                $key,
                $sqlBau->whereSql(),
                "Der Anfrageschlüssel '{$key}' darf nicht in der Klausel auftauchen."
            );
        }

        // Der eigentliche Beweis, schärfer als jede Zeichensuche: Dieselben
        // Filter mit völlig anderen WERTEN ergeben eine byte-identische
        // Klausel. Die Form der Abfrage hängt also allein davon ab, WELCHE
        // Felder gesetzt sind - nie davon, was darin steht.
        $harmlos = [];
        foreach (HorseSearchCriteria::FILTER_KEYS as $key) {
            $harmlos[$key] = 'Bella';
        }
        [$zwilling] = self::suche($harmlos, $nurOeffentlich, 1);

        $this->assertSame($zwilling->whereSql(), $sqlBau->whereSql());
        $this->assertSame($zwilling->joinSql(), $sqlBau->joinSql());
        $this->assertSame($zwilling->personAggregateJoin(), $sqlBau->personAggregateJoin());
    }

    /**
     * Die Gegenprobe zum Test oben: Die feindseligen Werte sind nicht etwa
     * verschwunden, sie stehen als gebundene Parameter bereit. Ohne diese
     * Hälfte wäre "kein Wert in der Klausel" auch dann erfüllt, wenn der
     * Filter schlicht nichts täte.
     */
    public function testTheHostileValuesArrivedAsBoundParametersInstead(): void {
        [$sqlBau, $criteria] = self::suche(self::feindseligeAnfrage(), false, null);
        $params = $criteria->params();

        $this->assertNotEmpty($params, 'Die Filter müssen greifen, sonst prüft der Test oben nichts.');
        $treffer = array_filter($params, static fn(mixed $p): bool => is_string($p) && str_contains($p, 'OR 1=1'));
        $this->assertNotEmpty($treffer, 'Die Werte gehören in die Parameterliste, gebunden statt eingesetzt.');

        // Die Zahl der Platzhalter muss zur Zahl der Parameter passen -
        // laufen sie auseinander, bindet PDO die Werte an die falschen
        // Stellen, und der Filter liefert stillschweigend Unsinn. Seit der
        // Aufteilung entstehen die beiden Zahlen in ZWEI Dateien; damit ist
        // diese Prüfung von einer Formalie zur eigentlichen Naht geworden.
        $this->assertSame(
            substr_count($sqlBau->whereSql(), '?'),
            count($params),
            'Platzhalter und Parameter müssen sich exakt entsprechen.'
        );
    }

    /**
     * Dieselbe Prüfung für den Veröffentlichungs-Filter der Verwaltung: Auch
     * er ist ein gebundener Parameter, keine interpolierte Zahl.
     */
    public function testPublishedFilterIsBoundToo(): void {
        [$ohneSql, $ohne] = self::suche([], false, null);
        [$mitSql, $mit] = self::suche([], false, 0);

        $this->assertStringNotContainsString('is_published = 0', $mitSql->whereSql());
        $this->assertStringContainsString('h.is_published = ?', $mitSql->whereSql());
        $this->assertSame([0], $mit->params());
        $this->assertSame([], $ohne->params());
        $this->assertStringNotContainsString('h.is_published', $ohneSql->whereSql());
    }

    /**
     * Die Naht zwischen den beiden Hälften, Fall für Fall: Der SQL-Ausschnitt
     * einer Bedingung muss genau so viele Platzhalter haben, wie
     * HorseSearchCondition::placeholders() ankündigt - und zwar in BEIDEN
     * Kontexten, denn öffentlich sehen mehrere Ausschnitte anders aus.
     *
     * Der Test zieht jede Bedingung einzeln durch, weil ein Fehler sonst
     * untergehen könnte: Zwei Ausschnitte, von denen einer einen Platzhalter
     * zu viel und der andere einen zu wenig hat, ergäben in der Summe wieder
     * die richtige Zahl - und eine Suche, die stumm falsche Treffer liefert.
     */
    #[\PHPUnit\Framework\Attributes\TestWith([true])]
    #[\PHPUnit\Framework\Attributes\TestWith([false])]
    public function testEveryConditionAnnouncesItsPlaceholderCountCorrectly(bool $nurOeffentlich): void {
        foreach (HorseSearchCondition::cases() as $condition) {
            $leer = new HorseSearchSql($nurOeffentlich);
            $mit = new HorseSearchSql($nurOeffentlich);
            $mit->add($condition);

            // placeholdersFor() statt placeholders(): Der Addon-Filter hat
            // seit #371 eine variable Länge, die nur die SQL-Hälfte kennt.
            $this->assertSame(
                $condition->placeholdersFor($mit),
                substr_count($mit->whereSql(), '?') - substr_count($leer->whereSql(), '?'),
                "Der Ausschnitt für {$condition->name} passt nicht zu placeholdersFor()."
            );
            $this->assertSame(
                $condition->placeholdersFor($mit),
                $mit->placeholderCount() - $leer->placeholderCount(),
                "placeholderCount() zählt {$condition->name} falsch."
            );
        }
    }

    /**
     * Das eigentliche Ergebnis der Aufteilung, und der Grund, warum der
     * Semgrep-Fund damit erledigt ist statt nur stumm: In die erzeugende
     * Hälfte passt gar nichts hinein, in dem ein Anfragewert stecken könnte.
     *
     * Geprüft wird die Signatur, nicht das Verhalten - denn hier soll gerade
     * verhindert werden, dass jemand später eine Tür einbaut. Ein
     * add(string $fragment) oder ein orderBy(string $spalte) fiele hier
     * sofort auf, und genau solche Zusätze waren an der alten Bauform die
     * Gefahr.
     */
    public function testTheGeneratingHalfCannotAcceptRequestData(): void {
        // PluginIdCount ist bewusst dabei, ein blankes 'int' bewusst NICHT
        // (#371): Das Wertobjekt lässt sich ausschließlich aus einem Array
        // bilden, seine Zahl ist also immer eine Array-Länge. Ein
        // Anfragewert kann diese Form gar nicht annehmen. Mit 'int' in der
        // Liste stünde die Tür dagegen für jede künftige Zahl offen - und
        // genau das soll dieser Test verhindern.
        $erlaubt = [HorseSearchCondition::class, 'bool', \App\Service\PluginIdCount::class];

        $klasse = new \ReflectionClass(HorseSearchSql::class);
        foreach ($klasse->getMethods(\ReflectionMethod::IS_PUBLIC) as $methode) {
            foreach ($methode->getParameters() as $parameter) {
                $typ = $parameter->getType();
                $this->assertInstanceOf(
                    \ReflectionNamedType::class,
                    $typ,
                    "HorseSearchSql::{$methode->getName()}() nimmt einen Parameter ohne eindeutigen Typ."
                );
                $this->assertContains(
                    $typ->getName(),
                    $erlaubt,
                    "HorseSearchSql::{$methode->getName()}() nimmt '{$typ->getName()}' entgegen. "
                    . 'Die erzeugende Hälfte darf nur Bedingungen kennen - über einen anderen Typ '
                    . 'käme ein Anfragewert in die Klausel.'
                );
            }
        }
    }

    /**
     * Die IN-Liste des Addon-Filters (#371), Fall für Fall.
     *
     * Der frühere FIND_IN_SET(h.id, ?) hatte eine feste Platzhalterzahl und
     * war deshalb hier unauffällig - er war aber nicht sargable und zwang die
     * Katalogabfrage zum Durchlauf über alle Pferde. Die IN-Liste nutzt den
     * Primärschlüssel; ihr Preis ist eine variable Platzhalterzahl, und die
     * muss zur Parameterliste passen, sonst bindet PDO versetzt.
     */
    public function testPluginFilterBuildsAnInListThatMatchesItsParameterCount(): void {
        foreach ([0, 1, 3, 25] as $anzahl) {
            // NICHT range(1, $anzahl): range(1, 0) zählt rückwärts und liefert
            // [1, 0] statt einer leeren Liste.
            $ids = $anzahl === 0 ? [] : range(1, $anzahl);
            $leer = new HorseSearchSql(true);
            $sql = new HorseSearchSql(true);
            $sql->setPluginIdCount(\App\Service\PluginIdCount::fromIds($ids));
            $sql->add(HorseSearchCondition::PluginIds);

            $klausel = $sql->whereSql();

            if ($anzahl === 0) {
                // "Keine Treffer" muss aussprechbar bleiben - h.id IN () wäre
                // ein Syntaxfehler.
                $this->assertStringContainsString('0 = 1', $klausel);
                $this->assertSame(0, substr_count($klausel, '?'));
            } else {
                $this->assertStringContainsString('h.id IN (', $klausel);
                $this->assertStringNotContainsString('FIND_IN_SET', $klausel);
            }

            $this->assertSame(
                $anzahl,
                substr_count($klausel, '?') - substr_count($leer->whereSql(), '?'),
                "Klausel für {$anzahl} Kennungen"
            );
            $this->assertSame(
                $anzahl,
                $sql->placeholderCount() - $leer->placeholderCount(),
                "placeholderCount() für {$anzahl} Kennungen"
            );
        }
    }

    /**
     * Und die Naht zur anderen Hälfte: Kennungen aus einem Addon müssen als
     * gebundene Werte ankommen, nicht in der Klausel stehen.
     */
    public function testPluginIdsAreBoundValuesAndNeverPartOfTheClause(): void {
        $sql = new HorseSearchSql(true);
        $sql->setPluginIdCount(\App\Service\PluginIdCount::fromIds([4711, 4712]));
        $sql->add(HorseSearchCondition::PluginIds);

        $this->assertStringNotContainsString('4711', $sql->whereSql());
        $this->assertStringContainsString('h.id IN (?,?)', $sql->whereSql());
    }

    /**
     * Zweite Naht derselben Art: personNamesSql() kommt ohne Parameter aus
     * (die Regel oben lässt keinen int zu) und emittiert deshalb eine FESTE
     * Zahl Platzhalter. Läuft sie von der Seitengrösse des Katalogs weg,
     * fehlten auf den letzten Karten die Namen oder es blieben Platzhalter
     * ohne Wert - beides still. Also bricht es hier ab.
     */
    public function testPersonNamesBatchMatchesTheCatalogPageSize(): void {
        $seitengroesse = (new \ReflectionClass(\App\Controllers\PublicController::class))
            ->getConstant('CATALOG_PER_PAGE');

        $this->assertSame(
            $seitengroesse,
            HorseSearchSql::PERSON_NAMES_BATCH,
            'PERSON_NAMES_BATCH und PublicController::CATALOG_PER_PAGE müssen übereinstimmen.'
        );

        $this->assertSame(
            HorseSearchSql::PERSON_NAMES_BATCH,
            substr_count((new HorseSearchSql(true))->personNamesSql(), '?'),
            'personNamesSql() muss genau PERSON_NAMES_BATCH Platzhalter emittieren.'
        );
    }

    /**
     * Die Aufteilung kostet eine Naht: Beide Hälften tragen den Schalter
     * $nurOeffentlich. Ein auseinandergelaufenes Paar hieße im öffentlichen
     * Katalog fehlende Sichtbarkeitsgrenzen (#121/#122/#151) - also bricht es
     * ab, statt still das Falsche zu bauen.
     */
    public function testMismatchedVisibilityContextIsRefused(): void {
        $criteria = HorseSearchCriteria::fromRequest(['q_name' => 'Bella'], true);

        $this->expectException(\LogicException::class);
        $criteria->applyTo(new HorseSearchSql(false));
    }

    /**
     * Die andere Naht, zur Laufzeit: Klausel und Parameterliste müssen
     * gleich viele Stellen haben. Hier künstlich herbeigeführt, indem eine
     * Bedingung am Bauplan angemeldet wird, zu der es keine Werte gibt -
     * echte Ursache wäre ein Ausschnitt in HorseSearchSql, der einen
     * Platzhalter mehr oder weniger bekommt als placeholders() ankündigt.
     * Ohne diese Prüfung liefe die Suche weiter und gäbe stumm falsche
     * Treffer aus.
     */
    public function testPlaceholdersWithoutMatchingParametersAreRefused(): void {
        $sqlBau = new HorseSearchSql(false);
        $sqlBau->add(HorseSearchCondition::Name);

        $criteria = HorseSearchCriteria::fromRequest([], false);

        $this->expectException(\LogicException::class);
        $criteria->applyTo($sqlBau);
    }
}
