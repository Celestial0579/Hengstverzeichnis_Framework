<?php
// src/Controllers/ProfileController.php

namespace App\Controllers;

use App\Database;
use App\Router;
use App\Security\ApiKey;
use App\Security\RateLimiter;
use App\Security\Totp;
use App\Service\AuditLogger;
use App\Service\Mailer;

/**
 * Selbstbedienung für das eigene Konto (#357).
 *
 * WAS VORHER FEHLTE. Ein angemeldeter Benutzer konnte sein Passwort **nicht
 * ändern**: UserController verlangt im Konstruktor requireAdmin(), es blieb
 * nur der Umweg über „Passwort vergessen" und eine Mail. Für Konten ohne
 * E-Mail-Adresse - die mit #348 absichtlich entstehen - gab es diesen Umweg
 * gar nicht; sie hätten ihr Erstpasswort nie wieder ändern können.
 *
 * DIE SEITE STEHT JEDEM ANGEMELDETEN BENUTZER OFFEN, unabhängig von Rechten.
 * Sie arbeitet ausschliesslich auf $_SESSION['user_id'] - es gibt keinen
 * Parameter, über den sich ein fremdes Konto adressieren liesse.
 */
class ProfileController extends BaseController {

    /** Frist des Bestätigungslinks für eine neue Adresse. */
    private const EMAIL_TOKEN_TTL_HOURS = 48;

    /**
     * KEIN checkAuth() im Konstruktor.
     *
     * confirmNewEmail() muss ohne Anmeldung erreichbar sein: Wer den
     * Bestätigungslink in der neuen Adresse öffnet, ist nicht zwingend gerade
     * angemeldet - und der Besitz des Tokens IST der Nachweis. Alle übrigen
     * Aktionen rufen checkAuth() selbst als erste Zeile.
     */
    public function __construct() {
        parent::__construct();
    }

    private function userId(): int {
        return (int)($_SESSION['user_id'] ?? 0);
    }

    private function konto(): array {
        $stmt = Database::getInstance()->prepare(
            "SELECT id, username, email, totp_enabled, backup_codes, totp_secret, last_totp_timeslice,
                    pending_email, pending_email_expires_at
             FROM users WHERE id = ? AND deleted_at IS NULL AND deactivated_at IS NULL"
        );
        $stmt->execute([$this->userId()]);
        $konto = $stmt->fetch();

        if (!$konto) {
            // checkAuth() hätte das abfangen müssen; wenn nicht, ist die
            // Sitzung nichts mehr wert.
            header('Location: /login');
            exit;
        }
        return $konto;
    }

    /**
     * Zahl der noch ungenutzten Backup-Codes.
     *
     * json_decode() liefert bei NULL oder kaputtem JSON null - das muss als 0
     * gelten und nicht in einen TypeError laufen.
     */
    private function offeneBackupCodes(array $konto): int {
        $codes = json_decode((string)($konto['backup_codes'] ?? '[]'), true);
        return is_array($codes) ? count($codes) : 0;
    }

    public function index(): void {
        $this->checkAuth();
        $konto = $this->konto();

        $this->render('profil', [
            'title' => 'Mein Profil',
            'konto' => $konto,
            'backupCodesOffen' => $this->offeneBackupCodes($konto),
            'neueCodes' => $this->einmaligeCodesAbholen(),
            'error' => $_GET['error'] ?? null,
            'success' => $_GET['success'] ?? null,
        ]);
    }

    /**
     * Frisch erzeugte Backup-Codes werden EINMAL angezeigt und dabei aus der
     * Sitzung entfernt - danach existieren sie nur noch als Hash. An die
     * Konto-ID gebunden, damit sie nach einem Kontowechsel in derselben
     * Sitzung nicht beim Falschen landen.
     *
     * @return array<int, string>
     */
    private function einmaligeCodesAbholen(): array {
        $ablage = $_SESSION['profile_new_backup_codes'] ?? null;
        unset($_SESSION['profile_new_backup_codes']);

        if (!is_array($ablage) || (int)($ablage['user_id'] ?? 0) !== $this->userId()) {
            return [];
        }
        return array_values(array_filter((array)($ablage['codes'] ?? []), 'is_string'));
    }

