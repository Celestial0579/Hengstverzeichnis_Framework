<?php
// src/Controllers/AuthController.php

namespace App\Controllers;

use App\Database;
use App\Security\EmailSecondFactor;
use App\Security\LoginIdentifier;
use App\Security\Passkeys;
use App\Security\KontoSicherheit;
use App\Security\SecondFactors;
use App\Security\StepUp;
use App\Security\Totp;
use App\Service\EmailVerification;

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

        // Angemeldet wird mit dem Benutzernamen ODER der E-Mail-Adresse
        // (#348). Am Rand trimmen, nicht erst im Zähler: Die Kennung geht
        // sowohl in die Benutzersuche als auch in den Bezeichner des
        // Rate-Limiters, und ein angehängtes Leerzeichen darf nicht zwei
        // verschiedene Dinge bedeuten (siehe
        // App\Security\RateLimiter::normalizeIdentifier()).
        $kennung = trim((string)($_POST['kennung'] ?? ''));
        $password = $_POST['password'] ?? '';

        // Getrennte Zähler (Issue #115): Der Konto-Zähler ist an die
        // Client-IP gekoppelt, damit ein Angreifer mit gezielten Fehlversuchen
        // nicht beliebige bekannte Konten global aussperren kann
        // (Account-Lockout-DoS). Der zusätzliche reine IP-Zähler (höheres
        // Limit) bremst Passwort-Spraying über viele Konten von derselben
        // Adresse. Alle Zähler bleiben durch den fail-open-Charakter des
        // RateLimiters bei DB-Fehlern ausfallsicher.
        //
        // Jeder Zähler BUCHT zuerst und zählt danach (Audit M20) - sonst
        // kämen parallel abgeschickte Anmeldungen alle am selben alten Stand
        // vorbei. Jeder frühe Ausstieg gibt die schon gemachten Buchungen
        // wieder frei: Ein gesperrter Versuch hat kein Passwort geprüft und
        // zählt nirgends.
        //
        // Der IP-Anteil ist bei IPv6 das /64 (Audit M7,
        // ClientIp::rateLimitKey()): Wer ein ganzes Präfix hat, wechselt sonst
        // bei jedem Versuch die Adresse.
        $ipKey = \App\Security\ClientIp::rateLimitKey();
        $gebucht = [];

        // Die reine IP-Bremse braucht keine Kontokenntnis und steht deshalb
        // vor der Suche.
        $ipRes = \App\Security\RateLimiter::reserveAttempt($ipKey, 'login_ip', self::LOGIN_IP_MAX);
        if ($ipRes === null) {
            $this->loginGesperrt();
            return;
        }
        $gebucht[] = $ipRes;

        // Zweite Stufe für IPv6: das /48 (D10). Ein /48 ist 65.536 /64 - so
        // viel bekommt man bei Tunnelbrokern kostenlos. Ohne diese Stufe
        // bremste dort allein die kontoweite Zusatzabfrage unten. Bei IPv4
        // ergäbe sie denselben Schlüssel wie login_ip und entfällt.
        $netzKey = \App\Security\ClientIp::rateLimitKey(null, self::LOGIN_NETZ_PRAEFIX);
        if ($netzKey !== $ipKey) {
            $netzRes = \App\Security\RateLimiter::reserveAttempt($netzKey, self::LOGIN_NETZ_TYP, self::LOGIN_NETZ_MAX);
            if ($netzRes === null) {
                $this->buchungenFreigeben($gebucht);
                $this->loginGesperrt();
                return;
            }
            $gebucht[] = $netzRes;
        }

        $user = $this->findeKontoFuerAnmeldung($kennung);

        // DER KONTO-ZÄHLER HÄNGT AM KONTO, NICHT AN DER SCHREIBWEISE (#348).
        //
        // Bis v0.8 war der Schlüssel "email|ip" - solange es nur eine mögliche
        // Kennung gab, war das dasselbe. Seit man sich auch mit dem
        // Benutzernamen anmelden kann, ist es das nicht mehr: Ein Angreifer
        // probierte fünfmal "anna", dann fünfmal "anna@example.org" und hätte
        // gegen DASSELBE Konto die doppelte Zahl an Versuchen. Deshalb steht
        // die Kontokennung im Schlüssel, sobald das Konto gefunden ist.
        //
        // Trifft die Eingabe kein Konto, bleibt die normalisierte Kennung der
        // Schlüssel - es gibt nichts Besseres, und es gibt auch nichts zu
        // erraten. Das Präfix trennt beide Fälle sauber: Ohne es teilte sich
        // ein Konto mit der ID 5 einen Zähler mit jemandem, der "5" eintippt.
        $kontoKey = $user !== null
            ? 'uid:' . (int)$user['id']
            : 'kennung:' . LoginIdentifier::normalize($kennung);
        $accountIdentifier = $kontoKey . '|' . $ipKey;

        $accRes = \App\Security\RateLimiter::reserveAttempt($accountIdentifier, 'login');
        if ($accRes === null) {
            // Die IP-Buchungen zurück: Sonst verbrauchte jemand, der an EINEM
            // Konto gesperrt ist, mit jedem weiteren Klick das IP-Budget des
            // ganzen /64 bzw. NAT.
            $this->buchungenFreigeben($gebucht);
            $this->loginGesperrt();
            return;
        }

        // KONTOWEITE BREMSE OHNE SPERRE (Audit M7). Die Zähler oben hängen an
        // der Adresse; wer viele Adressen hat, verteilt seine Versuche und
        // bleibt überall darunter. Ein kontoweiter Zähler, der SPERRT, wäre
        // aber genau der Account-Lockout-DoS, gegen den #115 gebaut wurde.
        // Deshalb bremst er nur: Ab KONTO_BREMSE_AB Fehlversuchen im Fenster
        // verlangt die Anmeldung zusätzlich die Spam-Schutz-Abfrage (Kontext
        // 'login'), und ohne gelöste Aufgabe wird das Passwort gar nicht erst
        // geprüft. Das Konto selbst kommt mit gelöster Aufgabe jederzeit
        // hinein. Unbekannte Kennungen werden genauso behandelt - die Bremse
        // verrät nicht, ob es ein Konto gibt.
        //
        // Ein einzelnes /64 erreicht die Schwelle nie: Der Konto|/64-Zähler
        // (5) sperrt vorher, und dann wird hier gar nicht erst gebucht.
        $kontoRes = \App\Security\RateLimiter::reserveAttempt(
            $kontoKey,
            self::KONTO_BREMSE_TYP,
            self::KONTO_BREMSE_AB,
            self::KONTO_BREMSE_FENSTER
        );
        if ($kontoRes === null) {
            $captcha = \App\Security\Captcha::verify($this->settings, 'login', $_POST);
            if ($captcha !== \App\Security\Captcha::OK) {
                // Kein Passwort geprüft: Der Konto|IP-Versuch zählt nicht.
                // login_ip bleibt gebucht - er begrenzt, wie oft ein /64 die
                // Abfrage durchprobieren kann.
                \App\Security\RateLimiter::releaseAttempt($accRes);

                $abgeschickt = isset($_POST['login_captcha']);
                $this->render('login', [
                    'title' => \App\I18n\Translator::t('meta.title_login_failed'),
                    'error' => \App\I18n\Translator::t(match (true) {
                        !$abgeschickt => 'auth.login_captcha_required',
                        $captcha === \App\Security\Captcha::EXPIRED => 'dsgvo.captcha_expired',
                        $captcha === \App\Security\Captcha::TOO_FAST => 'dsgvo.captcha_too_fast',
                        default => 'dsgvo.captcha_wrong',
                    }),
                    'captchaField' => \App\Security\Captcha::renderField($this->settings, 'login'),
                    'kennung' => $kennung,
                ]);
                return;
            }
            // Gelöst: Der Versuch zählt trotzdem kontoweit - mit eigener
            // Buchung (ohne Grenze), damit ein Erfolg genau sie wieder
            // freigeben kann.
            $kontoRes = \App\Security\RateLimiter::reserveAttempt(
                $kontoKey,
                self::KONTO_BREMSE_TYP,
                PHP_INT_MAX,
                self::KONTO_BREMSE_FENSTER
            );
        }
        $gebucht[] = $kontoRes;

        if ($user === null) {
            // Gleich lange Antwort, egal ob es das Konto gibt (#348).
            // Ohne diesen Vergleich kostet ein Treffer eine bcrypt-Prüfung
            // und ein Fehlschlag nichts - die Uhr verriete damit, welche
            // Benutzernamen und Adressen existieren. Der Abdruck unten
            // gehört zu keinem Konto und trifft nie.
            password_verify($password, self::VERGLEICHSHASH);
        }

        if ($user && password_verify($password, $user['password_hash'])) {
            // Zuerst die Buchungen freigeben - vor jeder Weiterleitung und
            // jedem exit. Der kontoweite Zähler wird dabei bewusst NICHT
            // geleert, nur die eigene Buchung zurückgenommen: Sonst setzte
            // jede Anmeldung des Opfers das Budget des Angreifers zurück.
            // Ebenso bleibt der IP-Zähler stehen, damit ein erfolgreicher
            // Login nicht die Spuren von Spraying gegen andere Konten löscht.
            $this->buchungenFreigeben($gebucht);

            // Selfservice-Registrierung (#83): Ein gesetzter Verifizierungs-
            // Token bedeutet, dass die E-Mail-Adresse noch nicht bestätigt
            // wurde - der Login bleibt bis dahin gesperrt (erst NACH der
            // Passwortprüfung gemeldet, damit die Meldung nichts über fremde
            // Konten verrät).
            if (!empty($user['email_verification_token'])) {
                \App\Security\RateLimiter::releaseAttempt($accRes);
                // Abgelaufener oder verlorener Link war bis hierher eine
                // Sackgasse (Audit N54): Wer sein Passwort kennt, bekommt
                // einen aktuellen - gedrosselt und nie über die
                // Höchstlebensdauer des Kontos hinaus (siehe
                // EmailVerification::erneutSenden()). Nur hier, nach der
                // Passwortprüfung: Dritte können so weder abfragen, ob es ein
                // unbestätigtes Konto gibt, noch Mail auslösen.
                EmailVerification::erneutSenden($user);
                $this->render('login', [
                    'title' => \App\I18n\Translator::t('meta.title_login_failed'),
                    'error' => \App\I18n\Translator::t('auth.email_not_verified')
                ]);
                return;
            }
            // Den eigenen Konto|IP-Zähler zurücksetzen.
            \App\Security\RateLimiter::clearAttempts($accountIdentifier, 'login');

            // Wohin es nach dem Passwort geht, entscheidet die zentrale
            // Faktorweiche (Audit N42) - dieselbe, die auch der SSO-Weg
            // benutzt (Audit N9).
            $this->nachErstemFaktor((int)$user['id']);
        }

        // Fehlschlag: Alle Buchungen bleiben stehen - sie SIND die
        // Fehlversuche.
        $this->render('login', [
            'title' => \App\I18n\Translator::t('meta.title_login_failed'),
            'error' => \App\I18n\Translator::t('auth.invalid_credentials')
        ]);
    }

    /** Fehlversuche je Client-IP (bei IPv6 je /64) im Fenster. */
    private const LOGIN_IP_MAX = 20;

    /**
     * Zweite IP-Stufe nur für IPv6: Fehlversuche je /48 (Audit M7, D10).
     * Weit über login_ip, damit unbeteiligte Kunden desselben Providers nur
     * unter einem echten Angriff gemeinsam gebremst werden.
     */
    private const LOGIN_NETZ_TYP = 'login_net';
    private const LOGIN_NETZ_PRAEFIX = 48;
    private const LOGIN_NETZ_MAX = 100;

    /**
     * Kontoweite Bremse (Audit M7): Ab so vielen Fehlversuchen gegen dasselbe
     * Konto im Fenster verlangt die Anmeldung zusätzlich die
     * Spam-Schutz-Abfrage. Sie sperrt nie (#115).
     */
    public const KONTO_BREMSE_TYP = 'login_konto';
    public const KONTO_BREMSE_AB = 10;
    public const KONTO_BREMSE_FENSTER = 900;

    /**
     * Zählertyp des erzwungenen Passwortwechsels (Audit M8). Bis hierher
     * hiess er 'force_password_change' - 21 Zeichen, eines mehr als die
     * Spalte login_attempts.type (VARCHAR(20)) fasst. Das Buchen scheiterte
     * still, und das bisherige Passwort liess sich unbegrenzt raten.
     */
    public const FORCE_PW_LIMITER_TYPE = 'force_pw_change';

    /** Die gemeinsame Sperrantwort der Anmeldung. */
    private function loginGesperrt(): void {
        $this->render('login', [
            'title' => \App\I18n\Translator::t('meta.title_login_failed'),
            'error' => \App\I18n\Translator::t('auth.rate_limited_login')
        ]);
    }

    /** @param array<int, int|null> $reservierungen */
    private function buchungenFreigeben(array $reservierungen): void {
        foreach ($reservierungen as $id) {
            \App\Security\RateLimiter::releaseAttempt($id);
        }
    }

    /**
     * Ein bcrypt-Abdruck, der zu keinem Konto gehoert.
     *
     * Nur dafuer da, dass eine Anmeldung mit unbekannter Kennung dieselbe
     * Rechenzeit kostet wie eine mit bekannter (#348). Der Klartext dazu ist
     * nie irgendwo gespeichert worden - er wurde einmal zufaellig erzeugt und
     * verworfen.
     */
    private const VERGLEICHSHASH = '$2y$12$Erfpy1ZDvtcZJBDSxhPRIOWrAl4dgHp7UJ/MtqcizNVn.cpDd1eaC';

    /**
     * Findet das Konto zu einer Anmeldekennung - Benutzername ODER
     * E-Mail-Adresse (#348).
     *
     * WARUM EINE ODER-ABFRAGE UND KEINE FALLUNTERSCHEIDUNG AM `@`. Naheliegend
     * waere: enthaelt die Eingabe ein `@`, ist sie eine Adresse, sonst ein
     * Benutzername. Das stimmt fuer alles, was ab v0.9 neu entsteht - neue
     * Benutzernamen duerfen kein `@` mehr enthalten. Es stimmt aber nicht fuer
     * den Bestand: Wer heute "kunde@example.org" als Benutzernamen hat, kaeme
     * mit einer solchen Weiche nicht mehr hinein, obwohl sein Konto eindeutig
     * auffindbar ist. Die ODER-Abfrage findet beide Namensraeume und laesst
     * die Eindeutigkeit von der Datenlage entscheiden.
     *
     * WARUM `LIMIT 2` UND EIN ABBRUCH BEI ZWEI TREFFERN. Genau ein Fall ist
     * mehrdeutig: Der Benutzername des einen Kontos ist die Adresse eines
     * anderen. Beide Spalten sind UNIQUE, mehr als zwei Zeilen kann es also
     * nicht geben. Statt zu raten, welches Konto gemeint ist, wird die
     * Anmeldung abgelehnt - fail-closed. Die Migration meldet solche Faelle
     * beim Update (siehe SchemaMigrator, Schritt 35b), damit sie auffallen,
     * bevor jemand vor der Tuer steht.
     *
     * @return array<string, mixed>|null
     */
    private function findeKontoFuerAnmeldung(string $kennung): ?array {
        if ($kennung === '') {
            return null;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT id, username, email, password_hash, totp_enabled, email_2fa_enabled, email_verification_token,
                    email_verification_expires_at, created_at
             FROM users
             WHERE (email = ? OR username = ?) AND deleted_at IS NULL AND deactivated_at IS NULL
             LIMIT 2"
        );
        $stmt->execute([$kennung, $kennung]);
        $treffer = $stmt->fetchAll();

        if (count($treffer) === 1) {
            return $treffer[0];
        }

        if (count($treffer) > 1) {
            \App\Service\AuditLogger::log(
                "Anmeldung abgelehnt: mehrdeutige Kennung",
                "auth",
                sprintf(
                    'Die Kennung trifft %d Konten - ein Benutzername entspricht der E-Mail-Adresse eines '
                    . 'anderen Kontos (#348). Solange das so ist, kann sich keines der beiden anmelden.',
                    count($treffer)
                )
            );
        }

        return null;
    }

    /**
     * Das Konto zu einer laufenden Zwei-Faktor-Anmeldung, sofern es noch
     * anmeldefaehig ist.
     *
     * Zwischen Faktor 1 und Faktor 2 koennen Minuten liegen. In dieser Zeit
     * kann das Konto geloescht oder gesperrt worden sein (#358) - dann darf
     * der zweite Faktor es nicht mehr hereinlassen.
     *
     * @return array<string, mixed>|null
     */
    private function aktivesKonto(int $userId): ?array {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT id, username, email, totp_enabled, email_2fa_enabled
             FROM users WHERE id = ? AND deleted_at IS NULL AND deactivated_at IS NULL"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * Erzeugt einen Anmeldecode, verschickt ihn und leitet zum Eingabefeld
     * weiter (#354).
     *
     * Der Versand ist gedrosselt. Ohne die Bremse waere er ein Verstaerker:
     * Wer ein Passwort kennt, koennte beliebig oft Mail an die hinterlegte
     * Adresse ausloesen.
     *
     * Ein fehlgeschlagener Versand wird SICHTBAR gemeldet. Ein Formular, das
     * auf einen Code wartet, fuer den nie eine Mail kommt, ist der
     * unangenehmste denkbare Zustand - die Seite weist dann ausdruecklich auf
     * die Backup-Codes hin.
     */
    private function sendeAnmeldecode(int $userId, string $email): void {
        $ziel = self::PFAD_MAILCODE;

        // Faktor aktiv, aber keine Adresse: Diesen Zustand verhindern
        // UserController (Adresse entfernt -> Faktor aus) und
        // ProfileController (Faktor nur mit Adresse einschaltbar). Sollte er
        // doch entstehen, ist die richtige Antwort NICHT, den Faktor zu
        // ueberspringen - das liesse jemanden mit einem Schritt weniger
        // herein. Stattdessen die Seite mit dem Versandfehler: Sie verweist
        // auf die Backup-Codes, und die sind der vorgesehene Rueckweg.
        if (trim($email) === '') {
            header("Location: {$ziel}?fehler=versand");
            return;
        }

        // Erst buchen, dann zählen (Audit M20): Parallele Anforderungen
        // kommen sonst alle an der Grenze vorbei.
        if (\App\Security\RateLimiter::reserveAttempt(
            (string)$userId,
            EmailSecondFactor::RESEND_LIMITER_TYPE,
            EmailSecondFactor::RESEND_MAX,
            EmailSecondFactor::RESEND_WINDOW
        ) === null) {
            header("Location: {$ziel}?fehler=gedrosselt");
            return;
        }

        $code = EmailSecondFactor::issue($userId, EmailSecondFactor::PURPOSE_LOGIN);
        $versandt = (new \App\Service\Mailer())->sendSecondFactorCode(
            $email,
            $code,
            (int)round(EmailSecondFactor::TTL_SECONDS / 60)
        );

        \App\Service\AuditLogger::log(
            $versandt ? "Anmeldecode versendet" : "Anmeldecode konnte nicht versendet werden",
            "auth",
            $versandt
                ? "Zweiter Faktor per E-Mail angefordert"
                : "Der Mailversand schlug fehl - der Benutzer wird auf die Backup-Codes verwiesen",
            $userId
        );

        header("Location: {$ziel}" . ($versandt ? '' : '?fehler=versand'));
    }

    /** Eingabeseiten der zweiten Faktoren - Ziele von faktorPfad(). */
    public const PFAD_PASSKEY = '/login/passkey';
    public const PFAD_TOTP = '/login/2fa';
    public const PFAD_MAILCODE = '/login/2fa/email';

    /**
     * Die zentrale Faktorweiche nach bestandenem ersten Faktor (Audit N42, N9).
     *
     * @internal Prüft KEINEN ersten Faktor und darf nie als Route registriert
     * werden. Aufrufen darf sie nur, wer den ersten Faktor für GENAU dieses
     * Konto gerade selbst geprüft hat: loginSubmit() (Passwort) und
     * EntraSsoController::callback() (ID-Token des IdP). Öffentlich nur,
     * damit SSO dieselbe Weiche benutzt statt einer Kopie - bis Audit N9 baute
     * der SSO-Callback die Sitzung direkt auf, an TOTP, Passkey, Mailcode und
     * jeder 2FA-Pflicht vorbei.
     *
     * Die Faktoren lädt die Weiche SELBST über aktivesKonto(). Ein Aufrufer,
     * der eine Spalte im SELECT vergäße, bekäme von SecondFactors::fromRow()
     * still eine leere Liste - und damit einen Weg am Faktor vorbei.
     *
     * $anmeldeweg beschreibt einen SSO-Login ({art:'sso', provider, iss, sub,
     * idp_mfa}); LoginSession kennzeichnet damit den Audit-Eintrag.
     * `idp_mfa` steuert den Ablauf nur als Parameter hier und wird nie aus
     * der Session zurückgelesen. Beendet den Request.
     *
     * @param array{art?: string, provider?: string, iss?: string, sub?: string, idp_mfa?: ?string}|null $anmeldeweg
     */
    public function nachErstemFaktor(int $userId, string $ziel = '/admin', ?array $anmeldeweg = null): void {
        // Ein neuer Login löst jede bestehende Anmeldung dieser Sitzung ab.
        // Ohne das laufen zwei Identitäten nebeneinander: die alte in
        // `user_id`, die neue in `pending_2fa_user_id` - und alles, was
        // zwischen Faktor 1 und Faktor 2 passiert, kann die Nachweise der
        // einen für das Konto der anderen verwenden.
        $this->discardExistingSessionState();

        $konto = $this->aktivesKonto($userId);
        if ($konto === null) {
            header('Location: /login');
            exit;
        }

        // Die Marke ist an das Konto gebunden: LoginSession verbraucht sie nur
        // für genau diese user_id, und discardExistingSessionState() räumt sie
        // bei jedem neuen Login weg. Ein abgebrochener SSO-Versuch etikettiert
        // so keinen späteren Passwort-Login.
        if ($anmeldeweg !== null) {
            $_SESSION['anmeldeweg'] = ['user_id' => $userId] + $anmeldeweg + ['lokaler_faktor' => false];
        }

        // Nur mit ausdrücklichem Betreiberschalter und Nachweis im ID-Token
        // (OIDC_TRUST_IDP_MFA, siehe EntraSsoController::idpMfaNachweis()):
        // Der Betreiber hat die MFA des IdP für gleichwertig erklärt, damit
        // entfallen hier bewusst auch die Admin-TOTP-Hürde und
        // userRequires2fa(). Der Wert kommt nur als Parameter aus dem
        // Callback, nie aus der Session.
        if ($anmeldeweg !== null && !empty($anmeldeweg['idp_mfa'])) {
            $this->completeLogin($userId, $ziel);
            exit;
        }

        // Welche zweiten Faktoren hat das Konto? Die Frage beantwortet
        // ausschliesslich SecondFactors (#354), wohin sie fuehren
        // ausschliesslich faktorPfad().
        $faktoren = SecondFactors::fromRow($konto);
        if ($faktoren !== []) {
            \App\Service\LoginSession::beginSecondFactor($userId);
            $this->lokalerFaktorVerlangt();
            $pfad = self::faktorPfad($faktoren);

            if ($pfad === self::PFAD_MAILCODE) {
                // Der Code wird HIER erzeugt und versendet, nicht beim
                // Anzeigen des Formulars: Ein GET, der Mail verschickt, tut
                // das auch beim Neuladen und beim Vorausladen des Browsers.
                // Und nur, wenn der Mailcode der gewaehlte Faktor ist - wer
                // zuerst zum Passkey geht, fordert ihn dort per Knopf an.
                $this->sendeAnmeldecode($userId, (string)($konto['email'] ?? ''));
            } else {
                header('Location: ' . $pfad);
            }
            exit;
        }

        // 2FA-Pflicht pro Gruppe (#84): Nur wenn mindestens eine Gruppe des
        // Benutzers (oder die fest verdrahtete Admin-Pflicht) 2FA verlangt,
        // wird das Setup erzwungen - sonst ist der Login hier abgeschlossen.
        // Kein Bestandsschutz: Wird die Pflicht später aktiviert, greift sie
        // automatisch beim nächsten Login.
        if ($this->userRequires2fa($userId)) {
            \App\Service\LoginSession::beginSecondFactor($userId);
            $this->lokalerFaktorVerlangt();
            header("Location: /2fa/setup");
            exit;
        }

        $this->completeLogin($userId, $ziel);
        exit;
    }

    /** Hält in der SSO-Marke fest, dass ein lokaler Faktor verlangt wurde. */
    private function lokalerFaktorVerlangt(): void {
        if (isset($_SESSION['anmeldeweg']) && is_array($_SESSION['anmeldeweg'])) {
            $_SESSION['anmeldeweg']['lokaler_faktor'] = true;
        }
    }

    /**
     * Wohin ein Konto mit diesen Faktoren zum Nachweis geschickt wird.
     *
     * Eine Stelle, damit die Weiche nicht an drei Orten getrennt gepflegt
     * wird - jeder vergessene Ort waere ein Weg am Faktor vorbei.
     *
     * @param array<int, string> $faktoren
     */
    private static function faktorPfad(array $faktoren): string {
        // Reihenfolge = Staerke (#353). Der Passkey steht vorn, weil er als
        // einziger gegen Phishing traegt - er ist an die Domain gebunden,
        // ein abgetippter Code ist es nicht. Wer mehrere Faktoren hat,
        // bekommt den staerksten angeboten und kann auf der Seite selbst
        // umschalten.
        //
        // Ausnahme (Audit N42): Auf einer Verbindung, auf der Passkeys gar
        // nicht funktionieren (HTTP ausserhalb von localhost), geht es direkt
        // zum naechsten Verfahren - die Passkey-Seite zeigte dort nur "nicht
        // gesichert, bitte ausweichen". Ist der Passkey der einzige Faktor,
        // bleibt es bei seiner Seite; sie erklaert dann wenigstens, warum.
        if (in_array(SecondFactors::PASSKEY, $faktoren, true)) {
            $andere = array_diff($faktoren, [SecondFactors::PASSKEY]);
            if ($andere === [] || Passkeys::verfuegbar()) {
                return self::PFAD_PASSKEY;
            }
        }
        return in_array(SecondFactors::TOTP, $faktoren, true) ? self::PFAD_TOTP : self::PFAD_MAILCODE;
    }

    /**
     * Beschriftung des Kontos in der Authentikator-App.
     *
     * Seit #348 kann `email` NULL sein. Totp::getOtpAuthUrl() verlangt einen
     * String, und eine leere Beschriftung waere in einer App mit mehreren
     * Konten wertlos - also der Benutzername, der ohnehin die zweite gueltige
     * Anmeldekennung ist.
     *
     * @param array<string, mixed> $user
     */
    private function totpLabel(array $user): string {
        $email = trim((string)($user['email'] ?? ''));
        return $email !== '' ? $email : (string)($user['username'] ?? 'Konto');
    }

    /**
     * Gemeinsamer Abschluss aller zweiten Faktoren.
     *
     * WARUM HIER NOCH EINE HUERDE STEHT. Der Mailcode ist der schwaechste
     * Faktor (#354), und Administratoren wird er deshalb gar nicht erst
     * angeboten (SecondFactors::emailFactorAllowedFor()). Ein Konto kann aber
     * SPAETER in die Gruppe `admin` kommen - dann haette es alle Rechte und
     * als einzigen Faktor einen Mailcode. Statt das hinzunehmen oder das
     * Konto auszusperren, verlangt die Anmeldung an dieser Stelle die
     * Einrichtung von TOTP: nach bestandenem zweiten Faktor, nicht davor.
     *
     * Dasselbe gilt für einen Admin, dessen einziger Faktor ein Passkey ist
     * (etwa nach dem Zurücksetzen der eigenen 2FA): App und Backup-Codes sind
     * der Rückweg, wenn das Gerät verloren geht.
     *
     * DIE MARKE `zweiter_faktor_bestanden` (Audit M32). Die Einrichtung
     * verlangt für Konten mit vorhandenem Faktor eine angemeldete Sitzung
     * plus Step-up - die gibt es während des Logins nie. Ohne die Marke
     * schickte /2fa/setup das Konto zurück zur Faktorseite, die wieder
     * hierher, und so fort. Sie hält fest, dass Passwort und ein vorhandener
     * Faktor in GENAU diesem Login bestanden sind, und öffnet damit nur die
     * TOTP-Einrichtung dieses einen Kontos (siehe
     * zweiterFaktorInDiesemLoginBestanden()).
     */
    private function afterSecondFactor(int $userId, string $redirectSuccess = '/admin'): void {
        if (
            \App\Permission\GroupMembership::isAdmin($userId)
            && !SecondFactors::has($userId, SecondFactors::TOTP)
        ) {
            \App\Service\LoginSession::beginSecondFactor($userId);
            $_SESSION['zweiter_faktor_bestanden'] = ['user_id' => $userId, 'at' => time()];
            header("Location: /2fa/setup?grund=starker_faktor");
            exit;
        }

        $this->completeLogin($userId, $redirectSuccess);
    }

    /**
     * Räumt Anmelde- und Step-up-Zustand einer vorherigen Identität aus der
     * Session, sobald ein neuer Faktor-1-Nachweis erbracht wurde. Das
     * CSRF-Token bleibt bewusst erhalten - es gehört zur Sitzung, nicht zur
     * Identität, und der Login-Vorgang ist an dieser Stelle bereits geprüft.
     *
     * Dazu gehören auch angefangene Passkey-Zeremonien (Audit M15, N10): Eine
     * Registrierung oder ein Step-up, eröffnet für das vorherige Konto, darf
     * nach dem Identitätswechsel nicht mehr abzuschliessen sein.
     */
    private function discardExistingSessionState(): void {
        // Die Liste der identitätsgebundenen Schlüssel steht seit Audit M24
        // an EINER Stelle - dieselbe verwirft auch eine Sitzung aus einer
        // früheren Installation (BaseController) oder eine ungültige Sitzung
        // auf einer öffentlichen Seite (LoginSession::currentUserId()).
        \App\Service\LoginSession::forgetIdentity();
    }

    /**
     * Abschluss einer Passkey-Anmeldung (#353).
     *
     * Fuehrt in denselben gemeinsamen Abschluss wie jeder andere zweite
     * Faktor. Ein eigener Weg hier waere eine zweite Stelle, an der eine
     * Anmeldung fertig wird - und die zweite ist immer die, die eine Regel
     * vergisst. Konkret haengt an afterSecondFactor() die TOTP-Pflicht fuer
     * Administratoren (#354) und die Sitzungs-Erneuerung.
     *
     * Oeffentlich, weil PasskeyController sie aufruft; sie prueft deshalb
     * selbst, dass der erste Faktor wirklich erbracht wurde, statt sich auf
     * den Aufrufer zu verlassen.
     */
    public function passkeyAbschluss(int $userId): void {
        $erwartet = $_SESSION['pending_2fa_user_id'] ?? null;
        if ($erwartet === null || (int)$erwartet !== $userId) {
            unset($_SESSION['pending_2fa_user_id']);
            header('Location: /login');
            exit;
        }

        $this->afterSecondFactor($userId);
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
     * Gültigkeitsdauer einer Step-up-Reauthentifizierung (#112) in Sekunden.
     * Die Regel selbst steht seit Audit M15 in App\Security\StepUp; die
     * Marke aus dem Anmeldeweg (Audit M32) gilt ebenso lange.
     */
    private const TWOFA_REAUTH_TTL = StepUp::TTL;

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
     * Siehe StepUp::frisch() - dort steht auch, warum die Konto-ID dazugehört.
     */
    private function hasFresh2faReauth(int $userId): bool {
        return StepUp::frisch($userId);
    }

    /**
     * Hat GENAU dieses Konto in diesem Login schon Passwort UND einen
     * vorhandenen zweiten Faktor bestanden (Audit M32)?
     *
     * Das ist derselbe Nachweis, den process2faReauth() verlangt - Passwort
     * plus vorhandener Faktor -, nur im Anmeldeweg erbracht. Er gilt nur,
     * solange der Anmeldevorgang für dieses Konto noch läuft und höchstens
     * TWOFA_REAUTH_TTL lang; danach führt der Weg wieder über die
     * Faktorseite, und afterSecondFactor() setzt eine frische Marke.
     */
    private function zweiterFaktorInDiesemLoginBestanden(int $userId): bool {
        $marke = $_SESSION['zweiter_faktor_bestanden'] ?? null;
        if (!is_array($marke) || !isset($marke['user_id'], $marke['at'])) {
            return false;
        }
        if ((int)$marke['user_id'] !== $userId) {
            return false;
        }
        if ((int)($_SESSION['pending_2fa_user_id'] ?? 0) !== $userId) {
            return false;
        }
        return (time() - (int)$marke['at']) <= self::TWOFA_REAUTH_TTL;
    }

    /**
     * Eine BESTEHENDE Anmeldung (nicht der laufende Login) geht auf /2fa/setup
     * und /2fa/enable durch dieselbe Sitzungsprüfung wie jede geschützte
     * Seite (Audit N60).
     *
     * Ohne sie galt dort nur `user_id` in der Sitzung. Nach einem "2FA Reset"
     * durch die Verwaltung ist das Konto faktorlos und die Einrichtung
     * verlangt keinen Nachweis - eine noch lebende Sitzung, etwa auf dem
     * gestohlenen Gerät, hätte sofort ein eigenes Secret gebunden, obwohl der
     * Reset `session_version` erhöht hat. Der laufende Login
     * (`pending_2fa_user_id`) hat noch keine Sitzung, die zu prüfen wäre.
     */
    private function sitzungPruefenWennAngemeldet(): void {
        if (!isset($_SESSION['pending_2fa_user_id'])) {
            $this->checkAuth();
        }
    }

    public function show2faSetup(): void {
        $userId = $this->twofaTargetUserId();
        if ($userId === null) {
            header("Location: /login");
            exit;
        }
        $this->sitzungPruefenWennAngemeldet();

        $user = $this->aktivesKonto($userId);
        if ($user === null) {
            header("Location: /login");
            exit;
        }

        // Step-up-Reauth (#112): Hat das Konto SCHON einen zweiten Faktor,
        // darf eine bestehende Session die Konfiguration (neues Secret + neue
        // Backup-Codes) nur nach erneuter Bestätigung ändern - sonst könnte
        // ein Angreifer mit übernommener Session (z. B. unbeaufsichtigter
        // Arbeitsplatz) die 2FA dauerhaft an sich binden.
        //
        // GEFRAGT WIRD NACH JEDEM FAKTOR, NICHT NUR NACH TOTP. Bis v0.8 war
        // `totp_enabled = 0` gleichbedeutend mit "kein zweiter Faktor" - seit
        // #354 nicht mehr. Wer nur diese Spalte prüft, lässt ein Konto, dessen
        // einziger Faktor der Mailcode ist, hier ohne jeden Nachweis durch:
        // Der Angreifer bräuchte nur das Passwort, holte sich auf dieser Seite
        // ein frisches TOTP-Secret, bestätigte es mit dem eigenen Gerät und
        // wäre angemeldet - mit dem Mailcode nie in Berührung gekommen und mit
        // den Backup-Codes des Opfers überschrieben.
        //
        // Ausnahme (Audit M32): Wer in diesem Login Passwort und einen
        // vorhandenen Faktor schon bestanden hat und nur noch die für
        // Administratoren vorgeschriebene App einrichten muss, hat den
        // Step-up-Nachweis bereits erbracht.
        $vorhandeneFaktoren = SecondFactors::fromRow($user);
        $grundStarkerFaktor = $this->zweiterFaktorInDiesemLoginBestanden($userId);
        if ($vorhandeneFaktoren !== [] && !$grundStarkerFaktor) {
            // Die Neukonfiguration darf nur die eigene, angemeldete Sitzung
            // dieses Kontos anstoßen. Eine Sitzung, die als jemand anderes
            // angemeldet ist, zählt hier ausdrücklich NICHT als Nachweis -
            // sonst genügte das Passwort des Opfers, um mit dem eigenen
            // Step-up dessen Secret zu überschreiben.
            if ((int)($_SESSION['user_id'] ?? 0) !== $userId) {
                // Pending-Session hat nur das Passwort bewiesen - für Konten
                // mit zweitem Faktor führt der Weg ausschließlich über dessen
                // Eingabeseite.
                header("Location: " . self::faktorPfad($vorhandeneFaktoren));
                exit;
            }
            if (!$this->hasFresh2faReauth($userId)) {
                $this->reauthSeite($userId, $vorhandeneFaktoren, 'setup');
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
        // Seit #348 darf `email` NULL sein - die Authentikator-App braucht
        // aber eine Beschriftung. Dann steht der Benutzername darin; er ist
        // ohnehin die andere gueltige Anmeldekennung.
        $otpAuthUrl = Totp::getOtpAuthUrl($this->totpLabel($user), $siteName, $secret);

        $this->render('2fa_setup', [
            'title' => '2FA Einrichtung',
            'secret' => $secret,
            'otpAuthUrl' => $otpAuthUrl,
            'backupCodes' => $backupCodes,
            'grundStarkerFaktor' => $grundStarkerFaktor,
        ]);
    }

    /**
     * Die Bestätigungsseite (#112, Audit M15, M17, N10).
     *
     * Welche Felder sie zeigt, richtet sich EXAKT nach StepUp::codePruefen():
     * TOTP-Feld bei TOTP, Mailcodefeld nur bei Mailcode ohne TOTP, und für
     * ein Konto mit Passkey der Passkey-Knopf. `fuer` bestimmt Überschrift
     * und Rückweg und kommt nur über die Liste in StepUp::ziel().
     *
     * @param array<int, string> $faktoren
     */
    private function reauthSeite(int $userId, array $faktoren, string $fuer, ?string $error = null): void {
        $daten = [
            'title' => $fuer === 'setup' ? '2FA-Änderung bestätigen' : 'Änderung bestätigen',
            'faktoren' => $faktoren,
            'fuer' => StepUp::fuer($fuer),
            'codeArt' => StepUp::codeArt($faktoren),
            'mailcodeAngefordert' => EmailSecondFactor::pending($userId, EmailSecondFactor::PURPOSE_SETUP),
            'passkeyMoeglich' => in_array(SecondFactors::PASSKEY, $faktoren, true) && Passkeys::verfuegbar(),
        ];
        if ($error !== null) {
            $daten['error'] = $error;
        }
        $this->render('2fa_reauth', $daten);
    }

    /**
     * GET /2fa/reauth?fuer=… - die Bestätigung direkt aufrufen (Audit M15).
     *
     * Bis hierher gab es die Seite nur als Zwischenstation von /2fa/setup.
     * Jetzt verlangen auch Passkey, Mailcode und Adressänderung sie, und nach
     * der Bestätigung geht es dorthin zurück, woher der Benutzer kam.
     */
    public function show2faReauth(): void {
        $this->checkAuth();
        $userId = (int)$_SESSION['user_id'];
        $fuer = StepUp::fuer($_GET['fuer'] ?? null);

        // Fail-closed: nicht über SecondFactors::forUser(), das bei einem
        // Datenbankfehler "keine Faktoren" meldet.
        $faktoren = StepUp::faktoren($userId);
        if ($faktoren === null) {
            header("Location: /login");
            exit;
        }
        if ($faktoren === []) {
            // Nichts zu bestätigen - die Aktion selbst verlangt dann keinen
            // Nachweis (StepUp::erfuellt()).
            header('Location: ' . StepUp::ziel($fuer));
            exit;
        }

        $this->reauthSeite($userId, $faktoren, $fuer);
    }

    /**
     * Step-up-Reauthentifizierung (#112): verlangt das aktuelle Passwort UND
     * einen gültigen Code des Faktors, den das Konto hat (StepUp::codePruefen(),
     * Audit N10). Erfolg wird zeitlich begrenzt in der Session vermerkt
     * (StepUp::markieren()); danach geht es zum Ziel aus `fuer`.
     *
     * Ein Konto, dessen einziger Faktor ein Passkey ist, besteht hier nie -
     * es bestätigt mit dem Passkey (PasskeyController::stepUpPruefen()). Bis
     * hierher genügte ihm ein Mailcode, obwohl der gar nicht sein Faktor war.
     */
    public function process2faReauth(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        // Dieselbe Sitzungsprüfung wie jede geschützte Seite: Eine Sitzung,
        // die dort hinausflöge (abgelaufen, anderes Gerät, Passwort anderswo
        // geändert), soll sich hier keine Freigabe mehr holen.
        $this->checkAuth();
        $userId = (int)$_SESSION['user_id'];
        $fuer = StepUp::fuer($_POST['fuer'] ?? null);

        $db = Database::getInstance();
        $stmt = $db->prepare(
            // `id` gehoert seit #353 mit in JEDE Abfrage, deren Zeile nach
            // SecondFactors::fromRow() geht: Passkeys stehen nicht in dieser
            // Zeile, sondern in einer eigenen Tabelle, und ohne die Kennung
            // laesst sich die Frage "hat dieses Konto einen Passkey" gar nicht
            // beantworten. Ohne sie galt ein Passkey-only-Konto hier als
            // ungeschuetzt - und der Step-up-Schutz vor der 2FA-Neukonfiguration
            // griff genau bei denen nicht, die ihn am noetigsten haben.
            "SELECT id, password_hash, totp_secret, totp_enabled, email_2fa_enabled, last_totp_timeslice
             FROM users WHERE id = ? AND deleted_at IS NULL AND deactivated_at IS NULL"
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        $faktoren = is_array($user) ? SecondFactors::fromRow($user) : [];

        // Erst buchen, dann prüfen (Audit M20). Ein Fehlschlag lässt die
        // Buchung stehen, ein Erfolg leert den Zähler.
        if (\App\Security\RateLimiter::reserveAttempt((string)$userId, '2fa') === null) {
            $this->reauthSeite($userId, $faktoren, $fuer, \App\I18n\Translator::t('auth.rate_limited_2fa'));
            return;
        }

        $password = (string)($_POST['password'] ?? '');

        if (
            $user
            && $faktoren !== []
            && password_verify($password, (string)$user['password_hash'])
            && StepUp::codePruefen(
                $userId,
                $user,
                $faktoren,
                (string)($_POST['totp_code'] ?? ''),
                (string)($_POST['email_code'] ?? '')
            )
        ) {
            \App\Security\RateLimiter::clearAttempts((string)$userId, '2fa');

            // Mit der Konto-ID, nicht als blanker Zeitstempel: Der
            // Nachweis gilt für dieses Konto und für kein anderes.
            StepUp::markieren($userId);

            \App\Service\AuditLogger::log("2FA-Neukonfiguration freigeschaltet", "auth", "Step-up-Reauth erfolgreich ({$fuer})", $userId, $_SESSION['username'] ?? null);

            header("Location: " . StepUp::ziel($fuer));
            exit;
        }

        $this->reauthSeite($userId, $faktoren, $fuer, 'Passwort oder Code ungültig. Bitte versuchen Sie es erneut.');
    }

    /**
     * Probecode fuer den Step-up an die hinterlegte Adresse (#354).
     *
     * Braucht ein Konto, dessen Faktor der Mailcode ist, um eine
     * Authentikator-App nachzuruesten oder seine Faktoren zu aendern: Die
     * Bestaetigungsseite verlangt einen gueltigen Faktor, und den gibt es
     * hier. Nur, wenn der Mailcode dort auch ZAEHLT (StepUp::codeArt()) -
     * ein Konto mit TOTP bestaetigt mit der App, einer ohne Mailcode-Faktor
     * bekommt keinen Code.
     *
     * Der Code geht an die BISHERIGE Adresse - auch vor einer Adressaenderung
     * (Audit M17). Sonst truege der Angreifer die Adresse um und bestaetigte
     * mit dem Code aus seinem eigenen Postfach.
     */
    public function sendReauthCode(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden(\App\I18n\Translator::t('errors.csrf_invalid'));
        }

        // Ausdruecklich nur die eigene, angemeldete Sitzung - genau wie der
        // Step-up selbst. Eine Pending-Session hat erst das Passwort bewiesen.
        $this->checkAuth();
        $userId = (int)$_SESSION['user_id'];
        $zurueck = '/2fa/reauth?fuer=' . StepUp::fuer($_POST['fuer'] ?? null);

        $konto = $this->aktivesKonto($userId);
        if (
            $konto === null
            || empty($konto['email'])
            || StepUp::codeArt(SecondFactors::fromRow($konto)) !== SecondFactors::EMAIL
        ) {
            header("Location: " . $zurueck);
            exit;
        }

        if (\App\Security\RateLimiter::reserveAttempt(
            (string)$userId,
            EmailSecondFactor::RESEND_LIMITER_TYPE,
            EmailSecondFactor::RESEND_MAX,
            EmailSecondFactor::RESEND_WINDOW
        ) === null) {
            header("Location: " . $zurueck);
            exit;
        }

        $code = EmailSecondFactor::issue($userId, EmailSecondFactor::PURPOSE_SETUP);
        (new \App\Service\Mailer())->sendSecondFactorCode(
            (string)$konto['email'],
            $code,
            (int)round(EmailSecondFactor::TTL_SECONDS / 60)
        );

        header("Location: " . $zurueck);
        exit;
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
        $this->sitzungPruefenWennAngemeldet();

        $db = Database::getInstance();
        $dbUser = $this->aktivesKonto($userId);
        if ($dbUser === null) {
            header("Location: /login");
            exit;
        }

        // Step-up-Reauth (#112): Hat das Konto schon einen zweiten Faktor, muss
        // die Session die Neukonfiguration zuvor über /2fa/reauth freigeschaltet
        // haben - die Prüfung aus show2faSetup() wird hier serverseitig
        // wiederholt, damit ein direkter POST sie nicht umgehen kann.
        // Wortgleich, weil beide Wege für sich allein tragen müssen:
        // /2fa/setup gibt das neue Secret bereits aus, ein Fix nur hier käme
        // zu spät. Zur Frage "welcher Faktor zählt" siehe show2faSetup().
        $vorhandeneFaktoren = SecondFactors::fromRow($dbUser);
        $grundStarkerFaktor = $this->zweiterFaktorInDiesemLoginBestanden($userId);
        if ($vorhandeneFaktoren !== [] && !$grundStarkerFaktor) {
            if ((int)($_SESSION['user_id'] ?? 0) !== $userId) {
                header("Location: " . self::faktorPfad($vorhandeneFaktoren));
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
                'otpAuthUrl' => Totp::getOtpAuthUrl($this->totpLabel($dbUser), $siteName, $secret),
                'backupCodes' => $backupCodesRaw,
                'grundStarkerFaktor' => $grundStarkerFaktor,
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

        // Server-State der Einrichtung und Reauth-Freischaltung verbrauchen -
        // ebenso die Marke aus dem Anmeldeweg (Audit M32): Sie hat ihren
        // einen Zweck erfüllt, afterSecondFactor() findet jetzt TOTP vor.
        unset($_SESSION['totp_setup'], $_SESSION['zweiter_faktor_bestanden']);
        StepUp::verbrauchen();

        $this->afterSecondFactor($userId, '/admin?2fa=enabled');
    }

    public function show2faVerify(): void {
        if (!isset($_SESSION['pending_2fa_user_id'])) {
            header("Location: /login");
            exit;
        }

        $this->render('2fa_verify', [
            'title' => \App\I18n\Translator::t('meta.title_2fa_confirm'),
            // Hat das Konto BEIDE Faktoren, bleibt der Mailcode von hier aus
            // als Ausweichweg erreichbar - sonst waere der Weg dorthin nur
            // ueber ein erneutes Anmelden zu finden.
            'mailcodeMoeglich' => $this->mailcodeMoeglich(),
        ]);
    }

    /**
     * Steht dem laufenden Anmeldevorgang der Mailcode als zweiter Weg offen?
     */
    private function mailcodeMoeglich(): bool {
        $userId = $_SESSION['pending_2fa_user_id'] ?? null;
        if (!$userId) {
            return false;
        }
        $konto = $this->aktivesKonto((int)$userId);

        return $konto !== null && !empty($konto['email_2fa_enabled']) && !empty($konto['email']);
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

        // Erst buchen, dann prüfen (Audit M20).
        $res = \App\Security\RateLimiter::reserveAttempt((string)$userId, '2fa');
        if ($res === null) {
            $this->render('2fa_verify', [
                'title' => \App\I18n\Translator::t('meta.title_2fa_confirm'),
                'error' => \App\I18n\Translator::t('auth.rate_limited_2fa'),
                'mailcodeMoeglich' => $this->mailcodeMoeglich(),
            ]);
            return;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT totp_secret, last_totp_timeslice FROM users WHERE id = ? AND deleted_at IS NULL AND deactivated_at IS NULL");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        // Fail-closed (Audit N8): Laesst sich das Secret nicht lesen, wird
        // gar nicht erst verglichen - der Versuch zaehlt als Fehlversuch.
        // Bis hierher wurde dann der Rohwert zum Secret, nach einem Wechsel
        // des APP_KEY also der Chiffretext, den jeder mit einem Dump kennt.
        $decryptedSecret = ($user && !empty($user['totp_secret']))
            ? Totp::secretAusSpeicher((string)$user['totp_secret'], (int)$userId)
            : null;

        if ($decryptedSecret !== null) {

            // Replay-Schutz (#111): Codes sind single-use - der getroffene
            // Zeitschlitz wird persistiert, bereits verbrauchte Schlitze lehnt
            // verifyCodeReturnSlice() auch bei korrektem Code ab.
            $lastSlice = $user['last_totp_timeslice'] !== null ? (int)$user['last_totp_timeslice'] : null;
            $matchedSlice = Totp::verifyCodeReturnSlice($decryptedSecret, $code, $lastSlice);
            // Den Schlitz nur verbrauchen, wenn er seit dem Lesen nicht schon
            // von einer parallelen Anfrage verbraucht wurde (Audit N43) -
            // sonst gälte derselbe Code zweimal, und eine langsamere Anfrage
            // könnte den gespeicherten Schlitz wieder senken.
            if ($matchedSlice !== null && \App\Security\OneTimeProofs::consumeTotpSlice((int)$userId, $matchedSlice)) {
                \App\Security\RateLimiter::clearAttempts((string)$userId, '2fa');
                $this->afterSecondFactor((int)$userId, '/admin');
            }
        }

        // Fehlschlag: Die Buchung bleibt stehen.

        $this->render('2fa_verify', [
            'title' => \App\I18n\Translator::t('meta.title_2fa_confirm'),
            'error' => \App\I18n\Translator::t('auth.invalid_2fa_code'),
            'mailcodeMoeglich' => $this->mailcodeMoeglich(),
        ]);
    }

    /**
     * Eingabefeld fuer den Anmeldecode aus der E-Mail (#354).
     *
     * Verschickt selbst NICHTS. Der Code entsteht im POST, der hierher
     * weiterleitet (loginSubmit() bzw. resendEmail2faCode()) - ein GET, der
     * Mail ausloest, tut das auch beim Neuladen und beim Vorausladen des
     * Browsers.
     */
    public function showEmail2faVerify(): void {
        if (!isset($_SESSION['pending_2fa_user_id'])) {
            header("Location: /login");
            exit;
        }

        $this->render('2fa_email_verify', [
            'title' => \App\I18n\Translator::t('meta.title_2fa_confirm'),
            'error' => $this->versandfehlerText($_GET['fehler'] ?? null),
        ]);
    }

    /**
     * Uebersetzt den Fehlermarker aus der URL in eine Meldung. Der Marker
     * steht in der URL und nicht in der Session, weil er einen abgeschlossenen
     * Vorgang beschreibt - beim naechsten Versuch soll er weg sein.
     */
    private function versandfehlerText(?string $marker): ?string {
        return match ($marker) {
            'versand'    => \App\I18n\Translator::t('auth.2fa_email_send_failed'),
            'gedrosselt' => \App\I18n\Translator::t('auth.2fa_email_send_throttled'),
            default      => null,
        };
    }

    public function processEmail2faVerify(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden(\App\I18n\Translator::t('errors.csrf_invalid'));
        }

        $userId = $_SESSION['pending_2fa_user_id'] ?? null;
        if (!$userId) {
            header("Location: /login");
            exit;
        }
        $userId = (int)$userId;

        // DERSELBE Zaehler wie beim TOTP-Code, nicht ein eigener: Wer zwei
        // Verfahren hat, soll dadurch nicht doppelt so viele Rateversuche
        // bekommen. Die Versuchsgrenze JE CODE (EmailSecondFactor::
        // MAX_ATTEMPTS) kommt zusaetzlich dazu und verbraucht den Code.
        $res = \App\Security\RateLimiter::reserveAttempt((string)$userId, '2fa');
        if ($res === null) {
            $this->render('2fa_email_verify', [
                'title' => \App\I18n\Translator::t('meta.title_2fa_confirm'),
                'error' => \App\I18n\Translator::t('auth.rate_limited_2fa')
            ]);
            return;
        }

        // Zwischen Passwort und Code koennen Minuten liegen - das Konto kann
        // in dieser Zeit gesperrt worden sein (#358).
        $konto = $this->aktivesKonto($userId);
        if ($konto === null) {
            // Neutraler Ausstieg: nichts geprüft, nichts gezählt.
            \App\Security\RateLimiter::releaseAttempt($res);
            unset($_SESSION['pending_2fa_user_id']);
            header("Location: /login");
            exit;
        }

        if (
            !empty($konto['email_2fa_enabled'])
            && EmailSecondFactor::verify($userId, EmailSecondFactor::PURPOSE_LOGIN, (string)($_POST['code'] ?? ''))
        ) {
            \App\Security\RateLimiter::clearAttempts((string)$userId, '2fa');
            $this->afterSecondFactor($userId, '/admin');
        }

        // Fehlschlag: Die Buchung bleibt stehen.
        \App\Service\AuditLogger::log(
            "Anmeldecode abgelehnt",
            "auth",
            "Falscher, abgelaufener oder bereits verbrauchter Code",
            $userId,
            (string)$konto['username']
        );

        $this->render('2fa_email_verify', [
            'title' => \App\I18n\Translator::t('meta.title_2fa_confirm'),
            'error' => \App\I18n\Translator::t('auth.invalid_2fa_code')
        ]);
    }

    /**
     * Neuen Code anfordern. Loest den alten ab (Primaerschluessel der
     * Tabelle) und ist ueber denselben Topf gedrosselt wie der erste Versand.
     */
    public function resendEmail2faCode(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden(\App\I18n\Translator::t('errors.csrf_invalid'));
        }

        $userId = $_SESSION['pending_2fa_user_id'] ?? null;
        if (!$userId) {
            header("Location: /login");
            exit;
        }

        $konto = $this->aktivesKonto((int)$userId);
        if ($konto === null || empty($konto['email_2fa_enabled']) || empty($konto['email'])) {
            unset($_SESSION['pending_2fa_user_id']);
            header("Location: /login");
            exit;
        }

        $this->sendeAnmeldecode((int)$userId, (string)$konto['email']);
        exit;
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

        // Erst buchen, dann prüfen (Audit M20).
        $res = \App\Security\RateLimiter::reserveAttempt((string)$userId, 'backup');
        if ($res === null) {
            $this->render('2fa_backup', [
                'title' => 'Backup-Code verwenden',
                'error' => 'Zu viele fehlgeschlagene Versuche. Bitte versuchen Sie es in 15 Minuten erneut.'
            ]);
            return;
        }

        if ($this->aktivesKonto((int)$userId) === null) {
            // Account wurde vermutlich zwischen 2FA-Pending und Backup-Code-
            // Eingabe gelöscht oder gesperrt - neutraler Ausstieg.
            \App\Security\RateLimiter::releaseAttempt($res);
            unset($_SESSION['pending_2fa_user_id']);
            header("Location: /login");
            exit;
        }

        // Einlösen per Vergleich mit dem gelesenen Codesatz (Audit N43): Ein
        // Code gilt auch bei parallelen Anfragen nur einmal, und eine
        // laufende Einlösung überschreibt keinen inzwischen neu erzeugten
        // Satz.
        if (\App\Security\OneTimeProofs::redeemBackupCode((int)$userId, (string)($_POST['backup_code'] ?? ''))) {
            \App\Security\RateLimiter::clearAttempts((string)$userId, 'backup');
            $this->afterSecondFactor((int)$userId, '/admin?backup_code_used=1');
        }

        // Fehlschlag (falsch, verbraucht oder im Wettlauf verloren): Die
        // Buchung bleibt stehen.
        \App\Service\AuditLogger::log(
            "Backup-Code abgelehnt",
            "auth",
            "Falscher oder bereits verwendeter Backup-Code",
            (int)$userId
        );

        $this->render('2fa_backup', [
            'title' => 'Backup-Code verwenden',
            'error' => 'Ungültiger oder bereits verwendeter Backup-Code.'
        ]);
    }

    public function logout(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }
        // Ohne `user_id` gibt es nur einen laufenden Anmeldevorgang, etwa
        // "Abbrechen" auf der Passkey-Seite (Audit N86). Der Weg zurück führt
        // dann zur Anmeldung, nicht zur Startseite. Das Ziel wird VOR
        // session_destroy() bestimmt - danach ist die Sitzung leer.
        $nurAnmeldevorgang = !isset($_SESSION['user_id']);
        if ($nurAnmeldevorgang) {
            $pendingId = $_SESSION['pending_2fa_user_id'] ?? null;
            \App\Service\AuditLogger::log(
                "Anmeldevorgang abgebrochen",
                "auth",
                "Abbruch vor dem zweiten Faktor",
                $pendingId !== null ? (int)$pendingId : null
            );
        } else {
            \App\Service\AuditLogger::log("Benutzer ausgeloggt", "auth", "Erfolgreich abgemeldet");
        }
        session_destroy();
        header("Location: " . ($nurAnmeldevorgang ? '/login' : '/'));
        exit;
    }

    public function forgotPassword(): void {
        $this->render('auth_forgot_password', ['title' => \App\I18n\Translator::t('meta.title_forgot_password')]);
    }

    public function sendResetLink(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden(\App\I18n\Translator::t('errors.csrf_invalid'));
        }

        // Nach Absender-IP begrenzen: Ohne diese Sperre könnte jeder Client
        // unbegrenzt oft echten SMTP-Versand auslösen (E-Mail-Bombing eines
        // beliebigen Opfers, Missbrauch/Reputationsschaden des SMTP-Relays) -
        // unabhängig davon, ob die eingegebene E-Mail-Adresse überhaupt
        // existiert. Jeder POST zählt, die Buchung wird nie freigegeben. Bei
        // IPv6 zählt das /64 (Audit M7).
        $clientKey = \App\Security\ClientIp::rateLimitKey();
        if (\App\Security\RateLimiter::reserveAttempt($clientKey, 'password_reset') === null) {
            $this->render('auth_forgot_password', [
                'title' => \App\I18n\Translator::t('meta.title_forgot_password'),
                'error' => \App\I18n\Translator::t('auth.rate_limited_password_reset')
            ]);
            return;
        }

        // Untergrenze für die Antwortzeit, siehe unten.
        $startedAt = microtime(true);

        $email = trim($_POST['email'] ?? '');
        if (
            !empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)
            // Zusätzlich je EMPFÄNGER begrenzt (Audit M7): Wer viele
            // Adressen hat, umging die IP-Grenze und flutete ein Postfach
            // trotzdem. Über der Grenze wird STILL nichts erzeugt - Antwort
            // und Antwortzeit bleiben gleich, die Route wird kein Orakel.
            // Gezählt wird nur ein Abdruck der Adresse: login_attempts wird
            // nie aufgeräumt, und beliebige eingetippte Fremdadressen haben
            // dort im Klartext nichts verloren.
            && \App\Security\RateLimiter::reserveAttempt(
                self::resetEmpfaengerKey($email),
                self::RESET_EMPFAENGER_TYP,
                self::RESET_EMPFAENGER_MAX,
                self::RESET_EMPFAENGER_FENSTER
            ) !== null
        ) {
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

    /** Reset-Mails je Empfängeradresse und Stunde (Audit M7). */
    public const RESET_EMPFAENGER_TYP = 'password_reset_to';
    public const RESET_EMPFAENGER_MAX = 3;
    public const RESET_EMPFAENGER_FENSTER = 3600;

    /**
     * Zählerschlüssel einer Empfängeradresse: SHA-256 der kleingeschriebenen
     * Adresse, nie die Adresse selbst. Kleingeschrieben, weil die Suche in
     * der Datenbank ohne Rücksicht auf Gross-/Kleinschreibung trifft - sonst
     * brächte jede Schreibweise drei neue Mails.
     */
    public static function resetEmpfaengerKey(string $email): string {
        return 'mail:' . hash('sha256', mb_strtolower(trim($email), 'UTF-8'));
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

        // Das Konto VOR dem UPDATE: Sein offener Adressantrag endet im
        // selben Statement (Audit M16), und fuer das Protokoll muss bekannt
        // sein, ob es einen gab.
        $stmt = $db->prepare("SELECT id, username FROM users WHERE email = ? AND deleted_at IS NULL AND deactivated_at IS NULL");
        $stmt->execute([$reset['email']]);
        $account = $stmt->fetch();
        $offenerAntrag = $account ? KontoSicherheit::offenerAdressantrag((int)$account['id']) : null;

        // Update user's password hash. session_version wird erhöht, damit alle
        // bestehenden Sessions dieses Benutzers sofort ungültig werden (#113) -
        // gerade der Passwort-Reset ist die typische Reaktion auf einen
        // Kompromittierungsverdacht.
        // Der Filter gehört AUCH hier hin (#358), nicht nur in die Abfrage
        // weiter unten: Ein Reset-Link, der vor der Sperre verschickt wurde,
        // ist danach noch bis zu 15 Minuten gültig. Ohne den Filter setzte er
        // dem gesperrten Konto ein frisches Passwort - und die Sperre haette
        // ein Zeitfenster, in dem sie sich aushebeln laesst.
        //
        // Der Reset-Link bestaetigt zugleich die Adresse (Audit N54): Er
        // beweist die Kontrolle ueber das Postfach genauso wie der
        // Bestaetigungslink der Selbstregistrierung. Hat jemand ein Konto auf
        // eine fremde Adresse registriert, uebernimmt der Postfachinhaber es
        // damit samt eigenem Passwort.
        $stmt = $db->prepare(
            "UPDATE users SET password_hash = ?, session_version = session_version + 1,
                    email_verification_token = NULL, email_verification_expires_at = NULL,
                    " . KontoSicherheit::ADRESSANTRAG_LEEREN . "
             WHERE email = ? AND deleted_at IS NULL AND deactivated_at IS NULL"
        );
        $stmt->execute([$newPasswordHash, $reset['email']]);

        // Auch alle API-Schlüssel des Kontos ausdrücklich widerrufen (#217):
        // Die session_version-Kopplung in ApiKey::authenticate() lehnt ältere
        // Schlüssel nach der Erhöhung oben bereits ab - der Widerruf macht das
        // zusätzlich dauerhaft und in der Schlüsselverwaltung sichtbar. Ein
        // Schlüssel darf den Passwort-Reset (die typische Reaktion auf einen
        // Kompromittierungsverdacht) nicht als zweites Credential überleben.
        if ($account) {
            // Ebenso ein noch unterwegs befindlicher Anmeldecode (#354) und
            // ein offener Adressantrag (Audit M16) - siehe
            // KontoSicherheit::nachPasswortwechsel().
            $revokedKeys = KontoSicherheit::nachPasswortwechsel((int)$account['id'], $offenerAntrag);
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
            'title' => 'Neues Passwort festlegen'
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
        // Erst buchen, dann prüfen (Audit M20). Typ siehe
        // FORCE_PW_LIMITER_TYPE - mit dem alten, zu langen Namen griff diese
        // Sperre nie (Audit M8).
        $res = \App\Security\RateLimiter::reserveAttempt((string)$userId, self::FORCE_PW_LIMITER_TYPE);
        if ($res === null) {
            $this->render('auth_force_password_change', [
                'title' => 'Neues Passwort festlegen',
                'error' => 'Zu viele Fehlversuche. Bitte versuchen Sie es später erneut.'
            ]);
            return;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ? AND deleted_at IS NULL AND deactivated_at IS NULL");
        $stmt->execute([$userId]);
        $currentHash = (string)$stmt->fetchColumn();

        if ($currentHash === '' || !password_verify($currentPassword, $currentHash)) {
            // Fehlversuch: Die Buchung bleibt stehen.
            \App\Service\AuditLogger::log(
                "Erzwungener Passwortwechsel abgelehnt",
                "auth",
                "Bisheriges Passwort falsch",
                (int)$userId,
                $_SESSION['username'] ?? null
            );

            $this->render('auth_force_password_change', [
                'title' => 'Neues Passwort festlegen',
                'error' => 'Das bisherige Passwort ist nicht korrekt.'
            ]);
            return;
        }

        if (strlen($password) < 8 || $password !== $passwordConfirm) {
            // Das bisherige Passwort war richtig - kein Rateversuch.
            \App\Security\RateLimiter::releaseAttempt($res);
            $this->render('auth_force_password_change', [
                'title' => 'Neues Passwort festlegen',
                'error' => 'Die Passwörter stimmen nicht überein oder sind zu kurz (mindestens 8 Zeichen).'
            ]);
            return;
        }

        \App\Security\RateLimiter::clearAttempts((string)$userId, self::FORCE_PW_LIMITER_TYPE);

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $offenerAntrag = KontoSicherheit::offenerAdressantrag((int)$userId);
        // session_version erhöhen, damit andere bestehende Sessions dieses
        // Benutzers ungültig werden (#113) - die eigene, gerade aktive Session
        // übernimmt den neuen Stand direkt und bleibt angemeldet. Ein offener
        // Adressantrag endet im selben Statement (Audit M16).
        $stmt = $db->prepare(
            "UPDATE users SET password_hash = ?, must_change_password = 0, session_version = session_version + 1,
                    " . KontoSicherheit::ADRESSANTRAG_LEEREN . "
             WHERE id = ?"
        );
        $stmt->execute([$hash, $userId]);

        // Offene Mailcodes verwerfen (#354) und alle API-Schlüssel des
        // Kontos ausdrücklich widerrufen (#217) - dieselbe Begründung wie in
        // updatePassword(): Die session_version-Kopplung invalidiert sie
        // bereits implizit, der Widerruf macht es dauerhaft und sichtbar
        // (revoked_at).
        $revokedKeys = KontoSicherheit::nachPasswortwechsel((int)$userId, $offenerAntrag);
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
