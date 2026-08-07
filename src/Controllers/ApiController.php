<?php
// src/Controllers/ApiController.php

namespace App\Controllers;

use App\Database;
use App\Helper\Paginator;

/**
 * Class ApiController
 *
 * Schlanke, öffentliche Read-only-JSON-API für Katalogdaten (#47). Liefert
 * ausschließlich Felder, die bereits über den öffentlichen HTML-Katalog
 * (`PublicController::catalog()`/`horseDetail()`) einsehbar sind - dieselbe
 * Sichtbarkeit wird auch hier erzwungen: nur veröffentlichte Pferde
 * (is_published) und nur, wenn die Gast-Gruppe das Leserecht `horses.view`
 * besitzt (siehe fetchHorses()). Entzieht ein Admin der Gast-Gruppe dieses
 * Recht, liefert die API - wie der HTML-Katalog - keine Pferde mehr. Bewusst
 * ohne API-Key/Authentifizierung (siehe docs/api.md) - falls künftig
 * nicht-öffentliche Felder hinzukommen sollen, braucht das eine eigene Betrachtung.
 */
class ApiController extends BaseController {

    /**
     * GET /api/horses - Liste aller öffentlich sichtbaren Pferde, optional
     * gefiltert und paginiert (dieselben Grundsätze wie /admin-Listenseiten,
     * siehe App\Helper\Paginator).
     *
     * Unterstützte Query-Parameter:
     * - search: Volltextsuche über Name/UELN
     * - name, ueln, color, status: exakte Teilstring-/Wertfilter
     * - birth_year_from, birth_year_to: Geburtsjahr-Bereich
     * - page, per_page (10/25/50/100/all, Standard 50)
     */
    public function index(): void {
        // SQL-seitige Pagination (LIMIT/OFFSET + separate COUNT-Query) statt
        // "alles laden und in PHP zuschneiden" (#125): ?per_page=10 führt so
        // tatsächlich nur noch eine kleine Abfrage aus.
        $perPage = Paginator::readPerPage($_GET, 50);
        $total = $this->countHorses($_GET);

        if ($perPage === 'all') {
            $page = 1;
            $totalPages = 1;
            $horses = $this->fetchHorses($_GET);
        } else {
            $totalPages = max(1, (int)ceil($total / $perPage));
            $page = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
            $horses = $this->fetchHorses($_GET, $perPage, ($page - 1) * $perPage);
        }

        $this->respondJson([
            'data' => array_map([$this, 'transform'], $horses),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => $totalPages,
                'total' => $total,
            ],
        ]);
    }

    /**
     * GET /api/horses/show?ueln=... - Einzelnes Pferd über seine UELN
     * (Unique Equine Life Number). Aus Konsistenz mit dem restlichen Routing
     * des Kerns (App\Router unterstützt ausschließlich exakte Pfade, keine
     * Platzhalter-Segmente wie /api/horses/{ueln}) als Query-Parameter statt
     * Pfad-Segment umgesetzt.
     */
    public function show(): void {
        $ueln = trim($_GET['ueln'] ?? '');
        if ($ueln === '') {
            $this->respondJson(['error' => 'missing_ueln', 'message' => 'Query-Parameter "ueln" erforderlich.'], 400);
            return;
        }

        $horses = $this->fetchHorses(['q_ueln_exact' => $ueln]);
        if (empty($horses)) {
            $this->respondJson(['error' => 'not_found', 'message' => 'Kein Pferd mit dieser UELN gefunden.'], 404);
            return;
        }

        $this->respondJson(['data' => $this->transform($horses[0])]);
    }

    /**
     * Zentrale Abfrage für beide Endpunkte - bewusst ein eigener, schlanker
     * Filtersatz statt vollständiger Parität mit den vielen Detailfiltern von
     * PublicController::catalog() (dort insb. für die interaktive
     * Katalog-Seite gedacht, hier für einen ersten, dokumentierten API-Umfang
     * - siehe docs/api.md für die unterstützten Parameter, bei Bedarf später
     * erweiterbar).
     *
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private function fetchHorses(array $params, ?int $limit = null, int $offset = 0): array {
        // Dieselbe Sichtbarkeit wie der öffentliche HTML-Katalog: Gäste ohne
        // horses.view sehen nichts, und es werden ausschließlich veröffentlichte
        // Pferde (is_published) ausgeliefert - unabhängig vom Lebenszyklus-Status.
        if (!$this->hasPermission('horses', 'view')) {
            return [];
        }

        $db = Database::getInstance();

        [$whereSql, $bindings] = $this->buildFilters($params);

        // Pagination direkt in SQL (#125); Züchter/Besitzer aggregiert statt
        // über multiplizierende JOINs - ein Pferd mit mehreren Besitzern erzeugt
        // so genau EINEN API-Datensatz - und nur veröffentlichte Personen (#121).
        $limitSql = $limit !== null ? "LIMIT " . (int)$limit . " OFFSET " . (int)$offset : "";

        $stmt = $db->prepare("
            SELECT
                h.id, h.name, h.ueln, h.foreign_ueln, h.birth_year, h.color, h.status, h.image_url,
                h.breeding_station, bs.name AS station_name,
                sire.name AS linked_sire_name, sire.ueln AS linked_sire_ueln,
                h.sire_name AS unlinked_sire_name, h.sire_ueln AS unlinked_sire_ueln,
                dam.name AS linked_dam_name, dam.ueln AS linked_dam_ueln,
                h.dam_name AS unlinked_dam_name, h.dam_ueln AS unlinked_dam_ueln,
                hpx.breeder_name, hpx.owner_name
            FROM horses h
            LEFT JOIN breeding_stations bs ON h.breeding_station_id = bs.id AND bs.deleted_at IS NULL AND bs.is_published = 1
            LEFT JOIN horses sire ON h.sire_id = sire.id AND sire.deleted_at IS NULL AND sire.is_published = 1
            LEFT JOIN horses dam ON h.dam_id = dam.id AND dam.deleted_at IS NULL AND dam.is_published = 1
            LEFT JOIN (
                SELECT hp.horse_id,
                       GROUP_CONCAT(DISTINCT CASE WHEN hp.role = 'breeder' THEN p.name END SEPARATOR ', ') AS breeder_name,
                       GROUP_CONCAT(DISTINCT CASE WHEN hp.role = 'owner' THEN p.name END SEPARATOR ', ') AS owner_name
                FROM horse_persons hp
                JOIN persons p ON p.id = hp.person_id AND p.deleted_at IS NULL AND p.is_published = 1
                GROUP BY hp.horse_id
            ) hpx ON hpx.horse_id = h.id
            WHERE {$whereSql}
            ORDER BY h.name ASC
            {$limitSql}
        ");
        $stmt->execute($bindings);
        return $stmt->fetchAll();
    }

    /**
     * Gesamtanzahl der Treffer für die Pagination-Metadaten - dieselben Filter
     * wie fetchHorses(), aber ohne die Personen-Aggregation (#125).
     */
    private function countHorses(array $params): int {
        if (!$this->hasPermission('horses', 'view')) {
            return 0;
        }

        $db = Database::getInstance();
        [$whereSql, $bindings] = $this->buildFilters($params);

        $stmt = $db->prepare("SELECT COUNT(*) FROM horses h WHERE {$whereSql}");
        $stmt->execute($bindings);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Baut WHERE-Klausel + Parameter aus den unterstützten Query-Parametern -
     * gemeinsame Grundlage für fetchHorses() und countHorses().
     *
     * @param array<string, mixed> $params
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function buildFilters(array $params): array {
        $where = ["h.deleted_at IS NULL", "h.is_published = 1"];
        $bindings = [];

        $search = trim((string)($params['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where[] = "(h.name LIKE ? OR h.ueln LIKE ? OR h.foreign_ueln LIKE ?)";
            array_push($bindings, $like, $like, $like);
        }

        $name = trim((string)($params['name'] ?? ''));
        if ($name !== '') {
            $where[] = "h.name LIKE ?";
            $bindings[] = '%' . $name . '%';
        }

        // Exakte UELN-Übereinstimmung für show() - bewusst kein Teilstring-Match,
        // damit /api/horses/show?ueln=... nie mehrere Treffer liefern kann.
        $uelnExact = trim((string)($params['q_ueln_exact'] ?? ''));
        if ($uelnExact !== '') {
            $where[] = "(h.ueln = ? OR h.foreign_ueln = ?)";
            array_push($bindings, $uelnExact, $uelnExact);
        }

        $ueln = trim((string)($params['ueln'] ?? ''));
        if ($ueln !== '') {
            $where[] = "(h.ueln LIKE ? OR h.foreign_ueln LIKE ?)";
            $like = '%' . $ueln . '%';
            array_push($bindings, $like, $like);
        }

        $color = trim((string)($params['color'] ?? ''));
        if ($color !== '') {
            $where[] = "h.color LIKE ?";
            $bindings[] = '%' . $color . '%';
        }

        $status = trim((string)($params['status'] ?? ''));
        if (in_array($status, ['active', 'inactive', 'deceased'], true)) {
            $where[] = "h.status = ?";
            $bindings[] = $status;
        }

        if (!empty($params['birth_year_from'])) {
            $where[] = "h.birth_year >= ?";
            $bindings[] = (int)$params['birth_year_from'];
        }

        if (!empty($params['birth_year_to'])) {
            $where[] = "h.birth_year <= ?";
            $bindings[] = (int)$params['birth_year_to'];
        }

        return [implode(' AND ', $where), $bindings];
    }

    /**
     * Wandelt eine rohe DB-Zeile aus fetchHorses() in die öffentliche
     * API-Repräsentation um - eigene, stabile Feldbenennung statt der
     * internen SQL-Alias-Namen, damit interne Umbenennungen (z. B. der
     * Spalten) die API nicht brechen.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function transform(array $row): array {
        $sireName = $row['linked_sire_name'] ?: $row['unlinked_sire_name'];
        $damName = $row['linked_dam_name'] ?: $row['unlinked_dam_name'];

        return [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'ueln' => $row['ueln'],
            'foreign_ueln' => $row['foreign_ueln'],
            'birth_year' => $row['birth_year'] !== null ? (int)$row['birth_year'] : null,
            'color' => $row['color'],
            'status' => $row['status'],
            'image_url' => $row['image_url'],
            'breeding_station' => $row['station_name'] ?: $row['breeding_station'],
            'sire' => $sireName ? ['name' => $sireName, 'ueln' => $row['linked_sire_ueln'] ?: $row['unlinked_sire_ueln']] : null,
            'dam' => $damName ? ['name' => $damName, 'ueln' => $row['linked_dam_ueln'] ?: $row['unlinked_dam_ueln']] : null,
            'breeder' => $row['breeder_name'],
            'owner' => $row['owner_name'],
            'profile_url' => '/hengst?id=' . (int)$row['id'],
        ];
    }

    /**
     * Einheitliche JSON-Antwort inkl. Content-Type und (da rein lesend, ohne
     * Cookies/Session-Auth) permissivem CORS-Header - dieselben Daten sind
     * bereits über den öffentlichen HTML-Katalog crawlbar, ein zusätzlicher
     * CORS-Schutz brächte hier keinen echten Sicherheitsgewinn, würde aber
     * die Einbindung durch Drittsysteme (der eigentliche Zweck dieser API)
     * unnötig erschweren.
     *
     * @param array<string, mixed> $payload
     */
    private function respondJson(array $payload, int $statusCode = 200): void {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
