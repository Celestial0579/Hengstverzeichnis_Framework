<?php
// tests/Unit/Views/StatusFarbenLintTest.php

namespace Tests\Unit\Views;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Keine festen Bootstrap-Statusfarben in den Views (#545).
 *
 * Die Töne der Bootstrap-Hinweiskästen (#155724 auf #d4edda, #721c24 auf
 * #f8d7da, #856404 auf #fff3cd) gibt es nur für hellen Grund. Als Text auf
 * einer Theme-Fläche kam #721c24 im Darkmode auf 1,5:1. Als festes Paar
 * bleiben sie zwar lesbar, stehen im Darkmode aber als helle Kästen da.
 * Dafür gibt es --success-fg/--warning-fg/--danger-fg und die passenden
 * --*-soft-bg aus style.css, die in beiden Themes geprüft sind
 * (ThemeContrastTest).
 *
 * Geprüft werden nur diese sechs Werte: Sie sind eindeutig die
 * Bootstrap-Statusfarben, ein Treffer ist also immer ein Fund. HTML-Mails
 * (src/Service) kennen keine CSS-Variablen und bleiben außen vor.
 */
class StatusFarbenLintTest extends TestCase {

    private const VIEWS_DIR = __DIR__ . '/../../../src/Views';
    private const STATUSFARBEN = ['#155724', '#721c24', '#856404', '#d4edda', '#f8d7da', '#fff3cd'];

    /** @return array<string, array{0: string}> */
    public static function viewProvider(): array {
        $faelle = [];
        $dateien = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::VIEWS_DIR, \FilesystemIterator::SKIP_DOTS));
        foreach ($dateien as $datei) {
            if ($datei->getExtension() === 'php') {
                $faelle[substr($datei->getPathname(), strlen(self::VIEWS_DIR) + 1)] = [$datei->getPathname()];
            }
        }
        self::assertNotSame([], $faelle, 'Keine Views gefunden - Verzeichnis verschoben?');

        return $faelle;
    }

    #[DataProvider('viewProvider')]
    public function testKeineFestenStatusfarben(string $datei): void {
        $funde = [];
        foreach (file($datei) ?: [] as $nr => $zeile) {
            foreach (self::STATUSFARBEN as $farbe) {
                if (stripos($zeile, $farbe) !== false) {
                    $funde[] = 'Zeile ' . ($nr + 1) . ": {$farbe}";
                }
            }
        }
        $this->assertSame(
            [],
            $funde,
            'Feste Bootstrap-Statusfarbe - bitte var(--success-fg) usw. und var(--*-soft-bg) nehmen'
        );
    }
}
