<?php
// src/Security/BackupCodes.php

namespace App\Security;

use App\Database;

/**
 * Backup-Codes eines Kontos erneuern und zählen.
 *
 * Herausgelöst aus ProfileController, weil seit Audit N10 auch die
 * Passkey-Registrierung Codes erzeugt: Wer als ersten Faktor einen Passkey
 * einrichtet und keine Codes hat, steht nach dem Verlust des Geräts sonst
 * ohne Rückweg da.
 *
 * Gespeichert werden nur die Abdrücke. Der Klartext liegt EINMAL in der
 * Sitzung, an die Konto-ID gebunden, und verschwindet mit der Anzeige
 * (ProfileController::einmaligeCodesAbholen()).
 */
final class BackupCodes {

    /** Sitzungsschlüssel für die einmalige Anzeige im Profil. */
    public const SESSION = 'profile_new_backup_codes';

    private function __construct() {}

    /**
     * Zehn frische Codes; die bisherigen verlieren sofort ihre Gültigkeit.
     *
     * Ein beim Nachweis verbrauchter TOTP-Zeitschlitz wird NICHT hier
     * geschrieben, sondern dort, wo der Code geprüft wurde
     * (StepUp::codePruefen()) - an einer Stelle, damit ihn kein Weg vergisst.
     *
     * @return array<int, string> die Codes im Klartext
     */
    public static function erneuern(int $userId): array {
        $codes = Totp::generateBackupCodes(10);
        $abdruecke = array_map(
            static fn(string $c): string => password_hash(str_replace('-', '', strtoupper($c)), PASSWORD_DEFAULT),
            $codes
        );

        Database::getInstance()
            ->prepare("UPDATE users SET backup_codes = ? WHERE id = ?")
            ->execute([json_encode($abdruecke), $userId]);

        $_SESSION[self::SESSION] = ['user_id' => $userId, 'codes' => $codes];
        return $codes;
    }

    /**
     * Zahl der noch ungenutzten Codes in `users.backup_codes`.
     *
     * json_decode() liefert bei NULL oder kaputtem JSON null - das muss als 0
     * gelten und nicht in einen TypeError laufen.
     */
    public static function offen(?string $gespeichert): int {
        $codes = json_decode((string)($gespeichert ?? '[]'), true);
        return is_array($codes) ? count($codes) : 0;
    }

    /** Dasselbe direkt aus der Datenbank. */
    public static function offenFuer(int $userId): int {
        $stmt = Database::getInstance()->prepare("SELECT backup_codes FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $wert = $stmt->fetchColumn();
        return self::offen($wert === false || $wert === null ? null : (string)$wert);
    }
}
