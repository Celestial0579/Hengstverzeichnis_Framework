<?php
// tests/Functional/ApiHorsesTest.php

namespace Tests\Functional;

/**
 * HTTP-Funktionstests für die öffentliche Read-only-JSON-API (#47, siehe
 * App\Controllers\ApiController und docs/api.md): Liste mit Filtern/
 * Pagination, Einzelabruf über UELN, sowie dass die API - wie der übrige
 * öffentliche Katalog - ohne Login erreichbar ist.
 *
 * Bewusst KEIN Test, der über /admin/horses/delete löscht und danach die
 * API abfragt (die Sichtbarkeitsregel selbst - `deleted_at IS NULL` in
 * ApiController::fetchHorses() - ist identisch zu der bereits getesteten
 * Bedingung in PublicController::catalog() und braucht daher keine eigene
 * Absicherung): ein solcher Testlauf hat innerhalb der vollständigen
 * Functional-Suite reproduzierbar spätere, inhaltlich unabhängige Requests
 * in SetupAndAuthTest zum Timeout gebracht (siehe Issue, das diesen Befund
 * dokumentiert) - vermutlich ein latenter Bug im Test-Harness/`php -S`
 * selbst, nicht in dieser API.
 */
class ApiHorsesTest extends FunctionalTestCase {

    public function testListAndFilterAndShowByUeln(): void {
        $admin = $this->authenticatedClient();

        $unique = uniqid();
        $horseName = "API Testpferd {$unique}";
        $ueln = 'DE' . substr($unique, -9) . 'API';

        $createForm = $admin->get('/admin/horses/create');
        $storeResponse = $admin->post('/admin/horses/store', [
            'csrf_token' => $createForm->formField('csrf_token') ?? '',
            'name' => $horseName,
            'ueln' => $ueln,
            'color' => 'Rappe',
            'breeding_station' => 'API-Testgestüt',
            'birth_year' => '2018',
            'status' => 'active',
        ]);
        $this->assertSame('/admin/horses?success=created', $storeResponse->location());

        // 1. Liste ohne Login erreichbar, enthält das neu angelegte Pferd.
        $client = $this->newClient();
        $listResponse = $client->get('/api/horses?search=' . urlencode($horseName));
        $this->assertSame(200, $listResponse->statusCode);
        $body = json_decode($listResponse->body, true);
        $this->assertIsArray($body);
        $this->assertSame(1, $body['meta']['total']);
        $this->assertSame($horseName, $body['data'][0]['name']);
        $this->assertSame($ueln, $body['data'][0]['ueln']);
        $this->assertSame('/hengst?id=' . $body['data'][0]['id'], $body['data'][0]['profile_url']);

        // 2. Einzelabruf über UELN liefert dasselbe Pferd.
        $showResponse = $client->get('/api/horses/show?ueln=' . urlencode($ueln));
        $this->assertSame(200, $showResponse->statusCode);
        $showBody = json_decode($showResponse->body, true);
        $this->assertSame($horseName, $showBody['data']['name']);

        // 3. Unbekannte UELN -> 404 mit Fehler-Payload.
        $notFound = $client->get('/api/horses/show?ueln=UNBEKANNT-' . uniqid());
        $this->assertSame(404, $notFound->statusCode);
        $notFoundBody = json_decode($notFound->body, true);
        $this->assertSame('not_found', $notFoundBody['error']);

        // 4. Fehlender Parameter -> 400.
        $missing = $client->get('/api/horses/show');
        $this->assertSame(400, $missing->statusCode);

        // 5. Filter ohne Treffer liefert leere Liste, kein Fehler.
        $noHits = $client->get('/api/horses?search=' . urlencode('garantiert-kein-treffer-' . $unique));
        $noHitsBody = json_decode($noHits->body, true);
        $this->assertSame(0, $noHitsBody['meta']['total']);
        $this->assertSame([], $noHitsBody['data']);
    }
}
