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
 * Reine Extraktion: Algorithmus/Verhalten unverändert übernommen, inklusive
 * der Besonderheit, dass ein synthetischer Platzhalter-Blattknoten (nicht in
 * der DB gefundener Vorfahre) `depth = $maxDepth + 1` tragen kann, weil sein
 * Aufbau nicht durch dieselbe `$currentDepth > $maxDepth`-Abbruchbedingung
 * wie der übrige rekursive DB-Lookup-Pfad geschützt ist. Das ist bestehendes
 * Verhalten (siehe public_horse_detail.php, gen-level-CSS-Klassen) - bei
 * einer künftigen Änderung bewusst entscheiden, nicht versehentlich "fixen".
 */
class PedigreeBuilder {

    /**
     * Baut den Abstammungsbaum für ein Pferd bis zur angegebenen Tiefe.
     *
     * @param int|null $horseId ID des Wurzel-Pferdes (Proband)
     * @param int $maxDepth Maximale Generationstiefe (1 = nur das Pferd selbst)
     * @return array|null Verschachtelte Knotenstruktur mit `id`, `name`, `ueln`,
     *   `birth_year`, `color`, `depth`, `is_placeholder`, `sire`, `dam` - oder
     *   `null`, wenn `$horseId` leer ist oder kein passendes Pferd existiert.
     */
    public static function build(?int $horseId, int $maxDepth = 4): ?array {
        return self::buildRecursive($horseId, 1, $maxDepth);
    }

    private static function buildRecursive(?int $horseId, int $currentDepth, int $maxDepth): ?array {
        if (!$horseId || $currentDepth > $maxDepth) {
            return null;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT id, name, ueln, birth_year, color, sire_id, sire_name, sire_ueln, dam_id, dam_name, dam_ueln FROM horses WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$horseId]);
        $horse = $stmt->fetch();

        if (!$horse) {
            return null;
        }

        $horse['depth'] = $currentDepth;

        // Sire resolution (FK or UELN/Foreign UELN/Name lookup fallback)
        if ($horse['sire_id']) {
            $horse['sire'] = self::buildRecursive($horse['sire_id'], $currentDepth + 1, $maxDepth);
        } else if (!empty($horse['sire_name']) || !empty($horse['sire_ueln'])) {
            $parentSireId = self::findParentByUelnOrName($db, $horse['sire_ueln'], $horse['sire_name']);
            if ($parentSireId) {
                $horse['sire'] = self::buildRecursive($parentSireId, $currentDepth + 1, $maxDepth);
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
            $horse['dam'] = self::buildRecursive($horse['dam_id'], $currentDepth + 1, $maxDepth);
        } else if (!empty($horse['dam_name']) || !empty($horse['dam_ueln'])) {
            $parentDamId = self::findParentByUelnOrName($db, $horse['dam_ueln'], $horse['dam_name']);
            if ($parentDamId) {
                $horse['dam'] = self::buildRecursive($parentDamId, $currentDepth + 1, $maxDepth);
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
     * Searches for a matching parent horse by primary UELN, foreign UELN or Name if FK is NULL
     */
    private static function findParentByUelnOrName(\PDO $db, ?string $ueln, ?string $name): ?int {
        $cleanUeln = trim($ueln ?? '');
        $cleanName = trim($name ?? '');

        if (!empty($cleanUeln)) {
            $stmt = $db->prepare("SELECT id FROM horses WHERE deleted_at IS NULL AND (ueln = ? OR foreign_ueln = ?) LIMIT 1");
            $stmt->execute([$cleanUeln, $cleanUeln]);
            $foundId = $stmt->fetchColumn();
            if ($foundId) return (int)$foundId;
        }

        if (!empty($cleanName)) {
            $stmt = $db->prepare("SELECT id FROM horses WHERE deleted_at IS NULL AND LOWER(name) = LOWER(?) LIMIT 1");
            $stmt->execute([$cleanName]);
            $foundId = $stmt->fetchColumn();
            if ($foundId) return (int)$foundId;
        }

        return null;
    }
}
