<?php
// tests/Functional/DatenbankVerbindungsfehlerTest.php

namespace Tests\Functional;

use Tests\Support\AuxiliaryServer;
use Tests\Support\HttpClient;

/**
 * Der gescheiterte Verbindungsaufbau zur Datenbank im Produktivbetrieb
 * (#521): Die Ursache steht im Serverprotokoll, die Antwort trägt 503, und
 * ein Kommandozeilen-Prozess endet mit Fehlercode.
 *
 * Bis #521 endete der Fall in einem blanken die(): kein Protokolleintrag,
 * HTTP 200, Exit-Code 0 - von außen sah die Instanz gesund aus.
 *
 * Eigene App-Instanz mit falschen Zugangsdaten statt eines unerreichbaren
 * Hosts: Der Kern weicht nach einem gescheiterten Aufbau auf lokale
 * Unix-Sockets aus. Auf einem Rechner mit lokaler Datenbank käme er damit
 * womöglich doch an - falsche Zugangsdaten scheitern auf jedem Weg.
 */
class DatenbankVerbindungsfehlerTest extends FunctionalTestCase {

    private const PORT = 8775;

    private const FALSCHE_ZUGANGSDATEN = [
        'DB_USER' => 'hv_gibt_es_nicht',
        'DB_PASS' => 'falsches-passwort',
        'APP_ENV' => 'production',
    ];

    private const MELDUNG = 'Datenbank-Verbindung fehlgeschlagen. Bitte überprüfen Sie die Einstellungen.';

    private static ?AuxiliaryServer $app = null;

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        self::$app = new AuxiliaryServer(self::PORT, __DIR__ . '/../../public', null, self::FALSCHE_ZUGANGSDATEN);
        self::$app->start();
    }

    public static function tearDownAfterClass(): void {
        self::$app?->stop();
        self::$app = null;
        parent::tearDownAfterClass();
    }

    public function testDieAntwortTraegt503UndVerraetDieUrsacheNicht(): void {
        $antwort = (new HttpClient(self::$app->baseUrl()))->get('/');

        $this->assertSame(503, $antwort->statusCode, "Body: {$antwort->body}");
        $this->assertStringContainsString(self::MELDUNG, $antwort->body);
        $this->assertStringNotContainsString('SQLSTATE', $antwort->body);
        $this->assertStringNotContainsString('hv_gibt_es_nicht', $antwort->body);
    }

    public function testDieUrsacheStehtImServerprotokoll(): void {
        (new HttpClient(self::$app->baseUrl()))->get('/');

        $protokoll = self::$app->log();
        $this->assertStringContainsString('Datenbank-Verbindung fehlgeschlagen: SQLSTATE', $protokoll);
        $this->assertStringContainsString('hv_gibt_es_nicht', $protokoll, 'Die Meldung nennt den abgewiesenen Benutzer.');
        $this->assertStringNotContainsString('falsches-passwort', $protokoll);
    }

    /**
     * Gegenprobe: Mit den richtigen Zugangsdaten antwortet der geteilte
     * Testserver auf dieselbe Anfrage ohne 503 - der Fehler oben kommt von
     * den Zugangsdaten und nicht von der Seite.
     */
    public function testMitRichtigenZugangsdatenGibtEsKeinen503(): void {
        $antwort = $this->newClient()->get('/');

        $this->assertNotSame(503, $antwort->statusCode);
        $this->assertStringNotContainsString(self::MELDUNG, $antwort->body);
    }

    public function testAufDerKommandozeileEndetDerProzessMitFehlercode(): void {
        // Derselbe Einstieg wie database/seed.php und database/reset.php,
        // ohne deren Schreibzugriffe.
        $skript = 'require "database/cli-autoload.php"; require "config/config.php"; '
            . '\App\Database::getInstance(); echo "verbunden";';

        $prozess = proc_open(
            [PHP_BINARY, '-r', $skript],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            __DIR__ . '/../..',
            array_merge(getenv(), self::FALSCHE_ZUGANGSDATEN)
        );
        $this->assertIsResource($prozess);
        fclose($pipes[0]);
        $ausgabe = (string)stream_get_contents($pipes[1]);
        $fehler = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($prozess);

        $this->assertSame(1, $code, "stdout: {$ausgabe}\nstderr: {$fehler}");
        $this->assertStringNotContainsString('verbunden', $ausgabe);
        $this->assertStringContainsString(self::MELDUNG, $fehler);
        $this->assertStringContainsString('Datenbank-Verbindung fehlgeschlagen: SQLSTATE', $fehler);
    }
}
