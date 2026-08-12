<?php
// src/Service/PedigreeBuilder.php

namespace App\Service;

use App\Database;

/**
 * Class PedigreeBuilder
 *
 * Baut den rekursiven Abstammungsbaum (Pedigree) eines Pferdes anhand von
 * `sire_id`/`dam_id` (Fremdschlüssel) mit Fallback auf eine UELN-/Namens-Suche
 * auf, falls kein FK gesetzt ist. Ursprünglich private Logik in
 * PublicController::horseDetail() (#56-Vorarbeit) - hierher extrahiert, damit
 * Plugins (z. B. ein Inzuchtkoeffizient-Rechner oder ein Pedigree-Export) den
 * Baum direkt mit eigener Tiefe abrufen können, ohne die öffentliche
 * Detailseite erneut zu rendern oder die Logik zu duplizieren.
 *
 * Performance (#119): Zeilen- und Eltern-Lookup-Ergebnisse werden je
 * build()-Aufruf memoisiert - bei Inzucht/Linienzucht (mehrfach auftretende
 * gemeinsame Ahnen, in dieser Domäne der Normalfall) wird jeder Ahne nur noch
 * einmal abgefragt. Auf der letzten Generation entfällt der frühere, immer
 * verworfene Freitext-Eltern-Lookup komplett (Platzhalter-Knoten jenseits von
 * $maxDepth werden seither nicht mehr erzeugt - die frühere
 * `depth = $maxDepth + 1`-Besonderheit ist damit bewusst entfallen).
 *
 * Zyklen-Schutz (#131): Bereits besuchte Pferde-IDs des aktuellen Pfades
 * werden mitgeführt - ein Zyklus in Altdaten (Pferd ist sein eigener
 * Vorfahre) bricht den betroffenen Ast sauber ab, statt das Pferd erneut im
 * eigenen Stammbaum zu zeigen.
 */
class PedigreeBuilder {

    /** @var array<string, array|false> Request-lokaler Cache der Pferde-Zeilen (false = nicht gefunden). */
    private static array $rowCache = [];

    /** @var array<string, int|null> Request-lokaler Cache der Freitext-Eltern-Lookups. */
    private static array $parentLookupCache = [];

    /**
     * Baut den Abstammungsbaum für ein Pferd bis zur angegebenen Tiefe.
     *
     * @param int|null $horseId ID des Wurzel-Pferdes (Proband)
     * @param int $maxDepth Maximale Generationstiefe (1 = nur das Pferd selbst)
     * @param bool $publishedOnly Wenn true, werden ausschließlich veröffentlichte
     *   Pferde (is_published = 1) als Ahnen aufgelöst. Ein unveröffentlichter,
     *   verknüpfter Vorfahre erscheint dann gar nicht (leerer Ast); ein nur per
     *   Freitext (sire_name/dam_name) hinterlegter Vorfahre bleibt als
     *   Platzhalter aus genau diesem Freitext sichtbar - ohne Profil-Link,
     *   ohne DB-Felder des unveröffentlichten Pferdes und ohne weitere Rekursion.
     *   ZWINGEND für jede öffentliche Ausgabe/Berechnung (Katalog, Detailseite,
     *   API, Inzuchtkoeffizient etc.), damit aus unveröffentlichten Daten nichts
     *   hergeleitet werden kann. Der Backend-Standard (false) liefert den vollen Baum.
     * @return array|null Verschachtelte Knotenstruktur mit `id`, `name`, `ueln`,
     *   `birth_year`, `color`, `depth`, `is_placeholder`, `sire`, `dam` - oder
     *   `null`, wenn `$horseId` leer ist oder kein passendes Pferd existiert.
     */
    public static function build(?int $horseId, int $maxDepth = 4, bool $publishedOnly = false): ?array {
        // Caches je Top-Level-Aufruf zurücksetzen: Memoisierung wirkt so nur
        // innerhalb EINES Baums (deckt den Inzucht-Fall ab), ohne dass zwischen
        // Requests/Tests veraltete Daten hängen bleiben.
        self::$rowCache = [];
        self::$parentLookupCache = [];
        return self::buildRecursive($horseId, 1, $maxDepth, $publishedOnly, []);
    }

