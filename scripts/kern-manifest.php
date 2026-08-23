<?php
// scripts/kern-manifest.php

/**
 * Erzeugt die beiden Listen, die ein Release über seinen eigenen Codebaum
 * mitliefert (#403).
 *
 *   KERN-SHA256SUMS.txt      SHA-256 je KERN-Datei DIESER Version.
 *                            Grundlage für Integritätsprüfung und Reparatur:
 *                            Was hier steht, ist der Sollzustand.
 *
 *   ABGELOESTE-DATEIEN.txt   SHA-256 je KERN-Datei, die eine FRÜHERE Version
 *                            ausgeliefert hat und diese nicht mehr.
 *                            Grundlage für den Abgleich beim Update: Nur was
 *                            hier exakt passt, ist beweisbar eine Leiche und
 *                            darf entfernt werden.
 *
 * Die zweite Liste ist der Grund, warum das Aufräumen überhaupt verantwortbar
 * ist. Ohne sie könnte ein Update nur sagen "das Archiv bringt diese Datei
 * nicht mit" - und das trifft auf eine Leiche aus v0.8.0 genauso zu wie auf
 * eine Datei, die der Betreiber selbst dort abgelegt hat. Mit ihr ist die
 * Unterscheidung beweisbar: Passt die Prüfsumme, war es unsere Datei und
 * niemand hat sie angefasst. Passt sie nicht, gehört sie jemand anderem und
 * bleibt liegen.
 *
 * Welche Pfade zum KERN gehören, sagt App\Service\Baumordnung - nicht dieses
 * Skript. Eine zweite Liste hier würde irgendwann auseinanderlaufen, und dann
 * prüfte das Release einen anderen Baum als die Anwendung.
 *
 * Aufruf (im Repo-Wurzelverzeichnis):
 *
 *     php scripts/kern-manifest.php <ausgabeverzeichnis>
 */

declare(strict_types=1);

require __DIR__ . '/../src/Service/Baumordnung.php';

use App\Service\Baumordnung;

$wurzel = dirname(__DIR__);
$ziel = $argv[1] ?? null;

if ($ziel === null) {
    fwrite(STDERR, "Aufruf: php scripts/kern-manifest.php <ausgabeverzeichnis>\n");
    exit(64);
}
if (!is_dir($ziel) && !mkdir($ziel, 0755, true)) {
    fwrite(STDERR, "Ausgabeverzeichnis nicht anlegbar: {$ziel}\n");
    exit(74);
}

// ---------------------------------------------------------------------------
// 1. Sollzustand dieser Version
// ---------------------------------------------------------------------------

$aktuell = [];
foreach (versionierteDateien($wurzel) as $pfad) {
    if (!Baumordnung::istKern($pfad)) {
        continue;
    }
    $voll = $wurzel . '/' . $pfad;
    if (!is_file($voll)) {
        continue;
    }
    $aktuell[$pfad] = hash_file('sha256', $voll);
}

