<?php
// tests/Unit/Controllers/MediaControllerSitzungTest.php

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

/**
 * Bildanfragen prüfen die Sitzung ohne checkAuth() (Audit N44).
 *
 * checkAuth() rotiert die Sitzungs-ID nach 15 Minuten. Eine Katalogseite
 * fordert Dutzende Bilder parallel an; rotierte eine davon, legten die
 * übrigen mit der alten ID leere Sitzungen an und überschrieben das Cookie.
 * Der MediaController nutzt deshalb die leichte Form currentUserId() -
 * dieselbe Regel, ohne Rotation.
 *
 * Geprüft über die Tokens statt über den Rohtext: Der #314-Kommentarblock
 * nennt checkAuth() weiterhin.
 */
class MediaControllerSitzungTest extends TestCase {

    /** @return list<array{0:int|string,1:string}> Tokens ohne Kommentare und Leerraum */
    private static function tokens(): array {
        $code = (string)file_get_contents(__DIR__ . '/../../../src/Controllers/MediaController.php');
        $ergebnis = [];
        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                    continue;
                }
                $ergebnis[] = [$token[0], $token[1]];
            } else {
                $ergebnis[] = [$token, $token];
            }
        }
        return $ergebnis;
    }

    /** @return int Anzahl der Aufrufe name( im Code */
    private static function aufrufe(string $name): int {
        $tokens = self::tokens();
        $anzahl = 0;
        foreach ($tokens as $i => [$typ, $text]) {
            if ($typ === T_STRING && $text === $name && ($tokens[$i + 1][1] ?? '') === '(') {
                $anzahl++;
            }
        }
        return $anzahl;
    }

    public function testBildauslieferungRuftCheckAuthNichtAuf(): void {
        $this->assertSame(0, self::aufrufe('checkAuth'), 'checkAuth() rotiert die Sitzungs-ID - parallele Bildanfragen verlieren dann die Sitzung');
    }

    public function testBildauslieferungPrueftDieSitzungUeberCurrentUserId(): void {
        $this->assertGreaterThanOrEqual(2, self::aufrufe('currentUserId'), 'horseImage() und horseMedia() müssen die Sitzung prüfen');
    }
}
