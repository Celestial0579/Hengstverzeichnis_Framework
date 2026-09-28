<?php
// tests/Unit/Controllers/FaktorPfadTest.php

namespace Tests\Unit\Controllers;

use App\Controllers\AuthController;
use App\Security\SecondFactors;
use PHPUnit\Framework\TestCase;

/**
 * Die Faktorweiche AuthController::faktorPfad() (Audit N42): Passkey vor TOTP
 * vor Mailcode - aber auf einer Verbindung, auf der Passkeys nicht
 * funktionieren, direkt zum nächsten Verfahren, sofern es eines gibt.
 *
 * Privat, deshalb über Reflection; Passkeys::verfuegbar() liest nur $_SERVER.
 */
class FaktorPfadTest extends TestCase {

    /** @var array<string, mixed> */
    private array $server = [];

    protected function setUp(): void {
        $this->server = $_SERVER;
    }

    protected function tearDown(): void {
        $_SERVER = $this->server;
    }

    /** @param array<int, string> $faktoren */
    private static function pfad(array $faktoren): string {
        $methode = new \ReflectionMethod(AuthController::class, 'faktorPfad');
        return (string)$methode->invoke(null, $faktoren);
    }

    private static function unsichereVerbindung(): void {
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
        $_SERVER['HTTP_HOST'] = 'verband.example';
    }

    private static function sichereVerbindung(): void {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'verband.example';
    }

    public function testOhnePasskeyVerfuegbarkeitGehtEsZumNaechstenFaktor(): void {
        self::unsichereVerbindung();

        $this->assertSame(AuthController::PFAD_TOTP, self::pfad([SecondFactors::PASSKEY, SecondFactors::TOTP]));
        $this->assertSame(AuthController::PFAD_MAILCODE, self::pfad([SecondFactors::PASSKEY, SecondFactors::EMAIL]));
    }

    public function testPasskeyAlsEinzigerFaktorBleibtAufSeinerSeite(): void {
        self::unsichereVerbindung();

        $this->assertSame(AuthController::PFAD_PASSKEY, self::pfad([SecondFactors::PASSKEY]));
    }

    public function testMitHttpsKommtDerPasskeyZuerst(): void {
        self::sichereVerbindung();

        $this->assertSame(AuthController::PFAD_PASSKEY, self::pfad([SecondFactors::PASSKEY, SecondFactors::TOTP]));
        $this->assertSame(AuthController::PFAD_PASSKEY, self::pfad([SecondFactors::PASSKEY, SecondFactors::EMAIL]));
        $this->assertSame(AuthController::PFAD_TOTP, self::pfad([SecondFactors::TOTP, SecondFactors::EMAIL]));
        $this->assertSame(AuthController::PFAD_MAILCODE, self::pfad([SecondFactors::EMAIL]));
    }
}
