<?php
// src/Controllers/MediaController.php

namespace App\Controllers;

use App\Database;

/**
 * Ausliefern von Pferdefotos über PHP statt als statische Datei (#262).
 *
 * WARUM NICHT NUR .htaccess: Der naheliegende Weg wäre ein Referer-Filter im
 * Upload-Verzeichnis. Er hat zwei Mängel, die beide erst im Betrieb auffallen:
 *
 * 1. Er gilt nur für Apache. Dieses Repo liefert ausschließlich .htaccess mit;
 *    eine nginx- oder Caddy-Installation hätte damit ÜBERHAUPT keinen Schutz,
 *    ohne dass das irgendwo sichtbar würde.
 * 2. Er ist in der Testsuite nicht prüfbar - sie fährt `php -S`, und das wertet
 *    .htaccess nicht aus. Ein Schutz, den keine Prüfung erreicht, ist genau die
 *    Sorte, die still ausfällt und beim Umzug auf einen anderen Webserver
 *    unbemerkt verschwindet.
 *
 * Deshalb liegt die Entscheidung hier im Anwendungscode - einmal, prüfbar und
 * unabhängig vom Webserver.
 *
 * WAS DER SCHUTZ LEISTET, UND WAS NICHT: Ein öffentlicher Katalog zeigt seine
 * Bilder; wer sie sieht, kann sie speichern. Daran ändert serverseitig nichts
 * etwas, und das Issue sagt das selbst. Verhindert wird das EINBETTEN durch
 * fremde Seiten - also der Fall, in dem eine fremde Homepage die Bilder
 * anzeigt und dafür unsere Bandbreite verbraucht.
 *
 * Zwei Schichten, die sich gegenseitig decken:
 *
 * - `Cross-Origin-Resource-Policy: same-origin` wird vom BROWSER durchgesetzt.
 *   Eine fremde Seite kann das Bild damit nicht per <img> einbinden, und sie
 *   kann das nicht umgehen - anders als beim Referer, den die einbettende Seite
 *   selbst bestimmt.
 * - Die Referer-Prüfung greift dort, wo CORP nicht greift: bei Clients ohne
 *   CORP-Unterstützung und bei direkten Abrufen aus fremden Seiten heraus.
 *   Ein LEERER Referer wird bewusst durchgelassen - ihn zu blocken bräche das
 *   direkte Aufrufen einer Bild-URL, Lesezeichen und Datenschutz-Browser, die
 *   den Referer grundsätzlich unterdrücken. Diese Lücke deckt CORP ab.
 *
 * Und ein Nebenbefund, den der Umbau mit erledigt: Als statische Datei war ein
 * Foto unabhängig von `is_published` abrufbar. Das Bild eines UNVERÖFFENTLICHTEN
 * Pferdes lag damit offen, sobald jemand die URL kannte. Hier gelten dieselben
 * Sichtbarkeitsregeln wie für die Detailseite.
 */
class MediaController extends BaseController {

