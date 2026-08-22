<?php
// src/I18n/Translator.php

namespace App\I18n;

/**
 * Minimalistisches i18n-Gerüst (#48): flache Array-basierte Sprachdateien
 * statt gettext, passend zur "keine externen Abhängigkeiten"-Philosophie
 * (siehe docs/architecture.md).
 *
 * Kern-Übersetzungen liegen unter `lang/<locale>.php` im Projekt-Root
 * (Domain `core`). Plugins können über eine eigene `lang/<locale>.php` in
 * ihrem Plugin-Verzeichnis zusätzliche, unter ihrem Slug als eigene Domain
 * namensraum-getrennte Übersetzungen bereitstellen - siehe registerDomain()
 * und App\Plugin\PluginManager, das dies automatisch für jedes Plugin mit
 * eigenem `lang/`-Verzeichnis übernimmt (Konvention, keine Manifest-Pflicht,
 * analog zum Default-Entry `Plugin.php`).
 *
 * ZWEI RICHTUNGEN, NICHT EINE (#344). Der Fall oben ist: ein Addon bringt
 * SEINE EIGENEN Texte mit. Seit v0.9 gibt es den umgekehrten: ein Addon
 * bringt eine ZUSÄTZLICHE SPRACHE FÜR DIE KERN-DOMÄNE mit
 * (`lang/core/<locale>.php` im Plugin-Verzeichnis, siehe
 * registerCoreLocale()). Im Kern liegen nur noch Deutsch und Englisch; die
 * übrigen zehn Sprachen sind Sprach-Addons.
 *
 * Der Grund ist nicht Platz, sondern Pflegbarkeit: Zwölf vollständige
 * Sprachdateien mit je über dreihundert Schlüsseln machten jeden neuen Text
 * im Kern zu einer Übersetzungsaufgabe in elf Fremdsprachen, bevor die
 * Testsuite grün wurde.
 */
final class Translator {

    /**
     * Die BEKANNTEN Sprachen: Code => Eigenname (#198).
     *
     * "Bekannt" heisst nicht "vorhanden". Seit #344 liegen im Kern nur noch
     * `de` und `en`; für die übrigen gilt diese Liste als Namensregister -
     * verfügbar wird eine Sprache erst, wenn es eine Datei dazu gibt, im Kern
     * oder in einem Sprach-Addon (siehe getAvailableLocales()).
     *
     * Der Name steht bewusst HIER und nicht im Addon: Sonst erfände jedes
     * Sprach-Addon seine eigene Schreibweise, und im Umschalter stünde einmal
     * "Nederlands" und einmal "Niederländisch".
     *
     * @var array<string, string>
     */
    private static array $knownLocales = [
        'de' => 'Deutsch',
        'en' => 'English',
        'da' => 'Dansk',
        'nl' => 'Nederlands',
        'fr' => 'Français',
        'lb' => 'Lëtzebuergesch',
        'it' => 'Italiano',
        'cs' => 'Čeština',
        'pl' => 'Polski',
        // Norwegisch bewusst als Bokmål (nb), die mit Abstand verbreitetste
        // der beiden norwegischen Schriftsprachen; Nynorsk (nn) kann bei
        // Bedarf später als eigene Locale ergänzt werden (#198).
        'nb' => 'Norsk bokmål',
        'sv' => 'Svenska',
        'fi' => 'Suomi',
    ];

    /**
     * Sprachen, die ein Addon für die Kern-Domäne mitbringt (#344).
     *
     * @var array<string, string> locale-code => Verzeichnis mit <locale>.php
     */
    private static array $addonCoreLocales = [];

    /** @var array<string, string> Von Addons gemeldete Anzeigenamen für unbekannte Codes */
    private static array $addonLocaleLabels = [];

    private static string $fallbackLocale = 'de';

    private static string $locale = 'de';

    private static bool $initialized = false;

    /** @var array<string, string> Domain ("core" oder Plugin-Slug) => Verzeichnis mit lang/<locale>.php-Dateien */
    private static array $domainDirs = [];

    /** @var array<string, array<string, array<string, string>>> [domain][locale] => flaches Key-Value-Array, pro Request gecacht */
    private static array $cache = [];

    private function __construct() {}

    /**
     * Setzt die aktive Locale für den restlichen Request. Ungültige/nicht
     * verfügbare Locales fallen sicher auf $fallbackLocale zurück, statt
     * eine Exception zu werfen - ein manipulierter/veralteter Locale-Wert
     * (z. B. aus einer alten Session) darf nie zu einem Fehler führen.
     */
    public static function init(string $locale): void {
        self::$locale = isset(self::getAvailableLocales()[$locale]) ? $locale : self::$fallbackLocale;
        self::$initialized = true;
    }

