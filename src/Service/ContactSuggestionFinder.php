<?php
// src/Service/ContactSuggestionFinder.php

namespace App\Service;

use App\Database;
use PDO;

/**
 * Class ContactSuggestionFinder
 *
 * Dublettenvorschläge für die Kontaktliste (#355).
 *
 * WOZU. Für Pferde gab es bis v0.7 Vorschläge, aber keine Entscheidung; für
 * Personen eine Zusammenführung (`/admin/contacts/merge`), aber keine
 * Vorschläge - man musste die Dublette selbst gefunden haben. Für
 * Deckstationen gab es beides nicht. Beim Bereinigen des Bestands enthielten
 * 41 Deckstationen **acht Dubletten**, die von Hand gefunden und über Skripte
 * zusammengeführt wurden. Kein einziger dieser Fälle wäre im Produkt selbst
 * aufgefallen - es gab dort keine Stelle, die sie zeigt.
 *
 * Seit #336 gibt es genau EINEN Bestand, in dem zu suchen ist, statt zweier
 * mit einer unbeantwortbaren Zuordnungsfrage dazwischen.
 *
 * WIE BEWERTET WIRD. Namensähnlichkeit trägt die Entscheidung, Ort/PLZ/Land
 * stützen sie - dasselbe Muster wie bei Pferden, wo Alter und Deckstation den
 * Namen stützen. Ein gestützter Treffer ist deutlich verlässlicher: Zwei
 * "Hof Meier" in derselben Stadt sind wahrscheinlich einer, zwei "Hof Meier"
 * in verschiedenen Ländern eher nicht.
 *
 * WAS AUSDRÜCKLICH NICHT MITSPIELT. Platzhalter-Namen wie "Nichtmitglied NO"
 * oder "Nichtmitglied NL" unterscheiden sich nur im Länderkürzel; jede
 * Ähnlichkeitsmetrik hält sie für denselben Kontakt. Sie werden deshalb
 * übersprungen - sonst bestünde die Vorschlagsliste zum großen Teil aus
 * Paaren, die niemand zusammenführen darf, und die echten Funde gingen darin
 * unter.
 */
final class ContactSuggestionFinder {

    /** Ab hier gilt ein Paar als vorschlagswürdig (von 100). */
    private const SCHWELLE = 62;

    /** Namensähnlichkeit trägt bis hierher. */
    private const PUNKTE_NAME = 70;

    /** Ort, PLZ und Land stützen zusammen bis hierher. */
    private const PUNKTE_ORT = 30;

    /**
     * Höchstzahl geprüfter Kontakte je Lauf.
     *
     * Der Vergleich ist ein Kreuzprodukt - bei 500 Kontakten sind das rund
     * 125.000 Paare, was `similar_text()` gerade noch in vertretbarer Zeit
     * schafft. Der Deckel ist die Grenze, an der die Seite anfinge zu hängen,
     * und er wird gemeldet statt stillschweigend zu greifen: Eine Liste, die
     * einen Teil des Bestands verschweigt, behauptet Vollständigkeit.
     */
    private const MAX_KONTAKTE = 800;

    /**
     * Namensbestandteile, die einen Kontakt als Platzhalter ausweisen. Diese
     * Datensätze nehmen an der Dublettensuche NICHT teil.
     */
    private const PLATZHALTER = ['nichtmitglied', 'unbekannt', 'privat', 'n.n.', 'nn'];

    private function __construct() {}

