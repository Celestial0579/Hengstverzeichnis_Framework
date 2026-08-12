<?php
// tests/Unit/Helper/ThemeContrastTest.php

namespace Tests\Unit\Helper;

use App\Helper\ColorContrast;
use PHPUnit\Framework\TestCase;

/**
 * Kontrast-Gate für die ausgelieferten Theme-Defaults (#196): liest die
 * Variablen aus public/css/style.css und rechnet die WCAG-Verhältnisse der
 * Kombinationen nach, die frühere Regressionen getroffen haben (Footer,
 * Buttons, Nav-Buttons) - in BEIDEN Themes. Der e2e-Dark-Audit ist bewusst
 * regressions-gescoped und sieht Farben, die in beiden Themes gleich sind,
 * nie; dieser Test prüft absolut.
 *
 * Zusätzlich wird erzwungen, dass die beiden Darkmode-Zwillingsblöcke
 * wortgleich definiert sind (siehe Warnkommentar in style.css) - bisher war
 * das nur eine Bitte an den nächsten Bearbeiter.
 */
class ThemeContrastTest extends TestCase {

    private static string $css = '';

    public static function setUpBeforeClass(): void {
        self::$css = (string)file_get_contents(__DIR__ . '/../../../public/css/style.css');
    }

    /**
     * Zieht alle --variable: wert; Deklarationen aus einem CSS-Blockinhalt.
     * Mehrfach-Deklarationen derselben Variable (statischer Fallback +
     * color-mix-Zeile) bleiben in Reihenfolge erhalten.
     *
     * @return array<string, list<string>>
     */
    private function parseVars(string $block): array {
        // Kommentare zuerst entfernen: Variablennamen im Kommentartext würden
        // sonst als (kaputte) Deklarationen mitgelesen und können sogar die
        // nachfolgende echte Deklaration verschlucken.
        $block = (string)preg_replace('~/\*.*?\*/~s', '', $block);
        $vars = [];
        preg_match_all('/--([a-z0-9-]+)\s*:\s*([^;]+);/i', $block, $m, PREG_SET_ORDER);
        foreach ($m as $match) {
            $vars['--' . $match[1]][] = trim($match[2]);
        }
        return $vars;
    }

    /** @return array<string, list<string>> */
    private function rootVars(): array {
        $this->assertMatchesRegularExpression('/:root\s*\{/', self::$css);
        preg_match('/^:root\s*\{(.*?)\n\}/ms', self::$css, $m);
        $this->assertNotEmpty($m, ':root-Block nicht gefunden');
        return $this->parseVars($m[1]);
    }

    /** @return array<string, list<string>> */
    private function darkVars(string $which): array {
        if ($which === 'media') {
            preg_match('/@media \(prefers-color-scheme: dark\)\s*\{\s*:root:not\(\[data-theme="light"\]\)\s*\{(.*?)\}\s*\}/s', self::$css, $m);
        } else {
            preg_match('/:root\[data-theme="dark"\]\s*\{(.*?)\n\}/s', self::$css, $m);
        }
        $this->assertNotEmpty($m, "Darkmode-Block '{$which}' nicht gefunden");
        return $this->parseVars($m[1]);
    }

