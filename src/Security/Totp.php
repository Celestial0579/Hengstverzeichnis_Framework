<?php
// src/Security/Totp.php

namespace App\Security;

class Totp {

    private static string $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generates a secret key in Base32 format (16 chars)
     */
    public static function generateSecret(int $length = 16): string {
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= self::$base32Chars[random_int(0, 31)];
        }
        return $secret;
    }

    /**
     * Calculates current TOTP code for a given secret
     */
    public static function getCode(string $secret, ?int $timeSlice = null): string {
        if ($timeSlice === null) {
            $timeSlice = floor(time() / 30);
        }

        $secretKey = self::base32Decode($secret);

        // Pack time into binary string (big endian)
        $time = pack('N*', 0) . pack('N*', $timeSlice);
        // Calculate HMAC-SHA1
        $hmac = hash_hmac('sha1', $time, $secretKey, true);

        // Dynamic truncation
        $offset = ord(substr($hmac, -1)) & 0x0F;
        $hashpart = substr($hmac, $offset, 4);

        $value = unpack('N', $hashpart);
        $value = $value[1];
        $value = $value & 0x7FFFFFFF;

        $modulo = pow(10, 6);
        return str_pad((string)($value % $modulo), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Verifies code allowing clock drift of +/- 1 window (30 seconds)
     */
    public static function verifyCode(string $secret, string $code, int $discrepancy = 1): bool {
        $currentTimeSlice = floor(time() / 30);

        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $calculatedCode = self::getCode($secret, $currentTimeSlice + $i);
            if (hash_equals($calculatedCode, trim($code))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generates otpauth:// URL for QR code scanners
     */
    public static function getOtpAuthUrl(string $label, string $issuer, string $secret): string {
        $encodedLabel = rawurlencode($issuer . ':' . $label);
        $encodedIssuer = rawurlencode($issuer);
        return "otpauth://totp/{$encodedLabel}?secret={$secret}&issuer={$encodedIssuer}";
    }

    /**
     * Generates 10 single-use backup recovery codes
     */
    public static function generateBackupCodes(int $count = 10): array {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $code = strtoupper(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)));
            $codes[] = $code;
        }
        return $codes;
    }

    /**
     * Decodes Base32 string to binary
     */
    private static function base32Decode(string $base32): string {
        $base32 = strtoupper($base32);
        $buffer = 0;
        $bufferSize = 0;
        $binary = '';

        for ($i = 0; $i < strlen($base32); $i++) {
            $char = $base32[$i];
            $pos = strpos(self::$base32Chars, $char);
            if ($pos === false) continue;

            $buffer = ($buffer << 5) | $pos;
            $bufferSize += 5;

            if ($bufferSize >= 8) {
                $bufferSize -= 8;
                $binary .= chr(($buffer >> $bufferSize) & 0xFF);
            }
        }

        return $binary;
    }
}
