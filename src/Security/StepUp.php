<?php
// src/Security/StepUp.php

namespace App\Security;

use App\Database;

/**
 * Frische Bestätigung vor Änderungen an den eigenen zweiten Faktoren (#112,
 * Audit M15, M17, N10).
 *
 * DIE REGEL. Hat ein Konto schon einen zweiten Faktor, verlangt jede Aktion,
 * die einen Faktor HINZUFÜGT, ABSCHALTET oder seinen ZUSTELLWEG ändert, eine
 * frische Bestätigung mit Passwort UND einem vorhandenen Faktor:
 *
 * - Authentikator-App neu einrichten (`/2fa/setup`, `/2fa/enable`)
 * - Passkey hinzufügen oder entziehen
 * - Mailcode ein- oder ausschalten
 * - E-Mail-Adresse ändern (sie ist der Zustellweg des Mailcodes)
 * - Backup-Codes erneuern, wenn das Konto dafür keinen Code eintippen kann
 *   (nur Passkey)
 *
 * Bis hierher galt die Schranke nur für die App. Wer eine unbeaufsichtigte
 * Sitzung übernahm und das Passwort kannte, hängte stattdessen einen eigenen
 * Passkey an oder trug die Adresse auf ein eigenes Postfach um - und war
 * damit dauerhaft im Konto, ohne den vorhandenen Faktor je gesehen zu haben.
 *
 * WARUM EINE EIGENE KLASSE. Die Freigabe stand in AuthController
 * (`hasFresh2faReauth()`) und war nur dort erreichbar. Jede weitere
 * Schranke hätte sie kopieren müssen, und eine Kopie, die die Konto-ID nicht
 * vergleicht, ist genau der Fehler, den TwoFaCrossAccountTest festhält.
 *
 * Ein Konto OHNE Faktor braucht keinen Nachweis (erfuellt()): Es gibt nichts,
 * womit es ihn führen könnte, und der erste Faktor lässt sich wie bisher mit
 * der angemeldeten Sitzung einrichten.
 *
 * Die Freigabe ist ein Fenster von TTL Sekunden, kein Einmalnachweis - nur
 * `/2fa/enable` verbraucht sie. Sie gilt ausschließlich für das Konto, für
 * das sie erbracht wurde.
 */
final class StepUp {

    /** Gültigkeit der Freigabe in Sekunden. */
    public const TTL = 600;

    /**
     * Sitzungsschlüssel. Derselbe wie vor der Herauslösung, damit das
     * Aufräumen in AuthController::discardExistingSessionState() und in
     * LoginSession unverändert greift.
     */
    private const SESSION = 'twofa_reauth';

    /**
     * Wohin es nach der Bestätigung geht. Nur diese Ziele - ein frei
     * übergebener Pfad wäre eine offene Weiterleitung hinter einer
     * Passwortabfrage, also eine Phishing-Vorlage.
     */
    private const ZIELE = [
        'setup'    => '/2fa/setup',
        'profil'   => '/profil',
        'passkeys' => '/profil#passkeys',
        'email'    => '/profil#email',
    ];

    private function __construct() {}

    /** Hält eine erfolgreiche Bestätigung für GENAU dieses Konto fest. */
    public static function markieren(int $userId): void {
        $_SESSION[self::SESSION] = ['user_id' => $userId, 'at' => time()];
    }

    /**
     * Liegt für GENAU dieses Konto eine frische Freigabe vor?
     *
     * Der Zeitstempel allein reicht nicht: Er sagt nichts darüber aus, für
     * welches Konto er gilt. Ohne den Abgleich bezahlt der Nachweis des
     * einen Kontos die Änderung eines anderen.
     */
    public static function frisch(int $userId): bool {
        return self::gueltigBis($userId) !== null;
    }

    /** Bis wann die Freigabe dieses Kontos gilt (Unix-Zeit), sonst null. */
    public static function gueltigBis(int $userId): ?int {
        $freigabe = $_SESSION[self::SESSION] ?? null;
        if (!is_array($freigabe) || !isset($freigabe['user_id'], $freigabe['at'])) {
            return null;
        }
        if ($userId <= 0 || (int)$freigabe['user_id'] !== $userId) {
            return null;
        }
        $bis = (int)$freigabe['at'] + self::TTL;
        return time() <= $bis ? $bis : null;
    }

    /** Verwirft die Freigabe (nach der TOTP-Neueinrichtung). */
    public static function verbrauchen(): void {
        unset($_SESSION[self::SESSION]);
    }

