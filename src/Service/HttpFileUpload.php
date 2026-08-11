<?php
// src/Service/HttpFileUpload.php

namespace App\Service;

/**
 * Class HttpFileUpload
 *
 * Streamender HTTP-Upload einer Datei über einen rohen TCP-/TLS-Socket
 * (`stream_socket_client()`), gemeinsam genutzt von App\Service\S3Client und
 * App\Service\WebDavClient für den Datei-Weg (#237).
 *
 * Warum ein roher Socket statt der bequemeren Wege: Der http-Stream-Wrapper
 * (String-Weg der beiden Clients) akzeptiert in der `content`-Kontextoption
 * nur Strings - eine übergebene Stream-Ressource wird stillschweigend als
 * leerer Body gesendet (in PHP 8.5 nachgeprüft). Und curl (`CURLOPT_INFILE`)
 * scheidet bewusst aus, weil die curl-Extension im mitgelieferten Dockerfile
 * nicht installiert und auf klassischem Webhosting nicht immer verfügbar ist
 * (gleiche Begründung wie in S3Client/WebDavClient/Mailer). Für einen Upload
 * mit konstantem Speicher bleibt damit nur, die HTTP-Anfrage selbst auf einen
 * Socket zu schreiben und den Datei-Inhalt blockweise hinterherzuschieben.
 */
final class HttpFileUpload {

    /**
     * Sendet eine HTTP-Anfrage, deren Body streamend aus einer Datei gelesen
     * wird, und liest die vollständige Antwort.
     *
     * @param array<int, string> $headerLines Vollständige Header-Zeilen
     *        ("Name: Wert") inklusive Host - anders als beim http-Wrapper
     *        setzt auf dem rohen Socket niemand automatisch Header;
     *        Content-Length und Connection: close ergänzt diese Methode
     *        selbst.
     * @return array{status: ?int, body: string} status null = Verbindung
     *         fehlgeschlagen oder abgerissen - die Fehlermeldung mit Kontext
     *         (Zieltyp, Schlüssel) baut der Aufrufer, der beides kennt,
     *         analog zum String-Weg der Clients.
     */
    public static function send(
        string $method,
        string $host,
        int $port,
        bool $tls,
        string $requestTarget,
        array $headerLines,
        string $bodyFile,
        int $timeoutSeconds = 30,
    ): array {
        $size = @filesize($bodyFile);
        $body = @fopen($bodyFile, 'rb');
        if ($size === false || $body === false) {
            if (is_resource($body)) {
                fclose($body);
            }
            throw new \RuntimeException("Upload-Datei nicht lesbar: {$bodyFile}");
        }

        // TLS mit Standard-Kontext: Zertifikatsprüfung gegen den System-CA-
        // Speicher, SNI/peer_name aus dem Hostnamen - identisch zum Verhalten
        // des https-Stream-Wrappers im String-Weg.
        $socket = @stream_socket_client(($tls ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, $timeoutSeconds);
        if ($socket === false) {
            fclose($body);
            return ['status' => null, 'body' => ''];
        }

        try {
            stream_set_timeout($socket, $timeoutSeconds);

            $head = "{$method} {$requestTarget} HTTP/1.1\r\n"
                . implode("\r\n", $headerLines) . "\r\n"
                . "Content-Length: {$size}\r\n"
                . "Connection: close\r\n\r\n";

            if (@fwrite($socket, $head) !== strlen($head)) {
                return ['status' => null, 'body' => ''];
            }

            // Der eigentliche Speichervorteil: Datei → Socket in internen
            // 8-KiB-Blöcken, ohne den Inhalt je als Gesamtstring zu halten.
            if (@stream_copy_to_stream($body, $socket) !== $size) {
                return ['status' => null, 'body' => ''];
            }

            $raw = (string)@stream_get_contents($socket);
        } finally {
            fclose($body);
            fclose($socket);
        }

        return self::parseResponse($raw);
    }

    /**
     * @return array{status: ?int, body: string}
     */
    private static function parseResponse(string $raw): array {
        $separator = strpos($raw, "\r\n\r\n");
        if ($separator === false) {
            return ['status' => null, 'body' => ''];
        }

        $head = substr($raw, 0, $separator);
        $body = substr($raw, $separator + 4);

        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $head, $matches) !== 1) {
            return ['status' => null, 'body' => ''];
        }

        // Mit "Connection: close" darf der Server den Body schlicht bis zum
        // Verbindungsende senden; kündigt er stattdessen Chunked Transfer-
        // Encoding an (z. B. php -S für dynamisch erzeugte Fehler-Bodys),
        // müssen die Chunk-Längenzeilen wieder herausgerechnet werden, bevor
        // der Aufrufer den Body (S3-Fehler-XML) auswertet.
        if (preg_match('/^Transfer-Encoding:\s*chunked/im', $head) === 1) {
            $body = self::decodeChunked($body);
        }

        return ['status' => (int)$matches[1], 'body' => $body];
    }

    private static function decodeChunked(string $body): string {
        $decoded = '';
        $offset = 0;
        while (($lineEnd = strpos($body, "\r\n", $offset)) !== false) {
            // Chunk-Längenzeile: Hex-Zahl, optional gefolgt von Erweiterungen
            // (";…") - hexdec() auf die ganze Zeile würde deren Zeichen still
            // mitverdauen, daher vorher exakt den Hex-Präfix herausziehen.
            if (preg_match('/^[0-9a-fA-F]+/', substr($body, $offset, $lineEnd - $offset), $matches) !== 1) {
                break;
            }
            $size = (int)hexdec($matches[0]);
            if ($size === 0) {
                break;
            }
            $decoded .= substr($body, $lineEnd + 2, $size);
            $offset = $lineEnd + 2 + $size + 2; // Chunk-Daten + abschließendes CRLF
        }
        return $decoded;
    }
}
