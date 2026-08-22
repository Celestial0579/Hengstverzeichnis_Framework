<?php
// tests/Unit/Service/HorseMediaVideoUrlTest.php

namespace Tests\Unit\Service;

use App\Service\HorseMedia;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Die Host-Allowlist für Video-Links (#339).
 *
 * ÜBERNOMMEN AUS DEM ABGELÖSTEN ADDON, samt Tests. Das Addon `galerie` prüfte
 * schon so, und eine Kern-Fassung, die nur „irgendein http(s)-Link" akzeptiert
 * hätte, wäre hinter dem Stand zurück, den sie ersetzt — der unangenehmste
 * Fall beim Übernehmen von Addon-Funktionen: Die Oberfläche sieht gleich aus,
 * die Prüfung ist schwächer, und es fällt niemandem auf.
 *
 * Die Prüfung macht PHPs `parse_url()`, angezeigt wird die Zeichenkette später
 * im Browser. Solange die Eingabe unverändert durchgereicht wird, hängt die
 * Sicherheit daran, dass beide Parser jede Eingabe gleich lesen — und
 * Abweichungen zwischen Parsern sind der Stoff, aus dem
 * Allowlist-Umgehungen gemacht sind. Seit die URL aus den geprüften Teilen
 * NEU GEBAUT wird, ist die Frage gegenstandslos.
 */
class HorseMediaVideoUrlTest extends TestCase {

    /** @return array<string, array{0: string}> */
    public static function abgelehnt(): array {
        return [
            'Benutzerinfo vor dem @' => ['https://youtube.com@evil.tld/video'],
            'Rueckwaertsschraegstrich vor dem @' => ['https://youtube.com\\@evil.tld/video'],
            'Fremder Host mit Fragment' => ['https://evil.tld#youtube.com'],
            'Suffix-Trick' => ['https://youtube.com.evil.tld/'],
            'Kein https' => ['http://youtube.com/watch?v=abc'],
            'Anderes Schema' => ['javascript:alert(1)'],
            'Datenschema' => ['data:text/html,<script>'],
            'Leer' => [''],
        ];
    }

    #[DataProvider('abgelehnt')]
    public function testFremdeHostsUndSchemataWerdenVerworfen(string $url): void {
        $this->assertNull(HorseMedia::gepruefterVideoLink($url));
    }

    public function testErlaubteHostsKommenNormalisiertZurueck(): void {
        $this->assertSame(
            'https://www.youtube.com/watch?v=abc123',
            HorseMedia::gepruefterVideoLink('https://www.youtube.com/watch?v=abc123')
        );
        $this->assertSame(
            'https://youtu.be/abc123',
            HorseMedia::gepruefterVideoLink('  https://youtu.be/abc123  ')
        );
        // Grossschreibung im Host ist zulaessig und wird normalisiert.
        $this->assertSame(
            'https://vimeo.com/12345',
            HorseMedia::gepruefterVideoLink('https://VIMEO.com/12345')
        );
    }

    /**
     * Das Fragment gehört nicht in eine Video-Adresse und fällt beim Neubau
     * weg - damit kann es auch nichts mehr verschleiern.
     */
    public function testFragmentWirdEntfernt(): void {
        $this->assertSame(
            'https://vimeo.com/12345',
            HorseMedia::gepruefterVideoLink('https://vimeo.com/12345#evil')
        );
    }

    /**
     * Anführungszeichen und spitze Klammern könnten im Attributwert den Wert
     * beenden - sie werden verworfen.
     */
    public function testAttributBrechendeZeichenWerdenVerworfen(): void {
        $this->assertNull(HorseMedia::gepruefterVideoLink('https://vimeo.com/12"onload=x'));
        $this->assertNull(HorseMedia::gepruefterVideoLink("https://vimeo.com/12'x"));
        $this->assertNull(HorseMedia::gepruefterVideoLink('https://vimeo.com/<script>'));
    }

    /**
     * Zeilenumbruch, Wagenrücklauf und Tabulator ersetzt PHPs `parse_url()`
     * von sich aus durch '_' - sie erreichen die eigene Prüfung also gar nicht
     * mehr. Festgehalten, weil sich sonst niemand darauf verlassen sollte:
     * Ändert PHP dieses Verhalten, wird der Test rot und nicht die Anwendung
     * unsicher.
     */
    public function testZeilenumbruecheWerdenVonParseUrlEntschaerft(): void {
        $this->assertSame(
            'https://vimeo.com/12_345',
            HorseMedia::gepruefterVideoLink("https://vimeo.com/12\n345")
        );
    }

    public function testEineUeberlangeAdresseWirdVerworfenStattAbgeschnitten(): void {
        // Abschneiden ergaebe eine ANDERE Adresse als die geprueften Teile -
        // genau das, was der Neubau verhindern soll.
        $lang = 'https://vimeo.com/' . str_repeat('a', 300);

        $this->assertNull(HorseMedia::gepruefterVideoLink($lang));
    }
}
