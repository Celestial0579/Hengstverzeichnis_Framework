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
        $db = Database::getInstance();
        $stmt = $db->query("SELECT id, name, ueln, birth_year, status, image_url FROM horses WHERE deleted_at IS NULL ORDER BY name ASC");
        $horses = $stmt->fetchAll();

        $this->render('admin_horses', [
            'title' => 'Pferde verwalten',
            'horses' => $horses
        ]);
    }

    public function create(): void {
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
            'horsePersons' => []
        ]);
    }

    public function store(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $name = trim($_POST['name'] ?? '');
        $ueln = trim($_POST['ueln'] ?? '');
        $foreign_ueln = trim($_POST['foreign_ueln'] ?? '');
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

        // Handle Photo Upload
        $imageUrl = $this->handleImageUpload($_FILES['horse_image'] ?? null);

        $db = Database::getInstance();
        $stmt = $db->prepare("INSERT INTO horses (name, ueln, foreign_ueln, sire_id, sire_name, sire_ueln, dam_id, dam_name, dam_ueln, birth_year, color, breeding_station_id, breeding_station, description, status, image_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $ueln, $foreign_ueln, $sire_id, $sire_name, $sire_ueln, $dam_id, $dam_name, $dam_ueln, $birth_year, $color, $breeding_station_id, $breeding_station, $description, $status, $imageUrl]);
        $newHorseId = (int)$db->lastInsertId();

        \App\Service\AuditLogger::log("Pferd angelegt", "horses", "Name: {$name}" . ($ueln ? " (UELN: {$ueln})" : ""));

        // Save Person Roles & Ownership History (horse_persons)
        $this->saveHorsePersons($db, $newHorseId, $_POST['persons'] ?? []);

        // Run auto-linking to automatically attach existing unlinked placeholders to this new horse
        $this->autoLinkMatches($newHorseId, $name, $ueln, $foreign_ueln);

        header("Location: /admin/horses?success=created");
        exit;
    }

    public function edit(): void {
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
            'horsePersons' => $horsePersons
        ]);
    }

    public function update(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $id = $_POST['id'] ?? null;
        if (!$id) {
            header("Location: /admin/horses");
            exit;
        }

        $name = trim($_POST['name'] ?? '');
        $ueln = trim($_POST['ueln'] ?? '');
        $foreign_ueln = trim($_POST['foreign_ueln'] ?? '');
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
        $stmt = $db->prepare("SELECT image_url FROM horses WHERE id = ?");
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        $currentImageUrl = $existing['image_url'] ?? null;

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

        $stmt = $db->prepare("UPDATE horses SET name = ?, ueln = ?, foreign_ueln = ?, sire_id = ?, sire_name = ?, sire_ueln = ?, dam_id = ?, dam_name = ?, dam_ueln = ?, birth_year = ?, color = ?, breeding_station_id = ?, breeding_station = ?, description = ?, status = ?, image_url = ? WHERE id = ?");
        $stmt->execute([$name, $ueln, $foreign_ueln, $sire_id, $sire_name, $sire_ueln, $dam_id, $dam_name, $dam_ueln, $birth_year, $color, $breeding_station_id, $breeding_station, $description, $status, $currentImageUrl, $id]);

        \App\Service\AuditLogger::log("Pferd aktualisiert", "horses", "Pferd ID {$id}: {$name}" . ($ueln ? " (UELN: {$ueln})" : ""));

        // Save Person Roles & Ownership History (horse_persons)
        $this->saveHorsePersons($db, (int)$id, $_POST['persons'] ?? []);

        // Run auto-linking for matches
        $this->autoLinkMatches((int)$id, $name, $ueln, $foreign_ueln);

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
    private function autoLinkMatches(int $horseId, string $name, string $ueln, string $foreignUeln = ''): void {
        $db = Database::getInstance();

        $uelnsToMatch = array_unique(array_filter([trim($ueln), trim($foreignUeln)]));

        foreach ($uelnsToMatch as $u) {
            // Auto-link Sires matching UELN or Foreign UELN
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
            // Auto-link Sires matching exact Name (where sire_ueln is empty)
            $stmt = $db->prepare("UPDATE horses SET sire_id = ?, sire_name = NULL, sire_ueln = NULL WHERE sire_id IS NULL AND (sire_ueln IS NULL OR sire_ueln = '') AND LOWER(sire_name) = LOWER(?)");
            $stmt->execute([$horseId, $name]);
            $countNameSires = $stmt->rowCount();
            if ($countNameSires > 0) {
                \App\Service\AuditLogger::log("Automatische Zusammenführung", "horses", "{$countNameSires} Nachkommen anhand Name '{$name}' mit Vater ID {$horseId} verknüpft");
            }

            // Auto-link Dams matching exact Name (where dam_ueln is empty)
            $stmt = $db->prepare("UPDATE horses SET dam_id = ?, dam_name = NULL, dam_ueln = NULL WHERE dam_id IS NULL AND (dam_ueln IS NULL OR dam_ueln = '') AND LOWER(dam_name) = LOWER(?)");
            $stmt->execute([$horseId, $name]);
            $countNameDams = $stmt->rowCount();
            if ($countNameDams > 0) {
                \App\Service\AuditLogger::log("Automatische Zusammenführung", "horses", "{$countNameDams} Nachkommen anhand Name '{$name}' mit Mutter ID {$horseId} verknüpft");
            }
        }
    }

    /**
     * Merge Tool: Scans DB for placeholders and suggests probabilities
     */
    public function matches(): void {
        $db = Database::getInstance();

        // Get all unlinked sire placeholders
        $stmt = $db->query("SELECT id, name, ueln, foreign_ueln, birth_year, color, breeding_station_id, breeding_station, sire_name, sire_ueln FROM horses WHERE deleted_at IS NULL AND sire_id IS NULL AND (sire_name IS NOT NULL OR sire_ueln IS NOT NULL)");
        $sirePlaceholders = $stmt->fetchAll();

        // Get all unlinked dam placeholders
        $stmt = $db->query("SELECT id, name, ueln, foreign_ueln, birth_year, color, breeding_station_id, breeding_station, dam_name, dam_ueln FROM horses WHERE deleted_at IS NULL AND dam_id IS NULL AND (dam_name IS NOT NULL OR dam_ueln IS NOT NULL)");
        $damPlaceholders = $stmt->fetchAll();

        // Fetch all existing active horses for matching
        $stmt = $db->query("SELECT id, name, ueln, foreign_ueln, birth_year, color, breeding_station_id, breeding_station FROM horses WHERE deleted_at IS NULL ORDER BY name ASC");
        $allHorses = $stmt->fetchAll();

        $unlinkedMatches = [];

        // Calculate matches for Sires
        foreach ($sirePlaceholders as $sp) {
            $suggestions = $this->calculateSuggestions($sp['sire_name'], $sp['sire_ueln'], $sp, $allHorses);
            if (!empty($suggestions)) {
                $unlinkedMatches[] = [
                    'child_id' => $sp['id'],
                    'child_name' => $sp['name'],
                    'parent_type' => 'sire',
                    'parent_type_label' => 'Vater',
                    'placeholder_name' => $sp['sire_name'],
                    'placeholder_ueln' => $sp['sire_ueln'],
                    'suggestions' => $suggestions
                ];
            }
        }

        // Calculate matches for Dams
        foreach ($damPlaceholders as $dp) {
            $suggestions = $this->calculateSuggestions($dp['dam_name'], $dp['dam_ueln'], $dp, $allHorses);
            if (!empty($suggestions)) {
                $unlinkedMatches[] = [
                    'child_id' => $dp['id'],
                    'child_name' => $dp['name'],
                    'parent_type' => 'dam',
                    'parent_type_label' => 'Mutter',
                    'placeholder_name' => $dp['dam_name'],
                    'placeholder_ueln' => $dp['dam_ueln'],
                    'suggestions' => $suggestions
                ];
            }
        }

        $this->render('admin_matches', [
            'title' => 'Blutlinien Zusammenführen & Match-Vorschläge',
            'unlinkedMatches' => $unlinkedMatches,
            'allHorses' => $allHorses
        ]);
    }

    /**
     * Calculates multi-field probability score (%) for matching placeholder against candidate horses
     */
    private function calculateSuggestions(?string $pName, ?string $pUeln, array $childHorse, array $allHorses): array {
        $suggestions = [];
        $pNameClean = strtolower(trim($pName ?? ''));
        $pUelnClean = strtolower(trim($pUeln ?? ''));

        foreach ($allHorses as $candidate) {
            if ($candidate['id'] == $childHorse['id']) continue;

            $candNameClean = strtolower(trim($candidate['name'] ?? ''));
            $candUelnClean = strtolower(trim($candidate['ueln'] ?? ''));
            $candForeignUelnClean = strtolower(trim($candidate['foreign_ueln'] ?? ''));

            $points = 0;
            $reasons = [];

            // 1. UELN Match (Max 45 Points)
            $hasUelnMatch = false;
            if (!empty($pUelnClean)) {
                if ($pUelnClean === $candUelnClean) {
                    $points += 45;
                    $reasons[] = "✓ Haupt-UELN übereinstimmend";
                    $hasUelnMatch = true;
                } else if (!empty($candForeignUelnClean) && $pUelnClean === $candForeignUelnClean) {
                    $points += 45;
                    $reasons[] = "✓ Ausländische UELN übereinstimmend";
                    $hasUelnMatch = true;
                }
            }

            // 2. Name Similarity (Max 35 Points)
            $hasStrongNameMatch = false;
            if (!empty($pNameClean) && !empty($candNameClean)) {
                similar_text($pNameClean, $candNameClean, $percent);
                $namePoints = round(($percent / 100) * 35);
                $points += $namePoints;

                if ($percent >= 90) {
                    $reasons[] = "✓ Name nahezu identisch (" . round($percent) . "%)";
                    $hasStrongNameMatch = true;
                } else if ($percent >= 70) {
                    $reasons[] = "✓ Name hohe Ähnlichkeit (" . round($percent) . "%)";
                } else if ($percent >= 50) {
                    $reasons[] = "Name ähnliche Schreibweise (" . round($percent) . "%)";
                }
            }

            // 3. Birth Year Plausibility (Max 12 Points or Penalty)
            $childYear = (int)($childHorse['birth_year'] ?? 0);
            $candYear = (int)($candidate['birth_year'] ?? 0);

            if ($childYear > 0 && $candYear > 0) {
                $ageDiff = $childYear - $candYear;
                if ($ageDiff >= 3 && $ageDiff <= 30) {
                    $points += 12;
                    $reasons[] = "✓ Plausibles Elternalter (" . $ageDiff . " Jahre älter)";
                } else if ($ageDiff >= 1 && $ageDiff < 3) {
                    $points += 5;
                    $reasons[] = "Grenzwertiges Alter (" . $ageDiff . " Jahre älter)";
                } else if ($ageDiff <= 0) {
                    $points -= 35; // Severe penalty: parent born after or same year as child
                    $reasons[] = "⚠️ Unmögliches Alter (Kandidat jünger/gleich alt)";
                } else if ($ageDiff > 35) {
                    $points -= 15;
                    $reasons[] = "⚠️ Unwahrscheinlicher Altersabstand (" . $ageDiff . " Jahre)";
                }
            }

            // 4. Breeding Station Match (Max 4 Points)
            if (!empty($childHorse['breeding_station_id']) && !empty($candidate['breeding_station_id']) && $childHorse['breeding_station_id'] == $candidate['breeding_station_id']) {
                $points += 4;
                $reasons[] = "✓ Identische Deckstation";
            } else if (!empty($childHorse['breeding_station']) && !empty($candidate['breeding_station']) && strtolower(trim($childHorse['breeding_station'])) === strtolower(trim($candidate['breeding_station']))) {
                $points += 4;
                $reasons[] = "✓ Identische Deckstation (Freitext)";
            }

            // 5. Color Match (Max 4 Points)
            if (!empty($childHorse['color']) && !empty($candidate['color']) && strtolower(trim($childHorse['color'])) === strtolower(trim($candidate['color']))) {
                $points += 4;
                $reasons[] = "✓ Gleiche Fellfarbe";
            }

            // Calculate final percentage score (0% - 100%)
            $score = min(100, max(0, $points));

            if ($score >= 45 || $hasUelnMatch || $hasStrongNameMatch) {
                $suggestions[] = [
                    'horse' => $candidate,
                    'score' => $score,
                    'reasons' => $reasons
                ];
            }
        }

        // Sort suggestions by highest score descending
        usort($suggestions, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return array_slice($suggestions, 0, 5); // Return top 5
    }

    /**
     * Link/Merge a parent match manually or via suggestion
     */
    public function linkMatch(): void {
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
