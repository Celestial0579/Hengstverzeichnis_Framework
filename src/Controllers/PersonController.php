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
            SELECT p.*, COUNT(h.id) as horse_count
            FROM persons p
            -- Geloeschte Pferde nicht mitzaehlen: Die Zahl neben der Person
            -- war bisher eine Obermenge der tatsaechlich sichtbaren
            -- Zuordnungen (#296, Nebenbefund).
            LEFT JOIN horse_persons hp ON hp.person_id = p.id
            LEFT JOIN horses h ON h.id = hp.horse_id AND h.deleted_at IS NULL
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
        $structuredColumns = implode(', ', self::STRUCTURED_FIELDS);
        $structuredPlaceholders = implode(', ', array_fill(0, count(self::STRUCTURED_FIELDS), '?'));
        // is_breeder ist ein Schalter, kein Freitext, und die Spalte ist NOT
        // NULL - deshalb neben STRUCTURED_FIELDS gefuehrt wie is_published.
        $isBreeder = !empty($_POST['is_breeder']) ? 1 : 0;
        $stmt = $db->prepare("INSERT INTO persons (name, contact_info, {$structuredColumns}, is_breeder, is_published) VALUES (?, ?, {$structuredPlaceholders}, ?, ?)");
        $stmt->execute([$name, $contact_info, ...array_values($fields), $isBreeder, $isPublished]);
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

        // Weich geloeschte Personen werden bewusst WEITER ANGEZEIGT, aber nicht
        // mehr gespeichert (#296). Ein Filter schon hier waere der naheliegende,
        // aber falsche Fix: admin_gdpr.php verlinkt genau auf diese Route, und
        // die DSGVO-Suche nimmt geloeschte Treffer ausdruecklich mit (siehe
        // GdprController: wer Loeschung verlangt, hat Anspruch auch auf weich
        // geloeschte Daten). Wer den Datensatz nicht mehr oeffnen kann, kann
        // auch nicht pruefen, was noch drinsteht - genau die Luecke, die dort
        // vermieden werden soll. Das Schreiben verhindert update().
        $this->render('admin_person_form', [
            'title' => 'Person bearbeiten',
            'person' => $person,
            'isDeleted' => $person['deleted_at'] !== null,
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
                    [
                        'id' => $id,
                        'name' => $name,
                        'contact_info' => $contact_info,
                        'is_published' => !empty($_POST['is_published']) ? 1 : 0,
                        // Sonst verlöre ein Validierungsfehler das Häkchen.
                        'is_breeder' => !empty($_POST['is_breeder']) ? 1 : 0,
                    ],
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
        $structuredSql = implode(', ', array_map(fn($f) => "{$f} = ?", self::STRUCTURED_FIELDS));
        $structuredValues = array_values($fields);
        $db = Database::getInstance();
        // Schreibschutz fuer den Papierkorb (#296): Ein Datensatz, der aus der
        // Oberflaeche verschwunden ist, darf nicht ueber einen alten Link oder
        // ein Lesezeichen weiter bearbeitet werden - er bliebe geloescht und
        // bekaeme trotzdem neue Werte, ohne dass jemand es merkt. Dasselbe
        // Muster wie beim Bulk-Publish darueber.
        //
        // is_breeder (#293) ist ein Schalter, kein Freitext, und die Spalte ist
        // NOT NULL - deshalb neben STRUCTURED_FIELDS gefuehrt wie is_published.
        $isBreeder = !empty($_POST['is_breeder']) ? 1 : 0;
        if ($this->hasPermission('persons', 'publish')) {
            $isPublished = !empty($_POST['is_published']) ? 1 : 0;
            $stmt = $db->prepare("UPDATE persons SET name = ?, contact_info = ?, {$structuredSql}, is_breeder = ?, is_published = ? WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$name, $contact_info, ...$structuredValues, $isBreeder, $isPublished, $id]);
        } else {
            $stmt = $db->prepare("UPDATE persons SET name = ?, contact_info = ?, {$structuredSql}, is_breeder = ? WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$name, $contact_info, ...$structuredValues, $isBreeder, $id]);
        }

        // Keine betroffene Zeile heisst: Der Datensatz liegt im Papierkorb.
        // Nicht als Erfolg melden - sonst glaubt der Bearbeiter, gespeichert zu
        // haben.
        if ($stmt->rowCount() === 0) {
            $stillDeleted = $db->prepare("SELECT 1 FROM persons WHERE id = ? AND deleted_at IS NOT NULL");
            $stillDeleted->execute([$id]);
            if ($stillDeleted->fetchColumn()) {
                header("Location: /admin/persons?error=deleted");
                exit;
            }
        }

        \App\Service\AuditLogger::log("Person aktualisiert", "persons", "Person ID {$id}: {$name}");

        header("Location: /admin/persons?success=updated");
        exit;
    }

    /**
     * Die strukturierten Personenfelder (#188, `state` seit #256) in
     * Spaltenreihenfolge. Einzige Stelle, an der die Liste steht: INSERT,
     * UPDATE und das Einlesen aus dem POST leiten sich daraus ab. Vorher war
     * sie dreimal ausgeschrieben - ein neues Feld an nur zwei der drei Stellen
     * zu ergänzen hätte still funktioniert und beim Speichern Daten verloren.
     */
    private const STRUCTURED_FIELDS = [
        'street', 'house_number', 'postal_code', 'city', 'state', 'country', 'email',
        'phone', 'mobile', 'website', 'membership_status'
    ];

    /**
     * Strukturierte Personenfelder aus dem POST, jeweils leer -> NULL. Bewusst
     * ohne Formatvalidierung (Freitext-Philosophie wie breed; auch
     * breeding_stations.email wird nicht validiert). Das gilt auch für `state`:
     * Bundesland- und Kantonsnamen sind in DACH uneinheitlich geschrieben, eine
     * ISO-3166-2-Prüfung würde mehr korrekte Eingaben ablehnen als falsche.
     */
    private function parseStructuredFields(): array {
        $fields = [];
        foreach (self::STRUCTURED_FIELDS as $field) {
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
