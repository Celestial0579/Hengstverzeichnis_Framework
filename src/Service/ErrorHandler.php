<?php
// src/Service/ErrorHandler.php

namespace App\Service;

/**
 * Zentrale Fehlerbehandlung.
 *
 * WARUM ES DAS BRAUCHT: `error_reporting(0)` in Produktion schaltet nicht nur
 * die Anzeige ab, sondern auch die Protokollierung - die Stufe ist eine Maske
 * für BEIDES. Zusammen mit dem fehlenden Exception-Handler hieß das: Eine
 * unbehandelte PDOException lieferte dem Besucher eine leere Seite und
 * hinterließ nirgends eine Spur. Kein Eintrag im Apache-Log, keiner in der
 * Datenbank (die ja gerade das Problem sein kann), keiner im Audit-Log. Ein
 * Betreiber konnte nicht einmal feststellen, DASS etwas kaputt war, geschweige
 * denn was - und ein Angreifer, der Fehler provoziert, um eine Anwendung
 * abzutasten, tat das unbeobachtet (OWASP A09).
 *
 * Die Trennung ist deshalb sauber gezogen:
 *   error_reporting  IMMER E_ALL   - alles wird betrachtet
 *   log_errors       IMMER an      - und protokolliert
 *   display_errors   nur in dev    - aber nur dort auch angezeigt
 *
 * Das Ziel ist absichtlich `error_log()` und nicht die Datenbank: Der Fall,
 * für den man die Protokollierung am dringendsten braucht, ist der, in dem die
 * Datenbank nicht antwortet. Unter Apache landet das im Fehlerprotokoll des
 * VHosts, im Container auf stderr und damit in `docker logs`.
 */
final class ErrorHandler {

    private static bool $registered = false;

    public static function register(bool $isProduction): void {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        error_reporting(E_ALL);
        ini_set('log_errors', '1');
        ini_set('display_errors', $isProduction ? '0' : '1');

        set_exception_handler(static function (\Throwable $e) use ($isProduction): void {
            self::logThrowable($e);
            self::respondWithGenericError($isProduction);
        });

        // Fatale Fehler (Speicher erschöpft, Zeitlimit, Typfehler außerhalb
        // eines try-Blocks) laufen nicht durch den Exception-Handler.
        register_shutdown_function(static function () use ($isProduction): void {
            $last = error_get_last();
            if ($last === null || !in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }
            error_log(sprintf(
                'Fataler Fehler: %s in %s:%d',
                $last['message'],
                $last['file'],
                $last['line']
            ));
            self::respondWithGenericError($isProduction);
        });
    }

    private static function logThrowable(\Throwable $e): void {
        error_log(sprintf(
            'Unbehandelte %s: %s in %s:%d%s%s',
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            PHP_EOL,
            $e->getTraceAsString()
        ));
    }

    /**
     * Antwort an den Browser: bewusst eine handgeschriebene Minimalseite und
     * kein render() über den BaseController. Der Handler läuft gerade dann,
     * wenn etwas Grundlegendes kaputt ist - ein Layout, das Einstellungen aus
     * der Datenbank lädt, wäre der zweite Fehler im selben Request.
     */
    private static function respondWithGenericError(bool $isProduction): void {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');

        if ($isProduction) {
            echo "<!doctype html><html lang=\"de\"><head><meta charset=\"utf-8\">"
                . "<title>Serverfehler</title></head><body>"
                . "<h1>Es ist ein Fehler aufgetreten</h1>"
                . "<p>Die Anfrage konnte nicht bearbeitet werden. Der Vorfall wurde protokolliert.</p>"
                . "</body></html>";
            return;
        }

        // In der Entwicklungsumgebung hat PHP die Details bereits über
        // display_errors ausgegeben; hier nur der Statuscode und ein Hinweis.
        echo "<!doctype html><html lang=\"de\"><head><meta charset=\"utf-8\">"
            . "<title>Serverfehler (Entwicklung)</title></head><body>"
            . "<h1>Serverfehler</h1><p>Details siehe Ausgabe oben bzw. Fehlerprotokoll.</p>"
            . "</body></html>";
    }
}