    public static function getLocale(): string {
        if (!self::$initialized) {
            self::init(self::$fallbackLocale);
        }
        return self::$locale;
    }

    /**
     * VERFÜGBARE Sprachen: die, zu denen es tatsächlich eine Datei gibt -
     * im Kern (`lang/<code>.php`) oder in einem Sprach-Addon (#344).
     *
     * Vorher gab diese Methode schlicht das Namensregister zurück. Das ging,
     * solange jede bekannte Sprache auch im Kern lag; seit #344 wäre es eine
     * Lüge: Der Umschalter böte zehn Sprachen an, von denen neun beim Klick
     * auf Deutsch zurückfielen.
     *
     * @return array<string, string> locale-code => Anzeigename
     */
    public static function getAvailableLocales(): array {
        $verfuegbar = [];

        foreach (self::$knownLocales as $code => $name) {
            if (self::hatQuelle($code)) {
                $verfuegbar[$code] = $name;
            }
        }

        // Ein Addon darf auch eine Sprache mitbringen, die das Register nicht
        // kennt - dann liefert es den Namen selbst.
        foreach (self::$addonCoreLocales as $code => $dir) {
            if (!isset($verfuegbar[$code])) {
                $verfuegbar[$code] = self::$addonLocaleLabels[$code] ?? $code;
            }
        }

        return $verfuegbar;
    }

    /**
     * Alle bekannten Sprachen samt Namen - unabhängig davon, ob es eine Datei
     * dazu gibt. Für die Anzeige im Adminbereich ("nl: kein Sprach-Addon
     * installiert") und für die Warnung vor einem Update.
     *
     * @return array<string, string>
     */
    public static function knownLocales(): array {
        return self::$knownLocales;
    }

    /**
     * Meldet eine Sprache, die ein Addon für die KERN-Domäne mitbringt (#344).
     *
     * Aufgerufen von App\Plugin\PluginManager für jedes aktive Plugin mit
     * einem Verzeichnis `lang/core/` - Konvention, keine Manifest-Pflicht,
     * genau wie bei der eigenen Domäne eines Plugins.
     */
    public static function registerCoreLocale(string $locale, string $dir, ?string $label = null): void {
        $locale = trim($locale);
        if ($locale === '' || !is_dir($dir)) {
            return;
        }

        self::$addonCoreLocales[$locale] = $dir;
        if ($label !== null && $label !== '') {
            self::$addonLocaleLabels[$locale] = $label;
        }
        unset(self::$cache['core'][$locale]);
    }

    /** Gibt es zu dieser Sprache eine Kern-Tabelle - im Kern oder in einem Addon? */
    public static function hatQuelle(string $locale): bool {
        return is_file(self::coreLangDir() . '/' . $locale . '.php')
            || isset(self::$addonCoreLocales[$locale]);
    }

    /**
     * Welche Sprache kommt woher? Für die Anzeige im Adminbereich.
     *
     * @return array<string, string> locale-code => 'kern' | 'addon' | 'fehlt'
     */
    public static function localeHerkunft(): array {
        $herkunft = [];
        foreach (array_keys(self::$knownLocales) as $code) {
            if (is_file(self::coreLangDir() . '/' . $code . '.php')) {
                $herkunft[$code] = 'kern';
            } elseif (isset(self::$addonCoreLocales[$code])) {
                $herkunft[$code] = 'addon';
            } else {
                $herkunft[$code] = 'fehlt';
            }
        }

        return $herkunft;
    }

    public static function coreLangDir(): string {
        return __DIR__ . '/../../lang';
    }

    /**
     * Vom Betreiber AKTIVIERTE Locales (#198): Teilmenge der verfügbaren,
     * gesteuert über den Settings-Schlüssel `active_locales`
     * (kommagetrennte Codes; leer/nicht gesetzt = alle verfügbaren aktiv,
     * damit neu hinzukommende Sprachen nicht still deaktiviert starten).
     * Die Quellsprache (de, Fallback) und die konfigurierte Standardsprache
     * sind IMMER aktiv - sonst könnte sich ein Betreiber die Oberfläche
     * sprachlos schalten. Konsumenten: Sprachumschalter im Footer und die
     * ?lang=-Validierung; die Sprachdateien selbst bleiben vollständig an
     * Bord (LocaleCompletenessTest prüft weiterhin ALLE verfügbaren).
     *
     * @param array<string, mixed> $settings
     * @return array<string, string> locale-code => Anzeigename
     */
    public static function activeLocales(array $settings): array {
        $available = self::getAvailableLocales();
        $raw = trim((string)($settings['active_locales'] ?? ''));
        if ($raw === '') {
            return $available;
        }

        $activeCodes = array_filter(array_map('trim', explode(',', $raw)));
        $defaultLanguage = (string)($settings['language'] ?? self::$fallbackLocale);

        $active = [];
        foreach ($available as $code => $label) {
            if ($code === self::$fallbackLocale
                || $code === $defaultLanguage
                || in_array($code, $activeCodes, true)) {
                $active[$code] = $label;
            }
        }
        return $active;
    }

