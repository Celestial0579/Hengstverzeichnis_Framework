<?php
// tests/Support/HttpClient.php

namespace Tests\Support;

/**
 * Minimaler curl-basierter HTTP-Client für die Funktionstests (tests/Functional/).
 * Kein WebDriver/Headless-Browser nötig, da die App serverseitig gerendertes PHP
 * ohne clientseitige JS-Logik ist - ein Cookie-Jar für PHP-Sessions (Login, CSRF)
 * reicht aus, siehe docs/development.md, Abschnitt "Tests".
 */
class HttpClient {

    private string $cookieJar;

    public function __construct(private readonly string $baseUrl) {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'hengst_test_cookies_');
    }

    public function __destruct() {
        if (is_file($this->cookieJar)) {
            unlink($this->cookieJar);
        }
    }

    /**
     * @param array<string, string> $headers Zusätzliche Request-Header, z. B. für
     *                                        den Cron-Secret-Header (siehe CronTest.php).
     */
    public function get(string $path, array $headers = []): HttpResponse {
        return $this->request('GET', $path, null, $headers);
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, string> $headers Zusätzliche Request-Header, wie bei get().
     *        Gebraucht seit #353: Ob Passkeys angeboten werden, haengt am Host -
     *        ueber localhost gilt der Kontext als sicher, ueber einen fremden
     *        Namen ohne TLS nicht. Diese Ausnahme laesst sich nur pruefen, wenn
     *        der Test den Host-Kopf setzen kann.
     */
    public function post(string $path, array $fields, array $headers = []): HttpResponse {
        return $this->request('POST', $path, http_build_query($fields), $headers);
    }

    /**
     * POST als multipart/form-data mit Datei-Upload (z. B. für den CSV-Import,
     * siehe ImportController) - $fileFieldName entspricht dem $_FILES-Schlüssel,
     * $content wird für die Dauer des Requests in eine temporäre Datei
     * geschrieben (CURLFile benötigt einen echten Dateipfad).
     *
     * @param array<string, string> $fields
     */
    public function postFile(string $path, array $fields, string $fileFieldName, string $filename, string $content, string $mimeType = 'text/csv'): HttpResponse {
        $tmpFile = tempnam(sys_get_temp_dir(), 'hengst_test_upload_');
        file_put_contents($tmpFile, $content);
        try {
            $multipartFields = $fields;
            $multipartFields[$fileFieldName] = new \CURLFile($tmpFile, $mimeType, $filename);
            return $this->request('POST', $path, $multipartFields);
        } finally {
            unlink($tmpFile);
        }
    }

    /**
     * @param string|array<string, mixed>|null $body String für application/x-www-form-urlencoded,
     *   Array (ggf. mit \CURLFile-Werten) für multipart/form-data - curl wählt den
     *   Content-Type je nach Typ automatisch, siehe postFile().
     * @param array<string, string> $headers
     */
    private function request(string $method, string $path, string|array|null $body = null, array $headers = []): HttpResponse {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_TIMEOUT => 10,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if (!empty($headers)) {
            $formattedHeaders = [];
            foreach ($headers as $name => $value) {
                $formattedHeaders[] = "{$name}: {$value}";
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $formattedHeaders);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            throw new \RuntimeException("HTTP-Request an {$path} fehlgeschlagen: {$error}");
        }

        $statusCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        $rawHeaders = substr($raw, 0, $headerSize);
        $responseBody = substr($raw, $headerSize);

        $headers = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[trim($name)] = trim($value);
            }
        }

        return new HttpResponse($statusCode, $headers, $responseBody);
    }
}