    // ---- Passwort ------------------------------------------------------

    /**
     * Eigenes Passwort ändern.
     *
     * Tut dasselbe wie der erzwungene Wechsel (AuthController::
     * processForcePasswordChange()) - und das ist keine Bequemlichkeit,
     * sondern Pflicht: `session_version + 1` beendet alle anderen Sitzungen,
     * `ApiKey::revokeAllForUser()` entwertet die ausgestellten Schlüssel. Ohne
     * beides bewirkte der Wechsel WENIGER als der erzwungene, während die
     * Seite dem Benutzer das Gegenteil verspricht.
     */
    public function changePassword(): void {
        $this->checkAuth();
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $userId = $this->userId();

        // Gedrosselt wie jeder andere Passwortnachweis - sonst ist die Seite
        // ein Orakel zum Durchprobieren des aktuellen Passworts.
        if (RateLimiter::tooManyAttempts((string)$userId, 'profile_password', 5, 900)) {
            $this->zurueck('error', 'rate_limited');
        }

        $aktuell = (string)($_POST['current_password'] ?? '');
        $neu = (string)($_POST['new_password'] ?? '');
        $wiederholung = (string)($_POST['new_password_confirm'] ?? '');

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ? AND deleted_at IS NULL AND deactivated_at IS NULL");
        $stmt->execute([$userId]);
        $konto = $stmt->fetch();

        if (!$konto || !password_verify($aktuell, $konto['password_hash'])) {
            RateLimiter::recordAttempt((string)$userId, 'profile_password');
            AuditLogger::log('Passwortwechsel abgelehnt (aktuelles Passwort falsch)', 'auth', 'User ID ' . $userId);
            $this->zurueck('error', 'current_password_wrong');
        }

        if ($neu !== $wiederholung) {
            $this->zurueck('error', 'mismatch');
        }
        if (strlen($neu) < 12) {
            $this->zurueck('error', 'too_short');
        }
        if (password_verify($neu, $konto['password_hash'])) {
            $this->zurueck('error', 'same_password');
        }

        $stmt = $db->prepare(
            "UPDATE users SET password_hash = ?, must_change_password = 0, session_version = session_version + 1
             WHERE id = ?"
        );
        $stmt->execute([password_hash($neu, PASSWORD_DEFAULT), $userId]);

        $widerrufen = ApiKey::revokeAllForUser($userId);
        AuditLogger::log(
            'Passwort selbst geändert',
            'auth',
            sprintf('User ID %d, %d API-Schlüssel widerrufen', $userId, $widerrufen)
        );

        // Bewusster Abschluss: Die eigene Sitzung endet mit. Sie liesse sich
        // zwar nachziehen ($_SESSION['session_version']), aber ein
        // Passwortwechsel ist die typische Reaktion auf einen Verdacht - dann
        // ist "alle Sitzungen sind weg, auch meine" die ehrlichere Zusage.
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();

        header('Location: /login?success=password_changed');
        exit;
    }

    // ---- Backup-Codes --------------------------------------------------

