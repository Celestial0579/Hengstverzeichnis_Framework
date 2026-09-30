<?php
// src/Service/CheckedFileWriter.php

namespace App\Service;

/**
 * Schreibt eine (optional gzip-komprimierte) Zwischendatei und stellt sicher,
 * dass sie vollständig auf dem Datenträger liegt (Audit M43).
 *
 * WARUM. PHP meldet Schreibfehler im gzip-Modus nicht zuverlässig:
 * `gzwrite()` liefert bei vollem Datenträger `0` statt `false`, und
 * `gzclose()`/`fclose()` liefern praktisch immer `true` - auch wenn der
 * letzte Flush samt gzip-Abschluss nie auf der Platte ankam. Nachgestellt:
 * `gzopen('/dev/full')` + `gzwrite(1 KB)` meldet 1024 geschriebene Bytes,
 * `gzclose()` meldet Erfolg, die Datei ist leer. Eine Sicherung, die so
 * abgeschnitten wurde, lief bisher als "erfolgreich" durch, und die Rotation
 * löschte dafür eine intakte ältere.
 *
 * Deshalb zwei Ebenen:
 * - write() verlangt pro Aufruf die volle Byte-Zahl und zählt Bytes und
 *   CRC32 der unkomprimierten Daten mit.
 * - close() prüft danach in O(1): ohne gzip die Dateigröße, mit gzip die
 *   Kennung am Anfang und den Abschluss (CRC32 und Länge, RFC 1952) am Ende.
 *   Eine Probe-Dekompression per gzread() erkennt einen fehlenden Abschluss
 *   nachweislich nicht.
 *
 * Voraussetzung: gzopen() schreibt genau ein gzip-Member. Das ist bei zlib
 * immer so.
 */
final class CheckedFileWriter {

    /** @var resource|null */
    private $handle;
    private int $bytes = 0;
    private \HashContext $crc;

    /**
     * @param resource $handle
     */
    private function __construct(
        private readonly string $path,
        private readonly bool $gzip,
        $handle,
    ) {
        $this->handle = $handle;
        $this->crc = hash_init('crc32b');
    }

    /**
     * Öffnet $path zum Schreiben. gzip nur, wenn gewünscht UND die
     * zlib-Extension vorhanden ist - isGzip() sagt, was tatsächlich gilt.
     */
    public static function open(string $path, bool $gzip, int $level = 6): self {
        $gzip = $gzip && function_exists('gzopen');
        $level = max(1, min(9, $level));
        $handle = $gzip ? @gzopen($path, 'wb' . $level) : @fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Zwischendatei {$path} nicht schreibbar.");
        }
        return new self($path, $gzip, $handle);
    }

    public function isGzip(): bool {
        return $this->gzip;
    }

    public function write(string $data): void {
        if ($data === '') {
            return;
        }
        if ($this->handle === null) {
            throw new \LogicException("Zwischendatei {$this->path} ist bereits geschlossen.");
        }
        $n = $this->gzip ? @gzwrite($this->handle, $data) : @fwrite($this->handle, $data);
        if ($n === false || $n !== strlen($data)) {
            throw new \RuntimeException(
                "Schreiben nach {$this->path} fehlgeschlagen - Datenträger oder Kontingent voll?"
            );
        }
        $this->bytes += $n;
        hash_update($this->crc, $data);
    }

    /**
     * Schließt die Datei und prüft, ob sie vollständig ist. Wirft, wenn
     * nicht - die Datei darf dann weder hochgeladen noch als Sicherung
     * gewertet werden.
     */
    public function close(): void {
        if ($this->handle === null) {
            throw new \LogicException("Zwischendatei {$this->path} ist bereits geschlossen.");
        }
        $handle = $this->handle;
        $this->handle = null;
        $ok = $this->gzip ? @gzclose($handle) : @fclose($handle);
        if ($ok === false) {
            throw new \RuntimeException("Schließen von {$this->path} fehlgeschlagen - Datenträger oder Kontingent voll?");
        }
        // Auf das true von gzclose()/fclose() ist kein Verlass - deshalb die
        // Prüfung am fertigen Ergebnis.
        $crc = (int)hexdec(hash_final($this->crc));
        self::assertComplete($this->path, $this->gzip, $this->bytes, $crc);
    }

    /**
     * Schließt still, ohne Prüfung - für Fehlerpfade. Idempotent.
     */
    public function abort(): void {
        if ($this->handle === null) {
            return;
        }
        $handle = $this->handle;
        $this->handle = null;
        $this->gzip ? @gzclose($handle) : @fclose($handle);
    }

    public function __destruct() {
        $this->abort();
    }

    /**
     * Prüft eine fertig geschriebene Datei gegen die mitgezählten Werte.
     * Öffentlich, damit sich die Prüfung ohne vollen Datenträger testen lässt.
     *
     * @param int $bytes Anzahl der UNKOMPRIMIERTEN Bytes
     * @param int $crc32 CRC32 der unkomprimierten Daten
     */
    public static function assertComplete(string $path, bool $gzip, int $bytes, int $crc32): void {
        clearstatcache(true, $path);
        $size = @filesize($path);
        if ($size === false) {
            throw new \RuntimeException("Zwischendatei {$path} fehlt.");
        }
        if (!$gzip) {
            if ($size !== $bytes) {
                throw new \RuntimeException(
                    "Zwischendatei {$path} ist unvollständig ({$size} statt {$bytes} Byte) - Datenträger voll?"
                );
            }
            return;
        }

        $ok = false;
        // Kleinstes gzip-Member: 10 Byte Kopf, Daten, 8 Byte Abschluss.
        if ($size >= 18) {
            $fh = @fopen($path, 'rb');
            if ($fh !== false) {
                $magic = fread($fh, 2);
                $trailer = fseek($fh, -8, SEEK_END) === 0 ? fread($fh, 8) : false;
                fclose($fh);
                $ok = $magic === "\x1f\x8b"
                    && $trailer === pack('V', $crc32 & 0xFFFFFFFF) . pack('V', $bytes & 0xFFFFFFFF);
            }
        }
        if (!$ok) {
            throw new \RuntimeException(
                "Zwischendatei {$path} ist unvollständig (gzip-Abschluss fehlt oder passt nicht) - Datenträger voll?"
            );
        }
    }
}
