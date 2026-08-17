<?php
// src/Controllers/EntraSsoController.php

namespace App\Controllers;

use App\Database;
use App\Security\OidcDiscovery;
use App\Security\OidcIdToken;

/**
 * Class EntraSsoController
 *
 * Optionaler SSO-Login per OIDC Authorization-Code-Flow (#42) - als
 * ZUSÄTZLICHE Login-Methode neben dem lokalen Login. Zwei Betriebsarten,
 * konfiguriert über Umgebungsvariablen bzw. db_config.php (analog
 * TRUSTED_PROXIES, siehe config/config.php):
 *
 * - **Generischer OIDC-Modus** (Authentik, Keycloak, ...): OIDC_ISSUER_URL,
 *   OIDC_CLIENT_ID, OIDC_CLIENT_SECRET (+ optional OIDC_PROVIDER_LABEL für
 *   den Login-Button). Authorize-/Token-Endpunkt kommen per OIDC-Discovery
 *   vom Issuer (App\Security\OidcDiscovery, inkl. Issuer-Gegenprüfung und
 *   https-Pflicht). Hat bei vollständiger Konfiguration Vorrang.
 * - **ENTRA-Modus** (Microsoft-Kurzform, unverändertes Verhalten):
 *   ENTRA_TENANT_ID, ENTRA_CLIENT_ID, ENTRA_CLIENT_SECRET mit den bekannten
 *   festen login.microsoftonline.com-Endpunkten, ohne Discovery.
 *
 * Ohne vollständige Konfiguration (keiner der beiden Modi) sind die Routen
 * nicht erreichbar (404) und der Login-Button erscheint nicht. Die Routen
 * heißen aus Kompatibilität weiterhin /auth/entra* - bestehende
 * Redirect-URIs in Entra-App-Registrierungen bleiben gültig; für andere
 * Provider wird `<Stamm-URL>/auth/entra/callback` als Redirect-URI
 * eingetragen (siehe docs/security.md).
 *
 * Sicherheits-Leitplanken:
 * - Kein Auto-Provisioning: SSO meldet ausschließlich BESTEHENDE lokale
 *   Konten an (Zuordnung über die E-Mail-Adresse). Unbekannte Identitäten
 *   werden abgewiesen - welche Konten existieren, entscheidet weiterhin
 *   allein der Admin (bzw. die Registrierung #83).
 * - CSRF-Schutz des Flows über den state-Parameter (Einmalwert in der
 *   Session, Vergleich per hash_equals).
 * - Das ID-Token kommt ausschließlich serverseitig vom Token-Endpunkt (TLS);
 *   Claims (aud/iss/exp) werden geprüft, siehe App\Security\OidcIdToken.
 *   Im generischen Modus wird der beim Redirect per Discovery ermittelte
 *   token_endpoint in der Session festgehalten und im Callback verwendet -
 *   ein Discovery-Aufruf pro Login-Versuch, kein Cache.
 * - Die lokale TOTP-2FA wird für SSO-Logins nicht zusätzlich verlangt -
 *   der Identity-Provider bringt eigene MFA-Richtlinien mit (siehe Issue
 *   #42); die Session-Härtung (App\Service\LoginSession) ist identisch zum
 *   lokalen Login.
 */
class EntraSsoController extends BaseController {

    private const AUTHORITY_BASE = 'https://login.microsoftonline.com';

    public static function isConfigured(): bool {
        return self::isGenericMode() || self::isEntraMode();
    }

    /**
     * Generischer OIDC-Modus: alle drei OIDC_*-Pflichtwerte gesetzt.
     * Hat Vorrang vor dem ENTRA-Modus. Public, damit die Login-View im
     * ENTRA-Modus weiterhin den unveränderten Microsoft-Button rendert.
     */
    public static function isGenericMode(): bool {
        return self::config('OIDC_ISSUER_URL') !== ''
            && self::config('OIDC_CLIENT_ID') !== ''
            && self::config('OIDC_CLIENT_SECRET') !== '';
    }

    private static function isEntraMode(): bool {
        return self::config('ENTRA_TENANT_ID') !== ''
            && self::config('ENTRA_CLIENT_ID') !== ''
            && self::config('ENTRA_CLIENT_SECRET') !== '';
    }

    /**
     * Anzeigename des Providers für Login-Button und Fehlermeldungen:
     * im generischen Modus OIDC_PROVIDER_LABEL (Default 'SSO'), im
     * ENTRA-Modus wie bisher Microsoft.
     */
    public static function providerLabel(): string {
        if (self::isGenericMode()) {
            $label = self::config('OIDC_PROVIDER_LABEL');
            return $label !== '' ? $label : 'SSO';
        }
        return 'Microsoft';
    }

    private static function clientId(): string {
        return self::isGenericMode() ? self::config('OIDC_CLIENT_ID') : self::config('ENTRA_CLIENT_ID');
    }

