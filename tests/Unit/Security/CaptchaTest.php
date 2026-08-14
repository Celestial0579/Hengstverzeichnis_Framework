<?php
// tests/Unit/Security/CaptchaTest.php

namespace Tests\Unit\Security;

use App\I18n\Translator;
use App\Security\Captcha;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests des selbst gehosteten Spam-Schutzes für das DSGVO-Portal
 * (siehe App\Security\Captcha und PublicController::dsgvoSubmit()).
 *
 * Die Tests greifen bewusst direkt auf den Session-Eintrag zu, um den
 * Ausgabezeitpunkt zurückzudatieren - ohne das würde jede Prüfung sofort
 * nach issue() am Mindest-Ausfüllzeit-Schutz (TOO_FAST) scheitern.
 */
class CaptchaTest extends TestCase {

    /** Interner Session-Schlüssel von App\Security\Captcha. */
    private const SESSION_KEY = 'captcha_challenge';

    /** @var array<string, int> Zahlwörter der Fallback-Locale 'de' */
    private const NUMBER_WORDS = [
        'eins' => 1, 'zwei' => 2, 'drei' => 3, 'vier' => 4, 'fünf' => 5,
        'sechs' => 6, 'sieben' => 7, 'acht' => 8, 'neun' => 9,
    ];

    protected function setUp(): void {
        $_SESSION = [];
        Translator::init('de');
    }

    /**
     * Datiert die laufende Aufgabe zurück, simuliert also ein Formular, das
     * vor $secondsAgo Sekunden ausgeliefert wurde.
     */
    private function ageChallenge(int $secondsAgo): void {
        $_SESSION[self::SESSION_KEY]['issued_at'] = time() - $secondsAgo;
    }

    private function currentAnswer(): int {
        return (int)$_SESSION[self::SESSION_KEY]['answer'];
    }

    public function testQuestionTextMatchesStoredAnswerAndIsNeverNegative(): void {
        // Mehrere Durchläufe, damit beide Operatoren und das Vertauschen der
        // Operanden bei Subtraktion zuverlässig abgedeckt sind.
        for ($i = 0; $i < 40; $i++) {
            $question = Captcha::issue();

            $parts = explode(' ', $question);
            $this->assertCount(3, $parts, "Unerwarteter Aufgabentext: {$question}");

            $left = self::NUMBER_WORDS[$parts[0]] ?? null;
            $right = self::NUMBER_WORDS[$parts[2]] ?? null;
            $this->assertNotNull($left, "Unbekanntes Zahlwort: {$parts[0]}");
            $this->assertNotNull($right, "Unbekanntes Zahlwort: {$parts[2]}");
            $this->assertContains($parts[1], ['plus', 'minus'], "Unbekannter Operator: {$parts[1]}");

            $expected = $parts[1] === 'minus' ? $left - $right : $left + $right;
            $this->assertSame($expected, $this->currentAnswer());
            $this->assertGreaterThanOrEqual(0, $this->currentAnswer(), 'Aufgabe darf nie negativ sein');
        }
    }

    public function testQuestionIsSpelledOutSoItCannotBeSolvedByANumberRegex(): void {
        $question = Captcha::issue();

        $this->assertDoesNotMatchRegularExpression('/\d/', $question);
    }

    public function testCorrectAnswerIsAccepted(): void {
        Captcha::issue();
        $answer = $this->currentAnswer();
        $this->ageChallenge(10);

        $this->assertSame(Captcha::OK, Captcha::verify((string)$answer));
    }

    public function testSurroundingWhitespaceIsTolerated(): void {
        Captcha::issue();
        $answer = $this->currentAnswer();
        $this->ageChallenge(10);

        $this->assertSame(Captcha::OK, Captcha::verify("  {$answer} "));
    }

    public function testWrongAnswerIsRejected(): void {
        Captcha::issue();
        $answer = $this->currentAnswer();
        $this->ageChallenge(10);

        $this->assertSame(Captcha::WRONG, Captcha::verify((string)($answer + 1)));
    }

