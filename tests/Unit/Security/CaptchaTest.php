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

    /** Interner Session-Schlüssel von App\Security\Captcha (Platz ohne Kontext). */
    private const SESSION_KEY = 'captcha_challenge';

    /** Interner Session-Schlüssel der Plätze je Kontext (Audit N3). */
    private const SESSION_KEY_CONTEXTS = 'captcha_challenges';

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

        $this->assertSame(Captcha::OK, Captcha::verifyBuiltin((string)$answer));
    }

    public function testSurroundingWhitespaceIsTolerated(): void {
        Captcha::issue();
        $answer = $this->currentAnswer();
        $this->ageChallenge(10);

        $this->assertSame(Captcha::OK, Captcha::verifyBuiltin("  {$answer} "));
    }

    public function testWrongAnswerIsRejected(): void {
        Captcha::issue();
        $answer = $this->currentAnswer();
        $this->ageChallenge(10);

        $this->assertSame(Captcha::WRONG, Captcha::verifyBuiltin((string)($answer + 1)));
    }

    public function testNonNumericAnswerIsRejected(): void {
        Captcha::issue();
        $this->ageChallenge(10);

        $this->assertSame(Captcha::WRONG, Captcha::verifyBuiltin('vier'));
    }

    public function testEmptyAnswerIsRejected(): void {
        Captcha::issue();
        $this->ageChallenge(10);

        $this->assertSame(Captcha::WRONG, Captcha::verifyBuiltin(null));
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

        $this->assertSame(Captcha::OK, Captcha::verifyBuiltin((string)$answer));
        $this->assertSame(Captcha::EXPIRED, Captcha::verifyBuiltin((string)$answer));
        $this->assertArrayNotHasKey(self::SESSION_KEY, $_SESSION);
    }

    public function testChallengeIsConsumedByFailedVerification(): void {
        Captcha::issue();
        $answer = $this->currentAnswer();
        $this->ageChallenge(10);

        $this->assertSame(Captcha::WRONG, Captcha::verifyBuiltin((string)($answer + 1)));
        $this->assertSame(Captcha::EXPIRED, Captcha::verifyBuiltin((string)$answer));
    }

    public function testVerificationWithoutIssuedChallengeIsExpired(): void {
        $this->assertSame(Captcha::EXPIRED, Captcha::verifyBuiltin('5'));
    }

    public function testChallengeExpiresAfterTtl(): void {
        Captcha::issue();
        $answer = $this->currentAnswer();
        $this->ageChallenge(Captcha::TTL_SECONDS + 1);

        $this->assertSame(Captcha::EXPIRED, Captcha::verifyBuiltin((string)$answer));
    }

    public function testInstantSubmissionIsRejectedAsTooFast(): void {
        Captcha::issue();
        $answer = $this->currentAnswer();

        // Kein Zurückdatieren: Absenden im selben Moment, in dem das Formular
        // ausgeliefert wurde - für einen Menschen unmöglich.
        $this->assertSame(Captcha::TOO_FAST, Captcha::verifyBuiltin((string)$answer));
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
        $this->assertSame(Captcha::WRONG, Captcha::verifyBuiltin((string)$firstAnswer));
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

    // --- Aufgaben je Formular-Kontext (Audit N3) ---------------------------

    private function contextAnswer(string $context): int {
        return (int)$_SESSION[self::SESSION_KEY_CONTEXTS][$context]['answer'];
    }

    private function ageContextChallenge(string $context, int $secondsAgo): void {
        $_SESSION[self::SESSION_KEY_CONTEXTS][$context]['issued_at'] = time() - $secondsAgo;
    }

    /**
     * Der Befund selbst: Zwei Formulare auf einer Seite (Deckanfrage und
     * Verkaufsbörse auf einer Hengstseite) werden nacheinander gerendert. Das
     * zuerst gerenderte muss danach trotzdem lösbar sein - vorher überschrieb
     * das zweite dessen Aufgabe, und es scheiterte immer.
     */
    public function testTwoContextsInOneSessionAreSolvedIndependently(): void {
        Captcha::issue('deckanfrage');
        Captcha::issue('verkaufsboerse');
        $first = $this->contextAnswer('deckanfrage');
        $second = $this->contextAnswer('verkaufsboerse');
        $this->ageContextChallenge('deckanfrage', 10);
        $this->ageContextChallenge('verkaufsboerse', 10);

        // Bewusst in umgekehrter Reihenfolge der Ausgabe geprüft.
        $this->assertSame(Captcha::OK, Captcha::verifyBuiltin((string)$first, 'deckanfrage'));
        $this->assertSame(Captcha::OK, Captcha::verifyBuiltin((string)$second, 'verkaufsboerse'));

        // Single-Use gilt je Kontext weiter.
        $this->assertSame(Captcha::EXPIRED, Captcha::verifyBuiltin((string)$first, 'deckanfrage'));
        $this->assertSame(Captcha::EXPIRED, Captcha::verifyBuiltin((string)$second, 'verkaufsboerse'));
    }

    /** Eine falsche Antwort in einem Formular entwertet nicht die Aufgabe des anderen. */
    public function testWrongAnswerInOneContextLeavesTheOtherIntact(): void {
        Captcha::issue('deckanfrage');
        Captcha::issue('verkaufsboerse');
        $this->ageContextChallenge('deckanfrage', 10);
        $this->ageContextChallenge('verkaufsboerse', 10);

        $this->assertSame(
            Captcha::WRONG,
            Captcha::verifyBuiltin((string)($this->contextAnswer('verkaufsboerse') + 1), 'verkaufsboerse')
        );
        $this->assertSame(
            Captcha::OK,
            Captcha::verifyBuiltin((string)$this->contextAnswer('deckanfrage'), 'deckanfrage')
        );
    }

    /** Die Antwort des einen Formulars gilt nicht für das andere. */
    public function testAnswerOfOneContextDoesNotSolveAnother(): void {
        Captcha::issue('deckanfrage');
        // Solange neu stellen, bis sich die Lösungen unterscheiden - begrenzt,
        // damit ein kaputter Platz den Test scheitern und nicht hängen lässt.
        for ($i = 0; $i < 200; $i++) {
            Captcha::issue('verkaufsboerse');
            if ($this->contextAnswer('verkaufsboerse') !== $this->contextAnswer('deckanfrage')) {
                break;
            }
        }
        $this->assertNotSame($this->contextAnswer('deckanfrage'), $this->contextAnswer('verkaufsboerse'));
        $this->ageContextChallenge('verkaufsboerse', 10);

        $this->assertSame(
            Captcha::WRONG,
            Captcha::verifyBuiltin((string)$this->contextAnswer('deckanfrage'), 'verkaufsboerse')
        );
    }

    /**
     * Ohne Kontext bleibt alles beim Alten: der bisherige gemeinsame Platz,
     * unabhängig von den Kontext-Plätzen.
     */
    public function testWithoutContextTheLegacySlotIsUsed(): void {
        Captcha::issue();
        $this->assertArrayHasKey(self::SESSION_KEY, $_SESSION);
        $this->assertArrayNotHasKey(self::SESSION_KEY_CONTEXTS, $_SESSION);

        Captcha::issue('dsgvo');
        $legacy = $this->currentAnswer();
        $this->ageChallenge(10);
        $this->ageContextChallenge('dsgvo', 10);

        $this->assertSame(Captcha::OK, Captcha::verifyBuiltin((string)$legacy));
        $this->assertArrayNotHasKey(self::SESSION_KEY, $_SESSION);
        $this->assertArrayHasKey('dsgvo', $_SESSION[self::SESSION_KEY_CONTEXTS], 'Der Kontext-Platz bleibt unberührt.');
    }

    /**
     * Übergang: Liegt für den Kontext keine eigene Aufgabe vor, gilt der
     * gemeinsame Platz - für Sitzungen, deren Formular vor dem Update
     * ausgeliefert wurde, und für Anbieter-Addons, die die Rückfall-Aufgabe
     * noch ohne Kontext stellen.
     */
    public function testContextWithoutOwnChallengeFallsBackToTheLegacySlot(): void {
        Captcha::issue();
        $answer = $this->currentAnswer();
        $this->ageChallenge(10);

        $this->assertSame(Captcha::OK, Captcha::verifyBuiltin((string)$answer, 'dsgvo'));
        $this->assertArrayNotHasKey(self::SESSION_KEY, $_SESSION);
    }

    public function testClearWithContextRemovesOnlyThatContext(): void {
        Captcha::issue();
        Captcha::issue('deckanfrage');
        Captcha::issue('verkaufsboerse');

        Captcha::clear('deckanfrage');

        $this->assertArrayNotHasKey('deckanfrage', $_SESSION[self::SESSION_KEY_CONTEXTS]);
        $this->assertArrayHasKey('verkaufsboerse', $_SESSION[self::SESSION_KEY_CONTEXTS]);
        $this->assertArrayHasKey(self::SESSION_KEY, $_SESSION);

        Captcha::clear();
        $this->assertArrayNotHasKey(self::SESSION_KEY, $_SESSION);
        $this->assertArrayHasKey('verkaufsboerse', $_SESSION[self::SESSION_KEY_CONTEXTS]);
    }

    /** Die Sitzung wächst nicht unbegrenzt, auch bei vielen Kontextnamen. */
    public function testNumberOfOpenContextsIsBounded(): void {
        for ($i = 0; $i < Captcha::MAX_CONTEXTS + 5; $i++) {
            Captcha::issue('formular-' . $i);
        }

        $this->assertCount(Captcha::MAX_CONTEXTS, $_SESSION[self::SESSION_KEY_CONTEXTS]);
        // Die ältesten fallen weg, die zuletzt ausgegebenen bleiben.
        $this->assertArrayNotHasKey('formular-0', $_SESSION[self::SESSION_KEY_CONTEXTS]);
        $this->assertArrayNotHasKey('formular-4', $_SESSION[self::SESSION_KEY_CONTEXTS]);
        $this->assertArrayHasKey('formular-5', $_SESSION[self::SESSION_KEY_CONTEXTS]);
        $this->assertArrayHasKey('formular-' . (Captcha::MAX_CONTEXTS + 4), $_SESSION[self::SESSION_KEY_CONTEXTS]);
    }

    /** Erneutes Ausgeben zählt als jüngste Aufgabe - ein aktives Formular fällt nicht heraus. */
    public function testReissuingAContextMovesItToTheEndOfTheQueue(): void {
        Captcha::issue('deckanfrage');
        for ($i = 0; $i < Captcha::MAX_CONTEXTS - 1; $i++) {
            Captcha::issue('formular-' . $i);
        }
        Captcha::issue('deckanfrage');
        Captcha::issue('noch-eines');

        $this->assertArrayHasKey('deckanfrage', $_SESSION[self::SESSION_KEY_CONTEXTS]);
        $this->assertArrayNotHasKey('formular-0', $_SESSION[self::SESSION_KEY_CONTEXTS]);
    }

    public function testExpiredContextChallengesArePrunedOnIssue(): void {
        Captcha::issue('alt');
        $this->ageContextChallenge('alt', Captcha::TTL_SECONDS + 1);

        Captcha::issue('neu');

        $this->assertArrayNotHasKey('alt', $_SESSION[self::SESSION_KEY_CONTEXTS]);
        $this->assertArrayHasKey('neu', $_SESSION[self::SESSION_KEY_CONTEXTS]);
    }

    /** Kontextnamen werden auf [a-z0-9_-] bereinigt - sie landen in Session und HTML-ID. */
    public function testContextNameIsSanitisedForTheSlot(): void {
        Captcha::issue('Deck"Anfrage<x>');

        $this->assertSame(['deckanfragex'], array_keys($_SESSION[self::SESSION_KEY_CONTEXTS]));
    }

    public function testRenderedFieldCarriesContextId(): void {
        $html = Captcha::renderField([], 'register');

        $this->assertStringContainsString('<label for="captcha-register">', $html);
        $this->assertStringContainsString('id="captcha-register" name="captcha"', $html);
        $this->assertArrayHasKey('register', $_SESSION[self::SESSION_KEY_CONTEXTS]);
        $this->assertArrayNotHasKey(self::SESSION_KEY, $_SESSION);
    }

    /** Das Ende-zu-Ende-Szenario über renderField() und verify(). */
    public function testTwoRenderedFormsVerifyIndependently(): void {
        Captcha::renderField([], 'dsgvo');
        Captcha::renderField([], 'register');
        $this->ageContextChallenge('dsgvo', 10);
        $this->ageContextChallenge('register', 10);

        $this->assertSame(
            Captcha::OK,
            Captcha::verify([], 'dsgvo', ['captcha' => (string)$this->contextAnswer('dsgvo')])
        );
        $this->assertSame(
            Captcha::OK,
            Captcha::verify([], 'register', ['captcha' => (string)$this->contextAnswer('register')])
        );
    }
}
