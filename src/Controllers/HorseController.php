<?php
// src/Controllers/HorseController.php

namespace App\Controllers;

use App\Database;

class HorseController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
    }

    public function index(): void {
        $this->requirePermission('horses', 'view');

        // Optionaler Veröffentlichungs-Filter (?published=1|0), siehe
        // BaseController::normalizePublishedFilter(). Der normalisierte Wert (0/1)
        // wird als gebundener Parameter übergeben statt in die Abfrage interpoliert.
        $publishedFilter = self::normalizePublishedFilter($_GET['published'] ?? null);
        $publishedSql = $publishedFilter === null ? '' : ' AND is_published = ?';

        $db = Database::getInstance();
        $sql = "SELECT id, name, ueln, birth_year, status, is_published, image_url FROM horses WHERE deleted_at IS NULL{$publishedSql} ORDER BY name ASC";
        if ($publishedFilter === null) {
            $stmt = $db->query($sql);
        } else {
            $stmt = $db->prepare($sql);
            $stmt->execute([$publishedFilter]);
        }
        $horses = $stmt->fetchAll();

        $this->render('admin_horses', [
            'title' => 'Pferde verwalten',
            'horses' => $horses,
            'publishedFilter' => $publishedFilter,
            'canCreate' => $this->hasPermission('horses', 'create'),
            'canEdit' => $this->hasPermission('horses', 'edit'),
            'canDelete' => $this->hasPermission('horses', 'delete'),
            'canPublish' => $this->hasPermission('horses', 'publish')
        ]);
    }

    /**
     * Massen-Veröffentlichung / -Depublikation der ausgewählten Pferde. Nur mit
     * 'horses.publish' erlaubt; setzt is_published unabhängig vom Lebenszyklus-Status.
     */
    public function bulkPublish(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }
        $this->requirePermission('horses', 'publish');

        $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($id) => $id > 0));
        $publish = !empty($_POST['publish']) ? 1 : 0;

        if ($ids) {
            $db = Database::getInstance();
            // Einzelne, vollständig parametrisierte UPDATEs statt einer dynamisch
            // zusammengesetzten IN (...)-Liste - inhaltlich identisch, vermeidet aber
            // jede String-Interpolation im SQL (auch die des ?-Platzhalter-Strings).
            $stmt = $db->prepare("UPDATE horses SET is_published = ? WHERE id = ? AND deleted_at IS NULL");
            foreach ($ids as $id) {
                $stmt->execute([$publish, $id]);
            }

            \App\Service\AuditLogger::log(
                $publish ? "Pferde veröffentlicht" : "Veröffentlichung von Pferden zurückgenommen",
                "horses",
                count($ids) . " Datensätze (IDs: " . implode(', ', $ids) . ")"
            );
        }

        header("Location: /admin/horses?success=published" . self::publishedFilterQuery($_POST['published'] ?? null));
        exit;
    }

    public function create(): void {
        $this->requirePermission('horses', 'create');

        $db = Database::getInstance();
        $stmt = $db->query("SELECT id, name, ueln, birth_year FROM horses WHERE deleted_at IS NULL ORDER BY name ASC");
        $allHorses = $stmt->fetchAll();

        $stmt = $db->query("SELECT id, name FROM persons WHERE deleted_at IS NULL ORDER BY name ASC");
        $allPersons = $stmt->fetchAll();

        $stmt = $db->query("SELECT id, name FROM breeding_stations WHERE deleted_at IS NULL ORDER BY name ASC");
        $allBreedingStations = $stmt->fetchAll();

        $this->render('admin_horse_form', [
            'title' => 'Neues Pferd anlegen',
            'horse' => null,
            'allHorses' => $allHorses,
            'allPersons' => $allPersons,
            'allBreedingStations' => $allBreedingStations,
            'horsePersons' => [],
            'canPublish' => $this->hasPermission('horses', 'publish')
        ]);
    }

    public function store(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }
        $this->requirePermission('horses', 'create');

        $name = trim($_POST['name'] ?? '');
        // NULL statt '' bei leerer UELN: die Spalte ist UNIQUE, mehrere Pferde ohne
        // UELN würden sonst als doppelter Leerstring-Eintrag kollidieren (SQLSTATE
        // 23000) - NULL-Werte sind für UNIQUE-Constraints in MySQL/MariaDB dagegen
        // nie doppelt.
        $ueln = trim($_POST['ueln'] ?? '') ?: null;
        $foreign_ueln = trim($_POST['foreign_ueln'] ?? '') ?: null;
        $birth_year = !empty($_POST['birth_year']) ? (int)$_POST['birth_year'] : null;
        $color = trim($_POST['color'] ?? '');
        $breeding_station_id = !empty($_POST['breeding_station_id']) ? (int)$_POST['breeding_station_id'] : null;
        $breeding_station = trim($_POST['breeding_station'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? 'active';

        // Veröffentlichung (öffentliche Sichtbarkeit) ist bewusst UNABHÄNGIG vom
        // Lebenszyklus-Status und wird über ein eigenes Flag gesteuert. Nur mit der
        // Berechtigung 'horses.publish' darf ein Pferd veröffentlicht werden - fehlt
        // sie, bleibt es unveröffentlicht, egal welchen Status es hat.
        $isPublished = (!empty($_POST['is_published']) && $this->hasPermission('horses', 'publish')) ? 1 : 0;

        // Sire handling
        $sire_id = !empty($_POST['sire_id']) ? (int)$_POST['sire_id'] : null;
        $sire_name = $sire_id ? null : (trim($_POST['sire_name'] ?? '') ?: null);
        $sire_ueln = $sire_id ? null : (trim($_POST['sire_ueln'] ?? '') ?: null);

        // Dam handling
        $dam_id = !empty($_POST['dam_id']) ? (int)$_POST['dam_id'] : null;
        $dam_name = $dam_id ? null : (trim($_POST['dam_name'] ?? '') ?: null);
        $dam_ueln = $dam_id ? null : (trim($_POST['dam_ueln'] ?? '') ?: null);

        // Handle Photo Upload
        $imageUrl = $this->handleImageUpload($_FILES['horse_image'] ?? null);

        // Plugin-Hook (#56): Erweiterungspunkt VOR dem Anlegen eines Pferdes, z. B. für
        // zusätzliche verbandsspezifische Prüfungen. Kann das Anlegen selbst nicht
        // blockieren (siehe HookManager-Isolation) - ein fehlerhaftes Plugin darf den
        // Kern-Workflow nie verhindern.
        $this->hooks()->doAction('horse.before_save', null, $_POST);

        $db = Database::getInstance();
        $stmt = $db->prepare("INSERT INTO horses (name, ueln, foreign_ueln, sire_id, sire_name, sire_ueln, dam_id, dam_name, dam_ueln, birth_year, color, breeding_station_id, breeding_station, description, status, is_published, image_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $ueln, $foreign_ueln, $sire_id, $sire_name, $sire_ueln, $dam_id, $dam_name, $dam_ueln, $birth_year, $color, $breeding_station_id, $breeding_station, $description, $status, $isPublished, $imageUrl]);
        $newHorseId = (int)$db->lastInsertId();

        \App\Service\AuditLogger::log("Pferd angelegt", "horses", "Name: {$name}" . ($ueln ? " (UELN: {$ueln})" : ""));

        // Save Person Roles & Ownership History (horse_persons)
        $this->saveHorsePersons($db, $newHorseId, $_POST['persons'] ?? []);

        // Run auto-linking to automatically attach existing unlinked placeholders to this new horse
        $this->autoLinkMatches($newHorseId, $name, $ueln, $foreign_ueln, $birth_year);

        // Plugin-Hook (#56): Erweiterungspunkt NACH dem Anlegen, z. B. für Folgeaktionen
        // in einem Plugin (Benachrichtigung, verknüpfte Zusatzdaten anlegen etc.).
        $this->hooks()->doAction('horse.after_save', $newHorseId, $_POST, true);

        header("Location: /admin/horses?success=created");
        exit;
    }

    public function edit(): void {
        $this->requirePermission('horses', 'edit');

        $id = $_GET['id'] ?? null;
        if (!$id) {
            header("Location: /admin/horses");
            exit;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM horses WHERE id = ?");
        $stmt->execute([$id]);
        $horse = $stmt->fetch();

        if (!$horse) {
            header("Location: /admin/horses");
            exit;
        }

        $stmt = $db->query("SELECT id, name, ueln, birth_year FROM horses WHERE deleted_at IS NULL ORDER BY name ASC");
        $allHorses = $stmt->fetchAll();

        $stmt = $db->query("SELECT id, name FROM persons WHERE deleted_at IS NULL ORDER BY name ASC");
        $allPersons = $stmt->fetchAll();

        $stmt = $db->query("SELECT id, name FROM breeding_stations WHERE deleted_at IS NULL ORDER BY name ASC");
        $allBreedingStations = $stmt->fetchAll();

        $stmt = $db->prepare("SELECT hp.*, p.name, bs.name AS station_name FROM horse_persons hp LEFT JOIN persons p ON hp.person_id = p.id AND p.deleted_at IS NULL LEFT JOIN breeding_stations bs ON hp.breeding_station_id = bs.id WHERE hp.horse_id = ? ORDER BY hp.id ASC");
        $stmt->execute([$id]);
        $horsePersons = $stmt->fetchAll();

        $this->render('admin_horse_form', [
            'title' => 'Pferd bearbeiten',
            'horse' => $horse,
            'allHorses' => $allHorses,
            'allPersons' => $allPersons,
            'allBreedingStations' => $allBreedingStations,
            'horsePersons' => $horsePersons,
            'canPublish' => $this->hasPermission('horses', 'publish')
        ]);
    }

    public function update(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }
        $this->requirePermission('horses', 'edit');

        $id = $_POST['id'] ?? null;
        if (!$id) {
            header("Location: /admin/horses");
            exit;
        }

        $name = trim($_POST['name'] ?? '');
        // NULL statt '' bei leerer UELN: die Spalte ist UNIQUE, mehrere Pferde ohne
        // UELN würden sonst als doppelter Leerstring-Eintrag kollidieren (SQLSTATE
        // 23000) - NULL-Werte sind für UNIQUE-Constraints in MySQL/MariaDB dagegen
        // nie doppelt.
        $ueln = trim($_POST['ueln'] ?? '') ?: null;
        $foreign_ueln = trim($_POST['foreign_ueln'] ?? '') ?: null;
        $birth_year = !empty($_POST['birth_year']) ? (int)$_POST['birth_year'] : null;
        $color = trim($_POST['color'] ?? '');
        $breeding_station_id = !empty($_POST['breeding_station_id']) ? (int)$_POST['breeding_station_id'] : null;
        $breeding_station = trim($_POST['breeding_station'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? 'active';

        // Sire handling
        $sire_id = !empty($_POST['sire_id']) ? (int)$_POST['sire_id'] : null;
        $sire_name = $sire_id ? null : (trim($_POST['sire_name'] ?? '') ?: null);
        $sire_ueln = $sire_id ? null : (trim($_POST['sire_ueln'] ?? '') ?: null);

        // Dam handling
        $dam_id = !empty($_POST['dam_id']) ? (int)$_POST['dam_id'] : null;
        $dam_name = $dam_id ? null : (trim($_POST['dam_name'] ?? '') ?: null);
        $dam_ueln = $dam_id ? null : (trim($_POST['dam_ueln'] ?? '') ?: null);

        // Prevent self-referencing
        if ($sire_id === (int)$id) $sire_id = null;
        if ($dam_id === (int)$id) $dam_id = null;

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT image_url, status, is_published FROM horses WHERE id = ?");
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        $currentImageUrl = $existing['image_url'] ?? null;

        // Veröffentlichung (öffentliche Sichtbarkeit) ist unabhängig vom Status und
        // darf nur mit 'horses.publish' geändert werden. Ohne diese Berechtigung
        // bleibt der bisherige Veröffentlichungszustand unverändert erhalten (ein
        // übermittelter Wunsch wird stillschweigend ignoriert statt gespeichert).
        if ($this->hasPermission('horses', 'publish')) {
            $isPublished = !empty($_POST['is_published']) ? 1 : 0;
        } else {
            $isPublished = (int)($existing['is_published'] ?? 0);
        }

        // Check for remove image request
        if (!empty($_POST['remove_image']) && $currentImageUrl) {
            $filePath = __DIR__ . '/../../public' . $currentImageUrl;
            if (file_exists($filePath)) @unlink($filePath);
            $currentImageUrl = null;
        }

        // Check for new upload
        $newUploadedUrl = $this->handleImageUpload($_FILES['horse_image'] ?? null);
        if ($newUploadedUrl) {
            // Delete old file if present
            if ($currentImageUrl) {
                $filePath = __DIR__ . '/../../public' . $currentImageUrl;
                if (file_exists($filePath)) @unlink($filePath);
            }
            $currentImageUrl = $newUploadedUrl;
        }

        // Plugin-Hook (#56): siehe store() für die Begründung, hier für den Update-Pfad.
        $this->hooks()->doAction('horse.before_save', (int)$id, $_POST);

        $stmt = $db->prepare("UPDATE horses SET name = ?, ueln = ?, foreign_ueln = ?, sire_id = ?, sire_name = ?, sire_ueln = ?, dam_id = ?, dam_name = ?, dam_ueln = ?, birth_year = ?, color = ?, breeding_station_id = ?, breeding_station = ?, description = ?, status = ?, is_published = ?, image_url = ? WHERE id = ?");
        $stmt->execute([$name, $ueln, $foreign_ueln, $sire_id, $sire_name, $sire_ueln, $dam_id, $dam_name, $dam_ueln, $birth_year, $color, $breeding_station_id, $breeding_station, $description, $status, $isPublished, $currentImageUrl, $id]);

        \App\Service\AuditLogger::log("Pferd aktualisiert", "horses", "Pferd ID {$id}: {$name}" . ($ueln ? " (UELN: {$ueln})" : ""));

        // Save Person Roles & Ownership History (horse_persons)
        $this->saveHorsePersons($db, (int)$id, $_POST['persons'] ?? []);

        // Run auto-linking for matches
        $this->autoLinkMatches((int)$id, $name, $ueln, $foreign_ueln, $birth_year);

        // Plugin-Hook (#56): siehe store() für die Begründung, hier für den Update-Pfad.
        $this->hooks()->doAction('horse.after_save', (int)$id, $_POST, false);

        header("Location: /admin/horses?success=updated");
        exit;
    }

    /**
     * Helper to validate and save uploaded horse image
     */
    private function handleImageUpload(?array $file): ?string {
        if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] === 0) {
            return null;
        }

        // Max 5MB
        if ($file['size'] > 5 * 1024 * 1024) {
            return null;
        }

        // Validate MIME type
        $allowedMimeTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);

        if (!isset($allowedMimeTypes[$mime])) {
            return null;
        }

        $ext = $allowedMimeTypes[$mime];
        $uploadDir = __DIR__ . '/../../public/uploads/horses/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filename = 'horse_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $targetPath = $uploadDir . $filename;

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            return '/uploads/horses/' . $filename;
        }

        return null;
    }

    /**
     * Auto-links unlinked placeholders matching $ueln, $foreignUeln or $name to $horseId
     */
    private function autoLinkMatches(int $horseId, string $name, ?string $ueln, ?string $foreignUeln = null, ?int $birthYear = null): void {
        $db = Database::getInstance();

        $uelnsToMatch = array_unique(array_filter([trim($ueln ?? ''), trim($foreignUeln ?? '')]));

        foreach ($uelnsToMatch as $u) {
            // Auto-link Sires matching UELN or Foreign UELN (UELN ist eindeutig, keine Mehrdeutigkeit möglich)
            $stmt = $db->prepare("UPDATE horses SET sire_id = ?, sire_name = NULL, sire_ueln = NULL WHERE sire_id IS NULL AND sire_ueln = ?");
            $stmt->execute([$horseId, $u]);
            $countSires = $stmt->rowCount();
            if ($countSires > 0) {
                \App\Service\AuditLogger::log("Automatische Zusammenführung", "horses", "{$countSires} Nachkommen automatisch mit Vater ID {$horseId} (UELN: {$u}) verknüpft");
            }

            // Auto-link Dams matching UELN or Foreign UELN
            $stmt = $db->prepare("UPDATE horses SET dam_id = ?, dam_name = NULL, dam_ueln = NULL WHERE dam_id IS NULL AND dam_ueln = ?");
            $stmt->execute([$horseId, $u]);
            $countDams = $stmt->rowCount();
            if ($countDams > 0) {
                \App\Service\AuditLogger::log("Automatische Zusammenführung", "horses", "{$countDams} Nachkommen automatisch mit Mutter ID {$horseId} (UELN: {$u}) verknüpft");
            }
        }

        if (!empty($name)) {
            // Namensbasiertes Auto-Linking nur, wenn der Name in der Datenbank eindeutig ist -
            // bei mehreren gleichnamigen Pferden kann nicht sicher bestimmt werden, welches
            // davon tatsächlich gemeint ist (siehe #41). Mehrdeutige Fälle bleiben als
            // Platzhalter stehen und tauchen stattdessen im manuellen Match-Tool auf.
            $stmt = $db->prepare("SELECT COUNT(*) FROM horses WHERE deleted_at IS NULL AND LOWER(name) = LOWER(?) AND id != ?");
            $stmt->execute([$name, $horseId]);
            $nameIsAmbiguous = (int)$stmt->fetchColumn() > 0;

            if (!$nameIsAmbiguous) {
                // Zusätzlich nur bei plausiblem Elternalter verknüpfen (3-30 Jahre älter als
                // das Kind), analog zur "plausibel"-Schwelle im manuellen Match-Tool
                // (calculateSuggestions()). Fehlt ein Geburtsjahr, ist keine Prüfung möglich -
                // dann wie bisher ohne Alters-Einschränkung verknüpfen.
                $ageCondition = '';
                $ageParams = [];
                if ($birthYear !== null) {
                    $ageCondition = " AND (birth_year IS NULL OR (birth_year - ?) BETWEEN 3 AND 30)";
                    $ageParams = [$birthYear];
                }

                // Auto-link Sires matching exact Name (where sire_ueln is empty)
                $stmt = $db->prepare("UPDATE horses SET sire_id = ?, sire_name = NULL, sire_ueln = NULL WHERE sire_id IS NULL AND (sire_ueln IS NULL OR sire_ueln = '') AND LOWER(sire_name) = LOWER(?){$ageCondition}");
                $stmt->execute([$horseId, $name, ...$ageParams]);
                $countNameSires = $stmt->rowCount();
                if ($countNameSires > 0) {
                    \App\Service\AuditLogger::log("Automatische Zusammenführung", "horses", "{$countNameSires} Nachkommen anhand Name '{$name}' mit Vater ID {$horseId} verknüpft");
                }

                // Auto-link Dams matching exact Name (where dam_ueln is empty)
                $stmt = $db->prepare("UPDATE horses SET dam_id = ?, dam_name = NULL, dam_ueln = NULL WHERE dam_id IS NULL AND (dam_ueln IS NULL OR dam_ueln = '') AND LOWER(dam_name) = LOWER(?){$ageCondition}");
                $stmt->execute([$horseId, $name, ...$ageParams]);
                $countNameDams = $stmt->rowCount();
                if ($countNameDams > 0) {
                    \App\Service\AuditLogger::log("Automatische Zusammenführung", "horses", "{$countNameDams} Nachkommen anhand Name '{$name}' mit Mutter ID {$horseId} verknüpft");
                }
            }
        }
    }

    /**
     * Merge Tool: Scans DB for placeholders and suggests probabilities
     */
    public function matches(): void {
        $this->requirePermission('horses', 'view');
        $this->requirePermission('horses', 'edit');

        $unlinkedMatches = \App\Service\MatchSuggestionFinder::findAll();

        $db = Database::getInstance();
        $allHorses = $db->query("SELECT id, name, ueln, foreign_ueln, birth_year, color, breeding_station_id, breeding_station FROM horses WHERE deleted_at IS NULL ORDER BY name ASC")->fetchAll();

        $this->render('admin_matches', [
            'title' => 'Blutlinien Zusammenführen & Match-Vorschläge',
            'unlinkedMatches' => $unlinkedMatches,
            'allHorses' => $allHorses
        ]);
    }

    /**
     * Link/Merge a parent match manually or via suggestion
     */
    public function linkMatch(): void {
        $this->requirePermission('horses', 'edit');

        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $childId = (int)($_POST['child_id'] ?? 0);
        $parentType = $_POST['parent_type'] ?? ''; // 'sire' or 'dam'
        $parentHorseId = (int)($_POST['parent_horse_id'] ?? 0);

        if ($childId && $parentHorseId && in_array($parentType, ['sire', 'dam'])) {
            $db = Database::getInstance();

            // Fetch names for audit log
            $stmt = $db->prepare("SELECT name FROM horses WHERE id = ?");
            $stmt->execute([$childId]);
            $childName = $stmt->fetchColumn() ?: "Pferd #{$childId}";

            $stmt->execute([$parentHorseId]);
            $parentName = $stmt->fetchColumn() ?: "Pferd #{$parentHorseId}";

            $roleLabel = ($parentType === 'sire') ? 'Vater (Hengst)' : 'Mutter (Stute)';

            if ($parentType === 'sire') {
                $stmt = $db->prepare("UPDATE horses SET sire_id = ?, sire_name = NULL, sire_ueln = NULL WHERE id = ?");
            } else {
                $stmt = $db->prepare("UPDATE horses SET dam_id = ?, dam_name = NULL, dam_ueln = NULL WHERE id = ?");
            }
            $stmt->execute([$parentHorseId, $childId]);

            \App\Service\AuditLogger::log(
                "Abstammung zusammengeführt",
                "horses",
                "Kind '{$childName}' (ID {$childId}) mit {$roleLabel} '{$parentName}' (ID {$parentHorseId}) verknüpft"
            );
        }

        header("Location: /admin/matches?success=linked");
        exit;
    }

    public function delete(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }
        $this->requirePermission('horses', 'delete');

        $id = $_POST['id'] ?? null;
        if ($id) {
            $db = Database::getInstance();

            // Fetch name for audit log
            $stmt = $db->prepare("SELECT name FROM horses WHERE id = ?");
            $stmt->execute([$id]);
            $horseName = $stmt->fetchColumn() ?: 'Unbekannt';

            $stmt = $db->prepare("UPDATE horses SET deleted_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);

            \App\Service\AuditLogger::log("Pferd in Papierkorb verschoben", "horses", "Pferd ID {$id}: {$horseName}");
        }

        header("Location: /admin/horses?success=deleted");
        exit;
    }

    /**
     * Save person roles & ownership history in horse_persons table
     */
    private function saveHorsePersons(\PDO $db, int $horseId, array $personsData): void {
        // Clear existing relations
        $stmt = $db->prepare("DELETE FROM horse_persons WHERE horse_id = ?");
        $stmt->execute([$horseId]);

        $insertStmt = $db->prepare("INSERT INTO horse_persons (horse_id, person_id, role, breeding_station_id, breeding_station_text, from_year, until_year) VALUES (?, ?, ?, ?, ?, ?, ?)");

        $validRoles = ['breeder', 'owner', 'keeper'];

        $currentStationId = null;
        $currentStationText = null;
        $highestScore = -1;

        foreach ($personsData as $item) {
            $personId = !empty($item['person_id']) ? (int)$item['person_id'] : null;
            $role = $item['role'] ?? 'owner';
            $stationId = !empty($item['breeding_station_id']) ? (int)$item['breeding_station_id'] : null;
            $stationText = trim($item['breeding_station_text'] ?? '');
            
            // Breeders do not have a time period!
            if ($role === 'breeder') {
                $fromYear = null;
                $untilYear = null;
            } else {
                $fromYear = !empty($item['from_year']) ? (int)$item['from_year'] : null;
                $untilYear = !empty($item['until_year']) ? (int)$item['until_year'] : null;
            }

            // Calculate score to identify the current/latest active breeding station
            if ($stationId || $stationText) {
                // If until_year IS NULL, the entry is currently active -> boost score with 99999 + from_year
                $calcFrom = $fromYear ?: 0;
                $score = ($untilYear === null && $role !== 'breeder') ? (99999 + $calcFrom) : ($untilYear ?: $calcFrom);

                if ($score >= $highestScore) {
                    $highestScore = $score;
                    if ($stationId) {
                        $currentStationId = $stationId;
                        $stStmt = $db->prepare("SELECT name FROM breeding_stations WHERE id = ?");
                        $stStmt->execute([$stationId]);
                        $currentStationText = $stStmt->fetchColumn() ?: null;
                    } else {
                        $currentStationId = null;
                        $currentStationText = $stationText;
                    }
                }
            }

            // Validation: Row must have a valid Role/Type AND at least one of Person OR Breeding Station (2 of the fields)
            $hasPerson = !empty($personId);
            $hasStation = !empty($stationId) || !empty($stationText);
            $hasValidRole = in_array($role, $validRoles, true);

            if ($hasValidRole && ($hasPerson || $hasStation)) {
                $insertStmt->execute([$horseId, $personId ?: null, $role, $stationId ?: null, $stationText ?: null, $fromYear, $untilYear]);
            }
        }

        // Automatically sync current/latest active breeding station onto the horse's main record
        $syncStmt = $db->prepare("UPDATE horses SET breeding_station_id = ?, breeding_station = ? WHERE id = ?");
        $syncStmt->execute([$currentStationId, $currentStationText, $horseId]);
    }
}
