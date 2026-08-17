<?php
// tests/Support/FakeSmtpServer.php

namespace Tests\Support;

/**
 * Startet tests/Support/fake-smtp-server.php als eigenen Prozess - dasselbe
 * Muster wie FakeS3Server, nur eben SMTP statt HTTP.
 *
 * Der Zweck ist ein Test, der den Mailversand WIRKLICH geht statt ihn
 * wegzumocken. Das erfordert TLS: App\Service\Mailer::sendViaSmtp() lehnt
 * unverschlüsselten Versand grundsätzlich ab und prüft das Zertifikat mit
 * `verify_peer` bei ausgeschaltetem `allow_self_signed`. Ein einfach
 * selbstsigniertes Zertifikat scheitert daran also absichtlich.
 *
 * Deshalb erzeugt diese Klasse beim Start eine eigene kleine
 * Zertifizierungsstelle, stellt damit ein Zertifikat für 127.0.0.1 aus und
 * hinterlegt die CA über die Umgebungsvariable SSL_CERT_FILE, der OpenSSL
 * folgt. Der Mailer läuft dadurch unverändert und mit voller Prüfung - der
 * Test biegt nichts an der zu prüfenden Klasse zurecht, er stellt ihr nur
 * eine Gegenstelle hin, der sie zu Recht vertraut.
 *
 * Die Zertifikate entstehen bei jedem Lauf neu und liegen im temporären
 * Verzeichnis: nichts davon gehört ins Repo, und nichts davon überlebt den
 * Testlauf.
 */
class FakeSmtpServer {

    private const HOST = '127.0.0.1';

    /** @var resource|null */
    private static $process = null;
    private static bool $shutdownRegistered = false;
    private static ?string $workDir = null;
    private static ?string $storageDir = null;
    private static ?string $logFile = null;
    private static ?string $previousCertFile = null;
    private static ?int $port = null;

    public static function host(): string {
        return self::HOST;
    }

    /**
     * Der tatsächlich belegte Port. Bewusst nicht fest verdrahtet: Ein fester
     * Port kollidiert mit den übrigen Testservern dieses Verzeichnisses,
     * sobald einer dazukommt - und die Kollision äußert sich nicht als
     * Fehlermeldung, sondern als Test, der mit dem falschen Server spricht.
     * Genau so ist es beim Bau dieser Klasse passiert (FakeReleaseServer hielt
     * denselben Port bereits).
     */
    public static function port(): int {
        if (self::$port === null) {
            throw new \RuntimeException('Fake-SMTP-Server läuft nicht.');
        }
        return self::$port;
    }

    /** Verzeichnis, in dem die empfangenen Nachrichten als Dateien liegen. */
    public static function storageDir(): string {
        if (self::$storageDir === null) {
            throw new \RuntimeException('Fake-SMTP-Server läuft nicht.');
        }
        return self::$storageDir;
    }

    /**
     * Alle bisher empfangenen Nachrichten als Rohtext.
     *
     * @return array<int, string>
     */
    public static function messages(): array {
        $files = glob(self::storageDir() . '/mail-*.txt') ?: [];
        sort($files);
        return array_map(static fn(string $f): string => (string)file_get_contents($f), $files);
    }

    public static function clear(): void {
        foreach (glob(self::storageDir() . '/mail-*.txt') ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * Wartet, bis mindestens $count Nachrichten angekommen sind. Der Versand
     * läuft im Testprozess synchron, der Schreibvorgang aber im Serverprozess -
     * ohne diese kurze Schleife wäre der Test von der Reihenfolge zweier
     * Prozesse abhängig, also gelegentlich rot ohne Fehler im Code.
     */
    public static function waitForMessages(int $count, float $timeoutSeconds = 5.0): bool {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            if (count(self::messages()) >= $count) {
                return true;
            }
            usleep(50_000);
        }
        return false;
    }

    public static function ensureStarted(): void {
        if (self::$process !== null) {
            return;
        }
        if (self::openSslBinary() === null) {
            throw new \RuntimeException('Für den Fake-SMTP-Server wird das openssl-Kommando benötigt.');
        }

        self::$workDir = sys_get_temp_dir() . '/fake_smtp_' . uniqid();
        self::$storageDir = self::$workDir . '/eingang';
        mkdir(self::$storageDir, 0777, true);

        $cert = self::createCertificates(self::$workDir);

        // OpenSSL folgt SSL_CERT_FILE beim Aufbau des Standard-Vertrauensspeichers.
        // So prüft der Mailer weiterhin vollständig, kennt aber unsere CA.
        self::$previousCertFile = getenv('SSL_CERT_FILE') ?: null;
        putenv('SSL_CERT_FILE=' . $cert['ca']);

        self::$logFile = tempnam(sys_get_temp_dir(), 'fake_smtp_log_');
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['file', self::$logFile, 'a'],
            2 => ['file', self::$logFile, 'a'],
        ];

        self::$process = proc_open(
            ['php', __DIR__ . '/fake-smtp-server.php'],
            $descriptorSpec,
            $pipes,
            __DIR__ . '/../..',
            array_merge(getenv() ?: [], [
                'FAKE_SMTP_STORAGE_DIR' => self::$storageDir,
                'FAKE_SMTP_CERT' => $cert['server'],
                'FAKE_SMTP_PORT_FILE' => self::$workDir . '/port',
            ])
        );

        if (!is_resource(self::$process)) {
            throw new \RuntimeException('Konnte den Fake-SMTP-Server nicht starten.');
        }

        if (!self::$shutdownRegistered) {
            register_shutdown_function([self::class, 'stop']);
            self::$shutdownRegistered = true;
        }

        self::$port = self::awaitPort(self::$workDir . '/port');
        self::waitUntilReady();
    }

