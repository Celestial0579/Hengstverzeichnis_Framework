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
     * Die EINZIGE Ablagestelle fuer Pferdefotos (auch das Foto beim Anlegen
     * eines Pferds, HorseController). Metadaten (GPS, Kamera, Aufnahmezeit,
     * XMP, Kommentare, angehaengte Bewegungsfotos) werden VOR der Ablage
     * entfernt (Audit M21, BildMetadaten): Der Inhalt wird eingelesen,
     * bereinigt und atomar an den endgueltigen Namen geschrieben. Eine
     * Rohfassung liegt so zu keinem Zeitpunkt in storage/horses - auch nicht
     * nach einem Prozessabbruch, und damit auch in keiner Sicherung
     * (BackupService sichert das ganze Verzeichnis). Laesst sich der Aufbau
     * nicht lesen, wird der Upload abgelehnt.
     *
     * @param array<string, mixed>|null $file Eintrag aus $_FILES
     * @param array<string, string>|null $erlaubt MIME-Typ => Endung; Vorgabe
     *   ERLAUBTE_TYPEN
     */
    public static function speichereUpload(?array $file, ?array $erlaubt = null): ?string {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }
        if (($file['size'] ?? 0) <= 0 || $file['size'] > self::MAX_BYTES) {
            return null;
        }

        // Positivliste ueber den tatsaechlichen Inhalt, nicht ueber die
        // Endung im Namen - dieselbe Pruefung wie beim Kernfoto.
        $erlaubt ??= self::ERLAUBTE_TYPEN;
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return null;
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!is_string($mime) || !isset($erlaubt[$mime])) {
            return null;
        }

        $inhalt = @file_get_contents($tmp);
        if ($inhalt === false) {
            return null;
        }
        $inhalt = BildMetadaten::bereinigeBytes($inhalt);
        if ($inhalt === null) {
            @unlink($tmp);
            return null;
        }

        $verzeichnis = HorseImagePath::dir() . '/';
        if (!is_dir($verzeichnis) && !@mkdir($verzeichnis, 0755, true) && !is_dir($verzeichnis)) {
            return null;
        }

        $name = 'horse_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $erlaubt[$mime];
        if (!\App\Helper\AtomicFile::write($verzeichnis . $name, $inhalt, 0644)) {
            return null;
        }
        @unlink($tmp);

        return '/uploads/horses/' . $name;
    }

    /**
     * Legt ein Medium an.
     *
     * Bild ODER Video - bei beidem gewinnt das Bild. Nach einem Bild stellt
     * syncMainImage() die Invariante her (genau ein Hauptbild, und zwar das
     * in `horses.image_url`): Das erste Bild eines Pferds wird Hauptbild,
     * ein weiteres verdraengt das angezeigte nicht - auch dann nicht, wenn
     * es eine kleinere Sortierung hat (Audit N69). Beim Anlegen ueber
     * HorseController::store() zeigt image_url schon auf das neue Bild, das
     * damit Hauptbild wird.
     *
     * Ein Katalogfoto ohne Medienzeile (v0.8-Bestand ohne Addon `galerie`,
     * Audit M40) wird vorher als Zeile uebernommen - sonst verdraengte das
     * neue Bild es ungefragt, und das Foto waere aus der Pflege verschwunden.
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

        // $ausser: Beim Anlegen schreibt store() image_url zuerst - dieselbe
        // Datei darf hier keine zweite Zeile bekommen.
        self::bestandsHauptbildUebernehmen($horseId, $fileName);

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

        if ($typ === self::TYP_BILD) {
            self::syncMainImage($horseId);
        }

        return $id;
    }

    /**
     * Loescht ein Medium samt Datei.
     *
     * War es das Hauptbild, rueckt das naechste Bild nach und wird als
     * Hauptbild GEKENNZEICHNET (Audit N69) - sonst stuende das Pferd in
     * Katalog und Liste ohne Foto da, obwohl noch welche vorhanden sind.
     *
     * DIE REIHENFOLGE IST DER KERN (Audit N68): erst die Zeile weg, dann
     * syncMainImage(), und ERST DANACH die Datei. Vorher zeigt image_url
     * noch auf genau diese Datei, und die Referenzpruefung liesse sie
     * stehen - beim Hauptbild also immer. Die Reihenfolge ist zugleich
     * fehlersicher: Wirft ein frueherer Schritt, bleibt die Datei liegen,
     * statt dass eine Zeile auf eine geloeschte Datei zeigt.
     */
    public static function loeschen(int $mediaId): bool {
        $medium = self::byId($mediaId);
        if ($medium === null) {
            return false;
        }
        $horseId = (int)$medium['horse_id'];

        // Ein Katalogfoto ohne Medienzeile (Audit M40) vorher uebernehmen -
        // sonst leerte das Loeschen etwa eines Videos das Katalogbild.
        self::bestandsHauptbildUebernehmen($horseId);

        $db = Database::getInstance();
        $stmt = $db->prepare('DELETE FROM horse_media WHERE id = ?');
        $stmt->execute([$mediaId]);

        self::syncMainImage($horseId);

        if (($medium['type'] ?? '') === self::TYP_BILD && !empty($medium['file_name'])) {
            self::verwaisteDateienEntfernen([(string)$medium['file_name']]);
        }

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

        // Das bisherige Katalogfoto bleibt als Nebenbild erhalten, auch wenn
        // es noch keine Medienzeile hat (Audit M40) - sonst waere es nach dem
        // Umschalten unerreichbar.
        self::bestandsHauptbildUebernehmen($horseId);

        $db->prepare('UPDATE horse_media SET is_main = 0 WHERE horse_id = ?')->execute([$horseId]);
        $db->prepare('UPDATE horse_media SET is_main = 1 WHERE id = ?')->execute([$mediaId]);

        self::syncMainImage($horseId);

        return true;
    }

    /**
     * Stellt die Invariante her: Hat das Pferd Bilder, traegt GENAU eines
     * is_main, und `horses.image_url` zeigt darauf. Ohne Bild wird die
     * Spalte geleert.
     *
     * Die EINE Stelle, an der Kennzeichnung und Spalte gemeinsam gesetzt
     * werden (neben dem Anlegen in HorseController::store()). Frueher
     * schrieb sie nur die Spalte - nach dem Loeschen des Hauptbilds zeigte
     * der Katalog den Nachfolger, die Kennzeichnung fehlte aber: Der
     * Nachfolger erschien auf der Detailseite doppelt, und das naechste
     * Bild wurde ungefragt Hauptbild (Audit N69).
     *
     * Auswahl: zuerst ein gekennzeichnetes Bild; ohne Kennzeichnung das
     * Bild, dessen Dateiname dem aktuellen image_url entspricht (Altbestand:
     * das angezeigte Bild bleibt, auch wenn ein Foto mit kleinerer
     * Sortierung dazukommt); erst dann die Anzeigereihenfolge. Doppelte
     * Kennzeichnungen und is_main auf Videozeilen werden dabei bereinigt.
     */
    public static function syncMainImage(int $horseId): void {
        $db = Database::getInstance();

        $stmt = $db->prepare(
            "SELECT m.id, m.file_name FROM horse_media m
             JOIN horses h ON h.id = m.horse_id
             WHERE m.horse_id = ? AND m.type = 'image' AND m.file_name IS NOT NULL
             ORDER BY m.is_main DESC,
                      (" . self::sqlDateiname('m.file_name') . " = " . self::sqlDateiname("COALESCE(h.image_url, '')") . ") DESC,
                      m.sort_order ASC, m.id ASC
             LIMIT 1"
        );
        $stmt->execute([$horseId]);
        $zeile = $stmt->fetch(PDO::FETCH_ASSOC);

        $hauptId = is_array($zeile) ? (int)$zeile['id'] : 0;
        $datei = is_array($zeile) ? (string)$zeile['file_name'] : null;

        $db->prepare('UPDATE horse_media SET is_main = (id = ?) WHERE horse_id = ? AND is_main <> (id = ?)')
            ->execute([$hauptId, $horseId, $hauptId]);

        $stmt = $db->prepare('UPDATE horses SET image_url = ? WHERE id = ? AND NOT (image_url <=> ?)');
        $stmt->execute([$datei, $horseId, $datei]);
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
     * SQL-Ausdruck: der Dateiname eines Spaltenwerts - ohne Query, Fragment
     * und Pfad. Dasselbe, was basename(parse_url(...)) in PHP liefert, und
     * das, was MediaController und Thumbnails tatsaechlich aufloesen. Wer
     * Referenzen ueber den exakten Spaltenwert zaehlt, uebersieht
     * `uploads/horses/x.jpg` neben `/uploads/horses/x.jpg` - und loescht
     * dann eine Datei, die noch gebraucht wird.
     */
    private static function sqlDateiname(string $ausdruck): string {
        return "SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX({$ausdruck}, '#', 1), '?', 1), '/', -1)";
    }

    /** Der Dateiname eines Spaltenwerts in PHP, oder null bei '', '.', '..'. */
    private static function dateiname(string $spaltenwert): ?string {
        $name = basename(parse_url($spaltenwert, PHP_URL_PATH) ?? '');

        return ($name === '' || $name === '.' || $name === '..') ? null : $name;
    }

    /**
     * Uebernimmt ein Katalogfoto ohne Medienzeile in die Medien (Audit M40).
     *
     * Bis v0.8 gab es nur `horses.image_url`. Die Uebernahme (#339) legte
     * die Zeile dafuer nur mit Addon `galerie` an; ohne Addon kannte die
     * Medienliste das Foto nicht, und die naechste Medienaktion ersetzte
     * oder leerte das Katalogbild. Der Datenschritt 339b holt das einmalig
     * nach - diese Abwehr faengt zusaetzlich Werte ab, die spaeter ohne
     * Zeile entstehen (Direktimporte, Restore-Mischstaende).
     *
     * Hauptbild wird die neue Zeile nur, wenn noch keines gekennzeichnet
     * ist. $ausser: Dateiname, fuer den gerade selbst eine Zeile entsteht.
     */
    private static function bestandsHauptbildUebernehmen(int $horseId, ?string $ausser = null): void {
        $db = Database::getInstance();

        $stmt = $db->prepare('SELECT image_url FROM horses WHERE id = ?');
        $stmt->execute([$horseId]);
        $bild = $stmt->fetchColumn();
        if (!is_string($bild) || trim($bild) === '') {
            return;
        }
        $name = self::dateiname($bild);
        if ($name === null || ($ausser !== null && self::dateiname($ausser) === $name)) {
            return;
        }

        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM horse_media WHERE horse_id = ? AND file_name IS NOT NULL AND '
            . self::sqlDateiname('file_name') . ' = ?'
        );
        $stmt->execute([$horseId, $name]);
        if ((int)$stmt->fetchColumn() > 0) {
            return;
        }

        $db->prepare(
            "INSERT INTO horse_media (horse_id, type, file_name, is_main, sort_order) VALUES (?, 'image', ?, ?, 0)"
        )->execute([$horseId, $bild, self::hatHauptbild($horseId) ? 0 : 1]);
    }

    /**
     * Die Bilddateien (Spaltenwerte) der genannten Pferde: Medienzeilen und
     * `horses.image_url`, auch ohne Zeile.
     *
     * Muss VOR dem endgueltigen Loeschen laufen - der FK-CASCADE nimmt die
     * Medienzeilen mit dem DELETE mit (Audit N59). Bei einem Fehler: [] und
     * ein Protokolleintrag. Das heisst sicher: Es wird nichts geloescht.
     *
     * @param array<int, int|string> $horseIds
     * @return array<int, string>
     */
    public static function bilddateienVonPferden(array $horseIds): array {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $horseIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return [];
        }

        $werte = [];
        try {
            $db = Database::getInstance();
            foreach (array_chunk($ids, 500) as $teil) {
                $p = implode(',', array_fill(0, count($teil), '?'));
                $stmt = $db->prepare(
                    "SELECT file_name FROM horse_media
                     WHERE horse_id IN ({$p}) AND type = 'image' AND file_name IS NOT NULL AND file_name <> ''
                     UNION
                     SELECT image_url FROM horses
                     WHERE id IN ({$p}) AND image_url IS NOT NULL AND image_url <> ''"
                );
                $stmt->execute([...$teil, ...$teil]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $wert) {
                    $werte[(string)$wert] = true;
                }
            }
        } catch (\Throwable $e) {
            error_log('HorseMedia::bilddateienVonPferden: ' . $e->getMessage());
            return [];
        }

        return array_keys($werte);
    }

    /**
     * Entfernt die Dateien zu den Spaltenwerten - aber nur die, die kein
     * Medium und kein Pferd (auch keines im Papierkorb) mehr ueber den
     * Dateinamen referenziert. Mit den Vorschaubildern (#397) und den Resten
     * abgebrochener atomarer Schreibvorgaenge (Audit M21/N80).
     *
     * Der gemeinsame Loeschweg fuer ein geloeschtes Medium (loeschen(),
     * Audit N68) und fuer endgueltig geloeschte Pferde (TrashController,
     * Audit N59). Die Referenzpruefung ist nicht theoretisch: Die
     * Uebernahme aus dem Addon (#339) kann dasselbe Bild als Galeriebild
     * UND als Hauptbild fuehren, und ein Pferd, das beim endgueltigen
     * Loeschen stehen blieb (Audit N57), verweist weiter auf seine Dateien.
     *
     * Wirft nie: Aufgerufen wird nach einem committeten DELETE, und dann
     * darf nichts mehr ein HTTP 500 ausloesen. Im Zweifel bleibt eine Datei
     * liegen - das ist die sichere Richtung.
     *
     * @param array<int, string> $spaltenwerte
     * @return int Zahl der entfernten Originale
     */
    public static function verwaisteDateienEntfernen(array $spaltenwerte): int {
        $namen = [];
        foreach ($spaltenwerte as $wert) {
            $name = self::dateiname((string)$wert);
            if ($name !== null) {
                $namen[$name] ??= (string)$wert;
            }
        }
        if ($namen === []) {
            return 0;
        }

        $entfernt = 0;
        try {
            $db = Database::getInstance();
            $benutzt = [];
            foreach (array_chunk(array_keys($namen), 500) as $teil) {
                $p = implode(',', array_fill(0, count($teil), '?'));
                $stmt = $db->prepare(
                    'SELECT ' . self::sqlDateiname('file_name') . ' FROM horse_media
                     WHERE file_name IS NOT NULL AND ' . self::sqlDateiname('file_name') . " IN ({$p})
                     UNION
                     SELECT " . self::sqlDateiname('image_url') . ' FROM horses
                     WHERE image_url IS NOT NULL AND ' . self::sqlDateiname('image_url') . " IN ({$p})"
                );
                $stmt->execute([...$teil, ...$teil]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
                    $benutzt[(string)$name] = true;
                }
            }

            // Die Suche nach Resten abgebrochener Schreibvorgaenge kostet je
            // Datei einen Verzeichnisdurchlauf. Beim Leeren eines grossen
            // Papierkorbs waeren das tausende - dort entfaellt sie (wie im
            // Bestandsschritt BildMetadaten); Reste sind ohnehin selten.
            $resteSuchen = count($namen) <= 20;
            foreach ($namen as $name => $wert) {
                if (isset($benutzt[(string)$name])) {
                    continue;
                }
                if (self::dateiUndVorschauLoeschen((string)$name, $wert, $resteSuchen)) {
                    $entfernt++;
                }
            }
        } catch (\Throwable $e) {
            error_log('HorseMedia::verwaisteDateienEntfernen: ' . $e->getMessage());
        }

        return $entfernt;
    }

    /** @return bool ob ein Original entfernt wurde */
    private static function dateiUndVorschauLoeschen(string $name, string $spaltenwert, bool $resteSuchen): bool {
        $entfernt = false;
        foreach ([HorseImagePath::dir(), HorseImagePath::legacyDir()] as $verzeichnis) {
            $pfad = $verzeichnis . '/' . $name;
            if (is_file($pfad) && @unlink($pfad)) {
                $entfernt = true;
            }
            // Reste eines abgebrochenen atomaren Schreibens (Audit M21/N80).
            if ($resteSuchen) {
                \App\Helper\AtomicFile::resteEntfernen($pfad);
            }
        }

        // Die abgeleiteten Vorschaubilder gehen mit (#397). Ohne diese Zeile
        // bliebe je geloeschtem Medium eine Waise in der Ablage liegen - und
        // die faellt erst auf, wenn jemand die Dateien zaehlt.
        Thumbnails::entfernen($spaltenwert, $resteSuchen);

        return $entfernt;
    }
}
