<?php
// src/Security/FrameGuard.php

namespace App\Security;

/**
 * Gezielte Lockerung der Frame-Sperre für Embed-Ansichten (#260).
 *
 * Der Kern blockiert das Einbetten grundsätzlich - `X-Frame-Options: SAMEORIGIN`
 * und `frame-ancestors 'self'` (config/config.php). Das ist Clickjacking-Schutz
 * und bleibt für jede normale Seite unangetastet.
 *
 * ZWEI DINGE, DIE HIER LEICHT ÜBERSEHEN WERDEN:
 *
 * 1. `X-Frame-Options` kann keine Allowlist ausdrücken. `ALLOW-FROM` ist
 *    zurückgezogen und wird von keinem aktuellen Browser mehr ausgewertet -
 *    ein Browser, der den Header kennt, sähe nur `SAMEORIGIN` und blockierte
 *    trotz Freigabe. Der Header muss für eine Embed-Antwort deshalb ENTFERNT
 *    werden; die Freigabe trägt allein `frame-ancestors`. Das ist kein
 *    Rückschritt: Jeder Browser, der CSP Level 2 beherrscht, ignoriert
 *    `X-Frame-Options` ohnehin zugunsten von `frame-ancestors`.
 *
 * 2. Deshalb darf `X-Frame-Options` NICHT zusätzlich in public/.htaccess
 *    gesetzt werden. Apache setzt seine `Header`-Direktiven nach PHP - ein
 *    `Header set` dort würde den entfernten Header wieder anfügen und die
 *    Freigabe still aufheben. Die Zeile ist mit #260 aus der .htaccess
 *    entfernt worden, PHP ist jetzt die einzige Quelle.
 *
 * OHNE KONFIGURATION PASSIERT NICHTS. `EMBED_ALLOWED_DOMAINS` ist im
 * Auslieferungszustand leer, und dann bleibt die Sperre auch für Embed-Routen
 * bestehen. Eine Freigabe ist eine bewusste Handlung des Betreibers, keine
 * Nebenwirkung davon, dass ein Addon eine Embed-Ansicht anbietet.
 */
final class FrameGuard {

    /**
     * Erlaubte einbettende Origins, leer wenn nichts freigegeben ist.
     *
     * @return array<int, string>
     */
    public static function allowedAncestors(): array {
        if (!defined('EMBED_ALLOWED_DOMAINS') || EMBED_ALLOWED_DOMAINS === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', EMBED_ALLOWED_DOMAINS))));
    }

    public static function embeddingIsConfigured(): bool {
        return self::allowedAncestors() !== [];
    }

    /**
     * Lockert die Frame-Sperre für die laufende Antwort - und nur für sie.
     * Ist nichts freigegeben, bleibt alles, wie es war.
     */
    public static function allowEmbedding(): void {
        if (headers_sent()) {
            return;
        }

        $ancestors = self::allowedAncestors();
        if ($ancestors === []) {
            return;
        }

        // Siehe Klassenkommentar: Der Header kann die Allowlist nicht abbilden
        // und würde die Freigabe überstimmen.
        header_remove('X-Frame-Options');
        header('Content-Security-Policy: ' . ContentSecurityPolicy::build($ancestors));
    }
}
