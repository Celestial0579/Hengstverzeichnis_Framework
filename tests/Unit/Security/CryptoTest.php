<?php
// tests/Unit/Security/CryptoTest.php

namespace Tests\Unit\Security;

use App\Security\Crypto;
use PHPUnit\Framework\TestCase;

class CryptoTest extends TestCase {

    public function testEncryptDecryptRoundTrip(): void {
        $plaintext = 'geheimes SMTP-Passwort mit Umlauten äöü';

        $ciphertext = Crypto::encrypt($plaintext);
        $decrypted = Crypto::decrypt($ciphertext);

        $this->assertNotSame($plaintext, $ciphertext);
        $this->assertSame($plaintext, $decrypted);
    }

    public function testEncryptProducesDifferentCiphertextForSamePlaintext(): void {
        $plaintext = 'immer gleicher Klartext';

        $first = Crypto::encrypt($plaintext);
        $second = Crypto::encrypt($plaintext);

        $this->assertNotSame($first, $second, 'Zufälliger IV muss pro Aufruf unterschiedliches Chiffrat erzeugen');
        $this->assertSame($plaintext, Crypto::decrypt($first));
        $this->assertSame($plaintext, Crypto::decrypt($second));
    }

    public function testEncryptHandlesEmptyString(): void {
        $ciphertext = Crypto::encrypt('');

        $this->assertSame('', Crypto::decrypt($ciphertext));
    }

    public function testDecryptReturnsNullForInvalidBase64(): void {
        $this->assertNull(Crypto::decrypt('not-valid-base64!!!'));
    }

    public function testDecryptReturnsNullForTooShortPayload(): void {
        $this->assertNull(Crypto::decrypt(base64_encode('zu kurz')));
    }

    public function testDecryptReturnsNullForTamperedCiphertext(): void {
        $ciphertext = Crypto::encrypt('geheime Nachricht');
        $raw = base64_decode($ciphertext, true);

        // Letztes Byte der Chiffre kippen -> Auth-Tag-Prüfung muss fehlschlagen
        $lastByte = ~$raw[strlen($raw) - 1];
        $tampered = substr($raw, 0, -1) . $lastByte;

        $this->assertNull(Crypto::decrypt(base64_encode($tampered)));
    }

    public function testDecryptReturnsNullForTamperedAuthTag(): void {
        $ciphertext = Crypto::encrypt('geheime Nachricht');
        $raw = base64_decode($ciphertext, true);
        $ivLength = openssl_cipher_iv_length('aes-256-gcm');

        // Ein Byte innerhalb des Auth-Tags kippen (liegt direkt nach dem IV)
        $tagByteOffset = $ivLength;
        $flippedByte = ~$raw[$tagByteOffset];
        $tampered = substr($raw, 0, $tagByteOffset) . $flippedByte . substr($raw, $tagByteOffset + 1);

        $this->assertNull(Crypto::decrypt(base64_encode($tampered)));
    }
}
