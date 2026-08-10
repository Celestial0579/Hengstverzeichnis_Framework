<?php
// tests/Unit/I18n/LocaleCompletenessTest.php

namespace Tests\Unit\I18n;

use App\I18n\Translator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Vollständigkeits-Gate für die Kern-Sprachdateien (#198): Jede registrierte
 * Locale muss exakt den Schlüsselsatz der Quellsprache de.php abdecken.
 * Translator::t() fällt bei fehlenden Schlüsseln zwar auf Deutsch zurück,
 * gemischtsprachige Seiten sind aber kein akzeptabler Endzustand - eine
 * später ergänzte Zeichenkette, die nur in einer Sprache landet, wird hier
 * rot statt still deutsch.
 *
 * Der Provider läuft über Translator::getAvailableLocales(): Eine neu
 * registrierte Locale ist damit automatisch testpflichtig, und eine
 * Sprachdatei ohne Registrierung fällt im Umkehrschluss ebenfalls auf.
 */
class LocaleCompletenessTest extends TestCase {

    private const LANG_DIR = __DIR__ . '/../../../lang';

    /** @return array<string, mixed> */
    private static function loadLocale(string $code): array {
        $file = self::LANG_DIR . '/' . $code . '.php';
        self::assertFileExists($file, "Sprachdatei {$code}.php fehlt");
        $table = require $file;
        self::assertIsArray($table, "{$code}.php liefert kein Array");
        return $table;
    }

    /** @return array<string, array{0: string}> */
    public static function localeProvider(): array {
        $cases = [];
        foreach (array_keys(Translator::getAvailableLocales()) as $code) {
            $cases[$code] = [$code];
        }
        return $cases;
    }

    /** @return array<string, array{0: string}> */
    public static function translatedLocaleProvider(): array {
        $cases = self::localeProvider();
        unset($cases['de']);
        return $cases;
    }

    public function testEveryLangFileBelongsToARegisteredLocale(): void {
        $registered = array_keys(Translator::getAvailableLocales());
        $files = glob(self::LANG_DIR . '/*.php') ?: [];
        $onDisk = array_map(static fn(string $f) => basename($f, '.php'), $files);
        sort($registered);
        sort($onDisk);
        $this->assertSame(
            $registered,
            $onDisk,
            'lang/ und Translator::$availableLocales sind auseinandergelaufen'
        );
    }

    #[DataProvider('translatedLocaleProvider')]
    public function testLocaleCoversExactlyTheGermanKeySet(string $code): void {
        $de = self::loadLocale('de');
        $locale = self::loadLocale($code);

        $missing = array_keys(array_diff_key($de, $locale));
        $extra = array_keys(array_diff_key($locale, $de));

        $this->assertSame([], $missing, "{$code}.php fehlen Schlüssel aus de.php");
        $this->assertSame([], $extra, "{$code}.php enthält Schlüssel, die es in de.php nicht gibt");
    }

    #[DataProvider('localeProvider')]
    public function testAllValuesAreNonEmptyStrings(string $code): void {
        foreach (self::loadLocale($code) as $key => $value) {
            $this->assertIsString($value, "{$code}.php: '{$key}' ist kein String");
            $this->assertNotSame('', trim($value), "{$code}.php: '{$key}' ist leer");
        }
    }

    /**
     * Die {platzhalter} je Schlüssel müssen mengengleich mit de.php sein:
     * Ein vergessener Platzhalter fiele sonst erst auf, wenn die Seite in
     * genau dieser Sprache aufgerufen wird.
     */
    #[DataProvider('translatedLocaleProvider')]
    public function testPlaceholdersMatchTheGermanSource(string $code): void {
        $de = self::loadLocale('de');
        $locale = self::loadLocale($code);

        foreach ($de as $key => $germanValue) {
            if (!isset($locale[$key]) || !is_string($locale[$key]) || !is_string($germanValue)) {
                continue; // deckt der Schlüsselsatz-Test ab
            }
            preg_match_all('/\{[a-z0-9_]+\}/i', $germanValue, $mDe);
            preg_match_all('/\{[a-z0-9_]+\}/i', $locale[$key], $mLoc);
            $expected = $mDe[0];
            $actual = $mLoc[0];
            sort($expected);
            sort($actual);
            $this->assertSame(
                $expected,
                $actual,
                "{$code}.php: Platzhalter von '{$key}' weichen von de.php ab"
            );
        }
    }

    /**
     * format.date muss ein brauchbares date()-Format sein: nicht leer, und
     * das Ergebnis enthält Tag, Monat und vierstelliges Jahr des Datums.
     */
    #[DataProvider('localeProvider')]
    public function testDateFormatIsUsable(string $code): void {
        $table = self::loadLocale($code);
        $this->assertArrayHasKey('format.date', $table, "{$code}.php hat kein format.date");
        $format = $table['format.date'];
        $this->assertIsString($format);

        $timestamp = mktime(12, 0, 0, 7, 24, 2026);
        $rendered = date($format, (int)$timestamp);
        $this->assertStringContainsString('2026', $rendered, "{$code}: format.date ohne Jahr");
        $this->assertStringContainsString('24', $rendered, "{$code}: format.date ohne Tag");
        $this->assertMatchesRegularExpression('/(07|7\.|7 |7\/)/', $rendered . ' ', "{$code}: format.date ohne Monat");
    }

    /**
     * Maschinell erstellte Erstübersetzungen müssen als solche gekennzeichnet
     * sein (Kopfkommentar), damit Muttersprachler gezielt nachbessern können.
     */
    #[DataProvider('translatedLocaleProvider')]
    public function testMachineTranslatedFilesCarryAReviewNote(string $code): void {
        if ($code === 'en') {
            $this->addToAssertionCount(1); // en ist gepflegte Erstsprache, keine Kennzeichnung nötig
            return;
        }
        $head = (string)file_get_contents(self::LANG_DIR . '/' . $code . '.php', false, null, 0, 1200);
        $this->assertMatchesRegularExpression(
            '/[Mm]aschinell|machine[- ]translated/',
            $head,
            "{$code}.php: Kopfkommentar kennzeichnet die maschinelle Erstübersetzung nicht"
        );
    }
}
