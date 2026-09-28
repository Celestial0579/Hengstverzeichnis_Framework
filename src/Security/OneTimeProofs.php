<?php
// src/Security/OneTimeProofs.php

namespace App\Security;

use App\Database;

/**
 * Einmal-Nachweise, die GENAU EINMAL verbraucht werden (Audit N43).
 *
 * DAS PROBLEM. Backup-Code und TOTP-Zeitschlitz wurden gelesen, in PHP
 * geprüft und danach unbedingt zurückgeschrieben. Zwischen Lesen und
 * Schreiben liegt die Prüfung - bei den Backup-Codes zehn bcrypt-Vergleiche,
 * also spürbar Zeit. Parallel abgeschickte Anfragen lasen alle denselben
 * Stand:
 *
 * - Ein Backup-Code liess sich mehrfach einlösen, jede Anfrage schrieb "alle
 *   ausser diesem" zurück.
 * - Eine laufende Einlösung überschrieb einen gerade neu erzeugten Codesatz
 *   mit dem Rest des alten - die frisch ausgegebenen Codes galten nicht, die
 *   alten wieder.
 * - Beim TOTP-Code konnte eine langsamere Anfrage den gespeicherten
 *   Zeitschlitz wieder SENKEN und so einen verbrauchten Code erneut gültig
 *   machen.
 *
 * DIE LÖSUNG ist in beiden Fällen ein Vergleich mit dem gelesenen Stand im
 * UPDATE selbst (compare-and-swap). Geschrieben wird nur, wenn sich seit dem
 * Lesen nichts geändert hat; gewonnen hat, wessen UPDATE genau eine Zeile
 * trifft. Wer verliert, hat einen Fehlversuch - in einem echten Wettlauf
 * sieht ein legitimer Benutzer also höchstens einmal "ungültig".
 *
 * Fail-closed: kein try/catch. Ein Datenbankfehler ist hier nie ein
 * bestandener Nachweis.
 *
 * Die autoritativen Schreibvorgänge (TOTP neu einrichten, Codes erneuern,
 * 2FA-Reset durch die Verwaltung) bleiben unbedingt - sie setzen einen neuen
 * Stand, statt einen alten zu verbrauchen.
 */
final class OneTimeProofs {

    private function __construct() {}

    /**
     * Löst einen Backup-Code ein. true nur, wenn GENAU diese Anfrage den Code
     * aus dem gelesenen Satz entfernt hat.
     *
     * `backup_codes` ist TEXT mit ci-Collation; der Vergleich läuft deshalb
     * über BINARY. bcrypt-Abdrücke unterscheiden sich zwar ohnehin in
     * Gross-/Kleinschreibung, aber "gleich bis auf die Schreibweise" darf beim
     * Verbrauch eines Geheimnisses nie als gleich gelten.
     */
    public static function redeemBackupCode(int $userId, string $input): bool {
        $input = strtoupper(str_replace(['-', ' '], '', trim($input)));
        if ($input === '') {
            return false;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT backup_codes FROM users WHERE id = ? AND deleted_at IS NULL AND deactivated_at IS NULL"
        );
        $stmt->execute([$userId]);
        $roh = $stmt->fetchColumn();
        if ($roh === false || $roh === null) {
            return false;
        }
        $roh = (string)$roh;

        // json_decode liefert bei leerem String oder kaputtem JSON null -
        // sauber als "keine Codes vorhanden" behandeln (#128).
        $codes = json_decode($roh, true);
        if (!is_array($codes)) {
            return false;
        }

        $treffer = null;
        foreach ($codes as $schluessel => $abdruck) {
            if (is_string($abdruck) && password_verify($input, $abdruck)) {
                $treffer = $schluessel;
                break;
            }
        }
        if ($treffer === null) {
            return false;
        }

        unset($codes[$treffer]);
        $stmt = $db->prepare(
            "UPDATE users SET backup_codes = ?
             WHERE id = ? AND deleted_at IS NULL AND deactivated_at IS NULL
               AND CAST(backup_codes AS BINARY) = CAST(? AS BINARY)"
        );
        $stmt->execute([json_encode(array_values($codes)), $userId, $roh]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Verbraucht einen TOTP-Zeitschlitz. true nur, wenn der Schlitz NEUER ist
     * als der gespeicherte und diese Anfrage ihn geschrieben hat - der Wert
     * kann damit nur steigen (#111).
     */
    public static function consumeTotpSlice(int $userId, int $slice): bool {
        $stmt = Database::getInstance()->prepare(
            "UPDATE users SET last_totp_timeslice = ?
             WHERE id = ? AND deleted_at IS NULL AND deactivated_at IS NULL
               AND (last_totp_timeslice IS NULL OR last_totp_timeslice < ?)"
        );
        $stmt->execute([$slice, $userId, $slice]);

        return $stmt->rowCount() === 1;
    }
}
