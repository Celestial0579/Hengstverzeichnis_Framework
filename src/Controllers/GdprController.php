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

    /** Seitengröße der Anfragen-Liste - deckelt zugleich die Batch-Personensuche (#126). */
    private const PER_PAGE = 25;

    public function index(): void {
        $db = Database::getInstance();

        // SQL-seitige Pagination statt "alle jemals eingegangenen Anfragen" -
        // gdpr_requests wächst monoton und wurde bisher komplett geladen (#126).
        $total = (int)$db->query("SELECT COUNT(*) FROM gdpr_requests")->fetchColumn();
        $totalPages = max(1, (int)ceil($total / self::PER_PAGE));
        $page = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));

        $offset = ($page - 1) * self::PER_PAGE;
        // LIMIT/OFFSET direkt interpoliert - beide Werte sind garantiert Integer.
        $requests = $db->query("SELECT * FROM gdpr_requests ORDER BY id DESC LIMIT " . self::PER_PAGE . " OFFSET {$offset}")->fetchAll();

        // Personensuche als EINE Batch-Query statt einer Query pro Anfrage
        // (vorher 1+N mit Full Scan je Zeile, #126). Kandidaten werden für alle
        // Suchbegriffe gemeinsam geladen und danach in PHP je Anfrage zugeordnet.
        // Nur offene Löschanfragen brauchen Treffer - die View zeigt sie nur dort an.
        $terms = [];
        foreach ($requests as $req) {
            if ($req['request_type'] === 'deletion' && $req['status'] !== 'processed') {
                $term = trim($req['name'] ?: $req['email']);
                if ($term !== '') {
                    $terms[$term] = true;
                }
            }
        }

        $candidates = [];
        if (!empty($terms)) {
            $conditions = implode(' OR ', array_fill(0, count($terms), '(p.name LIKE ? OR p.contact_info LIKE ?)'));
            $params = [];
            foreach (array_keys($terms) as $term) {
                $like = '%' . $term . '%';
                $params[] = $like;
                $params[] = $like;
            }
            $stmt = $db->prepare("
                SELECT p.*, COUNT(hp.id) as horse_count
                FROM persons p
                LEFT JOIN horse_persons hp ON hp.person_id = p.id
                WHERE {$conditions}
                GROUP BY p.id
                ORDER BY p.name ASC
            ");
            $stmt->execute($params);
            $candidates = $stmt->fetchAll();
        }

        foreach ($requests as &$req) {
            $matchingPersons = [];
            $searchTerm = trim($req['name'] ?: $req['email']);

            if ($req['request_type'] === 'deletion' && $req['status'] !== 'processed' && $searchTerm !== '') {
                foreach ($candidates as $candidate) {
                    if (self::containsIgnoreCase((string)$candidate['name'], $searchTerm)
                        || self::containsIgnoreCase((string)($candidate['contact_info'] ?? ''), $searchTerm)) {
                        $matchingPersons[] = $candidate;
                    }
                }
            }

            $req['matching_persons'] = $matchingPersons;
        }
        unset($req);

        $this->render('admin_gdpr', [
            'title' => 'DSGVO Anfragen verwalten',
            'requests' => $requests,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total
        ]);
    }

    /**
     * Case-insensitiver Teilstring-Vergleich, spiegelt das Verhalten von SQL
     * LIKE unter utf8mb4_unicode_ci für die PHP-seitige Zuordnung der
     * Batch-Treffer wider (Fallback ohne mbstring analog Paginator::search()).
     */
    private static function containsIgnoreCase(string $haystack, string $needle): bool {
        if (function_exists('mb_stripos')) {
            return mb_stripos($haystack, $needle) !== false;
        }
        return stripos($haystack, $needle) !== false;
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

            // DSGVO-Maßnahmen müssen im Audit-Log nachvollziehbar sein (#135).
            \App\Service\AuditLogger::log(
                "DSGVO: Person anonymisiert",
                "gdpr",
                "Person ID {$personId}" . ($requestId > 0 ? ", Anfrage ID {$requestId}" : "")
            );
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

            // DSGVO-Löschungen müssen im Audit-Log nachvollziehbar sein (#135).
            \App\Service\AuditLogger::log(
                "DSGVO: Person endgültig gelöscht",
                "gdpr",
                "Person ID {$personId}" . ($requestId > 0 ? ", Anfrage ID {$requestId}" : "")
            );
        }

        header("Location: /admin/gdpr?success=deleted&person_id=" . $personId);
        exit;
    }
}
