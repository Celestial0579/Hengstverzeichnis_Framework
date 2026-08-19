<?php
// src/Service/HorseSearchSql.php

namespace App\Service;

/**
 * Die erzeugende Hälfte der Pferdesuche: Sie baut WHERE-Fragmente und JOINs -
 * und sie bekommt die Werte aus der Anfrage GAR NICHT ERST ZU SEHEN.
 *
 * Was hereinkommt, ist ausschließlich ein HorseSearchCondition, also die
 * Auskunft, WELCHE Bedingung gilt; womit sie verglichen wird, erfährt diese
 * Klasse nie. Jeder Anfragewert steckt hinter einem Platzhalter und wird von
 * HorseSearchCriteria gebunden.
 *
 * WARUM diese Trennung, obwohl beides vorher in HorseSearchFilter zusammen
 * lag und funktionierte: Semgrep meldete an den vier Einsetzstellen in
 * HorseController::index() und PublicController::catalog()
 * tainted-sql-string. Der Fund war sachlich ein Fehlalarm, aber kein
 * grundloser - eine Klasse, die die Anfrage liest UND SQL erzeugt, hat den
 * Missgriff immer schon in Reichweite: Ein späterer Zusatz ("hier reicht doch
 * schnell ein Spaltenname aus dem Request") stünde zwei Zeilen neben den
 * Rohwerten und sähe unauffällig aus. Jetzt gibt es diese Reichweite nicht
 * mehr: In dieser Klasse existiert keine Variable, die je einen Anfragewert
 * enthalten hat, und add() nimmt nichts entgegen, in dem einer stecken
 * könnte. Damit ist der Fund nicht unterdrückt, sondern gegenstandslos - und
 * die Analyse folgt der Trennung auch, weil der Bauplan im Aufrufer
 * unabhängig von $_GET entsteht.
 *
 * DER UNTERSCHIED ZWISCHEN DEN BEIDEN KONTEXTEN steckt weiterhin in einem
 * einzigen Schalter, $nurOeffentlich:
 *
 * - Öffentlich (true) werden verknüpfte Personen, Deckstationen und
 *   Elterntiere überall zusätzlich auf is_published = 1 eingeschränkt. Das
 *   ist Absicht (#121, #122, #151): Ohne diese Einschränkung wäre schon die
 *   bloße Trefferzahl ein Existenz-Orakel für Namen, die der Betreiber
 *   bewusst depubliziert hat (typischer Fall: DSGVO-Widerspruch).
 *
 * - Im Admin (false) gilt das nicht: Wer die Verwaltung sieht, darf auch
 *   unveröffentlichte Züchter, Stationen und Elterntiere finden - sonst
 *   fände er ausgerechnet die Datensätze nicht, die er freigeben soll.
 */
final class HorseSearchSql {

    /**
     * Wie viele Pferde-IDs personNamesSql() erwartet.
     *
     * Muss mit PublicController::CATALOG_PER_PAGE uebereinstimmen - genau das
     * prueft HorseSearchSqlSafetyTest, denn ein auseinandergelaufenes Paar
     * hiesse entweder abgeschnittene Namen auf den letzten Karten oder
     * unnoetige Platzhalter.
     */
    public const PERSON_NAMES_BATCH = 24;

    /** @var array<int, HorseSearchCondition> in Anmeldereihenfolge */
    private array $conditions = [];

    /**
     * @param bool $nurOeffentlich Sichtbarkeitsgrenzen des öffentlichen
     *                             Katalogs anwenden (#121/#122/#151)
     */
    public function __construct(private readonly bool $nurOeffentlich) {
        // Zwei Bedingungen gelten immer und hängen an nichts, was in der
        // Anfrage stehen könnte - sie werden deshalb hier gesetzt und nicht
        // von der lesenden Seite angemeldet. Die Reihenfolge zählt: Sie
        // stehen in der fertigen Klausel vorn, so wie bisher auch.
        $this->conditions[] = HorseSearchCondition::NotDeleted;
        if ($nurOeffentlich) {
            $this->conditions[] = HorseSearchCondition::PublishedOnly;
        }
    }

