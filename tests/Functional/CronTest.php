<?php
// tests/Functional/CronTest.php

namespace Tests\Functional;

/**
 * HTTP-Funktionstests für die Cron-/Scheduler-Infrastruktur (#67, siehe
 * App\Service\Scheduler, App\Controllers\CronController und die Admin-
 * Verwaltung unter /admin/cron): Admin-Pflicht/CSRF-Schutz der Verwaltung
 * sowie das Secret-basierte Schutzschema des öffentlichen Auslöse-Endpunkts
 * /cron/run. Es sind bewusst keine konkreten Aufgaben registriert (siehe
 * Scheduler-Klassendoc) - dieser Test deckt daher nur die Infrastruktur
 * selbst ab (leere "ran"-Liste bei gültigem Secret), nicht das Ausführen
 * einer echten Aufgabe.
 */
class CronTest extends FunctionalTestCase {

    public function testCronSettingsPageRequiresAdmin(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $editor = $this->createAndLoginEditor($admin, "crontester{$unique}", "cron-test-{$unique}@example.com");

        $response = $editor->get('/admin/cron');
        $this->assertSame(403, $response->statusCode);
    }

    public function testCronSettingsPageIsReachableForAdmin(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->get('/admin/cron');
        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('Automatisierung (Cron)', $response->body);
    }

    public function testRegenerateCronSecretRequiresCsrfToken(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->post('/admin/cron/regenerate-secret', []);
        $this->assertSame(403, $response->statusCode);
    }

    /**
     * Muss vor testRegeneratingSecretThenTriggeringCronRunSucceeds() laufen (PHPUnit-
     * Standardreihenfolge: Deklarationsreihenfolge innerhalb der Klasse) - die
     * Funktionstests teilen sich eine einzige, über den gesamten Prozess laufende
     * Datenbank (siehe FunctionalTestCase-Klassendoc), das gesetzte Cron-Secret
     * bleibt daher über Testmethoden hinweg bestehen.
     */
    public function testCronRunEndpointRejectsMissingSecretBeforeConfiguration(): void {
        $client = $this->newClient();

        $response = $client->get('/cron/run');
        $this->assertSame(503, $response->statusCode);
    }

    public function testRegeneratingSecretThenTriggeringCronRunSucceeds(): void {
        $admin = $this->authenticatedClient();

        $regenerateResponse = $admin->post('/admin/cron/regenerate-secret', [
            'csrf_token' => $this->currentCsrfToken($admin),
        ]);
        $this->assertSame('/admin/cron?success=secret_regenerated', $regenerateResponse->location());

        $settingsPage = $admin->get('/admin/cron');
        $this->assertMatchesRegularExpression('/[0-9a-f]{64}/', $settingsPage->body, 'Erzeugtes Cron-Secret sollte auf der Verwaltungsseite angezeigt werden');
        preg_match('/([0-9a-f]{64})/', $settingsPage->body, $matches);
        $secret = $matches[1];

        $client = $this->newClient();

        $wrongSecretResponse = $client->get('/cron/run', ['X-Cron-Secret' => 'falsches-secret']);
        $this->assertSame(403, $wrongSecretResponse->statusCode);

        $correctSecretResponse = $client->get('/cron/run', ['X-Cron-Secret' => $secret]);
        $this->assertSame(200, $correctSecretResponse->statusCode);
        $payload = json_decode($correctSecretResponse->body, true);
        $this->assertIsArray($payload);
        $this->assertSame([], $payload['ran']);

        // Alternativer Query-Parameter-Weg (siehe CronController::run()) funktioniert ebenfalls.
        $queryParamResponse = $client->get('/cron/run?token=' . urlencode($secret));
        $this->assertSame(200, $queryParamResponse->statusCode);
    }

    public function testRunCronNowRequiresCsrfToken(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->post('/admin/cron/run-now', []);
        $this->assertSame(403, $response->statusCode);
    }

    public function testRunCronNowTriggersManualRunForAdmin(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->post('/admin/cron/run-now', [
            'csrf_token' => $this->currentCsrfToken($admin),
        ]);
        $this->assertSame('/admin/cron?success=run_now&ran=0', $response->location());
    }
}