    /**
     * Wie vollständig ist eine Sprache? (#344)
     *
     * Ohne diese Anzeige verrottet eine Addon-Sprache unbemerkt: Der Kern
     * bekommt neue Texte, das Sprach-Addon zieht nicht nach, und die
     * fehlenden Schlüssel erscheinen still auf Deutsch. Eine gemischtsprachige
     * Seite sieht nicht nach einem Fehler aus - sie sieht nach einer
     * unfertigen Übersetzung aus, und niemand meldet sie.
     *
     * @return array{vorhanden: int, gesamt: int}
     */
    public static function abdeckung(string $locale): array {
        $quelle = self::loadTable('core', self::$fallbackLocale);
        $gesamt = count($quelle);

        if ($locale === self::$fallbackLocale) {
            return ['vorhanden' => $gesamt, 'gesamt' => $gesamt];
        }

        $tabelle = self::loadTable('core', $locale);
        $vorhanden = 0;
        foreach (array_keys($quelle) as $schluessel) {
            if (($tabelle[$schluessel] ?? '') !== '') {
                $vorhanden++;
            }
        }

        return ['vorhanden' => $vorhanden, 'gesamt' => $gesamt];
    }

    /**
     * Sprachen, die der Betreiber eingestellt hat, zu denen es aber keine
     * Datei (mehr) gibt (#344).
     *
     * DER FALL, DEN #344 AUSDRUECKLICH NENNT: "Bestehende Installationen
     * dürfen nicht stumm auf Deutsch fallen." Wer auf Niederländisch lief und
     * den Kern hebt, ohne das Sprach-Addon zu installieren, hat sonst keine
     * Sprache mehr - und die Sprache ist das, was ein Benutzer als Erstes
     * sieht. Diese Methode ist die Grundlage der Warnung im Adminbereich und
     * vor dem Update.
     *
     * @param array<string, mixed> $settings
     * @return array<string, string> locale-code => Anzeigename
     */
    public static function fehlendeSprachen(array $settings): array {
        $gewuenscht = [];

        $standard = trim((string)($settings['language'] ?? ''));
        if ($standard !== '') {
            $gewuenscht[$standard] = true;
        }
        foreach (array_filter(array_map('trim', explode(',', (string)($settings['active_locales'] ?? '')))) as $code) {
            $gewuenscht[$code] = true;
        }

        $verfuegbar = self::getAvailableLocales();
        $fehlend = [];
        foreach (array_keys($gewuenscht) as $code) {
            if (isset($verfuegbar[$code])) {
                continue;
            }
            // Unbekannte Codes ("klingonisch") sind kein fehlendes
            // Sprach-Addon, sondern ein Tippfehler - sie werden ohnehin
            // verworfen und gehoeren nicht in die Warnung.
            if (isset(self::$knownLocales[$code])) {
                $fehlend[$code] = self::$knownLocales[$code];
            }
        }

        return $fehlend;
    }

    /**
     * Löst die für den aktuellen Request gültige Locale auf - die EINE Stelle
     * für die Auswahlregel, die vorher als Kopie in
     * BaseController::initLocale() und PluginPage::initLocale() lebte und
     * dort auseinandergelaufen ist (#220: die PluginPage-Kopie kannte die
     * active_locales-Prüfung aus #198 nicht, deaktivierte Sprachen blieben
     * über Plugin-Seiten dauerhaft erreichbar).
     *
     * Regel: `?lang=xx` wird nur übernommen (und in der Session persistiert),
     * wenn die Sprache vom Betreiber AKTIVIERT ist. Danach gilt Session-Wahl
     * vor Admin-Standardsprache. Ist die so bestimmte Locale inaktiv (z. B.
     * eine Session-Wahl, deren Sprache der Betreiber inzwischen deaktiviert
     * hat), fällt sie auf die Standardsprache zurück - und die veraltete
     * Session-Wahl wird ENTFERNT, damit sie nicht auf jeder Folgeseite
     * erneut geprüft und verworfen werden muss und der Besucher nach einer
     * Re-Aktivierung der Sprache nicht überraschend wieder dort landet.
     *
     * Setzt nur den Session-Eintrag, nicht die aktive Locale - der Aufrufer
     * gibt den Rückgabewert an init() weiter (dort greift zusätzlich das
     * Sicherheitsnetz gegen gänzlich unbekannte Codes).
     *
     * @param array<string, mixed> $settings
     * @return string Die aufgelöste, aktive Locale.
     */
    public static function resolveRequestLocale(array $settings): string {
        $active = self::activeLocales($settings);

        $requested = $_GET['lang'] ?? null;
        if (is_string($requested) && isset($active[$requested])) {
            $_SESSION['locale'] = $requested;
        }

        $locale = (string)($_SESSION['locale'] ?? ($settings['language'] ?? self::$fallbackLocale));
        if (!isset($active[$locale])) {
            unset($_SESSION['locale']);
            $locale = (string)($settings['language'] ?? self::$fallbackLocale);
        }
        return $locale;
    }

