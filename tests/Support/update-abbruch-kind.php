<?php
// tests/Support/update-abbruch-kind.php
//
// Kindprozess für die Abbruch-Tests (Audit M45, N72). Ein Fatal Error, ein
// exit() oder ein SIGKILL lassen sich nur in einem eigenen Prozess
// nachstellen - im Testprozess risse er PHPUnit mit.
//
// Aufruf: php update-abbruch-kind.php <modus> <argumente...>
//
//   anwenden <zip> <ziel> <ab_datei> <art>
//       Spielt das Archiv per UpdateService::applyUpdateArchive() ein. Nach
//       der <ab_datei>-ten kopierten Datei (Zählung ab 1) schlägt <art> zu:
//         zeitlimit  Zeitlimit-Fatal (set_time_limit(1) + Schleife)
//         speicher   Speicherlimit-Fatal
//         kill       "BEREIT" ausgeben und warten - der Test schickt SIGKILL
//         exit       exit(3)
//   rueckweg <ziel>
//       Nur (require <ziel>/var/update-journal/rueckweg.php)(<ziel>), OHNE
//       Autoloader - so wie der frühe Haken in public/index.php.
//   sperre <ziel>
//       Hält die UpdateSperre, gibt "BEREIT" aus und wartet.
//   wartung <art>
//       Maintenance::enable() und dann <art>: zeitlimit | exit | kill |
//       dauerhaft (enableDauerhaft() + exit) | fremder_fatal (eine fremde
//       Shutdown-Funktion mit Fatal Error vor dem Aufräumen)
//
// Ausgaben landen auf stdout; der Exitcode zählt.

$argv = $_SERVER['argv'];
$modus = $argv[1] ?? '';

if ($modus === 'rueckweg') {
    $ziel = $argv[2];
    $ergebnis = (require $ziel . '/var/update-journal/rueckweg.php')($ziel);
    fwrite(STDOUT, 'ERGEBNIS=' . $ergebnis . "\n");
    exit(0);
}

require __DIR__ . '/../bootstrap.php';

use App\Service\Maintenance;
use App\Service\UpdateService;
use App\Service\UpdateSperre;

$zuschlagen = static function (string $art): void {
    switch ($art) {
        case 'zeitlimit':
            set_time_limit(1);
            $x = 0;
            while (true) {
                $x++;
            }
            // no break
        case 'speicher':
            ini_set('memory_limit', (string)(memory_get_usage() + 8 * 1024 * 1024));
            $ballast = [];
            while (true) {
                $ballast[] = str_repeat('x', 1024 * 1024);
            }
            // no break
        case 'kill':
            fwrite(STDOUT, "BEREIT\n");
            fflush(STDOUT);
            sleep(60);
            exit(9);
        case 'exit':
            exit(3);
    }
};

switch ($modus) {
    case 'anwenden':
        [, , $zip, $ziel, $abDatei, $art] = $argv;
        $zaehler = 0;
        UpdateService::nachJederDateiFuerTests(static function () use (&$zaehler, $abDatei, $art, $zuschlagen): void {
            if (++$zaehler === (int)$abDatei) {
                $zuschlagen($art);
            }
        });
        UpdateService::applyUpdateArchive($zip, $ziel, ['zweck' => 'Kern-Update', 'von' => '1.0.0', 'nach' => '9.9.9']);
        fwrite(STDOUT, "FERTIG\n");
        exit(0);

    case 'sperre':
        UpdateSperre::erwerben($argv[2], 'Test');
        fwrite(STDOUT, "BEREIT\n");
        fflush(STDOUT);
        sleep(60);
        exit(0);

    case 'wartung':
        $art = $argv[2];
        if ($art === 'dauerhaft') {
            Maintenance::enableDauerhaft('Kindprozess dauerhaft');
            exit(0);
        }
        if ($art === 'fremder_fatal') {
            Maintenance::enable('Kindprozess mit fremdem Fatal');
            register_shutdown_function(static function (): void {
                // Aufruf einer nicht existierenden Funktion: Fatal Error in
                // einer Shutdown-Funktion VOR dem Aufräumen des Wartungsmodus.
                $name = 'gibt_es_nicht_' . mt_rand();
                $name();
            });
            exit(0);
        }
        Maintenance::enable('Kindprozess ' . $art);
        $zuschlagen($art);
        exit(0);
}

fwrite(STDERR, "Unbekannter Modus: {$modus}\n");
exit(64);
