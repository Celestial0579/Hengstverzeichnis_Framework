<?php
// src/Security/CaptchaContext.php

namespace App\Security;

/**
 * Class CaptchaContext
 *
 * Katalog der Formulare, die einen Spam-Schutz haben können (#351).
 *
 * DAS PROBLEM. `Captcha::renderField()` und `verify()` nehmen seit jeher einen
 * `$context` entgegen, und der Kommentar dort sagt sogar, wozu: "damit später
 * /register oder ein Addon-Formular dieselbe Schnittstelle nutzen kann".
 * Praktisch gab es nur den einen Wert `'dsgvo'`, und ein Addon hatte keine
 * Möglichkeit, seinen eigenen anzumelden. Die öffentlichen Formulare dieses
 * Systems liegen aber überwiegend in Addons - Kontaktanfrage, Deckanfrage,
 * Verkaufsbörse. Genau die, die Spam bekommen, konnten den vorhandenen
 * Unterbau nicht nutzen.
 *
 * WOZU EIN KATALOG UND NICHT EINFACH EINE FREIE ZEICHENKETTE. Weil der
 * Betreiber je Formular entscheiden können soll, ob und womit geschützt wird -
 * und dafür braucht die Einstellungsseite eine Liste. Eine freie Zeichenkette
 * ergäbe eine Einstellung, die niemand findet, weil niemand weiss, wie das
 * Formular intern heisst.
 *
 * FAIL-CLOSED, ABER RICHTIG HERUM. Ein unbekannter Kontext führt NICHT dazu,
 * dass der Schutz entfällt - das wäre die gefährliche Auslegung. Er führt
 * dazu, dass der eingebaute Schutz greift, und der Vorfall wird protokolliert.
 * Ein Tippfehler im Kontextnamen macht ein Formular so höchstens strenger als
 * gewollt, nie ungeschützter.
 */
final class CaptchaContext {

    /**
     * Kern-Formulare. `dsgvo` ist das DSGVO-Portal (Art. 15/17), `register`
     * die Selbstregistrierung.
     *
     * @var array<string, string>
     */
    private const CORE_CONTEXTS = [
        'dsgvo' => 'DSGVO-Portal (Auskunft und Löschung)',
        'register' => 'Selbstregistrierung',
    ];

    /** @var array<string, string>|null */
    private static ?array $contexts = null;

    private function __construct() {}

    /**
     * Meldet ein Formular an. Ein Addon ruft das in seiner `register()`-Methode
     * auf:
     *
     *     CaptchaContext::register('kontaktanfrage', 'Kontaktanfrage an einen Kontakt');
     *
     * Sicherheits-Leitplanke wie bei den Berechtigungen: Wer zuerst
     * registriert, gewinnt. Ein Addon kann damit weder einen Kern-Kontext noch
     * den eines anderen Addons umdefinieren - sonst könnte es die Beschriftung
     * eines fremden Formulars in der Einstellungsseite verändern und den
     * Betreiber dazu bringen, den Schutz am falschen Formular abzuschalten.
     */
    public static function register(string $key, string $label): void {
        self::ensureInitialized();

        $key = trim($key);
        if ($key === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $key)) {
            // Ein Kontextname landet in Einstellungsschlüsseln und in der
            // Oberfläche. Was hier nicht durchkommt, würde später an einer
            // unangenehmeren Stelle Ärger machen.
            return;
        }

        if (!isset(self::$contexts[$key])) {
            self::$contexts[$key] = $label !== '' ? $label : $key;
        }
    }

    /**
     * Vollständiger Katalog: Kern + zur Laufzeit angemeldete Addon-Formulare.
     *
     * @return array<string, string>
     */
    public static function all(): array {
        self::ensureInitialized();
        return self::$contexts;
    }

    public static function isValid(string $key): bool {
        self::ensureInitialized();
        return isset(self::$contexts[$key]);
    }

    /**
     * Der Einstellungsschlüssel, unter dem der Betreiber den Anbieter für ein
     * Formular wählt. Ohne eigenen Eintrag gilt die globale Wahl - ein
     * Betreiber, der nichts einstellt, bekommt überall dasselbe, und das ist
     * das erwartete Verhalten.
     */
    public static function settingKey(string $key): string {
        return 'captcha_provider_' . $key;
    }

    /**
     * Nur für Tests: setzt den Katalog auf den Kern-Anteil zurück.
     */
    public static function resetForTests(): void {
        self::$contexts = null;
    }

    private static function ensureInitialized(): void {
        if (self::$contexts === null) {
            self::$contexts = self::CORE_CONTEXTS;
        }
    }
}
