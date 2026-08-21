<?php
// src/Security/LoginIdentifier.php

namespace App\Security;

/**
 * Die Anmeldekennung: Benutzername ODER E-Mail-Adresse (#348).
 *
 * WARUM ES DIESE KLASSE GIBT. Bis v0.8 war die Kennung immer die E-Mail-
 * Adresse - eine Zeichenkette, die an drei Stellen gleichzeitig gebraucht
 * wird: fuer die Suche in `users`, fuer den Schluessel des Rate-Limiters und
 * fuer die Anzeige. Sobald zwei Spalten in Frage kommen, muessen sich diese
 * drei Stellen darueber einig sein, WAS dieselbe Kennung ist. Sind sie es
 * nicht, entsteht genau die Luecke, die Issue #348 benennt: Ein Angreifer
 * probiert dasselbe Konto einmal ueber den Benutzernamen und einmal ueber die
 * Adresse und hat damit zwei Zaehler statt einem.
 *
 * WARUM `mb_strtolower` UND NICHT `strtolower`. Die Datenbank vergleicht in
 * utf8mb4_unicode_ci, also ohne Ruecksicht auf Gross- und Kleinschreibung -
 * und zwar auch bei Umlauten. `strtolower()` arbeitet dagegen byteweise und
 * laesst "MÜLLER" unveraendert. Ohne die mehrbyte-faehige Variante findet die
 * Datenbank fuer "MÜLLER" und "müller" dasselbe Konto, der Zaehler fuehrt sie
 * aber getrennt: doppelt so viele Versuche, ohne dass es auffiele. Derselbe
 * Fehler steckte frueher in RateLimiter::normalizeIdentifier(); er ist dort
 * mitbehoben, hier steht er nochmals, weil die Kennung schon VOR dem
 * Zusammensetzen mit der IP eindeutig sein muss.
 *
 * WARUM KEIN `@` IM BENUTZERNAMEN. Beide Namensraeume liegen im selben
 * Eingabefeld. Waere "kunde@example.org" als Benutzername erlaubt, koennte er
 * die E-Mail-Adresse eines anderen Kontos sein - und die Anmeldung waere
 * mehrdeutig. Das Verbot trennt die Namensraeume an einem Zeichen, das in
 * jeder Adresse vorkommt und in keinem sinnvollen Benutzernamen fehlt.
 * Bestandsnamen, die es verletzen, bleiben bestehen (siehe
 * AuthController::loginSubmit(), das den Mehrdeutigkeitsfall fail-closed
 * abweist) - neue entstehen keine mehr.
 */
final class LoginIdentifier {

    /**
     * Laengengrenze der Eingabe. `users.email` ist VARCHAR(100),
     * `users.username` VARCHAR(50) - laenger kann nichts treffen, und ein
     * ueberlanger Wert hat im Zaehler nichts zu suchen (siehe dort).
     */
    public const MAX_LENGTH = 100;

    private function __construct() {}

    /**
     * Vereinheitlicht die Eingabe zu der einen Form, unter der sie gesucht
     * UND gezaehlt wird.
     */
    public static function normalize(string $eingabe): string {
        $kennung = trim($eingabe);
        $kennung = mb_strtolower($kennung, 'UTF-8');
        return mb_substr($kennung, 0, self::MAX_LENGTH, 'UTF-8');
    }

    /**
     * Sieht die Kennung nach einer E-Mail-Adresse aus?
     *
     * Bewusst nur die Frage nach dem `@`, nicht `FILTER_VALIDATE_EMAIL`: Es
     * geht hier nicht um Gueltigkeit, sondern um die Zuordnung zum
     * Namensraum. Eine unvollstaendige Adresse ist eine Adresse, die kein
     * Konto trifft - kein Benutzername.
     */
    public static function looksLikeEmail(string $kennung): bool {
        return str_contains($kennung, '@');
    }

    /**
     * Prueft einen Benutzernamen fuer das Anlegen oder Aendern eines Kontos.
     *
     * @return array<int, string> Leere Liste = in Ordnung.
     */
    public static function usernameErrors(string $username): array {
        $fehler = [];
        $username = trim($username);

        if ($username === '') {
            $fehler[] = 'Benutzername erforderlich.';
            return $fehler;
        }
        if (mb_strlen($username, 'UTF-8') > 50) {
            $fehler[] = 'Der Benutzername darf hoechstens 50 Zeichen lang sein.';
        }
        if (self::looksLikeEmail($username)) {
            $fehler[] = 'Der Benutzername darf kein "@" enthalten - sonst waere '
                      . 'nicht mehr eindeutig, ob damit ein Konto oder eine '
                      . 'E-Mail-Adresse gemeint ist (Anmeldung ueber beides, #348).';
        }

        return $fehler;
    }
}
