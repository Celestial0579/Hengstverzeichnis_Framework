<?php
// tests/Unit/Service/MailerProtokollTest.php

namespace Tests\Unit\Service;

use App\Service\AuditLogger;
use App\Service\Mailer;
use PHPUnit\Framework\TestCase;

/**
 * Das Mail-Protokoll im Audit-Log (Audit N17): nur Mail-Typ und eine
 * Empfängerreferenz, nie Adresse oder Betreff - im Betreff stehen die
 * Anmeldecodes des E-Mail-Faktors. Adressen in Serverantworten werden
 * maskiert, weil Server bei Ablehnungen die Adresse zitieren.
 */
class MailerProtokollTest extends TestCase {

    private static function zeile(string $typ, string $empfaenger, array $extra = []): string {
        $m = new \ReflectionMethod(Mailer::class, 'protokollZeile');
        return (string)$m->invoke(null, $typ, $empfaenger, $extra);
    }

    public function testReferenzIstDeterministischUndNormalisiert(): void {
        $a = Mailer::externeEmpfaengerReferenz('max@verein.de');
        $this->assertMatchesRegularExpression('/^extern:[0-9a-f]{12}$/', $a);
        $this->assertSame($a, Mailer::externeEmpfaengerReferenz('  MAX@Verein.DE '));
        $this->assertNotSame($a, Mailer::externeEmpfaengerReferenz('moritz@verein.de'));
        $this->assertStringNotContainsString('max', $a);
        $this->assertStringNotContainsString('verein', $a);
    }

    /** Konstante und reine Umgebungsvariable ergeben dieselbe Referenz (Addons-Tests, php -S). */
    public function testKonstanteUndUmgebungErgebenDieselbeReferenz(): void {
        $this->assertSame(
            Mailer::externeEmpfaengerReferenz('max@verein.de'),
            Mailer::externeEmpfaengerReferenz('max@verein.de', (string)APP_KEY)
        );
        $this->assertNotSame(
            Mailer::externeEmpfaengerReferenz('max@verein.de'),
            Mailer::externeEmpfaengerReferenz('max@verein.de', 'ein-anderer-schluessel')
        );
    }

    public function testOhneSchluesselNurExtern(): void {
        $this->assertSame('extern', Mailer::externeEmpfaengerReferenz('max@verein.de', ''));
    }

    public function testProtokollzeileEnthaeltWederCodeNochAdresse(): void {
        $zeile = self::zeile('anmeldecode', Mailer::externeEmpfaengerReferenz('max@verein.de'));
        $this->assertStringStartsWith('Typ: anmeldecode, Empfänger: extern:', $zeile);
        $this->assertStringNotContainsString('@', $zeile);
        $this->assertDoesNotMatchRegularExpression('/\d{6}/', $zeile);
    }

    public function testServerantwortWirdMaskiertUndGekuerzt(): void {
        $zeile = self::zeile('allgemein', 'Benutzer #5', [
            'Host' => 'smtp.example.test:587',
            'Antwort' => "550 5.1.1 <max@verein.de>: Recipient address rejected\r\n" . str_repeat('x', 400),
        ]);
        $this->assertStringContainsString('Antwort: 550 5.1.1 <' . AuditLogger::ADRESSE . '>', $zeile);
        $this->assertStringNotContainsString('@', $zeile);
        $this->assertStringNotContainsString("\n", $zeile);
        $this->assertLessThan(320, mb_strlen($zeile));
    }

    public function testAdressenMaskierenErfasstUebliche(): void {
        $this->assertSame(
            '550 5.1.1 <[Adresse]>: Recipient address rejected',
            AuditLogger::adressenMaskieren('550 5.1.1 <max@verein.de>: Recipient address rejected')
        );
        $this->assertSame(
            'an [Adresse], "[Adresse]" ([Adresse])',
            AuditLogger::adressenMaskieren('an a.b+c@x.de, "d@y.org" (ü@bücher.de)')
        );
        $this->assertSame('250 OK', AuditLogger::adressenMaskieren('250 OK'));
    }

    /**
     * Quelltext-Wächter: Adresse und Betreff stehen nirgends im Protokoll,
     * und kein Trigger umgeht sendTyped() (sonst fehlte der Typ).
     */
    public function testQuelltextProtokolliertWederBetreffNochAdresse(): void {
        $code = (string)file_get_contents(__DIR__ . '/../../../src/Service/Mailer.php');
        $this->assertStringNotContainsString('Betreff: {$subject}', $code);
        $this->assertStringNotContainsString('Empfänger: {$toEmail}', $code);

        preg_match_all('/function (\w+)\(.*?\n    \}\n/s', $code, $treffer, PREG_SET_ORDER);
        $this->assertNotEmpty($treffer);
        foreach ($treffer as [$koerper, $name]) {
            if ($name === 'sendTyped') {
                continue;
            }
            $this->assertStringNotContainsString('$this->send(', $koerper, "{$name}() muss über sendTyped() versenden.");
        }
    }
}
