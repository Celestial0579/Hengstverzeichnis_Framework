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
    private static ?string $logFile = null;

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
            ['php', '-S', self::HOST . ':' . self::PORT, '-t', $publicDir],
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
