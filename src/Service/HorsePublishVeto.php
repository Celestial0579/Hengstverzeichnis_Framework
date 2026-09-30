<?php
// src/Service/HorsePublishVeto.php

namespace App\Service;

use App\Database;
use App\Plugin\PluginManager;

/**
 * Veto der Addons gegen die VERÖFFENTLICHUNG eines Pferds (#335, Audit N49).
 *
 * Addons erheben Einwände über den Filter `horse.publish_blockers`. Der
 * Einwand verhindert nur die Veröffentlichung, nie das Speichern.
 *
 * WARUM EIN DIENST. Bis Audit N49 schrieb das Formular `is_published = 1`
 * schon im Haupt-INSERT/-UPDATE und nahm das Häkchen erst am Ende des
 * Speicherpfads zurück, wenn ein Addon Einwände hatte. Brach das Speichern
 * dazwischen ab (etwa ein Jahr „70000“ in einer Zuordnungszeile), blieb das
 * Pferd ungeprüft öffentlich. Jetzt gilt überall dieselbe Reihenfolge:
 * erst speichern (unveröffentlicht bzw. im bisherigen Zustand), dann
 * freigeben() - der Übergang 0→1 geschieht nur hier. Formular (store(),
 * update()), Massen-Veröffentlichung (bulkPublish()) und CSV-Import
 * (ImportController::commit()) nutzen diesen einen Weg.
 *
 * FAIL-OPEN FÜR DAS VETO: Liefert der Filter Unbrauchbares (ein Addon ist
 * abgestürzt, der HookManager verschluckt die Ausnahme und behält den
 * vorherigen Wert), gilt „keine Einwände“ - der Startwert ist die leere
 * Liste. Ein abgestürztes Addon darf keine Veröffentlichung blockieren,
 * sonst könnte niemand den Grund beheben.
 */
final class HorsePublishVeto {

    private function __construct() {}

    /**
     * Einwände der Addons gegen die Veröffentlichung, gegen den
     * PERSISTIERTEN Stand (die Zuordnungen in horse_persons entstehen erst
     * nach dem INSERT des Pferds). Pferde im Papierkorb oder unbekannte IDs:
     * keine Einwände - veröffentlicht werden sie von freigeben() ohnehin nicht.
     *
     * @return string[] Menschenlesbare Gründe; leer = veröffentlichen ist in Ordnung.
     */
    public static function einwaende(int $horseId): array {
        $stmt = Database::getInstance()->prepare(
            "SELECT * FROM horses WHERE id = ? AND deleted_at IS NULL"
        );
        $stmt->execute([$horseId]);
        $horse = $stmt->fetch();
        if (!$horse) {
            return [];
        }

        $blockers = PluginManager::getInstance()->getHooks()->applyFilters('horse.publish_blockers', [], $horseId, $horse);
        if (!is_array($blockers)) {
            return [];
        }

        $sauber = [];
        foreach ($blockers as $grund) {
            if (is_string($grund) && trim($grund) !== '') {
                $sauber[] = trim($grund);
            }
        }
        return $sauber;
    }

    /**
     * Veröffentlicht das Pferd, wenn kein Addon Einwände hat, und setzt es
     * sonst auf unveröffentlicht. Pferde im Papierkorb bleiben unverändert.
     *
     * @return string[] Die Einwände; leer heißt: veröffentlicht.
     */
    public static function freigeben(int $horseId): array {
        $gruende = self::einwaende($horseId);
        Database::getInstance()
            ->prepare("UPDATE horses SET is_published = ? WHERE id = ? AND deleted_at IS NULL")
            ->execute([$gruende === [] ? 1 : 0, $horseId]);
        return $gruende;
    }
}
