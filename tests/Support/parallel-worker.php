<?php
// tests/Support/parallel-worker.php
//
// Ein Arbeiter für die Parallelitätstests (Audit M20, N43). Er baut seine
// Datenbankverbindung auf, wartet bis zu einem gemeinsamen Startzeitpunkt
// (Startbarriere) und führt dann GENAU EINE Operation aus. Das Ergebnis steht
// als eine Zeile "ERGEBNIS=..." auf stdout.
//
// Eigene Prozesse und keine Schleife im Testprozess: Die Wettläufe, um die es
// geht, entstehen nur zwischen getrennten Verbindungen, die gleichzeitig
// arbeiten - so wie parallele HTTP-Anfragen.
//
// Aufruf: php parallel-worker.php <startzeit> <modus> <argumente...>
//
//   reserve  <bezeichner> <typ> <max> <halten_ms>
//            ERGEBNIS=ERLAUBT|GESPERRT
//   mailcode <user_id> <zweck> <code>
//            ERGEBNIS=OK|NEIN
//   backup   <user_id> <code>
//            vor der Einlösung "BEREIT", dann ERGEBNIS=OK|NEIN
//   totp     <user_id> <zeitschlitz>
//            ERGEBNIS=OK|NEIN
//
// Die Datenbank kommt wie im Testprozess aus der Umgebung (tests/bootstrap.php).

require __DIR__ . '/../bootstrap.php';

use App\Database;
use App\Security\EmailSecondFactor;
use App\Security\OneTimeProofs;
use App\Security\RateLimiter;

$start = (float)($argv[1] ?? 0);
$modus = (string)($argv[2] ?? '');
$args = array_slice($argv, 3);

// Verbindung VOR der Barriere, damit der Verbindungsaufbau nicht die
// Gleichzeitigkeit verwischt.
Database::getInstance()->query('SELECT 1');

$warten = $start - microtime(true);
if ($warten > 0) {
    usleep((int)round($warten * 1_000_000));
}

switch ($modus) {
    case 'reserve':
        [$bezeichner, $typ, $max, $haltenMs] = $args + ['', '', '5', '0'];
        $res = RateLimiter::reserveAttempt($bezeichner, $typ, (int)$max);
        echo $res === null ? "ERGEBNIS=GESPERRT\n" : "ERGEBNIS=ERLAUBT\n";
        // Die "Arbeit" nach der Buchung - hier bleibt die Zeile stehen.
        usleep((int)$haltenMs * 1000);
        break;

    case 'mailcode':
        [$userId, $zweck, $code] = $args + ['0', EmailSecondFactor::PURPOSE_LOGIN, ''];
        echo EmailSecondFactor::verify((int)$userId, $zweck, $code) ? "ERGEBNIS=OK\n" : "ERGEBNIS=NEIN\n";
        break;

    case 'backup':
        [$userId, $code] = $args + ['0', ''];
        echo "BEREIT\n";
        fflush(STDOUT);
        echo OneTimeProofs::redeemBackupCode((int)$userId, $code) ? "ERGEBNIS=OK\n" : "ERGEBNIS=NEIN\n";
        break;

    case 'totp':
        [$userId, $slice] = $args + ['0', '0'];
        echo OneTimeProofs::consumeTotpSlice((int)$userId, (int)$slice) ? "ERGEBNIS=OK\n" : "ERGEBNIS=NEIN\n";
        break;

    default:
        fwrite(STDERR, "Unbekannter Modus '{$modus}'\n");
        exit(2);
}
exit(0);
