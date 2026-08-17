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

    /**
     * Trefferdeckel der manuellen Personensuche (#266). Bewusst begrenzt: Ein
     * Auswahlfeld, das den kompletten Personenbestand lädt, ist genau die Falle
     * aus Addons#87 - dort lud die Hengstauswahl ungebremst den ganzen Bestand.
     * Derselbe Wert wie im Galerie-Addon, an dem das Muster schon hängt.
     */
    private const SEARCH_LIMIT = 50;

    public function index(): void {
        $db = Database::getInstance();

        // SQL-seitige Pagination statt "alle jemals eingegangenen Anfragen" -
        // gdpr_requests wächst monoton und wurde bisher komplett geladen (#126).
        $total = (int)$db->query("SELECT COUNT(*) FROM gdpr_requests")->fetchColumn();
        $totalPages = max(1, (int)ceil($total / self::PER_PAGE));
        $page = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));

        $offset = ($page - 1) * self::PER_PAGE;
        $stmt = $db->prepare("SELECT * FROM gdpr_requests ORDER BY id DESC LIMIT ? OFFSET ?");
        $stmt->bindValue(1, self::PER_PAGE, \PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $requests = $stmt->fetchAll();

        // Personensuche als EINE Batch-Query statt einer Query pro Anfrage
        // (vorher 1+N mit Full Scan je Zeile, #126). Kandidaten werden für alle
        // Suchbegriffe gemeinsam geladen und danach in PHP je Anfrage zugeordnet.
        //
        // Seit #266 auch für Auskunftsanfragen: Wer Auskunft verlangt, will
        // wissen, was gespeichert ist - dafür muss der Datensatz erst einmal
        // gefunden werden. Bisher lief für 'info' gar kein Matching, die
        // Bearbeitung begann also bei null. Was die Oberfläche danach anbietet,
        // unterscheidet sich weiterhin: Auskunft heißt einsehen, nicht löschen.
        $terms = [];
        foreach ($requests as $req) {
            if (self::needsMatching($req)) {
                $term = trim($req['name'] ?: $req['email']);
                if ($term !== '') {
                    $terms[$term] = true;
                }
            }
        }

        $candidates = [];
        if (!empty($terms)) {
            // E-Mail seit #188 eigenes Feld - Anträge nennen oft nur die
            // E-Mail-Adresse, die Suche muss sie auch dort finden.
            $conditions = implode(' OR ', array_fill(0, count($terms), '(p.name LIKE ? OR p.contact_info LIKE ? OR p.email LIKE ?)'));
            $params = [];
            foreach (array_keys($terms) as $term) {
                $like = '%' . $term . '%';
                $params[] = $like;
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

            if (self::needsMatching($req) && $searchTerm !== '') {
                foreach ($candidates as $candidate) {
                    if (self::containsIgnoreCase((string)$candidate['name'], $searchTerm)
                        || self::containsIgnoreCase((string)($candidate['contact_info'] ?? ''), $searchTerm)
                        || self::containsIgnoreCase((string)($candidate['email'] ?? ''), $searchTerm)) {
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
     * Braucht diese Anfrage eine Personenzuordnung? Beide Anfragearten - die
     * Löschung wie die Auskunft (#266) - solange sie nicht abgeschlossen ist.
     * An einer erledigten Anfrage gibt es nichts mehr zuzuordnen.
     *
     * @param array<string, mixed> $req
     */
    private static function needsMatching(array $req): bool {
        return in_array($req['request_type'], ['deletion', 'info'], true)
            && $req['status'] !== 'processed';
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

    /**
     * Manuelle Personensuche für die DSGVO-Verwaltung (#266).
     *
     * Der Automatch greift nur bei wörtlicher Übereinstimmung und scheitert
     * schon an abweichender Schreibweise, Tippfehlern oder einem geänderten
     * Namen. Ohne Rückfallweg blieb die Anfrage dann auf `pending` liegen -
     * bei einem Verfahren, dessen ganzer Zweck die Einhaltung gesetzlicher
     * Fristen ist, ist das der ungünstigste denkbare Ausgang.
     *
     * Liefert höchstens SEARCH_LIMIT Treffer als JSON. Der Konstruktor
     * erzwingt Anmeldung und Admin-Rolle, die Action erbt diesen Schutz - die
     * Antwort enthält personenbezogene Daten und darf nirgends sonst landen.
     */
    public function searchPersons(): void {
        header('Content-Type: application/json; charset=utf-8');
        // Treffer enthalten PII: weder Browser noch Zwischenspeicher sollen sie
        // aufbewahren, und eine fremde Seite soll sie nicht einbetten können.
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');

        $q = trim((string)($_GET['q'] ?? ''));
        // Ab zwei Zeichen: Ein einzelner Buchstabe liefert bei einem
        // Namensbestand ohnehin nur den willkürlichen Anfang der Liste.
        if (mb_strlen($q) < 2) {
            echo json_encode([]);
            exit;
        }

        $like = '%' . $q . '%';
        // BEWUSST OHNE deleted_at-Filter, wie schon der Automatch oben: Ein
        // weich gelöschter Datensatz ist aus der Oberfläche verschwunden, seine
        // personenbezogenen Daten stehen aber unverändert in der Tabelle. Wer
        // Löschung verlangt, hat Anspruch auch auf diese - würde die Suche sie
        // ausblenden, entstünde genau die Lücke, die niemandem auffällt: kein
        // Treffer, Anfrage abgehakt, Daten weiter da. Die Oberfläche kennzeichnet
        // solche Treffer.
        $stmt = Database::getInstance()->prepare(
            'SELECT p.id, p.name, p.contact_info, p.email, p.deleted_at, COUNT(hp.id) AS horse_count
             FROM persons p
             LEFT JOIN horse_persons hp ON hp.person_id = p.id
             WHERE p.name LIKE ? OR p.contact_info LIKE ? OR p.email LIKE ?
             GROUP BY p.id
             ORDER BY p.name ASC, p.id ASC
             LIMIT ' . self::SEARCH_LIMIT
        );
        $stmt->execute([$like, $like, $like]);

        $results = [];
        foreach ($stmt->fetchAll() as $row) {
            $results[] = [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'contact_info' => (string)($row['contact_info'] ?? ''),
                'email' => (string)($row['email'] ?? ''),
                'horse_count' => (int)$row['horse_count'],
                'is_deleted' => $row['deleted_at'] !== null,
            ];
        }

        echo json_encode($results, JSON_UNESCAPED_UNICODE);
        exit;
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

            // Anonymize person data while preserving horse relationships in horse_persons.
            // Alle PII-Felder nullen - auch city/state/country/membership_status sind
            // personenbezogen, sobald sie am Namen hängen (#188, state seit #256).
            //
            // Diese Liste ist hartkodiert und muss bei JEDER neuen Spalte in
            // persons mitgezogen werden. Ein vergessenes Feld fällt nicht auf:
            // Die Anonymisierung meldet weiterhin Erfolg, und die Lücke bleibt
            // still bestehen. Der Gegentest dazu steht in
            // tests/Functional/GdprEraseTest.php und prüft die Felder namentlich.
            $anonName = "Anonymisierte Person (#" . $personId . ")";
            $stmt = $db->prepare("UPDATE persons SET name = ?, contact_info = NULL, street = NULL, house_number = NULL, postal_code = NULL, city = NULL, state = NULL, country = NULL, email = NULL, phone = NULL, mobile = NULL, website = NULL, membership_status = NULL WHERE id = ?");
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
