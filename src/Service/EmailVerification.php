<?php
// src/Service/EmailVerification.php

namespace App\Service;

use App\Database;
use App\Security\RateLimiter;
use PDO;

/**
 * Bestätigung der E-Mail-Adresse bei der Selbstregistrierung (#83, Audit N54).
 *
 * WORUM ES GEHT. Der Bestätigungslink gilt 48 Stunden. Danach war das Konto
 * eine Sackgasse: Die Anmeldung blieb gesperrt, einen neuen Link gab es
 * nicht, und Benutzername und Adresse blieben dauerhaft belegt (beide
 * UNIQUE) - auch dann, wenn jemand ein Konto auf die Adresse eines Dritten
 * registriert hatte. "Registrieren Sie sich erneut" scheiterte genau daran.
 *
 * DREI WEGE HINAUS.
 *  - Wer sich mit korrektem Passwort anmeldet, bekommt einen aktuellen Link
 *    (erneutSenden()), gedrosselt auf RESEND_MAX je Tag.
 *  - Ein Passwort-Reset per Mail-Link bestätigt die Adresse ebenfalls
 *    (AuthController::updatePassword()) - er beweist dasselbe.
 *  - Was nach MAX_UNVERIFIED_DAYS noch unbestätigt ist, löscht die tägliche
 *    Aufgabe (run()) und gibt Benutzername und Adresse wieder frei.
 *
 * WARUM DIE FRIST AB `created_at` LÄUFT. Eine Frist nach
 * `email_verification_expires_at` wäre aushebelbar: Genau dieses Feld
 * verlängert der Neuversand, und wer eine fremde Adresse belegt, kennt das
 * Passwort. Er müsste sich nur alle paar Tage anmelden, und das Konto würde
 * nie gelöscht. Deshalb reicht auch kein neuer Link über die
 * Höchstlebensdauer hinaus.
 */
final class EmailVerification {

    /** Gültigkeit eines Bestätigungslinks in Stunden. */
    public const TTL_HOURS = 48;

    /**
     * Höchstlebensdauer eines unbestätigten Kontos ab Registrierung, in
     * Tagen: 48 Stunden Link plus 7 Tage Kulanz.
     */
    public const MAX_UNVERIFIED_DAYS = 9;

    /**
     * Drossel für den Neuversand - pro Tag, nicht pro Stunde: Wer eine fremde
     * Adresse belegt, kann den Versand in DEREN Postfach auslösen.
     */
    public const RESEND_LIMITER_TYPE = 'verify_resend';
    public const RESEND_MAX = 3;
    public const RESEND_WINDOW = 86400;

    public const TASK_NAME = 'users.purge_unverified';

    /**
     * Höchstzahl je Lauf - als Bremse, nicht aus Leistungsgründen (siehe
     * DormantAccountService::MAX_PER_RUN).
     */
    public const MAX_PER_RUN = 500;

    private function __construct() {}

    /**
     * Ein neues Token und sein Ablauf (Y-m-d H:i:s).
     *
     * @return array{0: string, 1: string}
     */
    public static function neuesToken(): array {
        return [bin2hex(random_bytes(32)), date('Y-m-d H:i:s', time() + self::TTL_HOURS * 3600)];
    }

    /**
     * Schickt einem unbestätigten Konto einen aktuellen Link.
     *
     * NUR NACH GEPRÜFTEM PASSWORT aufrufen (AuthController::loginSubmit()):
     * Dann ist das weder ein Orakel für Dritte noch ein Weg, fremde Postfächer
     * zu fluten. Ein noch gültiges Token wird wiederverwendet - sonst machte
     * jede Anmeldung den Link aus der vorigen Mail ungültig.
     *
     * @param array<string, mixed> $konto id, email, created_at,
     *        email_verification_token, email_verification_expires_at
     * @return bool true, wenn eine Mail verschickt wurde
     */
    public static function erneutSenden(array $konto): bool {
        $userId = (int)($konto['id'] ?? 0);
        $email = trim((string)($konto['email'] ?? ''));
        $bisherigesToken = (string)($konto['email_verification_token'] ?? '');
        if ($userId <= 0 || $email === '' || $bisherigesToken === '') {
            return false;
        }

        // Höchstlebensdauer erreicht: kein Versand mehr, das Konto wartet auf
        // die Bereinigung. Ohne lesbares created_at fail-closed dasselbe.
        $erstellt = strtotime((string)($konto['created_at'] ?? ''));
        if ($erstellt === false) {
            return false;
        }
        $spaetestens = $erstellt + self::MAX_UNVERIFIED_DAYS * 86400;
        if (time() >= $spaetestens) {
            return false;
        }

        if (RateLimiter::tooManyAttempts((string)$userId, self::RESEND_LIMITER_TYPE, self::RESEND_MAX, self::RESEND_WINDOW)) {
            return false;
        }
        RateLimiter::recordAttempt((string)$userId, self::RESEND_LIMITER_TYPE);

        try {
            $token = $bisherigesToken;
            $ablauf = strtotime((string)($konto['email_verification_expires_at'] ?? ''));
            if ($ablauf === false || $ablauf <= time()) {
                $token = bin2hex(random_bytes(32));
                $neuerAblauf = min(time() + self::TTL_HOURS * 3600, $spaetestens);

                // Compare-and-swap: Zwei gleichzeitige Anmeldungen dürfen
                // nicht zwei Links erzeugen, von denen nur einer gilt.
                $stmt = Database::getInstance()->prepare(
                    "UPDATE users SET email_verification_token = ?, email_verification_expires_at = ?
                     WHERE id = ? AND email_verification_token = ?"
                );
                $stmt->execute([$token, date('Y-m-d H:i:s', $neuerAblauf), $userId, $bisherigesToken]);
                if ($stmt->rowCount() === 0) {
                    return false;
                }
            }

            $versandt = (new Mailer())->sendEmailVerification($email, $token);
        } catch (\Throwable $e) {
            return false;
        }

        AuditLogger::log(
            'Bestätigungslink erneut versendet',
            'auth',
            $versandt
                ? 'Anmeldung eines unbestätigten Kontos mit korrektem Passwort'
                : 'Anmeldung eines unbestätigten Kontos - der Mailversand schlug fehl',
            $userId,
            isset($konto['username']) ? (string)$konto['username'] : null
        );

        return $versandt;
    }

