<?php
// src/Controllers/PublicController.php

namespace App\Controllers;

use App\Database;

class PublicController extends BaseController {

    public function index(): void {
        // Fetch some recent or featured horses for the homepage
        $db = Database::getInstance();
        $stmt = $db->query("SELECT id, name, color, status, image_url FROM horses WHERE status = 'active' AND deleted_at IS NULL ORDER BY id DESC LIMIT 3");
        $featuredHorses = $stmt->fetchAll();

        $this->render('public_home', [
            'title' => \App\I18n\Translator::t('meta.title_home') . ' - ' . ($this->settings['site_name'] ?? 'Hengstverzeichnis'),
            'featuredHorses' => $featuredHorses
        ]);
    }

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

        $where = ["h.deleted_at IS NULL"];
        $params = [];

        // General search term across horse name, ueln, foreign_ueln, sire, dam, station, breeder, owner
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
                p_breeder.name LIKE ? OR 
                p_owner.name LIKE ?
            )";
            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like);
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

        if (!empty($qBreeder)) {
            $where[] = "p_breeder.name LIKE ?";
            $params[] = '%' . $qBreeder . '%';
        }

        if (!empty($qOwner)) {
            $where[] = "p_owner.name LIKE ?";
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

        $sql = "
            SELECT DISTINCT 
                h.id, h.name, h.ueln, h.foreign_ueln, h.birth_year, h.color, h.status, h.image_url, h.breeding_station,
                bs.name as station_name,
                sire.name as linked_sire_name, h.sire_name as unlinked_sire_name,
                dam.name as linked_dam_name, h.dam_name as unlinked_dam_name,
                p_breeder.name as breeder_name,
                p_owner.name as owner_name
            FROM horses h
            LEFT JOIN breeding_stations bs ON h.breeding_station_id = bs.id AND bs.deleted_at IS NULL
            LEFT JOIN horses sire ON h.sire_id = sire.id AND sire.deleted_at IS NULL
            LEFT JOIN horses dam ON h.dam_id = dam.id AND dam.deleted_at IS NULL
            LEFT JOIN horse_persons hp_breeder ON hp_breeder.horse_id = h.id AND hp_breeder.role = 'breeder'
            LEFT JOIN persons p_breeder ON hp_breeder.person_id = p_breeder.id AND p_breeder.deleted_at IS NULL
            LEFT JOIN horse_persons hp_owner ON hp_owner.horse_id = h.id AND hp_owner.role = 'owner'
            LEFT JOIN persons p_owner ON hp_owner.person_id = p_owner.id AND p_owner.deleted_at IS NULL
            WHERE {$whereSql}
            ORDER BY h.name ASC
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $horses = $stmt->fetchAll();

        // Fetch distinct filter options for dropdowns
        $colors = $db->query("SELECT DISTINCT color FROM horses WHERE color IS NOT NULL AND color != '' AND deleted_at IS NULL ORDER BY color ASC")->fetchAll(\PDO::FETCH_COLUMN);
        $stations = $db->query("SELECT DISTINCT name FROM breeding_stations WHERE deleted_at IS NULL ORDER BY name ASC")->fetchAll(\PDO::FETCH_COLUMN);
        $persons = $db->query("SELECT DISTINCT name FROM persons WHERE deleted_at IS NULL ORDER BY name ASC")->fetchAll(\PDO::FETCH_COLUMN);

        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_GET['ajax']);

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            ob_start();
            include __DIR__ . '/../Views/public_catalog_cards.php';
            $cardsHtml = ob_get_clean();

            echo json_encode([
                'success' => true,
                'count' => count($horses),
                'count_text' => \App\I18n\Translator::t(count($horses) === 1 ? 'catalog.hit_count_one' : 'catalog.hit_count_other', ['count' => count($horses)]),
                'cards_html' => $cardsHtml
            ]);
            exit;
        }

        $this->render('public_catalog', [
            'title' => \App\I18n\Translator::t('meta.title_catalog') . ' - ' . ($this->settings['site_name'] ?? 'Hengstverzeichnis'),
            'horses' => $horses,
            'filters' => $_GET,
            'colors' => $colors,
            'stations' => $stations,
            'persons' => $persons
        ]);
    }

    public function horseDetail(): void {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            header("Location: /katalog");
            exit;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT h.*, bs.name as station_name, bs.contact_person as station_contact, bs.address as station_address, bs.phone as station_phone, bs.email as station_email, bs.website as station_website 
            FROM horses h 
            LEFT JOIN breeding_stations bs ON h.breeding_station_id = bs.id 
            WHERE h.id = ? AND h.deleted_at IS NULL
        ");
        $stmt->execute([$id]);
        $horse = $stmt->fetch();

        if (!$horse) {
            $this->renderNotFound(\App\I18n\Translator::t('horse.not_found'));
        }

        // Fetch ownership history, person roles and associated breeding stations/studs
        $stmt = $db->prepare("
            SELECT hp.*, p.name as person_name, p.contact_info, bs.name as station_name, bs.id as station_id
            FROM horse_persons hp 
            LEFT JOIN persons p ON hp.person_id = p.id AND p.deleted_at IS NULL
            LEFT JOIN breeding_stations bs ON hp.breeding_station_id = bs.id AND bs.deleted_at IS NULL
            WHERE hp.horse_id = ?
            ORDER BY hp.from_year ASC, hp.id ASC
        ");
        $stmt->execute([$id]);
        $horsePersons = $stmt->fetchAll();

        // Build 4-generation pedigree tree
        $pedigreeTree = \App\Service\PedigreeBuilder::build((int)$id, 4);

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

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM breeding_stations WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$id]);
        $station = $stmt->fetch();

        if (!$station) {
            $this->renderNotFound(\App\I18n\Translator::t('station.not_found'));
        }

        $stmt = $db->prepare("
            SELECT id, name, ueln, birth_year, color, status, image_url
            FROM horses
            WHERE breeding_station_id = ? AND deleted_at IS NULL
            ORDER BY name ASC
        ");
        $stmt->execute([$id]);
        $horses = $stmt->fetchAll();

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
