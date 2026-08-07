<?php
// src/Controllers/PublicController.php

namespace App\Controllers;

use App\Database;

class PublicController extends BaseController {

    public function index(): void {
        // Fetch some recent or featured horses for the homepage - nur veröffentlichte
        // (is_published), und nur wenn die Gast-Gruppe Pferde überhaupt sehen darf
        // (horses.view). Sichtbarkeit hängt bewusst NICHT mehr am Lebenszyklus-Status.
        $db = Database::getInstance();
        $featuredHorses = [];
        if ($this->hasPermission('horses', 'view')) {
            $stmt = $db->query("SELECT id, name, color, status, image_url FROM horses WHERE is_published = 1 AND deleted_at IS NULL ORDER BY id DESC LIMIT 3");
            $featuredHorses = $stmt->fetchAll();
        }

        $this->render('public_home', [
            'title' => \App\I18n\Translator::t('meta.title_home') . ' - ' . ($this->settings['site_name'] ?? 'Hengstverzeichnis'),
            'featuredHorses' => $featuredHorses
        ]);
    }

    /** Kartengröße je Katalogseite (SQL-seitige Pagination, #125). */
    private const CATALOG_PER_PAGE = 24;

    public function catalog(): void {
        $db = Database::getInstance();

        // Query parameters
        $search = trim($_GET['search'] ?? '');
        $qName = trim($_GET['q_name'] ?? '');
        $qUeln = trim($_GET['q_ueln'] ?? '');
        $birthYearFrom = !empty($_GET['birth_year_from']) ? (int)$_GET['birth_year_from'] : null;
        $birthYearTo = !empty($_GET['birth_year_to']) ? (int)$_GET['birth_year_to'] : null;
        $qColor = trim($_GET['q_color'] ?? '');
        $qStatus = trim($_GET['q_status'] ?? '');
        $qBreeder = trim($_GET['q_breeder'] ?? '');
        $qOwner = trim($_GET['q_owner'] ?? '');
        $qStation = trim($_GET['q_station'] ?? '');
        $qSire = trim($_GET['q_sire'] ?? '');
        $qDam = trim($_GET['q_dam'] ?? '');

        // Öffentliche Sichtbarkeit: nur veröffentlichte Pferde (is_published),
        // unabhängig vom Lebenszyklus-Status. Ob Gäste den Bereich überhaupt sehen
        // dürfen, entscheidet zusätzlich die Leseberechtigung der Gast-Gruppe (siehe
        // $canViewHorses unten).
        $where = ["h.deleted_at IS NULL", "h.is_published = 1"];
        $params = [];

        // General search term across horse name, ueln, foreign_ueln, sire, dam, station, breeder, owner.
        // Personen über EXISTS statt über die früheren p_breeder/p_owner-JOINs
        // (siehe unten, #125) und nur veröffentlichte Personen (#121).
        if (!empty($search)) {
            $like = '%' . $search . '%';
            $where[] = "(
                h.name LIKE ? OR
                h.ueln LIKE ? OR
                h.foreign_ueln LIKE ? OR
                h.sire_name LIKE ? OR
                h.sire_ueln LIKE ? OR
                h.dam_name LIKE ? OR
                h.dam_ueln LIKE ? OR
                sire.name LIKE ? OR
                sire.ueln LIKE ? OR
                sire.foreign_ueln LIKE ? OR
                dam.name LIKE ? OR
                dam.ueln LIKE ? OR
                dam.foreign_ueln LIKE ? OR
                bs.name LIKE ? OR
                h.breeding_station LIKE ? OR
                EXISTS (
                    SELECT 1 FROM horse_persons hps
                    JOIN persons ps ON ps.id = hps.person_id AND ps.deleted_at IS NULL AND ps.is_published = 1
                    WHERE hps.horse_id = h.id AND ps.name LIKE ?
                )
            )";
            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like);
        }

        if (!empty($qName)) {
            $where[] = "h.name LIKE ?";
            $params[] = '%' . $qName . '%';
        }

        if (!empty($qUeln)) {
            $like = '%' . $qUeln . '%';
            $where[] = "(h.ueln LIKE ? OR h.foreign_ueln LIKE ? OR h.sire_ueln LIKE ? OR h.dam_ueln LIKE ? OR sire.ueln LIKE ? OR sire.foreign_ueln LIKE ? OR dam.ueln LIKE ? OR dam.foreign_ueln LIKE ?)";
            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
        }

        if ($birthYearFrom !== null) {
            $where[] = "h.birth_year >= ?";
            $params[] = $birthYearFrom;
        }

        if ($birthYearTo !== null) {
            $where[] = "h.birth_year <= ?";
            $params[] = $birthYearTo;
        }

        if (!empty($qColor)) {
            $where[] = "h.color LIKE ?";
            $params[] = '%' . $qColor . '%';
        }

        if (!empty($qStatus)) {
            $where[] = "h.status = ?";
            $params[] = $qStatus;
        }

        // Züchter-/Besitzer-Filter nur gegen veröffentlichte, nicht gelöschte
        // Personen - sonst bleibt der Filter ein Existenz-Orakel für
        // unveröffentlichte Personennamen (#121).
        if (!empty($qBreeder)) {
            $where[] = "EXISTS (
                SELECT 1 FROM horse_persons hpb
                JOIN persons pb ON pb.id = hpb.person_id AND pb.deleted_at IS NULL AND pb.is_published = 1
                WHERE hpb.horse_id = h.id AND hpb.role = 'breeder' AND pb.name LIKE ?
            )";
            $params[] = '%' . $qBreeder . '%';
        }

        if (!empty($qOwner)) {
            $where[] = "EXISTS (
                SELECT 1 FROM horse_persons hpo
                JOIN persons po ON po.id = hpo.person_id AND po.deleted_at IS NULL AND po.is_published = 1
                WHERE hpo.horse_id = h.id AND hpo.role = 'owner' AND po.name LIKE ?
            )";
            $params[] = '%' . $qOwner . '%';
        }

        if (!empty($qStation)) {
            $where[] = "(bs.name LIKE ? OR h.breeding_station LIKE ?)";
            $params[] = '%' . $qStation . '%';
            $params[] = '%' . $qStation . '%';
        }

        if (!empty($qSire)) {
            $where[] = "(sire.name LIKE ? OR h.sire_name LIKE ?)";
            $params[] = '%' . $qSire . '%';
            $params[] = '%' . $qSire . '%';
        }

        if (!empty($qDam)) {
            $where[] = "(dam.name LIKE ? OR h.dam_name LIKE ?)";
            $params[] = '%' . $qDam . '%';
            $params[] = '%' . $qDam . '%';
        }

        $whereSql = implode(' AND ', $where);

        // Basis-JOINs, die sowohl die COUNT- als auch die Daten-Query brauchen
        // (bs/sire/dam werden in den WHERE-Filtern referenziert). Die Deckstation
        // nur, wenn veröffentlicht und nicht gelöscht (#122); alle JOINs sind 1:1,
        // daher keine Zeilen-Vervielfachung mehr und kein DISTINCT nötig (#125).
        $joinSql = "
            FROM horses h
            LEFT JOIN breeding_stations bs ON h.breeding_station_id = bs.id AND bs.deleted_at IS NULL AND bs.is_published = 1
            LEFT JOIN horses sire ON h.sire_id = sire.id AND sire.deleted_at IS NULL AND sire.is_published = 1
            LEFT JOIN horses dam ON h.dam_id = dam.id AND dam.deleted_at IS NULL AND dam.is_published = 1
        ";

        // Züchter/Besitzer aggregiert statt über multiplizierende JOINs (#125):
        // ein Pferd mit mehreren Züchtern/Besitzern erzeugt so genau EINE
        // Katalogkarte. Nur veröffentlichte, nicht gelöschte Personen (#121).
        $personAggregateJoin = "
            LEFT JOIN (
                SELECT hp.horse_id,
                       GROUP_CONCAT(DISTINCT CASE WHEN hp.role = 'breeder' THEN p.name END SEPARATOR ', ') AS breeder_name,
                       GROUP_CONCAT(DISTINCT CASE WHEN hp.role = 'owner' THEN p.name END SEPARATOR ', ') AS owner_name
                FROM horse_persons hp
                JOIN persons p ON p.id = hp.person_id AND p.deleted_at IS NULL AND p.is_published = 1
                GROUP BY hp.horse_id
            ) hpx ON hpx.horse_id = h.id
        ";

        // Gast-Gruppe ohne horses.view sieht keinerlei Pferde (leerer Katalog),
        // sonst würde die Rechte-Entziehung wirkungslos bleiben.
        $horses = [];
        $totalHorses = 0;
        $totalPages = 1;
        $page = max(1, (int)($_GET['page'] ?? 1));

        if ($this->hasPermission('horses', 'view')) {
            // Echte SQL-Pagination statt "alle Treffer laden" (#125).
            $countStmt = $db->prepare("SELECT COUNT(*) {$joinSql} WHERE {$whereSql}");
            $countStmt->execute($params);
            $totalHorses = (int)$countStmt->fetchColumn();

            $totalPages = max(1, (int)ceil($totalHorses / self::CATALOG_PER_PAGE));
            $page = min($page, $totalPages);
            $offset = ($page - 1) * self::CATALOG_PER_PAGE;

            $sql = "
                SELECT
                    h.id, h.name, h.ueln, h.foreign_ueln, h.birth_year, h.color, h.status, h.image_url, h.breeding_station,
                    bs.name as station_name,
                    sire.name as linked_sire_name, h.sire_name as unlinked_sire_name,
                    dam.name as linked_dam_name, h.dam_name as unlinked_dam_name,
                    hpx.breeder_name,
                    hpx.owner_name
                {$joinSql}
                {$personAggregateJoin}
                WHERE {$whereSql}
                ORDER BY h.name ASC
                LIMIT " . self::CATALOG_PER_PAGE . " OFFSET {$offset}
            ";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $horses = $stmt->fetchAll();
        }

        // Query-String der aktiven Filter (ohne page) für die Pagination-Links.
        $filterParams = $_GET;
        unset($filterParams['page'], $filterParams['ajax']);
        $filterParams = array_filter($filterParams, fn($v) => $v !== '' && $v !== null);
        $catalogPagination = [
            'page' => $page,
            'totalPages' => $totalPages,
            'query' => http_build_query($filterParams),
        ];

        // Fetch distinct filter options for dropdowns
        $colors = $db->query("SELECT DISTINCT color FROM horses WHERE color IS NOT NULL AND color != '' AND deleted_at IS NULL ORDER BY color ASC")->fetchAll(\PDO::FETCH_COLUMN);
        // Nur veröffentlichte Stationen/Personen als öffentliche Filteroptionen anbieten
        // (is_published), konsistent mit der Sichtbarkeit im übrigen öffentlichen Bereich.
        $stations = $db->query("SELECT DISTINCT name FROM breeding_stations WHERE deleted_at IS NULL AND is_published = 1 ORDER BY name ASC")->fetchAll(\PDO::FETCH_COLUMN);
        $persons = $db->query("SELECT DISTINCT name FROM persons WHERE deleted_at IS NULL AND is_published = 1 ORDER BY name ASC")->fetchAll(\PDO::FETCH_COLUMN);

        // Plugin-Hook (#56, #97): Erweiterungspunkt für zusätzlichen Inhalt je Katalog-Karte
        // (z. B. ein "Merken"-Button), analog zu horse.detail_sections auf der Detailseite.
        // Pro Pferd im Controller vorberechnet (statt in der View aufgerufen), damit Views
        // wie im gesamten Kern üblich keine eigene Hook-Logik enthalten (siehe
        // horse.detail_sections). Indiziert nach Pferde-ID, da beide Rendering-Pfade
        // (normal + AJAX) dieselbe public_catalog_cards.php-Schleife durchlaufen.
        $cardSections = [];
        foreach ($horses as $horse) {
            $cardSections[$horse['id']] = $this->hooks()->applyFilters('catalog.card_sections', [], $horse);
        }

        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_GET['ajax']);

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            ob_start();
            include __DIR__ . '/../Views/public_catalog_cards.php';
            $cardsHtml = ob_get_clean();

            echo json_encode([
                'success' => true,
                'count' => $totalHorses,
                'count_text' => \App\I18n\Translator::t($totalHorses === 1 ? 'catalog.hit_count_one' : 'catalog.hit_count_other', ['count' => $totalHorses]),
                'cards_html' => $cardsHtml
            ]);
            exit;
        }

        $this->render('public_catalog', [
            'title' => \App\I18n\Translator::t('meta.title_catalog') . ' - ' . ($this->settings['site_name'] ?? 'Hengstverzeichnis'),
            'horses' => $horses,
            'totalHorses' => $totalHorses,
            'filters' => $_GET,
            'colors' => $colors,
            'stations' => $stations,
            'persons' => $persons,
            'cardSections' => $cardSections,
            'catalogPagination' => $catalogPagination
        ]);
    }

    public function horseDetail(): void {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            header("Location: /katalog");
            exit;
        }

        // Öffentliche Detailseite: nur veröffentlichte Pferde (is_published) und nur,
        // wenn die Gast-Gruppe Pferde sehen darf (horses.view). Andernfalls wie ein
        // nicht existierendes Pferd behandeln, um keine Rückschlüsse zu ermöglichen.
        if (!$this->hasPermission('horses', 'view')) {
            $this->renderNotFound(\App\I18n\Translator::t('horse.not_found'));
        }

        // Deckstation nur, wenn veröffentlicht und nicht gelöscht - sonst wären
        // Kontaktdaten unveröffentlichter/gelöschter Stationen über die
        // Pferde-Detailseite abrufbar, obwohl /station?id=... korrekt 404 liefert (#122).
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT h.*, bs.name as station_name, bs.contact_person as station_contact, bs.address as station_address, bs.phone as station_phone, bs.email as station_email, bs.website as station_website
            FROM horses h
            LEFT JOIN breeding_stations bs ON h.breeding_station_id = bs.id AND bs.deleted_at IS NULL AND bs.is_published = 1
            WHERE h.id = ? AND h.deleted_at IS NULL AND h.is_published = 1
        ");
        $stmt->execute([$id]);
        $horse = $stmt->fetch();

        if (!$horse) {
            $this->renderNotFound(\App\I18n\Translator::t('horse.not_found'));
        }

        // Stations-Kontaktblock zusätzlich nur rendern, wenn Gäste Deckstationen
        // überhaupt sehen dürfen - analog zur eigenen Stationsroute stationDetail() (#122).
        if (!$this->hasPermission('breeding_stations', 'view')) {
            foreach (['station_name', 'station_contact', 'station_address', 'station_phone', 'station_email', 'station_website'] as $stationField) {
                $horse[$stationField] = null;
            }
        }

        // Fetch ownership history, person roles and associated breeding stations/studs.
        // Nur veröffentlichte Personen/Stationen (#121/#122) - unveröffentlichte
        // Namen und Kontaktdaten dürfen auf der öffentlichen Seite nicht erscheinen.
        $stmt = $db->prepare("
            SELECT hp.*, p.name as person_name, p.contact_info, bs.name as station_name, bs.id as station_id
            FROM horse_persons hp
            LEFT JOIN persons p ON hp.person_id = p.id AND p.deleted_at IS NULL AND p.is_published = 1
            LEFT JOIN breeding_stations bs ON hp.breeding_station_id = bs.id AND bs.deleted_at IS NULL AND bs.is_published = 1
            WHERE hp.horse_id = ?
            ORDER BY hp.from_year ASC, hp.id ASC
        ");
        $stmt->execute([$id]);
        $horsePersons = $stmt->fetchAll();

        // Zeilen, deren Person/Station nach den Sichtbarkeitsfiltern komplett
        // weggefallen ist, gar nicht erst an die View geben (leere Einträge).
        $horsePersons = array_values(array_filter($horsePersons, function ($hp) {
            return !empty($hp['person_name']) || !empty($hp['station_name']) || !empty($hp['breeding_station_text']);
        }));

        // Build 6-generation pedigree tree (#53) - die öffentliche Seite selbst
        // zeigt per Default weiterhin 3 Generationen an (JS-Umschalter bis 6),
        // die tieferen Ebenen werden serverseitig mitgeliefert, damit der
        // Generationswechsel ohne Nachladen rein clientseitig funktioniert.
        // publishedOnly = true: der öffentliche Stammbaum (und darauf aufbauende
        // Berechnungen wie ein Inzuchtkoeffizient) darf keine unveröffentlichten
        // Vorfahren einbeziehen - diese erscheinen nur als Platzhalter (siehe
        // PedigreeBuilder), damit aus unveröffentlichten Daten nichts hergeleitet wird.
        $pedigreeTree = \App\Service\PedigreeBuilder::build((int)$id, 6, true);

        // Plugin-Hook (#56): Erweiterungspunkt für einen zusätzlichen Abschnitt auf der
        // Pferde-Detailseite. Callbacks liefern bereits fertiges, selbst escapetes HTML
        // zurück (Filter-Rückgabewert wird in der View absichtlich unescaped ausgegeben,
        // siehe public_horse_detail.php) - Plugins sind für die eigene XSS-Vermeidung
        // verantwortlich, analog zum bestehenden $settings['tracking_code']-Muster.
        // Erhält zusätzlich den bereits berechneten Pedigree-Baum als vierten
        // Filter-Parameter, damit Plugins (z. B. Inzuchtkoeffizient, Pedigree-Export)
        // nicht dieselbe DB-Abfrage erneut ausführen müssen, wenn ihnen die
        // Standardtiefe genügt - für abweichende Tiefe steht ihnen unabhängig davon
        // \App\Service\PedigreeBuilder::build() direkt zur Verfügung.
        $pluginDetailSections = $this->hooks()->applyFilters('horse.detail_sections', [], $horse, $horsePersons, $pedigreeTree);

        $this->render('public_horse_detail', [
            'title' => $horse['name'] . ' - ' . \App\I18n\Translator::t('meta.title_horse_detail_suffix'),
            'horse' => $horse,
            'horsePersons' => $horsePersons,
            'pedigree' => $pedigreeTree,
            'pluginDetailSections' => $pluginDetailSections
        ]);
    }

    public function stationDetail(): void {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            header("Location: /katalog");
            exit;
        }

        // Öffentliche Stationsseite nur, wenn die Gast-Gruppe Deckstationen sehen darf.
        if (!$this->hasPermission('breeding_stations', 'view')) {
            $this->renderNotFound(\App\I18n\Translator::t('station.not_found'));
        }

        // Nur veröffentlichte Stationen (is_published) sind öffentlich erreichbar -
        // unveröffentlichte liefern wie ein fehlender Datensatz eine 404.
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM breeding_stations WHERE id = ? AND deleted_at IS NULL AND is_published = 1");
        $stmt->execute([$id]);
        $station = $stmt->fetch();

        if (!$station) {
            $this->renderNotFound(\App\I18n\Translator::t('station.not_found'));
        }

        // Zugeordnete Pferde nur, wenn Gäste Pferde sehen dürfen UND das jeweilige
        // Pferd veröffentlicht ist (Status ist für die Sichtbarkeit irrelevant).
        $horses = [];
        if ($this->hasPermission('horses', 'view')) {
            $stmt = $db->prepare("
                SELECT id, name, ueln, birth_year, color, status, image_url
                FROM horses
                WHERE breeding_station_id = ? AND deleted_at IS NULL AND is_published = 1
                ORDER BY name ASC
            ");
            $stmt->execute([$id]);
            $horses = $stmt->fetchAll();
        }

        $this->render('public_station_detail', [
            'title' => $station['name'] . ' - ' . \App\I18n\Translator::t('meta.title_station_detail_suffix'),
            'station' => $station,
            'horses' => $horses
        ]);
    }

    public function impressum(): void {
        $this->render('public_impressum', [
            'title' => \App\I18n\Translator::t('meta.title_impressum') . ' - ' . ($this->settings['site_name'] ?? 'Hengstverzeichnis')
        ]);
    }

    public function datenschutz(): void {
        $this->render('public_datenschutz', [
            'title' => \App\I18n\Translator::t('meta.title_datenschutz') . ' - ' . ($this->settings['site_name'] ?? 'Hengstverzeichnis')
        ]);
    }

    public function dsgvoForm(): void {
        $this->render('public_dsgvo', [
            'title' => \App\I18n\Translator::t('meta.title_dsgvo') . ' - ' . ($this->settings['site_name'] ?? 'Hengstverzeichnis')
        ]);
    }

    public function dsgvoSubmit(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden(\App\I18n\Translator::t('errors.csrf_invalid'));
        }

        // Nach Absender-IP begrenzen: Ohne diese Sperre könnte jeder Client unbegrenzt oft
        // eine echte Benachrichtigungs-E-Mail an den Admin auslösen sowie die
        // gdpr_requests-Tabelle mit Datenmüll fluten.
        $clientIp = \App\Security\ClientIp::resolve();
        if (\App\Security\RateLimiter::tooManyAttempts($clientIp, 'dsgvo_request')) {
            header("Location: /dsgvo?error=rate_limited");
            exit;
        }
        \App\Security\RateLimiter::recordAttempt($clientIp, 'dsgvo_request');

        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $type = $_POST['request_type'] ?? 'info';
        $message = trim($_POST['message'] ?? '');

        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL) && in_array($type, ['info', 'deletion'])) {
            $db = Database::getInstance();
            $stmt = $db->prepare("INSERT INTO gdpr_requests (name, email, request_type, message) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name ?: null, $email, $type, $message ?: null]);

            // Send Email Notification to Admin
            $mailer = new \App\Service\Mailer();
            $typeName = $type === 'deletion' ? 'Löschung / Anonymisierung von Daten (Art. 17 DSGVO)' : 'Auskunft über Daten (Art. 15 DSGVO)';
            $mailer->sendDsgvoNotification($email, $typeName, $message, $name);
        }

        header("Location: /dsgvo?success=1");
        exit;
    }
}
