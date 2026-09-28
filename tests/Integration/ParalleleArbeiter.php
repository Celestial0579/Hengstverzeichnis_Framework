<?php
// tests/Integration/ParalleleArbeiter.php

namespace Tests\Integration;

/**
 * Startet tests/Support/parallel-worker.php mehrfach gleichzeitig (Audit M20,
 * N43).
 *
 * Alle Arbeiter bekommen denselben Startzeitpunkt in der Zukunft und warten
 * ihn ab, nachdem sie ihre Verbindung aufgebaut haben. Der Vorlauf ist
 * großzügig, damit auch ein langsamer CI-Rechner alle Prozesse rechtzeitig
 * hochfährt - zugesichert werden trotzdem nur Obergrenzen, nie "mindestens
 * einer kommt durch".
 */
trait ParalleleArbeiter {

    /** Vorlauf der Startbarriere in Sekunden. */
    private static float $vorlauf = 1.5;

    /**
     * @param list<list<string>> $aufrufe je Arbeiter: Modus und Argumente
     * @return list<string> die Ausgabe jedes Arbeiters, in Aufrufreihenfolge
     */
    private function parallelAusfuehren(array $aufrufe): array {
        return $this->einsammeln($this->parallelStarten($aufrufe));
    }

    /**
     * Startet die Arbeiter und kehrt sofort zurück - für Tests, die während
     * der Arbeit selbst eingreifen. `start` ist der gemeinsame Startzeitpunkt.
     *
     * @param list<list<string>> $aufrufe
     * @return array{start: float, prozesse: array<int, array{0: resource, 1: array<int, resource>}>}
     */
    private function parallelStarten(array $aufrufe): array {
        $start = microtime(true) + self::$vorlauf;
        $skript = __DIR__ . '/../Support/parallel-worker.php';
        $prozesse = [];

        foreach ($aufrufe as $i => $aufruf) {
            $befehl = array_merge([PHP_BINARY, $skript, sprintf('%.6F', $start)], $aufruf);
            $prozess = proc_open($befehl, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($prozess, 'Arbeiter ließ sich nicht starten.');
            $prozesse[$i] = [$prozess, $pipes];
        }

        return ['start' => $start, 'prozesse' => $prozesse];
    }

    /**
     * Wartet auf alle Arbeiter.
     *
     * @param array{start: float, prozesse: array<int, array{0: resource, 1: array<int, resource>}>} $lauf
     * @return list<string>
     */
    private function einsammeln(array $lauf): array {
        $ausgaben = [];
        foreach ($lauf['prozesse'] as $i => [$prozess, $pipes]) {
            $ausgabe = (string)stream_get_contents($pipes[1]);
            $fehler = (string)stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($prozess);
            $this->assertSame(0, $code, "Arbeiter {$i} endete mit {$code}: {$fehler}{$ausgabe}");
            $ausgaben[] = $ausgabe;
        }

        return $ausgaben;
    }

    /** Wartet bis zum Zeitpunkt $zeit (Unix-Zeit mit Bruchteil). */
    private static function wartenBis(float $zeit): void {
        $rest = $zeit - microtime(true);
        if ($rest > 0) {
            usleep((int)round($rest * 1_000_000));
        }
    }

    /**
     * @param list<string> $ausgaben
     */
    private static function anzahl(array $ausgaben, string $ergebnis): int {
        return count(array_filter(
            $ausgaben,
            static fn(string $a): bool => str_contains($a, 'ERGEBNIS=' . $ergebnis . "\n")
        ));
    }
}
