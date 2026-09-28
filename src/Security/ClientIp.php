<?php
// src/Security/ClientIp.php

namespace App\Security;

/**
 * Class ClientIp
 *
 * Ermittelt die tatsächliche Client-IP-Adresse und ob die ursprüngliche
 * Verbindung über HTTPS lief - sicher auch hinter Reverse Proxies/Load
 * Balancern. `X-Forwarded-For`/`X-Forwarded-Proto` werden ausschließlich
 * dann ausgewertet, wenn die unmittelbar verbindende Gegenstelle (REMOTE_ADDR)
 * über TRUSTED_PROXIES als vertrauenswürdiger Proxy gelistet ist - sonst
 * könnte jeder Client diese Header selbst gefälscht mitschicken, um z. B.
 * IP-basiertes Rate-Limiting oder Audit-Logs zu manipulieren.
 */
class ClientIp {

    /**
     * Ermittelt die Client-IP-Adresse. Ohne konfigurierte TRUSTED_PROXIES
     * wird immer REMOTE_ADDR verwendet, X-Forwarded-For wird ignoriert.
     */
    public static function resolve(): string {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        if (!self::isTrustedProxy($remoteAddr)) {
            return $remoteAddr;
        }

        $forwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($forwardedFor === '') {
            return $remoteAddr;
        }

        // Kette: client, proxy1, proxy2, ... - von rechts (nächstgelegener Hop)
        // nach links laufen und den ersten nicht-vertrauenswürdigen Eintrag nehmen.
        $chain = array_map('trim', explode(',', $forwardedFor));
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $candidate = $chain[$i];
            if ($candidate === '' || !filter_var($candidate, FILTER_VALIDATE_IP)) {
                continue;
            }
            if (!self::isTrustedProxy($candidate)) {
                return $candidate;
            }
        }

