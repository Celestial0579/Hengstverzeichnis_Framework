<?php
// src/Security/Crypto.php

namespace App\Security;

/**
 * Class Crypto
 * 
 * Symmetrisches Kryptographie-Hilfswerkzeug.
 * Nutzt AES-256-GCM (Authenticated Encryption with Associated Data) für zweiwegebasiertes
 * Ver- und Entschlüsseln sensibler Daten (z. B. SMTP-Passwörter in der Datenbank)
 * mit zufälligem Initialisierungsvektor (IV) und Authentifizierungs-Tag.
 */
class Crypto {

    /**
     * Verschlüsselt einen Klartext-String mit AES-256-GCM.
     *
     * @param string $plaintext Der zu verschlüsselnde Klartext
     * @return string Base64-kodierter String bestehend aus IV + Auth-Tag + Chiffre
     * @throws \RuntimeException Falls bei der Verschlüsselung ein Fehler auftritt
     */
    public static function encrypt(string $plaintext): string {
        $key = self::getKey();
        $cipher = 'aes-256-gcm';
        $ivLength = openssl_cipher_iv_length($cipher);
        $iv = random_bytes($ivLength);

        $ciphertext = openssl_encrypt($plaintext, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($ciphertext === false) {
            throw new \RuntimeException("Verschlüsselungsfehler bei AES-256-GCM.");
        }

        // IV + Tag + Chiffre zusammenfügen und als Base64 ausgeben
        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Entschlüsselt einen AES-256-GCM verschlüsselten Base64-Payload.
     *
     * @param string $payload Der Base64-kodierte Chiffre-Text
     * @return string|null Der ursprüngliche Klartext oder NULL bei Fehler / ungültigem Auth-Tag
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
     * Generiert aus der Anwendungs-Geheimnis-Konstante (APP_KEY) einen exakt 256-Bit (32 Byte)
     * langen kryptographischen Schlüssel mittels SHA-256.
     *
     * @return string 32-Byte Rohdaten-Schlüssel
     */
    private static function getKey(): string {
        $key = (defined('APP_KEY') && !empty(APP_KEY)) ? APP_KEY : 'unconfigured-secret-app-key-fallback';
        return hash('sha256', $key, true);
    }
}
