<?php
// tests/Integration/RateLimiterConcurrencyTest.php

namespace Tests\Integration;

use App\Security\RateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * Parallele Anfragen kommen nicht über die Grenze (Audit M20).
 *
 * Bisher zählte der Kern erst, prüfte dann Passwort oder Code und buchte den
 * Fehlversuch zuletzt. Gleichzeitig abgeschickte Anfragen sahen dazwischen
 * alle denselben alten Stand - so liess sich ein Vielfaches der erlaubten
 * Versuche erzwingen. reserveAttempt() bucht zuerst und zählt danach.
 *
 * Zugesichert wird nur die OBERGRENZE. "Mindestens einer kommt durch" wäre
 * falsch: Starten alle exakt gleichzeitig, sehen alle die Zeilen der anderen
 * und lehnen sich selbst ab - das ist korrektes fail-closed-Verhalten.
 */
class RateLimiterConcurrencyTest extends TestCase {

    use KontoTestbestand;
    use ParalleleArbeiter;

    private const TYP = 'test_rl_par';
    private const ERLAUBT = 5;

    protected function setUp(): void {
        self::$db->prepare("DELETE FROM login_attempts WHERE type = ?")->execute([self::TYP]);
    }

    protected function tearDown(): void {
        self::$db->prepare("DELETE FROM login_attempts WHERE type = ?")->execute([self::TYP]);
    }

    private function zeilen(string $bezeichner): int {
        $stmt = self::$db->prepare("SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND type = ?");
        $stmt->execute([$bezeichner, self::TYP]);
        return (int)$stmt->fetchColumn();
    }

    public function testParalleleReservierungenBleibenUnterDerGrenze(): void {
        $bezeichner = 'parallel-' . uniqid();

        $ausgaben = $this->parallelAusfuehren(array_fill(
            0,
            20,
            ['reserve', $bezeichner, self::TYP, (string)self::ERLAUBT, '300']
        ));

        $erlaubt = self::anzahl($ausgaben, 'ERLAUBT');
        $this->assertSame(20, $erlaubt + self::anzahl($ausgaben, 'GESPERRT'), implode('', $ausgaben));
        $this->assertLessThanOrEqual(
            self::ERLAUBT,
            $erlaubt,
            'Mehr parallele Versuche angenommen als erlaubt - die Buchung ist nicht atomar.'
        );
        $this->assertSame(
            $erlaubt,
            $this->zeilen($bezeichner),
            'Abgelehnte Arbeiter dürfen keine Zeile hinterlassen.'
        );

        // Bis zur Grenze gelingen weitere Reservierungen der Reihe nach.
        for ($i = $erlaubt; $i < self::ERLAUBT; $i++) {
            $this->assertNotNull(RateLimiter::reserveAttempt($bezeichner, self::TYP, self::ERLAUBT));
        }
        $this->assertNull(RateLimiter::reserveAttempt($bezeichner, self::TYP, self::ERLAUBT));
    }

    public function testDerSechsteVersuchWirdOhneNeueZeileAbgelehnt(): void {
        $bezeichner = 'seriell-' . uniqid();
        $ids = [];
        for ($i = 0; $i < self::ERLAUBT; $i++) {
            $id = RateLimiter::reserveAttempt($bezeichner, self::TYP, self::ERLAUBT);
            $this->assertIsInt($id);
            $this->assertGreaterThan(0, $id);
            $ids[] = $id;
        }

        $this->assertNull(RateLimiter::reserveAttempt($bezeichner, self::TYP, self::ERLAUBT));
        $this->assertSame(self::ERLAUBT, $this->zeilen($bezeichner));
        $this->assertTrue(RateLimiter::tooManyAttempts($bezeichner, self::TYP, self::ERLAUBT));

        // Freigabe löscht genau die eigene Zeile, 0 tut nichts.
        RateLimiter::releaseAttempt($ids[0]);
        RateLimiter::releaseAttempt(0);
        RateLimiter::releaseAttempt(null);
        $this->assertSame(self::ERLAUBT - 1, $this->zeilen($bezeichner));
        $this->assertNotNull(RateLimiter::reserveAttempt($bezeichner, self::TYP, self::ERLAUBT));
    }

    public function testInEinerOffenenTransaktionWirdLautAbgelehnt(): void {
        self::$db->beginTransaction();
        try {
            $this->expectException(\LogicException::class);
            RateLimiter::reserveAttempt('tx-' . uniqid(), self::TYP);
        } finally {
            self::$db->rollBack();
        }
    }
}
