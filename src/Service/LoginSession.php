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
 *
 * Seit Audit M24/N14 ist die Klasse auch die EINZIGE Stelle, die beantwortet,
 * welches Konto eine Sitzung trägt:
 * - validate() ist die Gültigkeitsregel (Installationsepoche, gelöscht,
 *   deaktiviert, session_version, User-Agent, Inaktivität), ohne
 *   Seiteneffekte. BaseController::checkAuth() setzt sie mit Weiterleitung
 *   und Rotation durch.
 * - currentUserId() ist die leichte Form für Stellen ohne checkAuth():
 *   öffentliche Seiten, FeatureGate, Hooks, Bildauslieferung.
 * - forgetIdentity() ist die eine Liste der identitätsgebundenen Schlüssel.
 * - beginSecondFactor() eröffnet die halbe Anmeldung samt Epoche.
 */
class LoginSession {

    /** Gründe, aus denen validate() eine Sitzung verwirft. */
    public const ANONYM = 'anonym';
    public const INSTALLATION = 'installation';
    public const GELOESCHT = 'geloescht';
    public const DEAKTIVIERT = 'deaktiviert';
    public const PASSWORT_GEAENDERT = 'passwort_geaendert';
    public const FREMDER_BROWSER = 'fremder_browser';
    public const INAKTIV = 'inaktiv';

    /** Inaktivitäts-Timeout in Sekunden (2 Stunden). */
    public const MAX_INAKTIVITAET = 7200;

    /**
     * Identitätsgebundene Sitzungsschlüssel. Wer eine Identität verwirft
     * (neuer Login, ungültige Sitzung, neue Installation), verwirft sie alle.
     * Bewusst NICHT dabei: csrf_token und locale - sie gehören zur Sitzung,
     * nicht zum Konto.
     *
     * - profile_new_backup_codes und api_key_new_token enthalten Klartext
     *   (frisch erzeugte Backup-Codes, frisch erzeugter API-Token) und dürfen
     *   einer fremden Identität nie angezeigt werden.
     * - passkey_bestanden ist der Nachweis des zweiten Faktors einer
     *   Passkey-Anmeldung (PasskeyController::anmeldungPruefen()).
     */
    private const IDENTITAETS_SCHLUESSEL = [
        'user_id',
        'username',
        'user_agent_hash',
        'session_version',
        InstallEpoch::SETTING,
        'pending_2fa_user_id',
        'zweiter_faktor_bestanden',
        'passkey_bestanden',
        'anmeldeweg',
        'twofa_reauth',
        'passkey_stepup',
        'passkey_registrierung',
        'totp_setup',
        \App\Security\BackupCodes::SESSION,
        'api_key_new_token',
        'must_change_password',
        'last_activity',
        'last_token_rotation',
        'created_time',
    ];

    /**
     * Legt die Session an und leitet weiter (beendet den Request).
     */
    public static function establish(int $userId, string $redirectSuccess = '/admin'): void {
        $db = Database::getInstance();
        // Die Installationsepoche (Audit M24) kommt in derselben Abfrage mit.
        $stmt = $db->prepare(
            "SELECT username, must_change_password, session_version,
                    (SELECT s.setting_value FROM settings s WHERE s.setting_key = ?) AS install_epoch
             FROM users WHERE id = ?"
        );
        $stmt->execute([InstallEpoch::SETTING, $userId]);
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
        // Bindet die Sitzung an diese Installation (Audit M24, siehe
        // InstallEpoch). Fehlt die Zeile, gilt '' - nach der nächsten
        // Erneuerung passt das zu keiner Epoche mehr.
        $_SESSION[InstallEpoch::SETTING] = (string)($userRow['install_epoch'] ?? '');

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
     * Eröffnet die halbe Anmeldung: Faktor 1 ist erbracht, der zweite steht
     * aus. Die Installationsepoche wird mit gemerkt (Audit M24) - sonst galt
     * eine vor einem Reset begonnene 2FA-Einrichtung danach für das neue
     * Konto mit derselben ID.
     *
     * $epoche übergibt nur SetupController::provision(): Dort ist die
     * Datenbank, in der das Konto soeben entstanden ist, noch nicht die von
     * App\Database. Alle anderen lesen die aktuelle Epoche selbst.
     */
    public static function beginSecondFactor(int $userId, ?string $epoche = null): void {
        if ($epoche === null) {
            try {
                $epoche = InstallEpoch::current(Database::getInstance()) ?? '';
            } catch (\Throwable $e) {
                $epoche = '';
            }
        }
        $_SESSION['pending_2fa_user_id'] = $userId;
        $_SESSION[InstallEpoch::SETTING] = $epoche;
    }

    /**
     * Verwirft die Identität der Sitzung, ohne die Sitzung selbst zu beenden.
     * csrf_token und locale bleiben: Eine öffentliche Seite rendert danach
     * normal weiter, nur eben für einen Gast.
     */
    public static function forgetIdentity(): void {
        foreach (self::IDENTITAETS_SCHLUESSEL as $schluessel) {
            unset($_SESSION[$schluessel]);
        }
    }

    /**
     * Die Gültigkeitsregel einer angemeldeten Sitzung - ohne Seiteneffekte.
     *
     * null heißt gültig, sonst kommt einer der Gründe (Konstanten oben).
     * Reihenfolge wie bisher in BaseController::checkAuth(), vorn ergänzt um
     * die Installationsepoche: Nach einem Reset kann die ID einem ganz
     * anderen, gültigen Konto gehören, dessen Zeile also nichts verrät.
     *
     * Eine einzige Abfrage liest Konto UND Epoche; der LEFT JOIN liefert auch
     * dann eine Zeile, wenn das Konto gar nicht mehr existiert. Bei einem
     * Datenbankfehler entfallen die Datenbankprüfungen (fail-open wie bisher:
     * ein DB-Aussetzer darf angemeldete Benutzer nicht aussperren).
     * User-Agent und Inaktivität werden nur gelesen, nie geschrieben.
     */
    public static function validate(): ?string {
        if (!isset($_SESSION['user_id'])) {
            return self::ANONYM;
        }
        $userId = (int)$_SESSION['user_id'];

        try {
            $stmt = Database::getInstance()->prepare(
                "SELECT e.install_epoch, u.id, u.deleted_at, u.deactivated_at, u.session_version
                 FROM (SELECT (SELECT s.setting_value FROM settings s WHERE s.setting_key = ?) AS install_epoch) e
                 LEFT JOIN users u ON u.id = ?"
            );
            $stmt->execute([InstallEpoch::SETTING, $userId]);
            $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $zeile = null;
        }

        if (is_array($zeile)) {
            if (!InstallEpoch::matches((string)($zeile['install_epoch'] ?? ''))) {
                return self::INSTALLATION;
            }
            // Zwei Zustände, zwei Meldungen (#358). Gelöscht geht vor.
            if ($zeile['id'] === null || $zeile['deleted_at'] !== null) {
                return self::GELOESCHT;
            }
            if ($zeile['deactivated_at'] !== null) {
                return self::DEAKTIVIERT;
            }
            // Session-Invalidierung bei Passwortänderung (#113): Sessions
            // ohne gemerkten Stand gelten ebenfalls als veraltet.
            $sessionVersion = $_SESSION['session_version'] ?? null;
            if ($sessionVersion === null || (int)$sessionVersion !== (int)($zeile['session_version'] ?? 1)) {
                return self::PASSWORT_GEAENDERT;
            }
        }

        // Anti-Infostealer: User-Agent-Fingerprint.
        $agentHash = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
        if (isset($_SESSION['user_agent_hash']) && !hash_equals((string)$_SESSION['user_agent_hash'], $agentHash)) {
            return self::FREMDER_BROWSER;
        }

        if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity'] > self::MAX_INAKTIVITAET)) {
            return self::INAKTIV;
        }

        return null;
    }

