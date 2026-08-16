<?php
// src/Service/AuditLogger.php

namespace App\Service;

use App\Database;
use App\Security\ClientIp;

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
    public static function log($action, string $category = 'general', $details = null, ?int $userId = null, ?string $username = null): void {
        try {
            $db = Database::getInstance();

            // Fallback für Session-Start
            if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
                @session_start();
            }

            // Benutzer-Kontext ermitteln (sofern nicht explizit übergeben)
            if ($userId === null && $username === null) {
                if (!empty($_SESSION['user_id'])) {
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

            // Parameter sicher formatieren (Arrays/Objekte in JSON wandeln)
            if (is_array($action) || is_object($action)) {
                $action = json_encode($action, JSON_UNESCAPED_UNICODE);
            } else {
                $action = (string)$action;
            }

            if (is_array($category) || is_object($category)) {
                $category = json_encode($category, JSON_UNESCAPED_UNICODE);
            } else {
                $category = (string)$category;
            }

            if (is_array($details) || is_object($details)) {
                $details = json_encode($details, JSON_UNESCAPED_UNICODE);
            } else if ($details !== null) {
                $details = (string)$details;
            }

            // Client-IP-Adresse ermitteln (berücksichtigt Reverse Proxies / Load Balancer
            // nur, wenn REMOTE_ADDR über TRUSTED_PROXIES als vertrauenswürdig gilt)
            $ipAddress = ClientIp::resolve();

            // Log-Eintrag in der Datenbank speichern
            $stmt = $db->prepare("INSERT INTO audit_logs (user_id, username, action, category, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $userId ?: null,
                $username,
                self::truncate($action, 100),
                self::truncate($category, 50),
                $details,
                self::truncate($ipAddress, 45)
            ]);
        } catch (\Throwable $e) {
            // Ausfallsicherheit: Audit-Logger Fehler stören den normalen Anwendungsfluss nicht
            error_log("AuditLogger Failure: " . $e->getMessage());
            $logDir = __DIR__ . '/../../storage/logs';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            @file_put_contents($logDir . '/audit_errors.log', date('[Y-m-d H:i:s] ') . $e->getMessage() . "\n", FILE_APPEND);

            // Automatische Dateibereinigung triggern
            self::cleanupLogs();
        }
    }

    /**
     * Automatische Bereinigung alter Log-Dateien in storage/logs (> 5 MB oder > 30 Tage)
     */
    public static function cleanupLogs(): void {
        $logFile = __DIR__ . '/../../storage/logs/audit_errors.log';
        if (file_exists($logFile)) {
            $maxSizeBytes = 5 * 1024 * 1024; // 5 Megabyte
            $maxAgeSeconds = 30 * 86400;     // 30 Tage

            if (filesize($logFile) > $maxSizeBytes || (time() - filemtime($logFile) > $maxAgeSeconds)) {
                $lines = @file($logFile);
                if ($lines && count($lines) > 100) {
                    $recentLines = array_slice($lines, -100);
                    @file_put_contents($logFile, implode('', $recentLines));
                }
            }
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
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($input) > $maxLength) {
                return mb_substr($input, 0, $maxLength);
            }
            return $input;
        }

        if (strlen($input) > $maxLength) {
            return substr($input, 0, $maxLength);
        }
        return $input;
    }
}
