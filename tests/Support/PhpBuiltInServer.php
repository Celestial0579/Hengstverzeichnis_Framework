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
    private const PORT = 8767;

    /** @var resource|null */
    private static $process = null;
    private static bool $shutdownRegistered = false;

    public static function baseUrl(): string {
        return 'http://' . self::HOST . ':' . self::PORT;
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
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        self::$process = proc_open(
            ['php', '-S', self::HOST . ':' . self::PORT, '-t', $publicDir],
            $descriptorSpec,
            $pipes,
            __DIR__ . '/../..'
        );

        if (!is_resource(self::$process)) {
            throw new \RuntimeException('Konnte php -S Testserver nicht starten.');
        }

        // Nicht-blockierend lesen, damit die Pipes nicht volllaufen und den
        // Server-Prozess blockieren (php -S schreibt Zugriffs-Logs nach stderr).
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        if (!self::$shutdownRegistered) {
            register_shutdown_function([self::class, 'stop']);
            self::$shutdownRegistered = true;
        }

        self::waitUntilReady();
    }

    public static function stop(): void {
        if (self::$process !== null) {
            proc_terminate(self::$process);
            proc_close(self::$process);
            self::$process = null;
        }
    }

    private static function waitUntilReady(): void {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $connection = @fsockopen(self::HOST, self::PORT, $errno, $errstr, 0.5);
            if ($connection !== false) {
                fclose($connection);
                return;
            }
            usleep(100_000);
        }

        throw new \RuntimeException('php -S Testserver ist nach 10s nicht erreichbar geworden.');
    }
}
