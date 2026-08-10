<?php
// src/Controllers/HorseController.php

namespace App\Controllers;

use App\Database;

class HorseController extends BaseController {

    /** Gültige Werte der horses.sex-ENUM (#165); NULL = unbekannt. */
    private const SEXES = ['stallion', 'mare', 'gelding'];

    /** Gültige Werte der horses.status-ENUM (Zuchtstatus seit #188). */
    private const STATUSES = ['active', 'inactive'];

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
        $sql = "SELECT id, name, ueln, birth_year, status, is_deceased, is_published, image_url FROM horses WHERE deleted_at IS NULL{$publishedSql} ORDER BY name ASC";
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
        $stmt = $db->query("SELECT id, name, ueln, birth_year, sex FROM horses WHERE deleted_at IS NULL ORDER BY name ASC");
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
        // Geburtsdatum (#188) ist führend: wenn gesetzt, wird birth_year daraus
        // abgeleitet und ein abweichend übermitteltes Jahr ignoriert.
        $birth_date = $this->parseBirthDate($_POST['birth_date'] ?? '');
        if ($birth_date !== null) {
            $birth_year = (int)substr($birth_date, 0, 4);
        }
        $color = trim($_POST['color'] ?? '');
        $sex = in_array($_POST['sex'] ?? '', self::SEXES, true) ? $_POST['sex'] : null;
        $breed = trim($_POST['breed'] ?? '') ?: null;
        $height_cm = $this->parseHeightCm($_POST['height_cm'] ?? '');
        $breeding_station_id = !empty($_POST['breeding_station_id']) ? (int)$_POST['breeding_station_id'] : null;
        // Freitext-Deckstation nur übernehmen, wenn das Feld überhaupt übermittelt
        // wurde (#214): das Formular kennt kein name="breeding_station" mehr (nur
        // persons[N][breeding_station_id]), regulär setzt den Wert der CSV-Import.
        // NULL bedeutet hier "nicht übermittelt" - beim INSERT bleibt die Spalte
        // dann leer statt auf '' gesetzt.
        $breeding_station = array_key_exists('breeding_station', $_POST) ? trim($_POST['breeding_station']) : null;
        $description = trim($_POST['description'] ?? '');
        $status = in_array($_POST['status'] ?? '', self::STATUSES, true) ? $_POST['status'] : 'active';
        // Lebensstatus (#188): ein gesetztes Todesjahr impliziert verstorben.
        $death_year = $this->parseYear($_POST['death_year'] ?? '');
        $is_deceased = (!empty($_POST['is_deceased']) || $death_year !== null) ? 1 : 0;

        // Todesjahr vor Geburtsjahr ist unmöglich - vor dem Bild-Upload prüfen,
        // damit bei Ablehnung keine verwaiste Datei zurückbleibt.
        if ($death_year !== null && $birth_year !== null && $death_year < $birth_year) {
            header("Location: /admin/horses?error=death_before_birth");
            exit;
        }

        // Veröffentlichung (öffentliche Sichtbarkeit) ist bewusst UNABHÄNGIG vom
        // Zucht-/Lebensstatus und wird über ein eigenes Flag gesteuert. Nur mit der
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

        // Geschlechts-Validierung der Abstammung (#166) - vor dem Bild-Upload,
        // damit bei Ablehnung keine verwaiste Datei zurückbleibt.
        if ($error = $this->parentSexMismatch($sire_id, $dam_id)) {
            header("Location: /admin/horses?error={$error}");
            exit;
        }

        // Handle Photo Upload
        $imageUrl = $this->handleImageUpload($_FILES['horse_image'] ?? null);

        // Plugin-Hook (#56): Erweiterungspunkt VOR dem Anlegen eines Pferdes, z. B. für
        // zusätzliche verbandsspezifische Prüfungen. Kann das Anlegen selbst nicht
        // blockieren (siehe HookManager-Isolation) - ein fehlerhaftes Plugin darf den
        // Kern-Workflow nie verhindern.
        $this->hooks()->doAction('horse.before_save', null, $_POST);

