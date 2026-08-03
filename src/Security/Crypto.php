<?php
// src/Security/Crypto.php

namespace App\Security;

class Crypto {

    /**
     * Encrypts a string using AES-256-GCM (Authenticated Two-Way Encryption)
     */
    public static function encrypt(string $plaintext): string {
        $key = self::getKey();
        $cipher = 'aes-256-gcm';
        $ivLength = openssl_cipher_iv_length($cipher);
        $iv = random_bytes($ivLength);

        $ciphertext = openssl_encrypt($plaintext, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($ciphertext === false) {
            throw new \RuntimeException("Verschlüsselungsfehler.");
        }

        // Combine IV + Tag + Ciphertext into base64 payload
        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypts an AES-256-GCM encrypted base64 payload
     */
    public static function decrypt(string $payload): ?string {
        $key = self::getKey();
        $cipher = 'aes-256-gcm';
        $ivLength = openssl_cipher_iv_length($cipher);
        $tagLength = 16;

        $decoded = base64_decode($payload, true);
        if ($decoded === false || strlen($decoded) < ($ivLength + $tagLength)) {
            return null;
        }

        $iv = substr($decoded, 0, $ivLength);
        $tag = substr($decoded, $ivLength, $tagLength);
        $ciphertext = substr($decoded, $ivLength + $tagLength);

        $plaintext = openssl_decrypt($ciphertext, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tag);

        return $plaintext !== false ? $plaintext : null;
    }

    /**
     * Helper to get or generate secret key
     */
    private static function getKey(): string {
        $key = defined('APP_KEY') ? APP_KEY : 'default-secret-key-change-in-production';
        return hash('sha256', $key, true); // Ensures exact 256-bit (32 byte) key length
    }
}