    private static function clientSecret(): string {
        return self::isGenericMode() ? self::config('OIDC_CLIENT_SECRET') : self::config('ENTRA_CLIENT_SECRET');
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
     * Startet den Flow: Redirect zum Authorize-Endpunkt des Providers.
     */
    public function redirect(): void {
        if (!self::isConfigured()) {
            $this->renderNotFound();
        }

        $state = bin2hex(random_bytes(16));

        $params = [
            'client_id' => self::clientId(),
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'scope' => 'openid profile email',
            'state' => $state,
        ];

        if (self::isGenericMode()) {
            try {
                $endpoints = OidcDiscovery::fetch(self::config('OIDC_ISSUER_URL'));
            } catch (\Throwable $e) {
                \App\Service\AuditLogger::log('OIDC-SSO-Discovery fehlgeschlagen', 'auth', $e->getMessage());
                $this->failLogin(\App\I18n\Translator::t('auth.sso_failed', ['provider' => self::providerLabel()]));
                return; // failLogin beendet den Request
            }
            // Token-Endpunkt für den Callback festhalten - er stammt aus dem
            // issuer-geprüften Discovery-Dokument, nicht aus Benutzereingaben.
            $_SESSION['entra_sso_state'] = $state;
            $_SESSION['entra_sso_token_endpoint'] = $endpoints['token_endpoint'];

            // Endpunkte dürfen bereits Query-Parameter tragen.
            $authorizeUrl = $endpoints['authorization_endpoint']
                . (str_contains($endpoints['authorization_endpoint'], '?') ? '&' : '?')
                . http_build_query($params);
            header('Location: ' . $authorizeUrl);
            exit;
        }

        // ENTRA-Modus: feste Microsoft-Endpunkte, unverändertes Verhalten.
        $_SESSION['entra_sso_state'] = $state;
        $params['response_mode'] = 'query';
        $tenant = rawurlencode(self::config('ENTRA_TENANT_ID'));
        header('Location: ' . self::AUTHORITY_BASE . "/{$tenant}/oauth2/v2.0/authorize?" . http_build_query($params));
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

        $providerLabel = self::providerLabel();

        $expectedState = (string)($_SESSION['entra_sso_state'] ?? '');
        $sessionTokenEndpoint = (string)($_SESSION['entra_sso_token_endpoint'] ?? '');
        unset($_SESSION['entra_sso_state'], $_SESSION['entra_sso_token_endpoint']);
        $state = (string)($_GET['state'] ?? '');
        if ($expectedState === '' || $state === '' || !hash_equals($expectedState, $state)) {
            $this->failLogin('Ungültiger oder abgelaufener SSO-Anmeldeversuch (state). Bitte erneut versuchen.');
        }

        if (isset($_GET['error'])) {
            $this->failLogin(\App\I18n\Translator::t('auth.sso_cancelled', ['provider' => $providerLabel]) . ' ' . (string)$_GET['error']);
        }

        $code = (string)($_GET['code'] ?? '');
        if ($code === '') {
            $this->failLogin(\App\I18n\Translator::t('auth.sso_no_code', ['provider' => $providerLabel]));
        }

        if (self::isGenericMode()) {
            // Fail-closed: Ohne den beim Redirect hinterlegten Token-Endpunkt
            // (abgelaufene/fremde Session) wird nichts geraten.
            if ($sessionTokenEndpoint === '') {
                $this->failLogin('Ungültiger oder abgelaufener SSO-Anmeldeversuch (Sitzung). Bitte erneut versuchen.');
            }
            $tokenUrl = $sessionTokenEndpoint;
            $expectedIssuer = self::config('OIDC_ISSUER_URL');
        } else {
            $tenant = rawurlencode(self::config('ENTRA_TENANT_ID'));
            $tokenUrl = self::AUTHORITY_BASE . "/{$tenant}/oauth2/v2.0/token";
            $expectedIssuer = self::AUTHORITY_BASE . '/' . self::config('ENTRA_TENANT_ID') . '/v2.0';
        }

        try {
            $idToken = $this->exchangeCodeForIdToken($code, $tokenUrl);
            $claims = OidcIdToken::parseAndValidate($idToken, self::clientId(), $expectedIssuer);
        } catch (\Throwable $e) {
            \App\Service\AuditLogger::log('SSO-Login fehlgeschlagen', 'auth', $e->getMessage());
            $this->failLogin(\App\I18n\Translator::t('auth.sso_failed', ['provider' => $providerLabel]));
            return; // failLogin beendet den Request, return nur für die statische Analyse
        }

        $email = OidcIdToken::extractEmail($claims);
        if ($email === null) {
            $this->failLogin(\App\I18n\Translator::t('auth.sso_no_email', ['provider' => $providerLabel]));
        }

        // Kein Auto-Provisioning: nur bestehende, aktive lokale Konten.
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT id, email_verification_token FROM users WHERE email = ? AND deleted_at IS NULL");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            \App\Service\AuditLogger::log('SSO-Login abgewiesen', 'auth', "Kein lokales Konto für {$email}");
            $this->failLogin(\App\I18n\Translator::t('auth.sso_no_account', ['provider' => $providerLabel]));
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
    private function exchangeCodeForIdToken(string $code, string $tokenUrl): string {
        $ch = curl_init($tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => self::clientId(),
                'client_secret' => self::clientSecret(),
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri(),
                'scope' => 'openid profile email',
            ]),
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

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
