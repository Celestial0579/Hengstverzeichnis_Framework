<?php
// tests/Support/FakeWebDavServer.php

namespace Tests\Support;

/**
 * Startet `php -S` mit tests/Support/fake-webdav-server.php als Docroot-
 * Skript für tests/Integration/WebDavClientTest.php - analog zu
 * FakeS3Server.php, unabhängig von der Haupt-App/DB.
 */
class FakeWebDavServer {

    private const HOST = '127.0.0.1';
    private const PORT = 8769;

    /** @var resource|null */
    private static $process = null;
    private static bool $shutdownRegistered = false;
    private static ?string $storageDir = null;

    public static function baseUrl(): string {
        return 'http://' . self::HOST . ':' . self::PORT;
    }

    public static function storageDir(): string {
        return self::$storageDir;
    }

    public static function ensureStarted(): void {
        if (self::$process !== null) {
            return;
        }

        self::$storageDir = sys_get_temp_dir() . '/fake_webdav_' . uniqid();
        mkdir(self::$storageDir);

        $script = __DIR__ . '/fake-webdav-server.php';
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        self::$process = proc_open(
            ['php', '-S', self::HOST . ':' . self::PORT, $script],
            $descriptorSpec,
            $pipes,
            __DIR__ . '/../..',
            array_merge(getenv() ?: [], ['FAKE_WEBDAV_STORAGE_DIR' => self::$storageDir])
        );

        if (!is_resource(self::$process)) {
            throw new \RuntimeException('Konnte Fake-WebDAV-Testserver nicht starten.');
        }

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
        if (self::$storageDir !== null && is_dir(self::$storageDir)) {
            self::removeDirectory(self::$storageDir);
            self::$storageDir = null;
        }
    }

    private static function removeDirectory(string $dir): void {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
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

        throw new \RuntimeException('Fake-WebDAV-Testserver ist nach 10s nicht erreichbar geworden.');
    }
}