    /**
     * Registriert ein zusätzliches Sprachdatei-Verzeichnis unter einer eigenen
     * Domain (z. B. ein Plugin-Slug). "Wer zuerst registriert, gewinnt" gegen
     * versehentliches Überschreiben - analog zu
     * App\Permission\PermissionRegistry::registerAction(). Die Domain `core`
     * ist reserviert und kann nicht überschrieben werden.
     */
    public static function registerDomain(string $domain, string $dir): void {
        if ($domain === 'core' || isset(self::$domainDirs[$domain])) {
            return;
        }
        self::$domainDirs[$domain] = $dir;
    }

    /**
     * Übersetzt einen Schlüssel. Fehlt er in der aktiven Locale, wird auf
     * $fallbackLocale zurückgefallen; fehlt er auch dort, wird der Schlüssel
     * selbst zurückgegeben - bewusst sichtbar statt einer leeren
     * Zeichenkette, damit fehlende Übersetzungen in der UI sofort auffallen
     * statt stillschweigend zu verschwinden.
     *
     * @param string $key Flacher Schlüssel, z. B. 'nav.home'.
     * @param array<string, string|int|float> $params Platzhalter im Format {name},
     *   z. B. t('greeting', ['name' => 'X']) für den Wert "Hallo {name}".
     * @param string $domain 'core' für Kern-Übersetzungen, sonst ein zuvor über
     *   registerDomain() registrierter Bezeichner (z. B. ein Plugin-Slug).
     */
    public static function t(string $key, array $params = [], string $domain = 'core'): string {
        if (!self::$initialized) {
            self::init(self::$fallbackLocale);
        }

        $value = self::lookup($domain, self::$locale, $key);
        if ($value === null && self::$locale !== self::$fallbackLocale) {
            $value = self::lookup($domain, self::$fallbackLocale, $key);
        }

        return self::interpolate($value ?? $key, $params);
    }

    private static function lookup(string $domain, string $locale, string $key): ?string {
        return self::loadTable($domain, $locale)[$key] ?? null;
    }

    /** @return array<string, string> */
    private static function loadTable(string $domain, string $locale): array {
        if (isset(self::$cache[$domain][$locale])) {
            return self::$cache[$domain][$locale];
        }

        // Kern-Domäne: erst die eigene Datei, sonst die eines Sprach-Addons
        // (#344). Die Reihenfolge ist Absicht - was im Kern liegt, gilt.
        if ($domain === 'core') {
            $dir = is_file(self::coreLangDir() . '/' . $locale . '.php')
                ? self::coreLangDir()
                : (self::$addonCoreLocales[$locale] ?? null);
        } else {
            $dir = self::$domainDirs[$domain] ?? null;
        }

        $table = [];
        if ($dir !== null) {
            $file = rtrim($dir, '/') . '/' . $locale . '.php';
            if (is_file($file)) {
                $loaded = require $file;
                if (is_array($loaded)) {
                    $table = $loaded;
                }
            }
        }

        self::$cache[$domain][$locale] = $table;
        return $table;
    }

    private static function interpolate(string $value, array $params): string {
        if (empty($params)) {
            return $value;
        }

        $replacements = [];
        foreach ($params as $name => $paramValue) {
            $replacements['{' . $name . '}'] = (string)$paramValue;
        }

        return strtr($value, $replacements);
    }

    /**
     * Ausschließlich für Tests: setzt den kompletten statischen Zustand
     * zurück, damit aufeinanderfolgende Tests sich nicht gegenseitig über
     * Cache/registrierte Domains/aktive Locale beeinflussen.
     */
    public static function resetForTests(): void {
        self::$locale = self::$fallbackLocale;
        self::$initialized = false;
        self::$domainDirs = [];
        self::$addonCoreLocales = [];
        self::$addonLocaleLabels = [];
        self::$cache = [];
    }
}
