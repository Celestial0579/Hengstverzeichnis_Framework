<?php
// src/Service/S3Client.php

namespace App\Service;

/**
 * Class S3Client
 *
 * Minimaler Client für S3-kompatiblen Objektspeicher (AWS S3, MinIO, Hetzner
 * Object Storage o. Ä.), passend zur "keine externen Abhängigkeiten"-
 * Philosophie des Kerns (siehe docs/architecture.md): signiert Anfragen
 * selbst mit AWS Signature Version 4, ohne AWS-SDK/Composer-Laufzeit-
 * Abhängigkeit. Nutzt wie App\Service\Mailer bewusst PHP-Streams statt der
 * curl-Extension (im mitgelieferten Dockerfile nicht installiert, auf
 * klassischem Webhosting nicht immer verfügbar) - `allow_url_fopen` reicht.
 *
 * Unterstützt Path-Style (`https://endpoint/bucket/key`, u. a. für MinIO
 * benötigt) und Virtual-Hosted-Style (`https://bucket.endpoint/key`, AWS-
 * Standard) URLs.
 */
final class S3Client {

    public function __construct(
        private readonly string $endpoint,
        private readonly string $region,
        private readonly string $bucket,
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly bool $pathStyle = false,
        // Nur für Tests gegen den lokalen Fake-S3-Server (siehe
        // tests/Integration/S3ClientTest.php) auf false gesetzt - jeder
        // echte S3-kompatible Speicher wird ausschließlich über HTTPS
        // angesprochen.
        private readonly bool $useHttps = true,
    ) {}

    /**
     * Lädt ein Objekt hoch (überschreibt ein ggf. bestehendes Objekt mit
     * demselben Schlüssel).
     */
    public function putObject(string $key, string $body, string $contentType = 'application/octet-stream'): void {
        $this->request('PUT', $key, $body, [], ['Content-Type' => $contentType]);
    }

    public function deleteObject(string $key): void {
        $this->request('DELETE', $key);
    }

    /**
     * Listet Objekte unter einem Schlüssel-Präfix (ListObjectsV2), z. B. für
     * die Aufbewahrungsrotation in App\Service\BackupService.
     *
     * @return array<int, array{key:string, lastModified:string}> Nach Schlüssel aufsteigend sortiert
     *         (ListObjectsV2 liefert UTF-8-binär sortierte Ergebnisse - bei
     *         gleich langen, ISO-8601-artigen Zeitstempel-Präfixen im
     *         Schlüssel entspricht das der chronologischen Reihenfolge).
     */
    public function listObjects(string $prefix = ''): array {
        $query = ['list-type' => '2'];
        if ($prefix !== '') {
            $query['prefix'] = $prefix;
        }

        $response = $this->request('GET', '', '', $query);
        return $this->parseListObjectsXml($response);
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $extraHeaders
     */
    private function request(string $method, string $key, string $body = '', array $query = [], array $extraHeaders = []): string {
        $host = $this->pathStyle ? $this->endpoint : "{$this->bucket}.{$this->endpoint}";
        $canonicalUri = $this->pathStyle
            ? '/' . $this->uriEncodePath($this->bucket) . ($key !== '' ? '/' . $this->uriEncodePath($key) : '')
            : ($key !== '' ? '/' . $this->uriEncodePath($key) : '/');

        $headers = array_merge(['Host' => $host], $extraHeaders);

        $signed = self::sign(
            $method,
            $canonicalUri,
            $query,
            $headers,
            $body,
            $this->region,
            $this->accessKey,
            $this->secretKey,
            gmdate('Ymd\THis\Z')
        );

        $requestHeaderLines = [];
        foreach ($signed['headers'] as $name => $value) {
            if (strtolower($name) === 'host') {
                continue; // wird vom Stream-Wrapper selbst aus der URL gesetzt
            }
            $requestHeaderLines[] = "{$name}: {$value}";
        }
        $requestHeaderLines[] = "Authorization: {$signed['authorizationHeader']}";

        $scheme = $this->useHttps ? 'https' : 'http';
        $url = "{$scheme}://{$host}{$canonicalUri}" . ($signed['canonicalQueryString'] !== '' ? "?{$signed['canonicalQueryString']}" : '');

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $requestHeaderLines),
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => 30,
                'protocol_version' => 1.1,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        $statusCode = $this->extractStatusCode($http_response_header ?? []);

        if ($responseBody === false || $statusCode === null || $statusCode >= 300) {
            $reason = $responseBody !== false ? $this->extractErrorMessage((string)$responseBody) : 'Verbindung fehlgeschlagen';
            throw new \RuntimeException("S3-Anfrage ({$method} {$canonicalUri}) fehlgeschlagen: HTTP " . ($statusCode ?? '?') . " - {$reason}");
        }

        return (string)$responseBody;
    }

