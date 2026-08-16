<?php
// src/Security/ContentSecurityPolicy.php

namespace App\Security;

/**
 * Aufbau der Content-Security-Policy an EINER Stelle (#260).
 *
 * Vorher stand die Policy als Literal in config/config.php. Das genügte,
 * solange sie für jede Antwort gleich war. Seit der Embed-Freigabe gibt es zwei
 * Fassungen, die sich in genau einer Direktive unterscheiden - und zwei
 * getrennte Literale derselben Policy sind die Bauart, die auseinanderdriftet:
 * Wer später `connect-src` ergänzt, ergänzt es in einer von beiden, und die
 * Embed-Ansicht bricht auf eine Weise, die niemand mit der CSP in Verbindung
 * bringt.
 */
final class ContentSecurityPolicy {

    /**
     * @param array<int, string>|null $frameAncestors Ersetzt die Standard-Angabe
     *   `'self'`. Nur für die Embed-Freigabe gedacht; null heißt "wie immer".
     */
    public static function build(?array $frameAncestors = null): string {
        // TRACKING_DOMAINS wird nur bei aktiv konfiguriertem Tracking-Code
        // angehängt - ohne Konfiguration bleibt die Policy unverändert streng.
        //
        // defined()-Prüfung, weil die Klasse auch außerhalb eines Web-Requests
        // aufgerufen wird (Tests, künftig womöglich ein CLI-Werkzeug) und
        // config/config.php dort nicht geladen ist. Der Rückfall ist die
        // STRENGERE Variante: Fehlt die Konfiguration, wird nichts erlaubt,
        // statt dass der Aufbau der Policy scheitert - eine Ausnahme mitten im
        // Header-Setzen hinterließe eine Antwort ganz ohne CSP.
        $configured = defined('TRACKING_DOMAINS') ? (string)constant('TRACKING_DOMAINS') : '';
        $tracking = $configured !== '' ? ' ' . str_replace(',', ' ', $configured) : '';

        // 'self' bleibt IMMER enthalten, auch bei gesetzter Allowlist: Die eigene
        // Oberfläche bettet sich selbst ein (Vorschau im Admin-Bereich), und ein
        // Konfigurationsfehler in der Allowlist darf nicht die eigene Seite
        // aussperren.
        $ancestors = "'self'";
        if ($frameAncestors !== null && $frameAncestors !== []) {
            $ancestors .= ' ' . implode(' ', $frameAncestors);
        }

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline'" . $tracking,
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com",
            "img-src 'self' data:" . $tracking,
            "connect-src 'self'" . $tracking,
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            'frame-ancestors ' . $ancestors,
        ]);
    }
}
