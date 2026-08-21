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

        // Am Rand trimmen, nicht erst im Zähler: Die Adresse geht sowohl in
        // die Benutzersuche als auch in den Bezeichner des Rate-Limiters, und
        // ein angehängtes Leerzeichen darf nicht zwei verschiedene Dinge
        // bedeuten (siehe App\Security\RateLimiter::normalizeIdentifier()).
        $email = trim((string)($_POST['email'] ?? ''));
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
        $stmt = $db->prepare("SELECT id, password_hash, totp_enabled, totp_secret, email_verification_token FROM users WHERE email = ? AND deleted_at IS NULL AND deactivated_at IS NULL");
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

            // Ein neuer Login löst jede bestehende Anmeldung dieser Sitzung ab.
            // Ohne das laufen zwei Identitäten nebeneinander: die alte in
            // `user_id`, die neue in `pending_2fa_user_id` - und alles, was
            // zwischen Faktor 1 und Faktor 2 passiert, kann die Nachweise der
            // einen für das Konto der anderen verwenden.
            $this->discardExistingSessionState();

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
     * Räumt Anmelde- und Step-up-Zustand einer vorherigen Identität aus der
     * Session, sobald ein neuer Faktor-1-Nachweis erbracht wurde. Das
     * CSRF-Token bleibt bewusst erhalten - es gehört zur Sitzung, nicht zur
     * Identität, und der Login-Vorgang ist an dieser Stelle bereits geprüft.
     */
    private function discardExistingSessionState(): void {
        unset(
            $_SESSION['user_id'],
            $_SESSION['username'],
            $_SESSION['user_agent_hash'],
            $_SESSION['session_version'],
            $_SESSION['pending_2fa_user_id'],
            $_SESSION['twofa_reauth'],
            $_SESSION['totp_setup'],
            $_SESSION['must_change_password'],
            $_SESSION['last_activity'],
            $_SESSION['last_token_rotation'],
            $_SESSION['created_time']
        );
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

    /**
     * Konto, dessen 2FA gerade eingerichtet oder neu konfiguriert wird.
     *
     * Zwei Session-Werte können ein Konto benennen, und sie können auf
     * VERSCHIEDENE Konten zeigen: `pending_2fa_user_id` ist der Nachweis von
     * Faktor 1 aus dem gerade laufenden Login, `user_id` eine bereits
     * bestehende Anmeldung. Wer beides gleichzeitig hält, richtet sonst die
     * 2FA des einen Kontos mit dem Nachweis des anderen ein. Deshalb gibt es
     * für die Frage "welches Konto?" genau diese eine Antwortstelle, und jede
     * Prüfung weiter unten vergleicht ausdrücklich gegen ihr Ergebnis.
     */
    private function twofaTargetUserId(): ?int {
        $target = $_SESSION['pending_2fa_user_id'] ?? $_SESSION['user_id'] ?? null;
        return $target === null ? null : (int)$target;
    }

    /**
     * Liegt für GENAU dieses Konto eine frische Step-up-Freigabe vor?
     *
     * Der Zeitstempel allein reicht nicht: Er entsteht in process2faReauth()
     * aus Passwort und TOTP-Code des dort angemeldeten Benutzers und sagt
     * nichts darüber aus, für welches Konto er gilt. Ohne den Abgleich
     * bezahlt der Nachweis des einen Kontos die Neukonfiguration eines
     * anderen.
     */
    private function hasFresh2faReauth(int $userId): bool {
        $reauth = $_SESSION['twofa_reauth'] ?? null;
        if (!is_array($reauth) || !isset($reauth['user_id'], $reauth['at'])) {
            return false;
        }
        if ((int)$reauth['user_id'] !== $userId) {
            return false;
        }
        return (time() - (int)$reauth['at']) <= self::TWOFA_REAUTH_TTL;
    }

    public function show2faSetup(): void {
        $userId = $this->twofaTargetUserId();
        if ($userId === null) {
            header("Location: /login");
            exit;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT email, totp_enabled FROM users WHERE id = ? AND deleted_at IS NULL AND deactivated_at IS NULL");
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
            // Die Neukonfiguration darf nur die eigene, angemeldete Sitzung
            // dieses Kontos anstoßen. Eine Sitzung, die als jemand anderes
            // angemeldet ist, zählt hier ausdrücklich NICHT als Nachweis -
            // sonst genügte das Passwort des Opfers, um mit dem eigenen
            // Step-up dessen Secret zu überschreiben.
            if ((int)($_SESSION['user_id'] ?? 0) !== $userId) {
                // Pending-Session hat nur das Passwort bewiesen - für Konten
                // mit aktiver 2FA führt der Weg ausschließlich über /login/2fa.
                header("Location: /login/2fa");
                exit;
            }
            if (!$this->hasFresh2faReauth($userId)) {
                $this->render('2fa_reauth', ['title' => '2FA-Änderung bestätigen']);
                return;
            }
        }

        // Secret und Backup-Codes entstehen ausschließlich serverseitig und
        // werden bis zur Bestätigung in der Session gehalten (#112) - der
        // Client kann sie anzeigen, aber nicht per POST eigene Werte vorgeben.
        // Mit der Konto-ID daneben, damit ein in der Sitzung liegendes Secret
        // nicht auf ein anderes Konto angewendet werden kann.
        $secret = Totp::generateSecret();
        $backupCodes = Totp::generateBackupCodes(10);
        $_SESSION['totp_setup'] = ['user_id' => $userId, 'secret' => $secret, 'backup_codes' => $backupCodes];

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
        $stmt = $db->prepare("SELECT password_hash, totp_secret, last_totp_timeslice FROM users WHERE id = ? AND deleted_at IS NULL AND deactivated_at IS NULL");
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

                // Mit der Konto-ID, nicht als blanker Zeitstempel: Der
                // Nachweis gilt für dieses Konto und für kein anderes
                // (siehe hasFresh2faReauth()).
                $_SESSION['twofa_reauth'] = ['user_id' => (int)$userId, 'at' => time()];

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

        $userId = $this->twofaTargetUserId();
        if ($userId === null) {
            header("Location: /login");
            exit;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT email, totp_enabled FROM users WHERE id = ? AND deleted_at IS NULL AND deactivated_at IS NULL");
        $stmt->execute([$userId]);
        $dbUser = $stmt->fetch();
        if (!$dbUser) {
            header("Location: /login");
            exit;
        }

        // Step-up-Reauth (#112): Bei bereits aktiver 2FA muss die Session die
        // Neukonfiguration zuvor über /2fa/reauth freigeschaltet haben - die
        // Prüfung aus show2faSetup() wird hier serverseitig wiederholt, damit
        // ein direkter POST sie nicht umgehen kann. Wortgleich, weil beide
        // Wege für sich allein tragen müssen: /2fa/setup gibt das neue Secret
        // bereits aus, ein Fix nur hier käme zu spät.
        if ((int)$dbUser['totp_enabled'] === 1) {
            if ((int)($_SESSION['user_id'] ?? 0) !== $userId) {
                header("Location: /login/2fa");
                exit;
            }
            if (!$this->hasFresh2faReauth($userId)) {
                $this->renderForbidden("Für die Änderung der 2FA-Konfiguration ist eine erneute Bestätigung mit Passwort und aktuellem Code erforderlich.");
            }
        }

        // Secret/Backup-Codes stammen ausschließlich aus dem Server-State der
        // Session (#112, siehe show2faSetup()) - POST-Werte werden ignoriert.
        // Der hinterlegte Satz gilt nur für das Konto, für das er erzeugt
        // wurde: Sonst ließe sich ein in der eigenen Sitzung erzeugtes Secret
        // auf ein fremdes Konto anwenden.
        $setup = $_SESSION['totp_setup'] ?? null;
        if (!is_array($setup) || empty($setup['secret']) || empty($setup['backup_codes'])) {
            header("Location: /2fa/setup");
            exit;
        }
        if ((int)($setup['user_id'] ?? 0) !== $userId) {
            unset($_SESSION['totp_setup']);
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
        unset($_SESSION['totp_setup'], $_SESSION['twofa_reauth']);

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
        $stmt = $db->prepare("SELECT totp_secret, last_totp_timeslice FROM users WHERE id = ? AND deleted_at IS NULL AND deactivated_at IS NULL");
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
        $stmt = $db->prepare("SELECT backup_codes FROM users WHERE id = ? AND deleted_at IS NULL AND deactivated_at IS NULL");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            // Account wurde vermutlich zwischen 2FA-Pending und Backup-Code-Eingabe gelöscht
            unset($_SESSION['pending_2fa_user_id']);
            header("Location: /login");
            exit;
        }

        // json_decode liefert bei leerem String oder kaputtem JSON null -
        // sauber als "keine Codes vorhanden" behandeln statt foreach über null (#128).
        $backupCodes = json_decode($user['backup_codes'] ?? '[]', true);
        if (!is_array($backupCodes)) {
            $backupCodes = [];
        }
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

        // Untergrenze für die Antwortzeit, siehe unten.
        $startedAt = microtime(true);

        $email = trim($_POST['email'] ?? '');
        if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $db = Database::getInstance();
            // Gelöschte und deaktivierte Konten bekommen keinen Reset-Link
            // (#358). Diese Stelle filterte bis dahin GAR NICHT - ein Konto im
            // Papierkorb konnte sich per Mail ein neues Passwort setzen lassen.
            // Die Antwort bleibt für alle Fälle identisch, die Route ist also
            // weiterhin kein Orakel für vorhandene Adressen.
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND deleted_at IS NULL AND deactivated_at IS NULL");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                // Generate secure random token
                $token = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));

                // Delete older resets for this email
                $stmt = $db->prepare("DELETE FROM password_resets WHERE email = ?");
                $stmt->execute([$email]);

                // Gespeichert wird nur der SHA-256-Abdruck, nie das Token
                // selbst (siehe self::hashResetToken()).
                $stmt = $db->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
                $stmt->execute([$email, self::hashResetToken($token), $expiresAt]);

                $mailer = new \App\Service\Mailer();
                $mailer->sendPasswordResetEmail($email, $token);
            }
        }

        self::padResponseTime($startedAt);

        // Always show success message to prevent user enumeration
        header("Location: /forgot-password?sent=1");
        exit;
    }

    /**
     * Reset-Token werden nur als Abdruck gespeichert.
     *
     * Im Klartext wäre die Tabelle `password_resets` ein Vorrat gültiger
     * Zugänge: Wer sie lesen kann - über eine spätere Leselücke, eine
     * Sicherungskopie, einen Dump - übernimmt in den 15 Minuten Gültigkeit
     * jedes Konto, für das gerade ein Reset läuft, ohne das Passwort zu
     * kennen. Dasselbe Prinzip wie bei Passwörtern und API-Schlüsseln, die
     * hier längst nicht mehr im Klartext liegen.
     *
     * SHA-256 ohne Salt genügt, anders als bei Passwörtern: Das Token sind 256
     * Bit aus random_bytes(), da ist nichts zu raten und nichts über eine
     * Wortliste zu finden. Der Vergleich bleibt damit eine indizierte
     * Gleichheitssuche.
     */
    private static function hashResetToken(string $token): string {
        return hash('sha256', $token);
    }

    /**
     * Hält die Antwortzeit auf einer festen Untergrenze.
     *
     * Ohne sie verrät /forgot-password durch die Dauer, ob es das Konto gibt:
     * Nur für ein vorhandenes Konto wird eine SMTP-Verbindung aufgebaut, und
     * die kostet ein Vielfaches der übrigen Verarbeitung. Die Antwort ist zwar
     * in beiden Fällen wortgleich - genau darauf zielt der bestehende
     * Kommentar "prevent user enumeration" -, die Uhr sagte aber trotzdem die
     * Wahrheit.
     *
     * EHRLICHE GRENZE: Das deckelt die Auflösung, es beseitigt den Unterschied
     * nicht vollständig. Braucht der Mailversand länger als die Untergrenze,
     * ist er wieder messbar. Ein Versand über eine Warteschlange wäre die
     * saubere Lösung; bis dahin verengt das zusammen mit dem IP-Zähler
     * oberhalb das Fenster so weit, dass ein Abzählen von Konten unpraktikabel
     * wird.
     */
    private static function padResponseTime(float $startedAt, float $minimumSeconds = 1.0): void {
        $elapsed = microtime(true) - $startedAt;
        $remaining = $minimumSeconds - $elapsed;
        if ($remaining > 0) {
            usleep((int)round($remaining * 1_000_000));
        }
    }

    public function resetPassword(): void {
        $token = trim($_GET['token'] ?? '');
        if (empty($token)) {
            header("Location: /forgot-password");
            exit;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM password_resets WHERE token = ? AND expires_at > NOW()");
        $stmt->execute([self::hashResetToken($token)]);
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
        $stmt->execute([self::hashResetToken($token)]);
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
        // Der Filter gehört AUCH hier hin (#358), nicht nur in die Abfrage
        // weiter unten: Ein Reset-Link, der vor der Sperre verschickt wurde,
        // ist danach noch bis zu 15 Minuten gültig. Ohne den Filter setzte er
        // dem gesperrten Konto ein frisches Passwort - und die Sperre haette
        // ein Zeitfenster, in dem sie sich aushebeln laesst.
        $stmt = $db->prepare(
            "UPDATE users SET password_hash = ?, session_version = session_version + 1
             WHERE email = ? AND deleted_at IS NULL AND deactivated_at IS NULL"
        );
        $stmt->execute([$newPasswordHash, $reset['email']]);

        // Auch alle API-Schlüssel des Kontos ausdrücklich widerrufen (#217):
        // Die session_version-Kopplung in ApiKey::authenticate() lehnt ältere
        // Schlüssel nach der Erhöhung oben bereits ab - der Widerruf macht das
        // zusätzlich dauerhaft und in der Schlüsselverwaltung sichtbar. Ein
        // Schlüssel darf den Passwort-Reset (die typische Reaktion auf einen
        // Kompromittierungsverdacht) nicht als zweites Credential überleben.
        $stmt = $db->prepare("SELECT id, username FROM users WHERE email = ? AND deleted_at IS NULL AND deactivated_at IS NULL");
        $stmt->execute([$reset['email']]);
        $account = $stmt->fetch();
        if ($account) {
            $revokedKeys = \App\Security\ApiKey::revokeAllForUser((int)$account['id']);
            if ($revokedKeys > 0) {
                // Benutzer-Kontext explizit übergeben: Beim Reset per E-Mail-
                // Link existiert keine angemeldete Session, aus der der
                // AuditLogger ihn ableiten könnte.
                \App\Service\AuditLogger::log(
                    "API-Schlüssel widerrufen (Passwort-Reset)",
                    "security",
                    "{$revokedKeys} aktive(r) API-Schlüssel nach Passwort-Reset widerrufen",
                    (int)$account['id'],
                    (string)$account['username']
                );
            }
        }

        // Consume reset token
        $stmt = $db->prepare("DELETE FROM password_resets WHERE email = ?");
        $stmt->execute([$reset['email']]);

        // Ensure no auto-login occurs: User MUST authenticate via normal login + 2FA
        unset($_SESSION['user_id'], $_SESSION['pending_2fa_user_id'], $_SESSION['must_change_password']);

        header("Location: /login?success=password_reset");
        exit;
    }

    private function completeLogin(int $userId, string $redirectSuccess = '/admin'): void {
        // Gemeinsame Implementierung mit dem EntraID-SSO-Login (#42), siehe
        // App\Service\LoginSession.
        \App\Service\LoginSession::establish($userId, $redirectSuccess);
    }

    /**
     * Der erzwungene Passwortwechsel läuft über dieselbe Sitzungsprüfung wie
     * jede andere geschützte Seite.
     *
     * Ohne checkAuth() war er die einzige Ausnahme im ganzen Backend: Eine
     * Sitzung, die dort längst hinausgeflogen wäre (Konto gelöscht, Passwort
     * anderswo geändert, abweichender User-Agent, abgelaufen), konnte hier
     * ein neues Passwort setzen - und schrieb sich mit der frischen
     * session_version die Gültigkeit gleich selbst zurück, die #113 ihr
     * genommen hatte. checkAuth() lässt /force-password-change ausdrücklich
     * passieren (siehe dort Punkt 4), die Ausnahme kostet also nichts.
     */
    public function showForcePasswordChange(): void {
        $this->checkAuth();

        if (empty($_SESSION['must_change_password'])) {
            header("Location: /admin");
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

        $this->checkAuth();

        if (empty($_SESSION['must_change_password'])) {
            header("Location: /admin");
            exit;
        }

        $userId = $_SESSION['user_id'];

        $currentPassword = $_POST['current_password'] ?? '';
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';

        // Das bisherige Passwort erneut verlangen - dieselbe Begründung wie
        // beim Step-up vor einer 2FA-Änderung (#112): Wer eine unbeaufsichtigte
        // Sitzung übernimmt, soll das Konto nicht dauerhaft an sich binden
        // können. Gegen Raten gilt derselbe Zähler wie beim Login.
        if (\App\Security\RateLimiter::tooManyAttempts((string)$userId, 'force_password_change')) {
            $this->render('auth_force_password_change', [
                'title' => 'Erstmals Passwort ändern',
                'error' => 'Zu viele Fehlversuche. Bitte versuchen Sie es später erneut.'
            ]);
            return;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ? AND deleted_at IS NULL AND deactivated_at IS NULL");
        $stmt->execute([$userId]);
        $currentHash = (string)$stmt->fetchColumn();

        if ($currentHash === '' || !password_verify($currentPassword, $currentHash)) {
            \App\Security\RateLimiter::recordAttempt((string)$userId, 'force_password_change');
            \App\Service\AuditLogger::log(
                "Erzwungener Passwortwechsel abgelehnt",
                "auth",
                "Bisheriges Passwort falsch",
                (int)$userId,
                $_SESSION['username'] ?? null
            );

            $this->render('auth_force_password_change', [
                'title' => 'Erstmals Passwort ändern',
                'error' => 'Das bisherige Passwort ist nicht korrekt.'
            ]);
            return;
        }

        if (strlen($password) < 8 || $password !== $passwordConfirm) {
            $this->render('auth_force_password_change', [
                'title' => 'Erstmals Passwort ändern',
                'error' => 'Die Passwörter stimmen nicht überein oder sind zu kurz (mindestens 8 Zeichen).'
            ]);
            return;
        }

        \App\Security\RateLimiter::clearAttempts((string)$userId, 'force_password_change');

        $hash = password_hash($password, PASSWORD_DEFAULT);
        // session_version erhöhen, damit andere bestehende Sessions dieses
        // Benutzers ungültig werden (#113) - die eigene, gerade aktive Session
        // übernimmt den neuen Stand direkt und bleibt angemeldet.
        $stmt = $db->prepare("UPDATE users SET password_hash = ?, must_change_password = 0, session_version = session_version + 1 WHERE id = ?");
        $stmt->execute([$hash, $userId]);

        // Auch alle API-Schlüssel des Kontos ausdrücklich widerrufen (#217) -
        // dieselbe Begründung wie in updatePassword(): Die session_version-
        // Kopplung invalidiert sie bereits implizit, der Widerruf macht es
        // dauerhaft und sichtbar (revoked_at).
        $revokedKeys = \App\Security\ApiKey::revokeAllForUser((int)$userId);
        if ($revokedKeys > 0) {
            \App\Service\AuditLogger::log(
                "API-Schlüssel widerrufen (Passwortänderung)",
                "security",
                "{$revokedKeys} aktive(r) API-Schlüssel nach Passwortwechsel widerrufen"
            );
        }

        $stmt = $db->prepare("SELECT session_version FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $_SESSION['session_version'] = (int)$stmt->fetchColumn();

        unset($_SESSION['must_change_password']);

        header("Location: /admin?password_changed=1");
        exit;
    }
}
