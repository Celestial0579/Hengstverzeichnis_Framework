<?php
// tests/Unit/Views/ResponsiveLintTest.php

namespace Tests\Unit\Views;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Hält die beiden Regeln fest, die aus der Prüfung des responsiven
 * Verhaltens folgen (#345).
 *
 * WARUM ALS TEST UND NICHT ALS NOTIZ. #345 sagt es selbst: „Ohne diese Regel
 * wächst der Rückstand mit jeder neuen View weiter." Der Zustand, den die
 * Prüfung vorfand, ist genau so entstanden — nicht durch eine falsche
 * Entscheidung, sondern dadurch, dass niemand beim Hinzufügen der
 * siebzehnten Tabelle an die erste dachte. Eine Prüfliste im PR hilft
 * dagegen erst, wenn sie jemand liest; ein roter Lauf hilft immer.
 *
 * WAS HIER NICHT GEPRÜFT WIRD: Inline-Styles überhaupt. Davon gibt es über
 * tausend, die allermeisten sind harmlos (eine Farbe, ein Abstand), und ein
 * Verbot wäre in einer Woche mit Ausnahmen durchlöchert. Geprüft werden die
 * zwei Fälle, die auf einem Telefon tatsächlich brechen — und die sich ohne
 * Auslegung feststellen lassen.
 */
class ResponsiveLintTest extends TestCase {

    private const VIEWS_DIR = __DIR__ . '/../../../src/Views';

    /** @return array<string, array{0: string}> */
    public static function viewProvider(): array {
        $faelle = [];
        foreach (glob(self::VIEWS_DIR . '/*.php') ?: [] as $datei) {
            $faelle[basename($datei)] = [$datei];
        }
        foreach (glob(self::VIEWS_DIR . '/partials/*.php') ?: [] as $datei) {
            $faelle['partials/' . basename($datei)] = [$datei];
        }
        self::assertNotSame([], $faelle, 'Keine Views gefunden - Verzeichnis verschoben?');

        return $faelle;
    }

    /**
     * Eine Tabelle braucht einen waagerechten Bildlauf.
     *
     * Sonst sprengt eine Verwaltungsliste mit Aktionsspalte auf 360px die
     * Seite - und zwar nicht nur sich selbst: Der Rest der Oberfläche
     * verschiebt sich mit, und der Benutzer scrollt eine leere Fläche.
     * Gemessen wurde: 17 Views mit Tabellen, 4 mit `overflow-x`.
     */
    #[DataProvider('viewProvider')]
    public function testJedeTabelleHatEinenBildlaufBehaelter(string $datei): void {
        $inhalt = (string)file_get_contents($datei);
        if (!str_contains($inhalt, '<table')) {
            $this->assertTrue(true, 'Keine Tabelle in dieser View.');
            return;
        }

        $this->assertMatchesRegularExpression(
            '/tabelle-scroll|overflow-x/',
            $inhalt,
            basename($datei) . ': Die Tabelle braucht einen Bildlauf-Behälter '
            . '(<div class="tabelle-scroll">, siehe public/css/style.css).'
        );
    }

    /**
     * Kein Raster mit fest gezählten Spalten.
     *
     * `grid-template-columns: 1fr 1fr` heisst auf 360px: zwei Felder von je
     * rund 150 Pixeln. In einem Formular ist das unbedienbar, und ein
     * Inline-Style kann keine Media Query tragen, die es rettet. Erlaubt ist
     * `repeat(auto-fit, minmax(...))` oder eine Klasse (`.raster`,
     * `.raster-eng`), die unterhalb von 480px auf eine Spalte fällt.
     */
    #[DataProvider('viewProvider')]
    public function testKeinRasterMitFestGezaehltenSpalten(string $datei): void {
        $inhalt = (string)file_get_contents($datei);

        preg_match_all('/grid-template-columns:\s*([^;"\']+)/i', $inhalt, $treffer);

        $fest = [];
        foreach ($treffer[1] as $wert) {
            $wert = trim($wert);
            if (stripos($wert, 'auto-fit') !== false || stripos($wert, 'auto-fill') !== false) {
                continue;
            }
            // Eine einzelne Spalte ist kein Problem - sie bricht nicht.
            //
            // Lookaround statt verbrauchender Gruppen: `(^|\s)…(\s|$)` frisst
            // das Trennzeichen mit, und "1fr 1fr" zaehlte dadurch als EINE
            // Spalte - die Gegenprobe blieb gruen, obwohl der Fall genau der
            // gemeinte war.
            if (preg_match_all('/(?<=^|\s)[\d.]*fr(?=\s|$)/', $wert) >= 2) {
                $fest[] = $wert;
            }
        }

        $this->assertSame(
            [],
            $fest,
            basename($datei) . ': fest gezählte Rasterspalten (' . implode(' | ', $fest) . '). '
            . 'repeat(auto-fit, minmax(…)) oder die Klasse .raster benutzen.'
        );
    }

    /**
     * Die Umbruchpunkte stehen an EINER Stelle.
     *
     * Eine Media Query in einer View wäre der Anfang eines zweiten Satzes
     * Umbruchpunkte - und zwei Sätze laufen auseinander, sobald einer
     * geändert wird.
     */
    #[DataProvider('viewProvider')]
    public function testKeineMediaQueryInEinerView(string $datei): void {
        // Nur in <style>-Bloecken suchen, nicht im ganzen Text: `@media`
        // kommt auch in Kommentaren vor, die auf eine Regel im Stylesheet
        // VERWEISEN (layout.php tut das beim Darkmode-Vorlauf). Ein Verweis
        // ist kein zweiter Satz Umbruchpunkte.
        $inhalt = (string)file_get_contents($datei);
        preg_match_all('#<style\b[^>]*>(.*?)</style>#is', $inhalt, $bloecke);

        foreach ($bloecke[1] as $block) {
            $this->assertStringNotContainsString(
                '@media',
                $block,
                basename($datei) . ': Umbruchpunkte gehören nach public/css/style.css, nicht in eine View.'
            );
        }

        $this->assertTrue(true, 'Kein <style>-Block mit Media Query.');
    }

    /**
     * Und das Stylesheet führt sie wirklich - sonst wäre die Regel oben eine
     * Regel über nichts.
     */
    public function testDasStylesheetKenntDieBenanntenUmbruchpunkte(): void {
        $css = (string)file_get_contents(__DIR__ . '/../../../public/css/style.css');

        foreach (['480px', '768px'] as $punkt) {
            $this->assertStringContainsString(
                'max-width: ' . $punkt,
                $css,
                "Umbruchpunkt {$punkt} fehlt im Stylesheet."
            );
        }
        // Auf die REGEL pruefen, nicht auf die Zeichenkette: '.tabelle-scroll'
        // steckt auch in '.tabelle-scroll-weg', und ein blosses Vorkommen
        // koennte ebenso gut ein Kommentar sein. Gesucht wird die Definition.
        foreach (['.tabelle-scroll', '.raster', '.raster-eng', '.aktionen'] as $klasse) {
            $this->assertMatchesRegularExpression(
                '/^\s*' . preg_quote($klasse, '/') . '\s*(,|\{)/m',
                $css,
                "Hilfsklasse {$klasse} ist im Stylesheet nicht definiert."
            );
        }
    }
}
