<?php
// src/Service/HorseMedia.php

namespace App\Service;

use App\Database;
use App\Helper\HorseImagePath;
use PDO;

/**
 * Fotos und Video-Links je Pferd (#339).
 *
 * Bis v0.8 hatte der Kern genau ein `horses.image_url`, und das Addon
 * `galerie` brachte eine zweite Ablage fuer dasselbe mit - zwei
 * Pflegeoberflaechen, zwei Ausliefer-Wege, zwei Vorstellungen davon, welches
 * Bild das Hauptbild ist. Seit #339 macht der Kern es selbst.
 *
 * DAS HAUPTBILD BLEIBT IN `horses.image_url`. Katalogkarte, Admin-Liste,
 * Startseite, JSON-API und mehrere Addons lesen diese Spalte; sie hier
 * abzuschaffen hiesse, alle auf einmal umzustellen. Stattdessen fuellt
 * syncMainImage() sie aus dieser Tabelle nach - die Spalte bleibt gueltig,
 * die Wahrheit steht an einer Stelle.
 *
 * KEINE EIGENE BERECHTIGUNG. Wer `horses.edit` hat, pflegt die Medien;
 * sichtbar ist, was `horses.view` und `is_published` erlauben. Ein eigenes
 * Rechte-Modul waere eine zweite Antwort auf dieselbe Frage - genau die
 * Doppelung, die das Addon hatte (`galerie.manage` neben `horses.edit`).
 */
final class HorseMedia {

    public const TYP_BILD = 'image';
    public const TYP_VIDEO = 'video';

    /** Wie das Kernfoto: 5 MB, dieselbe Positivliste. */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** @var array<string, string> MIME-Typ => Endung */
    public const ERLAUBTE_TYPEN = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    private function __construct() {}

    /**
     * Alle Medien eines Pferds, in Anzeigereihenfolge.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forHorse(int $horseId): array {
        if ($horseId <= 0) {
            return [];
        }

        try {
            $stmt = Database::getInstance()->prepare(
                'SELECT id, horse_id, type, file_name, video_url, caption, is_main, sort_order
                 FROM horse_media WHERE horse_id = ? ORDER BY sort_order ASC, id ASC'
            );
            $stmt->execute([$horseId]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            // Auf einer noch nicht migrierten Instanz gibt es die Tabelle
            // nicht. "Keine Medien" ist die richtige Antwort - eine
            // Detailseite darf daran nicht scheitern.
            return [];
        }
    }

    /** @return array<string, mixed>|null */
    public static function byId(int $mediaId): ?array {
        try {
            $stmt = Database::getInstance()->prepare(
                'SELECT m.id, m.horse_id, m.type, m.file_name, m.video_url, m.caption,
                        h.is_published, h.deleted_at
                 FROM horse_media m JOIN horses h ON h.id = m.horse_id
                 WHERE m.id = ?'
            );
            $stmt->execute([$mediaId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return null;
        }

        return is_array($row) ? $row : null;
    }

    /**
     * Nimmt ein hochgeladenes Bild an und legt es in der geschuetzten Ablage
     * ab. Gibt den Spaltenwert zurueck - dieselbe Form wie
     * `horses.image_url`, damit das Hauptbild ohne Umrechnung uebernommen
     * werden kann.
     *
     * @param array<string, mixed>|null $file Eintrag aus $_FILES
     */
    public static function speichereUpload(?array $file): ?string {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }
        if (($file['size'] ?? 0) <= 0 || $file['size'] > self::MAX_BYTES) {
            return null;
        }

        // Positivliste ueber den tatsaechlichen Inhalt, nicht ueber die
        // Endung im Namen - dieselbe Pruefung wie beim Kernfoto.
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
        if (!isset(self::ERLAUBTE_TYPEN[$mime])) {
            return null;
        }

        $verzeichnis = HorseImagePath::dir() . '/';
        if (!is_dir($verzeichnis) && !mkdir($verzeichnis, 0755, true) && !is_dir($verzeichnis)) {
            return null;
        }

        $name = 'horse_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . self::ERLAUBTE_TYPEN[$mime];
        if (!move_uploaded_file((string)$file['tmp_name'], $verzeichnis . $name)) {
            return null;
        }

        return '/uploads/horses/' . $name;
    }

    /**
     * Legt ein Medium an.
     *
     * Bild ODER Video - bei beidem gewinnt das Bild. Das erste Bild eines
     * Pferds ohne Hauptbild wird automatisch zum Hauptbild: Ein Bestand mit
     * Fotos, aber ohne ausgezeichnetes Hauptbild, zeigte sonst in Katalog und
     * Liste nichts, obwohl Bilder da sind.
     *
     * @return int Neue ID, 0 bei Ablehnung
     */
    public static function hinzufuegen(
        int $horseId,
        ?string $fileName,
        ?string $videoUrl,
        ?string $caption,
        ?int $sortOrder = null
    ): int {
        if ($horseId <= 0) {
            return 0;
        }

        $videoUrl = self::gepruefterVideoLink($videoUrl);
        if ($fileName === null && $videoUrl === null) {
            return 0;
        }

        $db = Database::getInstance();
        $typ = $fileName !== null ? self::TYP_BILD : self::TYP_VIDEO;

        if ($sortOrder === null) {
            $stmt = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM horse_media WHERE horse_id = ?');
            $stmt->execute([$horseId]);
            $sortOrder = (int)$stmt->fetchColumn();
        }

        $stmt = $db->prepare(
            'INSERT INTO horse_media (horse_id, type, file_name, video_url, caption, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $horseId,
            $typ,
            $fileName,
            $videoUrl,
            ($caption ?? '') === '' ? null : mb_substr((string)$caption, 0, 255),
            $sortOrder,
        ]);
        $id = (int)$db->lastInsertId();

        if ($typ === self::TYP_BILD && !self::hatHauptbild($horseId)) {
            self::setzeHauptbild($horseId, $id);
        }

        return $id;
    }

