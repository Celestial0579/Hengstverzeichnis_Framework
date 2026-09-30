<?php
// src/Service/update-rueckweg.php
//
// Rückweg eines abgebrochenen Updates bzw. einer abgebrochenen Reparatur
// (Audit M45). BEWUSST KLASSENFREI und ohne jede Abhängigkeit.
//
// UpdateJournal::oeffnen() legt eine Kopie dieser Datei als ERSTE Aktion nach
// var/update-journal/rueckweg.php - bevor irgendeine Datei des Codebaums
// angefasst wird. Die Kopie stammt damit immer aus dem noch konsistenten
// Altstand. Aufgerufen wird sie
//   - vom frühen Haken ganz oben in public/index.php, noch vor jedem
//     Autoloader und vor config/config.php. Ein Kill mitten im Kopieren von
//     vendor/ kann autoload.php und autoload_real.php aus zwei Versionen
//     zurücklassen; dann endet jeder Request fatal, bevor die Anwendung
//     startet - der Rückweg muss deshalb ohne sie auskommen;
//   - vom Shutdown-Handler des Updates nach einem Fatal Error;
//   - vom Beginn jedes weiteren Update- oder Reparaturlaufs;
//   - von Hand: php var/update-journal/rueckweg.php [<wurzel>]
//
// Die Datei gibt eine Funktion zurück und deklariert NICHTS (keine Klasse,
// keine benannte Funktion) - so kollidiert sie weder mit dem Autoloader noch
// mit einem zweiten require im selben Prozess.
//
// Rückgabe: 'keins' (kein Journal), 'zurueckgerollt', 'belegt' (ein anderer
// Prozess hält var/update.lock - er lebt also und rollt selbst zurück oder
// arbeitet noch) oder 'unvollstaendig' (nicht alles ließ sich
// zurückholen; das Journal bleibt für einen weiteren Versuch liegen).

