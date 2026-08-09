<?php
// tests/Functional/HorseRouteRedirectTest.php

namespace Tests\Functional;

/**
 * HTTP-Funktionstests für die Routen-Umstellung /hengst -> /horse (#171):
 * /horse ist die kanonische Detailseiten-Route, /hengst bleibt DAUERHAFT als
 * 301-Redirect bestehen (gedruckte QR-Codes und exportierte PDFs mit alten
 * URLs ziehen nie nach). Der Query-String muss die Weiterleitung unverändert
 * überleben - dispatch() verwirft ihn vor dem Routen-Match, der
 * Redirect-Helfer hängt ihn selbst wieder an (Router::redirect()).
 */
class HorseRouteRedirectTest extends FunctionalTestCase {

    public function testLegacyHengstRouteRedirectsPermanentlyWithQueryString(): void {
        $visitor = $this->newClient();

        // 1. Alte Route mit Query: 301 und Query-Passthrough.
        $response = $visitor->get('/hengst?id=12345');
        $this->assertSame(301, $response->statusCode, 'Alte /hengst-Route muss dauerhaft (301) weiterleiten');
        $this->assertSame('/horse?id=12345', $response->location(), 'Der Query-String muss die Weiterleitung überleben');

        // 2. Alte Route ohne Query: 301 ohne angehängtes '?'.
        $response = $visitor->get('/hengst');
        $this->assertSame(301, $response->statusCode);
        $this->assertSame('/horse', $response->location());

        // 3. Die neue Route existiert und verhält sich wie die alte:
        // unbekannte ID -> 404 (kein Redirect, keine Fehlerkaskade).
        $response = $visitor->get('/horse?id=99999999');
        $this->assertSame(404, $response->statusCode);

        // 4. Volle Kette: veröffentlichtes Pferd ist unter /horse erreichbar,
        // und der Redirect von /hengst führt exakt dorthin.
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $name = "Route Testpferd {$unique}";
        $form = $admin->get('/admin/horses/create');
        $store = $admin->post('/admin/horses/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $name,
            'status' => 'active',
            'is_published' => '1',
        ]);
        $this->assertSame('/admin/horses?success=created', $store->location());

        $stmt = \App\Database::getInstance()->prepare("SELECT id FROM horses WHERE name = ?");
        $stmt->execute([$name]);
        $horseId = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $horseId);

        $detail = $visitor->get("/horse?id={$horseId}");
        $this->assertSame(200, $detail->statusCode);
        $this->assertStringContainsString(htmlspecialchars($name), $detail->body);

        $legacy = $visitor->get("/hengst?id={$horseId}");
        $this->assertSame(301, $legacy->statusCode);
        $this->assertSame("/horse?id={$horseId}", $legacy->location());
    }
}
