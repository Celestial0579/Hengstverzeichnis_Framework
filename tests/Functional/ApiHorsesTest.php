<?php
// tests/Functional/ApiHorsesTest.php

namespace Tests\Functional;

/**
 * HTTP-Funktionstests für die öffentliche Read-only-JSON-API (#47, siehe
 * App\Controllers\ApiController und docs/api.md): Liste mit Filtern/
 * Pagination, Einzelabruf über UELN sowie dass gelöschte Pferde nie
 * ausgeliefert werden. Alle Abrufe laufen über einen echten, per
 * Selfservice-Route angelegten API-Schlüssel (siehe ApiKeyHelper) - die API
 * ist seit der Schlüsselpflicht nicht mehr anonym erreichbar. Die
 * Authentifizierung und das Rechtemodell selbst sind in ApiKeyAuthTest
 * abgedeckt.
 *
 * Der Test, der über /admin/horses/delete löscht und danach die API abfragt,
 * brachte in der vollständigen Functional-Suite reproduzierbar spätere,
 * inhaltlich unabhängige Requests in SetupAndAuthTest zum Timeout (#102). Das
 * lag nicht an dieser API, sondern an einem Bug im Test-Harness: der `php -S`-
 * Server (tests/Support/PhpBuiltInServer.php) schrieb seine Access-Logs in eine
 * Pipe, die niemand auslas, bis deren Kernel-Buffer volllief und der
 * Single-Worker-Server blockierte. Seit dieser Bug behoben ist (Ausgabe geht in
 * eine Logdatei), ist der Regressionstest unten wieder gefahrlos möglich.
 */
class ApiHorsesTest extends FunctionalTestCase {

