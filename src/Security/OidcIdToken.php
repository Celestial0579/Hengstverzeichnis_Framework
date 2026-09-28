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
     * Extrahiert die E-Mail-Adresse, über die das lokale Konto gefunden wird.
     *
     * Die Adresse ist der einzige Anknüpfungspunkt an das lokale Konto
     * (EntraSsoController sucht `WHERE email = ?`). Was hier herauskommt,
     * entscheidet also, WELCHES Konto angemeldet wird. Deshalb gilt je Modus
     * eine eigene, feste Regel (Audit M19):
     *
     * - **Beide Modi:** Ein vorhandener `email_verified`-Claim, der nicht als
     *   wahr lesbar ist (false, "false", 0, "0", JSON-null, ein Array ...),
     *   führt IMMER zur Ablehnung - auch wenn gar kein `email`-Claim
     *   mitkommt. Bis hierher prüfte die Methode den Claim nur neben einer
     *   nicht leeren Adresse. Keycloak sendet für Konten ohne Adresse
     *   `email_verified=false` und kein `email`, Authentik `email=""` - und
     *   dann galt ungeprüft `preferred_username`. Den wählt bei Keycloak und
     *   Authentik der Benutzer selbst (OIDC Core 5.7 nennt ihn weder
     *   eindeutig noch unveränderlich). Wer sich dort den Namen
     *   `admin@verein.de` gab, war lokaler Administrator.
     * - **Generischer Modus** (Authentik, Keycloak, ...): Es zählt nur ein
     *   `email`-Claim mit `email_verified: true`. Fehlt der Claim, wird
     *   abgelehnt (fail-closed) - standardkonforme Provider senden ihn mit
     *   dem Scope `email`. Einen Rückfall auf `preferred_username` gibt es
     *   hier nicht mehr. Hinweis für den Betrieb: Authentik sendet ab
     *   2025.10 im Standard `email_verified: false`; siehe docs/security.md.
     * - **ENTRA-Modus:** Entra ID sendet `email_verified` für Geschäftskonten
     *   nicht, und Adressen wie UPN vergibt allein der Tenant-Administrator.
     *   Ein fehlender Claim bleibt deshalb akzeptiert, und ohne `email`-Claim
     *   gilt wie bisher der UPN (`preferred_username`) - Entra liefert die
     *   Adresse nur als optionalen Claim, ohne den Rückfall wären bestehende
     *   Installationen ausgesperrt.
     *
     * Der Standard des Parameters ist der strenge generische Modus: Ein
     * künftiger Aufrufer, der den Modus vergisst, arbeitet fail-closed.
     */
    public static function extractEmail(array $claims, bool $entraModus = false): ?string {
        $status = self::emailVerifiedStatus($claims);
        if ($status === false) {
            return null;
        }

        $roh = $claims['email'] ?? null;
        if ($roh !== null && !is_string($roh)) {
            return null;
        }
        $email = trim((string)$roh);
        if ($email !== '') {
            if (!$entraModus && $status !== true) {
                return null;
            }
            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
        }

        if (!$entraModus) {
            return null;
        }

        $upn = $claims['preferred_username'] ?? null;
        if (!is_string($upn)) {
            return null;
        }
        $upn = trim($upn);
        if ($upn !== '' && filter_var($upn, FILTER_VALIDATE_EMAIL) !== false) {
            return $upn;
        }

        return null;
    }

    /**
     * Warum extractEmail() abgelehnt hat - für das Audit-Log.
     *
     * Nennt NIE eine Adresse oder einen Benutzernamen, nur die Form der
     * Claims: Das Protokoll soll dem Betreiber zeigen, was sein IdP schickt,
     * ohne dass ein abgewiesener Versuch fremde Kennungen hineinschreibt.
     */
    public static function ablehnungsgrund(array $claims, bool $entraModus): string {
        $roh = $claims['email'] ?? null;
        if (!array_key_exists('email', $claims) || $roh === null) {
            $email = 'email-Claim fehlt';
        } elseif (!is_string($roh)) {
            $email = 'email-Claim ist kein Text';
        } elseif (trim($roh) === '') {
            $email = 'email-Claim leer';
        } elseif (filter_var(trim($roh), FILTER_VALIDATE_EMAIL) === false) {
            $email = 'email-Claim ohne gültiges Adressformat';
        } else {
            $email = 'email-Claim vorhanden';
        }

        $verifiziert = match (self::emailVerifiedStatus($claims)) {
            null => 'email_verified fehlt',
            true => 'email_verified=true',
            false => 'email_verified=false',
        };

        $upn = '';
        if ($entraModus && trim(is_string($roh) ? $roh : '') === '') {
            $upn = is_string($claims['preferred_username'] ?? null) && trim($claims['preferred_username']) !== ''
                ? ', preferred_username ohne gültiges Adressformat'
                : ', preferred_username fehlt';
        }

        return "{$email}, {$verifiziert}{$upn}, Modus=" . ($entraModus ? 'ENTRA' : 'generisch');
    }

    /**
     * Was sagt der Provider über die Adresse?
     *
     * null nur, wenn der Claim fehlt - das ist keine Aussage. Jeder
     * VORHANDENE Wert, der nicht eindeutig wahr ist, zählt als Nein: auch
     * JSON-null, ein Array oder Unsinn. Der Wert kommt je nach Provider als
     * Boolean, String oder 0/1 an.
     */
    private static function emailVerifiedStatus(array $claims): ?bool {
        if (!array_key_exists('email_verified', $claims)) {
            return null;
        }
        $wert = $claims['email_verified'];
        if (!is_scalar($wert)) {
            return false;
        }
        return filter_var($wert, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    /**
     * Weist das ID-Token eine Mehr-Faktor-Anmeldung beim IdP nach?
     *
     * Nur für OIDC_TRUST_IDP_MFA (Audit N9): Dann darf der lokale zweite
     * Faktor entfallen - aber nur mit Nachweis, nie auf Zuruf. Gibt die
     * Fundstelle zurück ('amr=mfa', 'acr=gold') oder null (fail-closed).
     *
     * - `amr` (RFC 8176) ist eine Liste von Strings; ein einzelner String
     *   wird toleriert. `pwd` zählt nie, auch wenn der Betreiber es in die
     *   Liste schreibt - es ist das Gegenteil eines Nachweises.
     * - `acr` zählt nur als String aus der konfigurierten Liste. Der Standard
     *   ist leer: Werte wie Keycloaks `1` (Level of Assurance) sagen je nach
     *   Konfiguration nichts über MFA aus.
     *
     * Provider: Entra ID schreibt `amr` in das v2-ID-Token nur als
     * konfigurierten optionalen Claim, `mfa` erscheint dort nur nach
     * erfolgter MFA. Keycloak braucht dafür einen AMR-Mapper (ab 23),
     * Authentik ein eigenes Scope-Mapping.
     *
     * @param array<int, string> $amrWerte
     * @param array<int, string> $acrWerte
     */
    public static function mfaNachweis(array $claims, array $amrWerte, array $acrWerte): ?string {
        $amrWerte = array_values(array_diff($amrWerte, ['pwd', '']));

        $amr = $claims['amr'] ?? null;
        if (is_string($amr)) {
            $amr = [$amr];
        }
        if (is_array($amr)) {
            foreach ($amr as $wert) {
                if (is_string($wert) && in_array($wert, $amrWerte, true)) {
                    return 'amr=' . $wert;
                }
            }
        }

        $acr = $claims['acr'] ?? null;
        $acrWerte = array_values(array_diff($acrWerte, ['']));
        if (is_string($acr) && in_array($acr, $acrWerte, true)) {
            return 'acr=' . $acr;
        }

        return null;
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
