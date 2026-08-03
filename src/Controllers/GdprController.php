<?php
// src/Controllers/GdprController.php

namespace App\Controllers;

use App\Database;

class GdprController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requireAdmin();
    }

    public function index(): void {
        $db = Database::getInstance();

        $stmt = $db->query("SELECT * FROM gdpr_requests ORDER BY id DESC");
        $requests = $stmt->fetchAll();

        // For each request, search for potential matching persons in the persons table
        foreach ($requests as &$req) {
            $matchingPersons = [];
            $searchTerm = trim($req['name'] ?: $req['email']);

            if (!empty($searchTerm)) {
                $stmt = $db->prepare("
                    SELECT p.*, COUNT(hp.id) as horse_count 
                    FROM persons p 
                    LEFT JOIN horse_persons hp ON hp.person_id = p.id 
                    WHERE p.name LIKE ? OR p.contact_info LIKE ? 
                    GROUP BY p.id 
                    ORDER BY p.name ASC
                ");
                $like = '%' . $searchTerm . '%';
                $stmt->execute([$like, $like]);
                $matchingPersons = $stmt->fetchAll();
            }

            $req['matching_persons'] = $matchingPersons;
        }

        $this->render('admin_gdpr', [
            'title' => 'DSGVO Anfragen verwalten',
            'requests' => $requests
        ]);
    }

    public function updateStatus(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? 'pending';
        $notes = trim($_POST['admin_notes'] ?? '');

        if ($id > 0 && in_array($status, ['pending', 'processed', 'rejected'])) {
            $db = Database::getInstance();
            $stmt = $db->prepare("UPDATE gdpr_requests SET status = ?, admin_notes = ? WHERE id = ?");
            $stmt->execute([$status, $notes ?: null, $id]);
        }

        header("Location: /admin/gdpr?success=status_updated");
        exit;
    }

    public function anonymizePerson(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $personId = (int)($_POST['person_id'] ?? 0);
        $requestId = (int)($_POST['request_id'] ?? 0);

        if ($personId > 0) {
            $db = Database::getInstance();
            
            // Anonymize person data while preserving horse relationships in horse_persons
            $anonName = "Anonymisierte Person (#" . $personId . ")";
            $stmt = $db->prepare("UPDATE persons SET name = ?, contact_info = NULL WHERE id = ?");
            $stmt->execute([$anonName, $personId]);

            // Automatically mark GDPR request as processed
            if ($requestId > 0) {
                $stmt = $db->prepare("UPDATE gdpr_requests SET status = 'processed', admin_notes = ? WHERE id = ?");
                $stmt->execute(["Person #" . $personId . " erfolgreich anonymisiert.", $requestId]);
            }
        }

        header("Location: /admin/gdpr?success=anonymized&person_id=" . $personId);
        exit;
    }

    public function deletePerson(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $personId = (int)($_POST['person_id'] ?? 0);
        $requestId = (int)($_POST['request_id'] ?? 0);

        if ($personId > 0) {
            $db = Database::getInstance();
            
            // Delete person record (foreign key ON DELETE CASCADE will clean up horse_persons)
            $stmt = $db->prepare("DELETE FROM persons WHERE id = ?");
            $stmt->execute([$personId]);

            // Automatically mark GDPR request as processed
            if ($requestId > 0) {
                $stmt = $db->prepare("UPDATE gdpr_requests SET status = 'processed', admin_notes = ? WHERE id = ?");
                $stmt->execute(["Person #" . $personId . " vollständig gelöscht.", $requestId]);
            }
        }

        header("Location: /admin/gdpr?success=deleted&person_id=" . $personId);
        exit;
    }
}
