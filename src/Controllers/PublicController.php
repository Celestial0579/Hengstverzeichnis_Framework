<?php
// src/Controllers/PublicController.php

namespace App\Controllers;

use App\Database;

class PublicController extends BaseController {

    /**
     * Obergrenze für den Freitext einer DSGVO-Anfrage. Die Spalte selbst ist
     * TEXT (64 KB); die engere Grenze verhindert, dass ein einzelner Absender
     * die Tabelle und die Benachrichtigungs-E-Mails mit Megabyte-Eingaben
     * aufbläht.
     */
    private const DSGVO_MESSAGE_MAX_LENGTH = 5000;

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
            LEFT JOIN horses sire ON h.sire_id = sire.id AND sire.deleted_at IS NULL AND sire.is_published = 1
            LEFT JOIN horses dam ON h.dam_id = dam.id AND dam.deleted_at IS NULL AND dam.is_published = 1
            LEFT JOIN horse_persons hp_breeder ON hp_breeder.horse_id = h.id AND hp_breeder.role = 'breeder'
            LEFT JOIN persons p_breeder ON hp_breeder.person_id = p_breeder.id AND p_breeder.deleted_at IS NULL
            LEFT JOIN horse_persons hp_owner ON hp_owner.horse_id = h.id AND hp_owner.role = 'owner'
            LEFT JOIN persons p_owner ON hp_owner.person_id = p_owner.id AND p_owner.deleted_at IS NULL
            WHERE {$whereSql}
            ORDER BY h.name ASC
        ";

