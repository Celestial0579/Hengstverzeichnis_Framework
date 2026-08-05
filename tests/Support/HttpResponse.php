<?php
// tests/Support/HttpResponse.php

namespace Tests\Support;

/**
 * Einfacher Werte-Container für eine HTTP-Antwort in den Funktionstests.
 */
class HttpResponse {

    public function __construct(
        public readonly int $statusCode,
        public readonly array $headers,
        public readonly string $body
    ) {}

    public function header(string $name): ?string {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }

    public function location(): ?string {
        return $this->header('Location');
    }

    /**
     * Extrahiert den Wert eines Formularfelds aus dem HTML-Body, z. B.
     * `<input type="hidden" name="csrf_token" value="abc123">`. Alle Views in
     * diesem Projekt schreiben Formularfelder konsistent im Muster
     * `name="FELD" ... value="WERT"` (siehe src/Views/*.php), daher reicht ein
     * einfacher, auf dieses Projekt zugeschnittener Regex ohne vollen HTML-Parser.
     */
    public function formField(string $name): ?string {
        $pattern = '/name="' . preg_quote($name, '/') . '"[^>]*?value="([^"]*)"/s';
        if (preg_match($pattern, $this->body, $matches)) {
            return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
        }
        return null;
    }
}
