<?php
// src/Service/MatchSuggestionFinder.php

namespace App\Service;

use App\Database;

/**
 * Class MatchSuggestionFinder
 *
 * Blutlinien-Match-/Merge-Vorschlagslogik, aus `HorseController::matches()`/
 * `calculateSuggestions()` extrahiert (#52). Ermöglicht die Wiederverwendung
 * außerhalb der Admin-Match-Seite selbst, z. B. für den E-Mail-Digest
 * (App\Service\DigestService), der nur die Anzahl offener Vorschläge
 * braucht, aber dieselbe Vorauswahl verwenden muss, um nicht grundlos von
 * der tatsächlichen Anzeige unter /admin/matches abzuweichen.
 *
 * Seit #215 werden die Kandidatenpaare SQL-seitig vorgefiltert, statt in PHP
 * das Kreuzprodukt "jeder Platzhalter × alle Pferde" zu bilden: Bei 2.000
 * Pferden mit je 1.000 unaufgelösten Vater-/Mutter-Angaben liefen vorher
 * ~4.000.000 Scoring-Durchläufe (inkl. similar_text()) pro Aufruf - real
 * 15-25 s CPU-Zeit, nur um am Ende je Platzhalter maximal 5 Vorschläge zu
 * behalten. Die eigentliche Bewertung (calculateSuggestions()) ist fachlich
 * unverändert - sie läuft nur noch auf der typischerweise um Größenordnungen
 * kleineren Paarmenge des Vorfilters.
 *
 * Seit #509 greift der Vorfilter über Indizes (ARME). In der Fassung aus
 * #215 stand er als EIN Ausdruck mit `SOUNDEX(p.name)` und `LEFT(p.name, 3)`
 * in einem ODER: Funktionen auf der Kandidatenspalte, für die kein Index
 * greift - je Platzhalter las die Datenbank alle Pferde, und zwar dreimal je
 * Seitenaufruf (zweimal countOpen(), einmal findAll()). Bei 10.000 Pferden
 * mit 6.000 Platzhaltern waren das zweistellige Sekunden.
 */
final class MatchSuggestionFinder {

    /**
     * SQL-Vorfilter für Kandidatenpaare (#215), als korrelierte Bedingung
     * zwischen dem Kind-Alias `c` und dem Kandidaten-Alias `p`. Der Filter
     * ist bewusst WEITER gefasst als die eigentliche Bewertung - er muss nur
     * alle Paare enthalten, die calculateSuggestions() aufnehmen würde:
     *
     * - UELN-Gleichheit deckt den 45-Punkte-UELN-Zweig ab (hasUelnMatch;
     *   Haupt- wie ausländische UELN, Groß-/Kleinschreibung übernimmt die
     *   CI-Collation der Spalten).
     * - Ohne UELN-Treffer erreicht ein Paar die 45-%-Schwelle nur über die
     *   Namens-Ähnlichkeit: Alter/Deckstation/Farbe liefern zusammen maximal
     *   12+4+4 = 20 Punkte, die fehlenden >= 25 Punkte müssen aus den 35
     *   Namens-Punkten kommen (similar_text >= ~71 %) - bzw. >= 90 % für den
     *   hasStrongNameMatch-Bypass. So ähnliche Namen teilen praktisch immer
     *   den SOUNDEX-Code oder die ersten drei Buchstaben; das Präfix wird in
     *   beide Richtungen geprüft, damit auch ein kurzer Name gegen einen
     *   längeren gefunden wird.
     *
     * Theoretisch konstruierbare Ausnahmen (ähnliche Namen mit komplett
     * anderem Anfang UND anderem Klangbild, z. B. "Quantum"/"Kwantum")
     * fallen durch den Vorfilter - dieser Kompromiss ist die Kernidee des
     * Lösungsvorschlags in #215 und dort so dokumentiert.
     *
     * sprintf-Platzhalter: %1$s = Eltern-Rolle ('sire'/'dam'), %2$s = das
     * einzige zur Rolle passende Geschlecht ('stallion'/'mare'; NULL =
     * unbekannt bleibt wie bisher zugelassen, #167). Beides sind
     * ausschließlich interne Literale aus ROLES, keine Benutzereingaben.
     *
     * NUR NOCH RÜCKFALL (#509): Diese Fassung läuft, wenn die generierten
     * Suchspalten fehlen (siehe mitSuchspalten()). Sie beschreibt weiterhin,
     * WAS der Vorfilter findet; ARME findet dasselbe über Indizes.
     *
     * Die Präfixprüfung stand hier bis #509 als `p.name LIKE
     * CONCAT(LEFT(c.…_name, 3), '%')` und umgekehrt. Damit wirkten `%` und
     * `_` in einem Namen als Jokerzeichen - ein Pferd namens "A_" passte auf
     * jeden Platzhalter, der mit A beginnt. Jetzt zählt der Namensanfang als
     * das, was dasteht; beide Wege sind darin gleich.
     */
    private const PAIR_PREFILTER_SQL = <<<'SQL'
        p.deleted_at IS NULL
        AND p.id <> c.id
        AND (p.sex IS NULL OR p.sex = '%2$s')
        AND (
            (c.%1$s_ueln IS NOT NULL AND c.%1$s_ueln <> ''
                AND (p.ueln = c.%1$s_ueln OR p.foreign_ueln = c.%1$s_ueln))
            OR (c.%1$s_name IS NOT NULL AND c.%1$s_name <> ''
                AND (SOUNDEX(p.name) = SOUNDEX(c.%1$s_name)
                    OR LEFT(p.name, CHAR_LENGTH(LEFT(c.%1$s_name, 3))) = LEFT(c.%1$s_name, 3)
                    OR LEFT(c.%1$s_name, CHAR_LENGTH(LEFT(p.name, 3))) = LEFT(p.name, 3)))
        )
        SQL;

