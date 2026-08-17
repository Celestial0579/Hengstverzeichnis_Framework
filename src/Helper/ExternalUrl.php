<?php
// src/Helper/ExternalUrl.php

namespace App\Helper;

/**
 * Prüft eine von Hand eingetragene Adresse, bevor sie als `href` in eine Seite
 * gerät.
 *
 * Der Anlass ist keine Theorie: Die Website-Felder von Personen (#293) und
 * Zuchtstationen sind bewusst Freitext ohne Formatprüfung - dieselbe
 * Philosophie wie bei Rasse und Land. Ausgegeben wurden sie bisher mit
 * `htmlspecialchars()` direkt als Verweisziel. Das reicht für den Attributwert,
 * sagt aber nichts über das Protokoll: `javascript:...` übersteht
 * `htmlspecialchars()` unverändert und wird beim Klick ausgeführt. Der Eintrag
 * stammt zwar aus dem Admin-Bereich, landet aber auf einer öffentlichen Seite -
 * ein Redakteurskonto genügte damit für gespeichertes JavaScript bei jedem
 * Besucher.
 *
 * Dieselbe Regel wendet App\Helper\Markdown auf Links im Fließtext schon an;
 * hier steht sie an einer Stelle, statt an jeder Ausgabestelle erneut
 * geschrieben zu werden.
 */
final class ExternalUrl {

    /** Nur diese Protokolle dürfen in ein Verweisziel. */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    private function __construct() {}

    /**
     * Liefert die Adresse, wenn sie sich gefahrlos verlinken lässt - sonst
     * null. Bewusst `null` statt Leerstring: Die Aufrufer sollen den Verweis
     * dann ganz weglassen, statt einen leeren zu erzeugen.
     */
    public static function hrefOrNull(?string $url): ?string {
        $url = trim((string)$url);
        if ($url === '') {
            return null;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            return null;
        }
        return $url;
    }
}
