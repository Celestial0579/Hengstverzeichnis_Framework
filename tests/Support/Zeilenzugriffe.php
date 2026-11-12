<?php
// tests/Support/Zeilenzugriffe.php

namespace Tests\Support;

use PDO;

/**
 * Misst, wie viele Zeilen eine Verbindung für einen Vorgang anfasst.
 *
 * WOZU. Ein Test, der nur das Ergebnis einer Abfrage prüft, merkt nicht, ob
 * sie dafür zehn Zeilen liest oder die ganze Tabelle - und genau das ist der
 * Unterschied, um den es bei einem Performance-Befund geht. Die Laufzeit
 * taugt als Maß nicht: Auf einer Testdatenbank mit ein paar hundert Zeilen
 * ist alles schnell, und der Wert schwankt mit der Auslastung des Läufers.
 *
 * Gezählt werden die Handler_read_*-Zähler der Sitzung: jeder Indexeinstieg,
 * jede gelesene Folgezeile, jede Zeile eines Tabellen-Scans. Das ist
 * unabhängig von der Uhr und davon, welchen Plan `EXPLAIN` ausgibt.
 *
 * Die Messung selbst kostet ein paar Zugriffe (`SHOW STATUS` füllt eine
 * interne Tabelle und liest sie). Wer enge Grenzen prüft, misst deshalb
 * einmal einen leeren Lauf und zieht ihn ab.
 */
trait Zeilenzugriffe {

    /** Zeilenzugriffe, die $lauf auf der Verbindung $db auslöst. */
    private static function zeilenzugriffeVon(PDO $db, callable $lauf): int {
        $stand = static function () use ($db): int {
            $summe = 0;
            $zaehler = $db->query("SHOW SESSION STATUS LIKE 'Handler_read%'")->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($zaehler as $wert) {
                $summe += (int)$wert;
            }
            return $summe;
        };

        $vorher = $stand();
        $lauf();

        return $stand() - $vorher;
    }

    /** Was die Messung allein kostet - zum Abziehen. */
    private static function zeilenzugriffeLeer(PDO $db): int {
        return self::zeilenzugriffeVon($db, static function (): void {
        });
    }
}
