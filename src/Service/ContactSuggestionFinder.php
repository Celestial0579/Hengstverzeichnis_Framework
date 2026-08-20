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
     * Wie viele Paare im Zwischenspeicher liegen. Die Seite zeigt 100, mehr
     * ist über die Oberfläche nicht erreichbar. Der Deckel hält den
     * JSON-Wert sicher unter der TEXT-Grenze von settings (64 KB) - die
     * Gesamtzahl wird daneben als Zahl geführt, damit der Zähler trotzdem
     * stimmt.
     */
    private const CACHE_MAX_PAARE = 100;

    /** Schlüssel in `settings`. */
    private const CACHE_KEY = 'contact_suggestions_cache';

    /**
     * Rückfallgrenze des Zwischenspeichers. Der Fingerabdruck erkennt jede
     * Änderung am Bestand von sich aus; die Frist ist nur der Sicherheitsgurt
     * für den Fall, dass er es einmal nicht tut (CRC32-Kollision, ein Feld,
     * an das niemand gedacht hat). 15 Minuten wie beim Zähler des Addons
     * plausibilitaetspruefung.
     */
    private const CACHE_HALTBAR_SEKUNDEN = 900;

    /**
     * Erhöhen, wenn sich die Bewertung ändert - sonst liefert der
     * Zwischenspeicher nach einem Update weiter Ergebnisse der alten Formel.
     */
    private const CACHE_ALGORITHMUS = 2;

    /**
     * Namensbestandteile, die einen Kontakt als Platzhalter ausweisen. Diese
     * Datensätze nehmen an der Dublettensuche NICHT teil.
     *
     * Verglichen wird WORTWEISE, nicht als Teilstring (#370). Mit
     * str_contains() traf 'nn' jeden Namen mit Doppel-n - Hermann, Zimmermann,
     * Bachmann, Johanna, Sonnenhof - und warf sie aus der Suche. Genau die
     * Dubletten, für die der Dienst gebaut wurde, wurden so von der eigenen
     * Ausschlussregel unsichtbar gemacht, und zwar still.
     */
    private const PLATZHALTER = ['nichtmitglied', 'unbekannt', 'privat', 'n.n.', 'nn'];

    private function __construct() {}

    /**
     * Vorschläge - aus dem Zwischenspeicher, wenn sich seit der letzten
     * Berechnung nichts geändert hat (#369).
     *
     * Warum überhaupt ein Zwischenspeicher: Der Vergleich ist und bleibt ein
     * Kreuzprodukt. Auch nach dem Wegfall der wiederholten Normalisierung
     * kostet der volle Deckel von 800 Kontakten (319.600 Paare) rund 1,3 s -
     * gemessen, nicht geschätzt. Diese Sekunde fiel bisher bei JEDEM Aufruf
     * von /admin/matches an, auch beim Blättern durch die Pferde-Vorschläge
     * darüber, die mit Kontakten nichts zu tun haben.
     *
     * Warum ein INHALTS-Fingerabdruck und keine Zeitstempel: `updated_at` hat
     * Sekundenauflösung. Zwei Änderungen in derselben Sekunde ergäben denselben
     * Stempel, und die zweite wäre unsichtbar - dieselbe Falle, die es hier
     * schon einmal gab. Die Prüfsumme über die bewertungsrelevanten Felder
     * kennt dieses Problem nicht und braucht keine einzige Invalidierung an
     * fremden Stellen: Wer einen Kontakt ändert, ändert den Abdruck, ohne es
     * zu wissen.
     *
     * @return array{paare: array<int, array>, abgeschnitten: bool, geprueft: int, uebersprungen: int, gesamt: int}
     */
    public static function findAll(int $limit = 100): array {
        $abdruck = self::fingerabdruck();
        $gespeichert = self::ausDemSpeicher($abdruck);
        if ($gespeichert !== null) {
            $gespeichert['paare'] = array_slice($gespeichert['paare'], 0, max(1, $limit));
            return $gespeichert;
        }

        $ergebnis = self::berechne();
        self::inDenSpeicher($abdruck, $ergebnis);

        $ergebnis['paare'] = array_slice($ergebnis['paare'], 0, max(1, $limit));
        return $ergebnis;
    }

    /**
     * @return array{paare: array<int, array>, abgeschnitten: bool, geprueft: int, uebersprungen: int, gesamt: int}
     */
    private static function berechne(): array {
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

        $vorFilter = count($kontakte);

        // Platzhalter aussortieren - siehe Klassenkommentar.
        $kontakte = array_values(array_filter($kontakte, static fn(array $k) => !self::istPlatzhalter((string)$k['name'])));

        // Normalisierung EINMAL je Kontakt (#369). Vorher lief sie in bewerte()
        // für JEDES Paar erneut - bei 800 Kontakten wurde derselbe Name rund
        // 800-mal durch zwei preg_replace geschickt, in Summe über 2,5 Mio.
        // Aufrufe pro Seitenaufruf. Das war der Löwenanteil der Laufzeit.
        foreach ($kontakte as &$k) {
            $k['_name'] = self::normalisiere((string)$k['name']);
            $k['_len'] = strlen($k['_name']);
            foreach (['city', 'postal_code', 'country'] as $feld) {
                $k['_' . $feld] = self::normalisiere((string)($k[$feld] ?? ''));
            }
        }
        unset($k);

        $entschieden = MatchLabel::ausgeblendete('contact');

        $paare = [];
        $anzahl = count($kontakte);
        for ($i = 0; $i < $anzahl; $i++) {
            $a = $kontakte[$i];
            if ($a['_len'] === 0) {
                continue;
            }
            for ($j = $i + 1; $j < $anzahl; $j++) {
                $b = $kontakte[$j];
                if ($b['_len'] === 0) {
                    continue;
                }

                if (MatchLabel::istAusgeblendet($entschieden, (int)$a['id'], (int)$b['id'])) {
                    continue;
                }

                // Ort/PLZ/Land zuerst: drei Zeichenkettenvergleiche auf schon
                // normalisierten Werten sind um Größenordnungen billiger als
                // similar_text(), und ihr Ergebnis sagt, wie ähnlich der Name
                // überhaupt noch sein MUSS.
                $ortPunkte = self::ortPunkte($a, $b);

                if (!self::nameKannReichen($a['_len'], $b['_len'], $ortPunkte)) {
                    // Ausgeschlossen ohne Ähnlichkeitsrechnung - und zwar
                    // beweisbar, nicht heuristisch: similar_text() kann
                    // höchstens 2*min(len)/(lenA+lenB) erreichen.
                    continue;
                }

                similar_text($a['_name'], $b['_name'], $prozent);
                $punkte = min(100, (int)round(($prozent / 100) * self::PUNKTE_NAME) + $ortPunkte);
                if ($punkte < self::SCHWELLE) {
                    continue;
                }

                $paare[] = [
                    'a' => $a,
                    'b' => $b,
                    'score' => $punkte,
                    // Der Prozentwert steht hier schon - begruendung() rechnete
                    // ihn früher ein zweites Mal aus.
                    'begruendung' => self::begruendung($a, $b, $prozent),
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
            'paare' => array_slice($paare, 0, self::CACHE_MAX_PAARE),
            'abgeschnitten' => $abgeschnitten,
            'geprueft' => $anzahl,
            // Damit "gefiltert" nicht wie "nichts gefunden" aussieht (#370).
            'uebersprungen' => $vorFilter - $anzahl,
            // Die volle Zahl, auch wenn die Liste gedeckelt ist. Ein Zähler,
            // der die Deckelung mitmacht, meldet zu wenig offene Fälle.
            'gesamt' => count($paare),
        ];
    }

    /** Anzahl offener Vorschläge - für den E-Mail-Digest. */
    public static function countOpen(): int {
        return (int)self::findAll(1)['gesamt'];
    }

    /**
     * Prüfsumme über alles, was in die Bewertung eingeht: die
     * bewertungsrelevanten Kontaktfelder, die getroffenen Entscheidungen und
     * die Parameter des Verfahrens selbst.
     */
    private static function fingerabdruck(): string {
        $db = Database::getInstance();

        $k = $db->query(
            "SELECT COUNT(*) AS n,
                    COALESCE(SUM(CRC32(CONCAT_WS(CHAR(31),
                        id, name, COALESCE(city,''), COALESCE(postal_code,''), COALESCE(country,'')
                    ))), 0) AS summe
             FROM contacts WHERE deleted_at IS NULL"
        )->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'summe' => 0];

        $l = $db->query(
            "SELECT COUNT(*) AS n,
                    COALESCE(SUM(CRC32(CONCAT_WS(CHAR(31), kind, left_id, right_id, label))), 0) AS summe
             FROM match_labels WHERE kind = 'contact'"
        )->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'summe' => 0];

        return implode('-', [
            self::CACHE_ALGORITHMUS,
            self::MAX_KONTAKTE, self::SCHWELLE, self::PUNKTE_NAME, self::PUNKTE_ORT,
            (int)$k['n'], (string)$k['summe'],
            (int)$l['n'], (string)$l['summe'],
        ]);
    }

    /**
     * @return array{paare: array<int, array>, abgeschnitten: bool, geprueft: int, uebersprungen: int, gesamt: int}|null
     */
    private static function ausDemSpeicher(string $abdruck): ?array {
        try {
            $stmt = Database::getInstance()->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
            $stmt->execute([self::CACHE_KEY]);
            $roh = $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return null;   // settings fehlt (Setup) - dann eben rechnen.
        }
        if (!is_string($roh) || $roh === '') {
            return null;
        }

        $daten = json_decode($roh, true);
        if (!is_array($daten)
            || ($daten['abdruck'] ?? null) !== $abdruck
            || !is_array($daten['paare'] ?? null)
            || !isset($daten['zeit'])
            || (time() - (int)$daten['zeit']) > self::CACHE_HALTBAR_SEKUNDEN
        ) {
            return null;
        }

        return [
            'paare' => $daten['paare'],
            'abgeschnitten' => (bool)($daten['abgeschnitten'] ?? false),
            'geprueft' => (int)($daten['geprueft'] ?? 0),
            'uebersprungen' => (int)($daten['uebersprungen'] ?? 0),
            'gesamt' => (int)($daten['gesamt'] ?? count($daten['paare'])),
        ];
    }

    private static function inDenSpeicher(string $abdruck, array $ergebnis): void {
        // Nur die Felder, die die Ansicht wirklich rendert - die internen
        // Normalisierungsfelder gehören nicht in den Speicher.
        $schlank = [];
        foreach ($ergebnis['paare'] as $paar) {
            $schlank[] = [
                'a' => self::schlankerKontakt($paar['a']),
                'b' => self::schlankerKontakt($paar['b']),
                'score' => (int)$paar['score'],
                'begruendung' => (string)$paar['begruendung'],
            ];
        }

        $json = json_encode([
            'abdruck' => $abdruck,
            'zeit' => time(),
            'paare' => $schlank,
            'abgeschnitten' => $ergebnis['abgeschnitten'],
            'geprueft' => $ergebnis['geprueft'],
            'uebersprungen' => $ergebnis['uebersprungen'],
            'gesamt' => $ergebnis['gesamt'],
        ], JSON_UNESCAPED_UNICODE);

        // settings.setting_value ist TEXT (64 KB). Lieber gar nicht
        // zwischenspeichern als einen abgeschnittenen Wert ablegen, den der
        // nächste Lauf für gültig hält.
        if (!is_string($json) || strlen($json) > 60000) {
            return;
        }

        try {
            Database::getInstance()->prepare(
                'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
            )->execute([self::CACHE_KEY, $json]);
        } catch (\Throwable $e) {
            // Ein nicht schreibbarer Zwischenspeicher darf die Seite nicht
            // kosten - dann wird eben jedes Mal gerechnet.
        }
    }

    /** @return array<string, mixed> */
    private static function schlankerKontakt(array $k): array {
        return [
            'id' => (int)$k['id'],
            'name' => (string)$k['name'],
            'city' => $k['city'] ?? null,
            'postal_code' => $k['postal_code'] ?? null,
            'country' => $k['country'] ?? null,
            'is_breeder' => $k['is_breeder'] ?? null,
            'is_published' => $k['is_published'] ?? null,
        ];
    }

    /**
     * Kann die Namensähnlichkeit dieses Paares die Schwelle überhaupt noch
     * erreichen? (#369)
     *
     * similar_text() liefert 2*gemeinsam/(lenA+lenB)*100, und `gemeinsam` kann
     * die kürzere Zeichenkette nicht überschreiten. Die Obergrenze steht damit
     * allein aus den Längen fest - ohne einen einzigen Zeichenvergleich.
     * Liegt sie unter dem, was nach Abzug der Ort-Stützung noch gebraucht wird,
     * ist das Paar sicher unter der Schwelle.
     *
     * Der Zuschlag von einem Prozentpunkt ist Absicht: Er macht den Filter
     * bewusst etwas zu großzügig, damit Rundung in beide Richtungen kein Paar
     * verlieren kann. Ein zu viel geprüftes Paar kostet Mikrosekunden, ein
     * verlorenes ist eine Dublette, die niemand mehr sieht.
     */
    private static function nameKannReichen(int $lenA, int $lenB, int $ortPunkte): bool {
        $benoetigtePunkte = self::SCHWELLE - $ortPunkte;
        if ($benoetigtePunkte <= 0) {
            return true;
        }
        if ($benoetigtePunkte > self::PUNKTE_NAME) {
            return false;
        }

        $benoetigtProzent = (($benoetigtePunkte - 0.5) * 100) / self::PUNKTE_NAME;
        $obereSchranke = (2 * min($lenA, $lenB) * 100) / ($lenA + $lenB);

        return $obereSchranke + 1.0 >= $benoetigtProzent;
    }

    /**
     * Ort, PLZ und Land stützen. Ein leeres Feld stützt NICHT - es widerlegt
     * aber auch nicht: Fehlende Angaben sind im Bestand die Regel, und ein
     * Abzug dafür machte gerade die dünn erfassten Datensätze unauffindbar,
     * obwohl dort die meisten Dubletten liegen.
     */
    private static function ortPunkte(array $a, array $b): int {
        $stuetzen = 0;
        $moeglich = 0;
        foreach (['city', 'postal_code', 'country'] as $feld) {
            $wertA = $a['_' . $feld] ?? '';
            $wertB = $b['_' . $feld] ?? '';
            if ($wertA === '' || $wertB === '') {
                continue;
            }
            $moeglich++;
            if ($wertA === $wertB) {
                $stuetzen++;
            }
        }
        if ($moeglich === 0) {
            return 0;
        }
        return (int)round(($stuetzen / $moeglich) * self::PUNKTE_ORT);
    }

    private static function begruendung(array $a, array $b, float $prozent): string {
        $teile = [sprintf('Name %d%% ähnlich', (int)round($prozent))];

        foreach ([['city', 'Ort'], ['postal_code', 'PLZ'], ['country', 'Land']] as [$feld, $label]) {
            $wertA = $a['_' . $feld] ?? '';
            if ($wertA !== '' && $wertA === ($b['_' . $feld] ?? '')) {
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

    /**
     * Wortweiser Vergleich statt Teilstring (#370). normalisiere() liefert
     * kleingeschriebene, durch einfache Leerzeichen getrennte Wörter - ein
     * Muster ist also entweder eines dieser Wörter oder eine zusammenhängende
     * Wortfolge darin.
     */
    private static function istPlatzhalter(string $name): bool {
        $woerter = explode(' ', self::normalisiere($name));
        if ($woerter === ['']) {
            return false;
        }

        foreach (self::PLATZHALTER as $muster) {
            $musterWoerter = explode(' ', self::normalisiere($muster));
            if (count($musterWoerter) === 1) {
                if (in_array($musterWoerter[0], $woerter, true)) {
                    return true;
                }
                continue;
            }
            if (str_contains(
                ' ' . implode(' ', $woerter) . ' ',
                ' ' . implode(' ', $musterWoerter) . ' '
            )) {
                return true;
            }
        }
        return false;
    }
}