    use ApiKeyHelper;

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
            'sex' => 'stallion',
            'breed' => 'Trakehner',
            'breeding_station' => 'API-Testgestüt',
            'birth_year' => '2018',
            'status' => 'active',
            // Öffentliche Sichtbarkeit (API/Katalog) hängt am Veröffentlicht-Flag,
            // nicht mehr am Status - ohne dieses Flag würde das Pferd nicht ausgeliefert.
            'is_published' => '1',
        ]);
        $this->assertSame('/admin/horses?success=created', $storeResponse->location());

        // 1. Liste ist mit gültigem Schlüssel abrufbar und enthält das neu angelegte Pferd.
        $token = $this->createApiKey($admin, 'ApiHorsesTest ' . $unique);
        $client = $this->newClient();
        $listResponse = $client->get('/api/horses?search=' . urlencode($horseName), $this->bearer($token));
        $this->assertSame(200, $listResponse->statusCode);
        $body = json_decode($listResponse->body, true);
        $this->assertIsArray($body);
        $this->assertSame(1, $body['meta']['total']);
        $this->assertSame($horseName, $body['data'][0]['name']);
        $this->assertSame($ueln, $body['data'][0]['ueln']);
        // Geschlecht und Rasse werden in der API ausgeliefert (#165/#163).
        $this->assertSame('stallion', $body['data'][0]['sex']);
        $this->assertSame('Trakehner', $body['data'][0]['breed']);
        $this->assertSame('/hengst?id=' . $body['data'][0]['id'], $body['data'][0]['profile_url']);

        // 2. Einzelabruf über UELN liefert dasselbe Pferd.
        $showResponse = $client->get('/api/horses/show?ueln=' . urlencode($ueln), $this->bearer($token));
        $this->assertSame(200, $showResponse->statusCode);
        $showBody = json_decode($showResponse->body, true);
        $this->assertSame($horseName, $showBody['data']['name']);

        // 3. Unbekannte UELN -> 404 mit Fehler-Payload.
        $notFound = $client->get('/api/horses/show?ueln=UNBEKANNT-' . uniqid(), $this->bearer($token));
        $this->assertSame(404, $notFound->statusCode);
        $notFoundBody = json_decode($notFound->body, true);
        $this->assertSame('not_found', $notFoundBody['error']);

        // 4. Fehlender Parameter -> 400.
        $missing = $client->get('/api/horses/show', $this->bearer($token));
        $this->assertSame(400, $missing->statusCode);

        // 5. Filter ohne Treffer liefert leere Liste, kein Fehler.
        $noHits = $client->get('/api/horses?search=' . urlencode('garantiert-kein-treffer-' . $unique), $this->bearer($token));
        $noHitsBody = json_decode($noHits->body, true);
        $this->assertSame(0, $noHitsBody['meta']['total']);
        $this->assertSame([], $noHitsBody['data']);
    }

    /**
     * Ein über den echten HTTP-Löschendpunkt gelöschtes Pferd darf über keinen
     * der beiden API-Endpunkte (Liste und Einzelabruf) mehr sichtbar sein.
     * Dieser Test hat #102 ausgelöst und dient nach dem Harness-Fix als
     * Regressionsabsicherung - siehe Klassenkommentar.
     */
    public function testDeletedHorseIsNeverExposed(): void {
        $admin = $this->authenticatedClient();

        $unique = uniqid();
        $horseName = "API Papierkorb Testpferd {$unique}";
        $ueln = 'DE' . substr($unique, -9) . 'DEL';

        $createForm = $admin->get('/admin/horses/create');
        $storeResponse = $admin->post('/admin/horses/store', [
            'csrf_token' => $createForm->formField('csrf_token') ?? '',
            'name' => $horseName,
            'ueln' => $ueln,
            'color' => 'Fuchs',
            'breeding_station' => 'API-Testgestüt',
            'birth_year' => '2019',
            'status' => 'active',
            'is_published' => '1',
        ]);
        $this->assertSame('/admin/horses?success=created', $storeResponse->location());

        // Vor dem Löschen ist das Pferd per UELN abrufbar - damit der spätere 404
        // das Löschen belegt und nicht einen Tippfehler in der UELN.
        $token = $this->createApiKey($admin, 'ApiHorsesDeleteTest ' . $unique);
        $beforeDelete = $this->newClient()->get('/api/horses/show?ueln=' . urlencode($ueln), $this->bearer($token));
        $this->assertSame(200, $beforeDelete->statusCode);

        // ID über die (an dieser Stelle bereits getestete) API-Suche ermitteln,
        // statt das HTML-Markup der Admin-Liste zu parsen.
        $lookup = $admin->get('/api/horses?search=' . urlencode($horseName), $this->bearer($token));
        $horseId = json_decode($lookup->body, true)['data'][0]['id'];

        $deleteResponse = $admin->post('/admin/horses/delete', [
            'csrf_token' => $createForm->formField('csrf_token') ?? '',
            'id' => (string)$horseId,
        ]);
        $this->assertSame('/admin/horses?success=deleted', $deleteResponse->location());

        $client = $this->newClient();
        $afterDelete = $client->get('/api/horses?search=' . urlencode($horseName), $this->bearer($token));
        $afterDeleteBody = json_decode($afterDelete->body, true);
        $this->assertSame(0, $afterDeleteBody['meta']['total'], 'Gelöschtes Pferd darf nie über die Listen-API sichtbar sein');

        // Auch der Einzelabruf per UELN darf das gelöschte Pferd nicht mehr
        // ausliefern - die "nie sichtbar"-Eigenschaft muss über beide
        // API-Endpunkte (Liste und Show) gelten.
        $afterDeleteShow = $client->get('/api/horses/show?ueln=' . urlencode($ueln), $this->bearer($token));
        $this->assertSame(404, $afterDeleteShow->statusCode, 'Gelöschtes Pferd darf nie über die Show-API sichtbar sein');
        $afterDeleteShowBody = json_decode($afterDeleteShow->body, true);
        $this->assertSame('not_found', $afterDeleteShowBody['error']);
    }
}
