<?php
// src/Service/HorseCsvImporter.php

namespace App\Service;

use PDO;

/**
 * Class HorseCsvImporter
 *
 * Parsing und Validierung für den CSV-Bulk-Import von Pferden (#49). Bewusst
 * nur echtes CSV (kein natives .xlsx-Parsing) - konsistent mit der
 * "keine externen Abhängigkeiten"-Philosophie des Kerns (siehe
 * docs/architecture.md): ein XLSX-Parser ohne Bibliothek wäre ein
 * ZIP+XML-Parser in Eigenbau, CSV lässt sich dagegen bereits mit PHPs
 * eingebautem `fgetcsv()` sicher lesen. Jede Tabellenkalkulation (Excel,
 * LibreOffice, Google Sheets) kann als CSV exportieren.
 *
 * Zwei-Schritt-Ablauf (siehe ImportController): parse()+validate() laufen
 * IDENTISCH sowohl in der Vorschau als auch beim tatsächlichen Import -
 * der rohe CSV-Text wird dafür serverseitig in der Session zwischengespeichert
 * (siehe ImportController::PREVIEW), niemals die geparsten Zeilen selbst,
 * damit ein manipulierter Preview-Request keine falschen Daten einschleusen
 * kann.
 */
final class HorseCsvImporter {

    private function __construct() {}

    /** Maximal zulässige Datenzeilen (ohne Kopfzeile) je Import - Schutz vor versehentlichem/böswilligem Massen-Upload. */
    public const MAX_ROWS = 2000;

    /**
     * Spaltennamen der Kopfzeile (case-insensitiv erkannt) und ihre Bedeutung.
     * Nur 'name' ist zwingend - alle anderen Spalten sind optional und dürfen
     * in der Datei auch komplett fehlen bzw. in beliebiger Reihenfolge stehen.
     */
    public const KNOWN_COLUMNS = [
        'name', 'ueln', 'foreign_ueln', 'sire_name', 'sire_ueln',
        'dam_name', 'dam_ueln', 'birth_year', 'color', 'sex', 'breed',
        'breeding_station', 'description', 'status',
    ];

    private const VALID_STATUSES = ['active', 'inactive', 'deceased'];

    /**
     * Akzeptierte Geschlechts-Angaben (case-insensitiv) und ihr kanonischer
     * ENUM-Wert (#165). Deutsche und englische Begriffe, damit Bestandslisten
     * beider Sprachen ohne Umformung importierbar sind.
     */
    private const SEX_ALIASES = [
        'stallion' => 'stallion', 'hengst' => 'stallion',
        'mare' => 'mare', 'stute' => 'mare',
        'gelding' => 'gelding', 'wallach' => 'gelding',
    ];

    /**
     * Maximale Feldlängen, identisch zu den jeweiligen Spalten in
     * database/schema.sql - eine zu lange Eingabe wird als Fehler markiert
     * statt beim tatsächlichen INSERT einen unklaren DB-Fehler zu erzeugen.
     */
    private const MAX_LENGTHS = [
        'name' => 100,
        'ueln' => 50,
        'foreign_ueln' => 50,
        'sire_name' => 100,
        'sire_ueln' => 15,
        'dam_name' => 100,
        'dam_ueln' => 15,
        'color' => 50,
        'breed' => 100,
        'breeding_station' => 255,
        // TEXT-Spalte: 65.535 Byte - ohne diese Grenze würde eine überlange
        // Beschreibung erst beim INSERT einen DB-Fehler auslösen (#133).
        'description' => 65535,
    ];

    /**
     * Zerlegt rohen CSV-Text in Kopfzeile + Datenzeilen. Erkennt das
     * Trennzeichen (Komma vs. Semikolon - deutsches Excel nutzt beim Export
     * meist Semikolon, da Komma dort das Dezimaltrennzeichen ist) anhand der
     * Kopfzeile automatisch, entfernt eine eventuelle UTF-8 BOM (verbreitete
     * Excel-Export-Eigenheit) und versucht bei nicht-UTF-8-Inhalt (z. B.
     * Windows-1252 aus älterem Excel) eine automatische Konvertierung.
     *
     * @return array{columnMap: array<string,int>, rows: array<int, array<int,string>>, error: string|null}
     */
    public static function parse(string $rawContent): array {
        $content = self::normalizeEncoding($rawContent);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        $firstLine = strtok($content, "\r\n") ?: '';
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        // fgetcsv() über einen Stream statt zeilenweisem str_getcsv(): nur so
        // werden RFC-4180-konforme, in Anführungszeichen eingebettete
        // Zeilenumbrüche (Standard-Export von Excel/LibreOffice bei mehrzeiligen
        // Zellen) als EIN Datensatz gelesen statt als mehrere (#124).
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, (string)$content);
        rewind($fh);

