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
 * Anwendung ohne externe Dienste und ohne eigene Abhaengigkeit (siehe
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

    /**
     * Schlüssel der Aufgabe OHNE Formular-Kontext in der Benutzersitzung. Bleibt
     * für Aufrufer ohne Kontext (und für bereits laufende Sitzungen aus der
     * Zeit vor den Kontext-Plätzen) unverändert bestehen.
     */
    private const SESSION_KEY = 'captcha_challenge';

    /**
     * Schlüssel der Aufgaben JE FORMULAR-KONTEXT (Audit N3):
     * `$_SESSION['captcha_challenges'][<kontext>]`.
     *
     * Vorher gab es genau einen Platz. Zwei geschützte Formulare auf einer
     * Seite (etwa Deckanfrage und Verkaufsbörse auf einer Hengstseite)
     * überschrieben sich beim Rendern gegenseitig die Aufgabe, und das zuerst
     * gerenderte Formular scheiterte beim Absenden immer am Spam-Schutz.
     */
    private const SESSION_KEY_CONTEXTS = 'captcha_challenges';

    /**
     * Höchstzahl gleichzeitig offener Kontext-Aufgaben je Sitzung. Wird sie
     * überschritten, fällt die am längsten nicht mehr ausgegebene weg - die
     * Sitzung kann so nicht unbegrenzt wachsen, auch wenn jemand viele
     * verschiedene Kontextnamen anfragt.
     */
    public const MAX_CONTEXTS = 10;

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
     * eine zuvor ausgegebene Aufgabe DESSELBEN Kontexts wird dabei ersetzt.
     *
     * Mit `$context` liegt die Aufgabe in einem eigenen Platz je Formular
     * (Audit N3), sodass mehrere Formulare auf einer Seite sich nicht
     * gegenseitig die Aufgabe überschreiben. Ohne Kontext gilt der bisherige
     * gemeinsame Platz.
     *
     * @param string|null $context Formularkennung, i. d. R. dieselbe wie bei
     *                             renderField()/verify()
     * @return string Aufgabentext, z. B. "sieben plus fünf"
     */
    public static function issue(?string $context = null): string {
        $a = random_int(1, 9);
        $b = random_int(1, 9);
        $subtract = random_int(0, 1) === 1;

        // Bei Subtraktion nie ein negatives Ergebnis stellen.
        if ($subtract && $b > $a) {
            [$a, $b] = [$b, $a];
        }

        $challenge = [
            'answer' => $subtract ? $a - $b : $a + $b,
            'issued_at' => time(),
        ];

        $slot = self::slot($context);
        if ($slot === null) {
            $_SESSION[self::SESSION_KEY] = $challenge;
        } else {
            self::storeContextChallenge($slot, $challenge);
        }

        return self::questionText($a, $b, $subtract);
    }

    /**
     * Platzname für einen Kontext: klein geschrieben, nur [a-z0-9_-], höchstens
     * 64 Zeichen - dieselbe Form, die CaptchaContext::register() verlangt.
     * `null` (oder was nach dem Bereinigen leer bleibt) heisst: kein Kontext,
     * also der bisherige gemeinsame Platz.
     */
    private static function slot(?string $context): ?string {
        if ($context === null) {
            return null;
        }
        $slot = substr((string)preg_replace('/[^a-z0-9_-]/', '', strtolower($context)), 0, 64);

        return $slot === '' ? null : $slot;
    }

    /**
     * Legt eine Kontext-Aufgabe ab und hält die Liste klein: abgelaufene
     * Aufgaben fallen weg, und mehr als MAX_CONTEXTS bleiben nie liegen (die
     * am längsten nicht mehr ausgegebene zuerst).
     *
     * @param array{answer:int, issued_at:int} $challenge
     */
    private static function storeContextChallenge(string $slot, array $challenge): void {
        $all = $_SESSION[self::SESSION_KEY_CONTEXTS] ?? [];
        if (!is_array($all)) {
            $all = [];
        }

        // Neu einsortieren statt überschreiben: Die Reihenfolge des Arrays ist
        // die Reihenfolge der Ausgabe, und die älteste fällt zuerst weg.
        unset($all[$slot]);
        $now = time();
        foreach ($all as $key => $existing) {
            if (!is_array($existing) || ($now - (int)($existing['issued_at'] ?? 0)) > self::TTL_SECONDS) {
                unset($all[$key]);
            }
        }
        $all[$slot] = $challenge;

        while (count($all) > self::MAX_CONTEXTS) {
            unset($all[array_key_first($all)]);
        }

        $_SESSION[self::SESSION_KEY_CONTEXTS] = $all;
    }

    /**
     * Holt die Aufgabe eines Platzes aus der Session und entfernt sie dabei
     * (Single-Use).
     *
     * Liegt für einen Kontext (noch) keine eigene Aufgabe vor, gilt der
     * gemeinsame Platz. Das trägt zwei Fälle über den Wechsel: eine Sitzung,
     * deren Formular noch vor dem Update ausgeliefert wurde, und ein
     * Anbieter-Addon, das die Rückfall-Aufgabe noch ohne Kontext stellt
     * (`Captcha::issue()`), dessen Prüfung aber abstürzt und damit hier im
     * Kern mit Kontext landet. Zwei Formulare MIT Kontext berührt dieser Weg
     * nicht - ihre Aufgaben liegen jeweils im eigenen Platz.
     *
     * @return mixed Die gespeicherte Aufgabe oder null
     */
    private static function takeChallenge(?string $slot): mixed {
        if ($slot !== null
            && is_array($_SESSION[self::SESSION_KEY_CONTEXTS] ?? null)
            && array_key_exists($slot, $_SESSION[self::SESSION_KEY_CONTEXTS])
        ) {
            $challenge = $_SESSION[self::SESSION_KEY_CONTEXTS][$slot];
            unset($_SESSION[self::SESSION_KEY_CONTEXTS][$slot]);
            return $challenge;
        }

        $challenge = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);
        return $challenge;
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
     *                        Der Kern kennt 'dsgvo', 'register' und 'login'
     *                        (nur als Bremse nach Fehlversuchen); Addons melden
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

        return self::renderBuiltinField($context);
    }

    /**
     * Das Fragment des eingebauten Anbieters: Beschriftung, Aufgabentext und
     * Eingabefeld. Die Aufgabe selbst wird dabei neu ausgegeben, und zwar im
     * Platz des Kontexts.
     *
     * Mit Kontext bekommt das Eingabefeld die ID `captcha-<kontext>`, damit
     * zwei Formulare auf einer Seite keine doppelte ID und keine fremde
     * Beschriftung haben. Der Feldname bleibt `captcha` - den liest verify().
     * Ohne Kontext bleibt die bisherige ID `captcha`.
     */
    private static function renderBuiltinField(?string $context = null): string {
        $question = self::issue($context);
        $slot = self::slot($context);
        $id = htmlspecialchars($slot === null ? 'captcha' : 'captcha-' . $slot, ENT_QUOTES, 'UTF-8');

        return '<div class="form-group">'
            . '<label for="' . $id . '">' . htmlspecialchars(\App\I18n\Translator::t('dsgvo.captcha_label')) . '</label>'
            . '<div style="margin-bottom: 0.5rem; font-size: 1.1rem;"><strong>'
            . htmlspecialchars($question) . '</strong> =</div>'
            . '<input type="text" id="' . $id . '" name="captcha" class="form-control" inputmode="numeric"'
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
                // später wiederverwendbar bleibt - die dieses Kontexts und
                // die ohne Kontext, die ein Addon als Rückfall noch ohne
                // Kontext gestellt haben kann. Aufgaben ANDERER Kontexte
                // bleiben stehen: Sie gehören zu anderen Formularen derselben
                // Seite (Audit N3).
                self::clear($context);
                self::clear();
                return $verdict;
            }

            \App\Service\AuditLogger::log(
                'CAPTCHA-Anbieter hat nicht geantwortet',
                'security',
                "Anbieter '{$provider}' lieferte kein Urteil für '{$context}' - auf die eingebaute Aufgabe zurückgefallen."
            );
        }

        return self::verifyBuiltin(is_string($input['captcha'] ?? null) ? $input['captcha'] : null, $context);
    }

    /**
     * Prüft die eingegebene Antwort gegen die laufende Aufgabe und verbraucht
     * diese dabei in jedem Fall (Single-Use).
     *
     * @param string|null $input Rohwert aus dem Formularfeld
     * @param string|null $context Formularkennung wie bei issue(); ohne
     *                             Kontext gilt der gemeinsame Platz
     * @return string Eine der Konstanten OK, WRONG, EXPIRED, TOO_FAST
     */
    public static function verifyBuiltin(?string $input, ?string $context = null): string {
        // Single-Use: Auch bei Erfolg wird die Aufgabe entwertet, damit eine
        // einmal gelöste Aufgabe nicht für eine Serie von Submits taugt.
        $challenge = self::takeChallenge(self::slot($context));

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
     *
     * Mit Kontext nur die Aufgabe dieses Formulars - die anderer Formulare
     * derselben Seite bleiben gültig. Ohne Kontext der gemeinsame Platz.
     */
    public static function clear(?string $context = null): void {
        $slot = self::slot($context);
        if ($slot === null) {
            unset($_SESSION[self::SESSION_KEY]);
            return;
        }
        if (is_array($_SESSION[self::SESSION_KEY_CONTEXTS] ?? null)) {
            unset($_SESSION[self::SESSION_KEY_CONTEXTS][$slot]);
        }
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