    /**
     * Loescht ein Medium samt Datei.
     *
     * War es das Hauptbild, rueckt das naechste Bild nach - sonst stuende das
     * Pferd in Katalog und Liste ploetzlich ohne Foto da, obwohl noch welche
     * vorhanden sind.
     */
    public static function loeschen(int $mediaId): bool {
        $medium = self::byId($mediaId);
        if ($medium === null) {
            return false;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare('DELETE FROM horse_media WHERE id = ?');
        $stmt->execute([$mediaId]);

        if (($medium['type'] ?? '') === self::TYP_BILD && !empty($medium['file_name'])) {
            self::dateiEntfernen((string)$medium['file_name']);
        }

        self::syncMainImage((int)$medium['horse_id']);

        return true;
    }

    /** Genau ein Hauptbild je Pferd - das Setzen nimmt es allen anderen. */
    public static function setzeHauptbild(int $horseId, int $mediaId): bool {
        $db = Database::getInstance();

        $stmt = $db->prepare("SELECT id FROM horse_media WHERE id = ? AND horse_id = ? AND type = 'image'");
        $stmt->execute([$mediaId, $horseId]);
        if ((int)$stmt->fetchColumn() !== $mediaId) {
            // Ein Video kann kein Hauptbild sein, und ein fremdes Medium
            // erst recht nicht.
            return false;
        }

        $db->prepare('UPDATE horse_media SET is_main = 0 WHERE horse_id = ?')->execute([$horseId]);
        $db->prepare('UPDATE horse_media SET is_main = 1 WHERE id = ?')->execute([$mediaId]);

        self::syncMainImage($horseId);

        return true;
    }

    /**
     * Traegt das Hauptbild nach `horses.image_url` nach.
     *
     * Die EINE Stelle, an der die Spalte aus den Medien gefuellt wird. Ohne
     * ausgezeichnetes Hauptbild gilt das erste Bild in Anzeigereihenfolge -
     * ein Bestand, der nie ein Hauptbild gewaehlt hat, zeigt so trotzdem
     * etwas. Gibt es gar kein Bild, wird die Spalte geleert.
     */
    public static function syncMainImage(int $horseId): void {
        $db = Database::getInstance();

        $stmt = $db->prepare(
            "SELECT file_name FROM horse_media
             WHERE horse_id = ? AND type = 'image' AND file_name IS NOT NULL
             ORDER BY is_main DESC, sort_order ASC, id ASC LIMIT 1"
        );
        $stmt->execute([$horseId]);
        $datei = $stmt->fetchColumn();

        $stmt = $db->prepare('UPDATE horses SET image_url = ? WHERE id = ?');
        $stmt->execute([$datei === false || $datei === null ? null : (string)$datei, $horseId]);
    }

    public static function hatHauptbild(int $horseId): bool {
        $stmt = Database::getInstance()->prepare(
            'SELECT COUNT(*) FROM horse_media WHERE horse_id = ? AND is_main = 1'
        );
        $stmt->execute([$horseId]);

        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Erlaubte Video-Hosts. Nur bekannte Plattformen, ausschliesslich https.
     *
     * Uebernommen aus dem abgeloesten Addon `galerie` (#339) - dort war es
     * schon so, und eine Kern-Fassung, die nur "irgendein http(s)-Link"
     * pruefte, waere hinter dem Stand zurueck, den sie ersetzt.
     *
     * @var array<int, string>
     */
    public const VIDEO_HOSTS = [
        'www.youtube.com', 'youtube.com', 'youtu.be',
        'vimeo.com', 'www.vimeo.com',
    ];

    /**
     * Prueft einen Video-Link und gibt ihn NEU GEBAUT zurueck.
     *
     * WARUM NEU GEBAUT UND NICHT DURCHGEREICHT. Die Pruefung macht PHPs
     * parse_url(), angezeigt wird die Zeichenkette spaeter im Browser - also
     * in einem anderen Parser. Solange die Eingabe unveraendert
     * durchgereicht wird, haengt die Sicherheit daran, dass beide Parser
     * jede Eingabe gleich lesen; Abweichungen zwischen Parsern sind der
     * Stoff, aus dem Allowlist-Umgehungen gemacht sind (Benutzerinfo vor dem
     * @, Rueckwaertsschraegstriche, Steuerzeichen, doppelte Fragmente).
     *
     * Wird die URL aus den geprueften Teilen zusammengesetzt, ist die Frage
     * gegenstandslos: Was der Browser sieht, ist per Konstruktion das, was
     * hier geprueft wurde. Benutzerinfo und Fragment fallen dabei ganz weg -
     * beide haben in einer Video-Adresse nichts zu suchen.
     */
    public static function gepruefterVideoLink(?string $url): ?string {
        $url = trim((string)($url ?? ''));
        if ($url === '') {
            return null;
        }

        $teile = parse_url($url);
        if (!is_array($teile) || ($teile['scheme'] ?? '') !== 'https' || ($teile['host'] ?? '') === '') {
            return null;
        }

        $host = mb_strtolower((string)$teile['host'], 'UTF-8');
        if (!in_array($host, self::VIDEO_HOSTS, true)) {
            return null;
        }

        $neu = 'https://' . $host;
        if (isset($teile['port'])) {
            $neu .= ':' . (int)$teile['port'];
        }
        $neu .= $teile['path'] ?? '/';
        if (($teile['query'] ?? '') !== '') {
            $neu .= '?' . $teile['query'];
        }

        // Steuerzeichen koennen in Pfad und Query stehen, ohne dass
        // parse_url stolpert - in einem Attribut beenden sie unter Umstaenden
        // den Wert.
        if (preg_match('/[\x00-\x1F\x7F"\'<>\\\\]/', $neu) === 1) {
            return null;
        }

        return mb_strlen($neu, 'UTF-8') > 255 ? null : $neu;
    }

    /**
     * Entfernt die Datei zu einem Spaltenwert - aber nur, wenn kein anderes
     * Medium und kein Pferd sie noch benutzt.
     *
     * Der Fall ist nicht theoretisch: Die Uebernahme aus dem Addon (#339)
     * kann dasselbe Bild als Galeriebild UND als Hauptbild fuehren.
     */
    private static function dateiEntfernen(string $spaltenwert): void {
        $db = Database::getInstance();

        $stmt = $db->prepare('SELECT COUNT(*) FROM horse_media WHERE file_name = ?');
        $stmt->execute([$spaltenwert]);
        if ((int)$stmt->fetchColumn() > 0) {
            return;
        }

        $stmt = $db->prepare('SELECT COUNT(*) FROM horses WHERE image_url = ?');
        $stmt->execute([$spaltenwert]);
        if ((int)$stmt->fetchColumn() > 0) {
            return;
        }

        $name = basename(parse_url($spaltenwert, PHP_URL_PATH) ?? '');
        if ($name === '' || $name === '.' || $name === '..') {
            return;
        }

        foreach ([HorseImagePath::dir(), HorseImagePath::legacyDir()] as $verzeichnis) {
            $pfad = $verzeichnis . '/' . $name;
            if (is_file($pfad)) {
                @unlink($pfad);
            }
        }

        // Die abgeleiteten Vorschaubilder gehen mit (#397). Ohne diese Zeile
        // bliebe je geloeschtem Medium eine Waise in der Ablage liegen - und
        // die faellt erst auf, wenn jemand die Dateien zaehlt.
        Thumbnails::entfernen($spaltenwert);
    }
}
