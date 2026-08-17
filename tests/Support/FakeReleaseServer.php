<?php
// tests/Support/FakeReleaseServer.php

namespace Tests\Support;

/**
 * Statischer Dateiserver (`php -S` mit Docroot) für Update-Tests: liefert
 * eine Release-Liste im GitHub-Format, das zugehörige Shared-Hosting-Zip und
 * die SHA256SUMS.txt aus einem temporären Verzeichnis aus.
 *
 * Damit läuft App\Service\UpdateService::performUpdate() in Tests komplett
 * ohne GitHub durch - inklusive echtem Download und echter Prüfsummen-
 * Verifikation (verifyArchiveChecksum), statt beides zu umgehen. Möglich ist
 * das, weil UPDATE_RELEASES_URL die Release-Liste übersteuert und die dort
 * eingetragenen Asset-URLs frei wählbar sind; http statt https erlaubt
 * UpdateService::allowedProtocols() ausschließlich in der
 * Entwicklungsumgebung (APP_ENV=development, wie sie tests/bootstrap.php
 * setzt).
 *
 * Aufbau bewusst wie FakeS3Server (eigener Port, Logdatei statt Pipes gegen
 * den blockierenden Single-Worker, siehe Issue #102) - hier reicht aber der
 * eingebaute Datei-Handler von `php -S`, es braucht kein Router-Skript.
 */
class FakeReleaseServer {

    private const HOST = '127.0.0.1';
    private const PORT = 8772;

    /** @var resource|null */
    private static $process = null;
    private static bool $shutdownRegistered = false;
    private static ?string $storageDir = null;
    private static ?string $logFile = null;

    public static function baseUrl(): string {
        return 'http://' . self::HOST . ':' . self::PORT;
    }

    public static function storageDir(): string {
        if (self::$storageDir === null) {
            throw new \RuntimeException('FakeReleaseServer läuft nicht - zuerst ensureStarted() aufrufen.');
        }
        return self::$storageDir;
    }

    public static function ensureStarted(): void {
        if (self::$process !== null) {
            return;
        }

        self::$storageDir = sys_get_temp_dir() . '/fake_release_' . uniqid();
        mkdir(self::$storageDir);

        self::$logFile = tempnam(sys_get_temp_dir(), 'fake_release_server_');
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['file', self::$logFile, 'a'],
            2 => ['file', self::$logFile, 'a'],
        ];

        self::$process = proc_open(
            ['php', '-S', self::HOST . ':' . self::PORT, '-t', self::$storageDir],
            $descriptorSpec,
            $pipes,
            __DIR__ . '/../..',
            getenv() ?: []
        );

        if (!is_resource(self::$process)) {
            throw new \RuntimeException('Konnte Fake-Release-Testserver nicht starten.');
        }

        if (!self::$shutdownRegistered) {
            register_shutdown_function([self::class, 'stop']);
            self::$shutdownRegistered = true;
        }

        self::waitUntilReady();
    }

    /** Legt eine ausgelieferte Datei an und gibt ihre öffentliche URL zurück. */
    public static function putFile(string $name, string $contents): string {
        file_put_contents(self::storageDir() . '/' . $name, $contents);
        return self::baseUrl() . '/' . $name;
    }

    /** Entfernt alle ausgelieferten Dateien, ohne den Server neu zu starten. */
    public static function clear(): void {
        if (self::$storageDir === null) {
            return;
        }
        foreach (glob(self::$storageDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
    }

    public static function stop(): void {
        if (self::$process !== null) {
            proc_terminate(self::$process);
            proc_close(self::$process);
            self::$process = null;
        }
        if (self::$storageDir !== null && is_dir(self::$storageDir)) {
            foreach (glob(self::$storageDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir(self::$storageDir);
            self::$storageDir = null;
        }
        if (self::$logFile !== null && is_file(self::$logFile)) {
            @unlink(self::$logFile);
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

        throw new \RuntimeException('Fake-Release-Testserver ist nach 10s nicht erreichbar geworden.');
    }
}
