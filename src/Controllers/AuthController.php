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

        if (\App\Security\RateLimiter::tooManyAttempts($email, 'login')) {
            $this->render('login', [
                'title' => \App\I18n\Translator::t('meta.title_login_failed'),
                'error' => \App\I18n\Translator::t('auth.rate_limited_login')
            ]);
            return;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT id, password_hash, totp_enabled, totp_secret FROM users WHERE email = ? AND deleted_at IS NULL");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            \App\Security\RateLimiter::clearAttempts($email, 'login');

            $_SESSION['pending_2fa_user_id'] = $user['id'];

            if (!$user['totp_enabled']) {
                // Mandatory 2FA Setup
                header("Location: /2fa/setup");
                exit;
            } else {
                // Prompt for 2FA Code
                header("Location: /login/2fa");
                exit;
            }
        }

        \App\Security\RateLimiter::recordAttempt($email, 'login');

        $this->render('login', [
            'title' => \App\I18n\Translator::t('meta.title_login_failed'),
            'error' => \App\I18n\Translator::t('auth.invalid_credentials')
        ]);
    }

    public function show2faSetup(): void {
        if (!isset($_SESSION['pending_2fa_user_id']) && !isset($_SESSION['user_id'])) {
            header("Location: /login");
            exit;
        }

        $userId = $_SESSION['pending_2fa_user_id'] ?? $_SESSION['user_id'];
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT email FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        $secret = Totp::generateSecret();
        $siteName = $this->settings['site_name'] ?? 'Hengstverzeichnis';
        $otpAuthUrl = Totp::getOtpAuthUrl($user['email'], $siteName, $secret);
        $backupCodes = Totp::generateBackupCodes(10);

        $this->render('2fa_setup', [
            'title' => '2FA Einrichtung',
            'secret' => $secret,
            'otpAuthUrl' => $otpAuthUrl,
            'backupCodes' => $backupCodes
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

        if (empty($_POST['confirm_backup'])) {
            die("Sie müssen bestätigen, dass Sie Ihre 10 Backup-Codes gespeichert haben.");
        }

        $secret = trim($_POST['totp_secret'] ?? '');
        $code = trim($_POST['totp_code'] ?? '');
        $backupCodesRaw = json_decode($_POST['backup_codes'] ?? '[]', true);

        $usedSlice = Totp::verifyCodeReturnSlice($secret, $code);
        if ($usedSlice === null) {
            $siteName = $this->settings['site_name'] ?? 'Hengstverzeichnis';
            $db = Database::getInstance();
            $stmt = $db->prepare("SELECT email FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            $this->render('2fa_setup', [
                'title' => '2FA Einrichtung',
                'secret' => $secret,
                'otpAuthUrl' => Totp::getOtpAuthUrl($user['email'], $siteName, $secret),
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

        // Den bei der Einrichtung verbrauchten Zeitschlitz gleich festhalten, damit
        // derselbe Bestätigungscode nicht unmittelbar danach für den Login
        // wiederverwendet werden kann (Replay-Schutz, siehe process2faVerify()).
        $db = Database::getInstance();
        $stmt = $db->prepare("UPDATE users SET totp_secret = ?, totp_enabled = 1, backup_codes = ?, last_totp_timeslice = ? WHERE id = ?");
        $stmt->execute([$encryptedSecret, json_encode($hashedBackupCodes), $usedSlice, $userId]);

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

            // Replay-Schutz: ein bereits verwendeter (oder älterer) TOTP-Zeitschlitz
            // wird abgelehnt, damit ein abgefangener gültiger Code nicht innerhalb
            // seines Toleranzfensters ein zweites Mal zum Login genutzt werden kann.
            $minSlice = isset($user['last_totp_timeslice']) && $user['last_totp_timeslice'] !== null
                ? (int)$user['last_totp_timeslice'] : null;
            $usedSlice = Totp::verifyCodeReturnSlice($decryptedSecret, $code, $minSlice);

            if ($usedSlice !== null) {
                // Verbrauchten Zeitschlitz festhalten, bevor die Session steht.
                $updateStmt = $db->prepare("UPDATE users SET last_totp_timeslice = ? WHERE id = ?");
                $updateStmt->execute([$usedSlice, $userId]);

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

        // Update user's password hash
        $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
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
        $stmt = $db->prepare("SELECT username, must_change_password FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $userRow = $stmt->fetch();

        $mustChange = (int)($userRow['must_change_password'] ?? 0);
        $username = $userRow['username'] ?? 'Unbekannt';

        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $username;
        $_SESSION['user_agent_hash'] = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
        $_SESSION['last_activity'] = time();
        $_SESSION['created_time'] = time();

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
        $stmt = $db->prepare("UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?");
        $stmt->execute([$hash, $userId]);

        unset($_SESSION['must_change_password']);

        header("Location: /admin?password_changed=1");
        exit;
    }
}
