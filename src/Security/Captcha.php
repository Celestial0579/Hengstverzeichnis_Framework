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
     * Im Kern eingebauter Anbieter; Standardwert der Einstellung
     * `captcha_provider`.
     */
    public const PROVIDER_BUILTIN = 'builtin';

    /**
     * Wählbare Anbieter: der eingebaute plus alles, was Addons über den Filter
     * `captcha.providers` melden.
     *
     * @return array<string, string> Slug => Anzeigename
     */
    public static function availableProviders(): array {
        $providers = [self::PROVIDER_BUILTIN => 'Rechenaufgabe (im Kern enthalten, ohne Drittanbieter)'];

        $fromPlugins = \App\Plugin\PluginManager::getInstance()->getHooks()
            ->applyFilters('captcha.providers', []);
        if (!is_array($fromPlugins)) {
            return $providers;
        }

        foreach ($fromPlugins as $slug => $label) {
            // Der eingebaute Anbieter ist nicht überschreibbar - er ist der
            // Rückfallweg, den ein fehlerhaftes Addon nicht unbrauchbar machen
            // können soll.
            if (!is_string($slug) || $slug === '' || $slug === self::PROVIDER_BUILTIN) {
                continue;
            }
            $providers[$slug] = is_string($label) && $label !== '' ? $label : $slug;
        }

        return $providers;
    }

    /**
     * Der konfigurierte Anbieter, auf Gültigkeit geprüft. Ist der gespeicherte
     * Anbieter unbekannt - etwa weil sein Addon deaktiviert oder deinstalliert
     * wurde -, gilt der eingebaute.
     *
     * @param array<string, mixed> $settings
     */
    public static function activeProvider(array $settings, ?string $context = null): string {
        // Je Formular wählbar (#351): Der Betreiber kann für ein einzelnes
        // Formular einen anderen Anbieter setzen als global. Ohne eigenen
        // Eintrag gilt die globale Wahl - wer nichts einstellt, bekommt
        // überall dasselbe, und das ist das erwartete Verhalten.
        $configured = '';
        if ($context !== null && CaptchaContext::isValid($context)) {
            $configured = trim((string)($settings[CaptchaContext::settingKey($context)] ?? ''));
        }
        if ($configured === '') {
            $configured = trim((string)($settings['captcha_provider'] ?? self::PROVIDER_BUILTIN));
        }
        if ($configured === '' || $configured === self::PROVIDER_BUILTIN) {
            return self::PROVIDER_BUILTIN;
        }

        return isset(self::availableProviders()[$configured]) ? $configured : self::PROVIDER_BUILTIN;
    }

    /**
     * Ist der Kontext angemeldet? Ein unbekannter Kontext schaltet den Schutz
     * NICHT ab - er zwingt auf den eingebauten Anbieter zurück und wird
     * protokolliert (#351).
     *
     * Die Richtung ist wesentlich: Ein Tippfehler im Kontextnamen macht ein
     * Formular höchstens strenger als gewollt, nie ungeschützter. Andersherum
     * wäre ein vertippter Kontext ein stiller Weg, den Spam-Schutz eines
     * Formulars auszuschalten.
     */
    private static function kontextGeprueft(string $context): bool {
        if (CaptchaContext::isValid($context)) {
            return true;
        }

        \App\Service\AuditLogger::log(
            'CAPTCHA: unbekannter Formular-Kontext',
            'security',
            "Kontext '{$context}' ist nicht angemeldet - der eingebaute Schutz greift. "
            . 'Ein Addon meldet seine Formulare mit App\\Security\\CaptchaContext::register() an.'
        );
        return false;
    }

    /**
     * Liefert das Formularfragment des aktiven Anbieters für genau die Stelle
     * im bestehenden Formular, an der es stehen soll.
     *
     * Ein Addon liefert über `captcha.render` ebenfalls nur ein Fragment und
     * kann sich damit keine vorgeschaltete Prüfseite erzwingen. Liefert es
     * nichts Brauchbares, rendert der Kern seine eigene Aufgabe.
     *
     * @param array<string, mixed> $settings
     * @param string $context Formularkennung aus App\Security\CaptchaContext.
     *                        Der Kern kennt 'dsgvo' und 'register'; Addons melden
     *                        ihre eigenen Formulare dort an (#351). Ein nicht
     *                        angemeldeter Kontext bekommt den eingebauten Schutz.
     */
    public static function renderField(array $settings, string $context = 'dsgvo'): string {
        $provider = self::kontextGeprueft($context)
            ? self::activeProvider($settings, $context)
            : self::PROVIDER_BUILTIN;

        if ($provider !== self::PROVIDER_BUILTIN) {
            $html = \App\Plugin\PluginManager::getInstance()->getHooks()
                ->applyFilters('captcha.render', '', $provider, $context);
            if (is_string($html) && trim($html) !== '') {
                return $html;
            }
            // Kein Addon hat geantwortet - siehe verify(): der Kern übernimmt.
        }

        return self::renderBuiltinField();
    }

    /**
     * Das Fragment des eingebauten Anbieters: Beschriftung, Aufgabentext und
     * Eingabefeld. Die Aufgabe selbst wird dabei neu ausgegeben.
     */
    private static function renderBuiltinField(): string {
        $question = self::issue();

        return '<div class="form-group">'
            . '<label for="captcha">' . htmlspecialchars(\App\I18n\Translator::t('dsgvo.captcha_label')) . '</label>'
            . '<div style="margin-bottom: 0.5rem; font-size: 1.1rem;"><strong>'
            . htmlspecialchars($question) . '</strong> =</div>'
            . '<input type="text" id="captcha" name="captcha" class="form-control" inputmode="numeric"'
            . ' autocomplete="off" maxlength="2" required style="max-width: 8rem;">'
            . '<small class="form-hint">' . htmlspecialchars(\App\I18n\Translator::t('dsgvo.captcha_hint')) . '</small>'
            . '</div>';
    }

    /**
     * Serverseitige Prüfung des aktiven Anbieters. Immer aufrufen, bevor etwas
     * gespeichert oder versendet wird.
     *
     * Antwortet ein Addon nicht - weil es abgestürzt, deaktiviert oder
     * deinstalliert ist -, prüft der Kern mit seiner eigenen Aufgabe. Das ist
     * besser als beide Alternativen: fail-open liesse das Formular ungeschützt,
     * hartes Blockieren würde Betroffene daran hindern, ihre Rechte aus
     * Art. 15/17 DSGVO wahrzunehmen. Ob dieser Zweig greift, hängt allein am
     * serverseitigen Plugin-Zustand und ist über Request-Daten nicht erzwingbar.
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $input Formulardaten (i. d. R. $_POST)
     * @return string Eine der Konstanten OK, WRONG, EXPIRED, TOO_FAST
     */
    public static function verify(array $settings, string $context, array $input): string {
        $provider = self::kontextGeprueft($context)
            ? self::activeProvider($settings, $context)
            : self::PROVIDER_BUILTIN;

        if ($provider !== self::PROVIDER_BUILTIN) {
            // Startwert null heisst "niemand hat geantwortet". Das ist wesentlich:
            // HookManager::applyFilters() verschluckt eine Exception im Callback
            // und behält den vorherigen Wert - ein abgestürztes Addon liefert
            // damit null und niemals versehentlich OK.
            $verdict = \App\Plugin\PluginManager::getInstance()->getHooks()
                ->applyFilters('captcha.verify', null, $provider, $context, $input);

            if (is_string($verdict) && in_array($verdict, [self::OK, self::WRONG, self::EXPIRED, self::TOO_FAST], true)) {
                // Eine ggf. offene eigene Aufgabe entwerten, damit sie nicht
                // später wiederverwendbar bleibt.
                self::clear();
                return $verdict;
            }

            \App\Service\AuditLogger::log(
                'CAPTCHA-Anbieter hat nicht geantwortet',
                'security',
                "Anbieter '{$provider}' lieferte kein Urteil für '{$context}' - auf die eingebaute Aufgabe zurückgefallen."
            );
        }

        return self::verifyBuiltin(is_string($input['captcha'] ?? null) ? $input['captcha'] : null);
    }

    /**
     * Prüft die eingegebene Antwort gegen die laufende Aufgabe und verbraucht
     * diese dabei in jedem Fall (Single-Use).
     *
     * @param string|null $input Rohwert aus dem Formularfeld
     * @return string Eine der Konstanten OK, WRONG, EXPIRED, TOO_FAST
     */
    public static function verifyBuiltin(?string $input): string {
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
