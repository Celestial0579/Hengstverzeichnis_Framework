<?php
// src/Service/HorseSearchCriteria.php

namespace App\Service;

/**
 * Die lesende Hälfte der Pferdesuche - öffentlicher Katalog
 * (PublicController::catalog) UND Pferdeverwaltung (HorseController::index).
 *
 * Sie prüft die Anfrage und behält zwei Dinge: die geprüften WERTE als
 * gebundene Parameter, und die Auskunft, WELCHE Bedingungen dadurch gelten.
 * SQL erzeugt sie nicht - dafür gibt es HorseSearchSql, und applyTo() reicht
 * dorthin ausschließlich HorseSearchCondition-Fälle weiter. Ein Anfragewert
 * hat auf dem Weg keine Gelegenheit mitzukommen; die Signaturen lassen ihn
 * nicht durch.
 *
 * WARUM überhaupt gemeinsam für beide Kontexte: Die Filterlogik ist
 * umfangreich (allgemeiner Begriff über 17 Spalten, dazu 13 Detailfilter) und
 * sicherheitsrelevant. Zwei Fassungen laufen unweigerlich auseinander - und
 * die Fassung, die dabei zurückbleibt, ist erfahrungsgemäß die mit den
 * Sichtbarkeitsregeln. Die Sichtbarkeitsregeln selbst stehen jetzt allein in
 * HorseSearchSql; hier wird $nurOeffentlich nur noch für eine einzige
 * Entscheidung gebraucht, siehe build().
 *
 * EINE bewusste Abweichung vom früheren Katalogverhalten (übernommen aus dem
 * Vorgänger HorseSearchFilter): Die Textfilter prüften mit empty(), und
 * empty('0') ist true - die Suche nach der Zeichenkette "0" (ein Name, eine
 * Lebensnummer) wurde also stillschweigend ignoriert und lieferte die gesamte
 * Liste. Hier zählt jeder nicht leere Wert. Die Richtung stimmt: Ein Filter,
 * der jetzt greift, engt weiter ein - sichtbar werden kann dadurch nichts,
 * was vorher verborgen war.
 */
final class HorseSearchCriteria {

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

    /** @var array<int, HorseSearchCondition> in der Reihenfolge, in der sie gelten */
    private array $conditions = [];

    /** @var array<int, mixed> */
    private array $params = [];

    /** @var array<string, string> Aktive Filter (Schlüssel => Rohwert) für Links */
    private array $active = [];

    private function __construct(private readonly bool $nurOeffentlich) {}

    /**
     * Baut die Kriterien aus einer Anfrage-Parameterquelle (in der Regel $_GET).
     *
     * @param array<string, mixed> $request
     * @param bool     $nurOeffentlich   Kontext des öffentlichen Katalogs; hier
     *                                   nur für den Veröffentlichungs-Filter
     *                                   nötig, die Sichtbarkeitsregeln selbst
     *                                   liegen in HorseSearchSql
     * @param int|null $publishedFilter  Nur im Admin: Veröffentlichungs-Filter
     *                                   der Liste (1/0), null = alle
     */
    public static function fromRequest(array $request, bool $nurOeffentlich, ?int $publishedFilter = null): self {
        $criteria = new self($nurOeffentlich);
        $criteria->build($request, $publishedFilter);
        return $criteria;
    }

    /**
     * Übergibt dem SQL-Bauplan, WELCHE Bedingungen gelten - und nur das.
     *
     * Der Bauplan wird beim Aufrufer unabhängig von der Anfrage erzeugt und
     * hier befüllt; dadurch bleibt er auch für eine Datenflussanalyse
     * nachweisbar frei von Anfragedaten (Semgrep tainted-sql-string). Der
     * Abgleich von $nurOeffentlich ist die eine Naht, die diese Trennung
     * kostet: Zwei Objekte tragen denselben Schalter, und ein
     * auseinandergelaufenes Paar hieße im öffentlichen Katalog fehlende
     * Sichtbarkeitsgrenzen. Deshalb bricht es hier ab, statt still das
     * Falsche zu bauen.
     */
    public function applyTo(HorseSearchSql $sql): void {
        if ($sql->nurOeffentlich() !== $this->nurOeffentlich) {
            throw new \LogicException(
                'HorseSearchCriteria und HorseSearchSql sind für verschiedene Kontexte gebaut '
                . '(öffentlich/Verwaltung). Beide brauchen denselben Schalter.'
            );
        }

        foreach ($this->conditions as $condition) {
            $sql->add($condition);
        }

        // Die zweite Naht, und die teurere: Klausel und Parameterliste
        // entstehen seit der Aufteilung in zwei Dateien. Stimmen ihre Zahlen
        // nicht überein, bindet PDO ab der ersten Abweichung alles um eine
        // Position versetzt - die Suche liefert dann keinen Fehler, sondern
        // stumm falsche Treffer. Das hier ist der Moment, in dem beide Zahlen
        // erstmals nebeneinanderliegen, also wird hier geprüft.
        if ($sql->placeholderCount() !== count($this->params)) {
            throw new \LogicException(sprintf(
                'Die Klausel erwartet %d gebundene Werte, die Kriterien liefern %d. '
                . 'Klausel und Parameterliste sind auseinandergelaufen.',
                $sql->placeholderCount(),
                count($this->params)
            ));
        }
    }

