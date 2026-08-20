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
                    OR p.name LIKE CONCAT(LEFT(c.%1$s_name, 3), '%%')
                    OR c.%1$s_name LIKE CONCAT(LEFT(p.name, 3), '%%')))
        )
        SQL;

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
        $parts = [];
        foreach (self::ROLES as $r) {
            $parts[] = "SELECT {$r['order']} AS role_order, '{$r['role']}' AS parent_type,
                               c.id, c.name, c.birth_year, c.color,
                               c.breeding_station_id, c.breeding_station,
                               c.{$r['role']}_name AS placeholder_name,
                               c.{$r['role']}_ueln AS placeholder_ueln
                        FROM horses c
                        WHERE " . self::placeholderCondition($r['role']) . "
                          AND EXISTS (SELECT 1 FROM horses p WHERE " . self::prefilterCondition($r) . ")";
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
        $placeholders = $stmt->fetchAll();

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
            $inList = implode(',', array_fill(0, count($childIds), '?'));
            $stmt = $db->prepare(
                "SELECT c.id AS child_id,
                        p.id, p.name, p.ueln, p.foreign_ueln, p.birth_year, p.color, p.sex,
                        p.breeding_station_id, p.breeding_station, p.sire_id, p.dam_id
                 FROM horses c
                 JOIN horses p ON " . self::prefilterCondition($r) . "
                 WHERE c.id IN ({$inList})
                 ORDER BY p.name ASC"
            );
            $stmt->execute($childIds);
            foreach ($stmt->fetchAll() as $pair) {
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

        $total = 0;
        foreach (self::ROLES as $r) {
            $total += (int)$db->query(
                "SELECT COUNT(*) FROM horses c
                 WHERE " . self::placeholderCondition($r['role']) . "
                   AND EXISTS (SELECT 1 FROM horses p WHERE " . self::prefilterCondition($r) . ")"
            )->fetchColumn();
        }
        return $total;
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
                $suggestions[] = [
                    'horse' => $candidate,
                    'score' => $score,
                    'reasons' => $reasons
                ];
            }
        }

        // Sort suggestions by highest score descending
        usort($suggestions, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return array_slice($suggestions, 0, 5); // Return top 5
    }
}
