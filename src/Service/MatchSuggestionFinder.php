<?php
// src/Service/MatchSuggestionFinder.php

namespace App\Service;

use App\Database;

/**
 * Class MatchSuggestionFinder
 *
 * Blutlinien-Match-/Merge-Vorschlagslogik, aus `HorseController::matches()`/
 * `calculateSuggestions()` extrahiert (#52) - unverändertes Verhalten,
 * reine Verschiebung. Ermöglicht der Wiederverwendung außerhalb der
 * Admin-Match-Seite selbst, z. B. für den E-Mail-Digest
 * (App\Service\DigestService), der nur die Anzahl offener Vorschläge
 * braucht, aber exakt dieselbe Bewertungslogik/Schwellenwerte verwenden
 * muss, um nicht von der tatsächlichen Anzeige unter /admin/matches
 * abzuweichen.
 */
final class MatchSuggestionFinder {

    /**
     * Ermittelt alle offenen Match-/Merge-Vorschläge für unaufgelöste
     * Vater-/Mutter-Angaben (nur `sire_name`/`sire_ueln` bzw.
     * `dam_name`/`dam_ueln` gesetzt, keine FK-Verknüpfung).
     *
     * @return array<int, array{
     *     child_id: int, child_name: string, parent_type: 'sire'|'dam',
     *     parent_type_label: string, placeholder_name: ?string,
     *     placeholder_ueln: ?string, suggestions: array
     * }>
     */
    public static function findAll(): array {
        $db = Database::getInstance();

        // Get all unlinked sire placeholders
        $stmt = $db->query("SELECT id, name, ueln, foreign_ueln, birth_year, color, breeding_station_id, breeding_station, sire_name, sire_ueln FROM horses WHERE deleted_at IS NULL AND sire_id IS NULL AND (sire_name IS NOT NULL OR sire_ueln IS NOT NULL)");
        $sirePlaceholders = $stmt->fetchAll();

        // Get all unlinked dam placeholders
        $stmt = $db->query("SELECT id, name, ueln, foreign_ueln, birth_year, color, breeding_station_id, breeding_station, dam_name, dam_ueln FROM horses WHERE deleted_at IS NULL AND dam_id IS NULL AND (dam_name IS NOT NULL OR dam_ueln IS NOT NULL)");
        $damPlaceholders = $stmt->fetchAll();

        // Fetch all existing active horses for matching (inkl. sire_id/dam_id,
        // um direkte Nachkommen des Kindes als Eltern-Kandidaten auszuschließen, #131)
        $stmt = $db->query("SELECT id, name, ueln, foreign_ueln, birth_year, color, breeding_station_id, breeding_station, sire_id, dam_id FROM horses WHERE deleted_at IS NULL ORDER BY name ASC");
        $allHorses = $stmt->fetchAll();

        $unlinkedMatches = [];

        // Calculate matches for Sires
        foreach ($sirePlaceholders as $sp) {
            $suggestions = self::calculateSuggestions($sp['sire_name'], $sp['sire_ueln'], $sp, $allHorses);
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

        // Calculate matches for Dams
        foreach ($damPlaceholders as $dp) {
            $suggestions = self::calculateSuggestions($dp['dam_name'], $dp['dam_ueln'], $dp, $allHorses);
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
