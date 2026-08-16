<?php
// src/Security/OidcIdToken.php

namespace App\Security;

/**
 * Class OidcIdToken
 *
 * Minimale Auswertung eines OIDC-ID-Tokens für den SSO-Login (#42, siehe
 * App\Controllers\EntraSsoController) - ohne externe JWT-Bibliothek,
 * konsistent mit der "keine Abhängigkeiten"-Philosophie des Kerns.
 *
 * Wichtiger Sicherheits-Kontext: Das ID-Token stammt hier IMMER direkt aus
 * der serverseitigen Antwort des Token-Endpunkts des konfigurierten
 * Providers über TLS (Authorization-Code-Flow mit Client-Secret) - nie aus
 * dem Browser. Die Authentizität ist damit durch den TLS-Kanal zur bekannten
 * Token-URL gegeben; eine zusätzliche Signaturprüfung (JWKS) entfällt
 * bewusst. Im generischen OIDC-Modus kommt die Token-URL aus dem
 * Discovery-Dokument des konfigurierten Issuers und wird von
 * App\Security\OidcDiscovery gegen genau diesen Issuer geprüft - das
 * TLS-Trust-Modell bleibt also geschlossen. Validiert werden die
 * inhaltlichen Claims: Aussteller, Zielgruppe (Client-ID) und Ablaufzeit.
 */
final class OidcIdToken {

    private function __construct() {}

    /**
     * Dekodiert und validiert die Claims eines ID-Tokens.
     *
     * @return array<string, mixed> Die Claims bei Erfolg
     * @throws \RuntimeException wenn das Token strukturell ungültig ist oder
     *                           ein Pflicht-Claim nicht passt (fail-closed)
     */
    public static function parseAndValidate(string $jwt, string $expectedClientId, string $expectedIssuer, ?int $now = null): array {
        $now = $now ?? time();

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new \RuntimeException('ID-Token hat kein gültiges JWT-Format.');
        }

        $payload = self::base64UrlDecode($parts[1]);
        $claims = json_decode($payload, true);
        if (!is_array($claims)) {
            throw new \RuntimeException('ID-Token-Payload ist kein gültiges JSON.');
        }

        // Zielgruppe: Token muss für GENAU diese App ausgestellt sein.
        $aud = $claims['aud'] ?? null;
        if ($aud !== $expectedClientId && !(is_array($aud) && in_array($expectedClientId, $aud, true))) {
            throw new \RuntimeException('ID-Token ist nicht für diese Anwendung ausgestellt (aud-Claim).');
        }

        // Aussteller: exakter Vergleich mit dem erwarteten Issuer - ein
        // trailing slash zählt (Authentik-Issuer enden z. B. auf '/'). Den
        // Microsoft-Issuer 'https://login.microsoftonline.com/<tenant>/v2.0'
        // baut im ENTRA-Modus der Controller.
        $iss = (string)($claims['iss'] ?? '');
        if ($iss === '' || $iss !== $expectedIssuer) {
            throw new \RuntimeException('ID-Token stammt nicht vom erwarteten Aussteller (iss-Claim).');
        }

        // Ablauf (mit kleiner Toleranz für Uhrenabweichung).
        $exp = (int)($claims['exp'] ?? 0);
        if ($exp < $now - 60) {
            throw new \RuntimeException('ID-Token ist abgelaufen.');
        }

        return $claims;
    }

    /**
     * Extrahiert die E-Mail-Adresse aus den Claims: `email` bevorzugt, sonst
     * `preferred_username` (bei Entra ID üblicherweise der UPN), sofern es wie
     * eine E-Mail-Adresse aussieht.
     *
     * WARUM `email_verified` HIER ZÄHLT: Die Adresse ist der einzige
     * Anknüpfungspunkt an das lokale Konto (EntraSsoController sucht
     * `WHERE email = ?`). Ein Provider mit Selbstregistrierung - Keycloak,
     * Authentik - lässt einen Benutzer beim Anlegen eine beliebige Adresse
     * eintragen. Sagt der Provider ausdrücklich, dass sie ihm NICHT
     * nachgewiesen wurde, ist der Claim eine Behauptung des Angreifers und
     * kein Identitätsnachweis: Es genügte, sich dort mit der Adresse eines
     * Administrators zu registrieren. Fehlt der Claim ganz, wird er
     * akzeptiert - Entra ID sendet ihn für Geschäftskonten nicht, und dort
     * vergibt ohnehin nur der Tenant-Administrator Adressen.
     *
     * RESTRISIKO `preferred_username`: OIDC Core 5.7 nennt den Claim
     * ausdrücklich weder eindeutig noch unveränderlich. Er bleibt als
     * Rückfall erhalten, weil Entra ID die E-Mail nur als optionalen Claim
     * ausliefert und ohne ihn der UPN die einzige Quelle ist - ein Entfernen
     * würde bestehende, funktionierende Installationen aussperren. Er greift
     * jetzt nur noch, wenn gar kein `email`-Claim vorliegt: Ein vorhandener,
     * aber unbestätigter `email`-Claim führt zur Ablehnung statt zum
     * Ausweichen auf den schwächeren Wert.
     */
    public static function extractEmail(array $claims): ?string {
        $email = trim((string)($claims['email'] ?? ''));
        if ($email !== '') {
            if (self::claimsSayEmailUnverified($claims)) {
                return null;
            }
            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
        }

        $upn = trim((string)($claims['preferred_username'] ?? ''));
        if ($upn !== '' && filter_var($upn, FILTER_VALIDATE_EMAIL) !== false) {
            return $upn;
        }

        return null;
    }

    /**
     * Sagt der Provider ausdrücklich, dass die Adresse unbestätigt ist?
     * Nur ein ausdrückliches Nein zählt - ein fehlender Claim ist keine
     * Aussage. Der Wert kommt je nach Provider als Boolean, als String oder
     * als 0/1 an.
     */
    private static function claimsSayEmailUnverified(array $claims): bool {
        if (!array_key_exists('email_verified', $claims)) {
            return false;
        }
        return filter_var($claims['email_verified'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== true;
    }

    private static function base64UrlDecode(string $data): string {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        if ($decoded === false) {
            // Padding ergänzen und erneut versuchen
            $padded = str_pad(strtr($data, '-_', '+/'), (int)(ceil(strlen($data) / 4) * 4), '=');
            $decoded = base64_decode($padded, true);
        }
        return $decoded === false ? '' : $decoded;
    }
}