    /**
     * Was für jeden Kandidaten `p` gilt, gleich über welchen Arm er gefunden
     * wurde. %2$s = das zur Rolle passende Geschlecht, siehe
     * PAIR_PREFILTER_SQL.
     */
    private const KANDIDAT_SQL = "p.deleted_at IS NULL AND p.id <> c.id AND (p.sex IS NULL OR p.sex = '%2\$s')";

    private const KIND_HAT_UELN = "c.%1\$s_ueln IS NOT NULL AND c.%1\$s_ueln <> ''";
    private const KIND_HAT_NAME = "c.%1\$s_name IS NOT NULL AND c.%1\$s_name <> ''";

    /**
     * Der Vorfilter aus PAIR_PREFILTER_SQL, zerlegt in Arme, die je über
     * EINEN Index laufen (#509). Je Arm: [Bedingung an das Kind `c`,
     * Verknüpfung Kind–Kandidat]. Ein Paar gehört zur Vorauswahl, wenn
     * mindestens ein Arm es findet.
     *
     * Die beiden UELN-Arme und der Klangbild-Arm sind die bisherigen
     * Bedingungen, nur einzeln gestellt: `ueln` ist UNIQUE, `foreign_ueln`
     * indiziert, und `name_soundex` ist die generierte Spalte zu
     * `SOUNDEX(name)` (SCHEMA_VERSION 34).
     *
     * Die Präfixprüfung "in beide Richtungen" stand bisher als zwei
     * LIKE-Ausdrücke da. Sie besagt: Der kürzere der beiden Namensanfänge
     * (höchstens drei Zeichen) ist der Anfang des anderen Namens. Das sind
     * vier Fälle:
     *
     * - beide Namen haben mindestens drei Zeichen: die ersten drei stimmen
     *   überein - `name_prefix3`, die generierte Spalte zu `LEFT(name, 3)`;
     * - der KANDIDAT ist kürzer (0, 1 oder 2 Zeichen) und sein ganzer Name
     *   ist der Anfang des Platzhalters - drei Gleichheiten auf `name`;
     * - der PLATZHALTER ist kürzer als drei Zeichen und der Anfang des
     *   Kandidaten - dafür gibt es keinen Index, der letzte Arm liest für
     *   genau diese Platzhalter alle Pferde. Ein- und zweibuchstabige
     *   Elternangaben sind die Ausnahme;
     * - beide sind kürzer und gleich: deckt `name_prefix3` mit ab.
     *
     * Jokerzeichen in Namen zählen dabei nicht als Muster - siehe
     * PAIR_PREFILTER_SQL.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const ARME = [
        [self::KIND_HAT_UELN, "p.ueln = c.%1\$s_ueln"],
        [self::KIND_HAT_UELN, "p.foreign_ueln = c.%1\$s_ueln"],
        [self::KIND_HAT_NAME, "p.name_soundex = SOUNDEX(c.%1\$s_name)"],
        [self::KIND_HAT_NAME, "p.name_prefix3 = LEFT(c.%1\$s_name, 3)"],
        [self::KIND_HAT_NAME, "p.name = ''"],
        [self::KIND_HAT_NAME, "p.name = LEFT(c.%1\$s_name, 1)"],
        [self::KIND_HAT_NAME, "p.name = LEFT(c.%1\$s_name, 2)"],
        [
            self::KIND_HAT_NAME . " AND CHAR_LENGTH(c.%1\$s_name) < 3",
            "LEFT(p.name, CHAR_LENGTH(c.%1\$s_name)) = c.%1\$s_name",
        ],
    ];

    /**
     * true, sobald feststeht, dass die generierten Suchspalten fehlen - dann
     * spart sich jede weitere Abfrage dieses Prozesses den Fehlversuch.
     */
    private static bool $suchspaltenFehlen = false;

