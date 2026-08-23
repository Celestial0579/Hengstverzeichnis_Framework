<?php
// tests/Unit/Service/AuditLoggerStilleTest.php

namespace Tests\Unit\Service;

use App\Database;
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

        // BEIDE Tests brauchen denselben erzwungenen Fehlschlag - eine
        // Datenbank, die antwortet, aber die Tabelle `audit_logs` nicht hat.
        // Nur so landet der Ablauf ueberhaupt im catch-Zweig, und nur dort
        // entscheidet sich, ob gemeldet wird oder nicht.
        //
        // Ohne das prueft der Stille-Fall nichts: Mit einer funktionierenden
        // Datenbank gelingt der Eintrag, es gibt keinen Fehler, und der Test
        // waere gruen, egal was die Regel sagt. Genau so blieb die Gegenprobe
        // zweimal gruen.
        $pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        (new \ReflectionProperty(Database::class, 'instance'))->setValue(null, $pdo);
    }

    protected function tearDown(): void {
        AuditLogger::overrideDatenbankEingerichtetForTests(null);
        (new \ReflectionProperty(Database::class, 'instance'))->setValue(null, null);
        if ($this->fehlerprotokoll !== '' && is_file($this->fehlerprotokoll)) {
            @unlink($this->fehlerprotokoll);
        }
        ini_restore('error_log');
    }

    /**
     * Ohne eingerichtete Datenbank schweigt der Logger.
     *
     * Der Zustand wird AUSDRÜCKLICH gesetzt statt vorgefunden. Die erste
     * Fassung prüfte `defined('DB_HOST')` und übersprang sich sonst - und
     * damit übersprang sie sich in jeder Umgebung mit Datenbankkonfiguration,
     * also in der gesamten CI. Zwei grüne Haken, die nie etwas geprüft hatten.
     */
    public function testOhneEingerichteteDatenbankSchweigtDerLogger(): void {
        AuditLogger::overrideDatenbankEingerichtetForTests(false);

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
        AuditLogger::overrideDatenbankEingerichtetForTests(true);

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