        // Gast-Gruppe ohne horses.view sieht keinerlei Pferde (leerer Katalog),
        // sonst würde die Rechte-Entziehung wirkungslos bleiben.
        if ($this->hasPermission('horses', 'view')) {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $horses = $stmt->fetchAll();
        } else {
            $horses = [];
        }

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
            'persons' => $persons,
            'cardSections' => $cardSections
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

        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT h.*, bs.name as station_name, bs.contact_person as station_contact, bs.address as station_address, bs.phone as station_phone, bs.email as station_email, bs.website as station_website
            FROM horses h
            LEFT JOIN breeding_stations bs ON h.breeding_station_id = bs.id
            WHERE h.id = ? AND h.deleted_at IS NULL AND h.is_published = 1
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
        $this->renderDsgvoForm();
    }

    /**
     * Rendert das öffentliche DSGVO-Formular. Gibt dabei IMMER eine frische
     * CAPTCHA-Aufgabe aus, da jede Prüfung die vorherige verbraucht
     * (Single-Use, siehe App\Security\Captcha).
     *
     * @param string|null $error Anzuzeigende Fehlermeldung
     * @param array<string, string> $old Zuvor eingegebene Werte (Formular-Wiederbefüllung)
     */
    private function renderDsgvoForm(?string $error = null, array $old = []): void {
        $this->render('public_dsgvo', [
            'title' => \App\I18n\Translator::t('meta.title_dsgvo') . ' - ' . ($this->settings['site_name'] ?? 'Hengstverzeichnis'),
            'error' => $error,
            'old' => $old,
            'captchaQuestion' => \App\Security\Captcha::issue()
        ]);
    }

    /**
     * Nimmt eine Betroffenen-Anfrage nach Art. 15/17 DSGVO entgegen.
     *
     * Der Endpunkt ist bewusst ohne Anmeldung erreichbar und löst pro
     * angenommener Anfrage eine echte E-Mail an den Admin sowie eine Zeile in
     * `gdpr_requests` aus - er wird deshalb mehrschichtig abgesichert
     * (CSRF → Rate-Limit → Honeypot → CAPTCHA → Validierung). Die Schichten
     * ergänzen einander bewusst: Der RateLimiter ist bei DB-Fehlern fail-open
     * (Ausfallsicherheit), CAPTCHA und Honeypot arbeiten dagegen ohne
     * Datenbank und bleiben in genau diesem Fall wirksam.
     */
    public function dsgvoSubmit(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden(\App\I18n\Translator::t('errors.csrf_invalid'));
        }

        // Nur echte Zeichenketten übernehmen: Ein manipulierter Request
        // (name[]=x) darf weder eine "Array to string"-Warnung noch einen
        // TypeError in den nachgelagerten Prüfungen auslösen.
        $postString = static fn(string $key, string $default = ''): string
            => is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : $default;

        $old = [
            'name' => $postString('name'),
            'email' => $postString('email'),
            'request_type' => $postString('request_type', 'info'),
            'message' => $postString('message')
        ];

        // Zwei getrennte Zähler pro Client-IP (analog zum Login, #115):
        // 'dsgvo_attempt' zählt JEDEN POST und bremst damit automatisiertes
        // Durchprobieren des CAPTCHAs; 'dsgvo_request' zählt nur tatsächlich
        // angenommene Anfragen und begrenzt eng, wie oft ein Client echte
        // Admin-Benachrichtigungen auslösen und Zeilen in gdpr_requests anlegen
        // kann. Die Trennung sorgt dafür, dass ein Tippfehler im CAPTCHA nicht
        // das kleine Kontingent echter Anfragen aufbraucht.
        $clientIp = \App\Security\ClientIp::resolve();
        if (
            \App\Security\RateLimiter::tooManyAttempts($clientIp, 'dsgvo_attempt', 20, 3600)
            || \App\Security\RateLimiter::tooManyAttempts($clientIp, 'dsgvo_request', 3, 3600)
        ) {
            $this->renderDsgvoForm(\App\I18n\Translator::t('dsgvo.rate_limited'), $old);
            return;
        }
        \App\Security\RateLimiter::recordAttempt($clientIp, 'dsgvo_attempt');

        // Honeypot: für Menschen unsichtbares Feld, das nur automatische
        // Formularausfüller befüllen. Bewusst mit der normalen Erfolgsmeldung
        // beantwortet - der Bot erfährt so nicht, dass er erkannt wurde -,
        // aber ohne Speicherung und ohne Benachrichtigungs-E-Mail.
        if (\App\Security\Captcha::honeypotTripped($_POST)) {
            \App\Security\Captcha::clear();
            header("Location: /dsgvo?success=1");
            exit;
        }

        $captchaResult = \App\Security\Captcha::verify($postString('captcha'));
        if ($captchaResult !== \App\Security\Captcha::OK) {
            $errorKey = match ($captchaResult) {
                \App\Security\Captcha::EXPIRED => 'dsgvo.captcha_expired',
                \App\Security\Captcha::TOO_FAST => 'dsgvo.captcha_too_fast',
                default => 'dsgvo.captcha_wrong'
            };
            $this->renderDsgvoForm(\App\I18n\Translator::t($errorKey), $old);
            return;
        }

        // Serverseitige Validierung: Zuvor wurden ungültige Eingaben still
        // verworfen und dem Absender trotzdem Erfolg gemeldet - eine echte
        // Anfrage konnte so unbemerkt verloren gehen. Die Längen entsprechen
        // den Spaltenbreiten in `gdpr_requests` (siehe database/schema.sql).
        if (
            $old['email'] === ''
            || !filter_var($old['email'], FILTER_VALIDATE_EMAIL)
            || mb_strlen($old['email']) > 100
        ) {
            $this->renderDsgvoForm(\App\I18n\Translator::t('dsgvo.email_invalid'), $old);
            return;
        }
        if (!in_array($old['request_type'], ['info', 'deletion'], true)) {
            $this->renderDsgvoForm(\App\I18n\Translator::t('dsgvo.request_type_invalid'), $old);
            return;
        }
        if (mb_strlen($old['name']) > 100) {
            $this->renderDsgvoForm(\App\I18n\Translator::t('dsgvo.name_too_long'), $old);
            return;
        }
        if (mb_strlen($old['message']) > self::DSGVO_MESSAGE_MAX_LENGTH) {
            $this->renderDsgvoForm(
                \App\I18n\Translator::t('dsgvo.message_too_long', ['max' => self::DSGVO_MESSAGE_MAX_LENGTH]),
                $old
            );
            return;
        }

        \App\Security\RateLimiter::recordAttempt($clientIp, 'dsgvo_request');

        $db = Database::getInstance();
        $stmt = $db->prepare("INSERT INTO gdpr_requests (name, email, request_type, message) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $old['name'] ?: null,
            $old['email'],
            $old['request_type'],
            $old['message'] ?: null
        ]);

        // Send Email Notification to Admin
        $mailer = new \App\Service\Mailer();
        $typeName = $old['request_type'] === 'deletion'
            ? 'Löschung / Anonymisierung von Daten (Art. 17 DSGVO)'
            : 'Auskunft über Daten (Art. 15 DSGVO)';
        $mailer->sendDsgvoNotification($old['email'], $typeName, $old['message'], $old['name']);

        header("Location: /dsgvo?success=1");
        exit;
    }
}
