<?php
// tests/Functional/CronTest.php

namespace Tests\Functional;

/**
 * HTTP-Funktionstests für die Cron-/Scheduler-Infrastruktur (#67, siehe
 * App\Service\Scheduler, App\Controllers\CronController und die Admin-
 * Verwaltung unter /admin/cron): Admin-Pflicht/CSRF-Schutz der Verwaltung
 * sowie das Secret-basierte Schutzschema des öffentlichen Auslöse-Endpunkts
 * /cron/run.
 *
 * Seit #358 IST eine konkrete Aufgabe registriert (users.deactivate_dormant,
 * täglich). Die Zusicherung lautet deshalb nicht mehr "die ran-Liste ist
 * leer", sondern "sie enthält höchstens die bekannten Aufgaben" - eine
 * unbekannte Aufgabe im Ergebnis wäre ein Fund. Ein starres assertSame([])
 * hätte hier bei jeder neuen Cron-Aufgabe rot geschlagen, ohne dass etwas
 * kaputt gewesen wäre.
 */
class CronTest extends FunctionalTestCase {

    /** Aufgaben, die der Kern registriert und die deshalb laufen dürfen. */
    private const BEKANNTE_AUFGABEN = [
        'backup.external',
        'digest.admin_editor',
        'update.check',
        'users.deactivate_dormant',
    ];

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
        // Die Eintraege sind Objekte (name/status), keine blossen Namen -
        // ein array_diff darauf ergibt "Array to string conversion" und
        // vergleicht Unsinn.
        $namen = array_map(static fn(array $e): string => (string)$e['name'], $payload['ran']);
        $this->assertSame(
            [],
            array_values(array_diff($namen, self::BEKANNTE_AUFGABEN)),
            'Der Lauf hat eine Aufgabe ausgeführt, die dieser Test nicht kennt.'
        );
        foreach ($payload['ran'] as $eintrag) {
            $this->assertSame('ok', $eintrag['status'], "Aufgabe {$eintrag['name']} ist nicht sauber durchgelaufen.");
        }

        // Der frühere Query-Parameter-Weg (?token=) wird aus Sicherheitsgründen
        // nicht mehr akzeptiert - Secrets im Query-String landen in Access-Logs
        // (Issue #114).
        $queryParamResponse = $client->get('/cron/run?token=' . urlencode($secret));
        $this->assertSame(403, $queryParamResponse->statusCode);
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
        // Wie viele Aufgaben tatsächlich fällig waren, hängt davon ab, was in
        // diesem Prozess vorher schon lief - festgenagelt wird der Weg, nicht
        // die Zahl.
        $this->assertMatchesRegularExpression(
            '#^/admin/cron\?success=run_now&ran=\d+$#',
            (string)$response->location()
        );
    }
}