        return $remoteAddr;
    }

    /**
     * Präfixlänge, auf die IPv6-Adressen für Rate-Limits gekürzt werden.
     */
    public const IPV6_RATE_LIMIT_PREFIX = 64;

    /**
     * Der Schlüssel, unter dem eine Client-Adresse in IP-Zählern geführt wird
     * (Audit M7).
     *
     * WARUM NICHT DIE VOLLE ADRESSE. Jeder IPv6-Anschluss bekommt mindestens
     * ein /64, also 2^64 Adressen, und kann jede Anfrage von einer neuen
     * schicken. Ein Zähler je voller Adresse griff damit nie: Wer
     * Passwörter rät, Reset-Mails auslöst oder Formulare flutet, wechselte
     * einfach die Absenderadresse. Adressen aus demselben Präfix teilen sich
     * deshalb einen Zähler - wie mehrere Geräte hinter einem IPv4-NAT.
     *
     * IPv4-gemappte Adressen (`::ffff:203.0.113.7`) werden ZUERST zu IPv4.
     * Ohne diese Weiche landeten auf Dual-Stack-Sockets alle IPv4-Clients in
     * einem einzigen /64-Topf (`::ffff:0:0/64` wäre für alle derselbe).
     *
     * Was keine gültige Adresse ist (auch eine mit Zonen-ID wie `fe80::1%eth0`),
     * bleibt unverändert: Hier wird nichts geraten. Das Ergebnis `…/64` ist
     * seinerseits kein gültiges IP-Literal, ein zweiter Aufruf ändert es also
     * nicht mehr.
     *
     * @param string|null $ip Adresse; ohne Angabe die aus resolve()
     * @param int $ipv6Prefix Präfixlänge für IPv6 (1-128)
     */
    public static function rateLimitKey(?string $ip = null, int $ipv6Prefix = self::IPV6_RATE_LIMIT_PREFIX): string {
        $ip ??= self::resolve();
        if ($ipv6Prefix < 1 || $ipv6Prefix > 128) {
            throw new \InvalidArgumentException("IPv6-Präfixlänge {$ipv6Prefix} liegt nicht zwischen 1 und 128.");
        }

        $bin = @inet_pton($ip);
        if ($bin === false) {
            return $ip;
        }
        if (strlen($bin) === 4) {
            return (string)inet_ntop($bin);
        }
        if (strlen($bin) !== 16) {
            return $ip;
        }

        if (substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            return (string)inet_ntop(substr($bin, 12));
        }

        $maske = str_repeat("\xff", intdiv($ipv6Prefix, 8));
        if ($ipv6Prefix % 8 !== 0) {
            $maske .= chr((0xFF << (8 - $ipv6Prefix % 8)) & 0xFF);
        }
        $maske = str_pad($maske, 16, "\0");

        return inet_ntop($bin & $maske) . '/' . $ipv6Prefix;
    }

    /**
     * Ermittelt, ob die ursprüngliche Verbindung über HTTPS lief. Berücksichtigt
     * X-Forwarded-Proto nur, wenn REMOTE_ADDR ein vertrauenswürdiger Proxy ist.
     */
    public static function isHttps(): bool {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        if (self::isTrustedProxy($remoteAddr) && !empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $proto = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]);
            return strtolower($proto) === 'https';
        }

        return false;
    }

    /**
     * Prüft, ob die gegebene IP in TRUSTED_PROXIES gelistet ist (einzelne
     * IPs oder CIDR-Notation, kommagetrennt, z. B. "10.0.0.5,172.16.0.0/12").
     */
    private static function isTrustedProxy(string $ip): bool {
        foreach (self::getTrustedProxies() as $entry) {
            if (self::ipMatches($ip, $entry)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return string[]
     */
    private static function getTrustedProxies(): array {
        static $trusted = null;
        if ($trusted === null) {
            // TRUSTED_PROXIES wird in config/config.php aus der Umgebungsvariable ODER,
            // falls diese nicht gesetzt ist (z. B. auf klassischem Webhosting ohne
            // zuverlässige Env-Var-Weitergabe), aus config/db_config.php aufgelöst.
            // Fallback auf getenv() direkt, falls die Konstante ausnahmsweise noch nicht
            // definiert ist (z. B. in CLI-Skripten ohne config.php-Bootstrap).
            $raw = defined('TRUSTED_PROXIES') ? TRUSTED_PROXIES : (getenv('TRUSTED_PROXIES') ?: '');
            $trusted = $raw === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $raw))));
        }
        return $trusted;
    }

    /**
     * Prüft, ob ein einzelner Eintrag ein syntaktisch gültiger TRUSTED_PROXIES-Wert
     * ist (einzelne IPv4/IPv6-Adresse oder CIDR-Notation). Für die Validierung von
     * Nutzereingaben im Admin-Bereich (Systemeinstellungen).
     */
    public static function isValidProxyEntry(string $entry): bool {
        if ($entry === '') {
            return false;
        }
        if (strpos($entry, '/') === false) {
            return filter_var($entry, FILTER_VALIDATE_IP) !== false;
        }

        $parts = explode('/', $entry, 2);
        if (count($parts) !== 2 || !ctype_digit($parts[1])) {
            return false;
        }
        [$subnet, $maskBits] = $parts;

        $subnetBin = @inet_pton($subnet);
        if ($subnetBin === false) {
            return false;
        }

        return (int)$maskBits <= strlen($subnetBin) * 8;
    }

    private static function ipMatches(string $ip, string $entry): bool {
        if (strpos($entry, '/') === false) {
            return $entry === $ip;
        }
        return self::cidrMatch($ip, $entry);
    }

    private static function cidrMatch(string $ip, string $cidr): bool {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$subnet, $maskBits] = $parts;
        $maskBits = (int)$maskBits;

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $maxBits = strlen($ipBin) * 8;
        if ($maskBits < 0 || $maskBits > $maxBits) {
            return false;
        }

        $bytes = intdiv($maskBits, 8);
        $remainderBits = $maskBits % 8;

        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }
        if ($remainderBits === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $remainderBits)) & 0xFF);
        return (substr($ipBin, $bytes, 1) & $mask) === (substr($subnetBin, $bytes, 1) & $mask);
    }
}
