<?php
// src/Helper/NavItems.php

namespace App\Helper;

/**
 * Prüft die Einträge, die Addons über den Filter `layout.nav_items` in die
 * öffentliche Navigation legen wollen, und wirft alles Unbrauchbare weg.
 *
 * WARUM eine eigene Prüfung und nicht einfach htmlspecialchars() in der View:
 * Escaping schützt den Textinhalt, nicht das href-Attribut. Ein Addon könnte
 * sonst `javascript:` oder eine fremde Domain in die Navigation JEDER Seite
 * legen - die Navigation ist die eine Stelle, die auf allen öffentlichen
 * Seiten steht, ein Fehler dort wirkt überall. Deshalb sind hier nur
 * seiteneigene, absolute Pfade zugelassen:
 *
 *   erlaubt    /plugin/zucht-suche  ·  /plugin/x?art=stationen
 *   abgelehnt  javascript:…  ·  https://fremd.example  ·  //fremd.example
 *              ·  ../raus  ·  plugin/ohne-schrägstrich
 *
 * `//fremd.example` ist der Fall, den man dabei übersieht: Es beginnt mit
 * einem Schrägstrich, ist aber eine protokollrelative Adresse und landet auf
 * einem fremden Host.
 *
 * Die Prüfung ist eine reine Funktion (kein Netz, keine DB), damit sie sich
 * isoliert festnageln lässt - dasselbe Muster wie bei
 * AddonUpdateService::resolveAutoUpdateRef() und ExternalUrl::hrefOrNull().
 */
final class NavItems {

    /** Deckel gegen eine Navigation, die zur Textwüste wird. */
    public const LABEL_MAX = 40;

    /**
     * Höchstzahl der von Addons ergänzten Einträge. Die Navigation ist kein
     * Menübaum; wer mehr braucht, baut eine eigene Übersichtsseite und
     * verlinkt die.
     */
    public const MAX_ITEMS = 5;

    private function __construct() {}

    /**
     * @param mixed $items Rückgabe des Filters - bewusst `mixed`, ein Addon
     *              kann alles zurückgeben, auch keinen Array.
     * @return array<int, array{url: string, label: string, icon: string}>
     */
    public static function sanitize(mixed $items): array {
        if (!is_array($items)) {
            return [];
        }

        $sauber = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $url = self::internalPath($item['url'] ?? null);
            if ($url === null) {
                continue;
            }

            $label = self::text($item['label'] ?? null, self::LABEL_MAX);
            if ($label === '') {
                continue;
            }

            // Das Symbol ist Schmuck: fehlt es oder ist es unbrauchbar, gibt
            // es das neutrale Puzzleteil - wie bei den Dashboard-Kacheln.
            $icon = self::text($item['icon'] ?? null, 8);

            $sauber[] = [
                'url' => $url,
                'label' => $label,
                'icon' => $icon !== '' ? $icon : '🧩',
            ];

            if (count($sauber) >= self::MAX_ITEMS) {
                break;
            }
        }

        return $sauber;
    }

    /**
     * Liefert den Pfad, wenn er seiteneigen und absolut ist, sonst null.
     */
    private static function internalPath(mixed $roh): ?string {
        if (!is_string($roh)) {
            return null;
        }
        $wert = trim($roh);

        // Steuerzeichen (auch Zeilenumbrüche) haben in einem Attribut nichts
        // verloren; ein Umbruch im href ist ein klassischer Filter-Trick.
        if ($wert === '' || preg_match('/[\x00-\x1F\x7F]/', $wert) === 1) {
            return null;
        }
        if ($wert[0] !== '/' || str_starts_with($wert, '//')) {
            return null;
        }
        // Kein Weg nach oben und kein Backslash (Windows-Pfadtrenner, den
        // manche Browser wie einen Schrägstrich behandeln).
        if (str_contains($wert, '..') || str_contains($wert, '\\')) {
            return null;
        }

        return $wert;
    }

    /** Einzeiliger, gekürzter Text - oder '' , wenn nichts Brauchbares kommt. */
    private static function text(mixed $roh, int $maxLaenge): string {
        if (!is_string($roh)) {
            return '';
        }
        $wert = trim(preg_replace('/\s+/u', ' ', $roh) ?? '');
        return mb_substr($wert, 0, $maxLaenge);
    }

    /**
     * Ist dieser Eintrag der gerade offene? Verglichen wird nur der Pfad,
     * ohne Query - `/plugin/zucht-suche?art=stationen` ist derselbe
     * Menüpunkt wie `/plugin/zucht-suche`.
     */
    public static function isActive(string $itemUrl, string $currentPath): bool {
        $pfad = strtok($itemUrl, '?');
        return $pfad !== false && $pfad === $currentPath;
    }
}