    public function testNonNumericAnswerIsRejected(): void {
        Captcha::issue();
        $this->ageChallenge(10);

        $this->assertSame(Captcha::WRONG, Captcha::verify('vier'));
    }

    public function testEmptyAnswerIsRejected(): void {
        Captcha::issue();
        $this->ageChallenge(10);

        $this->assertSame(Captcha::WRONG, Captcha::verify(null));
    }

    /**
     * Kernschutz gegen Massen-Submits: Eine einmal (richtig) gelöste Aufgabe
     * ist danach verbraucht - jeder weitere POST braucht eine neue Aufgabe,
     * die nur über ein erneutes GET des Formulars zu bekommen ist.
     */
    public function testChallengeIsConsumedBySuccessfulVerification(): void {
        Captcha::issue();
        $answer = $this->currentAnswer();
        $this->ageChallenge(10);

        $this->assertSame(Captcha::OK, Captcha::verify((string)$answer));
        $this->assertSame(Captcha::EXPIRED, Captcha::verify((string)$answer));
        $this->assertArrayNotHasKey(self::SESSION_KEY, $_SESSION);
    }

    public function testChallengeIsConsumedByFailedVerification(): void {
        Captcha::issue();
        $answer = $this->currentAnswer();
        $this->ageChallenge(10);

        $this->assertSame(Captcha::WRONG, Captcha::verify((string)($answer + 1)));
        $this->assertSame(Captcha::EXPIRED, Captcha::verify((string)$answer));
    }

    public function testVerificationWithoutIssuedChallengeIsExpired(): void {
        $this->assertSame(Captcha::EXPIRED, Captcha::verify('5'));
    }

    public function testChallengeExpiresAfterTtl(): void {
        Captcha::issue();
        $answer = $this->currentAnswer();
        $this->ageChallenge(Captcha::TTL_SECONDS + 1);

        $this->assertSame(Captcha::EXPIRED, Captcha::verify((string)$answer));
    }

    public function testInstantSubmissionIsRejectedAsTooFast(): void {
        Captcha::issue();
        $answer = $this->currentAnswer();

        // Kein Zurückdatieren: Absenden im selben Moment, in dem das Formular
        // ausgeliefert wurde - für einen Menschen unmöglich.
        $this->assertSame(Captcha::TOO_FAST, Captcha::verify((string)$answer));
    }

    public function testIssuingReplacesAPreviousChallenge(): void {
        Captcha::issue();
        $firstAnswer = $this->currentAnswer();

        // Wiederholtes Rendern des Formulars (z. B. nach einem Validierungs-
        // fehler) entwertet die alte Aufgabe.
        do {
            Captcha::issue();
        } while ($this->currentAnswer() === $firstAnswer);

        $this->ageChallenge(10);
        $this->assertSame(Captcha::WRONG, Captcha::verify((string)$firstAnswer));
    }

    public function testClearRemovesTheChallenge(): void {
        Captcha::issue();
        Captcha::clear();

        $this->assertArrayNotHasKey(self::SESSION_KEY, $_SESSION);
    }

    public function testHoneypotDetectsFilledField(): void {
        $this->assertTrue(Captcha::honeypotTripped([Captcha::HONEYPOT_FIELD => 'https://spam.example']));
    }

    public function testHoneypotIgnoresEmptyAndMissingField(): void {
        $this->assertFalse(Captcha::honeypotTripped([]));
        $this->assertFalse(Captcha::honeypotTripped([Captcha::HONEYPOT_FIELD => '']));
        $this->assertFalse(Captcha::honeypotTripped([Captcha::HONEYPOT_FIELD => "  \n "]));
    }

    public function testHoneypotTreatsNonStringInputAsTripped(): void {
        // Manipulierte Requests (website[]=x) dürfen den Honeypot nicht über
        // einen Typfehler aushebeln.
        $this->assertTrue(Captcha::honeypotTripped([Captcha::HONEYPOT_FIELD => ['x']]));
    }
}
