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
        return self::verifyCodeReturnSlice($secret, $code, null, $discrepancy) !== null;
    }

    /**
     * Wie verifyCode(), liefert aber den getroffenen Zeitschlitz zurück und
     * setzt Replay-Schutz durch (Issue #111): Zeitschlitze, die kleiner oder
     * gleich dem zuletzt verbrauchten Schlitz ($lastUsedSlice, siehe
     * users.last_totp_timeslice) sind, werden abgelehnt - ein abgefangener/
     * geschulterter Code ist damit nur ein einziges Mal verwendbar, nicht
     * während des gesamten Toleranzfensters (~90 s). Der Aufrufer muss den
     * zurückgegebenen Schlitz nach erfolgreicher Prüfung persistieren.
     *
     * @return int|null Getroffener Zeitschlitz bei Erfolg, sonst null
     */
    public static function verifyCodeReturnSlice(string $secret, string $code, ?int $lastUsedSlice, int $discrepancy = 1): ?int {
        $currentTimeSlice = (int)floor(time() / 30);

        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $slice = $currentTimeSlice + $i;
            if ($lastUsedSlice !== null && $slice <= $lastUsedSlice) {
                // Bereits verbrauchter oder noch älterer Schlitz: auch bei
                // korrektem Code ablehnen (Replay).
                continue;
            }
            if (hash_equals(self::getCode($secret, $slice), trim($code))) {
                return $slice;
            }
        }

        return null;
    }

    /**
     * Hat der gespeicherte Wert das Format eines UNVERSCHLÜSSELTEN Secrets?
     *
     * Genau das Format von generateSecret(): Base32-Großbuchstaben, 16 bis 64
     * Zeichen. Ein Chiffretext aus Crypto::encrypt() ist Base64 und mindestens
     * 60 Zeichen lang - dass er zufällig nur aus diesen 32 Zeichen besteht,
     * hat eine Wahrscheinlichkeit von etwa 2^-60 (Audit N8).
     */
    public static function istKlartextSecret(string $wert): bool {
        return preg_match('/^[A-Z2-7]{16,64}$/', $wert) === 1;
    }

    /**
     * Das nutzbare Secret zu einem gespeicherten users.totp_secret - oder
     * null, wenn es keins gibt.
     *
     * FAIL-CLOSED (Audit N8). Bis hierher galt: Lässt sich der Wert nicht
     * entschlüsseln, wird der Rohwert selbst zum Secret. Nach einem Wechsel
     * des APP_KEY war das der Base64-Chiffretext - und den kennt jeder, der
     * einen Datenbank-Dump hat. Der Rückfall auf den Rohwert gilt deshalb nur
     * noch für echten Base32-Klartext (Altbestand aus der Zeit vor der
     * Verschlüsselung, Restore alter Dumps); wer den in der Datenbank sieht,
     * kennt das Secret ohnehin. Der Migrationsschritt
     * totp_klartext_verschluesseln verschlüsselt solche Zeilen, der Rückfall
     * soll im nächsten Minor-Release entfallen.
     *
     * Ein fehlender APP_KEY wirft wie bisher (Crypto::getKey()).
     */
    public static function entschluesseleSecret(string $gespeichert): ?string {
        $klartext = Crypto::decrypt($gespeichert);
        if ($klartext !== null) {
            return $klartext;
        }
        return self::istKlartextSecret($gespeichert) ? $gespeichert : null;
    }

    /**
     * Wie entschluesseleSecret(), vermerkt ein nicht lesbares Secret aber im
     * Audit-Log. Die EINE Lesestelle für users.totp_secret im Anmeldeweg,
     * beim Step-up und im Profil - drei Kopien des Rückfalls waren genau die
     * Drift, aus der Audit N8 entstand.
     */
    public static function secretAusSpeicher(string $gespeichert, int $userId): ?string {
        $secret = self::entschluesseleSecret($gespeichert);
        if ($secret === null) {
            \App\Service\AuditLogger::log(
                'TOTP-Secret nicht lesbar',
                'security',
                "Benutzer-ID {$userId}: Das gespeicherte Secret lässt sich mit dem aktuellen APP_KEY nicht "
                . 'entschlüsseln (APP_KEY gewechselt oder verloren?). Anmeldung nur per Backup-Code; bitte die '
                . '2FA des Kontos zurücksetzen.',
                $userId
            );
        }
        return $secret;
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
     * Generates 10 single-use backup recovery codes.
     *
     * Jeder Code liefert 64 Bit Entropie (2x 4 Byte, als 16 Hex-Zeichen im Format
     * XXXXXXXX-XXXXXXXX) - genug Spielraum für einen 2FA-Wiederherstellungscode,
     * auch falls der (bewusst ausfallsichere, also im DB-Fehlerfall fail-open)
     * Rate-Limiter einmal nicht greift. Die Codes werden vor dem Speichern mit
     * password_hash() gehasht; die Länge ist für die Verifizierung unerheblich,
     * daher bleiben bereits ausgegebene ältere Codes weiterhin gültig.
     */
    public static function generateBackupCodes(int $count = 10): array {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $code = strtoupper(bin2hex(random_bytes(4)) . '-' . bin2hex(random_bytes(4)));
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
