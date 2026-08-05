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

    public function get(string $path): HttpResponse {
        return $this->request('GET', $path);
    }

    /**
     * @param array<string, string> $fields
     */
    public function post(string $path, array $fields): HttpResponse {
        return $this->request('POST', $path, http_build_query($fields));
    }

    private function request(string $method, string $path, ?string $body = null): HttpResponse {
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

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("HTTP-Request an {$path} fehlgeschlagen: {$error}");
        }

        $statusCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

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
