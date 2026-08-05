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
 */
final class Translator {

    /** @var array<string, string> locale-code => Anzeigename */
    private static array $availableLocales = [
        'de' => 'Deutsch',
        'en' => 'English',
    ];

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
        self::$locale = isset(self::$availableLocales[$locale]) ? $locale : self::$fallbackLocale;
        self::$initialized = true;
    }

    public static function getLocale(): string {
        if (!self::$initialized) {
            self::init(self::$fallbackLocale);
        }
        return self::$locale;
    }

    /** @return array<string, string> locale-code => Anzeigename, für Sprachumschalter/Admin-Dropdown */
    public static function getAvailableLocales(): array {
        return self::$availableLocales;
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

        $dir = $domain === 'core' ? (__DIR__ . '/../../lang') : (self::$domainDirs[$domain] ?? null);

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
        self::$cache = [];
    }
}
