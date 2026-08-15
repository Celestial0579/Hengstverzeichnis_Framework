<?php
// src/Controllers/BreedingStationController.php

namespace App\Controllers;

use App\Database;

class BreedingStationController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
    }

    public function index(): void {
        $this->requirePermission('breeding_stations', 'view');

        // Optionaler Veröffentlichungs-Filter (?published=1|0), siehe
        // BaseController::normalizePublishedFilter().
        $publishedFilter = self::normalizePublishedFilter($_GET['published'] ?? null);
        $publishedSql = $publishedFilter === null ? '' : ' AND bs.is_published = ?';

        $db = Database::getInstance();
        $sql = "
            SELECT bs.*, COUNT(h.id) as horse_count
            FROM breeding_stations bs
            LEFT JOIN horses h ON h.breeding_station_id = bs.id AND h.deleted_at IS NULL
            WHERE bs.deleted_at IS NULL{$publishedSql}
            GROUP BY bs.id
            ORDER BY bs.name ASC
        ";
        if ($publishedFilter === null) {
            $stmt = $db->query($sql);
        } else {
            $stmt = $db->prepare($sql);
            $stmt->execute([$publishedFilter]);
        }
        $stations = $stmt->fetchAll();

        $this->render('admin_breeding_stations', [
            'title' => 'Deckstationen verwalten',
            'stations' => $stations,
            'publishedFilter' => $publishedFilter,
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

        header("Location: /admin/breeding-stations?success=published" . self::publishedFilterQuery($_POST['published'] ?? null));
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
            INSERT INTO breeding_stations (name, contact_person, {$addressColumns}, address, phone, email, website, is_published)
            VALUES (?, ?, {$addressPlaceholders}, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $contactPerson, ...array_values($addressFields), $address, $phone, $email, $website, $isPublished]);
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
        if ($this->hasPermission('breeding_stations', 'publish')) {
            $isPublished = !empty($_POST['is_published']) ? 1 : 0;
            $stmt = $db->prepare("
                UPDATE breeding_stations
                SET name = ?, contact_person = ?, {$addressSql}, address = ?, phone = ?, email = ?, website = ?, is_published = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $contactPerson, ...$addressValues, $address, $phone, $email, $website, $isPublished, $id]);
        } else {
            $stmt = $db->prepare("
                UPDATE breeding_stations
                SET name = ?, contact_person = ?, {$addressSql}, address = ?, phone = ?, email = ?, website = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $contactPerson, ...$addressValues, $address, $phone, $email, $website, $id]);
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
