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
     * Bewusst FAIL-CLOSED: Ohne konfigurierten APP_KEY wird eine Exception geworfen statt
     * still auf einen im (öffentlichen Open-Source-)Quellcode bekannten Fallback-Schlüssel
     * auszuweichen - ein Fallback-Schlüssel wäre für jeden mit Lesezugriff auf die Datenbank
     * trivial nutzbar, um damit verschlüsselte TOTP-Secrets (2FA-Bypass!) und das SMTP-Passwort
     * zu entschlüsseln. Über die unterstützten Einrichtungswege (Setup-Wizard, Docker via
     * .env.example/docker-start.sh) ist APP_KEY immer gesetzt; dieser Pfad greift also nur bei
     * einer fehlerhaften manuellen Konfiguration - dann soll die Verschlüsselung laut fehlschlagen,
     * nicht still unsicher weiterlaufen.
     *
     * @return string 32-Byte Rohdaten-Schlüssel
     * @throws \RuntimeException Falls APP_KEY nicht konfiguriert ist
     */
    private static function getKey(): string {
        if (!defined('APP_KEY') || empty(APP_KEY)) {
            throw new \RuntimeException(
                "APP_KEY ist nicht konfiguriert. Verschlüsselung/Entschlüsselung kann nicht sicher " .
                "durchgeführt werden. Bitte APP_KEY als Umgebungsvariable setzen oder über den " .
                "Setup-Wizard einrichten (siehe README, Abschnitt 'Konfiguration')."
            );
        }
        return hash('sha256', APP_KEY, true);
    }
}
