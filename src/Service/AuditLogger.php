<?php
// src/Service/AuditLogger.php

namespace App\Service;

use App\Database;

class AuditLogger {

    /**
     * Logs an audit event into the database.
     *
     * @param string $action Action description (e.g. "Pferd erstellt", "Mail gesendet", "SMTP Fehler")
     * @param string $category Category (e.g. "horses", "users", "email", "auth", "settings")
     * @param string|null $details Extra information / context (e.g. "ID: 42, Name: Storm")
     * @param int|null $userId Optional override for User ID (defaults to session user or NULL if SYSTEM)
     * @param string|null $username Optional override for Username (defaults to session username or "SYSTEM")
     */
    public static function log(string $action, string $category = 'general', ?string $details = null, ?int $userId = null, ?string $username = null): void {
        try {
            $db = Database::getInstance();

            // Determine User Context
            if ($userId === null && $username === null) {
                if (isset($_SESSION['user_id'])) {
                    $userId = (int)$_SESSION['user_id'];
                    $username = $_SESSION['username'] ?? null;
                    
                    // Fetch username from DB if not stored in session
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

            // Client IP
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ipAddress = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            }

            $stmt = $db->prepare("INSERT INTO audit_logs (user_id, username, action, category, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $userId,
                $username,
                self::truncate($action, 100),
                self::truncate($category, 50),
                $details,
                self::truncate($ipAddress, 45)
            ]);
        } catch (\Exception $e) {
            // Fail gracefully so logging errors never crash main app flow
            error_log("AuditLogger Error: " . $e->getMessage());
        }
    }

    private static function truncate(string $str, int $length): string {
        if (function_exists('\mb_substr')) {
            return \mb_substr($str, 0, $length, 'UTF-8');
        }
        return \substr($str, 0, $length);
    }
}
