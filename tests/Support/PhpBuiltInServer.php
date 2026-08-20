<?php
// tests/Support/PhpBuiltInServer.php

namespace Tests\Support;

/**
 * Startet `php -S` (Docroot public/) als eigenen Subprozess für die
 * Funktionstests und beendet ihn wieder. Läuft in einem eigenen Prozess statt
 * die App in-process einzubinden, weil praktisch jede Controller-Aktion mit
 * `header()+exit;` endet (Redirect nach POST) - das würde den PHPUnit-Prozess
 * selbst beenden, siehe docs/development.md, Abschnitt "Tests".
 */
class PhpBuiltInServer {

    private const HOST = '127.0.0.1';

    /**
     * Standard-Port. Über die Umgebungsvariable HV_TEST_PORT überschreibbar.
     *
     * Wozu überschreibbar: Framework und Addons benutzen denselben Helfer und
     * damit denselben Port. Laufen beide Suiten gleichzeitig - auf einem
     * Entwicklungshost keine Seltenheit -, bricht die zweite mit der Meldung
     * aus refuseIfPortIsTaken() ab. Die Meldung ist richtig und der Abbruch
     * auch; was fehlte, war ein Ausweg, der nicht "warte, bis der andere
     * fertig ist" heisst.
     *
     *     HV_TEST_PORT=8768 composer test -- --testsuite Functional
     *
     * Der Abbruch bei belegtem Port bleibt unverändert bestehen: Ein Lauf
     * gegen eine fremde Instanz ist nach wie vor kein Ergebnis.
     */
    private const PORT_DEFAULT = 8767;

    private static function port(): int {
        $roh = getenv('HV_TEST_PORT');
        if ($roh === false || !ctype_digit((string)$roh)) {
            return self::PORT_DEFAULT;
        }
        $port = (int)$roh;
        // Ausserhalb des unprivilegierten Bereichs ist der Wert ein Vertipper.
        return ($port >= 1024 && $port <= 65535) ? $port : self::PORT_DEFAULT;
    }

    /** @var resource|null */
    private static $process = null;
    private static bool $shutdownRegistered = false;
    private static ?string $logFile = null;

    public static function baseUrl(): string {
        return 'http://' . self::HOST . ':' . self::port();
    }

    /**
     * Startet den Server einmalig pro PHPUnit-Prozess (mehrere Testklassen teilen
     * sich dieselbe Instanz). Ein register_shutdown_function-Hook beendet den
     * Subprozess zuverlässig, auch wenn PHPUnit vorzeitig abbricht.
     */
    public static function ensureStarted(): void {
        if (self::$process !== null) {
            return;
        }

        $publicDir = __DIR__ . '/../../public';

        self::refuseIfPortIsTaken();

        // stdout/stderr in eine Logdatei umleiten statt in Pipes. php -S schreibt
        // pro Request eine Access-Log-Zeile nach stderr; ginge das in eine Pipe,
        // die niemand ausliest, liefe deren Kernel-Buffer (~64 KB) über die volle
        // Functional-Suite hinweg voll und der Single-Worker-Server blockierte beim
        // nächsten write() - danach liefen alle weiteren Requests in den curl-Timeout
        // (Issue #102). stream_set_blocking auf der Leseseite verhindert das NICHT;
        // eine Datei als Deskriptor blockiert dagegen nie.
        self::$logFile = tempnam(sys_get_temp_dir(), 'hengst_test_server_');
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['file', self::$logFile, 'a'],
            2 => ['file', self::$logFile, 'a'],
        ];

        self::$process = proc_open(
            // date.timezone ausdrücklich von diesem Prozess übernehmen: Test
            // und App teilen sich eine Datenbank, und seit die Verbindung ihre
            // Sitzungs-Zeitzone an PHP angleicht (Database::alignSessionTimeZone())
            // müssen beide Seiten dieselbe Uhr benutzen. Sonst schreibt der
            // Test Zeitstempel in einer anderen Zeitzone, als die App sie
            // liest - das äußert sich nicht als Fehler, sondern als
            // unerklärlich abgelaufener Cache.
            ['php', '-d', 'date.timezone=' . date_default_timezone_get(),
                '-S', self::HOST . ':' . self::port(), '-t', $publicDir],
            $descriptorSpec,
            $pipes,
            __DIR__ . '/../..'
        );