    /** Gilt für diesen Bauplan die öffentliche Sichtbarkeitsgrenze? */
    public function nurOeffentlich(): bool {
        return $this->nurOeffentlich;
    }

    /**
     * Meldet eine Bedingung an. Die einzige Tür in diese Klasse hinein - und
     * sie ist absichtlich so schmal, dass nichts hindurchpasst, was aus der
     * Anfrage stammen könnte: ein Aufzählungsfall, sonst nichts.
     */
    public function add(HorseSearchCondition $condition): void {
        $this->conditions[] = $condition;
    }

    /** Vollständige WHERE-Klausel (ohne das Schlüsselwort WHERE). */
    public function whereSql(): string {
        return implode(' AND ', array_map(
            fn(HorseSearchCondition $condition): string => $this->fragment($condition),
            $this->conditions
        ));
    }

    /**
     * Zahl der Platzhalter in der fertigen Klausel.
     * HorseSearchCriteria::applyTo() hält am Ende seine Parameterliste
     * dagegen; laufen die beiden auseinander, bindet PDO an die falschen
     * Stellen und die Suche liefert stumm falsche Treffer.
     */
    public function placeholderCount(): int {
        $summe = 0;
        foreach ($this->conditions as $condition) {
            $summe += $condition->placeholders();
        }
        return $summe;
    }

    /**
     * Basis-JOINs, die COUNT- und Daten-Abfrage gleichermaßen brauchen (bs,
     * sire und dam werden in den Filtern referenziert). Alle 1:1, daher keine
     * Zeilen-Vervielfachung und kein DISTINCT nötig (#125).
     */
    public function joinSql(): string {
        $sichtbar = $this->nurOeffentlich ? ' AND %s.is_published = 1' : '';
        return "
            FROM horses h
            LEFT JOIN breeding_stations bs ON h.breeding_station_id = bs.id AND bs.deleted_at IS NULL" . sprintf($sichtbar, 'bs') . "
            LEFT JOIN horses sire ON h.sire_id = sire.id AND sire.deleted_at IS NULL" . sprintf($sichtbar, 'sire') . "
            LEFT JOIN horses dam ON h.dam_id = dam.id AND dam.deleted_at IS NULL" . sprintf($sichtbar, 'dam') . "
        ";
    }

    /**
     * Züchter/Besitzer aggregiert statt über multiplizierende JOINs (#125): ein
     * Pferd mit mehreren Züchtern/Besitzern erzeugt so genau EINE Zeile. Nur für
     * Aufrufer, die die Namen auch anzeigen - die Filter kommen ohne aus.
     */
    public function personAggregateJoin(): string {
        return "
            LEFT JOIN (
                SELECT hp.horse_id,
                       GROUP_CONCAT(DISTINCT CASE WHEN hp.role = 'breeder' THEN p.name END SEPARATOR ', ') AS breeder_name,
                       GROUP_CONCAT(DISTINCT CASE WHEN hp.role = 'owner' THEN p.name END SEPARATOR ', ') AS owner_name
                FROM horse_persons hp
                JOIN persons p ON p.id = hp.person_id AND p.deleted_at IS NULL" . $this->personVisibility('p') . "
                GROUP BY hp.horse_id
            ) hpx ON hpx.horse_id = h.id
        ";
    }

