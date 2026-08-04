<?php
// src/Security/RateLimiter.php

namespace App\Security;

use App\Database;

/**
 * Class RateLimiter
 *
 * Einfacher, datenbankgestützter Brute-Force-Schutz für Login, 2FA-Code
 * und Backup-Code-Verifizierung. Zählt fehlgeschlagene Versuche pro
 * Identifier (z. B. E-Mail oder Benutzer-ID) innerhalb eines Zeitfensters.
 */
class RateLimiter {

    /**
     * Prüft, ob für den gegebenen Identifier/Typ das Versuchslimit erreicht wurde.
     *
     * @param string $identifier Eindeutiger Bezug (z. B. E-Mail-Adresse oder Benutzer-ID)
     * @param string $type Art des Versuchs ('login', '2fa', 'backup')
     * @param int $maxAttempts Maximale Anzahl fehlgeschlagener Versuche im Zeitfenster
     * @param int $windowSeconds Länge des Zeitfensters in Sekunden
     * @return bool True, wenn das Limit erreicht ist und der Versuch geblockt werden muss
     */
    public static function tooManyAttempts(string $identifier, string $type, int $maxAttempts = 5, int $windowSeconds = 900): bool {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND type = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)");
            $stmt->execute([strtolower($identifier), $type, $windowSeconds]);
            return (int)$stmt->fetchColumn() >= $maxAttempts;
        } catch (\Throwable $e) {
            // Bei DB-Fehlern nicht blockieren (Ausfallsicherheit)
            return false;
        }
    }

    /**
     * Protokolliert einen fehlgeschlagenen Versuch.
     */
    public static function recordAttempt(string $identifier, string $type): void {
        try {
            $db = Database::getInstance();
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ipAddress = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            }

            $stmt = $db->prepare("INSERT INTO login_attempts (identifier, type, ip_address) VALUES (?, ?, ?)");
            $stmt->execute([strtolower($identifier), $type, $ipAddress]);
        } catch (\Throwable $e) {
            // Ausfallsicherheit: Rate-Limiter-Fehler dürfen den Login-Flow nicht blockieren
        }
    }

    /**
     * Löscht alle protokollierten Fehlversuche nach erfolgreicher Authentifizierung.
     */
    public static function clearAttempts(string $identifier, string $type): void {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("DELETE FROM login_attempts WHERE identifier = ? AND type = ?");
            $stmt->execute([strtolower($identifier), $type]);
        } catch (\Throwable $e) {
            // Ausfallsicherheit
        }
    }
}