    /**
     * Backup-Codes neu erzeugen.
     *
     * Verlangt Passwort UND einen gültigen TOTP-Code - denselben Maßstab wie
     * die 2FA-Einrichtung (#112). Zehn frische Backup-Codes sind dasselbe
     * Material wie ein neues Secret: Wer eine unbeaufsichtigte Sitzung
     * übernimmt und einmal aufs Telefon schaut, bindet das Konto sonst
     * dauerhaft an sich.
     */
    public function regenerateBackupCodes(): void {
        $this->checkAuth();
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $userId = $this->userId();
        $konto = $this->konto();

        if (empty($konto['totp_enabled'])) {
            $this->zurueck('error', 'no_2fa');
        }
        if (RateLimiter::tooManyAttempts((string)$userId, 'profile_backup', 5, 900)) {
            $this->zurueck('error', 'rate_limited');
        }

        $passwort = (string)($_POST['current_password'] ?? '');
        $code = trim((string)($_POST['totp_code'] ?? ''));

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $hash = (string)$stmt->fetchColumn();

        if (!password_verify($passwort, $hash)) {
            RateLimiter::recordAttempt((string)$userId, 'profile_backup');
            $this->zurueck('error', 'current_password_wrong');
        }

        // Crypto::decrypt() liefert bei alten, unverschlüsselten Secrets null -
        // der Rückfall auf den Rohwert muss mitkopiert werden, sonst lehnt die
        // Prüfung Bestandskonten grundlos ab.
        $secret = \App\Security\Crypto::decrypt((string)$konto['totp_secret']) ?? (string)$konto['totp_secret'];
        $slice = Totp::verifyCodeReturnSlice($secret, $code, $konto['last_totp_timeslice'] === null ? null : (int)$konto['last_totp_timeslice']);

        if ($slice === null) {
            RateLimiter::recordAttempt((string)$userId, 'profile_backup');
            $this->zurueck('error', 'totp_wrong');
        }

        $neueCodes = Totp::generateBackupCodes(10);
        $gehasht = array_map(
            static fn(string $c): string => password_hash(str_replace('-', '', strtoupper($c)), PASSWORD_DEFAULT),
            $neueCodes
        );

        // last_totp_timeslice mitschreiben: Der Replay-Schutz (#111) lebt
        // davon, dass jeder akzeptierte Code seinen Zeitschlitz verbraucht.
        $stmt = $db->prepare("UPDATE users SET backup_codes = ?, last_totp_timeslice = ? WHERE id = ?");
        $stmt->execute([json_encode($gehasht), $slice, $userId]);

        $_SESSION['profile_new_backup_codes'] = ['user_id' => $userId, 'codes' => $neueCodes];
        AuditLogger::log('Backup-Codes neu erzeugt', 'auth', 'User ID ' . $userId);

        header('Location: /profil?success=backup_codes');
        exit;
    }

    // ---- E-Mail-Adresse ------------------------------------------------

