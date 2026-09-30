<?php
// src/Service/TarArchive.php

namespace App\Service;

/**
 * Class TarArchive
 *
 * Streamender ustar-Schreiber in reinem PHP (#233) - kein `tar`-Binary, keine
 * PharData-/ext-phar-Abhängigkeit nötig, passend zur "keine externen
 * Abhängigkeiten"-Philosophie des Kerns. In den Kern übernommen aus dem
 * bewährten TarWriter des Addons `datenmigration` (dort für den
 * Instanz-Umzug im Einsatz); Hauptnutzer im Kern ist App\Service\BackupService
 * für das optionale Mitsichern der Uploads im externen Backup.
 *
 * Eigenschaften:
 * - Schreibt Block für Block direkt in die Zieldatei (optional gzip via
 *   zlib) - der Speicherbedarf bleibt unabhängig von der Archivgröße
 *   konstant, es liegt nie ein Gesamtarchiv im Speicher.
 * - Archiviert ausschließlich reguläre Dateien (keine Symlinks/Devices);
 *   leere Verzeichnisse erhalten keinen eigenen Eintrag - beim Entpacken
 *   legt `tar -x` die Elternverzeichnisse der Dateien selbst an.
 * - ustar-Format: Pfade bis 100 Zeichen direkt, bis 255 Zeichen über das
 *   prefix-Feld - von GNU tar, bsdtar und PharData gleichermaßen lesbar.
 */
final class TarArchive {

    /**
     * Schreibweg mit Prüfung jedes Schreibvorgangs und des gzip-Abschlusses
     * (Audit M43) - gzwrite() meldet einen vollen Datenträger sonst nicht.
     */
    private CheckedFileWriter $out;

    private function __construct(CheckedFileWriter $out) {
        $this->out = $out;
    }

    /**
     * Öffnet eine neue Archivdatei zum Schreiben. Ob gzip-komprimiert wird,
     * bestimmt standardmäßig die Dateiendung (`.gz` + vorhandene
     * zlib-Extension); mit $gzip lässt es sich explizit vorgeben - nützlich
     * für Temp-Dateien ohne sprechende Endung.
     */
    public static function create(string $path, ?bool $gzip = null): self {
        $gzip ??= str_ends_with($path, '.gz');
        try {
            return new self(CheckedFileWriter::open($path, $gzip, 6));
        } catch (\RuntimeException $e) {
            throw new \RuntimeException("Archiv nicht schreibbar: {$path}", 0, $e);
        }
    }

    private function write(string $data): void {
        $this->out->write($data);
    }

    /**
     * Fügt einen Eintrag mit Inhalt aus dem Speicher hinzu (z. B. eine
     * Manifest-/Metadatei).
     */
    public function addString(string $name, string $content): void {
        $this->writeHeader($name, strlen($content));
        $this->write($content);
        $this->pad(strlen($content));
    }

    /**
     * Fügt eine Datei vom Dateisystem hinzu - gelesen und geschrieben in
     * 512-KiB-Chunks, die Datei liegt nie komplett im Speicher.
     */
    public function addFile(string $name, string $sourcePath): void {
        $size = filesize($sourcePath);
        if ($size === false) {
            throw new \RuntimeException("Datei nicht lesbar: {$sourcePath}");
        }
        $in = fopen($sourcePath, 'rb');
        if ($in === false) {
            throw new \RuntimeException("Datei nicht lesbar: {$sourcePath}");
        }
        $this->writeHeader($name, $size);
        $written = 0;
        while (!feof($in)) {
            $chunk = fread($in, 1024 * 512);
            if ($chunk === false) {
                fclose($in);
                throw new \RuntimeException("Lesefehler: {$sourcePath}");
            }
            $this->write($chunk);
            $written += strlen($chunk);
        }
        fclose($in);
        if ($written !== $size) {
            throw new \RuntimeException("Datei änderte sich während des Archivierens: {$sourcePath}");
        }
        $this->pad($size);
    }

    /**
     * Fügt alle regulären Dateien unterhalb von $dir rekursiv hinzu, mit
     * $prefix als Pfad-Wurzel im Archiv (z. B. `uploads`). Einträge werden
     * je Verzeichnisebene alphabetisch sortiert - deterministische Archive
     * bei gleichem Bestand. Symlinks und Sonderdateien werden übersprungen.
     */
    public function addDirectoryTree(string $dir, string $prefix): void {
        $entries = scandir($dir);
        if ($entries === false) {
            throw new \RuntimeException("Verzeichnis nicht lesbar: {$dir}");
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            $archiveName = ($prefix === '' ? '' : $prefix . '/') . $entry;
            if (is_link($path)) {
                continue;
            }
            if (is_dir($path)) {
                $this->addDirectoryTree($path, $archiveName);
            } elseif (is_file($path)) {
                $this->addFile($archiveName, $path);
            }
        }
    }

    /**
     * Schreibt die Archiv-Endmarke (zwei Null-Blöcke), schließt die Datei und
     * prüft, ob sie vollständig auf dem Datenträger liegt (Audit M43).
     * Ohne close() ist das Archiv unvollständig.
     *
     * @throws \RuntimeException wenn ein Schreibvorgang oder der Abschluss
     *                           scheiterte (etwa Datenträger voll)
     */
    public function close(): void {
        $this->write(str_repeat("\0", 1024)); // Zwei Null-Blöcke = Archivende
        $this->out->close();
    }

    /**
     * Schließt die Datei ohne Endmarke und ohne Prüfung - für Fehlerpfade,
     * nach denen das Archiv ohnehin verworfen wird. Idempotent.
     */
    public function abort(): void {
        $this->out->abort();
    }

    private function pad(int $size): void {
        $rest = $size % 512;
        if ($rest !== 0) {
            $this->write(str_repeat("\0", 512 - $rest));
        }
    }

    private function writeHeader(string $name, int $size): void {
        $prefix = '';
        if (strlen($name) > 100) {
            // ustar: name (100) + prefix (155), getrennt an einem '/'
            $cut = strrpos(substr($name, 0, 156), '/');
            if ($cut === false || strlen($name) - $cut - 1 > 100) {
                throw new \RuntimeException("Pfad zu lang für ustar: {$name}");
            }
            $prefix = substr($name, 0, $cut);
            $name = substr($name, $cut + 1);
        }
        $header = str_pad($name, 100, "\0")
            . '0000644' . "\0"                       // mode
            . '0000000' . "\0" . '0000000' . "\0"    // uid/gid
            . sprintf('%011o', $size) . "\0"
            . sprintf('%011o', time()) . "\0"
            . '        '                             // Platzhalter Prüfsumme
            . '0'                                    // typeflag: regular file
            . str_repeat("\0", 100)                  // linkname
            . "ustar\0" . '00'
            . str_pad('', 32, "\0") . str_pad('', 32, "\0")  // uname/gname
            . '0000000' . "\0" . '0000000' . "\0"    // devmajor/minor
            . str_pad($prefix, 155, "\0");
        $header = str_pad($header, 512, "\0");
        $checksum = 0;
        for ($i = 0; $i < 512; $i++) {
            $checksum += ord($header[$i]);
        }
        $header = substr_replace($header, sprintf('%06o', $checksum) . "\0 ", 148, 8);
        $this->write($header);
    }
}