        $db = Database::getInstance();
        $stmt = $db->prepare("INSERT INTO horses (name, ueln, foreign_ueln, sire_id, sire_name, sire_ueln, dam_id, dam_name, dam_ueln, birth_year, birth_date, color, sex, breed, height_cm, breeding_station_id, breeding_station, description, status, is_deceased, death_year, is_published, image_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $ueln, $foreign_ueln, $sire_id, $sire_name, $sire_ueln, $dam_id, $dam_name, $dam_ueln, $birth_year, $birth_date, $color, $sex, $breed, $height_cm, $breeding_station_id, $breeding_station, $description, $status, $is_deceased, $death_year, $isPublished, $imageUrl]);
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

        $stmt = $db->query("SELECT id, name, ueln, birth_year, sex FROM horses WHERE deleted_at IS NULL ORDER BY name ASC");
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
        // Geburtsdatum (#188) ist führend, siehe store().
        $birth_date = $this->parseBirthDate($_POST['birth_date'] ?? '');
        if ($birth_date !== null) {
            $birth_year = (int)substr($birth_date, 0, 4);
        }
        $color = trim($_POST['color'] ?? '');
        $sex = in_array($_POST['sex'] ?? '', self::SEXES, true) ? $_POST['sex'] : null;
        $breed = trim($_POST['breed'] ?? '') ?: null;
        $height_cm = $this->parseHeightCm($_POST['height_cm'] ?? '');
        $breeding_station_id = !empty($_POST['breeding_station_id']) ? (int)$_POST['breeding_station_id'] : null;
        // Freitext-Deckstation nur überschreiben, wenn das Feld übermittelt wurde
        // (#214): das Bearbeiten-Formular kennt kein name="breeding_station" mehr,
        // ein normaler Edit lieferte daher immer '' und löschte damit still den
        // z. B. per CSV-Import gesetzten Wert. NULL heißt "nicht übermittelt" und
        // lässt den Bestandswert im UPDATE unten per COALESCE unangetastet; ein
        // übermittelter Leerstring löscht dagegen weiterhin bewusst.
        $breeding_station = array_key_exists('breeding_station', $_POST) ? trim($_POST['breeding_station']) : null;
        $description = trim($_POST['description'] ?? '');
        $status = in_array($_POST['status'] ?? '', self::STATUSES, true) ? $_POST['status'] : 'active';
        // Lebensstatus (#188): ein gesetztes Todesjahr impliziert verstorben.
        $death_year = $this->parseYear($_POST['death_year'] ?? '');
        $is_deceased = (!empty($_POST['is_deceased']) || $death_year !== null) ? 1 : 0;

        // Todesjahr vor Geburtsjahr ist unmöglich - vor Bild-Änderungen prüfen
        // (gleiche Begründung wie bei parentSexMismatch unten).
        if ($death_year !== null && $birth_year !== null && $death_year < $birth_year) {
            header("Location: /admin/horses?error=death_before_birth");
            exit;
        }

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

        // Geschlechts-Validierung der Abstammung (#166) - vor Bild-Änderungen,
        // damit bei Ablehnung weder Dateien gelöscht noch verwaiste angelegt werden.
        if ($error = $this->parentSexMismatch($sire_id, $dam_id)) {
            header("Location: /admin/horses?error={$error}");
            exit;
        }

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

        // breeding_station = COALESCE(?, breeding_station) (#214): NULL steht für
        // "Feld nicht übermittelt" (siehe oben) und erhält den Bestandswert.
        $stmt = $db->prepare("UPDATE horses SET name = ?, ueln = ?, foreign_ueln = ?, sire_id = ?, sire_name = ?, sire_ueln = ?, dam_id = ?, dam_name = ?, dam_ueln = ?, birth_year = ?, birth_date = ?, color = ?, sex = ?, breed = ?, height_cm = ?, breeding_station_id = ?, breeding_station = COALESCE(?, breeding_station), description = ?, status = ?, is_deceased = ?, death_year = ?, is_published = ?, image_url = ? WHERE id = ?");
        $stmt->execute([$name, $ueln, $foreign_ueln, $sire_id, $sire_name, $sire_ueln, $dam_id, $dam_name, $dam_ueln, $birth_year, $birth_date, $color, $sex, $breed, $height_cm, $breeding_station_id, $breeding_station, $description, $status, $is_deceased, $death_year, $isPublished, $currentImageUrl, $id]);

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
     * Stockmaß (#188) aus dem Formular: nur Ganzzahlen im plausiblen Bereich
     * 50-250 cm (identisch zum CSV-Import), alles andere wird zu NULL - die
     * min/max-Attribute des Formulars sind rein clientseitig, und ein Wert
     * jenseits von SMALLINT UNSIGNED liefe sonst in einen DB-Fehler nach dem
     * Bild-Upload (verwaiste Datei).
     */
    private function parseHeightCm(string $value): ?int {
        $value = trim($value);
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }
        $height = (int)$value;
        return ($height >= 50 && $height <= 250) ? $height : null;
    }

    /**
     * Jahresangabe (#188, Todesjahr) aus dem Formular: Ganzzahl im selben
     * Bereich wie der CSV-Import (1600 bis Folgejahr), sonst NULL - gleiche
     * Begründung wie bei parseHeightCm().
     */
    private function parseYear(string $value): ?int {
        $value = trim($value);
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }
        $year = (int)$value;
        return ($year >= 1600 && $year <= (int)date('Y') + 1) ? $year : null;
    }