    /**
     * Die Zuechter-/Besitzernamen fuer eine BEREITS ermittelte Seite von
     * Pferden (#320) - dieselbe Sichtbarkeitsregel wie personAggregateJoin(),
     * nur nachgelagert statt in der paginierten Abfrage.
     *
     * Der Grund fuer die zweite Abfrage: personAggregateJoin() enthaelt ein
     * GROUP BY und laesst sich deshalb nicht in die aeussere Abfrage
     * hineinziehen. MySQL materialisiert die abgeleitete Tabelle ueber die
     * GESAMTE horse_persons/persons-Menge, obwohl am Ende 24 Zeilen
     * uebrigbleiben. Beim Endlos-Scrollen wurde dieser Aufbau je
     * Nachladeschritt wiederholt.
     *
     * Die Regel selbst steht weiterhin nur hier und nicht im Aufrufer: Sie
     * entscheidet, ob unveroeffentlichte Personennamen oeffentlich werden
     * (#121), und zwei Fassungen davon waeren genau die Sorte Doppelung, die
     * irgendwann auseinanderlaeuft.
     *
     * OHNE PARAMETER, und das ist keine Bequemlichkeit: HorseSearchSqlSafetyTest
     * prueft per Reflection, dass in diese Klasse ausser HorseSearchCondition
     * und bool nichts hineinpasst - damit hier gar keine Tuer entsteht, durch
     * die ein Anfragewert in die Klausel kaeme. Auch ein harmlos wirkendes
     * `int $anzahl` waere so eine Tuer, und die Regel lebt davon, dass sie
     * keine Ausnahmen kennt. Die Zahl der Platzhalter steht deshalb fest;
     * kuerzere Seiten fuellt der Aufrufer mit 0 auf (die ID 0 gibt es nicht).
     */
    public function personNamesSql(): string {
        $platzhalter = implode(', ', array_fill(0, self::PERSON_NAMES_BATCH, '?'));
        return "
            SELECT hp.horse_id,
                   GROUP_CONCAT(DISTINCT CASE WHEN hp.role = 'breeder' THEN p.name END SEPARATOR ', ') AS breeder_name,
                   GROUP_CONCAT(DISTINCT CASE WHEN hp.role = 'owner' THEN p.name END SEPARATOR ', ') AS owner_name
            FROM horse_persons hp
            JOIN persons p ON p.id = hp.person_id AND p.deleted_at IS NULL" . $this->personVisibility('p') . "
            WHERE hp.horse_id IN ({$platzhalter})
            GROUP BY hp.horse_id
        ";
    }

    /**
     * Der SQL-Ausschnitt einer einzelnen Bedingung. Jeder Zweig liefert
     * ausschließlich Literale dieser Datei; die einzigen Stellen, an denen
     * etwas eingesetzt wird, sind die beiden privaten Helfer weiter unten -
     * und auch die kennen nur $nurOeffentlich und feste Tabellen-Aliase.
     */
    private function fragment(HorseSearchCondition $condition): string {
        return match ($condition) {
            HorseSearchCondition::NotDeleted => "h.deleted_at IS NULL",
            HorseSearchCondition::PublishedOnly => "h.is_published = 1",
            HorseSearchCondition::PublishedFlag => "h.is_published = ?",

            // Allgemeiner Suchbegriff über Pferdename, UELN, weitere Lebensnummern
            // (#246), Eltern, Deckstation, Züchter und Besitzer. Personen laufen über
            // EXISTS statt über multiplizierende JOINs (#125).
            HorseSearchCondition::FullText => "(
                h.name LIKE ? OR
                h.ueln LIKE ? OR
                h.foreign_ueln LIKE ? OR
                h.sire_name LIKE ? OR
                h.sire_ueln LIKE ? OR
                h.dam_name LIKE ? OR
                h.dam_ueln LIKE ? OR
                sire.name LIKE ? OR
                sire.ueln LIKE ? OR
                sire.foreign_ueln LIKE ? OR
                dam.name LIKE ? OR
                dam.ueln LIKE ? OR
                dam.foreign_ueln LIKE ? OR
                " . $this->stationMatchSql() . " OR
                EXISTS (
                    SELECT 1 FROM horse_persons hps
                    JOIN persons ps ON ps.id = hps.person_id AND ps.deleted_at IS NULL" . $this->personVisibility('ps') . "
                    WHERE hps.horse_id = h.id AND ps.name LIKE ?
                ) OR
                EXISTS (
                    SELECT 1 FROM horse_registrations hreg
                    WHERE hreg.horse_id = h.id AND hreg.registration_number LIKE ?
                )
            )",

            HorseSearchCondition::Name => "h.name LIKE ?",

            // Seit #246 auch über die weiteren Lebensnummern (horse_registrations)
            // des Pferds selbst - die foreign_ueln-Spalten bleiben als
            // Kompatibilitäts-Fallback mit durchsucht.
            HorseSearchCondition::Ueln => "(h.ueln LIKE ? OR h.foreign_ueln LIKE ? OR h.sire_ueln LIKE ? OR h.dam_ueln LIKE ? OR sire.ueln LIKE ? OR sire.foreign_ueln LIKE ? OR dam.ueln LIKE ? OR dam.foreign_ueln LIKE ? OR EXISTS (SELECT 1 FROM horse_registrations hreg WHERE hreg.horse_id = h.id AND hreg.registration_number LIKE ?))",

            HorseSearchCondition::BirthYearFrom => "h.birth_year >= ?",
            HorseSearchCondition::BirthYearTo => "h.birth_year <= ?",
            HorseSearchCondition::Color => "h.color LIKE ?",
            HorseSearchCondition::Sex => "h.sex = ?",
            HorseSearchCondition::Breed => "h.breed LIKE ?",
            HorseSearchCondition::Deceased => "h.is_deceased = 1",
            HorseSearchCondition::Status => "h.status = ?",

            HorseSearchCondition::Breeder => "EXISTS (
                SELECT 1 FROM horse_persons hpb
                JOIN persons pb ON pb.id = hpb.person_id AND pb.deleted_at IS NULL" . $this->personVisibility('pb') . "
                WHERE hpb.horse_id = h.id AND hpb.role = 'breeder' AND pb.name LIKE ?
            )",

