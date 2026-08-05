<?php
// src/Helper/Paginator.php

namespace App\Helper;

/**
 * Class Paginator
 *
 * Kleine Hilfsklasse für Suche + Seitengrößen-Auswahl (10/25/50/100/alle) in
 * Admin-Übersichtstabellen (z. B. Gruppen, Benutzer) - bewusst als einfache
 * Array-Operationen statt SQL-seitiger Pagination, da die betroffenen Listen
 * (Gruppen, Benutzer) in der Praxis klein bleiben und die aufrufenden
 * Controller die Datensätze ohnehin bereits vollständig laden (z. B. für
 * Dropdowns, die unabhängig von der Pagination immer vollständig bleiben
 * müssen, siehe GroupController::index()).
 */
final class Paginator {

    /** Erlaubte feste Seitengrößen (zusätzlich zu 'all'). */
    public const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    private function __construct() {}

    /**
     * Filtert eine Liste assoziativer Arrays per case-insensitiver Teilstring-Suche
     * über mehrere Felder (ODER-verknüpft: ein Treffer in irgendeinem Feld reicht).
     *
     * @param array<int, array<string, mixed>> $items
     * @param array<int, string> $fields
     * @return array<int, array<string, mixed>>
     */
    public static function search(array $items, string $search, array $fields): array {
        $search = trim($search);
        if ($search === '') {
            return $items;
        }

        return array_values(array_filter($items, function ($item) use ($search, $fields) {
            foreach ($fields as $field) {
                if (mb_stripos((string)($item[$field] ?? ''), $search) !== false) {
                    return true;
                }
            }
            return false;
        }));
    }

    /**
     * Liest den per_page-Parameter aus $params (z. B. $_GET) und normalisiert ihn
     * auf einen gültigen Wert aus PER_PAGE_OPTIONS oder 'all'.
     *
     * @param array<string, mixed> $params
     */
    public static function readPerPage(array $params, int $default = 25): int|string {
        $raw = $params['per_page'] ?? (string)$default;
        if ($raw === 'all') {
            return 'all';
        }
        $value = (int)$raw;
        return in_array($value, self::PER_PAGE_OPTIONS, true) ? $value : $default;
    }

    /**
     * Schneidet $items entsprechend Seitengröße/Seite zurecht.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, totalPages: int}
     */
    public static function paginate(array $items, int|string $perPage, int $requestedPage): array {
        $total = count($items);

        if ($perPage === 'all') {
            return ['items' => $items, 'total' => $total, 'page' => 1, 'totalPages' => 1];
        }

        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = max(1, min($totalPages, $requestedPage));
        $slice = array_slice($items, ($page - 1) * $perPage, $perPage);

        return ['items' => $slice, 'total' => $total, 'page' => $page, 'totalPages' => $totalPages];
    }
}