    /**
     * Eltern-Rollen mit dem jeweils einzig zulässigen Kandidaten-Geschlecht
     * (#167) und einem Sortierschlüssel, der die bisherige Blockreihenfolge
     * der Ausgabe (erst alle Vater-, dann alle Mutter-Platzhalter) erhält.
     */
    private const ROLES = [
        ['role' => 'sire', 'sex' => 'stallion', 'order' => 0, 'label' => 'Vater'],
        ['role' => 'dam', 'sex' => 'mare', 'order' => 1, 'label' => 'Mutter'],
    ];

    /**
     * Ermittelt offene Match-/Merge-Vorschläge für unaufgelöste Vater-/
     * Mutter-Angaben (nur `sire_name`/`sire_ueln` bzw. `dam_name`/`dam_ueln`
     * gesetzt, keine FK-Verknüpfung).
     *
     * Optional paginiert (#215, /admin/matches): $limit/$offset zählen
     * PLATZHALTER (Kind/Rolle-Kombinationen mit mindestens einem
     * Vorfilter-Kandidaten), deterministisch sortiert nach Rolle (erst
     * Väter, dann Mütter), Kind-Name, Kind-ID. Eine Seite kann WENIGER
     * Einträge liefern als Platzhalter angefragt wurden: Platzhalter, deren
     * sämtliche Vorfilter-Kandidaten unter der Anzeigeschwelle bleiben,
     * werden - wie schon immer - nicht ausgegeben.
     *
     * @return array<int, array{
     *     child_id: int, child_name: string, parent_type: 'sire'|'dam',
     *     parent_type_label: string, placeholder_name: ?string,
     *     placeholder_ueln: ?string, suggestions: array
     * }>
     */
    public static function findAll(?int $limit = null, int $offset = 0): array {
        $db = Database::getInstance();

        // Schritt 1: Platzhalter-Menge (ggf. die angefragte Seite) bestimmen.
        // Nur Kind/Rolle-Kombinationen, für die der Vorfilter mindestens
        // einen Kandidaten kennt - alle anderen könnten ohnehin keinen
        // Vorschlag ergeben und würden nur leere Bewertungsläufe erzeugen.
        $placeholders = self::mitSuchspalten(static function (bool $indiziert) use ($db, $limit, $offset): array {
            $parts = [];
            foreach (self::ROLES as $r) {
                $parts[] = "SELECT {$r['order']} AS role_order, '{$r['role']}' AS parent_type,
                                   c.id, c.name, c.birth_year, c.color,
                                   c.breeding_station_id, c.breeding_station,
                                   c.{$r['role']}_name AS placeholder_name,
                                   c.{$r['role']}_ueln AS placeholder_ueln
                            FROM horses c
                            WHERE " . self::placeholderCondition($r['role']) . "
                              AND " . self::hatKandidaten($r, $indiziert);
            }

            $sql = "SELECT * FROM (" . implode(" UNION ALL ", $parts) . ") plat
                    ORDER BY plat.role_order, plat.name, plat.id";
            $params = [];
            if ($limit !== null) {
                $sql .= " LIMIT ? OFFSET ?";
                $params = [max(0, $limit), max(0, $offset)];
            }
            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll();
        });

        if (empty($placeholders)) {
            return [];
        }

        // Schritt 2: Kandidatenpaare NUR für diese Platzhalter laden - eine
        // Abfrage je Rolle, IN-Liste als generierte ?-Platzhalter (gleiches
        // Muster wie DigestService::loadRecipients()). Die Kandidaten-Spalten
        // entsprechen exakt der früheren Vollmengen-Abfrage (inkl.
        // sire_id/dam_id für den Nachkommen-Ausschluss, #131), die Sortierung
        // nach Kandidaten-Name erhält die bisherige Reihenfolge bei
        // Punktgleichheit (stabile usort in calculateSuggestions()).
        $childIdsByRole = ['sire' => [], 'dam' => []];
        foreach ($placeholders as $row) {
            $childIdsByRole[$row['parent_type']][] = (int)$row['id'];
        }

        $candidatesByChild = ['sire' => [], 'dam' => []];
        foreach (self::ROLES as $r) {
            $childIds = $childIdsByRole[$r['role']];
            if (empty($childIds)) {
                continue;
            }
            $paare = self::mitSuchspalten(static function (bool $indiziert) use ($db, $r, $childIds): array {
                [$sql, $params] = self::kandidatenAbfrage($r, $childIds, $indiziert);
                $stmt = $db->prepare($sql);
                $stmt->execute($params);

                return $stmt->fetchAll();
            });
            foreach ($paare as $pair) {
                $childId = (int)$pair['child_id'];
                // child_id ist nur der Gruppierschlüssel - entfernen, damit
                // die Kandidaten-Arrays feldgleich zum bisherigen Verhalten
                // (und damit zur View/API) bleiben.
                unset($pair['child_id']);
                $candidatesByChild[$r['role']][$childId][] = $pair;
            }
        }

        // Schritt 3: unveränderte Bewertung, jetzt je Platzhalter nur noch
        // auf dessen vorgefilterten Kandidaten - und ohne die Paare, über die
        // längst entschieden wurde (#355).
        //
        // Der Filter greift NACH der Bewertung, nicht davor: Ein als
        // "verschieden" abgelegtes Paar soll aus der Liste verschwinden, aber
        // die Bewertung der übrigen Kandidaten desselben Platzhalters darf
        // sich dadurch nicht verschieben. Wäre der Kandidat schon vorher
        // heraus, änderte sich die Rangfolge der anderen mit.
        $entschieden = MatchLabel::ausgeblendete('horse');

        $unlinkedMatches = [];
        foreach ($placeholders as $row) {
            $role = $row['parent_type'];
            $suggestions = self::calculateSuggestions(
                $row['placeholder_name'],
                $row['placeholder_ueln'],
                $row,
                $candidatesByChild[$role][(int)$row['id']] ?? []
            );
            if ($entschieden !== []) {
                $kindId = (int)$row['id'];
                $suggestions = array_values(array_filter(
                    $suggestions,
                    static fn(array $v) => !MatchLabel::istAusgeblendet($entschieden, $kindId, (int)$v['id'])
                ));
            }
            if (!empty($suggestions)) {
                $unlinkedMatches[] = [
                    'child_id' => $row['id'],
                    'child_name' => $row['name'],
                    'parent_type' => $role,
                    'parent_type_label' => $role === 'sire' ? 'Vater' : 'Mutter',
                    'placeholder_name' => $row['placeholder_name'],
                    'placeholder_ueln' => $row['placeholder_ueln'],
                    'suggestions' => $suggestions
                ];
            }
        }

        return $unlinkedMatches;
    }