            HorseSearchCondition::Owner => "EXISTS (
                SELECT 1 FROM horse_persons hpo
                JOIN persons po ON po.id = hpo.person_id AND po.deleted_at IS NULL" . $this->personVisibility('po') . "
                WHERE hpo.horse_id = h.id AND hpo.role = 'owner' AND po.name LIKE ?
            )",

            HorseSearchCondition::Station => '(' . $this->stationMatchSql() . ')',
            HorseSearchCondition::Sire => "(sire.name LIKE ? OR h.sire_name LIKE ?)",
            HorseSearchCondition::Dam => "(dam.name LIKE ? OR h.dam_name LIKE ?)",
        };
    }

    /**
     * Sichtbarkeitszusatz für verknüpfte Personen. Öffentlich zwingend
     * (#121), im Admin bewusst nicht - siehe Klassenkommentar.
     */
    private function personVisibility(string $alias): string {
        return $this->nurOeffentlich ? " AND {$alias}.is_published = 1" : '';
    }

    /**
     * Treffer auf die Deckstation: über den verknüpften Datensatz (bs) oder
     * über den denormalisierten Text in horses.breeding_station.
     *
     * Öffentlich zählt der Text NUR ohne breeding_station_id, ist also echter
     * Freitext (z. B. aus dem CSV-Import). Bei gesetzter ID ist er die Kopie
     * des Stationsnamens; träfe er auch dort, läge der Name einer
     * unveröffentlichten Station über die Kopie wieder offen (#151, analog
     * #121) - der bs-JOIN ist öffentlich ja gerade auf is_published = 1
     * eingeschränkt. Im Admin gibt es nichts zu verbergen, dort zählt der
     * Text immer.
     *
     * Beide Fassungen binden exakt zwei Parameter, die Reihenfolge der
     * Platzhalter bleibt also gleich. Geliefert wird die nackte
     * ODER-Verkettung ohne äußere Klammern - innerhalb der großen
     * ODER-Kette des allgemeinen Suchbegriffs stünde sie sonst überflüssig
     * da; die eigenständige Verwendung klammert selbst.
     */
    private function stationMatchSql(): string {
        return $this->nurOeffentlich
            ? "bs.name LIKE ? OR (h.breeding_station_id IS NULL AND h.breeding_station LIKE ?)"
            : "bs.name LIKE ? OR h.breeding_station LIKE ?";
    }
}
