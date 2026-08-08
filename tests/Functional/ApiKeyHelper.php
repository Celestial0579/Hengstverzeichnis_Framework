<?php
// tests/Functional/ApiKeyHelper.php

namespace Tests\Functional;

use Tests\Support\HttpClient;

/**
 * Gemeinsamer Helfer für Funktionstests, die einen API-Schlüssel über die
 * echte Selfservice-Route (`/api-keys`) anlegen und anschließend gegen die
 * JSON-API verwenden.
 *
 * Der Klartext-Schlüssel existiert bewusst nur ein einziges Mal - unmittelbar
 * nach dem Anlegen auf der Übersichtsseite (siehe ApiKeyController::index()).
 * Der Helfer holt ihn genau dort ab, statt ihn aus der Datenbank zu lesen:
 * so wird derselbe Weg getestet, den auch ein echter Benutzer geht.
 */
trait ApiKeyHelper {

    /**
     * Legt für den angemeldeten Benutzer des Clients einen Schlüssel an und
     * liefert dessen Klartextwert.
     *
     * @param array<int, string>|null $scope null = alle Rechte des Besitzers,
     *        sonst Liste von "modul.aktion"-Paaren.
     */
    private function createApiKey(HttpClient $client, string $label, ?array $scope = null): string {
        $page = $client->get('/api-keys');
        $this->assertSame(200, $page->statusCode, 'Die Schlüsselverwaltung sollte für angemeldete Benutzer erreichbar sein.');

        $fields = [
            'csrf_token' => $page->formField('csrf_token') ?? '',
            'label' => $label,
            'scope_mode' => $scope === null ? 'all' : 'custom',
        ];
        if ($scope !== null) {
            // http_build_query erzeugt aus 'scope' => [...] die von PHP
            // erwarteten scope[]-Felder.
            $fields['scope'] = $scope;
        }

        $createResponse = $client->post('/api-keys/create', $fields);
        $this->assertSame(
            '/api-keys?success=created',
            $createResponse->location(),
            "Anlegen des Schlüssels '{$label}' fehlgeschlagen: " . (string)$createResponse->location()
        );

        $overview = $client->get('/api-keys');
        preg_match('/hv_[0-9a-f]{64}/', $overview->body, $matches);
        $this->assertNotEmpty(
            $matches,
            'Der Klartext-Schlüssel sollte nach dem Anlegen genau einmal angezeigt werden.'
        );

        return $matches[0];
    }

    /**
     * @return array<string, string> Authorization-Header für HttpClient::get()
     */
    private function bearer(string $token): array {
        return ['Authorization' => 'Bearer ' . $token];
    }

    /**
     * Liest die ID eines aktiven Schlüssels anhand seiner Bezeichnung aus der
     * Übersicht - für den Widerruf über die echte Route.
     */
    private function findApiKeyIdByLabel(HttpClient $client, string $label): int {
        $page = $client->get('/api-keys');

        preg_match_all('/<tr[^>]*>((?:(?!<\/tr>).)*?)<\/tr>/s', $page->body, $rowMatches);
        foreach ($rowMatches[1] as $rowHtml) {
            if (!str_contains($rowHtml, htmlspecialchars($label))) {
                continue;
            }
            preg_match('/name="id" value="(\d+)"/', $rowHtml, $idMatch);
            $this->assertNotEmpty($idMatch, "Zeile für Schlüssel '{$label}' enthält keine ID.");
            return (int)$idMatch[1];
        }

        $this->fail("Konnte die ID des Schlüssels '{$label}' nicht ermitteln.");
    }
}