    /**
     * @param array<int, true> $visited Pferde-IDs des aktuellen Rekursionspfades (Zyklen-Schutz, #131)
     */
    private static function buildRecursive(?int $horseId, int $currentDepth, int $maxDepth, bool $publishedOnly, array $visited): ?array {
        if (!$horseId || $currentDepth > $maxDepth) {
            return null;
        }

        // Zyklus in Altdaten (z. B. Pferd als eigener Vater): Ast sauber abbrechen,
        // statt das Pferd als seinen eigenen Vorfahren anzuzeigen.
        if (isset($visited[$horseId])) {
            return null;
        }
        $visited[$horseId] = true;

        $db = Database::getInstance();
        $horse = self::fetchHorseRow($db, (int)$horseId, $publishedOnly);

        if (!$horse) {
            return null;
        }

        $horse['depth'] = $currentDepth;

        // Sire resolution (FK or UELN/Foreign UELN/Name lookup fallback)
        if ($horse['sire_id']) {
            $horse['sire'] = self::buildRecursive((int)$horse['sire_id'], $currentDepth + 1, $maxDepth, $publishedOnly, $visited);
        } else if ((!empty($horse['sire_name']) || !empty($horse['sire_ueln'])) && $currentDepth < $maxDepth) {
            // $currentDepth < $maxDepth: Auf der letzten Generation wäre jeder
            // Lookup verschwendet, weil das Ergebnis ohnehin verworfen würde (#119).
            $parentSireId = self::findParentByUelnOrName($db, $horse['sire_ueln'], $horse['sire_name'], $publishedOnly);
            if ($parentSireId && !isset($visited[$parentSireId])) {
                $horse['sire'] = self::buildRecursive($parentSireId, $currentDepth + 1, $maxDepth, $publishedOnly, $visited);
            } else {
                $horse['sire'] = [
                    'id' => null,
                    'name' => $horse['sire_name'] ?: \App\I18n\Translator::t('horse.unknown_sire'),
                    'ueln' => $horse['sire_ueln'],
                    'depth' => $currentDepth + 1,
                    'is_placeholder' => true,
                    'sire' => null,
                    'dam' => null
                ];
            }
        } else {
            $horse['sire'] = null;
        }

        // Dam resolution (FK or UELN/Foreign UELN/Name lookup fallback)
        if ($horse['dam_id']) {
            $horse['dam'] = self::buildRecursive((int)$horse['dam_id'], $currentDepth + 1, $maxDepth, $publishedOnly, $visited);
        } else if ((!empty($horse['dam_name']) || !empty($horse['dam_ueln'])) && $currentDepth < $maxDepth) {
            $parentDamId = self::findParentByUelnOrName($db, $horse['dam_ueln'], $horse['dam_name'], $publishedOnly);
            if ($parentDamId && !isset($visited[$parentDamId])) {
                $horse['dam'] = self::buildRecursive($parentDamId, $currentDepth + 1, $maxDepth, $publishedOnly, $visited);
            } else {
                $horse['dam'] = [
                    'id' => null,
                    'name' => $horse['dam_name'] ?: \App\I18n\Translator::t('horse.unknown_dam'),
                    'ueln' => $horse['dam_ueln'],
                    'depth' => $currentDepth + 1,
                    'is_placeholder' => true,
                    'sire' => null,
                    'dam' => null
                ];
            }
        } else {
            $horse['dam'] = null;
        }

        return $horse;
    }

    /**
     * Lädt die Basiszeile eines Pferdes, memoisiert je build()-Aufruf (#119) -
     * bei Linienzucht wird derselbe gemeinsame Ahne so nur einmal abgefragt.
     *
     * @return array|null
     */
    private static function fetchHorseRow(\PDO $db, int $horseId, bool $publishedOnly): ?array {
        $cacheKey = $horseId . ':' . (int)$publishedOnly;
        if (array_key_exists($cacheKey, self::$rowCache)) {
            $cached = self::$rowCache[$cacheKey];
            return $cached === false ? null : $cached;
        }

        $publishedFilter = $publishedOnly ? " AND is_published = 1" : "";
        $stmt = $db->prepare("SELECT id, name, ueln, birth_year, color, sire_id, sire_name, sire_ueln, dam_id, dam_name, dam_ueln FROM horses WHERE id = ? AND deleted_at IS NULL{$publishedFilter}");
        $stmt->execute([$horseId]);
        $horse = $stmt->fetch();

        self::$rowCache[$cacheKey] = $horse === false ? false : $horse;
        return $horse === false ? null : $horse;
    }

    /**
     * Searches for a matching parent horse by primary UELN, foreign UELN or Name if FK is NULL.
     * Im publishedOnly-Modus werden unveröffentlichte Treffer ignoriert, sodass der
     * Aufrufer stattdessen den Freitext-Platzhalter verwendet (kein Datenleck).
     * Ergebnisse werden je build()-Aufruf memoisiert (#119).
     */
    private static function findParentByUelnOrName(\PDO $db, ?string $ueln, ?string $name, bool $publishedOnly = false): ?int {
        $cleanUeln = trim($ueln ?? '');
        $cleanName = trim($name ?? '');
        $cacheKey = $cleanUeln . "\0" . $cleanName . "\0" . (int)$publishedOnly;
        if (array_key_exists($cacheKey, self::$parentLookupCache)) {
            return self::$parentLookupCache[$cacheKey];
        }

        $publishedFilter = $publishedOnly ? " AND is_published = 1" : "";
        $result = null;

        if (!empty($cleanUeln)) {
            // Seit #246 zählt auch eine weitere Lebensnummer (horse_registrations)
            // als Treffer; foreign_ueln bleibt als Kompatibilitäts-Fallback dabei.
            $stmt = $db->prepare("SELECT id FROM horses WHERE deleted_at IS NULL{$publishedFilter} AND (ueln = ? OR foreign_ueln = ? OR id IN (SELECT horse_id FROM horse_registrations WHERE registration_number = ?)) LIMIT 1");
            $stmt->execute([$cleanUeln, $cleanUeln, $cleanUeln]);
            $foundId = $stmt->fetchColumn();
            if ($foundId) $result = (int)$foundId;
        }

        if ($result === null && !empty($cleanName)) {
            // Kein LOWER(): die Spalte ist utf8mb4_unicode_ci und damit ohnehin
            // case-insensitiv - LOWER() würde nur jede Index-Nutzung verhindern (#119).
            $stmt = $db->prepare("SELECT id FROM horses WHERE deleted_at IS NULL{$publishedFilter} AND name = ? LIMIT 1");
            $stmt->execute([$cleanName]);
            $foundId = $stmt->fetchColumn();
            if ($foundId) $result = (int)$foundId;
        }

        self::$parentLookupCache[$cacheKey] = $result;
        return $result;
    }
}
