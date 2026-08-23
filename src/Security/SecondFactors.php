<?php
// src/Security/SecondFactors.php

namespace App\Security;

use App\Database;

/**
 * Welche zweiten Faktoren hat ein Konto? (#354)
 *
 * WARUM ES DIESE KLASSE GIBT. Bis v0.8 gab es genau einen Schalter,
 * `users.totp_enabled`, und jede Stelle, die wissen wollte "ist dieses Konto
 * geschuetzt?", fragte ihn direkt ab - achtzehn Fundstellen quer durch
 * Controller, Views und die 180-Tage-Regel aus #358. Mit dem Mailcode kommt
 * ein zweites Verfahren dazu, mit Passkeys (#353) ein drittes. Wuerde jede
 * dieser Stellen kuenftig zwei oder drei Schalter verodern, waere die Frage
 * "geschuetzt oder nicht" an achtzehn Orten beantwortet - und beim naechsten
 * Verfahren an siebzehn davon falsch.
 *
 * WARUM KEINE EIGENE TABELLE. Naheliegend waere ein Register
 * `user_second_factors (user_id, type)`. Dagegen spricht ein handfester
 * Grund: Das Material jedes Verfahrens liegt in `users` (totp_secret,
 * backup_codes, last_totp_timeslice, passkeys). Ein Register daneben koennte
 * "TOTP aktiv" sagen, waehrend `totp_secret` NULL ist - eine neue
 * Fehlerklasse, die es heute nicht gibt, weil Schalter und Geheimnis in
 * derselben Zeile stehen und in demselben UPDATE gesetzt werden. Der Gewinn
 * waere eine huebschere Tabelle, der Preis eine moegliche Inkonsistenz im
 * Anmeldeweg. Also: Speicherung beim Material, Registerfunktion im Code -
 * und zwar hier und nur hier.
 *
 * Wer ein Verfahren hinzufuegt, aendert diese Klasse und sonst nichts an der
 * Frage "welche Faktoren hat das Konto".
 */
final class SecondFactors {

    /** Authentikator-App, zeitbasierte Einmalkennwoerter. */
    public const TOTP = 'totp';

    /** Einmalcode an die hinterlegte E-Mail-Adresse (#354). */
    public const EMAIL = 'email';

    /** Passkey / WebAuthn (#353). */
    public const PASSKEY = 'passkey';

    /**
     * Alle Verfahren in der Reihenfolge ihrer Staerke - der erste Eintrag ist
     * der, den die Anmeldung von sich aus anbietet, wenn mehrere aktiv sind.
     */
    public const ALL = [self::PASSKEY, self::TOTP, self::EMAIL];

    /**
     * Die Spalten, aus denen fromRow() lesen kann. Wer eine users-Zeile
     * selbst holt und hier hineingibt, muss sie mitselektieren.
     */
    public const COLUMNS = ['totp_enabled', 'email_2fa_enabled'];

    /**
     * Passkeys sind die Ausnahme von der Regel oben - und die Ausnahme hat
     * einen Grund, keine Bequemlichkeit (#353).
     *
     * Bei TOTP und Mailcode stehen Schalter und Material in derselben
     * users-Zeile; deshalb kann kein Register behaupten "aktiv", waehrend das
     * Geheimnis fehlt. Bei Passkeys gibt es kein einzelnes Geheimnis, sondern
     * beliebig viele Schluessel - ein Konto kann Telefon, Notebook und einen
     * Sicherheitsstick haben, und jeden davon einzeln entziehen. Ein Schalter
     * in users waere dann eine zweite Wahrheit neben der Tabelle, die
     * auseinanderlaufen kann: genau die Fehlerklasse, die der Kommentar oben
     * vermeiden will.
     *
     * Die Zahl der Schluessel IST hier der Schalter. Damit steht die Aussage
     * "hat einen Passkey" weiterhin an genau einer Stelle - sie wird nur
     * gezaehlt statt gelesen.
     */

    private function __construct() {}

