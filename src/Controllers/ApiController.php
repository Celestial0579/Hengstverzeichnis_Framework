<?php
// src/Controllers/ApiController.php

namespace App\Controllers;

use App\Database;
use App\Helper\Paginator;
use App\Security\ApiKey;

/**
 * Class ApiController
 *
 * Schlanke Read-only-JSON-API für Katalogdaten (#47). Liefert ausschließlich
 * Felder, die bereits über den öffentlichen HTML-Katalog
 * (`PublicController::catalog()`/`horseDetail()`) einsehbar sind - dieselbe
 * Sichtbarkeit wird auch hier erzwungen: nur veröffentlichte Pferde
 * (is_published).
 *
 * Zugriff NUR mit gültigem API-Schlüssel (siehe App\Security\ApiKey und
 * docs/api.md): kein anonymer Zugriff, Schlüssel ausschließlich über den
 * `Authorization: Bearer ...`-Header. Bewusst kein `?api_key=`-Fallback -
 * Query-Parameter landen in Server-Logs, Referrern und Browser-History, exakt
 * die Begründung, aus der auch das Cron-Secret nur noch per Header akzeptiert
 * wird (#114).
 *
 * Rechte: Ein Schlüssel darf höchstens das, was sein Besitzer aktuell selbst
 * darf (live geprüfte Schnittmenge aus dessen Gruppenrechten und dem Scope des
 * Schlüssels, siehe ApiKey::permits()). Damit ist auch abgesichert, was
 * passiert, wenn hier künftig zusätzliche - womöglich nicht-öffentliche -
 * Felder oder Endpunkte hinzukommen: sie sind automatisch an ein konkretes
 * Recht des Schlüsselbesitzers gebunden, statt implizit für alle offen zu sein.
 */
class ApiController extends BaseController {

    /**
     * Der authentifizierte Schlüssel des aktuellen Requests.
     *
     * @var array{id: int, user_id: int, scope: array<int, string>|null}|null
     */
    private ?array $apiKey = null;

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
        $this->requireApiKey();

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
        $this->requireApiKey();

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
     * Erzwingt einen gültigen API-Schlüssel. Bricht den Request andernfalls mit
     * 401 ab - bewusst mit identischer Antwort für "kein Header" und
     * "ungültiger/widerrufener Schlüssel", damit die API kein Orakel dafür
     * wird, welche Schlüsselwerte existieren.
     */
    private function requireApiKey(): void {
        $token = self::readBearerToken();

        if ($token !== null) {
            $key = ApiKey::authenticate($token);
            if ($key !== null) {
                $this->apiKey = $key;
                return;
            }
        }

        header('WWW-Authenticate: Bearer realm="api"');
        $this->respondJson([
            'error' => 'unauthorized',
            'message' => 'Gültiger API-Schlüssel erforderlich: Header "Authorization: Bearer <Schlüssel>". Schlüssel werden unter /api-keys verwaltet.',
        ], 401);
    }