    /**
     * ID des gültig angemeldeten Benutzers oder null - die leichte Form von
     * BaseController::checkAuth() für Stellen ohne Anmeldeschranke
     * (öffentliche Seiten, FeatureGate, Hooks, Bildauslieferung).
     *
     * Dieselbe Regel (validate()), aber bewusst ohne Weiterleitung, ohne
     * Sitzungsrotation, ohne session_destroy() und ohne last_activity-
     * Update: Die aufrufende Seite rendert weiter, braucht die Sitzung etwa
     * noch für das CSRF-Token, und parallele Nebenanfragen (Bilder) dürfen
     * sich nicht gegenseitig die Sitzungs-ID wegrotieren (Audit N44).
     *
     * Ist die Sitzung ungültig, wird das protokolliert und die Identität
     * verworfen - einmal, danach ist die Sitzung anonym. Kein statischer
     * Cache: Der Aufrufer (z. B. BaseController::currentUserId()) cacht
     * selbst, wo es sich lohnt.
     */
    public static function currentUserId(): ?int {
        if (!isset($_SESSION['user_id'])) {
            return null;
        }

        $grund = self::validate();
        if ($grund === null) {
            return (int)$_SESSION['user_id'];
        }

        self::protokolliere($grund, 'ohne Anmeldeschranke erkannt');
        self::forgetIdentity();
        return null;
    }

    /**
     * Audit-Eintrag für eine verworfene Sitzung. Muss VOR dem Verwerfen
     * laufen, solange die Sitzung ihr Konto noch nennt.
     *
     * Beim Grund INSTALLATION wird der Eintrag ausdrücklich KEINEM Konto
     * zugeordnet: Die ID der Alt-Sitzung gehört nach einem Reset womöglich
     * einem neuen, fremden Konto.
     */
    public static function protokolliere(string $grund, ?string $zusatz = null): void {
        $id = $_SESSION['user_id'] ?? $_SESSION['pending_2fa_user_id'] ?? $_SESSION['passkey_bestanden'] ?? null;
        $detail = $grund === self::FREMDER_BROWSER
            ? 'User-Agent Mismatch für User ID ' . $id
            : 'User ID ' . $id;
        if ($zusatz !== null) {
            $detail .= " ({$zusatz})";
        }

        if ($grund === self::INSTALLATION) {
            AuditLogger::log(self::auditText($grund), 'auth', $detail, null, 'SYSTEM');
            return;
        }
        AuditLogger::log(self::auditText($grund), 'auth', $detail);
    }

    /** Text des Audit-Eintrags je Grund - wortgleich mit den bisherigen. */
    public static function auditText(string $grund): string {
        return match ($grund) {
            self::INSTALLATION => 'Session beendet: Installation wurde zurückgesetzt',
            self::GELOESCHT => 'Session beendet: Benutzerkonto gelöscht',
            self::DEAKTIVIERT => 'Session beendet: Benutzerkonto deaktiviert',
            self::PASSWORT_GEAENDERT => 'Session beendet: Passwort wurde geändert',
            self::FREMDER_BROWSER => 'Session-Hijacking Versuch abgefangen',
            self::INAKTIV => 'Session wegen Inaktivität beendet',
            default => 'Session beendet',
        };
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