        $rows = [];
        while (($parsed = fgetcsv($fh, 0, $delimiter, '"', '\\')) !== false) {
            // Leere Zeilen überspringen (fgetcsv liefert dafür [null] bzw. [''])
            if ($parsed === [null] || (count($parsed) === 1 && trim((string)$parsed[0]) === '')) {
                continue;
            }
            $rows[] = array_map(fn($v) => trim((string)$v), $parsed);
        }
        fclose($fh);

        if (empty($rows)) {
            return ['columnMap' => [], 'rows' => [], 'error' => 'Die Datei enthält keine Zeilen.'];
        }

        $header = array_map(fn($h) => strtolower(trim($h)), array_shift($rows));
        $columnMap = [];
        foreach ($header as $index => $name) {
            if (in_array($name, self::KNOWN_COLUMNS, true)) {
                $columnMap[$name] = $index;
            }
        }

        if (!isset($columnMap['name'])) {
            return [
                'columnMap' => [],
                'rows' => [],
                'error' => 'Pflichtspalte "name" nicht in der Kopfzeile gefunden. Erste Zeile der Datei muss die Spaltennamen enthalten (mind. "name").',
            ];
        }

        if (count($rows) > self::MAX_ROWS) {
            return [
                'columnMap' => $columnMap,
                'rows' => [],
                'error' => "Die Datei enthält " . count($rows) . " Datenzeilen, maximal " . self::MAX_ROWS . " sind je Import erlaubt. Bitte in mehrere Dateien aufteilen.",
            ];
        }

