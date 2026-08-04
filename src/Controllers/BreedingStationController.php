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
        $db = Database::getInstance();
        $stmt = $db->query("
            SELECT bs.*, COUNT(h.id) as horse_count 
            FROM breeding_stations bs 
            LEFT JOIN horses h ON h.breeding_station_id = bs.id AND h.deleted_at IS NULL 
            WHERE bs.deleted_at IS NULL 
            GROUP BY bs.id 
            ORDER BY bs.name ASC
        ");
        $stations = $stmt->fetchAll();

        $this->render('admin_breeding_stations', [
            'title' => 'Deckstationen verwalten',
            'stations' => $stations
        ]);
    }

    public function create(): void {
        $this->render('admin_breeding_station_form', [
            'title' => 'Neue Deckstation anlegen',
            'station' => null
        ]);
    }

    public function store(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $name = trim($_POST['name'] ?? '');
        $contactPerson = trim($_POST['contact_person'] ?? '');
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
                'old' => $_POST
            ]);
            return;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO breeding_stations (name, contact_person, address, phone, email, website) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $contactPerson, $address, $phone, $email, $website]);
        $newStationId = $db->lastInsertId();

        \App\Service\AuditLogger::log("Deckstation angelegt", "breeding_stations", "Deckstation ID {$newStationId}: {$name}");

        header("Location: /admin/breeding-stations?success=created");
        exit;
    }

    public function edit(): void {
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
            'station' => $station
        ]);
    }

    public function update(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $contactPerson = trim($_POST['contact_person'] ?? '');
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
                'station' => ['id' => $id, 'name' => $name, 'contact_person' => $contactPerson, 'address' => $address, 'phone' => $phone, 'email' => $email, 'website' => $website],
                'errors' => $errors
            ]);
            return;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("
            UPDATE breeding_stations 
            SET name = ?, contact_person = ?, address = ?, phone = ?, email = ?, website = ? 
            WHERE id = ?
        ");
        $stmt->execute([$name, $contactPerson, $address, $phone, $email, $website, $id]);

        \App\Service\AuditLogger::log("Deckstation aktualisiert", "breeding_stations", "Deckstation ID {$id}: {$name}");

        header("Location: /admin/breeding-stations?success=updated");
        exit;
    }

    public function delete(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

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