    /**
     * Geburtsdatum (#188) aus dem Formular: erwartet YYYY-MM-DD (input
     * type="date"), verlangt ein reales Kalenderdatum und denselben
     * Jahresbereich wie der CSV-Import (1600 bis Folgejahr). Alles andere
     * wird zu NULL - das Formular behandelt das Feld als optional, die
     * strenge Variante mit Zeilenfehlern lebt im HorseCsvImporter.
     */
    private function parseBirthDate(string $value): ?string {
        $value = trim($value);
        if ($value === '' || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return null;
        }
        [, $year, $month, $day] = $m;
        if (!checkdate((int)$month, (int)$day, (int)$year)) {
            return null;
        }
        if ((int)$year < 1600 || (int)$year > (int)date('Y') + 1) {
            return null;
        }
        return $value;
    }

    /**
     * Geschlechts-Validierung der Abstammung (#166): Der Vater darf keine Stute
     * sein, die Mutter weder Hengst noch Wallach. NULL (unbekannt) besteht die
     * Prüfung immer - so bleibt der Altbestand ohne Geschlechtsangabe editierbar.
     * Ein Wallach ist als Vater serverseitig zulässig (Nachkommen können vor dem
     * Legen entstanden sein); das Formular bietet ihn lediglich nicht an.
     * Liefert den Fehlercode für den Redirect oder null, wenn alles passt.
     */
    private function parentSexMismatch(?int $sireId, ?int $damId): ?string {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT sex FROM horses WHERE id = ?");

        if ($sireId) {
            $stmt->execute([$sireId]);
            if ($stmt->fetchColumn() === 'mare') {
                return 'sex_mismatch_sire';
            }
        }
        if ($damId) {
            $stmt->execute([$damId]);
            $sex = $stmt->fetchColumn();
            if ($sex === 'stallion' || $sex === 'gelding') {
                return 'sex_mismatch_dam';
            }
        }
        return null;
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

        // Alle UPDATEs schließen die eben gespeicherte Zeile selbst aus (AND id != ?):
        // ohne diesen Guard kann sich ein Pferd selbst als Elternteil zugewiesen
        // bekommen (z. B. eigene UELN im Vater-UELN-Feld oder gleichlautender
        // Freitext-Vatername) - ein Stammbaum-Zyklus (#131).
        foreach ($uelnsToMatch as $u) {
            // Auto-link Sires matching UELN or Foreign UELN (UELN ist eindeutig, keine Mehrdeutigkeit möglich)
            $stmt = $db->prepare("UPDATE horses SET sire_id = ?, sire_name = NULL, sire_ueln = NULL WHERE sire_id IS NULL AND sire_ueln = ? AND id != ?");
            $stmt->execute([$horseId, $u, $horseId]);
            $countSires = $stmt->rowCount();
            if ($countSires > 0) {
                \App\Service\AuditLogger::log("Automatische Zusammenführung", "horses", "{$countSires} Nachkommen automatisch mit Vater ID {$horseId} (UELN: {$u}) verknüpft");
            }

            // Auto-link Dams matching UELN or Foreign UELN
            $stmt = $db->prepare("UPDATE horses SET dam_id = ?, dam_name = NULL, dam_ueln = NULL WHERE dam_id IS NULL AND dam_ueln = ? AND id != ?");
            $stmt->execute([$horseId, $u, $horseId]);
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
                $stmt = $db->prepare("UPDATE horses SET sire_id = ?, sire_name = NULL, sire_ueln = NULL WHERE sire_id IS NULL AND (sire_ueln IS NULL OR sire_ueln = '') AND LOWER(sire_name) = LOWER(?) AND id != ?{$ageCondition}");
                $stmt->execute([$horseId, $name, $horseId, ...$ageParams]);
                $countNameSires = $stmt->rowCount();
                if ($countNameSires > 0) {
                    \App\Service\AuditLogger::log("Automatische Zusammenführung", "horses", "{$countNameSires} Nachkommen anhand Name '{$name}' mit Vater ID {$horseId} verknüpft");
                }

                // Auto-link Dams matching exact Name (where dam_ueln is empty)
                $stmt = $db->prepare("UPDATE horses SET dam_id = ?, dam_name = NULL, dam_ueln = NULL WHERE dam_id IS NULL AND (dam_ueln IS NULL OR dam_ueln = '') AND LOWER(dam_name) = LOWER(?) AND id != ?{$ageCondition}");
                $stmt->execute([$horseId, $name, $horseId, ...$ageParams]);
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

        // SQL-seitige Pagination über die offenen Platzhalter (#215, 50 je
        // Seite, gleiches Muster wie ApiController::index()): countOpen() ist
        // ein reiner Vorfilter-COUNT, findAll() bewertet nur noch die
        // Platzhalter der angefragten Seite.
        $perPage = 50;
        $matchTotal = \App\Service\MatchSuggestionFinder::countOpen();
        $matchTotalPages = max(1, (int)ceil($matchTotal / $perPage));
        $matchPage = max(1, min($matchTotalPages, (int)($_GET['page'] ?? 1)));
        $unlinkedMatches = \App\Service\MatchSuggestionFinder::findAll($perPage, ($matchPage - 1) * $perPage);

        $db = Database::getInstance();
        // Nur noch die Felder, die das manuelle Auswahl-Dropdown der View
        // tatsächlich braucht (#215): die frühere Abfrage lud hier eine zweite
        // Vollkopie der horses-Tabelle mit allen Match-Spalten, obwohl
        // findAll() die Kandidaten-Daten bereits selbst ermittelt.
        $allHorses = $db->query("SELECT id, name, ueln, sex FROM horses WHERE deleted_at IS NULL ORDER BY name ASC")->fetchAll();

        // Datenqualitäts-Report (#166): bestehende Verknüpfungen, deren Elternteil
        // ein unpassendes Geschlecht trägt - entstanden, bevor es das Geschlechtsfeld
        // und die Speicher-Validierung gab. NULL (unbekannt) gilt nicht als Verstoß.
        $sexMismatches = $db->query(
            "SELECT c.id, c.name,
                    s.id AS sire_id, s.name AS sire_name, s.sex AS sire_sex,
                    d.id AS dam_id, d.name AS dam_name, d.sex AS dam_sex
             FROM horses c
             LEFT JOIN horses s ON c.sire_id = s.id
             LEFT JOIN horses d ON c.dam_id = d.id
             WHERE c.deleted_at IS NULL
               AND (s.sex = 'mare' OR d.sex IN ('stallion', 'gelding'))
             ORDER BY c.name ASC"
        )->fetchAll();

        $this->render('admin_matches', [
            'title' => 'Blutlinien Zusammenführen & Match-Vorschläge',
            'unlinkedMatches' => $unlinkedMatches,
            'allHorses' => $allHorses,
            'sexMismatches' => $sexMismatches,
            'matchPage' => $matchPage,
            'matchTotalPages' => $matchTotalPages,
            'matchTotal' => $matchTotal
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

        // Serverseitig ablehnen, dass ein Pferd sein eigener Elternteil wird -
        // die Absicherung existierte bisher nur clientseitig (#131).
        if ($childId > 0 && $childId === $parentHorseId) {
            header("Location: /admin/matches?error=self_link");
            exit;
        }

        // Geschlechts-Guard analog zur Selbst-Link-Sperre (#167): eine Stute kann
        // nicht als Vater, ein Hengst/Wallach nicht als Mutter verknüpft werden.
        if ($parentHorseId > 0 && in_array($parentType, ['sire', 'dam'], true)) {
            $mismatch = ($parentType === 'sire')
                ? $this->parentSexMismatch($parentHorseId, null)
                : $this->parentSexMismatch(null, $parentHorseId);
            if ($mismatch) {
                header("Location: /admin/matches?error=sex_mismatch");
                exit;
            }
        }

        if ($childId && $parentHorseId && in_array($parentType, ['sire', 'dam'])) {
            $db = Database::getInstance();

            // Fetch names for audit log
            $stmt = $db->prepare("SELECT name FROM horses WHERE id = ?");
            $stmt->execute([$childId]);
            $childName = $stmt->fetchColumn() ?: "Pferd #{$childId}";

            $stmt->execute([$parentHorseId]);
            $parentName = $stmt->fetchColumn() ?: "Pferd #{$parentHorseId}";

            // Rollen-Bezeichnung ohne Geschlechts-Behauptung (#167): das verknüpfte
            // Tier kann auch ohne hinterlegtes Geschlecht (NULL) gespeichert sein.
            $roleLabel = ($parentType === 'sire') ? 'Vater' : 'Mutter';

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

            // Kompletter Datensatz: fürs Audit-Log und als Hook-Payload (#164) -
            // Plugins sollen beim Aufräumen nicht selbst nachladen müssen.
            $stmt = $db->prepare("SELECT * FROM horses WHERE id = ?");
            $stmt->execute([$id]);
            $horse = $stmt->fetch() ?: null;
            $horseName = $horse['name'] ?? 'Unbekannt';

            // Plugin-Hook (#164): VOR dem Verschieben in den Papierkorb. Kann das
            // Löschen nicht blockieren (HookManager-Isolation, wie horse.before_save).
            $this->hooks()->doAction('horse.before_delete', (int)$id, $horse ?? [], false);

            $stmt = $db->prepare("UPDATE horses SET deleted_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);

            \App\Service\AuditLogger::log("Pferd in Papierkorb verschoben", "horses", "Pferd ID {$id}: {$horseName}");

            // Plugin-Hook (#164): NACH dem Soft-Delete - z. B. damit ein Plugin
            // abhängige Daten (Inserate, Verknüpfungen) deaktivieren kann.
            $this->hooks()->doAction('horse.trashed', (int)$id, $horse ?? []);
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

        // Aktuelle/letzte aktive Deckstation auf den Pferde-Hauptdatensatz
        // spiegeln - aber NUR, wenn aus den Personenzeilen tatsächlich eine
        // ermittelt wurde (#214). Vorher lief der Sync bedingungslos und nullte
        // bei leerem Personen-Block (der Normalfall beim Bearbeiten importierter
        // Pferde) die per CSV-Import gesetzte Freitext-Station wieder aus.
        // Kehrseite dieser bewussten Entscheidung: Wer die Station eines Pferds
        // entfernen will, muss das über eine Personenzeile mit neuer Station
        // (oder einen Request mit explizit leerem breeding_station-Feld, siehe
        // COALESCE in update()) tun - ein gelöschter Personen-Block lässt den
        // Bestandswert stehen.
        if ($currentStationId !== null || ($currentStationText ?? '') !== '') {
            $syncStmt = $db->prepare("UPDATE horses SET breeding_station_id = ?, breeding_station = ? WHERE id = ?");
            $syncStmt->execute([$currentStationId, $currentStationText, $horseId]);
        }
    }
}
