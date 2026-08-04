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
            $raw = getenv('TRUSTED_PROXIES');
            $trusted = $raw === false ? [] : array_values(array_filter(array_map('trim', explode(',', $raw))));
        }
        return $trusted;
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
