<?php
// src/Service/LoginSession.php

namespace App\Service;

use App\Database;

/**
 * Class LoginSession
 *
 * Baut nach erfolgreicher Authentifizierung die angemeldete Session auf -
 * gemeinsam genutzt vom lokalen Login-Flow (AuthController::completeLogin())
 * und vom EntraID-SSO-Login (#42, EntraSsoController). Eine einzige
 * Implementierung, damit Session-Härtung (User-Agent-Fingerprint,
 * session_regenerate_id, session_version für #113, must_change_password-
 * Handling) nie zwischen den Login-Wegen auseinanderläuft.
 */
class LoginSession {

    /**
     * Legt die Session an und leitet weiter (beendet den Request).
     */
    public static function establish(int $userId, string $redirectSuccess = '/admin'): void {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT username, must_change_password, session_version FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $userRow = $stmt->fetch();

        $mustChange = (int)($userRow['must_change_password'] ?? 0);
        $username = $userRow['username'] ?? 'Unbekannt';

        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $username;
        $_SESSION['user_agent_hash'] = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
        $_SESSION['last_activity'] = time();
        $_SESSION['created_time'] = time();
        // Für die Session-Invalidierung bei Passwortänderung (#113, siehe
        // BaseController::checkAuth()).
        $_SESSION['session_version'] = (int)($userRow['session_version'] ?? 1);

        unset($_SESSION['pending_2fa_user_id']);
        session_regenerate_id(true);

        AuditLogger::log("Benutzer eingeloggt", "auth", "Erfolgreich angemeldet", $userId, $username);

        if ($mustChange === 1) {
            $_SESSION['must_change_password'] = 1;
            header("Location: /force-password-change");
            exit;
        }

        unset($_SESSION['must_change_password']);
        header("Location: " . $redirectSuccess);
        exit;
    }
}
