<?php
// src/Service/WebDavClient.php

namespace App\Service;

/**
 * Class WebDavClient
 *
 * Minimaler WebDAV-Client für Backup-Ziele (#93), z. B. eine bereits
 * genutzte Nextcloud-/ownCloud-Instanz des Vereins. Passend zur "keine
 * externen Abhängigkeiten"-Philosophie des Kerns (siehe docs/architecture.md)
 * und konsistent mit App\Service\S3Client: PHP-Streams
 * (`stream_context_create()`/`file_get_contents()`) statt der curl-Extension
 * (im mitgelieferten Dockerfile nicht installiert) - `allow_url_fopen`
 * reicht.
 *
 * Nutzt nur die drei WebDAV-Methoden PUT/DELETE/PROPFIND sowie MKCOL zum
 * automatischen Anlegen des Zielordners - ausreichend für den einfachen
 * Datei-Upload/-Listing-Anwendungsfall hier, kein vollständiger
 * WebDAV-Client (keine Sperren/Kopieren/Verschieben o. Ä.).
 */
final class WebDavClient implements BackupTarget {

    public function __construct(
        // Basis-URL bis einschließlich des Zielordners, z. B.
        // "https://cloud.example.org/remote.php/dav/files/verband/backups"
        // (Nextcloud-Konvention) - OHNE abschließenden Slash.
        private readonly string $baseUrl,
        private readonly string $username,
        private readonly string $password,
    ) {}

    public function putObject(string $key, string $body, string $contentType = 'application/octet-stream'): void {
        $this->ensureParentCollectionExists($key);
        $this->request('PUT', $key, $body, ['Content-Type' => $contentType]);
    }

    /**
     * Lädt eine Datei streamend hoch (#237) - Gegenstück zu putObject() für
     * Inhalte, die als fertige Datei vorliegen (Backup-Zwischendateien,
     * siehe App\Service\BackupService): PUT über App\Service\HttpFileUpload
     * (roher Socket, Begründung dort), der Inhalt wird nie als Gesamtstring
     * in den Speicher geladen. MKCOL/DELETE/PROPFIND bleiben beim
     * http-Stream-Wrapper (request()) - deren Bodys sind winzig.
     */
    public function putObjectFromFile(string $key, string $path, string $contentType = 'application/octet-stream'): void {
        $this->ensureParentCollectionExists($key);

        $url = $this->baseUrl . '/' . ltrim($key, '/');
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            throw new \RuntimeException("WebDAV-Basis-URL nicht auswertbar: {$this->baseUrl}");
        }
        // Alles außer ausdrücklichem "http" gilt als TLS - sicherer Default,
        // konsistent zum Verhalten des String-Wegs bei https-Basis-URLs.
        $tls = ($parts['scheme'] ?? 'https') !== 'http';
        $port = $parts['port'] ?? ($tls ? 443 : 80);

        $headerLines = [
            'Host: ' . $parts['host'] . (isset($parts['port']) ? ":{$parts['port']}" : ''),
            'Authorization: Basic ' . base64_encode("{$this->username}:{$this->password}"),
            "Content-Type: {$contentType}",
        ];

        $response = HttpFileUpload::send('PUT', $parts['host'], $port, $tls, $parts['path'] ?? '/', $headerLines, $path);

