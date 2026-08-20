<?php
// tests/Unit/Security/CaptchaContextTest.php

namespace Tests\Unit\Security;

use App\Security\CaptchaContext;
use PHPUnit\Framework\TestCase;

/**
 * Prüft den Katalog der Formular-Kontexte für den Spam-Schutz (#351).
 */
class CaptchaContextTest extends TestCase {

    protected function setUp(): void {
        CaptchaContext::resetForTests();
    }

    protected function tearDown(): void {
        CaptchaContext::resetForTests();
    }

    public function testKernKontexteSindDa(): void {
        $this->assertTrue(CaptchaContext::isValid('dsgvo'));
        $this->assertTrue(CaptchaContext::isValid('register'));
    }

    public function testAddonKannEigenesFormularAnmelden(): void {
        CaptchaContext::register('kontaktanfrage', 'Kontaktanfrage an einen Kontakt');

        $this->assertTrue(CaptchaContext::isValid('kontaktanfrage'));
        $this->assertSame('Kontaktanfrage an einen Kontakt', CaptchaContext::all()['kontaktanfrage']);
    }

    /**
     * Sicherheits-Leitplanke, dieselbe wie bei den Berechtigungen: Ein Addon
     * darf die Beschriftung eines fremden Formulars nicht verändern - sonst
     * könnte es den Betreiber dazu bringen, den Schutz am falschen Formular
     * abzuschalten.
     */
    public function testWerZuerstRegistriertGewinnt(): void {
        CaptchaContext::register('dsgvo', 'Harmloses Testformular');
        $this->assertSame(
            'DSGVO-Portal (Auskunft und Löschung)',
            CaptchaContext::all()['dsgvo'],
            'Ein Kern-Kontext darf nicht überschreibbar sein'
        );

        CaptchaContext::register('eigenes', 'Erste Anmeldung');
        CaptchaContext::register('eigenes', 'Zweite Anmeldung');
        $this->assertSame('Erste Anmeldung', CaptchaContext::all()['eigenes']);
    }

    /**
     * Ein Kontextname landet in Einstellungsschlüsseln und in der Oberfläche.
     *
     * @param string $key
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unbrauchbareNamen')]
    public function testUnbrauchbareNamenWerdenAbgelehnt(string $key): void {
        CaptchaContext::register($key, 'Egal');
        $this->assertFalse(CaptchaContext::isValid($key));
    }

    public static function unbrauchbareNamen(): array {
        return [
            'leer' => [''],
            'nur Leerzeichen' => ['   '],
            'zu kurz' => ['a'],
            'Grossbuchstaben' => ['MeinFormular'],
            'Leerzeichen darin' => ['mein formular'],
            'Sonderzeichen' => ['mein/formular'],
            'beginnt mit Bindestrich' => ['-formular'],
            'zu lang' => [str_repeat('a', 65)],
        ];
    }

    public function testEinstellungsschluesselIstVorhersagbar(): void {
        $this->assertSame('captcha_provider_dsgvo', CaptchaContext::settingKey('dsgvo'));
    }

    public function testUnbekannterKontextIstUngueltig(): void {
        $this->assertFalse(CaptchaContext::isValid('gibt-es-nicht'));
    }
}