    /**
     * Aktive Faktoren eines Kontos.
     *
     * @return array<int, string> Teilmenge von self::ALL, in dieser Reihenfolge.
     */
    public static function forUser(int $userId): array {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare(
                'SELECT totp_enabled, email_2fa_enabled FROM users WHERE id = ?'
            );
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if (!is_array($row)) {
                return [];
            }
            // Passkeys stehen nicht in der users-Zeile, sie werden gezaehlt.
            $row['passkey_count'] = Passkeys::anzahl($userId);
            return self::fromRow($row);
        } catch (\Throwable $e) {
            // Ohne belastbare Auskunft gilt das Konto als ungeschuetzt.
            //
            // ACHTUNG, die urspruengliche Begruendung hier war zu optimistisch:
            // Sie sagte, das fuehre "zur Einrichtungsaufforderung, nie zu einer
            // uebersprungenen Pruefung". Das gilt nur fuer Konten MIT
            // 2FA-Pflicht. Ist sie fuer das Konto nicht verpflichtend, geht
            // AuthController::login() bei leerer Liste direkt in
            // completeLogin() - eine leere Liste ist dort also sehr wohl eine
            // uebersprungene Pruefung.
            //
            // Der Rueckfall bleibt trotzdem: Ein Datenbankfehler an dieser
            // Stelle darf die Anmeldung nicht mit einem Fehlerbild beenden.
            // Er wird aber protokolliert, damit er nicht unbemerkt bleibt.
            \App\Service\AuditLogger::log(
                'Zweite Faktoren nicht ermittelbar',
                'security',
                "Benutzer-ID {$userId}: " . $e->getMessage()
                . ' - das Konto gilt fuer diese Anmeldung als ungeschuetzt.'
            );
            return [];
        }
    }

    /**
     * Dieselbe Auswertung fuer eine bereits geholte users-Zeile - spart in
     * den Anmeldewegen eine zweite Abfrage.
     *
     * @param array<string, mixed> $row
     * @return array<int, string>
     */
    public static function fromRow(array $row): array {
        $faktoren = [];
        // Reihenfolge = Staerke. Der Passkey steht vorn, weil er als einziger
        // gegen Phishing traegt: Er ist an die Domain gebunden, ein
        // abgetippter Code ist es nicht.
        if (self::hatPasskey($row)) {
            $faktoren[] = self::PASSKEY;
        }
        if (!empty($row['totp_enabled'])) {
            $faktoren[] = self::TOTP;
        }
        if (!empty($row['email_2fa_enabled'])) {
            $faktoren[] = self::EMAIL;
        }
        return $faktoren;
    }

    /**
     * Hat dieses Konto einen Passkey?
     *
     * DIE STELLE, AN DER DIESE KLASSE EINMAL EINE ANMELDUNG DURCHGELASSEN HAT.
     *
     * Passkeys stehen als einziges Verfahren nicht in der users-Zeile,
     * sondern in einer eigenen Tabelle. Die erste Fassung las sie deshalb aus
     * `$row['passkey_count']` - einer Groesse, die NUR forUser() nachtraegt.
     * Alle sechs anderen Aufrufer von fromRow() uebergeben eine rohe
     * users-Zeile ohne dieses Feld, darunter der Anmeldeweg. Fuer ein Konto,
     * dessen einziger zweiter Faktor ein Passkey ist, kam damit eine leere
     * Faktorliste heraus: kein pending_2fa_user_id, kein /login/passkey,
     * direkt completeLogin(). Wer das Passwort hatte, war drin.
     *
     * Die Zahl wird jetzt hier geholt, wenn sie nicht schon dasteht. Damit
     * kann kein Aufrufer sie mehr vergessen - und genau das war der Fehler,
     * nicht eine falsche Abfrage.
     *
     * Fehlt AUCH die Benutzer-ID, ist das ein Programmierfehler. Er endet
     * ausdruecklich in einer Ausnahme und nicht in einem "dann eben keine
     * Faktoren": Ein Fehlerbild ist harmlos, eine stillschweigend
     * uebersprungene Anmeldepruefung nicht.
     *
     * @param array<string, mixed> $row
     */
    private static function hatPasskey(array $row): bool {
        if (array_key_exists('passkey_count', $row)) {
            return (int)$row['passkey_count'] > 0;
        }

        $userId = (int)($row['id'] ?? 0);
        if ($userId <= 0) {
            throw new \RuntimeException(
                'SecondFactors::fromRow() braucht entweder passkey_count oder id in der '
                . 'uebergebenen Zeile - sonst laesst sich nicht feststellen, ob das Konto '
                . 'einen Passkey hat.'
            );
        }

        return Passkeys::anzahl($userId) > 0;
    }

    public static function has(int $userId, string $type): bool {
        return in_array($type, self::forUser($userId), true);
    }

    public static function any(int $userId): bool {
        return self::forUser($userId) !== [];
    }

    /**
     * SQL-Ausdruck "dieses Konto hat mindestens einen zweiten Faktor".
     *
     * Fuer Mengenabfragen, die keine PHP-Schleife vertragen - insbesondere
     * die Fristenlogik aus #358 (DormantAccountService). Auch sie soll das
     * Wissen ueber die Verfahren nicht selbst tragen.
     */
    public static function sqlHasAnyFactor(string $alias = 'u'): string {
        return "({$alias}.totp_enabled = 1 OR {$alias}.email_2fa_enabled = 1"
            . " OR EXISTS (SELECT 1 FROM user_passkeys pk WHERE pk.user_id = {$alias}.id))";
    }

    /**
     * Darf dieses Konto den Mailcode als zweiten Faktor einschalten?
     *
     * Zwei Bedingungen, beide aus #354:
     *
     * 1. Es braucht eine hinterlegte Adresse. Ohne sie gibt es nichts zu
     *    versenden - die Auswahl wird gar nicht erst angeboten, statt eine
     *    Fehlermeldung zu produzieren (so entstehen mit #348 und Addons#131
     *    absichtlich Konten).
     * 2. Administratoren nicht. Der Mailcode ist der schwaechste der
     *    gaengigen zweiten Faktoren: Wer das Postfach hat, hat den Faktor.
     *    Fuer ein Konto mit allen Rechten ist das zu wenig, und "davon
     *    abraten" ist keine Schranke. Wird ein Konto SPAETER Administrator,
     *    verlangt die Anmeldung zusaetzlich TOTP (siehe
     *    AuthController::afterSecondFactor()).
     */
    public static function emailFactorAllowedFor(int $userId, ?string $email): bool {
        if ($email === null || trim($email) === '') {
            return false;
        }
        return !\App\Permission\GroupMembership::isAdmin($userId);
    }
}