    /**
     * Die zweiten Faktoren des Kontos - fail-closed.
     *
     * Bewusst NICHT SecondFactors::forUser(): Das liefert bei einem
     * Datenbankfehler eine leere Liste, und eine leere Liste hiesse hier
     * "braucht keinen Nachweis". Ein Fehler endet deshalb als Ausnahme, und
     * ein nicht (mehr) aktives Konto als null.
     *
     * `id` gehört mit in die Zeile - ohne sie zählt fromRow() keine Passkeys.
     *
     * @return array<int, string>|null
     */
    public static function faktoren(int $userId): ?array {
        $stmt = Database::getInstance()->prepare(
            "SELECT id, totp_enabled, email_2fa_enabled FROM users
             WHERE id = ? AND deleted_at IS NULL AND deactivated_at IS NULL"
        );
        $stmt->execute([$userId]);
        $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($zeile) ? SecondFactors::fromRow($zeile) : null;
    }

    /**
     * Darf dieses Konto jetzt eine Faktor-Änderung vornehmen?
     *
     * Ja ohne jeden Faktor, sonst nur mit frischer Freigabe. Wer die Faktoren
     * schon kennt, reicht sie herein und spart die Abfrage.
     *
     * @param array<int, string>|null $faktoren
     */
    public static function erfuellt(int $userId, ?array $faktoren = null): bool {
        $faktoren ??= self::faktoren($userId);
        if ($faktoren === null) {
            return false;
        }
        return $faktoren === [] || self::frisch($userId);
    }

    /**
     * Der Schlüssel eines erlaubten Ziels - alles Unbekannte wird zu `setup`,
     * dem Ziel der Seite vor dieser Klasse.
     */
    public static function fuer(?string $fuer): string {
        $fuer = (string)$fuer;
        return array_key_exists($fuer, self::ZIELE) ? $fuer : 'setup';
    }

    /** Pfad eines erlaubten Ziels (siehe ZIELE). */
    public static function ziel(?string $fuer): string {
        return self::ZIELE[self::fuer($fuer)];
    }

    /**
     * Prüft den Code des vorhandenen Faktors - welcher zählt, entscheidet das
     * Konto (Audit N10):
     *
     * - Hat es TOTP, zählt NUR der TOTP-Code. Auch bei TOTP + Mailcode: Die
     *   Anmeldung ließe dort den Mailcode zu, die Bestätigung bleibt beim
     *   stärkeren Verfahren. Wer das Gerät verloren hat, geht über die
     *   Verwaltung (2FA-Reset).
     * - Sonst zählt der Mailcode - aber nur, wenn er ein Faktor des Kontos
     *   IST. Bis hierher genügte der Postfachzugang auch bei einem Konto,
     *   dessen einziger Faktor ein Passkey war.
     * - Ein reines Passkey-Konto bestätigt mit dem Passkey
     *   (PasskeyController::stepUpPruefen()); hier besteht es nie.
     *
     * Ein akzeptierter TOTP-Code verbraucht seinen Zeitschlitz (#111), und zwar
     * atomar (OneTimeProofs::consumeTotpSlice(), Audit N43).
     * $konto braucht `totp_secret` und `last_totp_timeslice`.
     *
     * @param array<string, mixed> $konto
     * @param array<int, string> $faktoren
     */
    public static function codePruefen(int $userId, array $konto, array $faktoren, string $totpCode, string $mailCode): bool {
        $art = self::codeArt($faktoren);

        if ($art === SecondFactors::TOTP) {
            // Fail-closed (Audit N8): Ein nicht lesbares Secret besteht nie.
            $secret = Totp::secretAusSpeicher((string)($konto['totp_secret'] ?? ''), $userId);
            if ($secret === null) {
                return false;
            }
            $letzter = ($konto['last_totp_timeslice'] ?? null) === null ? null : (int)$konto['last_totp_timeslice'];
            $slice = Totp::verifyCodeReturnSlice($secret, trim($totpCode), $letzter);
            if ($slice === null) {
                return false;
            }
            // Verbrauch per Vergleich mit dem gespeicherten Stand (Audit
            // N43): Zwei parallele Bestätigungen mit demselben Code bestehen
            // nicht beide, und eine langsamere kann den Schlitz nicht senken.
            return OneTimeProofs::consumeTotpSlice($userId, $slice);
        }

        if ($art === SecondFactors::EMAIL) {
            return EmailSecondFactor::verify($userId, EmailSecondFactor::PURPOSE_SETUP, $mailCode);
        }

        return false;
    }

    /**
     * Welcher Code zählt - die Weiche für codePruefen() UND für die Felder
     * der Bestätigungsseite. Eine Stelle, damit die Seite nie ein Feld zeigt,
     * das nicht zählt (vorher sah ein Konto mit TOTP + Mailcode dort auch ein
     * Mailcodefeld, das nie gewertet wurde).
     *
     * @param array<int, string> $faktoren
     * @return string|null 'totp', 'email' oder null (nur Passkey)
     */
    public static function codeArt(array $faktoren): ?string {
        if (in_array(SecondFactors::TOTP, $faktoren, true)) {
            return SecondFactors::TOTP;
        }
        if (in_array(SecondFactors::EMAIL, $faktoren, true)) {
            return SecondFactors::EMAIL;
        }
        return null;
    }
}
