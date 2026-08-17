<?php
// src/Service/HorseSearchFilter.php

namespace App\Service;

/**
 * Gemeinsamer Suchbaustein für die Pferdelisten - öffentlicher Katalog
 * (PublicController::catalog) UND Pferdeverwaltung (HorseController::index).
 *
 * Der Baustein liefert WHERE-Fragmente, gebundene Parameter und die JOINs;
 * zusammengesetzt wird die Abfrage beim Aufrufer, weil beide Seiten eine
 * andere Spaltenauswahl brauchen (Katalogkarte vs. Verwaltungstabelle).
 *
 * WARUM überhaupt gemeinsam: Die Filterlogik ist umfangreich (allgemeiner
 * Begriff über 17 Spalten, dazu 13 Detailfilter) und sicherheitsrelevant.
 * Zwei Fassungen laufen unweigerlich auseinander - und die Fassung, die
 * dabei zurückbleibt, ist erfahrungsgemäß die mit den Sichtbarkeitsregeln.
 *
 * DER UNTERSCHIED ZWISCHEN DEN BEIDEN KONTEXTEN steckt in einem einzigen
 * Schalter, $nurOeffentlich:
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
 *
 * Gelöschte Datensätze (deleted_at) bleiben in BEIDEN Kontexten außen vor;
 * dafür gibt es den Papierkorb.
 *
 * EINE bewusste Abweichung vom früheren Katalogverhalten: Die Textfilter
 * prüften mit empty(), und empty('0') ist true - die Suche nach der
 * Zeichenkette "0" (ein Name, eine Lebensnummer) wurde also stillschweigend
 * ignoriert und lieferte die gesamte Liste. Hier zählt jeder nicht leere
 * Wert. Die Richtung stimmt: Ein Filter, der jetzt greift, engt weiter ein -
 * sichtbar werden kann dadurch nichts, was vorher verborgen war.
 */
final class HorseSearchFilter {

    /**
     * Alle von diesem Baustein gelesenen Anfrageparameter - zugleich die
     * Weißliste für Blätter-/Filter-Links und für die Rückkehr nach einer
     * Bulk-Aktion (siehe BaseController::listFilterQuery()).
     */
    public const FILTER_KEYS = [
        'search', 'q_name', 'q_ueln', 'birth_year_from', 'birth_year_to',
        'q_color', 'q_sex', 'q_breed', 'q_status', 'q_breeder', 'q_owner',
        'q_station', 'q_sire', 'q_dam',
    ];

    /** Gültige Werte des Geschlechtsfilters (#165), Whitelist gegen die ENUM. */
    private const SEXES = ['stallion', 'mare', 'gelding'];

    /**
     * Gültige Werte des Statusfilters. 'active'/'inactive' filtern den
     * Zuchtstatus; 'deceased' bleibt nach dem Status-Split (#188) als
     * Kompatibilitäts-Mapping auf is_deceased erhalten (Bookmarks und
     * geteilte Filter-URLs).
     */
    private const STATUSES = ['active', 'inactive', 'deceased'];

    /** @var array<int, string> */
    private array $where = [];

    /** @var array<int, mixed> */
    private array $params = [];

    /** @var array<string, string> Aktive Filter (Schlüssel => Rohwert) für Links */
    private array $active = [];

    private function __construct(private readonly bool $nurOeffentlich) {}

    /**
     * Baut den Filter aus einer Anfrage-Parameterquelle (in der Regel $_GET).
     *
     * @param array<string, mixed> $request
     * @param bool     $nurOeffentlich   Sichtbarkeitsgrenzen des öffentlichen
     *                                   Katalogs anwenden (#121/#122/#151)
     * @param int|null $publishedFilter  Nur im Admin: Veröffentlichungs-Filter
     *                                   der Liste (1/0), null = alle
     */
    public static function fromRequest(array $request, bool $nurOeffentlich, ?int $publishedFilter = null): self {
        $filter = new self($nurOeffentlich);
        $filter->build($request, $publishedFilter);
        return $filter;
    }