    /**
     * Anzahl offener Platzhalter mit mindestens einem Vorfilter-Kandidaten -
     * als reiner SQL-COUNT ohne jede Bewertung (#215). Gedacht für Aufrufer,
     * die nur die Größenordnung brauchen (E-Mail-Digest, Pagination von
     * /admin/matches): vorher lief für die eine Zahl im Digest das komplette
     * similar_text()-Scoring über das Kreuzprodukt.
     *
     * Bewusst eine OBERMENGE von count(findAll()): einzelne gezählte
     * Platzhalter können nach der Bewertung unter der Anzeigeschwelle
     * bleiben und tauchen dann in der Liste nicht auf.
     */
    public static function countOpen(): int {
        $db = Database::getInstance();

        return self::mitSuchspalten(static function (bool $indiziert) use ($db): int {
            $total = 0;
            foreach (self::ROLES as $r) {
                $total += (int)$db->query(
                    "SELECT COUNT(*) FROM horses c
                     WHERE " . self::placeholderCondition($r['role']) . "
                       AND " . self::hatKandidaten($r, $indiziert)
                )->fetchColumn();
            }
            return $total;
        });
    }

    /**
     * Führt eine Vorfilter-Abfrage über die generierten Suchspalten aus und
     * fällt auf die Fassung ohne sie zurück, wenn es die Spalten nicht gibt
     * (SQLSTATE 42S22): Migration noch nicht gelaufen, `horses` aus einem
     * älteren Teilarchiv zurückgespielt, oder ein Datenbankserver ohne
     * unsichtbare Spalten (siehe SchemaMigrator, Schritt 26c). Der Rückfall
     * liefert dieselben Paare, nur ohne Index.
     *
     * @template T
     * @param callable(bool): T $abfrage true = über die Suchspalten
     * @return T
     */
    private static function mitSuchspalten(callable $abfrage): mixed {
        if (!self::$suchspaltenFehlen) {
            try {
                return $abfrage(true);
            } catch (\PDOException $e) {
                if ($e->getCode() !== '42S22') {
                    throw $e;
                }
                self::$suchspaltenFehlen = true;
            }
        }

        return $abfrage(false);
    }

