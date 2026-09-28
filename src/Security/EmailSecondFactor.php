<?php
// src/Security/EmailSecondFactor.php

namespace App\Security;

use App\Database;

/**
 * Einmalcode per E-Mail als zweiter Faktor (#354).
 *
 * EINORDNUNG, DIE NICHT VERSCHWEIGEN WERDEN DARF. Das ist der schwaechste
 * der gaengigen zweiten Faktoren: Wer Zugriff auf das Postfach hat, hat den
 * Faktor. Er schuetzt gegen gestohlene Passwortlisten, kaum gegen einen
 * uebernommenen Mailzugang. Gebaut wird er trotzdem, weil er fuer viele der
 * einzige zweite Faktor ist, den sie tatsaechlich einrichten - aber er wird
 * ehrlich beschriftet und Administratoren nicht angeboten (siehe
 * SecondFactors::emailFactorAllowedFor()).
 *
 * WAS GESPEICHERT WIRD. Nur der Abdruck des Codes, nie der Code selbst -
 * dasselbe Verfahren wie bei den Backup-Codes. `password_hash()` und nicht
 * SHA-256, obwohl der Code nur sechs Stellen hat: GERADE deshalb. Eine
 * Million Moeglichkeiten sind mit einem schnellen Hash in Sekunden
 * durchprobiert, wenn die Tabelle einmal in fremde Haende geraet; mit bcrypt
 * dauert derselbe Durchlauf laenger als die zehn Minuten Gueltigkeit. (Bei
 * den Reset-Token ist es umgekehrt richtig: 256 Bit aus random_bytes() sind
 * nicht zu erraten, dort genuegt SHA-256.)
 *
 * WARUM EIN VERWENDUNGSZWECK IN DER TABELLE. Derselbe Mechanismus traegt
 * zwei Vorgaenge: den Faktor bei der Anmeldung und den Probecode beim
 * Einschalten. Ohne die Unterscheidung liesse sich ein Probecode als
 * Anmeldefaktor einloesen - beides geht zwar an dieselbe Adresse, aber ein
 * Nachweis gilt fuer den Vorgang, fuer den er ausgestellt wurde, und fuer
 * keinen anderen. Der Primaerschluessel (user_id, purpose) sorgt zugleich
 * dafuer, dass es je Vorgang immer nur EINEN gueltigen Code gibt: Ein neu
 * angeforderter loest den alten ab.
 */
final class EmailSecondFactor {

    /** Anmeldung: zweiter Faktor nach dem Passwort. */
    public const PURPOSE_LOGIN = 'login';

    /** Einschalten: Probecode, der einmal richtig eingegeben werden muss. */
    public const PURPOSE_SETUP = 'setup';

    public const CODE_LENGTH = 6;

    /** Gueltigkeit in Sekunden. */
    public const TTL_SECONDS = 600;

    /**
     * Versuche je Code. Danach ist er verbraucht - nicht nur gesperrt: Ein
     * Zaehler, der nur bremst, laesst den Code weiterleben, und die naechste
     * Runde faengt von vorn an.
     */
    public const MAX_ATTEMPTS = 5;

    /** Neuanforderungen je Konto im Fenster (Rate-Limiter-Typ unten). */
    public const RESEND_MAX = 3;
    public const RESEND_WINDOW = 900;
    public const RESEND_LIMITER_TYPE = '2fa_email_send';

    private function __construct() {}

    /**
     * Erzeugt einen Code, legt seinen Abdruck ab und gibt den Klartext
     * zurueck - der Aufrufer versendet ihn und vergisst ihn.
     */
    public static function issue(int $userId, string $purpose): string {
        $code = str_pad((string)random_int(0, 999999), self::CODE_LENGTH, '0', STR_PAD_LEFT);

        $db = Database::getInstance();
        $stmt = $db->prepare(
            'REPLACE INTO email_2fa_codes (user_id, purpose, code_hash, expires_at, attempts)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), 0)'
        );
        $stmt->execute([$userId, $purpose, password_hash($code, PASSWORD_DEFAULT), self::TTL_SECONDS]);

        return $code;
    }

