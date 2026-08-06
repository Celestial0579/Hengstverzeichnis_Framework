<?php
// src/Controllers/AuthController.php

namespace App\Controllers;

use App\Database;
use App\Security\Totp;

class AuthController extends BaseController {

    public function loginForm(): void {
        if (isset($_SESSION['user_id'])) {
            header("Location: /admin");
            exit;
        }

        $this->render('login', ['title' => 'Login - ' . ($this->settings['site_name'] ?? 'Hengstverzeichnis')]);
    }

    public function loginSubmit(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden(\App\I18n\Translator::t('errors.csrf_invalid'));
        }

        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';

        // Zwei getrennte Zähler (Issue #115): Der Konto-Zähler ist an die
        // Client-IP gekoppelt (email|ip), damit ein Angreifer mit gezielten
        // Fehlversuchen nicht beliebige bekannte E-Mail-Adressen global
        // aussperren kann (Account-Lockout-DoS). Der zusätzliche reine
        // IP-Zähler (höheres Limit) bremst Passwort-Spraying über viele
        // Konten von derselben Adresse. Beide Zähler bleiben durch den
        // fail-open-Charakter des RateLimiters bei DB-Fehlern ausfallsicher.
        $clientIp = \App\Security\ClientIp::resolve();
        $accountIdentifier = $email . '|' . $clientIp;

        if (
            \App\Security\RateLimiter::tooManyAttempts($accountIdentifier, 'login')
            || \App\Security\RateLimiter::tooManyAttempts($clientIp, 'login_ip', 20)
        ) {
            $this->render('login', [
                'title' => \App\I18n\Translator::t('meta.title_login_failed'),
                'error' => \App\I18n\Translator::t('auth.rate_limited_login')
            ]);
            return;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT id, password_hash, totp_enabled, totp_secret, email_verification_token FROM users WHERE email = ? AND deleted_at IS NULL");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            // Selfservice-Registrierung (#83): Ein gesetzter Verifizierungs-
            // Token bedeutet, dass die E-Mail-Adresse noch nicht bestätigt
            // wurde - der Login bleibt bis dahin gesperrt (erst NACH der
            // Passwortprüfung gemeldet, damit die Meldung nichts über fremde
            // Konten verrät).
            if (!empty($user['email_verification_token'])) {
                $this->render('login', [
                    'title' => \App\I18n\Translator::t('meta.title_login_failed'),
                    'error' => \App\I18n\Translator::t('auth.email_not_verified')
                ]);
                return;
            }
            // Nur den eigenen Konto-Zähler (email|ip) zurücksetzen - der reine
            // IP-Zähler bleibt bestehen, damit ein erfolgreicher Login nicht
            // die Spuren von Spraying-Versuchen gegen andere Konten löscht.
            \App\Security\RateLimiter::clearAttempts($accountIdentifier, 'login');

            if ($user['totp_enabled']) {
                // Prompt for 2FA Code
                $_SESSION['pending_2fa_user_id'] = $user['id'];
                header("Location: /login/2fa");
                exit;
            }

            // 2FA-Pflicht pro Gruppe (#84): Nur wenn mindestens eine Gruppe des
            // Benutzers (oder die fest verdrahtete Admin-Pflicht) 2FA verlangt,
            // wird das Setup erzwungen - sonst ist der Login hier abgeschlossen.
            // Kein Bestandsschutz: Wird die Pflicht später aktiviert, greift sie
            // automatisch beim nächsten Login.
            if ($this->userRequires2fa((int)$user['id'])) {
                $_SESSION['pending_2fa_user_id'] = $user['id'];
                header("Location: /2fa/setup");
                exit;
            }

            $this->completeLogin((int)$user['id']);
        }

        \App\Security\RateLimiter::recordAttempt($accountIdentifier, 'login');
        \App\Security\RateLimiter::recordAttempt($clientIp, 'login_ip');

