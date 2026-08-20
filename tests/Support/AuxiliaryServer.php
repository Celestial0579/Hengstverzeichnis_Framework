<?php
// tests/Support/AuxiliaryServer.php

namespace Tests\Support;

/**
 * Instanzbasierter `php -S`-Wrapper für Funktionstests, die ZUSÄTZLICHE
 * Server neben dem geteilten PhpBuiltInServer brauchen - mit eigenem Port,
 * eigener Umgebung (proc_open-Env-Parameter) und optionalem Router-Skript.
 *
 * Anwendungsfall: Der SSO-Funktionstest (OidcSsoConfiguredTest) startet
 * (1) einen Fake-Identity-Provider (Router-Skript fake_oidc_idp.php) und
 * (2) eine zweite App-Instanz mit gesetzten OIDC_*-Variablen - der geteilte
 * Testserver läuft bewusst OHNE SSO-Konfiguration, damit die bestehenden
 * "nicht konfiguriert => 404"-Tests gültig bleiben.
 *
 * Dieselbe Logfile-statt-Pipe-Entscheidung wie in PhpBuiltInServer
 * (Issue #102): stderr in eine Datei, sonst blockiert der Single-Worker
 * nach ~64 KB Access-Log.
 */
class AuxiliaryServer {

    /** @var resource|null */
    private $process = null;
    private ?string $logFile = null;

    /**
     * @param array<string, string> $env Zusätzliche/überschreibende
     *   Umgebungsvariablen; die aktuelle Prozessumgebung wird vererbt,
     *   damit DB_*-Zugangsdaten etc. erhalten bleiben.
     */
    public function __construct(
        private readonly int $port,
        private readonly ?string $docroot = null,
        private readonly ?string $routerScript = null,
        private readonly array $env = [],
    ) {}

    /**
     * Der tatsaechlich benutzte Port.
     *
     * Die Testklassen geben feste Ports an (8791, 8792). Solange der
     * Hauptserver auf seinem Standardport 8767 lief, war das harmlos. Seit der
     * Hauptport ueber HV_TEST_PORT verstellbar ist (damit Framework- und
     * Addon-Suite nebeneinander laufen koennen), ist es das nicht mehr: Wer
     * HV_TEST_PORT=8791 setzt, dessen Hilfsserver kann nicht starten, und die
     * Anfragen landen STILL beim Hauptserver - der die Umgebung des
     * Hilfsservers nicht kennt. Der Test scheitert dann an einer Zusicherung
     * ueber Header oder Einbettung und sieht wie ein echter Fund aus.
     *
     * Genau das ist beim Bau von v0.8.0 passiert und hat eine Runde
     * Fehlersuche gekostet.
     *
     * Deshalb weicht der Hilfsport aus, sobald er mit dem Hauptport
     * zusammenfaellt. Der Versatz ist gross genug, um nicht auf den zweiten
     * Hilfsport zu treffen.
     */
    private function effektiverPort(): int {
        $haupt = getenv('HV_TEST_PORT');
        if ($haupt !== false && (int)$haupt === $this->port) {
            return $this->port + 100;
        }
        return $this->port;
    }

    public function baseUrl(): string {
        return 'http://127.0.0.1:' . $this->effektiverPort();
    }

    public function start(): void {
        if ($this->process !== null) {
            return;
        }

        // Zeitzone dieses Prozesses übernehmen - siehe PhpBuiltInServer,
        // dieselbe Begründung: Test und App teilen sich die Datenbank.
        $cmd = ['php', '-d', 'date.timezone=' . date_default_timezone_get(),
            '-S', '127.0.0.1:' . $this->effektiverPort()];
        if ($this->docroot !== null) {
            $cmd[] = '-t';
            $cmd[] = $this->docroot;
        }
        if ($this->routerScript !== null) {
            $cmd[] = $this->routerScript;
        }

        $this->logFile = tempnam(sys_get_temp_dir(), 'hengst_aux_server_');
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['file', $this->logFile, 'a'],
            2 => ['file', $this->logFile, 'a'],
        ];

        // getenv() liefert die komplette aktuelle Umgebung als Array; die
        // Overrides gewinnen. proc_open mit explizitem Env-Parameter vererbt
        // sonst NICHTS - deshalb das Mergen.
        $fullEnv = array_merge(getenv(), $this->env);

        $this->process = proc_open(
            $cmd,
            $descriptorSpec,
            $pipes,
            __DIR__ . '/../..',
            $fullEnv
        );

        if (!is_resource($this->process)) {
            throw new \RuntimeException("Konnte Zusatz-Testserver auf Port {$this->effektiverPort()} nicht starten.");
        }

        $this->waitUntilReady();
    }

    public function stop(): void {
        if ($this->process !== null) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
        if ($this->logFile !== null && is_file($this->logFile)) {
            unlink($this->logFile);
            $this->logFile = null;
        }
    }

    private function waitUntilReady(): void {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', $this->effektiverPort(), $errno, $errstr, 0.5);
            if ($connection !== false) {
                fclose($connection);
                return;
            }
            usleep(100_000);
        }

        throw new \RuntimeException("Zusatz-Testserver auf Port {$this->effektiverPort()} ist nach 10s nicht erreichbar geworden.");
    }
}
