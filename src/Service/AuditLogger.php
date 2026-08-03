<?php
// src/Service/AuditLogger.php

namespace App\Service;

use App\Database;

/**
 * Class AuditLogger
 * 
 * Revisionssicherer Audit-Protokoll-Dienst.
 * Zeichnet alle sicherheits- und datenrelevanten Systemereignisse (Pferde, Personen,
 * Deckstationen, Einstellungen, Logins, 403-Sicherheitsverstöße, E-Mail-Versand)
 * in der Datenbank-Tabelle `audit_logs` auf.
 */
class AuditLogger {

    /**
     * Protokolliert ein Audit-Ereignis in der Datenbank.
     *
     * @param string $action Kurzbeschreibung der Aktion (z. B. "Pferd erstellt", "Systemeinstellungen aktualisiert")
     * @param string $category Kategorie ("horses", "persons", "stations", "users", "settings", "email", "auth", "security", "trash")
     * @param string|null $details Zusatzinformationen / Kontext zur Aktion
     * @param int|null $userId Optionale Überschreibung der Benutzer-ID (Standard: $_SESSION['user_id'] oder NULL)
     * @param string|null $username Optionale Überschreibung des Benutzernamens (Standard: $_SESSION['username'] oder 'SYSTEM')
     */
    public static function log(string $action, string $category = 'general', ?string $details = null, ?int $userId = null, ?string $username = null): void {
        try {
            $db = Database::getInstance();

            // Benutzer-Kontext ermitteln (sofern nicht explizit übergeben)
            if ($userId === null && $username === null) {
                if (isset($_SESSION['user_id'])) {
                    $userId = (int)$_SESSION['user_id'];
                    $username = $_SESSION['username'] ?? null;
                    
                    // Benutzernamen aus der Datenbank nachladen, falls nicht in der Session vorhanden
                    if (empty($username)) {
                        $stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
                        $stmt->execute([$userId]);
                        $username = $stmt->fetchColumn() ?: 'Unbekannt';
                        $_SESSION['username'] = $username;
                    }
                } else {
                    $userId = null;
                    $username = 'SYSTEM';
                }
            }

            if (empty($username)) {
                $username = 'SYSTEM';
            }

            // Client-IP-Adresse ermitteln (berücksichtigt Reverse Proxies / Load Balancer)
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ipAddress = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            }

            // Log-Eintrag in der Datenbank speichern
            $stmt = $db->prepare("INSERT INTO audit_logs (user_id, username, action, category, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $userId,
                $username,
                self::truncate($action, 100),
                self::truncate($category, 50),
                $details,
                self::truncate($ipAddress, 45)
            ]);
        } catch (\Throwable $e) {
            // Ausfallsicherheit: Audit-Logger Fehler stören den normalen Anwendungsfluss nicht
            error_log("AuditLogger Failure: " . $e->getMessage());
        }
    }

    /**
     * Kürzt einen String sicher auf eine maximale Zeichenlänge.
     *
     * @param string $input Eingangstext
     * @param int $maxLength Maximale Zeichenanzahl
     * @return string Gekürzter Text
     */
    private static function truncate(string $input, int $maxLength): string {
        if (mb_strlen($input) > $maxLength) {
            return mb_substr($input, 0, $maxLength);
        }
        return $input;
    }
}