$hvUpdateRueckweg = static function (string $wurzel, bool $sperreGehalten = false, string $ursache = ''): string {
    $wurzel = rtrim($wurzel, '/');
    $verzeichnis = $wurzel . '/var/update-journal';
    $journalDatei = $verzeichnis . '/journal.jsonl';
    $sicherungen = $verzeichnis . '/sicherungen';

    if (!is_file($journalDatei)) {
        return 'keins';
    }

    // Die Sperre ERWERBEN, nicht nur prüfen, und bis zum Ende halten. Sonst
    // rollen ein Request und ein gleichzeitig startender Update-Lauf beide
    // zurück, und der zweite räumt dem ersten die Sicherungen weg.
    $sperre = null;
    if (!$sperreGehalten) {
        if (!is_dir($wurzel . '/var')) {
            return 'keins';
        }
        $sperre = @fopen($wurzel . '/var/update.lock', 'c+');
        if ($sperre !== false) {
            if (!@flock($sperre, LOCK_EX | LOCK_NB, $wuerdeBlockieren)) {
                @fclose($sperre);
                $sperre = null;
                if ($wuerdeBlockieren) {
                    return 'belegt';
                }
                // Ohne flock bleibt nur die Prozesskennung aus dem Kopf.
            }
        } else {
            $sperre = null;
        }
    }

    $loesen = static function () use (&$sperre): void {
        if (is_resource($sperre)) {
            @flock($sperre, LOCK_UN);
            @fclose($sperre);
        }
        $sperre = null;
    };

    // Unter der Sperre erneut: Ein anderer Rückweg kann gerade fertig
    // geworden sein.
    clearstatcache();
    if (!is_file($journalDatei)) {
        $loesen();
        return 'keins';
    }

    $sichererPfad = static function ($rel): ?string {
        if (!is_string($rel) || $rel === '' || str_contains($rel, "\0") || $rel[0] === '/' || str_contains($rel, '\\')) {
            return null;
        }
        foreach (explode('/', $rel) as $teil) {
            if ($teil === '' || $teil === '.' || $teil === '..') {
                return null;
            }
        }
        // Das Journal selbst und die Sperre sind nie Gegenstand des Rückwegs.
        if ($rel === 'var/update.lock' || str_starts_with($rel, 'var/update-journal')) {
            return null;
        }
        return $rel;
    };
    $sichererName = static function ($name): ?string {
        if (!is_string($name) || $name === '' || $name === '.' || $name === '..'
            || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
            return null;
        }
        return $name;
    };

    $kopf = [];
    $eintraege = ['restore' => [], 'created' => [], 'created_dirs' => [], 'deleted' => [], 'rmdir' => []];
    $verworfen = 0;
    foreach (@file($journalDatei, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $zeile) {
        $e = json_decode($zeile, true);
        // Eine abgeschnittene letzte Zeile (Kill mitten im Schreiben) ist
        // kein Fehler: Zu ihr gehört noch keine Dateioperation.
        if (!is_array($e) || !isset($e['art']) || !is_string($e['art'])) {
            continue;
        }
        switch ($e['art']) {
            case 'kopf':
                $kopf = $e;
                break;
            case 'restore':
            case 'deleted':
                $original = $sichererPfad($e['original'] ?? null);
                $sicherung = ($e['sicherung'] ?? null) === null && $e['art'] === 'deleted'
                    ? null
                    : $sichererName($e['sicherung'] ?? null);
                if ($original === null || ($sicherung === null && $e['art'] === 'restore')) {
                    $verworfen++;
                    break;
                }
                $eintraege[$e['art']][] = [$sicherung, $original];
                break;
            case 'created':
            case 'created_dirs':
            case 'rmdir':
                $pfad = $sichererPfad($e['pfad'] ?? null);
                if ($pfad === null) {
                    $verworfen++;
                    break;
                }
                $eintraege[$e['art']][] = $pfad;
                break;
            default:
                $verworfen++;
        }
    }

    // Ohne flock: Lebt der Prozess aus dem Kopf noch, arbeitet er - nichts
    // anfassen.
    if (!$sperreGehalten && $sperre === null) {
        $pid = isset($kopf['pid']) && is_int($kopf['pid']) ? $kopf['pid'] : 0;
        if ($pid > 0 && $pid !== getmypid() && is_dir('/proc') && is_dir('/proc/' . $pid)) {
            return 'belegt';
        }
    }

    $fehler = 0;
    $zurueck = static function (string $von, string $nach) use (&$fehler): void {
        $ordner = dirname($nach);
        if (!is_dir($ordner)) {
            @mkdir($ordner, 0755, true);
        }
        // Über eine Temp-Datei und rename(): Parallele Requests sehen die
        // Datei nie halb geschrieben.
        $temp = $nach . '.hv-rueckweg';
        if (!@copy($von, $temp) || !@rename($temp, $nach)) {
            @unlink($temp);
            if (!@copy($von, $nach)) {
                $fehler++;
            }
        }
    };

    // Reihenfolge wie UpdateService::rollback(): erst die vom Abgleich
    // entfernten Verzeichnisse und Dateien, dann neue Dateien weg und
    // überschriebene zurück, zuletzt neue Verzeichnisse weg.
    foreach (array_reverse($eintraege['rmdir']) as $rel) {
        $ziel = $wurzel . '/' . $rel;
        if (!is_dir($ziel)) {
            @mkdir($ziel, 0755, true);
        }
    }
    $verknuepfungen = 0;
    foreach (array_reverse($eintraege['deleted']) as [$sicherung, $rel]) {
        if ($sicherung === null) {
            $verknuepfungen++;
            continue;
        }
        if (is_file($sicherungen . '/' . $sicherung)) {
            $zurueck($sicherungen . '/' . $sicherung, $wurzel . '/' . $rel);
        } elseif (!is_file($wurzel . '/' . $rel)) {
            $fehler++;
        }
    }
    foreach (array_reverse($eintraege['created']) as $rel) {
        $ziel = $wurzel . '/' . $rel;
        if ((is_file($ziel) || is_link($ziel)) && !@unlink($ziel)) {
            $fehler++;
        }
    }
    foreach (array_reverse($eintraege['restore']) as [$sicherung, $rel]) {
        if (is_file($sicherungen . '/' . $sicherung)) {
            $zurueck($sicherungen . '/' . $sicherung, $wurzel . '/' . $rel);
        } else {
            // Vermerkt wird erst NACH der Sicherung. Fehlt sie trotzdem,
            // ist sie abhandengekommen - das ist ein Fehler, kein Normalfall.
            $fehler++;
        }
    }
    foreach (array_reverse($eintraege['created_dirs']) as $rel) {
        $ziel = $wurzel . '/' . $rel;
        if (is_dir($ziel)) {
            @rmdir($ziel); // nur leere - was dort sonst liegt, bleibt
        }
    }

    $bilanz = [
        'wiederhergestellt' => count($eintraege['restore']),
        'entfernt' => count($eintraege['created']),
        'verzeichnisse' => count($eintraege['created_dirs']),
        'zurueckgeholt' => count($eintraege['deleted']) - $verknuepfungen,
        'verknuepfungen' => $verknuepfungen,
        'verworfen' => $verworfen,
        'fehler' => $fehler,
    ];

    // Hinweis für /admin/updates und die Automatik (UpdateJournal::
    // letzterAbbruch()). Kein Audit-Log hier: Das bräuchte Datenbank und
    // Autoloader - genau das, worauf sich dieser Weg nicht verlassen darf.
    $hinweis = [
        'zeit' => date('c'),
        'zweck' => is_string($kopf['zweck'] ?? null) ? $kopf['zweck'] : '',
        'von' => is_string($kopf['von'] ?? null) ? $kopf['von'] : '',
        'nach' => is_string($kopf['nach'] ?? null) ? $kopf['nach'] : '',
        'seit' => is_string($kopf['seit'] ?? null) ? $kopf['seit'] : '',
        'ursache' => $ursache !== '' ? $ursache : 'Der Prozess endete, ohne das Einspielen abzuschließen (harter Abbruch, z. B. Zeitlimit des Webservers oder beendeter Worker).',
        'bilanz' => $bilanz,
        'vollstaendig' => $fehler === 0,
        'protokolliert' => false,
        'gemeldet' => false,
    ];
    $hinweisDatei = $wurzel . '/var/update-abbruch.json';
    $temp = $hinweisDatei . '.' . getmypid() . '.tmp';
    if (@file_put_contents($temp, (string)json_encode($hinweis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) !== false) {
        @rename($temp, $hinweisDatei);
    }

    if ($fehler > 0) {
        error_log(sprintf(
            'Update-Rückweg unvollständig: %d Datei(en) ließen sich nicht zurückholen. Das Journal bleibt in %s liegen; '
            . 'nach Behebung der Ursache (meist Dateirechte) erneut: php %s/rueckweg.php %s',
            $fehler, $verzeichnis, $verzeichnis, $wurzel
        ));
        $loesen();
        return 'unvollstaendig';
    }

    // Abschließen: zuerst das Journal (danach gilt der Lauf als erledigt),
    // dann der Rest des Baums.
    @unlink($journalDatei);
    $baumWeg = static function (string $pfad) use (&$baumWeg): void {
        if (is_link($pfad) || is_file($pfad)) {
            @unlink($pfad);
            return;
        }
        if (!is_dir($pfad)) {
            return;
        }
        foreach (array_diff(@scandir($pfad) ?: [], ['.', '..']) as $eintrag) {
            $baumWeg($pfad . '/' . $eintrag);
        }
        @rmdir($pfad);
    };
    $baumWeg($verzeichnis);

    // Entpack-Verzeichnis und Download liegen im System-Temp. Nur entfernen,
    // was nach unseren eigenen Namen aussieht - der Kopf ist Dateiinhalt.
    $temp = rtrim(sys_get_temp_dir(), '/');
    foreach (['entpackt', 'download'] as $feld) {
        $pfad = $kopf[$feld] ?? null;
        if (!is_string($pfad) || $pfad === '' || dirname($pfad) !== $temp
            || preg_match('/^hengst_(update|repair)_[A-Za-z0-9_.]+$/', basename($pfad)) !== 1) {
            continue;
        }
        $baumWeg($pfad);
    }

    error_log(sprintf(
        'Abgebrochenes %s zurückgerollt: %d Datei(en) wiederhergestellt, %d neue entfernt.',
        $hinweis['zweck'] !== '' ? $hinweis['zweck'] : 'Update',
        $bilanz['wiederhergestellt'] + $bilanz['zurueckgeholt'],
        $bilanz['entfernt']
    ));

    $loesen();
    return 'zurueckgerollt';
};

// Aufruf von Hand: php var/update-journal/rueckweg.php [<wurzel>]
if (PHP_SAPI === 'cli'
    && isset($_SERVER['argv'][0])
    && realpath((string)$_SERVER['argv'][0]) === realpath(__FILE__)
) {
    $hvWurzel = isset($_SERVER['argv'][1]) ? (string)$_SERVER['argv'][1] : dirname(__DIR__, 2);
    $hvErgebnis = $hvUpdateRueckweg($hvWurzel);
    fwrite(STDOUT, $hvErgebnis . PHP_EOL);
    exit($hvErgebnis === 'zurueckgerollt' || $hvErgebnis === 'keins' ? 0 : 1);
}

return $hvUpdateRueckweg;
