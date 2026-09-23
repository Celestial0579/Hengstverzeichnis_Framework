<?php
// src/Service/SystemReset.php

namespace App\Service;

/**
 * Welche Tabellen ein Werksreset leert - gemeinsam für beide Reset-Wege,
 * AdminController::resetSystem() (Oberfläche) und database/reset.php (CLI).
 *
 * Die Liste stand früher an beiden Stellen getrennt und lief auseinander:
 * #118 ergänzte user_groups nur im Controller, das CLI-Skript ließ die
 * Zuordnungen stehen (#451). Weil TRUNCATE users den AUTO_INCREMENT
 * zurückstellt, erbten danach neu angelegte Konten die Gruppen der alten
 * Benutzer mit derselben id - bis hin zu Administratorrechten.
 *
 * Nicht geleert werden bewusst audit_logs (bleibt über Resets erhalten) und
 * groups (die Gruppen-IDs bleiben stabil).
 */
final class SystemReset {

    /**
     * Reihenfolge ist egal, TRUNCATE läuft unter FOREIGN_KEY_CHECKS = 0.
     *
     * - contact_id_map MUSS mit contacts zusammen geleert werden (#336): Sie
     *   bildet alte Personen-/Stationskennungen auf Kontakte ab. Bliebe sie
     *   stehen, landeten die alten Adressen /person?id= und /station?id= nach
     *   dem Reset bei einem fremden Kontakt.
     * - Alles, was per user_id an users hängt, MUSS mit users zusammen geleert
     *   werden (#118, #451): TRUNCATE feuert kein ON DELETE CASCADE, und
     *   TRUNCATE users stellt den AUTO_INCREMENT zurück. Verwaiste Zeilen
     *   gehörten danach dem neuen Konto mit derselben id - Gruppen
     *   (user_groups), aber auch API-Schlüssel (api_keys: ein neues Konto
     *   startet wieder bei session_version 1, der alte Schlüssel wäre gültig)
     *   und Passkeys (user_passkeys: Anmeldung als das neue Konto).
     * - Dasselbe gilt für horse_media an horses und für match_labels, das
     *   Pferde- und Kontaktkennungen ohne Fremdschlüssel paarweise speichert.
     *
     * SystemResetTest leitet aus database/schema.sql ab, dass jede Tabelle
     * mit einem Fremdschlüssel auf eine geleerte Tabelle selbst hier steht.
     */
    public const TABLES = [
        'horse_persons',
        'horse_registrations',
        'horse_media',
        'match_labels',
        'password_resets',
        'gdpr_requests',
        'horses',
        'contact_id_map',
        'contacts',
        'user_groups',
        'api_keys',
        'user_passkeys',
        'email_2fa_codes',
        'users',
        'settings',
    ];

    /** Leert alle Tabellen aus TABLES. */
    public static function truncateAll(\PDO $db): void {
        $db->exec("SET FOREIGN_KEY_CHECKS = 0;");
        try {
            foreach (self::TABLES as $table) {
                $db->exec("TRUNCATE TABLE `{$table}`;");
            }
        } finally {
            $db->exec("SET FOREIGN_KEY_CHECKS = 1;");
        }
    }
}