        return ['columnMap' => $columnMap, 'rows' => $rows, 'error' => null];
    }

    /**
     * Validiert jede Datenzeile einzeln (Pflichtfeld, Feldlängen, gültiger
     * Status, numerisches Geburtsjahr, UELN-Eindeutigkeit sowohl innerhalb
     * der Datei als auch gegen bereits vorhandene Pferde in der DB - die
     * UNIQUE-Constraint auf `horses.ueln` gilt unabhängig von `deleted_at`,
     * siehe database/schema.sql, daher hier ebenfalls ohne
     * deleted_at-Einschränkung geprüft).
     *
     * @param array{columnMap: array<string,int>, rows: array<int, array<int,string>>} $parsed
     * @return array<int, array{row:int, data:array<string,mixed>, errors:array<int,string>}>
     */
    public static function validateRows(array $parsed, PDO $db): array {
        $columnMap = $parsed['columnMap'];
        $existingUelns = array_flip(array_map('strval', $db->query("SELECT ueln FROM horses WHERE ueln IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN)));
        $seenUelnsInFile = [];

        $results = [];
        foreach ($parsed['rows'] as $rowIndex => $row) {
            $get = fn(string $col): string => isset($columnMap[$col], $row[$columnMap[$col]]) ? trim((string)$row[$columnMap[$col]]) : '';

            $data = [
                'name' => $get('name'),
                'ueln' => $get('ueln') ?: null,
                'foreign_ueln' => $get('foreign_ueln') ?: null,
                'sire_name' => $get('sire_name') ?: null,
                'sire_ueln' => $get('sire_ueln') ?: null,
                'dam_name' => $get('dam_name') ?: null,
                'dam_ueln' => $get('dam_ueln') ?: null,
                'birth_year' => $get('birth_year'),
                'color' => $get('color'),
                'sex' => $get('sex'),
                'breed' => $get('breed') ?: null,
                'breeding_station' => $get('breeding_station'),
                'description' => $get('description'),
                'status' => $get('status') ?: 'active',
            ];

            $errors = [];

            if ($data['name'] === '') {
                $errors[] = 'Name fehlt.';
            }

            foreach (self::MAX_LENGTHS as $field => $maxLength) {
                // description ist eine TEXT-Spalte, deren Limit in BYTES gilt -
                // dort zählt strlen() (Bytes), sonst Zeichenlänge wie bisher.
                $length = $field === 'description'
                    ? strlen((string)$data[$field])
                    : self::strlen((string)$data[$field]);
                if (!empty($data[$field]) && $length > $maxLength) {
                    $errors[] = ucfirst(str_replace('_', ' ', $field)) . " ist zu lang (max. {$maxLength} Zeichen).";
                }
            }

            if ($data['birth_year'] !== '') {
                if (!ctype_digit($data['birth_year']) || (int)$data['birth_year'] < 1600 || (int)$data['birth_year'] > (int)date('Y') + 1) {
                    $errors[] = "Geburtsjahr '{$data['birth_year']}' ist ungültig (erwartet: Jahreszahl zwischen 1600 und " . ((int)date('Y') + 1) . ").";
                }
            }
            $data['birth_year'] = $data['birth_year'] !== '' && ctype_digit($data['birth_year']) ? (int)$data['birth_year'] : null;

            if (!in_array($data['status'], self::VALID_STATUSES, true)) {
                $errors[] = "Status '{$data['status']}' ist ungültig (erlaubt: " . implode(', ', self::VALID_STATUSES) . ").";
            }

            // Geschlecht (#165): leer -> NULL (unbekannt); ungültige Angabe ist ein
            // Zeilenfehler (konsistent zur Status-Behandlung), keine stille NULL.
            if ($data['sex'] !== '') {
                $sexKey = strtolower($data['sex']);
                if (isset(self::SEX_ALIASES[$sexKey])) {
                    $data['sex'] = self::SEX_ALIASES[$sexKey];
                } else {
                    $errors[] = "Geschlecht '{$data['sex']}' ist ungültig (erlaubt: " . implode(', ', array_keys(self::SEX_ALIASES)) . ").";
                }
            } else {
                $data['sex'] = null;
            }

            if ($data['ueln'] !== null) {
                if (isset($existingUelns[$data['ueln']])) {
                    $errors[] = "UELN '{$data['ueln']}' ist bereits einem bestehenden Pferd zugeordnet.";
                } elseif (isset($seenUelnsInFile[$data['ueln']])) {
                    $errors[] = "UELN '{$data['ueln']}' kommt mehrfach in dieser Datei vor (Zeile {$seenUelnsInFile[$data['ueln']]}).";
                } else {
                    $seenUelnsInFile[$data['ueln']] = $rowIndex + 2; // +2: 1-basiert + Kopfzeile
                }
            }

            $results[] = ['row' => $rowIndex + 2, 'data' => $data, 'errors' => $errors];
        }

        return $results;
    }

    /**
     * Best-effort UTF-8-Normalisierung für aus älterem Excel exportierte
     * CSV-Dateien (häufig Windows-1252/ISO-8859-1 statt UTF-8). Lässt bereits
     * gültigen UTF-8-Inhalt unangetastet. Die `mbstring`-Extension ist auf
     * einfachem Shared-Hosting nicht immer aktiviert (siehe
     * docs/architecture.md, "keine externen Abhängigkeiten") - ohne sie wird
     * der Inhalt unverändert durchgereicht statt einen Fatal Error
     * auszulösen, analog zum bestehenden Fallback in
     * App\Service\AuditLogger::truncate().
     */
    private static function normalizeEncoding(string $content): string {
        if (!function_exists('mb_check_encoding') || !function_exists('mb_detect_encoding') || !function_exists('mb_convert_encoding')) {
            return $content;
        }

        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }

        $detected = mb_detect_encoding($content, ['Windows-1252', 'ISO-8859-1'], true);
        if ($detected !== false) {
            $converted = mb_convert_encoding($content, 'UTF-8', $detected);
            if (is_string($converted)) {
                return $converted;
            }
        }

        return $content;
    }

    /**
     * Zeichenlängen-Zählung mit Fallback ohne `mbstring` (siehe
     * normalizeEncoding()) - identisches Muster zu
     * App\Service\AuditLogger::truncate().
     */
    private static function strlen(string $value): int {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }
}
