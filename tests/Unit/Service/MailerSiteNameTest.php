<?php
// tests/Unit/Service/MailerSiteNameTest.php

namespace Tests\Unit\Service;

use App\Service\Mailer;
use PHPUnit\Framework\TestCase;

/**
 * Verbandsname in Betreff und Text (#452): settings speichert ein geleertes
 * Feld als Leerstring. Der Rückfall auf 'Hengstverzeichnis' muss dann trotzdem
 * greifen - dieselbe Falle wie beim Absender (#132).
 */
class MailerSiteNameTest extends TestCase {

    /** Mailer ohne Konstruktor (der liest die Datenbank), Konfiguration direkt gesetzt. */
    private static function siteName(array $config): string {
        $class = new \ReflectionClass(Mailer::class);
        $mailer = $class->newInstanceWithoutConstructor();
        $class->getProperty('config')->setValue($mailer, $config);
        return $class->getMethod('siteName')->invoke($mailer);
    }

    public function testLeererVerbandsnameFaelltAufDenStandardZurueck(): void {
        $this->assertSame('Hengstverzeichnis', self::siteName(['site_name' => '']));
    }

    public function testFehlenderVerbandsnameFaelltAufDenStandardZurueck(): void {
        $this->assertSame('Hengstverzeichnis', self::siteName([]));
    }

    public function testGesetzterVerbandsnameGilt(): void {
        $this->assertSame('Zuchtverband Nord', self::siteName(['site_name' => 'Zuchtverband Nord']));
    }

    /** Alle Templates holen den Namen über siteName(), keines liest ihn mit ?? selbst. */
    public function testKeinTemplateLiestSiteNameAmRueckfallVorbei(): void {
        $code = (string)file_get_contents(__DIR__ . '/../../../src/Service/Mailer.php');
        $this->assertDoesNotMatchRegularExpression("/\\['site_name'\\]\\s*\\?\\?\\s*'Hengstverzeichnis'/", $code);
    }
}
