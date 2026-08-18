<?php
// tests/Unit/Helper/NavItemsTest.php

namespace Tests\Unit\Helper;

use App\Helper\NavItems;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Die Prüfung der Menüpunkte aus Addons (Filter `layout.nav_items`).
 *
 * Rein, ohne Netz und ohne Datenbank - genau deshalb steht die Logik in einer
 * eigenen Klasse und nicht in layout.php: Die Navigation steht auf jeder
 * öffentlichen Seite, ihre Weißliste gehört einzeln festgenagelt.
 */
class NavItemsTest extends TestCase {

    public function testValidEntryPassesThrough(): void {
        $ergebnis = NavItems::sanitize([
            ['url' => '/plugin/zucht-suche', 'label' => 'Zucht', 'icon' => '🧬'],
        ]);

        $this->assertSame(
            [['url' => '/plugin/zucht-suche', 'label' => 'Zucht', 'icon' => '🧬']],
            $ergebnis
        );
    }

    public function testQueryStringIsAllowed(): void {
        $ergebnis = NavItems::sanitize([
            ['url' => '/plugin/zucht-suche?art=stationen', 'label' => 'Deckstationen'],
        ]);

        $this->assertCount(1, $ergebnis);
        $this->assertSame('/plugin/zucht-suche?art=stationen', $ergebnis[0]['url']);
    }

    /** Ohne Symbol gibt es das neutrale Puzzleteil, wie bei den Dashboard-Kacheln. */
    public function testMissingIconFallsBackToThePuzzlePiece(): void {
        $ergebnis = NavItems::sanitize([['url' => '/x', 'label' => 'Ohne Symbol']]);
        $this->assertSame('🧩', $ergebnis[0]['icon']);
    }

    /**
     * @param mixed $url Adresse, die abgelehnt werden muss
     */
    #[DataProvider('abzulehnendeAdressen')]
    public function testUnsafeOrForeignUrlsAreDropped(mixed $url, string $warum): void {
        $this->assertSame(
            [],
            NavItems::sanitize([['url' => $url, 'label' => 'Egal']]),
            $warum
        );
    }

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function abzulehnendeAdressen(): array {
        return [
            'javascript' => ['javascript:alert(1)', 'javascript: gehört nicht in ein href der Navigation.'],
            'data' => ['data:text/html,<script>1</script>', 'data: ebensowenig.'],
            'fremde Domain' => ['https://fremd.example/x', 'Die Navigation verlinkt nur die eigene Seite.'],
            'protokollrelativ' => ['//fremd.example/x', 'Beginnt mit /, führt aber auf einen fremden Host - der übersehene Fall.'],
            'relativ' => ['plugin/ohne-schraegstrich', 'Relative Pfade hängen vom aktuellen Verzeichnis ab.'],
            'nach oben' => ['/../raus', '.. gehört nicht in eine Menü-Adresse.'],
            'Backslash' => ['/plugin\\x', 'Manche Browser behandeln \\ wie /.'],
            'Zeilenumbruch' => ["/plugin\nx", 'Steuerzeichen im Attribut sind ein Filter-Trick.'],
            'Nullbyte' => ["/plugin\0x", 'Nullbyte ebenso.'],
            'leer' => ['', 'Ohne Adresse kein Menüpunkt.'],
            'kein String' => [42, 'Ein Addon kann alles zurückgeben.'],
            'null' => [null, 'Fehlender Schlüssel darf nicht in einen TypeError laufen.'],
        ];
    }

    public function testEntryWithoutLabelIsDropped(): void {
        $this->assertSame([], NavItems::sanitize([['url' => '/x']]));
        $this->assertSame([], NavItems::sanitize([['url' => '/x', 'label' => '   ']]));
    }

    public function testLabelIsShortenedAndFlattenedToOneLine(): void {
        $ergebnis = NavItems::sanitize([[
            'url' => '/x',
            'label' => "Ein  sehr\nlanger Menüpunkt, der die Navigation sprengen würde",
        ]]);

        $this->assertCount(1, $ergebnis);
        $this->assertStringNotContainsString("\n", $ergebnis[0]['label']);
        $this->assertStringNotContainsString('  ', $ergebnis[0]['label']);
        $this->assertSame(NavItems::LABEL_MAX, mb_strlen($ergebnis[0]['label']));
    }

    public function testNonArrayInputAndJunkEntriesAreIgnored(): void {
        $this->assertSame([], NavItems::sanitize('kein Array'));
        $this->assertSame([], NavItems::sanitize(null));
        $this->assertSame([], NavItems::sanitize([['nur' => 'quatsch'], 'string', 5]));
    }

    /** Die Navigation ist kein Menübaum - ab MAX_ITEMS ist Schluss. */
    public function testNumberOfEntriesIsCapped(): void {
        $viele = [];
        for ($i = 0; $i < NavItems::MAX_ITEMS + 3; $i++) {
            $viele[] = ['url' => "/x{$i}", 'label' => "Punkt {$i}"];
        }

        $this->assertCount(NavItems::MAX_ITEMS, NavItems::sanitize($viele));
    }

    public function testActiveIgnoresTheQueryString(): void {
        $this->assertTrue(NavItems::isActive('/plugin/zucht-suche', '/plugin/zucht-suche'));
        $this->assertTrue(NavItems::isActive('/plugin/zucht-suche?art=stationen', '/plugin/zucht-suche'));
        $this->assertFalse(NavItems::isActive('/plugin/zucht-suche', '/katalog'));
        // Kein Präfix-Treffer: /plugin/zucht wäre sonst auf /plugin/zucht-suche aktiv.
        $this->assertFalse(NavItems::isActive('/plugin/zucht', '/plugin/zucht-suche'));
    }
}
