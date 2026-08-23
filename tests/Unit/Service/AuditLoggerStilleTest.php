<?php
// tests/Unit/Service/AuditLoggerStilleTest.php

namespace Tests\Unit\Service;

use App\Service\AuditLogger;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Der AuditLogger unterscheidet "konnte gar nicht" von "hat nicht geklappt".
 *
 * Ohne eingerichtete Datenbank - frische Installation vor dem Assistenten,
 * isolierter Unit-Test, CLI-Werkzeug ohne Konfiguration - gibt es nichts zu
 * protokollieren, und das ist kein Fehlschlag. Mit eingerichteter Datenbank
 * ist ein misslungener Eintrag dagegen ein echter Befund: Ein
 * sicherheitsrelevantes Ereignis wäre dann nicht revisionssicher festgehalten.
 *
 * Warum das einen eigenen Test bekommt: Bis #403 meldete der Logger BEIDE
 * Fälle gleich laut. In der Unit-Suite erzeugte das Rauschen im
 * Fehlerprotokoll - und Rauschen im Fehlerprotokoll ist teuer, weil es die
 * echten Meldungen zudeckt. Wer die Unterscheidung wieder herausnimmt, soll
 * hier anlaufen.
 *
 * Jeder Test laeuft in einem EIGENEN Prozess: Der zweite definiert DB_HOST,
 * und eine einmal definierte Konstante laesst sich nicht zuruecknehmen. Ohne
 * Prozesstrennung haenge das Ergebnis an der Reihenfolge - und DB_HOST wuerde
 * in jeden folgenden Test der Suite durchschlagen.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class AuditLoggerStilleTest extends TestCase {

    private string $fehlerprotokoll = '';

    protected function setUp(): void {
        $this->fehlerprotokoll = rtrim(sys_get_temp_dir(), '/')
            . '/hv_auditstille_' . bin2hex(random_bytes(6)) . '.log';
        ini_set('error_log', $this->fehlerprotokoll);
    }

    protected function tearDown(): void {
        if ($this->fehlerprotokoll !== '' && is_file($this->fehlerprotokoll)) {
            @unlink($this->fehlerprotokoll);
        }
        ini_restore('error_log');
    }

    /**
     * Ohne DB_HOST ist keine Datenbank eingerichtet - der Logger schweigt.
     *
     * Die Unit-Suite läuft ohne config/config.php, DB_HOST ist hier also
     * tatsächlich nicht definiert. Dieser Test verlässt sich nicht darauf,
     * sondern stellt es fest und überspringt sonst - eine grüne Zusicherung
     * unter einer Bedingung, die gar nicht galt, wäre keine.
     */
    public function testOhneEingerichteteDatenbankSchweigtDerLogger(): void {
        if (defined('DB_HOST')) {
            $this->markTestSkipped(
                'DB_HOST ist in diesem Lauf definiert - der Stille-Fall lässt sich hier nicht prüfen.'
            );
        }

        AuditLogger::log('Testereignis', 'test', 'ohne Datenbank');

        $inhalt = is_file($this->fehlerprotokoll)
            ? (string)file_get_contents($this->fehlerprotokoll)
            : '';

        $this->assertStringNotContainsString(
            'AuditLogger Failure',
            $inhalt,
            'Ohne eingerichtete Datenbank gibt es nichts zu protokollieren - das ist kein Fehlschlag '
            . 'und gehört nicht ins Fehlerprotokoll.'
        );
    }

    /**
     * Die Gegenrichtung, und die ist die wichtigere: Ist eine Datenbank
     * eingerichtet und der Eintrag geht trotzdem schief, MUSS das gemeldet
     * werden.
     *
     * Nachgestellt wird das mit einem definierten, aber unerreichbaren
     * DB_HOST. Ohne diesen Test wäre die Stille-Regel zu weit gefasst, ohne
     * dass es jemandem auffiele - sicherheitsrelevante Ereignisse fielen dann
     * lautlos unter den Tisch.
     */
    public function testMitEingerichteterAberKaputterDatenbankWirdGemeldet(): void {
        if (defined('DB_HOST')) {
            $this->markTestSkipped('DB_HOST ist bereits definiert - dieser Test setzt ihn selbst.');
        }

        define('DB_HOST', '203.0.113.255');   // TEST-NET-3, nicht erreichbar

        AuditLogger::log('Testereignis', 'test', 'mit kaputter Datenbank');

        $inhalt = is_file($this->fehlerprotokoll)
            ? (string)file_get_contents($this->fehlerprotokoll)
            : '';

        $this->assertStringContainsString(
            'AuditLogger Failure',
            $inhalt,
            'Eine eingerichtete, aber nicht erreichbare Datenbank ist ein echter Fehlschlag: '
            . 'Ein sicherheitsrelevantes Ereignis ist dann nicht revisionssicher festgehalten.'
        );
    }
}
