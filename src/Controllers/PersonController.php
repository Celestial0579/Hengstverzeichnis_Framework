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
        $publishedSql = $publishedFilter === null ? '' : ' AND p.is_published = ' . $publishedFilter;

        $db = Database::getInstance();
        $stmt = $db->query("
            SELECT p.*, COUNT(hp.id) as horse_count
            FROM persons p
            LEFT JOIN horse_persons hp ON hp.person_id = p.id
            WHERE p.deleted_at IS NULL{$publishedSql}
            GROUP BY p.id
            ORDER BY p.name ASC
        ");
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
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("UPDATE persons SET is_published = ? WHERE id IN ({$placeholders}) AND deleted_at IS NULL");
            $stmt->execute([$publish, ...$ids]);

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
        $stmt = $db->prepare("INSERT INTO persons (name, contact_info, is_published) VALUES (?, ?, ?)");
        $stmt->execute([$name, $contact_info, $isPublished]);
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

        if (empty($name)) {
            $this->render('admin_person_form', [
                'title' => 'Person bearbeiten',
                'person' => ['id' => $id, 'name' => $name, 'contact_info' => $contact_info, 'is_published' => !empty($_POST['is_published']) ? 1 : 0],
                'error' => 'Der Name der Person ist erforderlich.',
                'canPublish' => $this->hasPermission('persons', 'publish')
            ]);
            return;
        }

        // Veröffentlichung nur mit 'persons.publish' änderbar; ohne das Recht bleibt der
        // bisherige Zustand erhalten (ein übermittelter Wunsch wird ignoriert, analog
        // HorseController::update()).
        $db = Database::getInstance();
        if ($this->hasPermission('persons', 'publish')) {
            $isPublished = !empty($_POST['is_published']) ? 1 : 0;
            $stmt = $db->prepare("UPDATE persons SET name = ?, contact_info = ?, is_published = ? WHERE id = ?");
            $stmt->execute([$name, $contact_info, $isPublished, $id]);
        } else {
            $stmt = $db->prepare("UPDATE persons SET name = ?, contact_info = ? WHERE id = ?");
            $stmt->execute([$name, $contact_info, $id]);
        }

        \App\Service\AuditLogger::log("Person aktualisiert", "persons", "Person ID {$id}: {$name}");

        header("Location: /admin/persons?success=updated");
        exit;
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
