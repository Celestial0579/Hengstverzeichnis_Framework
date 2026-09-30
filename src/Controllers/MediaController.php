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
     * Ein Jahr - aber NUR für den Browser und NUR unter einer versionierten
     * Adresse (`&v=`, siehe MediaUrl, Audit M14).
     *
     * Der frühere Kommentar hier behauptete, der Dateiname ändere sich bei
     * jedem Upload, eine lange Frist könne also nichts Veraltetes festhalten.
     * Die ADRESSE trug den Dateinamen aber gar nicht, nur die Pferde-ID: Nach
     * einem Wechsel des Hauptbilds sah jeder Browser ein Jahr lang das alte
     * Foto, und ein gemeinsamer Cache lieferte das Foto eines inzwischen
     * depublizierten Pferds weiter an jeden aus. Jetzt wechselt die Adresse
     * mit dem Foto.
     */
    private const CACHE_SECONDS = 31536000;

    /**
     * Frist für gemeinsame Zwischenspeicher (`s-maxage`) und für Adressen
     * ohne passende Version. Auch unter einer versionierten Adresse kann
     * sich die ANTWORT ändern, ohne dass sich die Adresse ändert - bei einer
     * Depublikation. Ein Proxy fragt deshalb nach spätestens fünf Minuten
     * wieder nach; PHP antwortet dann mit 404 oder 304.
     */
    private const GEMEINSAM_SECONDS = 300;

    public function horseImage(): void {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            $this->sendStatus(404);
        }

        // Datenbankfehler enden hier als 404, nicht als 500.
        //
        // Seit dem Kurzschluss in public/index.php (#311) laeuft diese Route
        // an der Setup-Weiche vorbei - auf einer noch nicht eingerichteten
        // Installation gibt es die Tabelle horses also gar nicht, und eine
        // PDOException wuerde jedes <img> mit einem Serverfehler beantworten.
        // "Kein Bild" ist die richtige Antwort auf eine Bildanfrage, in beiden
        // Faellen. Protokolliert wird der Fehler von PHP weiterhin.
        try {
            $stmt = Database::getInstance()->prepare(
                "SELECT id, image_url, is_published FROM horses WHERE id = ? AND deleted_at IS NULL"
            );
            $stmt->execute([$id]);
            $horse = $stmt->fetch();
        } catch (\Throwable $e) {
            error_log('MediaController: Bildabfrage fehlgeschlagen: ' . $e->getMessage());
            $this->sendStatus(404);
        }

        if (!$horse || empty($horse['image_url'])) {
            $this->sendStatus(404);
        }

        // Angemeldet ist, wer eine GÜLTIGE Sitzung hat - nicht, wer irgendwann
        // einmal eine hatte (#314).
        //
        // Vorher genügte hier die blosse Anwesenheit von $_SESSION['user_id'].
        // Damit galt keine der Sitzungsprüfungen, die im übrigen Backend
        // greifen: gelöschtes oder deaktiviertes Konto, session_version nach
        // einem Passwortwechsel (#113), User-Agent-Fingerprint,
        // Inaktivitäts-Timeout. Ein Angreifer mit einem alten Sitzungscookie
        // flog auf /admin/horses sofort auf /login, konnte über diese Route
        // aber weiter jedes Foto abrufen - auch die unveröffentlichter oder
        // nach DSGVO-Widerspruch depublizierter Pferde.
        //
        // Es gilt dieselbe Regel wie in checkAuth() - LoginSession::validate(),
        // eine einzige Fassung, denn die Lücke von #113 lebte davon, dass die
        // Invalidierung nur an einer Stelle stand. Aber in der leichten Form
        // (currentUserId(), Audit N44): ohne Sitzungs-ID-Rotation, ohne
        // Weiterleitung, ohne last_activity-Update. Bildanfragen laufen als
        // Nebenanfragen einer Seite zu Dutzenden parallel; rotierte eine von
        // ihnen die ID, legten die übrigen mit der alten ID unter
        // use_strict_mode leere Sitzungen an und überschrieben das Cookie -
        // der Benutzer war sporadisch abgemeldet. Eine ungültige Sitzung wird
        // hier wie ein Gast behandelt (404 statt Weiterleitung, für ein <img>
        // dasselbe) und verliert ihre Identität.
        //
        // must_change_password: checkAuth() leitete solche Sitzungen um, sie
        // sahen also nie unveröffentlichte Fotos. Das bleibt so.
        $angemeldet = $this->currentUserId() !== null && empty($_SESSION['must_change_password']);

        // Sitzung so früh wie möglich freigeben (#311).
        //
        // PHPs Standard-Sitzungsspeicher hält die Sitzungsdatei bis zum Ende
        // des Requests exklusiv gesperrt; eine Katalogseite fordert zwei
        // Dutzend Bilder über diesen Endpunkt an, die sich sonst
        // hintereinander aufreihen statt parallel zu laufen. Ab hier wird
        // nichts mehr in die Sitzung geschrieben - die Sitzungsprüfung ist
        // durch (eine ungültige Identität ist bereits verworfen), und die
        // folgenden Prüfungen lesen nur noch.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // Sichtbarkeit wie auf der Detailseite und in der Verwaltungsliste:
        // - Veröffentlicht: wer das Pferd öffentlich sehen darf - die
        //   Gast-Rechte sind für Angemeldete eine Untergrenze (Audit N61).
        // - Unveröffentlicht: nur angemeldete Konten mit interner Einsicht
        //   (horses.internal/edit/delete/publish, Audit M13). Bis dahin
        //   genügte horses.view, und damit jedes Konto der
        //   Registrierungs-Standardgruppe.
        if (!empty($horse['is_published'])) {
            if (!$this->hasPublicPermission('horses', 'view')) {
                $this->sendStatus(404);
            }
        } elseif (!$angemeldet || !$this->hasInternalAccess('horses')) {
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

        $path = $this->vielleichtVerkleinert($path, (string)$horse['image_url']);

        $this->stream(
            $path,
            !empty($horse['is_published']),
            \App\Helper\MediaUrl::versionPasst((string)$horse['image_url'], $_GET['v'] ?? null)
        );
    }

    /**
     * Weitere Medien eines Pferds (#339) - dieselbe Tuer wie das Hauptbild.
     *
     * Wortgleich dieselben Sichtbarkeitsregeln, und das ist der Punkt: Das
     * Addon `galerie` brachte eine ZWEITE Ausliefer-Route mit, und jede
     * Regel - gueltige Sitzung, horses.view bzw. interne Einsicht, is_published, Referer,
     * Cache-Kopfzeilen - musste dort ein zweites Mal richtig sein. Der Kern
     * hat davon jetzt genau eine; sie unterscheidet sich nur darin, WO die
     * Datei steht.
     */
    public function horseMedia(): void {
        $mediaId = (int)($_GET['id'] ?? 0);
        if ($mediaId <= 0) {
            $this->sendStatus(404);
        }

        // Wie oben: Datenbankfehler enden als 404, nicht als 500.
        try {
            $medium = \App\Service\HorseMedia::byId($mediaId);
        } catch (\Throwable $e) {
            error_log('MediaController: Medienabfrage fehlgeschlagen: ' . $e->getMessage());
            $this->sendStatus(404);
        }

        if ($medium === null || $medium['deleted_at'] !== null || ($medium['type'] ?? '') !== 'image') {
            $this->sendStatus(404);
        }
        if (empty($medium['file_name'])) {
            $this->sendStatus(404);
        }

        // Dieselbe leichte Sitzungsprüfung wie in horseImage() (Audit N44).
        $angemeldet = $this->currentUserId() !== null && empty($_SESSION['must_change_password']);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // Dieselbe Regel wie in horseImage() (Audit N61, M13).
        if (!empty($medium['is_published'])) {
            if (!$this->hasPublicPermission('horses', 'view')) {
                $this->sendStatus(404);
            }
        } elseif (!$angemeldet || !$this->hasInternalAccess('horses')) {
            $this->sendStatus(404);
        }

        $path = $this->resolveUploadPath((string)$medium['file_name']);
        if ($path === null) {
            $this->sendStatus(404);
        }

        if (!$this->refererIsAllowed()) {
            $this->sendStatus(403);
        }

        $path = $this->vielleichtVerkleinert($path, (string)$medium['file_name']);

        $this->stream(
            $path,
            !empty($medium['is_published']),
            \App\Helper\MediaUrl::versionPasst((string)$medium['file_name'], $_GET['v'] ?? null)
        );
    }

    /**
     * Die verkleinerte Fassung, wenn der Betreiber sie eingeschaltet hat und
     * sie sich erzeugen laesst - sonst das Original (#397).
     *
     * STEHT NACH ALLEN PRUEFUNGEN, und das ist wesentlich: Die Vorschau liegt
     * in derselben Ablage und geht durch dieselbe Route wie das Original.
     * Sie muss deshalb dieselbe Sichtbarkeits-, Rechte- und Referer-Pruefung
     * hinter sich haben - ein zweiter Auslieferungsweg mit eigener Pruefung
     * waere genau die Doppelung, an der so etwas schiefgeht.
     *
     * Jeder Fehlschlag endet beim Original. Ein fehlendes Vorschaubild ist
     * ein langsamer Seitenaufbau; eine Fehlermeldung waere ein kaputtes Bild.
     */
    private function vielleichtVerkleinert(string $originalPfad, string $gespeicherterWert): string {
        // Der Anfragewert wird nicht weitergereicht, sondern durch die
        // EIGENE Konstante ersetzt, die er benennt. Ab hier ist $groesse
        // garantiert ein Literal aus Thumbnails::GROESSEN und nicht mehr die
        // Zeichenkette aus $_GET - der Unterschied ist der zwischen
        // "geprueft" und "stammt gar nicht mehr von aussen".
        //
        // Eine Pruefung allein haette hier nicht gereicht: Die Semgrep-Regel
        // `tainted-filename` kennt keine pattern-sanitizers, sie ist
        // ausschliesslich dadurch erfuellbar, den Wert nicht mehr aus der
        // Eingabe zu bauen. Das ist hier ohnehin das Richtige - der Wert
        // landet als Teil eines DATEINAMENS in der Ablage.
        $angefragt = (string)($_GET['groesse'] ?? '');
        $groesse = null;
        foreach (array_keys(\App\Service\Thumbnails::GROESSEN) as $erlaubt) {
            if ($angefragt === $erlaubt) {
                $groesse = $erlaubt;
                break;
            }
        }
        if ($groesse === null) {
            return $originalPfad;
        }

        if (!\App\Service\Thumbnails::aktiv($this->settings ?? null)) {
            return $originalPfad;
        }

        $vorhanden = \App\Service\Thumbnails::pfad($gespeicherterWert, $groesse);
        if ($vorhanden !== null && filemtime($vorhanden) >= (filemtime($originalPfad) ?: 0)) {
            return $vorhanden;
        }

        return \App\Service\Thumbnails::erzeugen($originalPfad, $groesse, $gespeicherterWert)
            ?? $originalPfad;
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
        // Eine Stelle für beide Ablagen, die Endungs-Positivliste und die
        // Symlink-Prüfung - dieselbe, die auch die Bestandsbereinigung
        // (Audit M21) nutzt.
        $pfad = \App\Helper\HorseImagePath::datei($imageUrl);
        if ($pfad === null || !isset(self::TYPES[strtolower(pathinfo($pfad, PATHINFO_EXTENSION))])) {
            return null;
        }

        return $pfad;
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

    private function stream(string $path, bool $oeffentlich, bool $versioniert): void {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $size = filesize($path);
        $mtime = filemtime($path);
        $etag = '"' . md5($path . '|' . $mtime . '|' . $size) . '"';

        header('Content-Type: ' . self::TYPES[$extension]);
        header('X-Content-Type-Options: nosniff');
        // Die eigentliche Sperre gegen fremde Einbettung - vom Browser
        // durchgesetzt und von der einbettenden Seite nicht umgehbar.
        header('Cross-Origin-Resource-Policy: same-origin');
        // Die Cache-Direktive folgt der tatsächlichen Sichtbarkeit (#315).
        //
        // Vorher trug JEDE 200er-Antwort `public, max-age=1 Jahr`. Für einen
        // gemeinsam genutzten Zwischenspeicher (nginx proxy_cache, Varnish,
        // CDN) war die URL damit eine statische, für alle gleiche Ressource:
        // Öffnete ein angemeldeter Redakteur die Bearbeitungsseite eines
        // UNVERÖFFENTLICHTEN Pferds, legte der Proxy dessen Foto mit Status 200
        // und einem Jahr Frist ab und lieferte es anschliessend an jeden
        // nicht angemeldeten Besucher aus - PHP wurde gar nicht mehr gefragt,
        // die Prüfung oben also nie wieder erreicht. Genau der im
        // Klassenkommentar beworbene Nebeneffekt fiel damit aus.
        //
        // Kein `Vary: Cookie` im öffentlichen Zweig, und das ist Absicht: Die
        // Antwort auf ein veröffentlichtes Foto ist für jeden Client
        // byteweise dieselbe, ein gemeinsamer Cache-Eintrag ist also richtig -
        // dafür steht `public`. Ein Vary auf Cookie würde ihn zerlegen, denn
        // config.php startet für JEDEN Besucher eine Sitzung und setzt damit
        // ein Cookie; jeder Besucher bekäme seinen eigenen Eintrag und die
        // Jahresfrist wäre wertlos. Das Problem dieses Befunds sind die
        // unveröffentlichten Fotos, und die verlassen den Server jetzt gar
        // nicht mehr zwischenspeicherbar.
        //
        // Öffentlich (Audit M14): Ein Jahr nur für den Browser und nur unter
        // der passenden Version; gemeinsame Caches höchstens fünf Minuten
        // (s-maxage), weil eine Depublikation die Adresse nicht ändert.
        // Adressen ohne oder mit falscher Version gelten überall fünf
        // Minuten - eine falsche Version ist kein Fehler (alte Seite im
        // Cache, API-Konsument), sie bekommt nur keine lange Frist.
        // `immutable` bewusst nicht: Unter derselben Adresse kann sich der
        // Inhalt ändern (Vorschau eingeschaltet, Bestandsbereinigung).
        if (!$oeffentlich) {
            $cacheControl = 'private, no-store';
        } elseif ($versioniert) {
            $cacheControl = 'public, max-age=' . self::CACHE_SECONDS . ', s-maxage=' . self::GEMEINSAM_SECONDS;
        } else {
            $cacheControl = 'public, max-age=' . self::GEMEINSAM_SECONDS;
        }
        header('Cache-Control: ' . $cacheControl);
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');

        // Bedingte Anfragen: Ohne sie holte der Browser das Bild bei jedem
        // Neuladen vollständig - die Auslieferung über PHP wäre dann tatsächlich
        // der Rückschritt, den das Issue befürchtet.
        //
        // Reihenfolge nach RFC 9110 §13.1.3/§13.2.2 (Audit N50): Schickt der
        // Client ein If-None-Match, entscheidet AUSSCHLIESSLICH das ETag. Bis
        // dahin genügte ein passendes If-Modified-Since auch bei abweichendem
        // ETag - wechselte die Adresse auf eine ÄLTERE Datei (Hauptbild
        // zurückgetauscht, Original statt Vorschau), bestätigte der Server
        // dem Browser mit 304 das falsche Bild.
        $ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        if ($ifNoneMatch !== '') {
            $nichtGeaendert = self::etagPasst($ifNoneMatch, $etag);
        } else {
            $ifModifiedSince = strtotime((string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '')) ?: 0;
            $nichtGeaendert = $ifModifiedSince > 0 && $ifModifiedSince >= $mtime;
        }
        if ($nichtGeaendert) {
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

    /**
     * Schwacher Vergleich nach RFC 9110 §13.1.2: `*` passt immer, sonst
     * eine Liste, deren Einträge ohne `W/` verglichen werden (ein Proxy mit
     * Kompression macht aus unserem starken ETag gern ein schwaches).
     */
    private static function etagPasst(string $ifNoneMatch, string $etag): bool {
        if ($ifNoneMatch === '*') {
            return true;
        }
        foreach (explode(',', $ifNoneMatch) as $eintrag) {
            $eintrag = trim($eintrag);
            if (str_starts_with($eintrag, 'W/')) {
                $eintrag = substr($eintrag, 2);
            }
            if ($eintrag === $etag) {
                return true;
            }
        }
        return false;
    }

    private function sendStatus(int $code): void {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        // Eine Ablehnung ist immer zugriffsabhängig: Der 404 für Gäste und der
        // 403 gegen eine fremde einbettende Seite gelten nur für DIESEN
        // Abrufer, ein gemeinsamer Zwischenspeicher dürfte den 404 sonst
        // heuristisch aufbewahren und anschliessend einem Berechtigten
        // ausliefern - dieselbe Verwechslung wie bei den 200ern, nur in die
        // andere Richtung.
        //
        // Heute käme dasselbe Ergebnis auch ohne diese Zeile zustande, weil
        // PHPs session.cache_limiter ('nocache') bei jedem session_start()
        // bereits no-store setzt. Darauf soll sich hier aber nichts verlassen:
        // Der Kurzschluss aus #311 macht es denkbar, dass für diesen Pfad
        // eines Tages keine Sitzung mehr gestartet wird - und dann fiele die
        // Zusicherung still weg.
        header('Cache-Control: private, no-store');
        echo $code === 403 ? 'Forbidden' : 'Not Found';
        exit;
    }
}
