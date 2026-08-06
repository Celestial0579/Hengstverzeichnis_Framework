<?php
// src/Controllers/EntraSsoController.php

namespace App\Controllers;

use App\Database;
use App\Security\OidcIdToken;

/**
 * Class EntraSsoController
 *
 * Optionaler Microsoft Entra ID (Azure AD)-Login per OIDC Authorization-Code-
 * Flow (#42) - als ZUSÄTZLICHE Login-Methode neben dem lokalen Login.
 * Konfiguration über Umgebungsvariablen bzw. db_config.php (analog
 * TRUSTED_PROXIES, siehe config/config.php): ENTRA_TENANT_ID,
 * ENTRA_CLIENT_ID, ENTRA_CLIENT_SECRET. Ohne vollständige Konfiguration sind
 * die Routen nicht erreichbar (404) und der Login-Button erscheint nicht.
 *
 * Sicherheits-Leitplanken:
 * - Kein Auto-Provisioning: SSO meldet ausschließlich BESTEHENDE lokale
 *   Konten an (Zuordnung über die E-Mail-Adresse). Unbekannte
 *   Entra-Identitäten werden abgewiesen - welche Konten existieren,
 *   entscheidet weiterhin allein der Admin (bzw. die Registrierung #83).
 * - CSRF-Schutz des Flows über den state-Parameter (Einmalwert in der
 *   Session, Vergleich per hash_equals).
 * - Das ID-Token kommt ausschließlich serverseitig vom Microsoft-Token-
 *   Endpunkt (TLS); Claims (aud/iss/exp) werden geprüft, siehe
 *   App\Security\OidcIdToken.
 * - Die lokale TOTP-2FA wird für SSO-Logins nicht zusätzlich verlangt -
 *   Entra ID bringt eigene MFA-/Conditional-Access-Richtlinien mit
 *   (siehe Issue #42); die Session-Härtung (App\Service\LoginSession)
 *   ist identisch zum lokalen Login.
 */
class EntraSsoController extends BaseController {

    private const AUTHORITY_BASE = 'https://login.microsoftonline.com';

    public static function isConfigured(): bool {
        return self::config('ENTRA_TENANT_ID') !== ''
            && self::config('ENTRA_CLIENT_ID') !== ''
            && self::config('ENTRA_CLIENT_SECRET') !== '';
    }

    private static function config(string $name): string {
        if (defined($name)) {
            return trim((string)constant($name));
        }
        $env = getenv($name);
        return $env === false ? '' : trim($env);
    }

    private function redirectUri(): string {
        return rtrim((string)(defined('APP_URL') ? APP_URL : ''), '/') . '/auth/entra/callback';
    }

    /**
     * Startet den Flow: Redirect zum Entra-Authorize-Endpunkt.
     */
    public function redirect(): void {
        if (!self::isConfigured()) {
            $this->renderNotFound();
        }

        $state = bin2hex(random_bytes(16));
        $_SESSION['entra_sso_state'] = $state;

        $params = http_build_query([
            'client_id' => self::config('ENTRA_CLIENT_ID'),
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'response_mode' => 'query',
            'scope' => 'openid profile email',
            'state' => $state,
        ]);

        $tenant = rawurlencode(self::config('ENTRA_TENANT_ID'));
        header("Location: " . self::AUTHORITY_BASE . "/{$tenant}/oauth2/v2.0/authorize?{$params}");
        exit;
    }

    /**
     * Callback: state prüfen, Code gegen Token tauschen, Claims validieren,
     * lokales Konto anmelden.
     */
    public function callback(): void {
        if (!self::isConfigured()) {
            $this->renderNotFound();
        }

        $expectedState = (string)($_SESSION['entra_sso_state'] ?? '');
        unset($_SESSION['entra_sso_state']);
        $state = (string)($_GET['state'] ?? '');
        if ($expectedState === '' || $state === '' || !hash_equals($expectedState, $state)) {
            $this->failLogin('Ungültiger oder abgelaufener SSO-Anmeldeversuch (state). Bitte erneut versuchen.');
        }

        if (isset($_GET['error'])) {
            $this->failLogin('Microsoft-Anmeldung abgebrochen oder fehlgeschlagen: ' . (string)$_GET['error']);
        }

        $code = (string)($_GET['code'] ?? '');
        if ($code === '') {
            $this->failLogin('Microsoft-Anmeldung lieferte keinen Autorisierungscode.');
        }

        try {
            $idToken = $this->exchangeCodeForIdToken($code);
            $claims = OidcIdToken::parseAndValidate(
                $idToken,
                self::config('ENTRA_CLIENT_ID'),
                self::config('ENTRA_TENANT_ID')
            );
        } catch (\Throwable $e) {
            \App\Service\AuditLogger::log('EntraID-SSO-Login fehlgeschlagen', 'auth', $e->getMessage());
            $this->failLogin('Die Microsoft-Anmeldung konnte nicht abgeschlossen werden.');
            return; // failLogin beendet den Request, return nur für die statische Analyse
        }

        $email = OidcIdToken::extractEmail($claims);
        if ($email === null) {
            $this->failLogin('Die Microsoft-Anmeldung lieferte keine verwendbare E-Mail-Adresse.');
        }

        // Kein Auto-Provisioning: nur bestehende, aktive lokale Konten.
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT id, email_verification_token FROM users WHERE email = ? AND deleted_at IS NULL");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            \App\Service\AuditLogger::log('EntraID-SSO-Login abgewiesen', 'auth', "Kein lokales Konto für {$email}");
            $this->failLogin('Für diese Microsoft-Identität existiert kein Konto in dieser Installation. Bitte wenden Sie sich an den Administrator.');
        }
        if (!empty($user['email_verification_token'])) {
            $this->failLogin(\App\I18n\Translator::t('auth.email_not_verified'));
        }

        \App\Service\LoginSession::establish((int)$user['id'], '/admin?sso=entra');
    }

    /**
     * Tauscht den Autorisierungscode am Token-Endpunkt gegen das ID-Token
     * (serverseitig, mit Client-Secret).
     */
    private function exchangeCodeForIdToken(string $code): string {
        $tenant = rawurlencode(self::config('ENTRA_TENANT_ID'));
        $url = self::AUTHORITY_BASE . "/{$tenant}/oauth2/v2.0/token";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => self::config('ENTRA_CLIENT_ID'),
                'client_secret' => self::config('ENTRA_CLIENT_SECRET'),
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri(),
                'scope' => 'openid profile email',
            ]),
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException("Token-Endpunkt nicht erreichbar: {$error}");
        }
        $data = json_decode((string)$body, true);
        if ($status !== 200 || !is_array($data) || empty($data['id_token'])) {
            $apiError = is_array($data) ? (string)($data['error'] ?? '') : '';
            throw new \RuntimeException("Token-Tausch fehlgeschlagen (HTTP {$status}" . ($apiError !== '' ? ", {$apiError}" : '') . ').');
        }

        return (string)$data['id_token'];
    }

    /**
     * Zeigt die Login-Seite mit Fehlermeldung und beendet den Request.
     */
    private function failLogin(string $message): void {
        $this->render('login', [
            'title' => \App\I18n\Translator::t('meta.title_login_failed'),
            'error' => $message,
        ]);
        exit;
    }
}
