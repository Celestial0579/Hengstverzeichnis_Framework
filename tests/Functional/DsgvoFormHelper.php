<?php
// tests/Functional/DsgvoFormHelper.php

namespace Tests\Functional;

use Tests\Support\HttpResponse;

/**
 * Gemeinsame Helfer für Tests, die das öffentliche DSGVO-Formular tatsächlich
 * absenden (siehe DsgvoPortalTest und GdprEraseTest).
 *
 * Die CAPTCHA-Aufgabe wird ausdrücklich GELÖST, nicht umgangen: Die Lösung
 * steht nur serverseitig in der Session, der Helfer liest wie ein Besucher den
 * ausgeschriebenen Aufgabentext aus dem HTML und rechnet ihn aus. Den Schutz
 * für Tests abzuschalten wäre der falsche Weg - er wäre dann genau dort
 * ungeprüft, wo er zählt.
 *
 * Alle Requests der Functional-Suite kommen von 127.0.0.1 und teilen sich damit
 * dieselben IP-Zähler. Deshalb setzt resetDsgvoRateLimit() sie zurück, sonst
 * hinge das Ergebnis von der Reihenfolge der Testklassen und von vorherigen
 * Läufen gegen dieselbe Test-Datenbank ab.
 */
trait DsgvoFormHelper {

    /**
     * Zahlwörter der deutschen Sprachdatei (`captcha.number_*` in lang/de.php).
     * Die Aufgabe wird bewusst ausgeschrieben gestellt, damit sie sich nicht
     * per Zahlen-Regex aus dem HTML lösen lässt - hier wird sie deshalb wie von
     * einem Menschen über die Wortbedeutung gelöst.
     *
     * @var array<string, int>
     */
    private const DSGVO_NUMBER_WORDS = [
        'eins' => 1, 'zwei' => 2, 'drei' => 3, 'vier' => 4, 'fünf' => 5,
        'sechs' => 6, 'sieben' => 7, 'acht' => 8, 'neun' => 9,
    ];

    /** Liest den ausgeschriebenen Aufgabentext aus dem gerenderten Formular. */
    protected function captchaQuestion(HttpResponse $page): string {
        preg_match('/<label for="captcha">.*?<strong>([^<]+)<\/strong>/su', $page->body, $matches);
        $this->assertNotEmpty(
            $matches,
            "Konnte die CAPTCHA-Aufgabe nicht aus dem Formular lesen, Body: {$page->body}"
        );

        return trim($matches[1]);
    }

    /** Löst die Aufgabe über die Bedeutung der Zahlwörter. */
    protected function solveCaptcha(HttpResponse $page): int {
        $question = $this->captchaQuestion($page);
        $parts = preg_split('/\s+/u', $question);
        $this->assertCount(3, $parts, "Unerwarteter CAPTCHA-Aufgabentext: {$question}");

        $left = self::DSGVO_NUMBER_WORDS[$parts[0]] ?? null;
        $right = self::DSGVO_NUMBER_WORDS[$parts[2]] ?? null;
        $this->assertNotNull($left, "Unbekanntes Zahlwort: {$parts[0]}");
        $this->assertNotNull($right, "Unbekanntes Zahlwort: {$parts[2]}");
        $this->assertContains($parts[1], ['plus', 'minus'], "Unbekannter Operator: {$parts[1]}");

        return $parts[1] === 'minus' ? $left - $right : $left + $right;
    }

    /**
     * Wartet die Mindest-Ausfüllzeit des CAPTCHA ab. Ein sofort nach dem
     * Rendern abgeschicktes Formular gilt als Bot (Captcha::MIN_SOLVE_SECONDS).
     */
    protected function waitForMinimumSolveTime(): void {
        sleep(\App\Security\Captcha::MIN_SOLVE_SECONDS);
    }

    /** Setzt beide IP-Zähler des DSGVO-Formulars zurück. */
    protected static function resetDsgvoRateLimit(): void {
        \App\Database::getInstance()->exec(
            "DELETE FROM login_attempts WHERE type IN ('dsgvo_attempt', 'dsgvo_request')"
        );
    }
}
