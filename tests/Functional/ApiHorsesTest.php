<?php
// tests/Functional/ApiHorsesTest.php

namespace Tests\Functional;

/**
 * HTTP-Funktionstests für die öffentliche Read-only-JSON-API (#47, siehe
 * App\Controllers\ApiController und docs/api.md): Liste mit Filtern/
 * Pagination, Einzelabruf über UELN, sowie dass die API - wie der übrige
 * öffentliche Katalog - ohne Login erreichbar ist und gelöschte Pferde nie
 * ausliefert.
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

    public function testDeletedHorseIsNeverExposed(): void {
        $admin = $this->authenticatedClient();

        $unique = uniqid();
        $horseName = "API Papierkorb Testpferd {$unique}";

        $createForm = $admin->get('/admin/horses/create');
        $storeResponse = $admin->post('/admin/horses/store', [
            'csrf_token' => $createForm->formField('csrf_token') ?? '',
            'name' => $horseName,
            'color' => 'Fuchs',
            'breeding_station' => 'API-Testgestüt',
            'birth_year' => '2019',
            'status' => 'active',
        ]);
        $this->assertSame('/admin/horses?success=created', $storeResponse->location());

        // ID über die (an dieser Stelle bereits getestete) API-Suche ermitteln,
        // statt das HTML-Markup der Admin-Liste zu parsen.
        $lookup = $admin->get('/api/horses?search=' . urlencode($horseName));
        $horseId = json_decode($lookup->body, true)['data'][0]['id'];

        $deleteResponse = $admin->post('/admin/horses/delete', [
            'csrf_token' => $createForm->formField('csrf_token') ?? '',
            'id' => (string)$horseId,
        ]);
        $this->assertSame('/admin/horses?success=deleted', $deleteResponse->location());

        $client = $this->newClient();
        $afterDelete = $client->get('/api/horses?search=' . urlencode($horseName));
        $afterDeleteBody = json_decode($afterDelete->body, true);
        $this->assertSame(0, $afterDeleteBody['meta']['total'], 'Gelöschtes Pferd darf nie über die API sichtbar sein');
    }
}
