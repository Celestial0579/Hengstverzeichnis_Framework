<?php
// src/Controllers/ImportController.php

namespace App\Controllers;

use App\Database;
use App\Service\HorseCsvImporter;

/**
 * Class ImportController
 *
 * CSV-Bulk-Import für Pferde (#49): Vorschau/Validierung vor dem
 * tatsächlichen Import, inkl. Fehlerreport je Zeile - analog zum
 * bestehenden Match-/Merge-Vorschlagswerkzeug (`HorseController::matches()`),
 * das im Anschluss an einen Import genutzt werden kann, um über
 * `sire_name`/`dam_name` importierte, noch unverknüpfte Blutlinien mit
 * bestehenden Pferden zu verbinden - der Import selbst verknüpft bewusst
 * nicht automatisch, um Fehlverknüpfungen bei mehrdeutigen Namen zu
 * vermeiden (siehe MatchSuggestionFinder-Bewertungslogik dort).
 *
 * Zwei-Schritt-Ablauf: preview() speichert den rohen, hochgeladenen
 * CSV-Text in der Session (NICHT die bereits geparsten/validierten Zeilen -
 * commit() parst und validiert bewusst erneut aus derselben gespeicherten
 * Rohquelle, damit ein manipulierter Preview-Request keine unvalidierten
 * Daten in den tatsächlichen Import einschleusen kann).
 */
class ImportController extends BaseController {

    private const SESSION_KEY = 'horse_import_csv';

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
    }

    public function showForm(): void {
        $this->requirePermission('horses', 'create');

        $this->render('admin_import_horses', [
            'title' => 'Pferde-Bulk-Import (CSV)',
            'preview' => null,
        ]);
    }

    public function preview(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }
        $this->requirePermission('horses', 'create');

        $file = $_FILES['csv_file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] === 0) {
            $this->render('admin_import_horses', [
                'title' => 'Pferde-Bulk-Import (CSV)',
                'preview' => null,
                'errors' => ['Bitte eine CSV-Datei auswählen.'],
            ]);
            return;
        }

        // Großzügige, aber begrenzte Obergrenze - eine CSV mit MAX_ROWS Zeilen
        // ist auch mit langen Freitextfeldern weit darunter, primär Schutz vor
        // versehentlichem Upload einer falschen/riesigen Datei.
        if ($file['size'] > 2 * 1024 * 1024) {
            $this->render('admin_import_horses', [
                'title' => 'Pferde-Bulk-Import (CSV)',
                'preview' => null,
                'errors' => ['Datei zu groß (maximal 2 MB, siehe ' . HorseCsvImporter::MAX_ROWS . ' Zeilen Obergrenze).'],
            ]);
            return;
        }

        $rawContent = file_get_contents($file['tmp_name']);
        if ($rawContent === false) {
            $this->render('admin_import_horses', [
                'title' => 'Pferde-Bulk-Import (CSV)',
                'preview' => null,
                'errors' => ['Datei konnte nicht gelesen werden.'],
            ]);
            return;
        }

        $parsed = HorseCsvImporter::parse($rawContent);
        if ($parsed['error'] !== null) {
            $this->render('admin_import_horses', [
                'title' => 'Pferde-Bulk-Import (CSV)',
                'preview' => null,
                'errors' => [$parsed['error']],
            ]);
            return;
        }

        $_SESSION[self::SESSION_KEY] = $rawContent;

        $db = Database::getInstance();
        $validated = HorseCsvImporter::validateRows($parsed, $db);

        $this->render('admin_import_horses', [
            'title' => 'Pferde-Bulk-Import (CSV) - Vorschau',
            'preview' => $validated,
            'validCount' => count(array_filter($validated, fn($r) => empty($r['errors']))),
            'canPublish' => $this->hasPermission('horses', 'publish'),
        ]);
    }

    public function commit(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }
        $this->requirePermission('horses', 'create');

        $rawContent = $_SESSION[self::SESSION_KEY] ?? null;
        if ($rawContent === null) {
            $this->render('admin_import_horses', [
                'title' => 'Pferde-Bulk-Import (CSV)',
                'preview' => null,
                'errors' => ['Keine hochgeladene Datei in der Sitzung gefunden - bitte erneut hochladen.'],
            ]);
            return;
        }

        // Erneutes Parsen+Validieren aus der serverseitig gespeicherten Rohdatei
        // (siehe Klassenkommentar) statt der Werte aus dem Vorschau-Formular zu
        // vertrauen.
        $parsed = HorseCsvImporter::parse($rawContent);
        $db = Database::getInstance();
        $validated = $parsed['error'] === null ? HorseCsvImporter::validateRows($parsed, $db) : [];

        unset($_SESSION[self::SESSION_KEY]);

        $canPublish = $this->hasPermission('horses', 'publish');
        $importedCount = 0;
        $skippedCount = 0;

        $insertStmt = $db->prepare("
            INSERT INTO horses (name, ueln, foreign_ueln, sire_name, sire_ueln, dam_name, dam_ueln, birth_year, color, breeding_station, description, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($validated as $entry) {
            if (!empty($entry['errors'])) {
                $skippedCount++;
                continue;
            }

            $data = $entry['data'];
            // Dieselbe Sperre wie bei der Einzelanlage (HorseController::store()):
            // ohne horses.publish darf ein importiertes Pferd nie direkt öffentlich
            // sichtbar ('active') werden.
            $status = ($data['status'] === 'active' && !$canPublish) ? 'inactive' : $data['status'];

            $insertStmt->execute([
                $data['name'], $data['ueln'], $data['foreign_ueln'],
                $data['sire_name'], $data['sire_ueln'], $data['dam_name'], $data['dam_ueln'],
                $data['birth_year'], $data['color'], $data['breeding_station'], $data['description'],
                $status,
            ]);
            $importedCount++;
        }

        \App\Service\AuditLogger::log(
            "Bulk-Import Pferde (CSV)",
            "horses",
            "{$importedCount} importiert, {$skippedCount} übersprungen (Fehler)"
        );

        $this->render('admin_import_horses', [
            'title' => 'Pferde-Bulk-Import (CSV) - Ergebnis',
            'preview' => null,
            'result' => ['imported' => $importedCount, 'skipped' => $skippedCount],
        ]);
    }
}
