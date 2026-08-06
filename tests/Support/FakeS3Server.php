<?php
// tests/Support/FakeS3Server.php

namespace Tests\Support;

/**
 * Startet `php -S` mit tests/Support/fake-s3-server.php als Docroot-Skript
 * für tests/Integration/S3ClientTest.php - analog zu PhpBuiltInServer.php,
 * aber unabhängig von der Haupt-App/DB, da App\Service\S3Client keine
 * Datenbank braucht.
 */
class FakeS3Server {

    private const HOST = '127.0.0.1';
    private const PORT = 8768;

    /** @var resource|null */
    private static $process = null;
    private static bool $shutdownRegistered = false;
    private static ?string $storageDir = null;
    private static ?string $logFile = null;

    public static function endpoint(): string {
        return self::HOST . ':' . self::PORT;
    }

    public static function storageDir(): string {
        return self::$storageDir;
    }

    public static function ensureStarted(): void {
        if (self::$process !== null) {
            return;
        }

        self::$storageDir = sys_get_temp_dir() . '/fake_s3_' . uniqid();
        mkdir(self::$storageDir);

        $script = __DIR__ . '/fake-s3-server.php';

        // stdout/stderr in eine Logdatei statt in nie ausgelesene Pipes umleiten -
        // sonst blockiert der Single-Worker-Server, sobald der Pipe-Buffer durch die
        // Access-Logs volläuft (siehe PhpBuiltInServer.php und Issue #102).
        self::$logFile = tempnam(sys_get_temp_dir(), 'fake_s3_server_');
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['file', self::$logFile, 'a'],
            2 => ['file', self::$logFile, 'a'],
        ];

        self::$process = proc_open(
            ['php', '-S', self::HOST . ':' . self::PORT, $script],
            $descriptorSpec,
            $pipes,
            __DIR__ . '/../..',
            array_merge(getenv() ?: [], ['FAKE_S3_STORAGE_DIR' => self::$storageDir])
        );

        if (!is_resource(self::$process)) {
            throw new \RuntimeException('Konnte Fake-S3-Testserver nicht starten.');
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
        if (self::$storageDir !== null && is_dir(self::$storageDir)) {
            foreach (glob(self::$storageDir . '/*') as $file) {
                unlink($file);
            }
            rmdir(self::$storageDir);
            self::$storageDir = null;
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

        throw new \RuntimeException('Fake-S3-Testserver ist nach 10s nicht erreichbar geworden.');
    }
}