        $this->render('login', [
            'title' => \App\I18n\Translator::t('meta.title_login_failed'),
            'error' => \App\I18n\Translator::t('auth.invalid_credentials')
        ]);
    }

    /**
     * Prüft, ob für den Benutzer TOTP-2FA verpflichtend ist (#84): fest
     * verdrahtet für Mitglieder der Gruppe `admin` (unabhängig von deren
     * require_2fa-Spalte), sonst sobald mindestens eine seiner Gruppen
     * `require_2fa = 1` gesetzt hat. Fail-safe: Benutzer ohne Gruppen sowie
     * DB-Fehler führen zu "verpflichtend" (Status quo vor #84), nie zu einem
     * stillen Entfall der Pflicht.
     */
    private function userRequires2fa(int $userId): bool {
        try {
            $db = Database::getInstance();

            $stmt = $db->prepare("SELECT COUNT(*) FROM user_groups WHERE user_id = ?");
            $stmt->execute([$userId]);
            if ((int)$stmt->fetchColumn() === 0) {
                return true;
            }

            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM user_groups ug
                 JOIN `groups` g ON g.id = ug.group_id
                 WHERE ug.user_id = ? AND (g.slug = 'admin' OR g.require_2fa = 1)"
            );
            $stmt->execute([$userId]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Gültigkeitsdauer einer Step-up-Reauthentifizierung für die 2FA-
     * (Neu-)Einrichtung (#112) in Sekunden.
     */
    private const TWOFA_REAUTH_TTL = 600;

    private function hasFresh2faReauth(): bool {
        return isset($_SESSION['twofa_reauth_at'])
            && (time() - (int)$_SESSION['twofa_reauth_at']) <= self::TWOFA_REAUTH_TTL;
    }

    public function show2faSetup(): void {
        if (!isset($_SESSION['pending_2fa_user_id']) && !isset($_SESSION['user_id'])) {
            header("Location: /login");
            exit;
        }

        $userId = $_SESSION['pending_2fa_user_id'] ?? $_SESSION['user_id'];
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT email, totp_enabled FROM users WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            header("Location: /login");
            exit;
        }

        // Step-up-Reauth (#112): Ist 2FA bereits aktiv, darf eine bestehende
        // Session die Konfiguration (neues Secret + neue Backup-Codes) nur nach
        // erneuter Bestätigung von Passwort UND aktuellem TOTP-Code ändern -
        // sonst könnte ein Angreifer mit übernommener Session (z. B.
        // unbeaufsichtigter Arbeitsplatz) die 2FA dauerhaft an sich binden.
        if ((int)$user['totp_enabled'] === 1) {
            if (!isset($_SESSION['user_id'])) {
                // Pending-Session hat nur das Passwort bewiesen - für Konten
                // mit aktiver 2FA führt der Weg ausschließlich über /login/2fa.
                header("Location: /login/2fa");
                exit;
            }
            if (!$this->hasFresh2faReauth()) {
                $this->render('2fa_reauth', ['title' => '2FA-Änderung bestätigen']);
                return;
            }
        }

        // Secret und Backup-Codes entstehen ausschließlich serverseitig und
        // werden bis zur Bestätigung in der Session gehalten (#112) - der
        // Client kann sie anzeigen, aber nicht per POST eigene Werte vorgeben.
        $secret = Totp::generateSecret();
        $backupCodes = Totp::generateBackupCodes(10);
        $_SESSION['totp_setup'] = ['secret' => $secret, 'backup_codes' => $backupCodes];

        $siteName = $this->settings['site_name'] ?? 'Hengstverzeichnis';
        $otpAuthUrl = Totp::getOtpAuthUrl($user['email'], $siteName, $secret);

        $this->render('2fa_setup', [
            'title' => '2FA Einrichtung',
            'secret' => $secret,
            'otpAuthUrl' => $otpAuthUrl,
            'backupCodes' => $backupCodes
        ]);
    }

    /**
     * Step-up-Reauthentifizierung vor einer 2FA-Neukonfiguration (#112):
     * verlangt das aktuelle Passwort UND einen aktuellen TOTP-Code der
     * bestehenden 2FA. Erfolg wird zeitlich begrenzt in der Session vermerkt
     * (siehe hasFresh2faReauth()).
     */
    public function process2faReauth(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            header("Location: /login");
            exit;
        }

        if (\App\Security\RateLimiter::tooManyAttempts((string)$userId, '2fa')) {
            $this->render('2fa_reauth', [
                'title' => '2FA-Änderung bestätigen',
                'error' => \App\I18n\Translator::t('auth.rate_limited_2fa')
            ]);
            return;
        }

        $password = $_POST['password'] ?? '';
        $code = trim($_POST['totp_code'] ?? '');

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT password_hash, totp_secret, last_totp_timeslice FROM users WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if ($user && !empty($user['totp_secret']) && password_verify($password, $user['password_hash'])) {
            $decryptedSecret = \App\Security\Crypto::decrypt($user['totp_secret']) ?? $user['totp_secret'];
            $lastSlice = $user['last_totp_timeslice'] !== null ? (int)$user['last_totp_timeslice'] : null;
            $matchedSlice = Totp::verifyCodeReturnSlice($decryptedSecret, $code, $lastSlice);

            if ($matchedSlice !== null) {
                $update = $db->prepare("UPDATE users SET last_totp_timeslice = ? WHERE id = ?");
                $update->execute([$matchedSlice, $userId]);
                \App\Security\RateLimiter::clearAttempts((string)$userId, '2fa');

                $_SESSION['twofa_reauth_at'] = time();

                \App\Service\AuditLogger::log("2FA-Neukonfiguration freigeschaltet", "auth", "Step-up-Reauth erfolgreich", (int)$userId, $_SESSION['username'] ?? null);

                header("Location: /2fa/setup");
                exit;
            }
        }

        \App\Security\RateLimiter::recordAttempt((string)$userId, '2fa');

        $this->render('2fa_reauth', [
            'title' => '2FA-Änderung bestätigen',
            'error' => 'Passwort oder 6-stelliger Code ungültig. Bitte versuchen Sie es erneut.'
        ]);
    }

    public function enable2fa(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $userId = $_SESSION['pending_2fa_user_id'] ?? $_SESSION['user_id'] ?? null;
        if (!$userId) {
            header("Location: /login");
            exit;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT email, totp_enabled FROM users WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$userId]);
        $dbUser = $stmt->fetch();
        if (!$dbUser) {
            header("Location: /login");
            exit;
        }

        // Step-up-Reauth (#112): Bei bereits aktiver 2FA muss die Session die
        // Neukonfiguration zuvor über /2fa/reauth freigeschaltet haben - die
        // Prüfung aus show2faSetup() wird hier serverseitig wiederholt, damit
        // ein direkter POST sie nicht umgehen kann.
        if ((int)$dbUser['totp_enabled'] === 1) {
            if (!isset($_SESSION['user_id'])) {
                header("Location: /login/2fa");
                exit;
            }
            if (!$this->hasFresh2faReauth()) {
                $this->renderForbidden("Für die Änderung der 2FA-Konfiguration ist eine erneute Bestätigung mit Passwort und aktuellem Code erforderlich.");
            }
        }

        // Secret/Backup-Codes stammen ausschließlich aus dem Server-State der
        // Session (#112, siehe show2faSetup()) - POST-Werte werden ignoriert.
        $setup = $_SESSION['totp_setup'] ?? null;
        if (!is_array($setup) || empty($setup['secret']) || empty($setup['backup_codes'])) {
            header("Location: /2fa/setup");
            exit;
        }
        $secret = (string)$setup['secret'];
        $backupCodesRaw = (array)$setup['backup_codes'];

        if (empty($_POST['confirm_backup'])) {
            die("Sie müssen bestätigen, dass Sie Ihre 10 Backup-Codes gespeichert haben.");
        }

        $code = trim($_POST['totp_code'] ?? '');

        // Replay-Schutz (#111): Auch der Bestätigungscode der Einrichtung
        // verbraucht seinen Zeitschlitz (frisches Secret, daher ohne Vorwert).
        $matchedSlice = Totp::verifyCodeReturnSlice($secret, $code, null);
        if ($matchedSlice === null) {
            $siteName = $this->settings['site_name'] ?? 'Hengstverzeichnis';

            $this->render('2fa_setup', [
                'title' => '2FA Einrichtung',
                'secret' => $secret,
                'otpAuthUrl' => Totp::getOtpAuthUrl($dbUser['email'], $siteName, $secret),
                'backupCodes' => $backupCodesRaw,
                'error' => 'Ungültiger 6-stelliger Code. Bitte versuchen Sie es erneut.'
            ]);
            return;
        }

        // Hash backup codes before saving
        $hashedBackupCodes = array_map(function($c) {
            return password_hash(str_replace('-', '', strtoupper($c)), PASSWORD_DEFAULT);
        }, $backupCodesRaw);

        // Encrypt TOTP Secret at rest using AES-256-GCM
        $encryptedSecret = \App\Security\Crypto::encrypt($secret);

        $stmt = $db->prepare("UPDATE users SET totp_secret = ?, totp_enabled = 1, backup_codes = ?, last_totp_timeslice = ? WHERE id = ?");
        $stmt->execute([$encryptedSecret, json_encode($hashedBackupCodes), $matchedSlice, $userId]);

        // Server-State der Einrichtung und Reauth-Freischaltung verbrauchen.
        unset($_SESSION['totp_setup'], $_SESSION['twofa_reauth_at']);

        $this->completeLogin($userId, '/admin?2fa=enabled');
    }

    public function show2faVerify(): void {
        if (!isset($_SESSION['pending_2fa_user_id'])) {
            header("Location: /login");
            exit;
        }

        $this->render('2fa_verify', ['title' => \App\I18n\Translator::t('meta.title_2fa_confirm')]);
    }

    public function process2faVerify(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden(\App\I18n\Translator::t('errors.csrf_invalid'));
        }

        $userId = $_SESSION['pending_2fa_user_id'] ?? null;
        if (!$userId) {
            header("Location: /login");
            exit;
        }

        $code = trim($_POST['totp_code'] ?? '');

        if (\App\Security\RateLimiter::tooManyAttempts((string)$userId, '2fa')) {
            $this->render('2fa_verify', [
                'title' => \App\I18n\Translator::t('meta.title_2fa_confirm'),
                'error' => \App\I18n\Translator::t('auth.rate_limited_2fa')
            ]);
            return;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT totp_secret, last_totp_timeslice FROM users WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if ($user && !empty($user['totp_secret'])) {
            $decryptedSecret = \App\Security\Crypto::decrypt($user['totp_secret']);
            // Fallback for unencrypted secrets if any existed previously
            if ($decryptedSecret === null) {
                $decryptedSecret = $user['totp_secret'];
            }

            // Replay-Schutz (#111): Codes sind single-use - der getroffene
            // Zeitschlitz wird persistiert, bereits verbrauchte Schlitze lehnt
            // verifyCodeReturnSlice() auch bei korrektem Code ab.
            $lastSlice = $user['last_totp_timeslice'] !== null ? (int)$user['last_totp_timeslice'] : null;
            $matchedSlice = Totp::verifyCodeReturnSlice($decryptedSecret, $code, $lastSlice);
            if ($matchedSlice !== null) {
                $update = $db->prepare("UPDATE users SET last_totp_timeslice = ? WHERE id = ?");
                $update->execute([$matchedSlice, $userId]);

                \App\Security\RateLimiter::clearAttempts((string)$userId, '2fa');
                $this->completeLogin($userId, '/admin');
            }
        }

        \App\Security\RateLimiter::recordAttempt((string)$userId, '2fa');

        $this->render('2fa_verify', [
            'title' => \App\I18n\Translator::t('meta.title_2fa_confirm'),
            'error' => \App\I18n\Translator::t('auth.invalid_2fa_code')
        ]);
    }

    public function showBackupCode(): void {
        if (!isset($_SESSION['pending_2fa_user_id'])) {
            header("Location: /login");
            exit;
        }

        $this->render('2fa_backup', ['title' => 'Backup-Code verwenden']);
    }

    public function processBackupCode(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $userId = $_SESSION['pending_2fa_user_id'] ?? null;
        if (!$userId) {
            header("Location: /login");
            exit;
        }

        $inputCode = strtoupper(str_replace(['-', ' '], '', trim($_POST['backup_code'] ?? '')));

        if (\App\Security\RateLimiter::tooManyAttempts((string)$userId, 'backup')) {
            $this->render('2fa_backup', [
                'title' => 'Backup-Code verwenden',
                'error' => 'Zu viele fehlgeschlagene Versuche. Bitte versuchen Sie es in 15 Minuten erneut.'
            ]);
            return;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT backup_codes FROM users WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            // Account wurde vermutlich zwischen 2FA-Pending und Backup-Code-Eingabe gelöscht
            unset($_SESSION['pending_2fa_user_id']);
            header("Location: /login");
            exit;
        }

        $backupCodes = json_decode($user['backup_codes'] ?? '[]', true);
        $matchedKey = null;

        foreach ($backupCodes as $key => $hashedCode) {
            if (password_verify($inputCode, $hashedCode)) {
                $matchedKey = $key;
                break;
            }
        }

        if ($matchedKey !== null) {
            \App\Security\RateLimiter::clearAttempts((string)$userId, 'backup');

            // Remove used backup code
            unset($backupCodes[$matchedKey]);
            $updatedCodes = array_values($backupCodes);

            $stmt = $db->prepare("UPDATE users SET backup_codes = ? WHERE id = ?");
            $stmt->execute([json_encode($updatedCodes), $userId]);

            $this->completeLogin($userId, '/admin?backup_code_used=1');
        }

        \App\Security\RateLimiter::recordAttempt((string)$userId, 'backup');

        $this->render('2fa_backup', [
            'title' => 'Backup-Code verwenden',
            'error' => 'Ungültiger oder bereits verwendeter Backup-Code.'
        ]);
    }

    public function logout(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }
        \App\Service\AuditLogger::log("Benutzer ausgeloggt", "auth", "Erfolgreich abgemeldet");
        session_destroy();
        header("Location: /");
        exit;
    }

    public function forgotPassword(): void {
        $this->render('auth_forgot_password', ['title' => \App\I18n\Translator::t('meta.title_forgot_password')]);
    }

    public function sendResetLink(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden(\App\I18n\Translator::t('errors.csrf_invalid'));
        }

        // Nach Absender-IP begrenzen (nicht nach E-Mail): Ohne diese Sperre könnte jeder
        // Client unbegrenzt oft echten SMTP-Versand auslösen (E-Mail-Bombing eines
        // beliebigen Opfers, Missbrauch/Reputationsschaden des SMTP-Relays) - unabhängig
        // davon, ob die eingegebene E-Mail-Adresse überhaupt existiert.
        $clientIp = \App\Security\ClientIp::resolve();
        if (\App\Security\RateLimiter::tooManyAttempts($clientIp, 'password_reset')) {
            $this->render('auth_forgot_password', [
                'title' => \App\I18n\Translator::t('meta.title_forgot_password'),
                'error' => \App\I18n\Translator::t('auth.rate_limited_password_reset')
            ]);
            return;
        }
        \App\Security\RateLimiter::recordAttempt($clientIp, 'password_reset');

        $email = trim($_POST['email'] ?? '');
        if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $db = Database::getInstance();
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                // Generate secure random token
                $token = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));

                // Delete older resets for this email
                $stmt = $db->prepare("DELETE FROM password_resets WHERE email = ?");
                $stmt->execute([$email]);

                $stmt = $db->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
                $stmt->execute([$email, $token, $expiresAt]);

                $mailer = new \App\Service\Mailer();
                $mailer->sendPasswordResetEmail($email, $token);
            }
        }

        // Always show success message to prevent user enumeration
        header("Location: /forgot-password?sent=1");
        exit;
    }

    public function resetPassword(): void {
        $token = trim($_GET['token'] ?? '');
        if (empty($token)) {
            header("Location: /forgot-password");
            exit;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM password_resets WHERE token = ? AND expires_at > NOW()");
        $stmt->execute([$token]);
        $reset = $stmt->fetch();

        if (!$reset) {
            $this->render('auth_forgot_password', [
                'title' => \App\I18n\Translator::t('meta.title_forgot_password'),
                'error' => \App\I18n\Translator::t('auth.reset_link_invalid')
            ]);
            return;
        }

        $this->render('auth_reset_password', [
            'title' => \App\I18n\Translator::t('meta.title_reset_password'),
            'token' => $token
        ]);
    }

    public function updatePassword(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden(\App\I18n\Translator::t('errors.csrf_invalid'));
        }

        $token = trim($_POST['token'] ?? '');
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';

        if (empty($token) || strlen($password) < 8 || $password !== $passwordConfirm) {
            $this->render('auth_reset_password', [
                'title' => \App\I18n\Translator::t('meta.title_reset_password'),
                'token' => $token,
                'error' => \App\I18n\Translator::t('auth.passwords_mismatch_short')
            ]);
            return;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM password_resets WHERE token = ? AND expires_at > NOW()");
        $stmt->execute([$token]);
        $reset = $stmt->fetch();

        if (!$reset) {
            header("Location: /forgot-password");
            exit;
        }

        $newPasswordHash = password_hash($password, PASSWORD_DEFAULT);

        // Update user's password hash. session_version wird erhöht, damit alle
        // bestehenden Sessions dieses Benutzers sofort ungültig werden (#113) -
        // gerade der Passwort-Reset ist die typische Reaktion auf einen
        // Kompromittierungsverdacht.
        $stmt = $db->prepare("UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE email = ?");
        $stmt->execute([$newPasswordHash, $reset['email']]);

        // Consume reset token
        $stmt = $db->prepare("DELETE FROM password_resets WHERE email = ?");
        $stmt->execute([$reset['email']]);

        // Ensure no auto-login occurs: User MUST authenticate via normal login + 2FA
        unset($_SESSION['user_id'], $_SESSION['pending_2fa_user_id'], $_SESSION['must_change_password']);

        header("Location: /login?success=password_reset");
        exit;
    }

    private function completeLogin(int $userId, string $redirectSuccess = '/admin'): void {
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

        \App\Service\AuditLogger::log("Benutzer eingeloggt", "auth", "Erfolgreich angemeldet", $userId, $username);

        if ($mustChange === 1) {
            $_SESSION['must_change_password'] = 1;
            header("Location: /force-password-change");
            exit;
        }

        unset($_SESSION['must_change_password']);
        header("Location: " . $redirectSuccess);
        exit;
    }

    public function showForcePasswordChange(): void {
        if (!isset($_SESSION['user_id'])) {
            header("Location: /login");
            exit;
        }

        $this->render('auth_force_password_change', [
            'title' => 'Erstmals Passwort ändern'
        ]);
    }

    public function processForcePasswordChange(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            header("Location: /login");
            exit;
        }

        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';

        if (strlen($password) < 8 || $password !== $passwordConfirm) {
            $this->render('auth_force_password_change', [
                'title' => 'Erstmals Passwort ändern',
                'error' => 'Die Passwörter stimmen nicht überein oder sind zu kurz (mindestens 8 Zeichen).'
            ]);
            return;
        }

        $db = Database::getInstance();
        $hash = password_hash($password, PASSWORD_DEFAULT);
        // session_version erhöhen, damit andere bestehende Sessions dieses
        // Benutzers ungültig werden (#113) - die eigene, gerade aktive Session
        // übernimmt den neuen Stand direkt und bleibt angemeldet.
        $stmt = $db->prepare("UPDATE users SET password_hash = ?, must_change_password = 0, session_version = session_version + 1 WHERE id = ?");
        $stmt->execute([$hash, $userId]);

        $stmt = $db->prepare("SELECT session_version FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $_SESSION['session_version'] = (int)$stmt->fetchColumn();

        unset($_SESSION['must_change_password']);

        header("Location: /admin?password_changed=1");
        exit;
    }
}
