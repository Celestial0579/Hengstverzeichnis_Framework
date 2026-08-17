<?php
// src/Security/OidcDiscovery.php

namespace App\Security;

/**
 * Class OidcDiscovery
 *
 * Lädt und validiert das OIDC-Discovery-Dokument
 * (`<issuer>/.well-known/openid-configuration`) für den generischen
 * SSO-Modus (siehe App\Controllers\EntraSsoController) - ohne externe
 * Bibliothek, konsistent mit der "keine Abhängigkeiten"-Philosophie.
 *
 * Sicherheitsmodell: Der SSO-Login verzichtet bewusst auf eine
 * JWT-Signaturprüfung, weil das ID-Token ausschließlich serverseitig über
 * TLS vom Token-Endpunkt geholt wird (siehe App\Security\OidcIdToken).
 * Dieses Modell steht und fällt damit, dass der Token-Endpunkt
 * vertrauenswürdig ist. Deshalb gilt hier fail-closed:
 *
 * - Das Discovery-Dokument muss den konfigurierten Issuer EXAKT als
 *   `issuer` ausweisen (RFC 8414 - schützt gegen Tippfehler in der
 *   Konfiguration und gegen Dokumente, die für einen anderen Issuer
 *   ausgestellt sind).
 * - Issuer und beide Endpunkte müssen `https://` sein. Einzige Ausnahme:
 *   Loopback-Hosts (127.0.0.1, ::1, localhost) für lokale Tests und
 *   Dev-Setups, bei denen der Verkehr die Maschine nie verlässt.
 */
final class OidcDiscovery {

    private const TIMEOUT_SECONDS = 15;

    private function __construct() {}

    /**
     * Holt das Discovery-Dokument des Issuers und gibt die validierten
     * Endpunkte zurück.
     *
     * @return array{authorization_endpoint: string, token_endpoint: string}
     * @throws \RuntimeException bei Netz-/Format-/Validierungsfehlern (fail-closed)
     */
    public static function fetch(string $issuerUrl): array {
        $issuerUrl = trim($issuerUrl);
        self::assertHttpsOrLoopback($issuerUrl, 'Issuer-URL');

        $url = rtrim($issuerUrl, '/') . '/.well-known/openid-configuration';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        if ($body === false) {
            throw new \RuntimeException("OIDC-Discovery nicht erreichbar: {$error}");
        }
        if ($status !== 200) {
            throw new \RuntimeException("OIDC-Discovery fehlgeschlagen (HTTP {$status}).");
        }

        return self::parse((string)$body, $issuerUrl);
    }

    /**
     * Validiert ein Discovery-Dokument (netzfrei, damit unit-testbar).
     *
     * @return array{authorization_endpoint: string, token_endpoint: string}
     * @throws \RuntimeException wenn das Dokument nicht zum erwarteten Issuer
     *                           passt oder Pflichtangaben fehlen (fail-closed)
     */
    public static function parse(string $json, string $expectedIssuer): array {
        $doc = json_decode($json, true);
        if (!is_array($doc)) {
            throw new \RuntimeException('OIDC-Discovery-Dokument ist kein gültiges JSON.');
        }

        // RFC 8414: Der im Dokument ausgewiesene Issuer muss exakt dem
        // entsprechen, unter dem das Dokument abgerufen wurde - ein
        // trailing slash zählt (Authentik-Issuer enden z. B. auf '/').
        $issuer = (string)($doc['issuer'] ?? '');
        if ($issuer === '' || $issuer !== trim($expectedIssuer)) {
            throw new \RuntimeException('OIDC-Discovery: issuer im Dokument entspricht nicht der konfigurierten Issuer-URL.');
        }

        $endpoints = [];
        foreach (['authorization_endpoint', 'token_endpoint'] as $key) {
            $value = trim((string)($doc[$key] ?? ''));
            if ($value === '') {
                throw new \RuntimeException("OIDC-Discovery: {$key} fehlt im Dokument.");
            }
            self::assertHttpsOrLoopback($value, $key);
            $endpoints[$key] = $value;
        }

        return $endpoints;
    }

    /**
     * `https://` erzwingen; `http://` nur für Loopback-Hosts zulassen.
     */
    private static function assertHttpsOrLoopback(string $url, string $label): void {
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if ($scheme === 'https') {
            return;
        }

        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $isLoopback = $host === 'localhost' || $host === '::1' || $host === '[::1]'
            || (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                && str_starts_with($host, '127.'));
        if ($scheme === 'http' && $isLoopback) {
            return;
        }

        throw new \RuntimeException("OIDC-Discovery: {$label} muss https:// verwenden (http nur für Loopback-Adressen erlaubt).");
    }
}