    /**
     * Die gebundenen Parameter in genau der Reihenfolge der Platzhalter in
     * HorseSearchSql::whereSql(). LIMIT/OFFSET hängt der Aufrufer selbst an.
     *
     * @return array<int, mixed>
     */
    public function params(): array {
        return $this->params;
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
     * @param array<string, mixed> $request
     */
    private function build(array $request, ?int $publishedFilter): void {
        // h.deleted_at IS NULL und - öffentlich - h.is_published = 1 gelten
        // immer und hängen an keinem Anfragewert; sie setzt HorseSearchSql
        // selbst. Hier steht nur, was die Anfrage bestimmt.
        if (!$this->nurOeffentlich && $publishedFilter !== null) {
            // Admin-Liste: der optionale Veröffentlichungs-Filter (?published=1|0)
            // als gebundener Parameter, nicht interpoliert.
            $this->activate(HorseSearchCondition::PublishedFlag, $publishedFilter);
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

        if ($search !== '') {
            // Derselbe Wert füllt alle 17 Platzhalter des Ausschnitts.
            $like = '%' . $search . '%';
            $this->activate(
                HorseSearchCondition::FullText,
                ...array_fill(0, HorseSearchCondition::FullText->placeholders(), $like)
            );
        }

        if ($qName !== '') {
            $this->activate(HorseSearchCondition::Name, '%' . $qName . '%');
        }

        if ($qUeln !== '') {
            $like = '%' . $qUeln . '%';
            $this->activate(
                HorseSearchCondition::Ueln,
                ...array_fill(0, HorseSearchCondition::Ueln->placeholders(), $like)
            );
        }

        if ($birthYearFrom !== null) {
            $this->activate(HorseSearchCondition::BirthYearFrom, $birthYearFrom);
        }

        if ($birthYearTo !== null) {
            $this->activate(HorseSearchCondition::BirthYearTo, $birthYearTo);
        }

        if ($qColor !== '') {
            $this->activate(HorseSearchCondition::Color, '%' . $qColor . '%');
        }

        if ($qSex !== '') {
            $this->activate(HorseSearchCondition::Sex, $qSex);
        }

        if ($qBreed !== '') {
            $this->activate(HorseSearchCondition::Breed, '%' . $qBreed . '%');
        }

        if ($qStatus === 'deceased') {
            $this->activate(HorseSearchCondition::Deceased);
        } elseif ($qStatus !== '') {
            $this->activate(HorseSearchCondition::Status, $qStatus);
        }

        if ($qBreeder !== '') {
            $this->activate(HorseSearchCondition::Breeder, '%' . $qBreeder . '%');
        }

        if ($qOwner !== '') {
            $this->activate(HorseSearchCondition::Owner, '%' . $qOwner . '%');
        }

        if ($qStation !== '') {
            $this->activate(HorseSearchCondition::Station, '%' . $qStation . '%', '%' . $qStation . '%');
        }

        if ($qSire !== '') {
            $this->activate(HorseSearchCondition::Sire, '%' . $qSire . '%', '%' . $qSire . '%');
        }

        if ($qDam !== '') {
            $this->activate(HorseSearchCondition::Dam, '%' . $qDam . '%', '%' . $qDam . '%');
        }
    }

    /**
     * Merkt eine Bedingung samt ihrer gebundenen Werte vor.
     *
     * Die Stückzahl wird gegen HorseSearchCondition::placeholders() geprüft,
     * weil das Auseinandernehmen der früheren Klasse genau hier schiefgehen
     * kann: Klausel und Parameterliste entstehen seither in zwei Dateien. Ein
     * Wert zu wenig, und PDO bindet ab dieser Stelle alles um eine Position
     * versetzt - die Suche liefert dann keinen Fehler, sondern falsche
     * Treffer.
     */
    private function activate(HorseSearchCondition $condition, mixed ...$values): void {
        if (count($values) !== $condition->placeholders()) {
            throw new \LogicException(sprintf(
                'Bedingung %s erwartet %d gebundene Werte, bekommen hat sie %d.',
                $condition->name,
                $condition->placeholders(),
                count($values)
            ));
        }

        $this->conditions[] = $condition;
        foreach ($values as $value) {
            $this->params[] = $value;
        }
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
