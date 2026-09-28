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
 *
 * Seit Audit N9 kommt der SSO-Login nicht mehr direkt hierher, sondern wie
 * der lokale über die Faktorweiche AuthController::nachErstemFaktor(). Im
 * Audit-Log ist er an der Marke `anmeldeweg` erkennbar.
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

        // Anmeldeweg für das Audit-Log (Audit N9). Die Marke setzt die
        // Faktorweiche (AuthController::nachErstemFaktor()) für SSO-Logins;
        // sie zählt nur für GENAU dieses Konto und wird in jedem Fall
        // verbraucht - ein liegengebliebener SSO-Versuch darf keinen späteren
        // Login eines anderen Kontos falsch etikettieren.
        $weg = $_SESSION['anmeldeweg'] ?? null;
        unset($_SESSION['anmeldeweg']);
        $detail = self::auditDetail(is_array($weg) ? $weg : null, $userId);
        $username = $userRow['username'] ?? 'Unbekannt';

        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $username;
        $_SESSION['user_agent_hash'] = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
        $_SESSION['last_activity'] = time();
        $_SESSION['created_time'] = time();
        // Für die Session-Invalidierung bei Passwortänderung (#113, siehe
        // BaseController::checkAuth()).
        $_SESSION['session_version'] = (int)($userRow['session_version'] ?? 1);

        // Mit dem Anmeldevorgang endet auch die Marke "zweiter Faktor in
        // diesem Login bestanden" (Audit M32, AuthController::
        // afterSecondFactor()) - eine fertige Sitzung braucht sie nicht, und
        // übrig bleiben soll sie nirgends.
        unset($_SESSION['pending_2fa_user_id'], $_SESSION['zweiter_faktor_bestanden']);
        session_regenerate_id(true);

        AuditLogger::log("Benutzer eingeloggt", "auth", $detail, $userId, $username);

        if ($mustChange === 1) {
            $_SESSION['must_change_password'] = 1;
            header("Location: /force-password-change");
            exit;
        }

        unset($_SESSION['must_change_password']);
        header("Location: " . $redirectSuccess);
        exit;
    }

    /**
     * Detailtext des Eintrags "Benutzer eingeloggt".
     *
     * @param array<string, mixed>|null $weg
     */
    private static function auditDetail(?array $weg, int $userId): string {
        if ($weg === null || ($weg['art'] ?? '') !== 'sso' || (int)($weg['user_id'] ?? 0) !== $userId) {
            return 'Erfolgreich angemeldet';
        }

        $idpMfa = (string)($weg['idp_mfa'] ?? '');
        $faktor = match (true) {
            $idpMfa !== '' => "beim IdP nachgewiesen ({$idpMfa})",
            !empty($weg['lokaler_faktor']) => 'lokal verlangt',
            default => 'nicht verlangt',
        };

        return sprintf(
            'Erfolgreich angemeldet per SSO (%s, iss=%s, sub=%s; zweiter Faktor: %s)',
            (string)($weg['provider'] ?? ''),
            (string)($weg['iss'] ?? ''),
            (string)($weg['sub'] ?? ''),
            $faktor
        );
    }
}