    /** Nur für Tests: erzwingt den Rückfall bzw. hebt ihn wieder auf. */
    public static function ohneSuchspaltenFuerTests(bool $ohne): void {
        self::$suchspaltenFehlen = $ohne;
    }

    /**
     * Bedingung an das Kind `c`: Der Vorfilter kennt mindestens einen
     * Kandidaten.
     *
     * Über die Suchspalten als ODER einzelner EXISTS - jedes davon ist ein
     * Indexzugriff und endet beim ersten Treffer.
     *
     * @param array{role: string, sex: string} $r Eintrag aus ROLES.
     */
    private static function hatKandidaten(array $r, bool $indiziert): string {
        if (!$indiziert) {
            return 'EXISTS (SELECT 1 FROM horses p WHERE ' . self::prefilterCondition($r) . ')';
        }

        $teile = [];
        foreach (self::ARME as [$kind, $verknuepfung]) {
            $teile[] = sprintf(
                "({$kind} AND EXISTS (SELECT 1 FROM horses p WHERE {$verknuepfung} AND " . self::KANDIDAT_SQL . '))',
                $r['role'],
                $r['sex']
            );
        }

        return '(' . implode(' OR ', $teile) . ')';
    }

    /**
     * Die Kandidatenpaare der genannten Kinder, sortiert nach Kandidaten-Name.
     *
     * Über die Suchspalten als UNION der Arme: Ein Paar, das mehrere Arme
     * finden (gleiche UELN und gleicher Name), steht durch das UNION nur
     * einmal da. Die ID als zweites Sortiermerkmal macht die Reihenfolge
     * gleichnamiger Kandidaten fest - bisher ergab sie sich aus der
     * Lesereihenfolge.
     *
     * @param array{role: string, sex: string} $r Eintrag aus ROLES.
     * @param list<int> $childIds
     * @return array{0: string, 1: list<int>} SQL und Parameter
     */
    private static function kandidatenAbfrage(array $r, array $childIds, bool $indiziert): array {
        $inList = implode(',', array_fill(0, count($childIds), '?'));
        $spalten = 'c.id AS child_id,
                    p.id, p.name, p.ueln, p.foreign_ueln, p.birth_year, p.color, p.sex,
                    p.breeding_station_id, p.breeding_station, p.sire_id, p.dam_id';

        if (!$indiziert) {
            return [
                "SELECT {$spalten}
                 FROM horses c
                 JOIN horses p ON " . self::prefilterCondition($r) . "
                 WHERE c.id IN ({$inList})
                 ORDER BY p.name ASC",
                $childIds,
            ];
        }

        $teile = [];
        $params = [];
        foreach (self::ARME as [$kind, $verknuepfung]) {
            $bedingung = sprintf("{$verknuepfung} AND " . self::KANDIDAT_SQL, $r['role'], $r['sex']);
            $kindBedingung = sprintf($kind, $r['role']);
            $teile[] = "SELECT {$spalten}
                        FROM horses c
                        JOIN horses p ON {$bedingung}
                        WHERE c.id IN ({$inList}) AND {$kindBedingung}";
            array_push($params, ...$childIds);
        }

        return [implode(' UNION ', $teile) . ' ORDER BY name ASC, id ASC', $params];
    }

