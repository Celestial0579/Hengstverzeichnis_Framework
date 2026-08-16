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

        // Externer CAPTCHA-Anbieter (#258-Hooks). Beide gängigen Anbieter
        // rendern ihr Widget in einem IFRAME und rufen von dort auf ihre eigene
        // Herkunft zurück - sie brauchen deshalb frame-src, script-src UND
        // connect-src. Nur script-src zu erweitern (wie TRACKING_DOMAINS es
        // täte) lädt das Skript und scheitert danach am Rahmen: Das Widget
        // bleibt leer, ohne Fehlermeldung im Formular.
        $captchaConfigured = defined('CAPTCHA_DOMAINS') ? (string)constant('CAPTCHA_DOMAINS') : '';
        $captcha = $captchaConfigured !== '' ? ' ' . str_replace(',', ' ', $captchaConfigured) : '';

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
            "script-src 'self' 'unsafe-inline'" . $tracking . $captcha,
            // Keine Freigabe für fonts.googleapis.com/fonts.gstatic.com mehr:
            // Die Schrift kommt seit dem Entfernen des externen Stylesheets
            // aus dem System-Stack (siehe src/Views/layout.php). Eine
            // Freigabe, die niemand mehr braucht, ist eine offene Tür ohne
            // Zweck - und sie stünde ausgerechnet in style-src, das wegen
            // 'unsafe-inline' ohnehin die schwächste Direktive hier ist.
            "style-src 'self' 'unsafe-inline'",
            "font-src 'self'",
            "img-src 'self' data:" . $tracking,
            "connect-src 'self'" . $tracking . $captcha,
            // frame-src wird jetzt AUSDRÜCKLICH gesetzt. Ohne die Direktive
            // greift der Rückfall auf default-src 'self' - dieselbe Wirkung,
            // aber unsichtbar: Wer die Policy liest, sieht nicht, dass fremde
            // Rahmen gesperrt sind, und sucht den Fehler anderswo.
            "frame-src 'self'" . $captcha,
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            'frame-ancestors ' . $ancestors,
        ]);
    }
}