        if ($response['status'] === null || $response['status'] >= 300) {
            throw new \RuntimeException("WebDAV-Anfrage (PUT {$key}) fehlgeschlagen: HTTP " . ($response['status'] ?? '?'));
        }
    }

    public function deleteObject(string $key): void {
        try {
            $this->request('DELETE', $key);
        } catch (\RuntimeException $e) {
            // Bereits nicht (mehr) vorhanden gilt als Erfolg (idempotent),
            // analog zum S3-Verhalten bei DELETE eines fehlenden Objekts.
            if (!str_contains($e->getMessage(), 'HTTP 404')) {
                throw $e;
            }
        }
    }

    /**
     * @return array<int, array{key: string}>
     */
    public function listObjects(string $prefix = ''): array {
        $path = trim($prefix, '/');
        try {
            $responseBody = $this->request('PROPFIND', $path, '', ['Depth' => '1', 'Content-Type' => 'application/xml']);
        } catch (\RuntimeException $e) {
            // Zielordner existiert noch nicht (z. B. erster Lauf vor dem
            // ersten putObject()) -> einfach keine Objekte.
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return [];
            }
            throw $e;
        }

        $hrefs = self::parsePropfindHrefs($responseBody);
        $basePath = parse_url($this->baseUrl, PHP_URL_PATH) ?? '';

        $objects = [];
        foreach ($hrefs as $href) {
            $decoded = rawurldecode($href);
            if (!str_starts_with($decoded, $basePath)) {
                continue;
            }
            $relative = trim(substr($decoded, strlen($basePath)), '/');
            // PROPFIND mit Depth:1 liefert den angefragten Ordner selbst mit
            // - dessen Schlüssel-Rest entspricht genau dem angefragten $path.
            // Ob der Server dafür einen abschließenden Schrägstrich mitsendet
            // ist serverabhängig (nicht alle WebDAV-Implementierungen tun das
            // konsequent für den "self"-Eintrag), daher zusätzlich über den
            // exakten Pfadvergleich statt nur über str_ends_with() geprüft.
            // Echte Unterordner (nur bei Depth:1 sichtbar, hier nicht
            // benötigt) werden weiterhin über den Schrägstrich erkannt.
            if ($relative === '' || $relative === $path || str_ends_with($decoded, '/')) {
                continue;
            }
            $objects[] = ['key' => $relative];
        }

        sort($objects);
        return $objects;
    }

    /**
     * MKCOL für den Zielordner (und ggf. dessen übergeordnete Segmente, falls
     * $key selbst Schrägstriche enthält) - WebDAV-Server verlangen anders als
     * S3 ein tatsächlich existierendes Verzeichnis vor dem PUT. Ein bereits
     * bestehender Ordner beantwortet MKCOL mit 405 (Method Not Allowed), was
     * hier bewusst ignoriert wird.
     */
    private function ensureParentCollectionExists(string $key): void {
        $dir = trim((string)dirname($key), '/.');
        if ($dir === '') {
            return;
        }

        $segments = explode('/', $dir);
        $built = '';
        foreach ($segments as $segment) {
            $built = $built === '' ? $segment : "{$built}/{$segment}";
            try {
                $this->request('MKCOL', $built);
            } catch (\RuntimeException $e) {
                // 405 = Ordner existiert bereits, unbedenklich. Alles andere
                // (z. B. 401/403) soll den eigentlichen Upload-Versuch
                // fehlschlagen lassen, statt es hier zu verschlucken.
                if (!str_contains($e->getMessage(), 'HTTP 405')) {
                    throw $e;
                }
            }
        }
    }

    /**
     * @param array<string, string> $extraHeaders
     */
    private function request(string $method, string $path, string $body = '', array $extraHeaders = []): string {
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        $headers = array_merge([
            'Authorization' => 'Basic ' . base64_encode("{$this->username}:{$this->password}"),
        ], $extraHeaders);

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => 30,
                'protocol_version' => 1.1,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        $statusCode = self::extractStatusCode($http_response_header ?? []);

        if ($responseBody === false || $statusCode === null || $statusCode >= 300) {
            throw new \RuntimeException("WebDAV-Anfrage ({$method} {$path}) fehlgeschlagen: HTTP " . ($statusCode ?? '?'));
        }

        return (string)$responseBody;
    }

    /**
     * @param array<int, string> $headerLines
     */
    private static function extractStatusCode(array $headerLines): ?int {
        foreach ($headerLines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
                return (int)$matches[1];
            }
        }
        return null;
    }

    /**
     * Extrahiert alle `<D:href>`-Werte aus einer PROPFIND-Multistatus-Antwort,
     * namespace-unabhängig (Server nutzen unterschiedliche Präfixe wie `d:`,
     * `D:` oder `lp1:`) - deshalb über DOMDocument mit `getElementsByTagNameNS()`
     * gegen den WebDAV-Standard-Namespace `DAV:` statt eines Präfix-basierten
     * String-Vergleichs. Reine, netzwerkfreie Parsing-Logik, daher als eigene
     * statische Methode isoliert und direkt testbar (siehe
     * tests/Unit/Service/WebDavClientTest.php), analog zu
     * App\Service\S3Client::sign().
     *
     * @return array<int, string>
     */
    public static function parsePropfindHrefs(string $xml): array {
        if (trim($xml) === '') {
            return [];
        }

        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($xml);
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return [];
        }

        $hrefs = [];
        foreach ($doc->getElementsByTagNameNS('DAV:', 'href') as $node) {
            $value = trim($node->textContent);
            if ($value !== '') {
                $hrefs[] = $value;
            }
        }

        return $hrefs;
    }
}
