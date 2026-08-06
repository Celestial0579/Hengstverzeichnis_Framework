<?php
// tests/Unit/Security/TotpTest.php

namespace Tests\Unit\Security;

use App\Security\Totp;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase {

    public function testGenerateSecretHasExpectedLengthAndAlphabet(): void {
        $secret = Totp::generateSecret();

        $this->assertSame(16, strlen($secret));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }

    public function testGenerateSecretRespectsCustomLength(): void {
        $secret = Totp::generateSecret(32);

        $this->assertSame(32, strlen($secret));
    }

    public function testGetCodeIsDeterministicForSameTimeSlice(): void {
        $secret = Totp::generateSecret();

        $codeA = Totp::getCode($secret, 100000);
        $codeB = Totp::getCode($secret, 100000);

        $this->assertSame($codeA, $codeB);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $codeA);
    }

    public function testGetCodeDiffersForDifferentTimeSlices(): void {
        $secret = Totp::generateSecret();

        $codeA = Totp::getCode($secret, 100000);
        $codeB = Totp::getCode($secret, 100001);

        $this->assertNotSame($codeA, $codeB);
    }

    public function testVerifyCodeAcceptsCurrentCode(): void {
        $secret = Totp::generateSecret();
        $currentSlice = (int) floor(time() / 30);
        $code = Totp::getCode($secret, $currentSlice);

        $this->assertTrue(Totp::verifyCode($secret, $code));
    }

    public function testVerifyCodeAcceptsCodeWithinDiscrepancyWindow(): void {
        $secret = Totp::generateSecret();
        $currentSlice = (int) floor(time() / 30);
        $previousWindowCode = Totp::getCode($secret, $currentSlice - 1);

        $this->assertTrue(Totp::verifyCode($secret, $previousWindowCode, 1));
    }

    public function testVerifyCodeRejectsCodeOutsideDiscrepancyWindow(): void {
        $secret = Totp::generateSecret();
        $currentSlice = (int) floor(time() / 30);
        $farOffCode = Totp::getCode($secret, $currentSlice - 5);

        $this->assertFalse(Totp::verifyCode($secret, $farOffCode, 1));
    }

    public function testVerifyCodeRejectsGarbageCode(): void {
        $secret = Totp::generateSecret();

        $this->assertFalse(Totp::verifyCode($secret, '000000'));
    }

    public function testVerifyCodeTrimsWhitespace(): void {
        $secret = Totp::generateSecret();
        $currentSlice = (int) floor(time() / 30);
        $code = Totp::getCode($secret, $currentSlice);

        $this->assertTrue(Totp::verifyCode($secret, " {$code}\n"));
    }

    public function testGenerateBackupCodesReturnsExpectedCountAndFormat(): void {
        $codes = Totp::generateBackupCodes();

        $this->assertCount(10, $codes);
        foreach ($codes as $code) {
            // 64 Bit Entropie je Code: 2x 4 Byte als 8+8 Hex-Zeichen (siehe Totp::generateBackupCodes()).
            $this->assertMatchesRegularExpression('/^[0-9A-F]{8}-[0-9A-F]{8}$/', $code);
        }
        $this->assertCount(10, array_unique($codes), 'Backup-Codes sollten (praktisch sicher) eindeutig sein');
    }

    public function testGenerateBackupCodesRespectsCustomCount(): void {
        $codes = Totp::generateBackupCodes(3);

        $this->assertCount(3, $codes);
    }

    public function testGetOtpAuthUrlContainsSecretAndUrlEncodedLabelIssuer(): void {
        $url = Totp::getOtpAuthUrl('admin@example.com', 'Hengstverzeichnis', 'JBSWY3DPEHPK3PXP');

        $this->assertStringStartsWith('otpauth://totp/', $url);
        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $url);
        $this->assertStringContainsString('issuer=Hengstverzeichnis', $url);
        $this->assertStringContainsString(rawurlencode('Hengstverzeichnis:admin@example.com'), $url);
    }
}
