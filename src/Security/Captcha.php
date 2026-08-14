<?php
// src/Security/Captcha.php

namespace App\Security;

/**
 * Class Captcha
 *
 * Selbst gehosteter, abhängigkeitsfreier Spam-/Bot-Schutz für öffentliche
 * Formulare, die ohne Anmeldung erreichbar sind (aktuell: das DSGVO-Portal,
 * siehe PublicController::dsgvoSubmit()).
 *
 * Bewusst KEIN Drittanbieter-CAPTCHA (reCAPTCHA/hCaptcha/Turnstile): Das
 * DSGVO-Formular ist genau die Stelle, an der Betroffene ihre Rechte aus
 * Art. 15/17 DSGVO geltend machen - dabei ihre IP-Adresse und einen
 * Browser-Fingerprint an einen weiteren Empfänger (i. d. R. Drittland) zu
 * übertragen, wäre ausgerechnet für dieses Formular kaum zu rechtfertigen und
 * müsste zudem in der Datenschutzerklärung stehen. Zusätzlich läuft die
 * Anwendung ohne Composer und ohne externe Dienste (siehe
 * docs/development.md) und setzt keine GD-Extension voraus (siehe Dockerfile) -
 * ein Bild-CAPTCHA wäre daher auch technisch eine neue Abhängigkeit.
 *
 * Sicherheits-Eigenschaften:
 * - Die Lösung steht ausschließlich serverseitig in der Session, nie im HTML.
 * - Single-Use: Jede Prüfung verbraucht die Aufgabe (auch bei Erfolg), ein
 *   einmal gelöstes CAPTCHA lässt sich also nicht für viele Submits
 *   wiederverwenden - jeder weitere Versuch erfordert einen neuen GET.
 * - Zeitfenster nach oben (TTL) und nach unten (Mindest-Ausfüllzeit): sofort
 *   nach dem Rendern abgeschickte Formulare stammen nicht von Menschen.
 * - Die Aufgabe wird in Worten gestellt ("sieben plus fünf"), damit sie nicht
 *   mit einem trivialen Zahlen-Regex aus dem HTML gelöst werden kann.
 *
 * Grenzen (bewusst): Eine Rechenaufgabe hält gezielte, für diese Seite
 * geschriebene Angreifer nicht auf - sie erhöht die Kosten für die üblichen
 * generischen Spam-Bots. Der eigentliche Mengenschutz bleibt das
 * IP-Rate-Limiting (siehe RateLimiter); beide Schichten wirken unabhängig
 * voneinander, insbesondere weil der RateLimiter bei DB-Fehlern bewusst
 * fail-open ist, das CAPTCHA dagegen fail-closed.
 */
class Captcha {

    /** Schlüssel der laufenden Aufgabe in der Benutzersitzung. */
    private const SESSION_KEY = 'captcha_challenge';

    /** Maximale Gültigkeit einer ausgegebenen Aufgabe in Sekunden. */
    public const TTL_SECONDS = 900;

    /**
     * Mindestzeit zwischen Ausgabe des Formulars und Absenden. Menschen
     * brauchen zum Lesen und Ausfüllen zwangsläufig länger.
     */
    public const MIN_SOLVE_SECONDS = 3;

    /**
     * Name des Honeypot-Feldes. Absichtlich unauffällig benannt, damit
     * automatische Formularausfüller es für ein echtes Feld halten.
     */
    public const HONEYPOT_FIELD = 'website';

    /** Ergebnis von verify(): Aufgabe korrekt gelöst. */
    public const OK = 'ok';

    /** Ergebnis von verify(): falsche oder keine Antwort. */
    public const WRONG = 'wrong';

    /** Ergebnis von verify(): keine (mehr) gültige Aufgabe in der Session. */
    public const EXPIRED = 'expired';

    /** Ergebnis von verify(): unmenschlich schnell abgeschickt. */
    public const TOO_FAST = 'too_fast';

    /**
     * Erzeugt eine neue Aufgabe, legt Lösung und Ausgabezeitpunkt in der
     * Session ab und liefert den anzuzeigenden Aufgabentext in der aktiven
     * Sprache zurück. Muss bei JEDEM Rendern des Formulars aufgerufen werden -
     * eine zuvor ausgegebene Aufgabe wird dabei ersetzt.
     *
     * @return string Aufgabentext, z. B. "sieben plus fünf"
     */
    public static function issue(): string {
        $a = random_int(1, 9);
        $b = random_int(1, 9);
        $subtract = random_int(0, 1) === 1;

        // Bei Subtraktion nie ein negatives Ergebnis stellen.
        if ($subtract && $b > $a) {
            [$a, $b] = [$b, $a];
        }

        $_SESSION[self::SESSION_KEY] = [
            'answer' => $subtract ? $a - $b : $a + $b,
            'issued_at' => time(),
        ];

        return self::questionText($a, $b, $subtract);
    }

    /**
     * Prüft die eingegebene Antwort gegen die laufende Aufgabe und verbraucht
     * diese dabei in jedem Fall (Single-Use).
     *
     * @param string|null $input Rohwert aus dem Formularfeld
     * @return string Eine der Konstanten OK, WRONG, EXPIRED, TOO_FAST
     */
    public static function verify(?string $input): string {
        $challenge = $_SESSION[self::SESSION_KEY] ?? null;

        // Single-Use: Auch bei Erfolg wird die Aufgabe entwertet, damit eine
        // einmal gelöste Aufgabe nicht für eine Serie von Submits taugt.
        unset($_SESSION[self::SESSION_KEY]);

        if (!is_array($challenge) || !isset($challenge['answer'], $challenge['issued_at'])) {
            return self::EXPIRED;
        }

        $age = time() - (int)$challenge['issued_at'];
        if ($age > self::TTL_SECONDS) {
            return self::EXPIRED;
        }
        if ($age < self::MIN_SOLVE_SECONDS) {
            return self::TOO_FAST;
        }

        $given = trim((string)$input);
        if (!preg_match('/^-?\d{1,2}$/', $given)) {
            return self::WRONG;
        }

        return (int)$given === (int)$challenge['answer'] ? self::OK : self::WRONG;
    }

    /**
     * Prüft, ob das für Menschen unsichtbare Honeypot-Feld ausgefüllt wurde -
     * ein sicheres Bot-Indiz.
     *
     * @param array<string, mixed> $input Formulardaten (i. d. R. $_POST)
     */
    public static function honeypotTripped(array $input): bool {
        $value = $input[self::HONEYPOT_FIELD] ?? '';

        return is_string($value) ? trim($value) !== '' : $value !== '';
    }

    /**
     * Verwirft eine ggf. laufende Aufgabe (z. B. nach erfolgreicher
     * Verarbeitung), damit die Session keinen verwaisten Zustand behält.
     */
    public static function clear(): void {
        unset($_SESSION[self::SESSION_KEY]);
    }

    /**
     * Baut den Aufgabentext aus den Zahlwörtern der aktiven Sprache
     * (`captcha.number_1` … `captcha.number_9`, `captcha.plus`,
     * `captcha.minus`).
     */
    private static function questionText(int $a, int $b, bool $subtract): string {
        $operator = \App\I18n\Translator::t($subtract ? 'captcha.minus' : 'captcha.plus');

        return \App\I18n\Translator::t('captcha.number_' . $a)
            . ' ' . $operator . ' '
            . \App\I18n\Translator::t('captcha.number_' . $b);
    }
}
