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
 * Bootstrap-Statusfarben, ein Treffer ist also immer ein Fund. Dazu kommen
 * Grün und Rot der Bootstrap-Schaltflächen, die als Text zu schwach sind
 * (#28a745 auf hellem Grund 2,97:1, #dc3545 auf dunklem 3,93:1). Erkannt
 * wird auch die Schreibweise als rgb()/rgba() und mit Deckkraft (#721c24ff).
 * HTML-Mails (src/Service) kennen keine CSS-Variablen und bleiben außen vor.
 */
class StatusFarbenLintTest extends TestCase {

    private const VIEWS_DIR = __DIR__ . '/../../../src/Views';
    private const STATUSFARBEN = ['#155724', '#721c24', '#856404', '#d4edda', '#f8d7da', '#fff3cd'];
    private const SCHALTFLAECHENFARBEN = ['#28a745', '#dc3545'];

    /**
     * Views, die Grün oder Rot der Schaltflächen noch haben; #550 stellt sie um.
     * Die Liste schrumpft nur: Wer eine View umstellt, nimmt sie heraus, sonst
     * schlägt testNochOffenIstAktuell an.
     */
    private const NOCH_OFFEN = [
        '2fa_setup.php',
        'admin_dashboard.php',
        'admin_gdpr.php',
        'admin_horse_form.php',
        'admin_matches.php',
        'admin_system_settings.php',
        'admin_trash.php',
        'setup.php',
    ];

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

    /**
     * Farben einer Zeile als #rrggbb in Kleinbuchstaben: Hex mit und ohne
     * Deckkraft, rgb() und rgba() mit Komma oder Leerzeichen.
     *
     * @return list<string>
     */
    private static function farben(string $zeile): array {
        $farben = [];
        preg_match_all('/#([0-9a-f]{6})(?:[0-9a-f]{2})?\b/i', $zeile, $hex);
        foreach ($hex[1] as $wert) {
            $farben[] = '#' . strtolower($wert);
        }
        preg_match_all('/rgba?\(\s*(\d{1,3})\s*[,\s]\s*(\d{1,3})\s*[,\s]\s*(\d{1,3})/i', $zeile, $rgb, PREG_SET_ORDER);
        foreach ($rgb as $teile) {
            $farben[] = sprintf('#%02x%02x%02x', (int) $teile[1], (int) $teile[2], (int) $teile[3]);
        }

        return $farben;
    }

    /** @return list<string> */
    private static function funde(string $datei, array $verboten): array {
        $funde = [];
        foreach (file($datei) ?: [] as $nr => $zeile) {
            foreach (array_intersect(self::farben($zeile), $verboten) as $farbe) {
                $funde[] = 'Zeile ' . ($nr + 1) . ": {$farbe}";
            }
        }

        return $funde;
    }

    #[DataProvider('viewProvider')]
    public function testKeineFestenStatusfarben(string $datei): void {
        $verboten = self::STATUSFARBEN;
        if (!in_array(substr($datei, strlen(self::VIEWS_DIR) + 1), self::NOCH_OFFEN, true)) {
            $verboten = array_merge($verboten, self::SCHALTFLAECHENFARBEN);
        }
        $this->assertSame(
            [],
            self::funde($datei, $verboten),
            'Feste Bootstrap-Statusfarbe - bitte var(--success-fg) usw. und var(--*-soft-bg) nehmen'
        );
    }

    public function testNochOffenIstAktuell(): void {
        foreach (self::NOCH_OFFEN as $view) {
            $datei = self::VIEWS_DIR . '/' . $view;
            $this->assertFileExists($datei);
            $this->assertNotSame(
                [],
                self::funde($datei, self::SCHALTFLAECHENFARBEN),
                "{$view} nutzt Grün und Rot der Schaltflächen nicht mehr - bitte aus NOCH_OFFEN streichen"
            );
        }
    }

    public function testErkenntSchreibweisen(): void {
        $this->assertSame(['#721c24'], self::farben('color: rgb(114, 28, 36);'));
        $this->assertSame(['#721c24'], self::farben('color: rgb(114 28 36 / 50%);'));
        $this->assertSame(['#721c24'], self::farben('color: #721C24;'));
        $this->assertSame(['#721c24'], self::farben('color: #721c24ff;'));
        $this->assertSame(['#dc3545'], self::farben('border-color: rgba(220,53,69,.5)'));
        $this->assertSame([], self::farben('#1557240 und 12:34:56'));
    }
}