    /**
     * Löst eine Variable zu einem Hex-Wert auf: nimmt die späteste
     * Deklaration, die sich (ggf. über var()-Ketten) zu Hex auflösen lässt -
     * die color-mix-Zeile ist hier nicht auswertbar, dann greift der
     * statische Fallback davor, exakt wie in Browsern ohne color-mix.
     *
     * @param array<string, list<string>> $vars
     */
    private function resolve(array $vars, string $name, int $depth = 0): ?string {
        $this->assertLessThan(10, $depth, "Zirkelbezug bei {$name}");
        $declarations = $vars[$name] ?? [];
        foreach (array_reverse($declarations) as $value) {
            if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $value)) {
                return $value;
            }
            if (preg_match('/^var\((--[a-z0-9-]+)(?:\s*,\s*[^)]+)?\)$/i', $value, $m)) {
                $resolved = $this->resolve($vars, $m[1], $depth + 1);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }
        return null;
    }

    public function testDarkmodeTwinBlocksAreIdentical(): void {
        $media = $this->darkVars('media');
        $manual = $this->darkVars('manual');

        // Reihenfolge-unabhängig vergleichen, aber Werte exakt: Ein Wert, der
        // nur in einem Block geändert wurde, ist genau der Fehler, vor dem
        // der Kommentar in style.css warnt.
        ksort($media);
        ksort($manual);
        $this->assertSame($media, $manual, 'Die beiden Darkmode-Blöcke in style.css sind nicht mehr wortgleich');
    }

    public function testLightThemeFooterAndButtonsMeetAa(): void {
        $vars = $this->rootVars();

        $footerBg = $this->resolve($vars, '--footer-bg');
        $footerFg = $this->resolve($vars, '--footer-fg');
        $footerLink = $this->resolve($vars, '--footer-link-color');
        $this->assertNotNull($footerBg);
        $this->assertNotNull($footerFg);
        $this->assertNotNull($footerLink);
        $this->assertGreaterThanOrEqual(4.5, ColorContrast::contrastRatio($footerFg, $footerBg), 'Footer-Text (hell)');
        $this->assertGreaterThanOrEqual(4.5, ColorContrast::contrastRatio($footerLink, $footerBg), 'Footer-Links (hell)');

        $btnBg = $this->resolve($vars, '--primary-color');
        $btnFg = $this->resolve($vars, '--on-primary');
        $this->assertGreaterThanOrEqual(4.5, ColorContrast::contrastRatio($btnFg, $btnBg), '.btn (hell)');

        $hoverBg = $this->resolve($vars, '--secondary-btn-bg');
        $this->assertGreaterThanOrEqual(4.5, ColorContrast::contrastRatio('#ffffff', $hoverBg), '.btn:hover');

        $this->assertGreaterThanOrEqual(
            4.5,
            ColorContrast::contrastRatio($this->resolve($vars, '--link-color'), $this->resolve($vars, '--bg-color')),
            'Inhalts-Links (hell)'
        );
    }

    public function testDarkThemeFooterAndNavButtonMeetThresholds(): void {
        $root = $this->rootVars();
        $dark = $this->darkVars('manual');
        // Dark überschreibt :root - für die Auflösung beide Ebenen mischen,
        // Dark gewinnt.
        $vars = array_merge($root, $dark);

        $footerBg = $this->resolve($vars, '--footer-bg');
        $footerFg = $this->resolve($vars, '--footer-fg');
        $footerLink = $this->resolve($vars, '--footer-link-color');
        $this->assertGreaterThanOrEqual(4.5, ColorContrast::contrastRatio($footerFg, $footerBg), 'Footer-Text (dunkel)');
        $this->assertGreaterThanOrEqual(4.5, ColorContrast::contrastRatio($footerLink, $footerBg), 'Footer-Links (dunkel)');

        // Der .btn-nav-Rahmen muss die Fläche >= 3:1 (Komponenten-Kontrast)
        // vom Header abheben - im Darkmode über den statischen
        // --primary-fg-Fallback (die color-mix-Ableitung ist mindestens so
        // hell und kann nur besser sein).
        $border = $this->resolve($vars, '--primary-fg');
        $headerBg = $this->resolve($vars, '--header-bg');
        $this->assertGreaterThanOrEqual(3.0, ColorContrast::contrastRatio($border, $headerBg), '.btn-nav-Rahmen (dunkel)');

        // Text im dunklen Theme auf der (nicht umgeschalteten) Markenfläche:
        // --on-primary bleibt auch hier die berechnete Textfarbe der Buttons.
        $this->assertGreaterThanOrEqual(
            4.5,
            ColorContrast::contrastRatio($this->resolve($vars, '--on-primary'), $this->resolve($vars, '--primary-color')),
            '.btn (dunkel)'
        );
    }

    public function testLayoutInjectsOnPrimaryAndDropsNavBtnIsland(): void {
        $layout = (string)file_get_contents(__DIR__ . '/../../../src/Views/layout.php');
        // Ohne die Injektion wäre --on-primary immer der statische
        // Weiß-Fallback aus style.css - auf hellen Markenfarben unlesbar.
        $this->assertStringContainsString('--on-primary:', $layout, 'layout.php injiziert --on-primary nicht mehr');
        $this->assertStringContainsString('ColorContrast::readableTextOn', $layout);
        // Die Insel-Stile dürfen nicht zurückkommen (#196): Sie liefen an
        // jeder zentralen Kontrast-Korrektur vorbei.
        $this->assertStringNotContainsString('.nav-btn-admin', $layout, 'Insel-Stile .nav-btn-admin sind zurück in layout.php');
        $this->assertStringContainsString('btn btn-nav', $layout, 'Admin-Button nutzt die gemeinsamen Button-Klassen nicht mehr');
    }

    public function testFooterRulesUseDerivedVariables(): void {
        // Der Footer darf nicht auf rohe Markenfarben oder hartes Weiß
        // zurückfallen.
        preg_match('/footer\s*\{([^}]*)\}/s', self::$css, $m);
        $this->assertNotEmpty($m, 'footer-Regel nicht gefunden');
        $this->assertStringContainsString('var(--footer-bg)', $m[1]);
        $this->assertStringContainsString('var(--footer-fg)', $m[1]);
        $this->assertStringNotContainsString('color: white', $m[1]);

        // Selektor MIT den beiden :not() (#248): Ein schlichtes `footer a`
        // (0,0,2) verliert gegen die globale Inhalts-Link-Regel
        // `a:not(.btn):not(.nav-link)` (0,2,1) - die Footer-Links bekämen
        // --link-color statt --footer-link-color und fielen im hellen Theme
        // auf der Markenfläche unter 4,5:1 (real gemessen: 1,8:1).
        preg_match('/footer a:not\(\.btn\):not\(\.nav-link\)\s*\{([^}]*)\}/s', self::$css, $m);
        $this->assertNotEmpty($m, 'footer-Link-Regel (footer a:not(.btn):not(.nav-link)) nicht gefunden - '
            . 'ohne die :not()-Spezifität überschreibt die globale Inhalts-Link-Regel die Footer-Linkfarbe (#248)');
        $this->assertStringContainsString('var(--footer-link-color)', $m[1]);
        $this->assertStringNotContainsString('var(--secondary-color)', $m[1]);
    }

    public function testColorSchemeFollowsTheme(): void {
        // color-scheme koppelt die Browser-eigenen Farben (UA-Buttontext von
        // Bedienelementen ohne eigene Farbangabe) ans Theme (#248): Ohne
        // 'dark' im Darkmode stand schwarzer UA-Buttontext auf dunklen
        // Theme-Flächen - real getroffen hat es Plugin-Buttons, die nur
        // background aus den Theme-Variablen setzen ("☆ Merken" 1,44:1,
        // "QR-Code anzeigen" 1,26:1; Soll >= 3:1, WCAG 1.4.11). Der
        // Zwillingsblock-Vergleich oben sieht nur --Variablen, deshalb hier
        // beide Blöcke einzeln.
        preg_match('/^:root\s*\{(.*?)\n\}/ms', self::$css, $m);
        $this->assertStringContainsString('color-scheme: light', $m[1] ?? '', ':root ohne color-scheme: light');

        preg_match('/@media \(prefers-color-scheme: dark\)\s*\{\s*:root:not\(\[data-theme="light"\]\)\s*\{(.*?)\}\s*\}/s', self::$css, $m);
        $this->assertStringContainsString('color-scheme: dark', $m[1] ?? '', 'Media-Darkmode-Block ohne color-scheme: dark');

        preg_match('/:root\[data-theme="dark"\]\s*\{(.*?)\n\}/s', self::$css, $m);
        $this->assertStringContainsString('color-scheme: dark', $m[1] ?? '', 'Manueller Darkmode-Block ohne color-scheme: dark');
    }
}
