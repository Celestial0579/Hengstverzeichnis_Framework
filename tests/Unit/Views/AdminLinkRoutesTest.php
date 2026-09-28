<?php
// tests/Unit/Views/AdminLinkRoutesTest.php

namespace Tests\Unit\Views;

use PHPUnit\Framework\TestCase;

/**
 * Jeder feste Link `href="/admin/…"` in einer View muss auf eine registrierte
 * GET-Route zeigen (Audit N83: /admin/system und /admin/addon-store gab es
 * nicht - der Hinweis „Sprache fehlt“ führte auf eine 404-Seite).
 *
 * Geprüft werden nur Literale ohne PHP-Ausdruck; Query-String und Fragment
 * zählen nicht zum Pfad (der Router vergleicht den Pfad exakt).
 */
class AdminLinkRoutesTest extends TestCase {

    /** @return array<string, true> */
    private static function registrierteGetPfade(): array {
        $index = (string)file_get_contents(__DIR__ . '/../../../public/index.php');
        preg_match_all("/\\\$router->(?:get|redirect)\\(\\s*'([^']+)'/", $index, $treffer);
        return array_fill_keys($treffer[1], true);
    }

    public function testAlleAdminLinksDerViewsSindRegistriert(): void {
        $routen = self::registrierteGetPfade();
        $this->assertArrayHasKey('/admin/system-settings', $routen, 'Routenerkennung defekt?');

        $fehlend = [];
        $geprueft = 0;
        foreach (glob(__DIR__ . '/../../../src/Views/*.php') ?: [] as $view) {
            $inhalt = (string)file_get_contents($view);
            preg_match_all('#href="(/admin[^"]*)"#', $inhalt, $treffer);
            foreach ($treffer[1] as $href) {
                if (str_contains($href, '<?')) {
                    continue;
                }
                $pfad = preg_replace('/[?#].*$/', '', $href);
                $geprueft++;
                if (!isset($routen[$pfad])) {
                    $fehlend[] = basename($view) . ': ' . $href;
                }
            }
        }

        $this->assertGreaterThan(20, $geprueft, 'Zu wenige Links gefunden - Muster defekt?');
        $this->assertSame([], array_values(array_unique($fehlend)), 'Links ohne registrierte Route.');
    }
}