// Nicht alles, was ausgeliefert wird, steht in git: vendor/ entsteht erst beim
// Bauen (#353). Deshalb ueber die KERN-Pfade auch das Dateisystem laufen und
// aufnehmen, was git nicht kennt. Ohne das haette die Solliste ausgerechnet
// ueber die Bibliothek im Anmeldepfad nichts zu sagen.
foreach (Baumordnung::kernPfade() as $kernPfad) {
    $basis = $wurzel . '/' . $kernPfad;
    if (!is_dir($basis)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($basis, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $datei) {
        if (!$datei->isFile()) {
            continue;
        }
        $rel = substr($datei->getPathname(), strlen($wurzel) + 1);
        if (isset($aktuell[$rel]) || !Baumordnung::istKern($rel)) {
            continue;
        }
        $aktuell[$rel] = hash_file('sha256', $datei->getPathname());
    }
}

// Schutz gegen die naheliegendste Verwechslung: Wer das Skript in einer
// Arbeitskopie laufen laesst, hat die Entwicklungs-Abhaengigkeiten in vendor/
// und schriebe sie in die Solliste. Eine Installation bekaeme sie nie zu
// Gesicht und meldete ab da dauerhaft fehlende Dateien - die Pruefung waere
// vom ersten Tag an rot und damit wertlos.
if (is_dir($wurzel . '/vendor') && is_dir($wurzel . '/vendor/phpunit')) {
    fwrite(STDERR,
        "vendor/ enthaelt Entwicklungs-Abhaengigkeiten (vendor/phpunit).\n"
        . "Die Solliste wuerde damit Dateien nennen, die eine Installation nie bekommt.\n"
        . "Erst 'composer install --no-dev -o -a' laufen lassen, dann dieses Skript.\n");
    exit(65);
}

if ($aktuell === []) {
    fwrite(STDERR, "Kein einziger KERN-Pfad gefunden - das kann nicht stimmen.\n");
    exit(70);
}

ksort($aktuell);
schreibe($ziel . '/KERN-SHA256SUMS.txt', $aktuell, [
    'Sollzustand der KERN-Dateien dieser Version.',
    'Erzeugt von scripts/kern-manifest.php. Format wie sha256sum.',
]);

// ---------------------------------------------------------------------------
// 2. Was frühere Versionen ausgeliefert haben und diese nicht mehr
// ---------------------------------------------------------------------------

$abgeloest = [];          // "<sha256>  <pfad>" => true, dedupliziert
$tags = releaseTags($wurzel);

foreach ($tags as $tag) {
    foreach (versionierteDateien($wurzel, $tag) as $pfad) {
        if (isset($aktuell[$pfad])) {
            continue;                       // gibt es weiterhin
        }
        if (!Baumordnung::istKern($pfad)) {
            continue;                       // war nie unsere Zuständigkeit
        }
        $inhalt = gitInhalt($wurzel, $tag, $pfad);
        if ($inhalt === null) {
            continue;
        }
        $abgeloest[hash('sha256', $inhalt) . '  ' . $pfad] = true;
    }
}

$zeilen = array_keys($abgeloest);
sort($zeilen);

schreibeRoh($ziel . '/ABGELOESTE-DATEIEN.txt', $zeilen, [
    'KERN-Dateien, die eine fruehere Version ausgeliefert hat und diese nicht mehr.',
    'Ein Update entfernt eine vorgefundene Datei NUR, wenn ihre Pruefsumme hier steht -',
    'dann ist bewiesen, dass sie von uns stammt und niemand sie angefasst hat.',
    'Ein Pfad kann mehrfach vorkommen: einmal je Inhalt, den er ueber die Versionen hatte.',
    sprintf('Ausgewertete Versionen: %d.', count($tags)),
]);

fprintf(
    STDOUT,
    "KERN-SHA256SUMS.txt: %d Dateien\nABGELOESTE-DATEIEN.txt: %d Eintraege (%d Pfade, %d Versionen ausgewertet)\n",
    count($aktuell),
    count($zeilen),
    count(array_unique(array_map(static fn(string $z): string => substr($z, 66), $zeilen))),
    count($tags)
);

// ---------------------------------------------------------------------------

/**
 * Die von git verwalteten Dateien - eines Tags oder des Arbeitsstands.
 *
 * git ist hier die richtige Quelle und nicht das Dateisystem: Es liefert
 * genau das, was auch `git archive` ins Release legt, also ohne vendor/,
 * ohne lokale Reste, ohne das, was gerade jemand danebengelegt hat.
 *
 * @return array<int, string>
 */
function versionierteDateien(string $wurzel, ?string $tag = null): array {
    $befehl = $tag === null
        ? 'git -C %s ls-files'
        : 'git -C %s ls-tree -r --name-only ' . escapeshellarg($tag);

    $ausgabe = [];
    $rc = 0;
    exec(sprintf($befehl, escapeshellarg($wurzel)) . ' 2>/dev/null', $ausgabe, $rc);

    if ($rc !== 0) {
        fwrite(STDERR, "git-Aufruf fehlgeschlagen (" . ($tag ?? 'Arbeitsstand') . ").\n");
        exit(70);
    }

    return $ausgabe;
}

/** @return array<int, string> Release-Tags, aelteste zuerst. */
function releaseTags(string $wurzel): array {
    $ausgabe = [];
    $rc = 0;
    exec('git -C ' . escapeshellarg($wurzel) . " tag -l 'v*' --sort=v:refname 2>/dev/null", $ausgabe, $rc);

    if ($rc !== 0) {
        fwrite(STDERR, "Konnte die Release-Tags nicht lesen.\n");
        exit(70);
    }

    return $ausgabe;
}

/** Inhalt einer Datei zu einem Tag, oder null wenn es sie dort nicht gibt. */
function gitInhalt(string $wurzel, string $tag, string $pfad): ?string {
    $befehl = 'git -C ' . escapeshellarg($wurzel)
        . ' cat-file blob ' . escapeshellarg($tag . ':' . $pfad) . ' 2>/dev/null';

    $handle = popen($befehl, 'r');
    if ($handle === false) {
        return null;
    }
    $inhalt = stream_get_contents($handle);
    $rc = pclose($handle);

    return ($rc === 0 && $inhalt !== false) ? $inhalt : null;
}

/** @param array<string, string> $eintraege pfad => hash */
function schreibe(string $datei, array $eintraege, array $kopf): void {
    $zeilen = [];
    foreach ($eintraege as $pfad => $hash) {
        $zeilen[] = $hash . '  ' . $pfad;
    }
    schreibeRoh($datei, $zeilen, $kopf);
}

/**
 * @param array<int, string> $zeilen
 * @param array<int, string> $kopf
 */
function schreibeRoh(string $datei, array $zeilen, array $kopf): void {
    $text = '';
    foreach ($kopf as $z) {
        $text .= '# ' . $z . "\n";
    }
    $text .= implode("\n", $zeilen) . "\n";

    if (file_put_contents($datei, $text) === false) {
        fwrite(STDERR, "Konnte nicht schreiben: {$datei}\n");
        exit(74);
    }
}