    /**
     * Reine, zustandslose AWS-SigV4-Signaturberechnung (RFC 3986 URI-Encoding,
     * kanonische Anfrage, String-to-Sign, HMAC-Schlüsselableitung,
     * Signatur) - als eigenständige statische Methode ausgelagert, damit sie
     * unabhängig von einer echten Netzwerkanfrage getestet werden kann (siehe
     * tests/Unit/Service/S3ClientSignatureTest.php, das die Ausgabe gegen
     * eine unabhängige Python-Referenzimplementierung desselben öffentlich
     * dokumentierten Algorithmus prüft).
     *
     * @param array<string, string> $query
     * @param array<string, string> $headers Muss mindestens 'Host' enthalten; X-Amz-Content-Sha256
     *                                        und X-Amz-Date werden hier automatisch ergänzt.
     * @return array{
     *     headers: array<string, string>,
     *     canonicalQueryString: string,
     *     canonicalRequest: string,
     *     stringToSign: string,
     *     signature: string,
     *     authorizationHeader: string,
     * }
     */
    public static function sign(
        string $method,
        string $canonicalUri,
        array $query,
        array $headers,
        string $body,
        string $region,
        string $accessKey,
        string $secretKey,
        string $amzDate
    ): array {
        $dateStamp = substr($amzDate, 0, 8);
        $payloadHash = hash('sha256', $body);

        $headers = array_merge($headers, [
            'X-Amz-Content-Sha256' => $payloadHash,
            'X-Amz-Date' => $amzDate,
        ]);

        ksort($query);
        $canonicalQueryString = implode('&', array_map(
            fn($k, $v) => rawurlencode($k) . '=' . rawurlencode($v),
            array_keys($query),
            array_values($query)
        ));

        $signedHeaderNames = array_map('strtolower', array_keys($headers));
        sort($signedHeaderNames);
        $signedHeaders = implode(';', $signedHeaderNames);

        $lowerHeaders = [];
        foreach ($headers as $name => $value) {
            $lowerHeaders[strtolower($name)] = trim($value);
        }
        ksort($lowerHeaders);
        $canonicalHeaders = '';
        foreach ($lowerHeaders as $name => $value) {
            $canonicalHeaders .= "{$name}:{$value}\n";
        }

        $canonicalRequest = implode("\n", [
            $method,
            $canonicalUri,
            $canonicalQueryString,
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $credentialScope = "{$dateStamp}/{$region}/s3/aws4_request";
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $kDate = hash_hmac('sha256', $dateStamp, "AWS4{$secretKey}", true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $authorizationHeader = "AWS4-HMAC-SHA256 Credential={$accessKey}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        return [
            'headers' => $headers,
            'canonicalQueryString' => $canonicalQueryString,
            'canonicalRequest' => $canonicalRequest,
            'stringToSign' => $stringToSign,
            'signature' => $signature,
            'authorizationHeader' => $authorizationHeader,
        ];
    }

    /**
     * URI-Encoding je Pfadsegment nach AWS-SigV4-Vorgabe (RFC 3986,
     * unreservierte Zeichen unverändert, '/' bleibt zwischen den Segmenten
     * erhalten) - rawurlencode() allein würde '/' selbst kodieren.
     */
    private function uriEncodePath(string $path): string {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /**
     * @param array<int, string> $headerLines
     */
    private function extractStatusCode(array $headerLines): ?int {
        // Bei Redirects/Retries können mehrere Status-Zeilen enthalten sein - die letzte gewinnt.
        $statusCode = null;
        foreach ($headerLines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches)) {
                $statusCode = (int)$matches[1];
            }
        }
        return $statusCode;
    }

    private function extractErrorMessage(string $xmlBody): string {
        if ($xmlBody === '') {
            return '(keine Fehlerdetails)';
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlBody);
        libxml_use_internal_errors($previous);
        if ($xml !== false && isset($xml->Message)) {
            return (string)$xml->Message;
        }
        return substr($xmlBody, 0, 200);
    }

    /**
     * @return array<int, array{key:string, lastModified:string}>
     */
    private function parseListObjectsXml(string $xmlBody): array {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlBody);
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            return [];
        }

        $objects = [];
        foreach ($xml->Contents ?? [] as $content) {
            $objects[] = [
                'key' => (string)$content->Key,
                'lastModified' => (string)$content->LastModified,
            ];
        }

        usort($objects, fn($a, $b) => $a['key'] <=> $b['key']);
        return $objects;
    }
}