    /**
     * Wartet, bis der Serverprozess seinen Port hinterlegt hat. Die Datei
     * entsteht erst nach dem erfolgreichen Bind - sie ist damit der Beleg,
     * dass dieser Server läuft, und nicht bloß irgendeiner.
     */
    private static function awaitPort(string $portFile): int {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            if (is_file($portFile)) {
                $port = (int)trim((string)file_get_contents($portFile));
                if ($port > 0) {
                    return $port;
                }
            }
            usleep(50_000);
        }
        throw new \RuntimeException(
            'Fake-SMTP-Server hat innerhalb von 10s keinen Port gemeldet. Log: '
            . (self::$logFile !== null ? (string)@file_get_contents(self::$logFile) : '-')
        );
    }

    public static function stop(): void {
        if (self::$process !== null) {
            proc_terminate(self::$process);
            proc_close(self::$process);
            self::$process = null;
        }
        if (self::$previousCertFile !== null) {
            putenv('SSL_CERT_FILE=' . self::$previousCertFile);
        } else {
            putenv('SSL_CERT_FILE');
        }
        self::$previousCertFile = null;
        if (self::$workDir !== null && is_dir(self::$workDir)) {
            self::removeTree(self::$workDir);
        }
        self::$workDir = null;
        self::$storageDir = null;
        if (self::$logFile !== null && is_file(self::$logFile)) {
            @unlink(self::$logFile);
            self::$logFile = null;
        }
    }

    /**
     * Eigene CA plus davon ausgestelltes Serverzertifikat für 127.0.0.1.
     * Der Name muss passen, weil der Mailer auch `verify_peer_name` setzt.
     *
     * @return array{ca: string, server: string}
     */
    private static function createCertificates(string $dir): array {
        $openssl = (string)self::openSslBinary();
        $caKey = $dir . '/ca.key';
        $caPem = $dir . '/ca.pem';
        $srvKey = $dir . '/srv.key';
        $srvCsr = $dir . '/srv.csr';
        $srvCrt = $dir . '/srv.crt';
        $srvPem = $dir . '/srv.pem';
        $extFile = $dir . '/ext.cnf';

        file_put_contents($extFile, "subjectAltName=DNS:localhost,IP:127.0.0.1\n");

        self::run([$openssl, 'req', '-x509', '-newkey', 'rsa:2048', '-keyout', $caKey,
            '-out', $caPem, '-days', '2', '-nodes', '-subj', '/CN=Fake-SMTP-Test-CA']);
        self::run([$openssl, 'req', '-newkey', 'rsa:2048', '-keyout', $srvKey,
            '-out', $srvCsr, '-nodes', '-subj', '/CN=127.0.0.1']);
        self::run([$openssl, 'x509', '-req', '-in', $srvCsr, '-CA', $caPem, '-CAkey', $caKey,
            '-CAcreateserial', '-out', $srvCrt, '-days', '2', '-extfile', $extFile]);

        file_put_contents($srvPem, file_get_contents($srvCrt) . file_get_contents($srvKey));

        return ['ca' => $caPem, 'server' => $srvPem];
    }

    /** @param array<int, string> $cmd */
    private static function run(array $cmd): void {
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Konnte ' . $cmd[0] . ' nicht ausführen.');
        }
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new \RuntimeException('Zertifikatserzeugung fehlgeschlagen: ' . $err);
        }
    }

    private static function openSslBinary(): ?string {
        foreach (['/usr/bin/openssl', '/bin/openssl', '/usr/local/bin/openssl'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Wartet, bis der Server antwortet - und zwar als SMTP-Server.
     *
     * Die naheliegende Prüfung "lässt sich ein Socket öffnen?" ist ein
     * Scheinnachweis: Sie ist auch dann erfüllt, wenn auf dem Port ein ganz
     * anderer Dienst lauscht. Beim Bau dieser Klasse war genau das der Fall,
     * und der Test lief in einen 15-Sekunden-Timeout, statt einen klaren
     * Fehler zu melden. Deshalb wird hier auf die Begrüßung (220) bestanden.
     */
    private static function waitUntilReady(): void {
        $deadline = microtime(true) + 10;
        $lastSeen = '(keine Antwort)';
        while (microtime(true) < $deadline) {
            $connection = @fsockopen(self::HOST, self::port(), $errno, $errstr, 0.5);
            if ($connection !== false) {
                stream_set_timeout($connection, 2);
                $greeting = (string)fgets($connection, 512);
                fclose($connection);
                if (str_starts_with($greeting, '220')) {
                    return;
                }
                $lastSeen = trim($greeting) !== '' ? trim($greeting) : '(keine Antwort)';
            }
            usleep(100_000);
        }
        throw new \RuntimeException(
            'Auf Port ' . self::port() . ' meldet sich kein SMTP-Server. Zuletzt gesehen: ' . $lastSeen
        );
    }

    private static function removeTree(string $dir): void {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