    /**
     * Prueft einen eingegebenen Code und verbraucht ihn bei Erfolg.
     *
     * Abgelaufene und eingeloeste Codes werden geloescht: Was nicht mehr
     * gelten soll, bleibt nicht als Zeile liegen. Aufgebrauchte bleiben bis
     * zum Ablauf stehen und gelten nicht mehr (siehe discardInvalid()).
     *
     * ATOMAR UND AN DEN CODE GEBUNDEN (Audit M20, N43). Bisher: lesen,
     * pruefen, bei Fehlschlag den Zaehler erhoehen, bei Erfolg die Zeile
     * loeschen. Parallele Anfragen sahen alle denselben Zaehlerstand und
     * bekamen zusammen weit mehr als MAX_ATTEMPTS Versuche; ein richtiger Code
     * galt mehrfach; und das Loeschen nach (user_id, purpose) raeumte auch
     * einen inzwischen NEU ausgestellten Code ab. Jetzt:
     *
     * 1. Der Versuch wird VOR der Pruefung gebucht - nur, solange die Grenze
     *    nicht erreicht ist, und nur fuer genau den gelesenen Abdruck. Wer die
     *    Buchung nicht bekommt, prueft gar nicht erst.
     * 2. Eingeloest ist der Code nur, wenn das Loeschen GENAU dieses Abdrucks
     *    eine Zeile trifft. Eine zweite parallele Einloesung findet nichts
     *    mehr, und ein neu ausgestellter Code hat einen anderen Abdruck.
     */
    public static function verify(int $userId, string $purpose, string $code): bool {
        $code = preg_replace('/\s+/u', '', trim($code)) ?? '';
        if ($code === '') {
            return false;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT code_hash FROM email_2fa_codes
             WHERE user_id = ? AND purpose = ? AND expires_at > NOW() AND attempts < ?'
        );
        $stmt->execute([$userId, $purpose, self::MAX_ATTEMPTS]);
        $hash = $stmt->fetchColumn();

        if (!is_string($hash) || $hash === '') {
            self::discardInvalid($userId, $purpose);
            return false;
        }

        $stmt = $db->prepare(
            'UPDATE email_2fa_codes SET attempts = attempts + 1
             WHERE user_id = ? AND purpose = ? AND code_hash = ? AND attempts < ? AND expires_at > NOW()'
        );
        $stmt->execute([$userId, $purpose, $hash, self::MAX_ATTEMPTS]);
        if ($stmt->rowCount() !== 1) {
            self::discardInvalid($userId, $purpose);
            return false;
        }

        if (!password_verify($code, $hash)) {
            return false;
        }

        $stmt = $db->prepare('DELETE FROM email_2fa_codes WHERE user_id = ? AND purpose = ? AND code_hash = ?');
        $stmt->execute([$userId, $purpose, $hash]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Raeumt abgelaufene Zeilen ab. Ein gueltiger, vielleicht gerade neu
     * ausgestellter Code bleibt stehen.
     *
     * Aufgebrauchte Codes (attempts = MAX_ATTEMPTS) bleiben bis zum Ablauf
     * liegen, gelten aber nirgends mehr (verify(), pending()). Sie sofort zu
     * loeschen hiesse, einer parallelen Pruefung, die ihre Buchung noch
     * bekommen hat, die Zeile unter den Fuessen wegzuziehen - dann haette
     * unter einem Burst auch der richtige Code keine Chance mehr.
     */
    private static function discardInvalid(int $userId, string $purpose): void {
        try {
            Database::getInstance()->prepare(
                'DELETE FROM email_2fa_codes WHERE user_id = ? AND purpose = ? AND expires_at <= NOW()'
            )->execute([$userId, $purpose]);
        } catch (\Throwable $e) {
            // Aufraeumen ist nachrangig; die Zeile gilt ohnehin nicht mehr.
        }
    }

    /**
     * Verwirft offene Codes.
     *
     * Ohne Zweck: alle. Das ist der Aufruf, der zu einem Passwortwechsel
     * gehoert - dort werden Sitzungen und API-Schluessel ungueltig (#113,
     * #217), und ein noch unterwegs befindlicher Mailcode gehoert in
     * denselben Zug (#354).
     */
    public static function discard(int $userId, ?string $purpose = null): void {
        try {
            $db = Database::getInstance();
            if ($purpose === null) {
                $stmt = $db->prepare('DELETE FROM email_2fa_codes WHERE user_id = ?');
                $stmt->execute([$userId]);
                return;
            }
            $stmt = $db->prepare('DELETE FROM email_2fa_codes WHERE user_id = ? AND purpose = ?');
            $stmt->execute([$userId, $purpose]);
        } catch (\Throwable $e) {
            // Aufraeumen darf keinen Anmelde- oder Profilvorgang abbrechen.
            // Ein liegengebliebener Code laeuft nach TTL_SECONDS von selbst ab.
        }
    }

    /**
     * Liegt fuer diesen Vorgang ein noch gueltiger Code bereit?
     * Steuert die Oberflaeche ("Code erneut senden" statt "Code anfordern").
     */
    public static function pending(int $userId, string $purpose): bool {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare(
                'SELECT 1 FROM email_2fa_codes
                 WHERE user_id = ? AND purpose = ? AND expires_at > NOW() AND attempts < ?'
            );
            $stmt->execute([$userId, $purpose, self::MAX_ATTEMPTS]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