    /** Erlaubte Bildendungen samt MIME-Typ - eine Positivliste, kein finfo-Raten. */
    private const TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];

    /**
     * Ein Jahr, wie schon die statische Auslieferung (public/.htaccess).
     * Der Dateiname trägt einen Zufallsanteil und ändert sich bei jedem neuen
     * Upload - eine lange Frist kann also keine veraltete Fassung festhalten.
     */
    private const CACHE_SECONDS = 31536000;

    public function horseImage(): void {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            $this->sendStatus(404);
        }

        $stmt = Database::getInstance()->prepare(
            "SELECT id, image_url, is_published FROM horses WHERE id = ? AND deleted_at IS NULL"
        );
        $stmt->execute([$id]);
        $horse = $stmt->fetch();

        if (!$horse || empty($horse['image_url'])) {
            $this->sendStatus(404);
        }

        // Sichtbarkeit wie auf der Detailseite: Ein unveröffentlichtes Pferd ist
        // für Gäste nicht vorhanden - sein Foto also auch nicht. Angemeldete
        // Benutzer mit horses.view sehen es (Verwaltungslisten, Bearbeitungsform).
        if (!$this->hasPermission('horses', 'view')) {
            $this->sendStatus(404);
        }
        if (empty($horse['is_published']) && empty($_SESSION['user_id'])) {
            $this->sendStatus(404);
        }

        $path = $this->resolveUploadPath((string)$horse['image_url']);
        if ($path === null) {
            $this->sendStatus(404);
        }

        if (!$this->refererIsAllowed()) {
            // 403 statt 404: Die Ressource gibt es, sie wird nur nicht an eine
            // fremde einbettende Seite geliefert.
            $this->sendStatus(403);
        }

        $this->stream($path);
    }

    /**
     * Bildet den gespeicherten `image_url`-Wert auf eine Datei im
     * Upload-Verzeichnis ab. Gibt null zurück, sobald irgendetwas nicht passt -
     * insbesondere, wenn der aufgelöste Pfad das Verzeichnis verlässt.
     *
     * Der Wert stammt zwar aus der eigenen Datenbank und nicht aus der Anfrage,
     * aber genau darauf hat sich schon manche Anwendung verlassen, bevor ein
     * CSV-Import oder eine Altdatenübernahme dort etwas anderes hineinschrieb.
     */
    private function resolveUploadPath(string $imageUrl): ?string {
        $baseDir = realpath(__DIR__ . '/../../public/uploads/horses');
        if ($baseDir === false) {
            return null;
        }

        $name = basename(parse_url($imageUrl, PHP_URL_PATH) ?? '');
        if ($name === '' || $name === '.' || $name === '..') {
            return null;
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$extension])) {
            return null;
        }

        $full = realpath($baseDir . '/' . $name);
        if ($full === false || !is_file($full)) {
            return null;
        }
        // realpath löst Symlinks auf: Ein Link im Upload-Verzeichnis dürfte
        // sonst auf jede Datei des Systems zeigen.
        if (!str_starts_with($full, $baseDir . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $full;
    }

    /**
     * Leerer Referer ist erlaubt (direkter Aufruf, Lesezeichen,
     * Datenschutz-Browser), fremde Hosts nicht. Die Lücke, die das lässt -
     * eine einbettende Seite mit referrerpolicy="no-referrer" - schließt CORP.
     */
    private function refererIsAllowed(): bool {
        $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
        if ($referer === '') {
            return true;
        }

        $refererHost = parse_url($referer, PHP_URL_HOST);
        if ($refererHost === null || $refererHost === false) {
            return true;
        }

        $ownHost = (string)($_SERVER['HTTP_HOST'] ?? '');
        // Port abschneiden: Host-Header führt ihn, der Referer nicht zwingend.
        $ownHost = strtolower(explode(':', $ownHost)[0]);

        return strtolower($refererHost) === $ownHost;
    }

    private function stream(string $path): void {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $size = filesize($path);
        $mtime = filemtime($path);
        $etag = '"' . md5($path . '|' . $mtime . '|' . $size) . '"';

        header('Content-Type: ' . self::TYPES[$extension]);
        header('X-Content-Type-Options: nosniff');
        // Die eigentliche Sperre gegen fremde Einbettung - vom Browser
        // durchgesetzt und von der einbettenden Seite nicht umgehbar.
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Cache-Control: public, max-age=' . self::CACHE_SECONDS);
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');

        // Bedingte Anfragen: Ohne sie holte der Browser das Bild bei jedem
        // Neuladen vollständig - die Auslieferung über PHP wäre dann tatsächlich
        // der Rückschritt, den das Issue befürchtet.
        $ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        $ifModifiedSince = strtotime((string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '')) ?: 0;
        if ($ifNoneMatch === $etag || ($ifModifiedSince > 0 && $ifModifiedSince >= $mtime)) {
            http_response_code(304);
            exit;
        }

        header('Content-Length: ' . $size);

        // Ausgabepuffer leeren, sonst hält PHP die gesamte Datei im Speicher,
        // bevor das erste Byte den Server verlässt.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        readfile($path);
        exit;
    }

    private function sendStatus(int $code): void {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        echo $code === 403 ? 'Forbidden' : 'Not Found';
        exit;
    }
}
