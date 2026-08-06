<?php
// src/Security/TrustedHost.php

namespace App\Security;

/**
 * Class TrustedHost
 *
 * Validiert den vom Client mitgeschickten Host-Header (Issue #116): Der Wert
 * aus `$_SERVER['HTTP_HOST']` ist Angreifer-kontrolliert und darf nie ungeprüft
 * in absolute URLs einfließen, die per E-Mail verschickt werden (z. B. der
 * Passwort-Reset-Link in App\Service\Mailer) - sonst kann ein gefälschter
 * Host-Header den Reset-Token auf eine Angreifer-Domain umleiten
 * (Reset-Link-Poisoning).
 *
 * Zwei Verteidigungslinien:
 * 1. Syntaktische Validierung (Hostname- oder IP-Literal, optional :Port) -
 *    verhindert Header-Injection über Sonderzeichen.
 * 2. Optionale Allowlist TRUSTED_HOSTS (kommagetrennte Hostnamen, Eintrag mit
 *    führendem Punkt = beliebige Subdomain, z. B. ".example.org"): Ist sie
 *    konfiguriert, wird jeder nicht gelistete Host verworfen. Konfiguration
 *    analog zu TRUSTED_PROXIES per Umgebungsvariable oder db_config.php
 *    (siehe config/config.php).
 *
 * Vorrang haben immer die explizit konfigurierte settings.base_url bzw. die
 * Umgebungsvariable APP_URL - diese Klasse betrifft nur den dynamischen
 * Fallback, wenn beides fehlt.
 */
class TrustedHost {

    /**
     * Liefert den validierten Host (inkl. Port, falls vorhanden) aus HTTP_HOST
     * oder '' wenn der Header fehlt, syntaktisch ungültig ist oder nicht auf
     * der konfigurierten Allowlist steht.
     */
    public static function resolve(): string {
        $rawHost = trim($_SERVER['HTTP_HOST'] ?? '');
        if ($rawHost === '' || !self::isSyntacticallyValid($rawHost)) {
            return '';
        }

        $allowlist = self::getTrustedHosts();
        if ($allowlist === []) {
            // Keine Allowlist konfiguriert: nur syntaktische Prüfung (Härtung
            // ohne Breaking Change für Bestandsinstallationen). Betreiber ohne
            // gesetzte base_url/APP_URL sollten TRUSTED_HOSTS setzen, siehe
            // docs/security.md.
            return $rawHost;
        }

        $hostOnly = strtolower(self::stripPort($rawHost));
        foreach ($allowlist as $entry) {
            if (self::hostMatches($hostOnly, $entry)) {
                return $rawHost;
            }
        }
        return '';
    }

    /**
     * Hostname (RFC-952/1123-Zeichensatz), IPv4-Literal oder IPv6-Literal in
     * eckigen Klammern - jeweils mit optionalem :Port.
     */
    private static function isSyntacticallyValid(string $host): bool {
        // IPv6-Literal, z. B. "[::1]:8080"
        if (preg_match('/^\[([0-9a-fA-F:]+)\](:\d{1,5})?$/', $host, $m) === 1) {
            return filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }
        // Hostname oder IPv4, optional mit Port
        return preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?(:\d{1,5})?$/', $host) === 1;
    }

    private static function stripPort(string $host): string {
        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');
            return $end === false ? $host : substr($host, 0, $end + 1);
        }
        $colon = strpos($host, ':');
        return $colon === false ? $host : substr($host, 0, $colon);
    }

    /**
     * Eintrag mit führendem Punkt (".example.org") matcht die Domain selbst und
     * jede Subdomain, sonst exakter (case-insensitiver) Vergleich.
     */
    private static function hostMatches(string $hostOnly, string $entry): bool {
        $entry = strtolower($entry);
        if (str_starts_with($entry, '.')) {
            $bare = ltrim($entry, '.');
            return $hostOnly === $bare || str_ends_with($hostOnly, $entry);
        }
        return $hostOnly === $entry;
    }

    /**
     * @return string[]
     */
    private static function getTrustedHosts(): array {
        // Auflösung analog zu ClientIp::getTrustedProxies(): Konstante aus
        // config/config.php, sonst direkt die Umgebungsvariable (CLI-Skripte
        // ohne config.php-Bootstrap). Bewusst ohne static-Cache, damit
        // Unit-Tests verschiedene Allowlists per putenv() durchspielen können.
        $raw = defined('TRUSTED_HOSTS') ? TRUSTED_HOSTS : (getenv('TRUSTED_HOSTS') ?: '');
        return $raw === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