        if (!is_resource(self::$process)) {
            throw new \RuntimeException('Konnte php -S Testserver nicht starten.');
        }

        if (!self::$shutdownRegistered) {
            register_shutdown_function([self::class, 'stop']);
            self::$shutdownRegistered = true;
        }

        self::waitUntilReady();
    }

    /**
     * Bricht ab, wenn auf dem Port schon jemand antwortet.
     *
     * DER GRUND, WARUM ES DIESE PRÜFUNG GIBT: Der Port ist fest, und die
     * Addons-Suite startet über den vendorierten Kern denselben Server. Läuft
     * eine zweite Suite an, während die erste noch lebt, kann `php -S` nicht
     * binden - `proc_open()` liefert trotzdem eine Ressource, und
     * `waitUntilReady()` sieht nur, dass IRGENDWER auf dem Port antwortet.
     * Die gesamte Suite lief dann gegen die fremde Instanz samt deren bereits
     * eingerichteter Datenbank.
     *
     * Das Fehlerbild führt in die Irre: Die Ersteinrichtung meldet `/login`
     * statt `/2fa/setup`, danach scheitert jeder Test mit "Table users doesn't
     * exist", obwohl die eigene Datenbank frisch angelegt wurde. Es sieht nach
     * einem Schema-Problem aus und ist ein Portproblem.
     *
     * Deshalb hier lieber ein klarer Abbruch als ein grüner oder wirr roter
     * Lauf: Der nächtliche `devhost-tests`-Lauf prüft Framework, Addons und
     * E2E innerhalb weniger Minuten und meldet unbeaufsichtigt GitHub-Issues -
     * "konnte nicht prüfen" und "geprüft, ist kaputt" sind verschiedene
     * Aussagen.
     */
    private static function refuseIfPortIsTaken(): void {
        $connection = @fsockopen(self::HOST, self::port(), $errno, $errstr, 0.5);
        if ($connection === false) {
            return;
        }
        fclose($connection);

        throw new \RuntimeException(sprintf(
            "Auf %s:%d antwortet bereits ein Server - dieser Lauf würde gegen eine FREMDE "
            . "Instanz testen (samt deren Datenbank) statt gegen die eigene.\n"
            . "Typische Ursachen: eine parallel laufende Functional-Suite (auch die des "
            . "Addons-Repos nutzt diesen Server), ein Rest aus einem abgebrochenen Lauf, "
            . "oder der nächtliche devhost-tests-Lauf.\n"
            . "Nachsehen mit:  ss -ltnp | grep %d",
            self::HOST,
            self::port(),
            self::port()
        ));
    }

    /**
     * Ist der eigene Subprozess noch am Leben? Konnte `php -S` den Port nicht
     * binden, endet er sofort - ohne diese Prüfung liefe die Suite gegen den
     * Server weiter, der ihn hält.
     */
    private static function ownProcessDied(): bool {
        if (!is_resource(self::$process)) {
            return true;
        }
        $status = proc_get_status(self::$process);

        return $status['running'] === false;
    }

    public static function stop(): void {
        if (self::$process !== null) {
            proc_terminate(self::$process);
            proc_close(self::$process);
            self::$process = null;
        }
        if (self::$logFile !== null && is_file(self::$logFile)) {
            unlink(self::$logFile);
            self::$logFile = null;
        }
    }

    private static function waitUntilReady(): void {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            // Zuerst der eigene Prozess: Antwortet jemand auf dem Port, während
            // der eigene php -S längst gestorben ist, wäre das erneut die
            // fremde Instanz - nur diesmal durch ein Wettrennen entstanden,
            // das die Vorabprüfung nicht sehen konnte.
            if (self::ownProcessDied()) {
                throw new \RuntimeException(sprintf(
                    "Der eigene php -S auf %s:%d ist sofort beendet worden (vermutlich Port belegt).\n"
                    . "Protokoll: %s",
                    self::HOST,
                    self::port(),
                    self::$logFile !== null ? (string)@file_get_contents(self::$logFile) : '(keines)'
                ));
            }

            $connection = @fsockopen(self::HOST, self::port(), $errno, $errstr, 0.5);
            if ($connection !== false) {
                fclose($connection);
                return;
            }
            usleep(100_000);
        }

        throw new \RuntimeException('php -S Testserver ist nach 10s nicht erreichbar geworden.');
    }
}
