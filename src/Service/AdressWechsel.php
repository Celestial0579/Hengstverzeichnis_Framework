<?php
// src/Service/AdressWechsel.php

namespace App\Service;

use App\Security\KontoSicherheit;
use PDO;

/**
 * Übernahme einer bestätigten neuen E-Mail-Adresse (Audit N52, N53).
 *
 * ATOMAR (N53). Vorher suchte ein SELECT das Konto per Token, und ein
 * UPDATE mit nur `WHERE id = ?` schrieb `email = pending_email`. Riefen ein
 * Mailscanner und der Benutzer den Link fast gleichzeitig auf, übernahm der
 * erste die Adresse - der zweite las beim UPDATE den aktuellen Stand, in dem
 * `pending_email` schon NULL war, und setzte `email = NULL`. Jetzt trägt das
 * UPDATE alle Bedingungen selbst (Token, Frist, aktives Konto); der
 * InnoDB-„current read" lässt einen zweiten Aufruf keine Zeile mehr finden.
 * Ob übernommen wurde, entscheidet allein rowCount().
 *
 * VERGEBEN (N52). Der UNIQUE-Index auf `users.email` umfasst auch Konten im
 * Papierkorb, und zwischen Antrag und Bestätigung kann die Adresse einem
 * anderen Konto zugeordnet worden sein. Bisher endete das in einem
 * unbehandelten Duplikatsfehler (HTTP 500), und der Antrag hing fest.
 */
final class AdressWechsel {

    public const UEBERNOMMEN = 'uebernommen';
    public const NICHT_MEHR_OFFEN = 'nicht_mehr_offen';
    public const VERGEBEN = 'vergeben';

    /** MariaDB/MySQL: Duplicate entry for key. */
    private const DUPLIKAT = 1062;

    private function __construct() {}

    /**
     * Übernimmt $neueAdresse für $userId, sofern der Antrag mit $tokenHash
     * noch offen ist.
     *
     * $tokenHash ist der gespeicherte SHA-256-Abdruck, nie der Klartext.
     */
    public static function uebernehmen(PDO $db, int $userId, string $tokenHash, string $neueAdresse): string {
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?");
        $stmt->execute([$neueAdresse, $userId]);
        if ((int)$stmt->fetchColumn() > 0) {
            KontoSicherheit::adressantragVerwerfen($userId, 'Adresse inzwischen vergeben');
            return self::VERGEBEN;
        }

        try {
            $stmt = $db->prepare(
                "UPDATE users
                 SET email = pending_email, pending_email = NULL, pending_email_token = NULL,
                     pending_email_expires_at = NULL, unprotected_since = NULL
                 WHERE id = ? AND pending_email_token = ? AND pending_email IS NOT NULL
                   AND pending_email_expires_at > NOW()
                   AND deleted_at IS NULL AND deactivated_at IS NULL"
            );
            $stmt->execute([$userId, $tokenHash]);
        } catch (\PDOException $e) {
            // Das Wettrennen, das die Vorprüfung nicht sieht: Die Adresse
            // wurde zwischen COUNT und UPDATE vergeben.
            if ((int)($e->errorInfo[1] ?? 0) === self::DUPLIKAT) {
                KontoSicherheit::adressantragVerwerfen($userId, 'Adresse inzwischen vergeben');
                return self::VERGEBEN;
            }
            throw $e;
        }

        // Affected rows, nicht gefundene: Das UPDATE ändert mindestens
        // pending_email, und MYSQL_ATTR_FOUND_ROWS ist nicht gesetzt.
        return $stmt->rowCount() === 1 ? self::UEBERNOMMEN : self::NICHT_MEHR_OFFEN;
    }
}