    /**
     * Liest den Schlüssel aus dem Authorization-Header. getallheaders() steht
     * je nach SAPI nicht zur Verfügung, deshalb zusätzlich der von PHP/Apache
     * gefüllte $_SERVER-Weg (HTTP_AUTHORIZATION bzw. das von manchen
     * Konfigurationen genutzte REDIRECT_HTTP_AUTHORIZATION).
     */
    private static function readBearerToken(): ?string {
        $header = '';

        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $header = (string)$value;
                    break;
                }
            }
        }

        if ($header === '') {
            $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        }

        if ($header === '' || !preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $matches)) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Effektive Rechteprüfung für den authentifizierten Schlüssel: Scope UND
     * aktuelle Rechte des Besitzers müssen die Aktion erlauben. Ersetzt in
     * diesem Controller bewusst BaseController::hasPermission() - dort hängt
     * die Prüfung an der Session, die es bei einem API-Zugriff nicht gibt.
     */
    private function apiCan(string $module, string $action): bool {
        return $this->apiKey !== null && ApiKey::permits($this->apiKey, $module, $action);
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
        // Sichtbarkeit wie im öffentlichen HTML-Katalog - zusätzlich an die
        // Rechte des Schlüssels gebunden: ohne horses.view (beim Besitzer UND
        // im Scope) liefert die API nichts, und es werden ausschließlich
        // veröffentlichte Pferde (is_published) ausgegeben - unabhängig vom
        // Lebenszyklus-Status.
        if (!$this->apiCan('horses', 'view')) {
            return [];
        }

        $db = Database::getInstance();

        [$whereSql, $bindings] = $this->buildFilters($params);

        // Pagination direkt in SQL (#125); Züchter/Besitzer aggregiert statt
        // über multiplizierende JOINs - ein Pferd mit mehreren Besitzern erzeugt
        // so genau EINEN API-Datensatz - und nur veröffentlichte Personen (#121).
        $limitSql = $limit !== null ? "LIMIT ? OFFSET ?" : "";

        // Denormalisierte Kopie des Stationsnamens unterdrücken, wenn die Station
        // öffentlich nicht sichtbar ist: der bs-JOIN unten ist auf is_published = 1 AND
        // deleted_at IS NULL eingeschränkt, "bs.id IS NULL" heißt dort also exakt: nicht
        // öffentlich sichtbar. Ohne das gäbe die API den Namen unveröffentlichter
        // Stationen über den Fallback station_name ?: breeding_station heraus, obwohl
        // deren Kontaktdaten korrekt ausgeblendet sind (#151/#122). Freitext ohne
        // Stations-Datensatz hat keine breeding_station_id und bleibt erhalten.
        $stmt = $db->prepare("
            SELECT
                h.id, h.name, h.ueln, h.foreign_ueln, h.birth_year, h.birth_date, h.color, h.sex, h.breed, h.height_cm, h.status, h.is_deceased, h.death_year, h.image_url,
                CASE WHEN h.breeding_station_id IS NOT NULL AND bs.id IS NULL
                     THEN NULL ELSE h.breeding_station END AS breeding_station,
                bs.name AS station_name,
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
        $paramIndex = 1;
        foreach ($bindings as $value) {
            $stmt->bindValue($paramIndex++, $value);
        }
        if ($limit !== null) {
            $stmt->bindValue($paramIndex++, $limit, \PDO::PARAM_INT);
            $stmt->bindValue($paramIndex, $offset, \PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Gesamtanzahl der Treffer für die Pagination-Metadaten - dieselben Filter
     * wie fetchHorses(), aber ohne die Personen-Aggregation (#125).
     */
    private function countHorses(array $params): int {
        if (!$this->apiCan('horses', 'view')) {
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

        // Seit #246 wird überall dort, wo ueln/foreign_ueln durchsucht wird,
        // auch die Kindtabelle horse_registrations (weitere Lebensnummern)
        // einbezogen - foreign_ueln bleibt als Kompatibilitäts-Fallback dabei.
        $search = trim((string)($params['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where[] = "(h.name LIKE ? OR h.ueln LIKE ? OR h.foreign_ueln LIKE ? OR EXISTS (SELECT 1 FROM horse_registrations hreg WHERE hreg.horse_id = h.id AND hreg.registration_number LIKE ?))";
            array_push($bindings, $like, $like, $like, $like);
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
            $where[] = "(h.ueln = ? OR h.foreign_ueln = ? OR EXISTS (SELECT 1 FROM horse_registrations hreg WHERE hreg.horse_id = h.id AND hreg.registration_number = ?))";
            array_push($bindings, $uelnExact, $uelnExact, $uelnExact);
        }

        $ueln = trim((string)($params['ueln'] ?? ''));
        if ($ueln !== '') {
            $where[] = "(h.ueln LIKE ? OR h.foreign_ueln LIKE ? OR EXISTS (SELECT 1 FROM horse_registrations hreg WHERE hreg.horse_id = h.id AND hreg.registration_number LIKE ?))";
            $like = '%' . $ueln . '%';
            array_push($bindings, $like, $like, $like);
        }

        $color = trim((string)($params['color'] ?? ''));
        if ($color !== '') {
            $where[] = "h.color LIKE ?";
            $bindings[] = '%' . $color . '%';
        }

        // Zuchtstatus seit dem Split (#188) nur noch active/inactive; der frühere
        // Wert 'deceased' fällt wie jeder unbekannte Wert aus der Whitelist
        // (= kein Filter). Der Lebensstatus hat den eigenen Parameter 'deceased'.
        $status = trim((string)($params['status'] ?? ''));
        if (in_array($status, ['active', 'inactive'], true)) {
            $where[] = "h.status = ?";
            $bindings[] = $status;
        }

        $deceased = trim((string)($params['deceased'] ?? ''));
        if ($deceased === '0' || $deceased === '1') {
            $where[] = "h.is_deceased = ?";
            $bindings[] = (int)$deceased;
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
            'birth_date' => $row['birth_date'],
            'color' => $row['color'],
            'sex' => $row['sex'],
            'breed' => $row['breed'],
            'height_cm' => $row['height_cm'] !== null ? (int)$row['height_cm'] : null,
            'status' => $row['status'],
            'is_deceased' => (bool)$row['is_deceased'],
            'death_year' => $row['death_year'] !== null ? (int)$row['death_year'] : null,
            'image_url' => $row['image_url'],
            'breeding_station' => $row['station_name'] ?: $row['breeding_station'],
            'sire' => $sireName ? ['name' => $sireName, 'ueln' => $row['linked_sire_ueln'] ?: $row['unlinked_sire_ueln']] : null,
            'dam' => $damName ? ['name' => $damName, 'ueln' => $row['linked_dam_ueln'] ?: $row['unlinked_dam_ueln']] : null,
            'breeder' => $row['breeder_name'],
            'owner' => $row['owner_name'],
            'profile_url' => '/horse?id=' . (int)$row['id'],
        ];
    }

    /**
     * Einheitliche JSON-Antwort inkl. Content-Type.
     *
     * Bewusst OHNE `Access-Control-Allow-Origin: *`: Seit die API einen
     * Schlüssel verlangt, wäre ein Wildcard-CORS-Header eine Einladung, den
     * Schlüssel in Browser-JavaScript einzubetten - dort ist er für jeden
     * Besucher auslesbar. Serverseitige Aufrufe (der vorgesehene Weg für
     * Drittsysteme) unterliegen keiner Same-Origin-Policy und sind davon nicht
     * betroffen. Wer die API wirklich aus dem Browser heraus nutzen will,
     * sollte sie hinter einem eigenen Backend-Proxy kapseln, statt den
     * Schlüssel auszuliefern.
     *
     * `Cache-Control: no-store` verhindert, dass rechtegebundene Antworten in
     * gemeinsam genutzten Caches (Proxys) landen und dort von jemandem gelesen
     * werden, dessen Schlüssel weniger darf.
     *
     * @param array<string, mixed> $payload
     */
    private function respondJson(array $payload, int $statusCode = 200): void {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