    /**
     * Platzhalter-Bedingung einer Eltern-Rolle - identisch zu den früheren
     * Vollmengen-Abfragen: aktives Pferd, keine FK-Verknüpfung, aber
     * Name und/oder UELN des Elternteils als Freitext vorhanden.
     *
     * @param 'sire'|'dam' $role Internes Literal aus ROLES, nie Benutzereingabe.
     */
    private static function placeholderCondition(string $role): string {
        return "c.deleted_at IS NULL AND c.{$role}_id IS NULL
                AND (c.{$role}_name IS NOT NULL OR c.{$role}_ueln IS NOT NULL)";
    }

    /**
     * @param array{role: string, sex: string} $r Eintrag aus ROLES.
     */
    private static function prefilterCondition(array $r): string {
        return sprintf(self::PAIR_PREFILTER_SQL, $r['role'], $r['sex']);
    }

    /**
     * Calculates multi-field probability score (%) for matching placeholder against candidate horses
     */
    private static function calculateSuggestions(?string $pName, ?string $pUeln, array $childHorse, array $allHorses): array {
        $suggestions = [];
        $pNameClean = strtolower(trim($pName ?? ''));
        $pUelnClean = strtolower(trim($pUeln ?? ''));

        foreach ($allHorses as $candidate) {
            if ($candidate['id'] == $childHorse['id']) continue;

            // Direkte Nachkommen des Kindes nicht als dessen Eltern vorschlagen -
            // das wäre ein garantierter 2er-Stammbaum-Zyklus (#131).
            if ((int)($candidate['sire_id'] ?? 0) === (int)$childHorse['id']
                || (int)($candidate['dam_id'] ?? 0) === (int)$childHorse['id']) {
                continue;
            }

            $candNameClean = strtolower(trim($candidate['name'] ?? ''));
            $candUelnClean = strtolower(trim($candidate['ueln'] ?? ''));
            $candForeignUelnClean = strtolower(trim($candidate['foreign_ueln'] ?? ''));

            $points = 0;
            $reasons = [];

            // 1. UELN Match (Max 45 Points)
            $hasUelnMatch = false;
            if (!empty($pUelnClean)) {
                if ($pUelnClean === $candUelnClean) {
                    $points += 45;
                    $reasons[] = "✓ Haupt-UELN übereinstimmend";
                    $hasUelnMatch = true;
                } else if (!empty($candForeignUelnClean) && $pUelnClean === $candForeignUelnClean) {
                    $points += 45;
                    $reasons[] = "✓ Ausländische UELN übereinstimmend";
                    $hasUelnMatch = true;
                }
            }

            // 2. Name Similarity (Max 35 Points)
            $hasStrongNameMatch = false;
            if (!empty($pNameClean) && !empty($candNameClean)) {
                similar_text($pNameClean, $candNameClean, $percent);
                $namePoints = round(($percent / 100) * 35);
                $points += $namePoints;

                if ($percent >= 90) {
                    $reasons[] = "✓ Name nahezu identisch (" . round($percent) . "%)";
                    $hasStrongNameMatch = true;
                } else if ($percent >= 70) {
                    $reasons[] = "✓ Name hohe Ähnlichkeit (" . round($percent) . "%)";
                } else if ($percent >= 50) {
                    $reasons[] = "Name ähnliche Schreibweise (" . round($percent) . "%)";
                }
            }

            // 3. Birth Year Plausibility (Max 12 Points or Penalty)
            $childYear = (int)($childHorse['birth_year'] ?? 0);
            $candYear = (int)($candidate['birth_year'] ?? 0);
            // Unmögliches Alter (Audit N47): Der Vorschlag bleibt sichtbar
            // (die Geburtsjahre können falsch sein), aber "Jetzt verknüpfen"
            // wird gesperrt. Die eigentliche Sperre ist der Server-Guard in
            // HorseController::linkMatch().
            $blockiert = false;

            if ($childYear > 0 && $candYear > 0) {
                $ageDiff = $childYear - $candYear;
                if ($ageDiff >= 3 && $ageDiff <= 30) {
                    $points += 12;
                    $reasons[] = "✓ Plausibles Elternalter (" . $ageDiff . " Jahre älter)";
                } else if ($ageDiff >= 1 && $ageDiff < 3) {
                    $points += 5;
                    $reasons[] = "Grenzwertiges Alter (" . $ageDiff . " Jahre älter)";
                } else if ($ageDiff <= 0) {
                    $points -= 35; // Severe penalty: parent born after or same year as child
                    $reasons[] = "⚠️ Unmögliches Alter (Kandidat jünger/gleich alt)";
                    $blockiert = true;
                } else if ($ageDiff > 35) {
                    $points -= 15;
                    $reasons[] = "⚠️ Unwahrscheinlicher Altersabstand (" . $ageDiff . " Jahre)";
                }
            }

            // 4. Breeding Station Match (Max 4 Points)
            if (!empty($childHorse['breeding_station_id']) && !empty($candidate['breeding_station_id']) && $childHorse['breeding_station_id'] == $candidate['breeding_station_id']) {
                $points += 4;
                $reasons[] = "✓ Identische Deckstation";
            } else if (!empty($childHorse['breeding_station']) && !empty($candidate['breeding_station']) && strtolower(trim($childHorse['breeding_station'])) === strtolower(trim($candidate['breeding_station']))) {
                $points += 4;
                $reasons[] = "✓ Identische Deckstation (Freitext)";
            }

            // 5. Color Match (Max 4 Points)
            if (!empty($childHorse['color']) && !empty($candidate['color']) && strtolower(trim($childHorse['color'])) === strtolower(trim($candidate['color']))) {
                $points += 4;
                $reasons[] = "✓ Gleiche Fellfarbe";
            }

            // Calculate final percentage score (0% - 100%)
            $score = min(100, max(0, $points));

            if ($score >= 45 || $hasUelnMatch || $hasStrongNameMatch) {
                $vorschlag = [
                    'horse' => $candidate,
                    'score' => $score,
                    'reasons' => $reasons
                ];
                if ($blockiert) {
                    $vorschlag['blockiert'] = true;
                }
                $suggestions[] = $vorschlag;
            }
        }

        // Sort suggestions by highest score descending
        usort($suggestions, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return array_slice($suggestions, 0, 5); // Return top 5
    }
}