    /**
     * @param array<string, mixed> $request
     */
    private function build(array $request, ?int $publishedFilter): void {
        $this->where[] = "h.deleted_at IS NULL";

        if ($this->nurOeffentlich) {
            // Öffentliche Sichtbarkeit: nur veröffentlichte Pferde (is_published),
            // unabhängig vom Lebenszyklus-Status.
            $this->where[] = "h.is_published = 1";
        } elseif ($publishedFilter !== null) {
            // Admin-Liste: der optionale Veröffentlichungs-Filter (?published=1|0)
            // als gebundener Parameter, nicht interpoliert.
            $this->where[] = "h.is_published = ?";
            $this->params[] = $publishedFilter;
        }

        $search = $this->readString($request, 'search');
        $qName = $this->readString($request, 'q_name');
        $qUeln = $this->readString($request, 'q_ueln');
        $birthYearFrom = $this->readOptionalInt($request, 'birth_year_from');
        $birthYearTo = $this->readOptionalInt($request, 'birth_year_to');
        $qColor = $this->readString($request, 'q_color');
        $qSex = $this->readEnum($request, 'q_sex', self::SEXES);
        $qBreed = $this->readString($request, 'q_breed');
        $qStatus = $this->readEnum($request, 'q_status', self::STATUSES);
        $qBreeder = $this->readString($request, 'q_breeder');
        $qOwner = $this->readString($request, 'q_owner');
        $qStation = $this->readString($request, 'q_station');
        $qSire = $this->readString($request, 'q_sire');
        $qDam = $this->readString($request, 'q_dam');

        // Allgemeiner Suchbegriff über Pferdename, UELN, weitere Lebensnummern
        // (#246), Eltern, Deckstation, Züchter und Besitzer. Personen laufen über
        // EXISTS statt über multiplizierende JOINs (#125).
        if ($search !== '') {
            $like = '%' . $search . '%';
            $this->where[] = "(
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
            )";
            array_push($this->params, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like);
        }

        if ($qName !== '') {
            $this->where[] = "h.name LIKE ?";
            $this->params[] = '%' . $qName . '%';
        }

        if ($qUeln !== '') {
            // Seit #246 auch über die weiteren Lebensnummern (horse_registrations)
            // des Pferds selbst - die foreign_ueln-Spalten bleiben als
            // Kompatibilitäts-Fallback mit durchsucht.
            $like = '%' . $qUeln . '%';
            $this->where[] = "(h.ueln LIKE ? OR h.foreign_ueln LIKE ? OR h.sire_ueln LIKE ? OR h.dam_ueln LIKE ? OR sire.ueln LIKE ? OR sire.foreign_ueln LIKE ? OR dam.ueln LIKE ? OR dam.foreign_ueln LIKE ? OR EXISTS (SELECT 1 FROM horse_registrations hreg WHERE hreg.horse_id = h.id AND hreg.registration_number LIKE ?))";
            array_push($this->params, $like, $like, $like, $like, $like, $like, $like, $like, $like);
        }

        if ($birthYearFrom !== null) {
            $this->where[] = "h.birth_year >= ?";
            $this->params[] = $birthYearFrom;
        }

        if ($birthYearTo !== null) {
            $this->where[] = "h.birth_year <= ?";
            $this->params[] = $birthYearTo;
        }

        if ($qColor !== '') {
            $this->where[] = "h.color LIKE ?";
            $this->params[] = '%' . $qColor . '%';
        }

        if ($qSex !== '') {
            $this->where[] = "h.sex = ?";
            $this->params[] = $qSex;
        }

        if ($qBreed !== '') {
            $this->where[] = "h.breed LIKE ?";
            $this->params[] = '%' . $qBreed . '%';
        }

        if ($qStatus === 'deceased') {
            $this->where[] = "h.is_deceased = 1";
        } elseif ($qStatus !== '') {
            $this->where[] = "h.status = ?";
            $this->params[] = $qStatus;
        }