    /**
     * @return array{paare: array<int, array>, abgeschnitten: bool, geprueft: int}
     */
    public static function findAll(int $limit = 100): array {
        $db = Database::getInstance();

        $kontakte = $db->query(
            'SELECT id, name, city, postal_code, country, is_breeder, is_published
             FROM contacts WHERE deleted_at IS NULL
             ORDER BY id ASC LIMIT ' . (self::MAX_KONTAKTE + 1)
        )->fetchAll(PDO::FETCH_ASSOC);

        $abgeschnitten = count($kontakte) > self::MAX_KONTAKTE;
        if ($abgeschnitten) {
            $kontakte = array_slice($kontakte, 0, self::MAX_KONTAKTE);
        }

        // Platzhalter aussortieren - siehe Klassenkommentar.
        $kontakte = array_values(array_filter($kontakte, static fn(array $k) => !self::istPlatzhalter($k['name'])));

        $entschieden = MatchLabel::ausgeblendete('contact');

        $paare = [];
        $anzahl = count($kontakte);
        for ($i = 0; $i < $anzahl; $i++) {
            for ($j = $i + 1; $j < $anzahl; $j++) {
                $a = $kontakte[$i];
                $b = $kontakte[$j];

                if (MatchLabel::istAusgeblendet($entschieden, (int)$a['id'], (int)$b['id'])) {
                    continue;
                }

                $punkte = self::bewerte($a, $b);
                if ($punkte < self::SCHWELLE) {
                    continue;
                }

                $paare[] = [
                    'a' => $a,
                    'b' => $b,
                    'score' => $punkte,
                    'begruendung' => self::begruendung($a, $b),
                ];
            }
        }

        // Stabil: erst nach Punkten, bei Gleichstand nach Name - sonst
        // wechselt die Reihenfolge zwischen zwei Aufrufen, und wer die Liste
        // von oben abarbeitet, verliert seinen Platz.
        usort($paare, static function (array $x, array $y): int {
            return [$y['score'], $x['a']['name']] <=> [$x['score'], $y['a']['name']];
        });

        return [
            'paare' => array_slice($paare, 0, max(1, $limit)),
            'abgeschnitten' => $abgeschnitten,
            'geprueft' => $anzahl,
        ];
    }

    /** Anzahl offener Vorschläge - für den E-Mail-Digest. */
    public static function countOpen(): int {
        return count(self::findAll(PHP_INT_MAX)['paare']);
    }

    private static function bewerte(array $a, array $b): int {
        $nameA = self::normalisiere((string)$a['name']);
        $nameB = self::normalisiere((string)$b['name']);
        if ($nameA === '' || $nameB === '') {
            return 0;
        }

        similar_text($nameA, $nameB, $prozent);
        $punkte = (int)round(($prozent / 100) * self::PUNKTE_NAME);

        // Ort/PLZ/Land stützen. Ein leeres Feld stützt NICHT - es widerlegt
        // aber auch nicht: Fehlende Angaben sind im Bestand die Regel, und
        // ein Abzug dafür machte gerade die dünn erfassten Datensätze
        // unauffindbar, obwohl dort die meisten Dubletten liegen.
        $stuetzen = 0;
        $moeglich = 0;
        foreach (['city', 'postal_code', 'country'] as $feld) {
            $wertA = self::normalisiere((string)($a[$feld] ?? ''));
            $wertB = self::normalisiere((string)($b[$feld] ?? ''));
            if ($wertA === '' || $wertB === '') {
                continue;
            }
            $moeglich++;
            if ($wertA === $wertB) {
                $stuetzen++;
            }
        }
        if ($moeglich > 0) {
            $punkte += (int)round(($stuetzen / $moeglich) * self::PUNKTE_ORT);
        }

        return min(100, $punkte);
    }

    private static function begruendung(array $a, array $b): string {
        $teile = [];
        similar_text(self::normalisiere((string)$a['name']), self::normalisiere((string)$b['name']), $prozent);
        $teile[] = sprintf('Name %d%% ähnlich', (int)round($prozent));

        foreach ([['city', 'Ort'], ['postal_code', 'PLZ'], ['country', 'Land']] as [$feld, $label]) {
            $wertA = self::normalisiere((string)($a[$feld] ?? ''));
            $wertB = self::normalisiere((string)($b[$feld] ?? ''));
            if ($wertA !== '' && $wertA === $wertB) {
                $teile[] = "gleiche(r) {$label}";
            }
        }
        return implode(', ', $teile);
    }

    private static function normalisiere(string $wert): string {
        $wert = mb_strtolower(trim($wert));
        // Satzzeichen und Mehrfach-Leerzeichen weg - "Hof Meier" und
        // "Hof-Meier," sollen sich nicht an Zeichensetzung unterscheiden.
        $wert = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $wert) ?? $wert;
        return trim(preg_replace('/\s+/', ' ', $wert) ?? $wert);
    }

    private static function istPlatzhalter(string $name): bool {
        $normal = self::normalisiere($name);
        foreach (self::PLATZHALTER as $muster) {
            if (str_contains($normal, self::normalisiere($muster))) {
                return true;
            }
        }
        return false;
    }
}
