<?php
// src/Controllers/BreedingStationController.php

namespace App\Controllers;

use App\Database;

class BreedingStationController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
    }

    /** Zeilen je Seite der Verwaltungsliste, wie bei Pferden und Personen. */
    public const PER_PAGE = 50;

    /**
     * Suchparameter der Stationsliste - zugleich die Weißliste für
     * Blätter-Links und den Redirect nach einer Bulk-Aktion.
     *
     * @var array<int, string>
     */
    public const FILTER_KEYS = [
        'search', 'q_name', 'q_contact', 'q_city', 'q_postal_code', 'q_state', 'q_country',
    ];

    public function index(): void {
        $this->requirePermission('breeding_stations', 'view');

        // Optionaler Veröffentlichungs-Filter (?published=1|0), siehe
        // BaseController::normalizePublishedFilter().
        $publishedFilter = self::normalizePublishedFilter($_GET['published'] ?? null);

        // Wie bei Pferden und Personen: Die Verwaltung sieht unveröffentlichte
        // Stationen (im öffentlichen Bereich wäre das ein Existenz-Orakel,
        // #151), gelöschte nicht.
        $filters = self::readListFilters(self::FILTER_KEYS);

        $where = ['bs.deleted_at IS NULL'];
        $params = [];

        if ($publishedFilter !== null) {
            $where[] = 'bs.is_published = ?';
            $params[] = $publishedFilter;
        }

        // Allgemeiner Begriff quer über Name, Ansprechpartner, Anschrift und
        // Kontaktwege - inklusive der alten Freitext-Adresse, in der bei
        // Altbestand die gesamte Anschrift steht.
        $search = $filters['search'] ?? '';
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where[] = "(
                bs.name LIKE ? OR
                bs.contact_person LIKE ? OR
                bs.street LIKE ? OR
                bs.postal_code LIKE ? OR
                bs.city LIKE ? OR
                bs.state LIKE ? OR
                bs.country LIKE ? OR
                bs.address LIKE ? OR
                bs.email LIKE ? OR
                bs.phone LIKE ?
            )";
            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like);
        }

        foreach ([
            'q_name' => 'bs.name',
            'q_contact' => 'bs.contact_person',
            'q_city' => 'bs.city',
            'q_postal_code' => 'bs.postal_code',
            'q_state' => 'bs.state',
            'q_country' => 'bs.country',
        ] as $key => $column) {
            $value = $filters[$key] ?? '';
            if ($value !== '') {
                $where[] = "{$column} LIKE ?";
                $params[] = '%' . $value . '%';
            }
        }

        $whereSql = implode(' AND ', $where);
        $db = Database::getInstance();

        $countStmt = $db->prepare("SELECT COUNT(*) FROM breeding_stations bs WHERE {$whereSql}");
        $countStmt->execute($params);
        $totalStations = (int)$countStmt->fetchColumn();

        $totalPages = max(1, (int)ceil($totalStations / self::PER_PAGE));
        $page = min(self::requestInt('page', 1, 1), $totalPages);
        $offset = ($page - 1) * self::PER_PAGE;

        // horse_count als Unterabfrage statt über GROUP BY - sonst zählte das
        // COUNT(*) oben Gruppen statt Zeilen und die Seitenzahl wäre falsch.
        $stmt = $db->prepare("
            SELECT bs.*, (
                SELECT COUNT(*) FROM horses h
                WHERE h.breeding_station_id = bs.id AND h.deleted_at IS NULL
            ) AS horse_count
            FROM breeding_stations bs
            WHERE {$whereSql}
            ORDER BY bs.name ASC
            LIMIT ? OFFSET ?
        ");
        $index = 1;
        foreach ($params as $value) {
            $stmt->bindValue($index++, $value);
        }
        $stmt->bindValue($index++, self::PER_PAGE, \PDO::PARAM_INT);
        $stmt->bindValue($index, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $stations = $stmt->fetchAll();

        $countries = $db->query("SELECT DISTINCT country FROM breeding_stations WHERE country IS NOT NULL AND country != '' AND deleted_at IS NULL ORDER BY country ASC")->fetchAll(\PDO::FETCH_COLUMN);

        $this->render('admin_breeding_stations', [
            'title' => 'Deckstationen verwalten',
            'stations' => $stations,
            'publishedFilter' => $publishedFilter,
            'filters' => $filters,
            'hasActiveFilters' => $filters !== [],
            'countries' => $countries,
            'page' => $page,
            'totalPages' => $totalPages,
            'totalCount' => $totalStations,
            'perPage' => self::PER_PAGE,
            'canCreate' => $this->hasPermission('breeding_stations', 'create'),
            'canEdit' => $this->hasPermission('breeding_stations', 'edit'),
            'canDelete' => $this->hasPermission('breeding_stations', 'delete'),
            'canPublish' => $this->hasPermission('breeding_stations', 'publish')
        ]);
    }

    /**
     * Massen-Veröffentlichung / -Depublikation der ausgewählten Deckstationen. Nur
     * mit 'breeding_stations.publish' erlaubt.
     */
    public function bulkPublish(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }
        $this->requirePermission('breeding_stations', 'publish');

        $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($id) => $id > 0));
        $publish = !empty($_POST['publish']) ? 1 : 0;

        if ($ids) {
            $db = Database::getInstance();
            // Einzelne, vollständig parametrisierte UPDATEs statt einer dynamisch
            // zusammengesetzten IN (...)-Liste - inhaltlich identisch, vermeidet aber
            // jede String-Interpolation im SQL (auch die des ?-Platzhalter-Strings).
            $stmt = $db->prepare("UPDATE breeding_stations SET is_published = ? WHERE id = ? AND deleted_at IS NULL");
            foreach ($ids as $id) {
                $stmt->execute([$publish, $id]);
            }

            \App\Service\AuditLogger::log(
                $publish ? "Deckstationen veröffentlicht" : "Veröffentlichung von Deckstationen zurückgenommen",
                "breeding_stations",
                count($ids) . " Datensätze (IDs: " . implode(', ', $ids) . ")"
            );
        }

        // Zurück zur Liste, wie der Benutzer sie verlassen hat (Suche + Seite),
        // siehe partials/publish_bulk_bar.php.
        header("Location: /admin/breeding-stations?success=published"
            . self::publishedFilterQuery($_POST['published'] ?? null)
            . self::listFilterQuery($_POST, [...self::FILTER_KEYS, 'page']));
        exit;
    }

    public function create(): void {
        $this->requirePermission('breeding_stations', 'create');

        $this->render('admin_breeding_station_form', [
            'title' => 'Neue Deckstation anlegen',
            'station' => null,
            'canPublish' => $this->hasPermission('breeding_stations', 'publish')
        ]);
    }

    /**
     * Die strukturierte Stationsadresse (#256) in Spaltenreihenfolge. Einzige
     * Stelle, an der die Liste steht - INSERT, UPDATE, Fehlerpfad und das
     * Einlesen aus dem POST leiten sich daraus ab. Vorher stand jedes Feld
     * dieses Controllers an vier Stellen einzeln ausgeschrieben; bei sechs
     * neuen Feldern wäre das eine Einladung, eine davon zu vergessen.
     *
     * `address` gehört bewusst NICHT dazu: Das ist das alte Freitextfeld, es
     * bleibt erhalten und wird gesondert behandelt (siehe database/schema.sql).
     */
    private const ADDRESS_FIELDS = ['street', 'house_number', 'postal_code', 'city', 'state', 'country'];

    /**
     * Strukturierte Adressfelder aus dem POST, jeweils leer -> NULL. Bewusst
     * ohne Formatvalidierung, wie bei den Personendaten (#188).
     */
    private function parseAddressFields(): array {
        $fields = [];
        foreach (self::ADDRESS_FIELDS as $field) {
            $fields[$field] = trim($_POST[$field] ?? '') ?: null;
        }
        return $fields;
    }

    public function store(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }
        $this->requirePermission('breeding_stations', 'create');

        $name = trim($_POST['name'] ?? '');
        $contactPerson = trim($_POST['contact_person'] ?? '');
        $addressFields = $this->parseAddressFields();
        $address = trim($_POST['address'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $website = trim($_POST['website'] ?? '');

        $errors = [];
        if (empty($name)) {
            $errors[] = "Name der Deckstation / des Gestüts ist erforderlich.";
        }
        if (!empty($website) && !str_starts_with($website, 'http://') && !str_starts_with($website, 'https://')) {
            $errors[] = "Website muss eine gültige Adresse beginnend mit http:// oder https:// sein.";
        }

        if (!empty($errors)) {
            $this->render('admin_breeding_station_form', [
                'title' => 'Neue Deckstation anlegen',
                'station' => null,
                'errors' => $errors,
                'old' => $_POST,
                'canPublish' => $this->hasPermission('breeding_stations', 'publish')
            ]);
            return;
        }

        // Veröffentlichung nur mit 'breeding_stations.publish' und nur bei angehakter
        // Checkbox (analog HorseController::store()).
        $isPublished = (!empty($_POST['is_published']) && $this->hasPermission('breeding_stations', 'publish')) ? 1 : 0;

        $db = Database::getInstance();
        $addressColumns = implode(', ', self::ADDRESS_FIELDS);
        $addressPlaceholders = implode(', ', array_fill(0, count(self::ADDRESS_FIELDS), '?'));
        $stmt = $db->prepare("
            INSERT INTO breeding_stations (name, contact_person, {$addressColumns}, address, phone, email, website, contact_public, is_published)
            VALUES (?, ?, {$addressPlaceholders}, ?, ?, ?, ?, ?, ?)
        ");
        // Vorgabe 1 beim Anlegen ueber das Formular: Das Haekchen ist dort
        // vorbelegt, ein leeres Feld heisst also bewusst "nicht oeffentlich".
        $contactPublic = !empty($_POST['contact_public']) ? 1 : 0;
        $stmt->execute([$name, $contactPerson, ...array_values($addressFields), $address, $phone, $email, $website, $contactPublic, $isPublished]);
        $newStationId = $db->lastInsertId();

        \App\Service\AuditLogger::log("Deckstation angelegt", "breeding_stations", "Deckstation ID {$newStationId}: {$name}");

        header("Location: /admin/breeding-stations?success=created");
        exit;
    }

    public function edit(): void {
        $this->requirePermission('breeding_stations', 'edit');

        $id = (int)($_GET['id'] ?? 0);
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM breeding_stations WHERE id = ?");
        $stmt->execute([$id]);
        $station = $stmt->fetch();

        if (!$station) {
            header("Location: /admin/breeding-stations");
            exit;
        }

        $this->render('admin_breeding_station_form', [
            'title' => 'Deckstation bearbeiten',
            'station' => $station,
            // Siehe PersonController::edit() - anzeigen ja, speichern nein.
            'isDeleted' => ($station['deleted_at'] ?? null) !== null,
            // Erweiterungspunkt wie person.edit_sections.
            'pluginEditSections' => $this->hooks()->applyFilters('station.edit_sections', [], $station),
            'canPublish' => $this->hasPermission('breeding_stations', 'publish')
        ]);
    }

    public function update(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }
        $this->requirePermission('breeding_stations', 'edit');

        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $contactPerson = trim($_POST['contact_person'] ?? '');
        $addressFields = $this->parseAddressFields();
        $address = trim($_POST['address'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $website = trim($_POST['website'] ?? '');

        $errors = [];
        if (empty($name)) {
            $errors[] = "Name der Deckstation ist erforderlich.";
        }
        if (!empty($website) && !str_starts_with($website, 'http://') && !str_starts_with($website, 'https://')) {
            $errors[] = "Website muss eine gültige Adresse beginnend mit http:// oder https:// sein.";
        }

        if (!empty($errors)) {
            $this->render('admin_breeding_station_form', [
                'title' => 'Deckstation bearbeiten',
                // Fehlerpfad baut den Stations-Datensatz von Hand - die
                // strukturierten Adressfelder kommen per array_merge dazu, damit
                // sie beim erneuten Rendern nicht verlorengehen.
                'station' => array_merge(
                    ['id' => $id, 'name' => $name, 'contact_person' => $contactPerson, 'address' => $address, 'phone' => $phone, 'email' => $email, 'website' => $website, 'is_published' => !empty($_POST['is_published']) ? 1 : 0],
                    $addressFields
                ),
                'errors' => $errors,
                'canPublish' => $this->hasPermission('breeding_stations', 'publish')
            ]);
            return;
        }

        // Veröffentlichung nur mit 'breeding_stations.publish' änderbar; ohne das Recht
        // bleibt der bisherige Zustand erhalten (analog HorseController::update()).
        $db = Database::getInstance();
        $addressSql = implode(', ', array_map(fn($field) => "{$field} = ?", self::ADDRESS_FIELDS));
        $addressValues = array_values($addressFields);
        $contactPublic = !empty($_POST['contact_public']) ? 1 : 0;

        // Schreibschutz fuer den Papierkorb, wie bei Personen (#296): Ein aus
        // der Oberflaeche verschwundener Datensatz darf nicht ueber einen alten
        // Link weiter bearbeitet werden.
        if ($this->hasPermission('breeding_stations', 'publish')) {
            $isPublished = !empty($_POST['is_published']) ? 1 : 0;
            $stmt = $db->prepare("
                UPDATE breeding_stations
                SET name = ?, contact_person = ?, {$addressSql}, address = ?, phone = ?, email = ?, website = ?, contact_public = ?, is_published = ?
                WHERE id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([$name, $contactPerson, ...$addressValues, $address, $phone, $email, $website, $contactPublic, $isPublished, $id]);
        } else {
            $stmt = $db->prepare("
                UPDATE breeding_stations
                SET name = ?, contact_person = ?, {$addressSql}, address = ?, phone = ?, email = ?, website = ?, contact_public = ?
                WHERE id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([$name, $contactPerson, ...$addressValues, $address, $phone, $email, $website, $contactPublic, $id]);
        }

        \App\Service\AuditLogger::log("Deckstation aktualisiert", "breeding_stations", "Deckstation ID {$id}: {$name}");

        header("Location: /admin/breeding-stations?success=updated");
        exit;
    }

    public function delete(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }
        $this->requirePermission('breeding_stations', 'delete');

        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $db = Database::getInstance();

            $stmt = $db->prepare("SELECT name FROM breeding_stations WHERE id = ?");
            $stmt->execute([$id]);
            $stationName = $stmt->fetchColumn() ?: "ID {$id}";

            $stmt = $db->prepare("UPDATE breeding_stations SET deleted_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);

            \App\Service\AuditLogger::log("Deckstation in Papierkorb verschoben", "breeding_stations", "Deckstation ID {$id}: {$stationName}");
        }

        header("Location: /admin/breeding-stations?success=deleted");
        exit;
    }
}