    /**
     * Neue Adresse beantragen.
     *
     * ZWEI SCHRANKEN. Erstens das aktuelle Passwort - eine übernommene
     * Sitzung allein genügt nicht. Zweitens gilt die neue Adresse erst nach
     * Bestätigung über einen Link an SIE; bis dahin bleibt die alte in Kraft.
     * Zusätzlich geht eine Nachricht an die BISHERIGE Adresse: Die kann ein
     * Angreifer nicht verhindern, und sie ist der einzige Weg, auf dem der
     * rechtmäßige Eigentümer von der Übernahme erfährt, solange sie noch
     * rückgängig zu machen ist.
     */
    public function requestEmailChange(): void {
        $this->checkAuth();
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $userId = $this->userId();
        $konto = $this->konto();

        if (RateLimiter::tooManyAttempts((string)$userId, 'profile_email', 5, 3600)) {
            $this->zurueck('error', 'rate_limited');
        }

        $neu = trim((string)($_POST['new_email'] ?? ''));
        $passwort = (string)($_POST['current_password'] ?? '');

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        if (!password_verify($passwort, (string)$stmt->fetchColumn())) {
            RateLimiter::recordAttempt((string)$userId, 'profile_email');
            $this->zurueck('error', 'current_password_wrong');
        }

        // Formatprüfung UND Längengrenze - die Spalte ist VARCHAR(100). Ohne
        // die Prüfung scheiterte erst das abschliessende UPDATE, nachdem der
        // Benutzer zwei Stufen durchlaufen hat.
        if ($neu === '' || !filter_var($neu, FILTER_VALIDATE_EMAIL) || mb_strlen($neu) > 100) {
            $this->zurueck('error', 'email_invalid');
        }
        if (strcasecmp($neu, (string)($konto['email'] ?? '')) === 0) {
            $this->zurueck('error', 'email_unchanged');
        }

        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id <> ? AND deleted_at IS NULL");
        $stmt->execute([$neu, $userId]);
        if ((int)$stmt->fetchColumn() > 0) {
            // Bewusst dieselbe Meldung wie bei einer ungültigen Adresse: Sonst
            // wäre die Seite ein Orakel dafür, welche Adressen ein Konto haben.
            RateLimiter::recordAttempt((string)$userId, 'profile_email');
            $this->zurueck('error', 'email_invalid');
        }

        $token = bin2hex(random_bytes(32));
        $stmt = $db->prepare(
            "UPDATE users SET pending_email = ?, pending_email_token = ?, pending_email_expires_at = ?
             WHERE id = ?"
        );
        $stmt->execute([
            $neu,
            hash('sha256', $token),
            date('Y-m-d H:i:s', time() + self::EMAIL_TOKEN_TTL_HOURS * 3600),
            $userId,
        ]);
        RateLimiter::recordAttempt((string)$userId, 'profile_email');

        $mailer = new Mailer();
        $mailer->sendProfileEmailChangeConfirmation($neu, $token);
        if (!empty($konto['email'])) {
            $mailer->sendProfileEmailChangeNotice((string)$konto['email'], $neu);
        }

        AuditLogger::log('Adressänderung beantragt', 'auth', 'User ID ' . $userId);
        header('Location: /profil?success=email_requested');
        exit;
    }

    /**
     * GET /profil/email/bestaetigen?token=… - der Link aus der Mail.
     *
     * Ohne Anmeldung erreichbar: Der Empfänger der neuen Adresse ist nicht
     * zwingend gerade angemeldet, und der Besitz des Tokens ist der Nachweis.
     * Deshalb steht die Route auch nicht hinter checkAuth() - siehe
     * public/index.php.
     */
    public function confirmNewEmail(): void {
        $token = trim((string)($_GET['token'] ?? ''));
        if ($token === '') {
            header('Location: /profil?error=email_token_invalid');
            exit;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT id, pending_email FROM users
             WHERE pending_email_token = ? AND pending_email_expires_at > NOW()
               AND deleted_at IS NULL AND deactivated_at IS NULL"
        );
        $stmt->execute([hash('sha256', $token)]);
        $konto = $stmt->fetch();

        if (!$konto || empty($konto['pending_email'])) {
            header('Location: /profil?error=email_token_invalid');
            exit;
        }

        $stmt = $db->prepare(
            "UPDATE users
             SET email = pending_email, pending_email = NULL, pending_email_token = NULL,
                 pending_email_expires_at = NULL, unprotected_since = NULL
             WHERE id = ?"
        );
        $stmt->execute([(int)$konto['id']]);

        AuditLogger::log('E-Mail-Adresse bestätigt', 'auth', 'User ID ' . $konto['id']);
        header('Location: /profil?success=email_changed');
        exit;
    }

    public function cancelEmailChange(): void {
        $this->checkAuth();
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        Database::getInstance()->prepare(
            "UPDATE users SET pending_email = NULL, pending_email_token = NULL, pending_email_expires_at = NULL WHERE id = ?"
        )->execute([$this->userId()]);

        AuditLogger::log('Adressänderung abgebrochen', 'auth', 'User ID ' . $this->userId());
        header('Location: /profil?success=email_cancelled');
        exit;
    }

    private function zurueck(string $art, string $schluessel): void {
        header('Location: /profil?' . $art . '=' . urlencode($schluessel));
        exit;
    }
}
