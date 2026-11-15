<?php

namespace App\Security;

/**
 * PKCE für den OIDC-Code-Flow (RFC 7636, Methode S256; GHSA-g8jp-xvcw-m27p).
 *
 * Der Client erzeugt pro Login-Versuch einen geheimen code_verifier und
 * schickt nur dessen Hash (code_challenge) zum Authorize-Endpunkt. Beim
 * Token-Tausch muss er den Verifier vorlegen. Ein Autorisierungscode, der in
 * einem fremden Flow ausgestellt wurde, passt deshalb nicht zum Verifier
 * dieser Session, und der Provider lehnt den Tausch ab. Damit ist das
 * Einschleusen eines Codes abgewehrt, das RFC 9700, Abschnitt 2.1.1,
 * verlangt. `state` schützt nur den Redirect-Endpunkt gegen CSRF.
 */
final class OidcPkce {

    public const METHODE = 'S256';

    private function __construct() {}

    /**
     * 32 Zufallsbytes, base64url ohne Padding: 43 Zeichen aus dem in
     * RFC 7636, Abschnitt 4.1, erlaubten Zeichensatz.
     */
    public static function verifier(): string {
        return self::base64Url(random_bytes(32));
    }

    /** code_challenge = BASE64URL(SHA256(code_verifier)) (RFC 7636, Abschnitt 4.2). */
    public static function challenge(string $verifier): string {
        return self::base64Url(hash('sha256', $verifier, true));
    }

    private static function base64Url(string $bytes): string {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