    /**
     * Meldet die tägliche Bereinigung an. Bewusst ohne Datenbankzugriff - das
     * läuft im Bootstrap JEDES Requests (siehe public/index.php).
     */
    public static function registerScheduledTask(): void {
        Scheduler::register(self::TASK_NAME, 86400, [self::class, 'run']);
    }

    /**
     * Die Bedingung "unbestätigt und über der Höchstlebensdauer" - einmal,
     * damit Auswahl und DELETE nicht auseinanderlaufen.
     *
     * Mitglieder der Gruppe `admin` sind ausgenommen: Ein Admin-Konto löscht
     * keine Automatik, auch kein unbestätigtes. Gesetztes Token mit leerem
     * Ablauf zählt mit - ein solches Konto ist nie bestätigbar.
     */
    private static function faelligBedingung(string $alias = 'u'): string {
        return "{$alias}.email_verification_token IS NOT NULL
               AND {$alias}.email_verification_token <> ''
               AND {$alias}.created_at < NOW() - INTERVAL " . self::MAX_UNVERIFIED_DAYS . " DAY
               AND ({$alias}.email_verification_expires_at IS NULL OR {$alias}.email_verification_expires_at < NOW())
               AND NOT EXISTS (
                   SELECT 1 FROM user_groups ug
                   JOIN `groups` g ON g.id = ug.group_id AND g.slug = 'admin'
                   WHERE ug.user_id = {$alias}.id
               )";
    }

    /**
     * Der tägliche Lauf: unbestätigte Konten endgültig löschen.
     *
     * HART, nicht in den Papierkorb: Nur ein DELETE gibt die UNIQUE-Werte
     * frei, und genau darum geht es. Alle Fremdschlüssel auf users sind
     * ON DELETE CASCADE; auch weich gelöschte und deaktivierte unbestätigte
     * Konten fallen darunter.
     *
     * @return array{geloescht: int, fehlgeschlagen: int}
     */
    public static function run(): array {
        $db = Database::getInstance();

        $stmt = $db->query(
            "SELECT u.id, u.username FROM users u
             WHERE " . self::faelligBedingung('u') . "
             ORDER BY u.id ASC
             LIMIT " . self::MAX_PER_RUN
        );
        $kandidaten = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        // Die Bedingung steht im DELETE noch einmal: Zwischen Auswahl und
        // Löschung kann jemand bestätigt oder zum Admin gemacht worden sein.
        $loeschen = $db->prepare("DELETE u FROM users u WHERE u.id = ? AND " . self::faelligBedingung('u'));

        $geloescht = [];
        $fehlgeschlagen = 0;
        foreach ($kandidaten as $konto) {
            try {
                $loeschen->execute([(int)$konto['id']]);
                if ($loeschen->rowCount() > 0) {
                    $geloescht[] = (string)$konto['username'];
                }
            } catch (\Throwable $e) {
                // Etwa ein Addon-Fremdschlüssel ohne CASCADE: Dieses Konto
                // bleibt, der Rest des Laufs nicht.
                $fehlgeschlagen++;
            }
        }

        if ($geloescht !== [] || $fehlgeschlagen > 0) {
            AuditLogger::log(
                'Unbestätigte Konten gelöscht',
                'users',
                sprintf(
                    '%d Konto/Konten ohne bestätigte E-Mail-Adresse %d Tage nach der Registrierung endgültig gelöscht%s%s',
                    count($geloescht),
                    self::MAX_UNVERIFIED_DAYS,
                    $geloescht !== []
                        ? ': ' . implode(', ', array_slice($geloescht, 0, 20)) . (count($geloescht) > 20 ? ' …' : '')
                        : '',
                    $fehlgeschlagen > 0 ? "; {$fehlgeschlagen} Löschung(en) fehlgeschlagen" : ''
                )
            );
        }

        return ['geloescht' => count($geloescht), 'fehlgeschlagen' => $fehlgeschlagen];
    }
}
