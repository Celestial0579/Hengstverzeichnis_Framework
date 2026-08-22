<?php
// src/Controllers/HorseMediaController.php

namespace App\Controllers;

use App\Database;
use App\Service\AuditLogger;
use App\Service\HorseMedia;

/**
 * Pflege der Medien eines Pferds (#339).
 *
 * Eigener Controller und nicht Teil von HorseController::update(): Die
 * Abschnitte liegen AUSSERHALB des Stammdaten-Formulars (verschachtelte
 * <form> sind ungueltiges HTML), jeder Knopf schickt fuer sich ab. Wer hier
 * etwas aendert, verliert damit keine ungespeicherten Stammdaten - und
 * umgekehrt.
 *
 * Keine eigene Berechtigung: `horses.edit` reicht und ist gemeint. Das Addon
 * hatte dafuer ein eigenes `galerie.manage`, was zu dem Zustand fuehrte, den
 * es selbst im Code beschreibt - ein Redakteur sah ein Formular, das beim
 * Absenden 403 lieferte.
 */
class HorseMediaController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission('horses', 'edit');
    }

    public function add(): void {
        $horseId = $this->geprueftesPferd();

        // Bild ODER Video. Bei beidem gewinnt das Bild - das sagt auch das
        // Formular, sonst verfiele der Link stillschweigend.
        $datei = HorseMedia::speichereUpload($_FILES['media_image'] ?? null);
        $video = $datei === null ? (string)($_POST['video_url'] ?? '') : '';

        $id = HorseMedia::hinzufuegen(
            $horseId,
            $datei,
            $video === '' ? null : $video,
            (string)($_POST['caption'] ?? ''),
            isset($_POST['sort_order']) && $_POST['sort_order'] !== '' ? (int)$_POST['sort_order'] : null
        );

        if ($id === 0) {
            $this->zurueck($horseId, 'media_invalid');
        }

        AuditLogger::log(
            'Medium hinzugefügt',
            'horses',
            sprintf('Pferd %d, Medium %d (%s)', $horseId, $id, $datei !== null ? 'Bild' : 'Video')
        );

        $this->zurueck($horseId, 'media_added');
    }

    public function delete(): void {
        $horseId = $this->geprueftesPferd();
        $mediaId = (int)($_POST['media_id'] ?? 0);

        // Das Medium muss zu DIESEM Pferd gehoeren. Ohne die Pruefung
        // genuegte die Kenntnis einer fremden Medien-ID, um sie ueber die
        // eigene Pferdeseite zu loeschen.
        $medium = HorseMedia::byId($mediaId);
        if ($medium === null || (int)$medium['horse_id'] !== $horseId) {
            $this->zurueck($horseId, 'media_invalid');
        }

        HorseMedia::loeschen($mediaId);
        AuditLogger::log('Medium gelöscht', 'horses', sprintf('Pferd %d, Medium %d', $horseId, $mediaId));

        $this->zurueck($horseId, 'media_deleted');
    }

    public function main(): void {
        $horseId = $this->geprueftesPferd();
        $mediaId = (int)($_POST['media_id'] ?? 0);

        if (!HorseMedia::setzeHauptbild($horseId, $mediaId)) {
            $this->zurueck($horseId, 'media_invalid');
        }

        AuditLogger::log('Hauptbild gewählt', 'horses', sprintf('Pferd %d, Medium %d', $horseId, $mediaId));

        $this->zurueck($horseId, 'media_main');
    }

    /**
     * Pferd aus dem POST, mit CSRF- und Existenzpruefung. Bricht ab, statt
     * einen unbrauchbaren Wert weiterzureichen.
     */
    private function geprueftesPferd(): int {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden(\App\I18n\Translator::t('errors.csrf_invalid'));
        }

        $horseId = (int)($_POST['horse_id'] ?? 0);
        if ($horseId <= 0) {
            header('Location: /admin/horses');
            exit;
        }

        $stmt = Database::getInstance()->prepare('SELECT id FROM horses WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$horseId]);
        if ((int)$stmt->fetchColumn() !== $horseId) {
            header('Location: /admin/horses');
            exit;
        }

        return $horseId;
    }

    private function zurueck(int $horseId, string $status): never {
        header('Location: /admin/horses/edit?id=' . $horseId . '&media=' . $status);
        exit;
    }
}
