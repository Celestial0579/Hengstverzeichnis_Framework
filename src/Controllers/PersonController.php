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
        $db = Database::getInstance();
        $stmt = $db->query("
            SELECT p.*, COUNT(hp.id) as horse_count 
            FROM persons p 
            LEFT JOIN horse_persons hp ON hp.person_id = p.id 
            WHERE p.deleted_at IS NULL 
            GROUP BY p.id 
            ORDER BY p.name ASC
        ");
        $persons = $stmt->fetchAll();

        $this->render('admin_persons', [
            'title' => 'Personen verwalten',
            'persons' => $persons,
            'canCreate' => $this->hasPermission('persons', 'create'),
            'canEdit' => $this->hasPermission('persons', 'edit'),
            'canDelete' => $this->hasPermission('persons', 'delete')
        ]);
    }

    public function create(): void {
        $this->requirePermission('persons', 'create');

        $this->render('admin_person_form', [
            'title' => 'Neue Person anlegen',
            'person' => null
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
                'old' => $_POST
            ]);
            return;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("INSERT INTO persons (name, contact_info) VALUES (?, ?)");
        $stmt->execute([$name, $contact_info]);
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
            'person' => $person
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
                'person' => ['id' => $id, 'name' => $name, 'contact_info' => $contact_info],
                'error' => 'Der Name der Person ist erforderlich.'
            ]);
            return;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("UPDATE persons SET name = ?, contact_info = ? WHERE id = ?");
        $stmt->execute([$name, $contact_info, $id]);

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
