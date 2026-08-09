<?php
// src/Controllers/PersonController.php

namespace App\Controllers;

use App\Database;

class PersonController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
    }

    public function index(): void {
        $this->requirePermission('persons', 'view');

        // Optionaler Veröffentlichungs-Filter (?published=1|0). Ohne Parameter werden
        // alle Personen angezeigt; nur die exakten Werte '1'/'0' filtern, alles andere
        // wird als "alle" behandelt.
        $publishedFilter = self::normalizePublishedFilter($_GET['published'] ?? null);
        $publishedSql = $publishedFilter === null ? '' : ' AND p.is_published = ?';

        $db = Database::getInstance();
        $sql = "
            SELECT p.*, COUNT(hp.id) as horse_count
            FROM persons p
            LEFT JOIN horse_persons hp ON hp.person_id = p.id
            WHERE p.deleted_at IS NULL{$publishedSql}
            GROUP BY p.id
            ORDER BY p.name ASC
        ";
        if ($publishedFilter === null) {
            $stmt = $db->query($sql);
        } else {
            $stmt = $db->prepare($sql);
            $stmt->execute([$publishedFilter]);
        }
        $persons = $stmt->fetchAll();

        $this->render('admin_persons', [
            'title' => 'Personen verwalten',
            'persons' => $persons,
            'publishedFilter' => $publishedFilter,
            'canCreate' => $this->hasPermission('persons', 'create'),
            'canEdit' => $this->hasPermission('persons', 'edit'),
            'canDelete' => $this->hasPermission('persons', 'delete'),
            'canPublish' => $this->hasPermission('persons', 'publish')
        ]);
    }

    /**
     * Massen-Veröffentlichung / -Depublikation der ausgewählten Personen. Nur mit
     * 'persons.publish' erlaubt; setzt is_published für alle übergebenen IDs.
     */
    public function bulkPublish(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }
        $this->requirePermission('persons', 'publish');

        $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($id) => $id > 0));
        $publish = !empty($_POST['publish']) ? 1 : 0;

        if ($ids) {
            $db = Database::getInstance();
            // Einzelne, vollständig parametrisierte UPDATEs statt einer dynamisch
            // zusammengesetzten IN (...)-Liste - inhaltlich identisch, vermeidet aber
            // jede String-Interpolation im SQL (auch die des ?-Platzhalter-Strings).
            $stmt = $db->prepare("UPDATE persons SET is_published = ? WHERE id = ? AND deleted_at IS NULL");
            foreach ($ids as $id) {
                $stmt->execute([$publish, $id]);
            }

            \App\Service\AuditLogger::log(
                $publish ? "Personen veröffentlicht" : "Veröffentlichung von Personen zurückgenommen",
                "persons",
                count($ids) . " Datensätze (IDs: " . implode(', ', $ids) . ")"
            );
        }

        header("Location: /admin/persons?success=published" . self::publishedFilterQuery($_POST['published'] ?? null));
        exit;
    }

    public function create(): void {
        $this->requirePermission('persons', 'create');

        $this->render('admin_person_form', [
            'title' => 'Neue Person anlegen',
            'person' => null,
            'canPublish' => $this->hasPermission('persons', 'publish')
        ]);
    }

    public function store(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }
        $this->requirePermission('persons', 'create');

        $name = trim($_POST['name'] ?? '');
        $contact_info = trim($_POST['contact_info'] ?? '');
        $fields = $this->parseStructuredFields();

        if (empty($name)) {
            $this->render('admin_person_form', [
                'title' => 'Neue Person anlegen',
                'person' => null,
                'error' => 'Der Name der Person ist erforderlich.',
                'old' => $_POST,
                'canPublish' => $this->hasPermission('persons', 'publish')
            ]);
            return;
        }

        // Veröffentlichung (öffentliche Sichtbarkeit) nur mit 'persons.publish' und
        // nur bei explizit angehakter Checkbox - andernfalls unveröffentlicht (analog
        // HorseController::store()).
        $isPublished = (!empty($_POST['is_published']) && $this->hasPermission('persons', 'publish')) ? 1 : 0;

        $db = Database::getInstance();
        $stmt = $db->prepare("INSERT INTO persons (name, contact_info, street, house_number, postal_code, city, country, email, membership_status, is_published) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $contact_info, $fields['street'], $fields['house_number'], $fields['postal_code'], $fields['city'], $fields['country'], $fields['email'], $fields['membership_status'], $isPublished]);
        $newPersonId = $db->lastInsertId();

        \App\Service\AuditLogger::log("Person angelegt", "persons", "Person ID {$newPersonId}: {$name}");

        header("Location: /admin/persons?success=created");
        exit;
    }

    public function edit(): void {
        $this->requirePermission('persons', 'edit');

        $id = $_GET['id'] ?? null;
        if (!$id) {
            header("Location: /admin/persons");
            exit;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM persons WHERE id = ?");
        $stmt->execute([$id]);
        $person = $stmt->fetch();

        if (!$person) {
            header("Location: /admin/persons");
            exit;
        }

        $this->render('admin_person_form', [
            'title' => 'Person bearbeiten',
            'person' => $person,
            'canPublish' => $this->hasPermission('persons', 'publish')
        ]);
    }

    public function update(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }
        $this->requirePermission('persons', 'edit');

        $id = $_POST['id'] ?? null;
        if (!$id) {
            header("Location: /admin/persons");
            exit;
        }

        $name = trim($_POST['name'] ?? '');
        $contact_info = trim($_POST['contact_info'] ?? '');
        $fields = $this->parseStructuredFields();

        if (empty($name)) {
            // Fehlerpfad baut das Person-Array von Hand - alle Felder mitgeben,
            // sonst gehen die Eingaben beim erneuten Rendern verloren.
            $this->render('admin_person_form', [
                'title' => 'Person bearbeiten',
                'person' => array_merge(
                    ['id' => $id, 'name' => $name, 'contact_info' => $contact_info, 'is_published' => !empty($_POST['is_published']) ? 1 : 0],
                    $fields
                ),
                'error' => 'Der Name der Person ist erforderlich.',
                'canPublish' => $this->hasPermission('persons', 'publish')
            ]);
            return;
        }

        // Veröffentlichung nur mit 'persons.publish' änderbar; ohne das Recht bleibt der
        // bisherige Zustand erhalten (ein übermittelter Wunsch wird ignoriert, analog
        // HorseController::update()).
        $structuredSql = "street = ?, house_number = ?, postal_code = ?, city = ?, country = ?, email = ?, membership_status = ?";
        $structuredValues = [$fields['street'], $fields['house_number'], $fields['postal_code'], $fields['city'], $fields['country'], $fields['email'], $fields['membership_status']];
        $db = Database::getInstance();
        if ($this->hasPermission('persons', 'publish')) {
            $isPublished = !empty($_POST['is_published']) ? 1 : 0;
            $stmt = $db->prepare("UPDATE persons SET name = ?, contact_info = ?, {$structuredSql}, is_published = ? WHERE id = ?");
            $stmt->execute([$name, $contact_info, ...$structuredValues, $isPublished, $id]);
        } else {
            $stmt = $db->prepare("UPDATE persons SET name = ?, contact_info = ?, {$structuredSql} WHERE id = ?");
            $stmt->execute([$name, $contact_info, ...$structuredValues, $id]);
        }

        \App\Service\AuditLogger::log("Person aktualisiert", "persons", "Person ID {$id}: {$name}");

        header("Location: /admin/persons?success=updated");
        exit;
    }

    /**
     * Strukturierte Personenfelder (#188) aus dem POST: Adresse, E-Mail und
     * Mitgliedsstatus, jeweils leer -> NULL. Bewusst ohne Formatvalidierung
     * (Freitext-Philosophie wie breed; auch breeding_stations.email wird
     * nicht validiert).
     */
    private function parseStructuredFields(): array {
        $fields = [];
        foreach (['street', 'house_number', 'postal_code', 'city', 'country', 'email', 'membership_status'] as $field) {
            $fields[$field] = trim($_POST[$field] ?? '') ?: null;
        }
        return $fields;
    }

    public function delete(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }
        $this->requirePermission('persons', 'delete');

        $id = $_POST['id'] ?? null;
        if ($id) {
            $db = Database::getInstance();

            $stmt = $db->prepare("SELECT name FROM persons WHERE id = ?");
            $stmt->execute([$id]);
            $personName = $stmt->fetchColumn() ?: "ID {$id}";

            $stmt = $db->prepare("UPDATE persons SET deleted_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);

            \App\Service\AuditLogger::log("Person in Papierkorb verschoben", "persons", "Person ID {$id}: {$personName}");
        }

        header("Location: /admin/persons?success=deleted");
        exit;
    }
}