        if ($qBreeder !== '') {
            $this->where[] = "EXISTS (
                SELECT 1 FROM horse_persons hpb
                JOIN persons pb ON pb.id = hpb.person_id AND pb.deleted_at IS NULL" . $this->personVisibility('pb') . "
                WHERE hpb.horse_id = h.id AND hpb.role = 'breeder' AND pb.name LIKE ?
            )";
            $this->params[] = '%' . $qBreeder . '%';
        }

        if ($qOwner !== '') {
            $this->where[] = "EXISTS (
                SELECT 1 FROM horse_persons hpo
                JOIN persons po ON po.id = hpo.person_id AND po.deleted_at IS NULL" . $this->personVisibility('po') . "
                WHERE hpo.horse_id = h.id AND hpo.role = 'owner' AND po.name LIKE ?
            )";
            $this->params[] = '%' . $qOwner . '%';
        }

        if ($qStation !== '') {
            $this->where[] = '(' . $this->stationMatchSql() . ')';
            $this->params[] = '%' . $qStation . '%';
            $this->params[] = '%' . $qStation . '%';
        }

        if ($qSire !== '') {
            $this->where[] = "(sire.name LIKE ? OR h.sire_name LIKE ?)";
            $this->params[] = '%' . $qSire . '%';
            $this->params[] = '%' . $qSire . '%';
        }

        if ($qDam !== '') {
            $this->where[] = "(dam.name LIKE ? OR h.dam_name LIKE ?)";
            $this->params[] = '%' . $qDam . '%';
            $this->params[] = '%' . $qDam . '%';
        }
    }

    /** Vollständige WHERE-Klausel (ohne das Schlüsselwort WHERE). */
    public function whereSql(): string {
        return implode(' AND ', $this->where);
    }

    /**
     * Die gebundenen Parameter in genau der Reihenfolge der Platzhalter in
     * whereSql(). LIMIT/OFFSET hängt der Aufrufer selbst an.
     *
     * @return array<int, mixed>
     */
    public function params(): array {
        return $this->params;
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

    /** Ist überhaupt ein Suchfeld gesetzt? (Steuert u. a. das offene <details>.) */
    public function hasActiveFilters(): bool {
        return $this->active !== [];
    }

    /**
     * Die aktiven Filter als Parameterliste für Links (Blättern,
     * Veröffentlichungs-Filter) - ausschließlich Werte, die dieser Baustein
     * tatsächlich gelesen hat.
     *
     * @return array<string, string>
     */
    public function activeParams(): array {
        return $this->active;
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

    /**
     * Liest einen Textparameter. Nicht-Strings (etwa ?search[]=x) gelten als
     * nicht gesetzt, statt in einen TypeError zu laufen.
     *
     * @param array<string, mixed> $request
     */
    private function readString(array $request, string $key): string {
        $value = $request[$key] ?? '';
        if (!is_string($value)) {
            return '';
        }
        $value = trim($value);
        if ($value !== '') {
            $this->active[$key] = $value;
        }
        return $value;
    }

    /**
     * Liest einen Auswahlparameter gegen eine Weißliste; alles andere gilt als
     * "kein Filter".
     *
     * @param array<string, mixed> $request
     * @param array<int, string> $allowed
     */
    private function readEnum(array $request, string $key, array $allowed): string {
        $value = $request[$key] ?? '';
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            return '';
        }
        $this->active[$key] = $value;
        return $value;
    }

    /**
     * Liest einen optionalen Jahresparameter: leer = kein Filter. Ein
     * gesetzter, aber unbrauchbarer Wert wird nicht umgedeutet, sondern auf 0
     * validiert (filter_var statt Cast, siehe BaseController::requestInt()).
     *
     * @param array<string, mixed> $request
     */
    private function readOptionalInt(array $request, string $key): ?int {
        $raw = $request[$key] ?? null;
        if (!is_string($raw) && !is_int($raw)) {
            return null;
        }
        if ((string)$raw === '' || (string)$raw === '0') {
            return null;
        }
        $this->active[$key] = (string)$raw;
        $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['default' => 0]]);
        return is_int($value) ? $value : 0;
    }
}
