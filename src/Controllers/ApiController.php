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
        $horses = $this->fetchHorses($_GET);

        $perPage = Paginator::readPerPage($_GET, 50);
        $result = Paginator::paginate($horses, $perPage, (int)($_GET['page'] ?? 1));

        $this->respondJson([
            'data' => array_map([$this, 'transform'], $result['items']),
            'meta' => [
                'page' => $result['page'],
                'per_page' => $perPage,
                'total_pages' => $result['totalPages'],
                'total' => $result['total'],
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
    private function fetchHorses(array $params): array {
        // Dieselbe Sichtbarkeit wie der öffentliche HTML-Katalog: Gäste ohne
        // horses.view sehen nichts, und es werden ausschließlich veröffentlichte
        // Pferde (is_published) ausgeliefert - unabhängig vom Lebenszyklus-Status.
        if (!$this->hasPermission('horses', 'view')) {
            return [];
        }

        $db = Database::getInstance();

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

        $whereSql = implode(' AND ', $where);

        $stmt = $db->prepare("
            SELECT
                h.id, h.name, h.ueln, h.foreign_ueln, h.birth_year, h.color, h.status, h.image_url,
                h.breeding_station, bs.name AS station_name,
                sire.name AS linked_sire_name, sire.ueln AS linked_sire_ueln,
                h.sire_name AS unlinked_sire_name, h.sire_ueln AS unlinked_sire_ueln,
                dam.name AS linked_dam_name, dam.ueln AS linked_dam_ueln,
                h.dam_name AS unlinked_dam_name, h.dam_ueln AS unlinked_dam_ueln,
                p_breeder.name AS breeder_name, p_owner.name AS owner_name
            FROM horses h
            LEFT JOIN breeding_stations bs ON h.breeding_station_id = bs.id AND bs.deleted_at IS NULL
            LEFT JOIN horses sire ON h.sire_id = sire.id AND sire.deleted_at IS NULL AND sire.is_published = 1
            LEFT JOIN horses dam ON h.dam_id = dam.id AND dam.deleted_at IS NULL AND dam.is_published = 1
            LEFT JOIN horse_persons hp_breeder ON hp_breeder.horse_id = h.id AND hp_breeder.role = 'breeder'
            LEFT JOIN persons p_breeder ON hp_breeder.person_id = p_breeder.id AND p_breeder.deleted_at IS NULL
            LEFT JOIN horse_persons hp_owner ON hp_owner.horse_id = h.id AND hp_owner.role = 'owner'
            LEFT JOIN persons p_owner ON hp_owner.person_id = p_owner.id AND p_owner.deleted_at IS NULL
            WHERE {$whereSql}
            ORDER BY h.name ASC
        ");
        $stmt->execute($bindings);
        return $stmt->fetchAll();
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
