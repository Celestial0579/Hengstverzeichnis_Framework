<?php
// src/Controllers/ProfileController.php

namespace App\Controllers;

use App\Database;
use App\Router;
use App\Security\BackupCodes;
use App\Security\EmailSecondFactor;
use App\Security\KontoSicherheit;
use App\Security\RateLimiter;
use App\Security\SecondFactors;
use App\Security\StepUp;
use App\Service\AdressWechsel;
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
 *
 * WAS DIE ZWEITEN FAKTOREN VERÄNDERT, VERLANGT EINEN STEP-UP (Audit M15, M17,
 * N10): Mailcode ein- und ausschalten und die Adresse ändern - die Adresse
 * ist der Zustellweg des Mailcodes. Passwort allein genügte dafür nicht: Wer
 * eine Sitzung übernommen hatte und das Passwort kannte, schaltete den
 * Mailcode ab und richtete ohne Nachweis eine eigene App ein, oder trug die
 * Adresse samt Faktor auf ein eigenes Postfach um. Siehe App\Security\StepUp.
 *
 * DIE profile_*-ZÄHLER bleiben bei tooManyAttempts()/recordAttempt() und
 * werden nicht vorab gebucht (anders als Anmeldung und Codeversand, Audit
 * M20): Sie setzen eine voll angemeldete Sitzung voraus, und deren Anfragen
 * serialisiert die Sperre der PHP-Sitzungsdatei ohnehin. Der gemeinsame
 * Versandtopf des Mailcodes wird dagegen gebucht - er ist dieselbe Grenze wie
 * im Anmeldeweg.
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
            "SELECT id, username, email, totp_enabled, email_2fa_enabled, backup_codes, totp_secret,
                    last_totp_timeslice, pending_email, pending_email_expires_at
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

    /** Zahl der noch ungenutzten Backup-Codes (siehe BackupCodes::offen()). */
    private function offeneBackupCodes(array $konto): int {
        return BackupCodes::offen(isset($konto['backup_codes']) ? (string)$konto['backup_codes'] : null);
    }

    public function index(): void {
        $this->checkAuth();
        $konto = $this->konto();
        $faktoren = SecondFactors::fromRow($konto);

        $this->render('profil', [
            'title' => 'Mein Profil',
            'konto' => $konto,
            'faktoren' => $faktoren,
            // Darf das Konto seine Faktoren gerade ändern (ohne Faktor immer,
            // sonst nur mit frischer Bestätigung)? Die Seite zeigt sonst den
            // Weg zur Bestätigung statt der Formulare.
            'stepUpErfuellt' => StepUp::erfuellt($this->userId(), $faktoren),
            'stepUpBis' => StepUp::gueltigBis($this->userId()),
            // Warum der Mailcode NICHT angeboten wird, muss auf der Seite
            // stehen - eine fehlende Auswahl ohne Begruendung sieht wie ein
            // Fehler aus (#354).
            'emailFaktorErlaubt' => SecondFactors::emailFactorAllowedFor($this->userId(), $konto['email'] ?? null),
            'istAdmin' => \App\Permission\GroupMembership::isAdmin($this->userId()),
            'mailcodeAngefordert' => EmailSecondFactor::pending($this->userId(), EmailSecondFactor::PURPOSE_SETUP),
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
        $ablage = $_SESSION[BackupCodes::SESSION] ?? null;
        unset($_SESSION[BackupCodes::SESSION]);

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

        // Ein offener Adressantrag endet im selben Statement (Audit M16):
        // Sonst bestaetigte ein Angreifer ihn nach dem Wechsel noch und holte
        // sich das Konto ueber "Passwort vergessen" an die neue Adresse.
        $offenerAntrag = KontoSicherheit::offenerAdressantrag($userId);
        $stmt = $db->prepare(
            "UPDATE users SET password_hash = ?, must_change_password = 0, session_version = session_version + 1,
                    " . KontoSicherheit::ADRESSANTRAG_LEEREN . "
             WHERE id = ?"
        );
        $stmt->execute([password_hash($neu, PASSWORD_DEFAULT), $userId]);

        // Offene Mailcodes gehoeren in denselben Zug wie Sitzungen und
        // Schluessel (#354): Ein Code, der schon in einem fremden Postfach
        // liegt, darf den Wechsel nicht ueberleben.
        $widerrufen = KontoSicherheit::nachPasswortwechsel($userId, $offenerAntrag);
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
     * Verlangt Passwort UND einen gültigen zweiten Faktor - denselben Maßstab
     * wie die 2FA-Einrichtung (#112). Zehn frische Backup-Codes sind dasselbe
     * Material wie ein neues Secret: Wer eine unbeaufsichtigte Sitzung
     * übernimmt und einmal aufs Telefon schaut, bindet das Konto sonst
     * dauerhaft an sich.
     *
     * WELCHER Faktor, entscheidet das Konto (#354, Audit N10) - dieselbe
     * Weiche wie die Bestätigungsseite (StepUp::codePruefen()): TOTP, wenn
     * vorhanden, sonst der Mailcode, aber nur, wenn er ein Faktor des Kontos
     * ist. Ein reines Passkey-Konto tippt keinen Code, es bestätigt vorher mit
     * dem Passkey (/2fa/reauth). Bis hierher genügte ihm ein Mailcode - der
     * Postfachzugang ersetzte den Passkey -, und ohne Adresse kam es nie an
     * neue Codes. Eine frische Bestätigung ersetzt den Code auch sonst.
     */
    public function regenerateBackupCodes(): void {
        $this->checkAuth();
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $userId = $this->userId();
        $konto = $this->konto();
        $faktoren = SecondFactors::fromRow($konto);
        $codeArt = StepUp::codeArt($faktoren);
        $freigegeben = StepUp::frisch($userId);

        if ($faktoren === []) {
            $this->zurueck('error', 'no_2fa');
        }
        if ($codeArt === null && !$freigegeben) {
            // Vor der Passwortprüfung: Ohne Freigabe kann dieses Konto hier
            // nie bestehen, und die Seite soll kein Passwort-Orakel sein.
            $this->zurueck('error', 'stepup_required');
        }
        if (RateLimiter::tooManyAttempts((string)$userId, 'profile_backup', 5, 900)) {
            $this->zurueck('error', 'rate_limited');
        }

        $passwort = (string)($_POST['current_password'] ?? '');

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $hash = (string)$stmt->fetchColumn();

        if (!password_verify($passwort, $hash)) {
            RateLimiter::recordAttempt((string)$userId, 'profile_backup');
            $this->zurueck('error', 'current_password_wrong');
        }

        if (!$freigegeben && !StepUp::codePruefen(
            $userId,
            $konto,
            $faktoren,
            (string)($_POST['totp_code'] ?? ''),
            (string)($_POST['email_code'] ?? '')
        )) {
            RateLimiter::recordAttempt((string)$userId, 'profile_backup');
            $this->zurueck('error', $codeArt === SecondFactors::TOTP ? 'totp_wrong' : 'code_wrong');
        }

        BackupCodes::erneuern($userId);
        AuditLogger::log('Backup-Codes neu erzeugt', 'auth', 'User ID ' . $userId);

        header('Location: /profil?success=backup_codes');
        exit;
    }

    // ---- Zweiter Faktor per E-Mail (#354) ------------------------------

    /**
     * Probecode an die hinterlegte Adresse schicken.
     *
     * Ohne Passwort - der Code allein bewirkt nichts, er ist erst zusammen
     * mit dem Passwort etwas wert (siehe enableEmailFactor()). Gedrosselt
     * ueber denselben Topf wie der Anmeldecode: Der Versand darf kein
     * Verstaerker sein.
     *
     * DER PROBECODE IST DER EIGENTLICHE PUNKT. Eine falsch eingetragene
     * Adresse sperrt das Konto in dem Moment aus, in dem der Faktor
     * eingeschaltet wird. Deshalb muss er einmal richtig eingegeben werden -
     * die Bestaetigung aus der Selbstregistrierung (#83) reicht nicht, sie
     * gilt nur fuer selbst registrierte Konten; von einem Admin angelegte
     * tragen dort NULL.
     */
    public function requestEmailFactorCode(): void {
        $this->checkAuth();
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $userId = $this->userId();
        $konto = $this->konto();
        $adresse = trim((string)($konto['email'] ?? ''));

        if ($adresse === '') {
            $this->zurueck('error', 'no_email');
        }
        // Nur, wo der Code etwas bewirken kann (Audit N10): beim Einschalten
        // (dann muss das Konto den Faktor nutzen dürfen) oder als Faktor, den
        // das Konto schon hat. Bis hierher bekam jedes Konto mit Adresse
        // einen - auch eines, dessen Faktor ein Passkey ist, und dort stand
        // der Code dann für den Passkey ein.
        if (
            !SecondFactors::emailFactorAllowedFor($userId, $adresse)
            && !in_array(SecondFactors::EMAIL, SecondFactors::fromRow($konto), true)
        ) {
            $this->zurueck('error', 'email_factor_not_allowed');
        }
        // Derselbe Topf wie der Anmeldecode, erst gebucht, dann gezählt
        // (Audit M20).
        if (RateLimiter::reserveAttempt(
            (string)$userId,
            EmailSecondFactor::RESEND_LIMITER_TYPE,
            EmailSecondFactor::RESEND_MAX,
            EmailSecondFactor::RESEND_WINDOW
        ) === null) {
            $this->zurueck('error', 'rate_limited');
        }

        $code = EmailSecondFactor::issue($userId, EmailSecondFactor::PURPOSE_SETUP);
        $versandt = (new Mailer())->sendSecondFactorCode(
            $adresse,
            $code,
            (int)round(EmailSecondFactor::TTL_SECONDS / 60)
        );

        AuditLogger::log(
            $versandt ? 'Probecode versendet' : 'Probecode konnte nicht versendet werden',
            'auth',
            'User ID ' . $userId
        );

        $this->zurueck($versandt ? 'success' : 'error', $versandt ? 'code_sent' : 'code_send_failed');
    }

    /**
     * Mailcode als zweiten Faktor einschalten.
     *
     * Verlangt Passwort UND den Probecode. Das Passwort, weil eine
     * uebernommene Sitzung allein nicht genuegen darf; den Probecode, weil
     * sonst eine falsch eingetragene Adresse das Konto aussperrt. Hat das
     * Konto schon einen Faktor, zusaetzlich die frische Bestaetigung (Audit
     * M15): Ein neuer Faktor ist ein neuer Weg ins Konto.
     *
     * Backup-Codes werden dabei erzeugt, falls es noch keine gibt: Sie sind
     * der Rueckweg, wenn keine Mail ankommt - und der Mailversand ist der
     * unzuverlaessigste Teil dieses Verfahrens.
     */
    public function enableEmailFactor(): void {
        $this->checkAuth();
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $userId = $this->userId();
        $konto = $this->konto();

        if (!SecondFactors::emailFactorAllowedFor($userId, $konto['email'] ?? null)) {
            $this->zurueck('error', 'email_factor_not_allowed');
        }
        // NACH der Zulassung (die Ablehnung fuer Administratoren bleibt die
        // erste Antwort), VOR Drossel und Passwort (kein Passwort-Orakel).
        if (!StepUp::erfuellt($userId, SecondFactors::fromRow($konto))) {
            $this->zurueck('error', 'stepup_required');
        }
        if (RateLimiter::tooManyAttempts((string)$userId, 'profile_2fa', 5, 900)) {
            $this->zurueck('error', 'rate_limited');
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        if (!password_verify((string)($_POST['current_password'] ?? ''), (string)$stmt->fetchColumn())) {
            RateLimiter::recordAttempt((string)$userId, 'profile_2fa');
            $this->zurueck('error', 'current_password_wrong');
        }

        if (!EmailSecondFactor::verify($userId, EmailSecondFactor::PURPOSE_SETUP, (string)($_POST['code'] ?? ''))) {
            RateLimiter::recordAttempt((string)$userId, 'profile_2fa');
            $this->zurueck('error', 'code_wrong');
        }

        $stmt = $db->prepare("UPDATE users SET email_2fa_enabled = 1 WHERE id = ?");
        $stmt->execute([$userId]);

        if ($this->offeneBackupCodes($konto) === 0) {
            BackupCodes::erneuern($userId);
        }

        AuditLogger::log('Zweiter Faktor per E-Mail eingeschaltet', 'auth', 'User ID ' . $userId);
        $this->zurueck('success', 'email_factor_on');
    }

    /**
     * Mailcode wieder ausschalten.
     *
     * Verlangt Passwort UND die frische Bestätigung (Audit M17). Die frühere
     * Begründung ("einen Faktor abzugeben schwächt nur das eigene Konto")
     * traf nicht zu: Nach dem Abschalten ist das Konto oft faktorlos, und
     * /2fa/setup nimmt dann ohne jeden Nachweis ein fremdes TOTP-Secret an.
     * Wer Sitzung und Passwort hatte, band das Konto so an sein eigenes
     * Gerät - genau das, was #112 für TOTP-Konten verhindert.
     */
    public function disableEmailFactor(): void {
        $this->checkAuth();
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $userId = $this->userId();
        $konto = $this->konto();

        if (empty($konto['email_2fa_enabled'])) {
            $this->zurueck('error', 'email_factor_not_on');
        }
        // Vor Drossel und Passwortprüfung - sonst ist die Seite ein Orakel
        // für das Passwort, auch ohne dass je etwas abgeschaltet wird.
        if (!StepUp::frisch($userId)) {
            $this->zurueck('error', 'stepup_required');
        }
        if (RateLimiter::tooManyAttempts((string)$userId, 'profile_2fa', 5, 900)) {
            $this->zurueck('error', 'rate_limited');
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        if (!password_verify((string)($_POST['current_password'] ?? ''), (string)$stmt->fetchColumn())) {
            RateLimiter::recordAttempt((string)$userId, 'profile_2fa');
            $this->zurueck('error', 'current_password_wrong');
        }

        $stmt = $db->prepare("UPDATE users SET email_2fa_enabled = 0 WHERE id = ?");
        $stmt->execute([$userId]);
        EmailSecondFactor::discard($userId);

        AuditLogger::log('Zweiter Faktor per E-Mail ausgeschaltet', 'auth', 'User ID ' . $userId);
        KontoSicherheit::hinweisSenden(
            $userId,
            'Zweiter Faktor per E-Mail ausgeschaltet',
            sprintf(
                'Für Ihr Konto wurde am %s der Einmalcode per E-Mail als zweiter Faktor ausgeschaltet.',
                date('d.m.Y \u\m H:i')
            ),
            (string)($konto['email'] ?? '')
        );
        $this->zurueck('success', 'email_factor_off');
    }

    // ---- E-Mail-Adresse ------------------------------------------------

    /**
     * Neue Adresse beantragen.
     *
     * DREI SCHRANKEN. Erstens das aktuelle Passwort - eine übernommene
     * Sitzung allein genügt nicht. Zweitens, bei einem Konto mit zweitem
     * Faktor, die frische Bestätigung (Audit M17): Die Adresse ist der
     * Zustellweg des Mailcodes und des Passwort-Resets; wer sie umträgt,
     * nimmt beides mit. Drittens gilt die neue Adresse erst nach
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

        if (!StepUp::erfuellt($userId, SecondFactors::fromRow($konto))) {
            $this->zurueck('error', 'stepup_required');
        }
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

        // OHNE `deleted_at IS NULL` (Audit N52): Der UNIQUE-Index umfasst auch
        // Konten im Papierkorb. Mit dem Filter wurde der Antrag angenommen und
        // scheiterte erst die Bestätigung - mit HTTP 500.
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?");
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
     *
     * Die Übernahme selbst ist atomar und fängt eine inzwischen vergebene
     * Adresse ab (App\Service\AdressWechsel, Audit N52, N53).
     */
    public function confirmNewEmail(): void {
        $token = trim((string)($_GET['token'] ?? ''));
        if ($token === '') {
            header('Location: /profil?error=email_token_invalid');
            exit;
        }

        $tokenHash = hash('sha256', $token);
        $db = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT id, email, pending_email FROM users
             WHERE pending_email_token = ? AND pending_email_expires_at > NOW()
               AND deleted_at IS NULL AND deactivated_at IS NULL"
        );
        $stmt->execute([$tokenHash]);
        $konto = $stmt->fetch();

        if (!$konto || empty($konto['pending_email'])) {
            header('Location: /profil?error=email_token_invalid');
            exit;
        }

        $userId = (int)$konto['id'];
        $alteAdresse = trim((string)($konto['email'] ?? ''));
        $neueAdresse = (string)$konto['pending_email'];

        $ergebnis = AdressWechsel::uebernehmen($db, $userId, $tokenHash, $neueAdresse);

        if ($ergebnis === AdressWechsel::VERGEBEN) {
            header('Location: /profil?error=email_taken');
            exit;
        }

        if ($ergebnis === AdressWechsel::NICHT_MEHR_OFFEN) {
            // Ein paralleler Aufruf desselben Links (Mailscanner) war
            // schneller. Hat ER die Adresse übernommen, ist das für den
            // Benutzer ein Erfolg - ohne zweites Protokoll und zweite Mail.
            $stmt = $db->prepare("SELECT email FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $jetzt = (string)($stmt->fetchColumn() ?: '');
            $ziel = strcasecmp($jetzt, $neueAdresse) === 0 ? 'success=email_changed' : 'error=email_token_invalid';
            header('Location: /profil?' . $ziel);
            exit;
        }

        // Offene Codes gingen an die ALTE Adresse (#354). Sie sollen nach dem
        // Wechsel nichts mehr bewirken - wer sie dort noch lesen kann, ist
        // gerade nicht mehr der Eigentuemer dieses Kontos.
        EmailSecondFactor::discard($userId);

        AuditLogger::log('E-Mail-Adresse bestätigt', 'auth', 'User ID ' . $userId);

        // Hinweis an die ALTE Adresse (Audit M17): Ab jetzt gehen Mailcode und
        // Passwort-Reset an die neue - und der SSO-Login ordnet Konten über
        // die Adresse zu. Der bisherige Eigentümer muss davon erfahren.
        if ($alteAdresse !== '') {
            KontoSicherheit::hinweisSenden(
                $userId,
                'Ihre E-Mail-Adresse wurde geändert',
                sprintf(
                    'Die E-Mail-Adresse Ihres Kontos wurde am %s auf %s geändert. '
                    . 'An diese Adresse hier gehen ab jetzt keine Nachrichten mehr.',
                    date('d.m.Y \u\m H:i'),
                    $neueAdresse
                ),
                $alteAdresse
            );
        }

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
