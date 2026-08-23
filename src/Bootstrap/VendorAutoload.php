<?php
// src/Bootstrap/VendorAutoload.php

namespace App\Bootstrap;

/**
 * Findet `vendor/autoload.php` — auch dort, wo es nicht neben `public/` liegt.
 *
 * Seit #353 hat die Anwendung eine Laufzeit-Abhängigkeit
 * (`web-auth/webauthn-lib`), und damit braucht sie den Composer-Autoloader.
 * Das naheliegende `require __DIR__ . '/../vendor/autoload.php'` in
 * `public/index.php` ist dafür zu kurz gesprungen, und der Grund fällt nicht
 * beim Entwickeln auf, sondern erst in einer fremden Testsuite:
 *
 * **Das Addons-Repo bindet den Kern selbst per Composer ein** und startet ihn
 * mit `php -S -t <vendor>/hengstverzeichnis/framework/public`. Dort liegt der
 * Kern INNERHALB eines vendor-Baums, und ein eigenes `vendor/` daneben gibt es
 * nicht — Composer installiert flach. Der feste Pfad zeigte also ins Leere,
 * und jeder Functional-Test des Addons-Repos wäre im ersten Request gestorben.
 *
 * Deshalb wird von der eigenen Lage aus nach oben gesucht. Das deckt beide
 * Anordnungen ab:
 *
 *     /app/public/index.php                                  -> /app/vendor/autoload.php
 *     /addons/vendor/hengstverzeichnis/framework/public/…     -> /addons/vendor/autoload.php
 *
 * Die Suche endet am Dateisystem-Wurzelverzeichnis und ist auf eine
 * überschaubare Tiefe begrenzt — eine unbegrenzte Schleife über `dirname()`
 * ist auf einem kaputten Pfad eine Endlosschleife, und die fiele als
 * hängender Request auf, nicht als Fehler.
 */
final class VendorAutoload {

    /** So viele Ebenen wird höchstens nach oben gesucht. */
    private const MAX_EBENEN = 12;

    /**
     * Lädt den Composer-Autoloader.
     *
     * @param string $start Verzeichnis, von dem aus gesucht wird.
     * @throws \RuntimeException wenn kein vendor/autoload.php zu finden ist.
     */
    public static function laden(string $start): void {
        $pfad = self::finden($start);

        if ($pfad === null) {
            // Fail-closed und mit Ansage. Ein stilles Weitermachen führte zu
            // einem "Class not found" mitten im Anmeldevorgang - also an der
            // Stelle, an der eine klare Meldung am meisten wert ist.
            throw new \RuntimeException(
                'vendor/autoload.php wurde nicht gefunden. Seit v0.10 braucht die Anwendung '
                . 'die mitgelieferten Abhängigkeiten. Im Release-Archiv liegen sie bei; '
                . 'in einer Arbeitskopie hilft "composer install --no-dev".'
            );
        }

        require_once $pfad;
    }

    /**
     * Sucht `vendor/autoload.php` von $start aufwärts.
     *
     * Öffentlich, weil Tests den Fund prüfen wollen, ohne ihn zu laden — ein
     * geladener Autoloader lässt sich im selben Prozess nicht zurücknehmen.
     */
    public static function finden(string $start): ?string {
        $verzeichnis = rtrim($start, '/');

        for ($i = 0; $i < self::MAX_EBENEN; $i++) {
            $kandidat = $verzeichnis . '/vendor/autoload.php';
            if (is_file($kandidat)) {
                return $kandidat;
            }

            $eltern = \dirname($verzeichnis);
            if ($eltern === $verzeichnis) {
                break;                  // Wurzel erreicht
            }
            $verzeichnis = $eltern;
        }

        return null;
    }
}
