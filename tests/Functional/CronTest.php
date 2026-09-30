<?php
// tests/Functional/CronTest.php

namespace Tests\Functional;

use App\Database;

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
        'users.purge_unverified',
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
        // Audit M38: Aufgaben, die gerade ein anderer Cron-Aufruf ausführt,
        // stehen als Namensliste unter 'skipped' - nie unter 'ran'.
        $this->assertArrayHasKey('skipped', $payload);
        $this->assertIsArray($payload['skipped']);

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
            '#^/admin/cron\?success=run_now&ran=\d+(&skipped=\d+)?$#',
            (string)$response->location()
        );
    }

    /**
     * Audit M39: Ein Fatal Error in einer Aufgabe beendet den Request. Früher
     * stand die Aufgabe danach bei jedem Aufruf wieder vorn, stürzte wieder
     * ab, und alle Aufgaben dahinter liefen nie mehr. Jetzt ist sie schon
     * beim Start als gelaufen vermerkt, der Abbruch steht im Status und im
     * Audit-Log, und der nächste Aufruf führt die übrigen aus.
     *
     * Der HTTP-Status des ersten Aufrufs wird bewusst nicht geprüft: Der
     * Testserver läuft mit APP_ENV=development (display_errors=1); PHP gibt
     * die Fehlermeldung aus und sendet dabei die Header, der Status kann dann
     * 200 sein. In Produktion bleibt es bei 500.
     */
    public function testAbgestuerzteAufgabeWirdVermerktUndHaeltAndereNichtAuf(): void {
        $admin = $this->authenticatedClient();
        $db = Database::getInstance();
        $this->installCrashPlugin();

        $toggle = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::CRASH_SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggle->location(), "Aktivieren fehlgeschlagen: {$toggle->body}");

        try {
            $admin->post('/admin/cron/regenerate-secret', ['csrf_token' => $this->currentCsrfToken($admin)]);
            $secret = (string)$db->query("SELECT setting_value FROM settings WHERE setting_key = 'cron_secret'")->fetchColumn();
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);

            $client = $this->newClient();

            // 1) Absturz.
            $erster = $client->get('/cron/run', ['X-Cron-Secret' => $secret]);
            $json = json_decode($erster->body, true);
            $this->assertFalse(is_array($json) && isset($json['ran']), "Der Lauf hätte abstürzen sollen: {$erster->body}");

            $this->assertNotFalse(self::setting('cron_last_run__cron-absturz.faellt'), 'Die Aufgabe muss schon beim Start als gelaufen vermerkt sein');
            $status = json_decode((string)self::setting('cron_status__cron-absturz.faellt'), true);
            $this->assertIsArray($status);
            $this->assertSame('aborted', $status['status']);
            $this->assertStringContainsString('Allowed memory size', (string)$status['error']);

            $audit = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = ?");
            $audit->execute(['Cron-Aufgabe abgebrochen: cron-absturz.faellt']);
            $this->assertSame(1, (int)$audit->fetchColumn());

            // 2) Der nächste Aufruf führt die Aufgabe dahinter aus, die
            //    abgestürzte nicht erneut.
            $zweiter = $client->get('/cron/run', ['X-Cron-Secret' => $secret]);
            $this->assertSame(200, $zweiter->statusCode, $zweiter->body);
            $payload = json_decode($zweiter->body, true);
            $ergebnisse = array_column($payload['ran'], 'status', 'name');
            $this->assertSame('ok', $ergebnisse['cron-absturz.danach'] ?? null);
            $this->assertArrayNotHasKey('cron-absturz.faellt', $ergebnisse);

            // 3) Die Verwaltungsseite zeigt den Abbruch.
            $seite = $admin->get('/admin/cron');
            $this->assertSame(200, $seite->statusCode);
            $this->assertStringContainsString('abgebrochen', $seite->body);
            $this->assertStringContainsString('Zuletzt gestartet', $seite->body);
        } finally {
            $admin->post('/admin/plugins/toggle', [
                'csrf_token' => $this->currentCsrfToken($admin),
                'slug' => self::CRASH_SLUG,
                'enable' => '0',
            ]);
        }
    }

    private const CRASH_SLUG = 'cron-absturz-test';
    private const CRASH_DEST = __DIR__ . '/../../plugins/cron-absturz-test';

    protected function tearDown(): void {
        foreach (glob(self::CRASH_DEST . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir(self::CRASH_DEST);
        try {
            $db = Database::getInstance();
            $db->prepare("DELETE FROM plugins WHERE slug = ?")->execute([self::CRASH_SLUG]);
            $db->exec("DELETE FROM settings WHERE setting_key LIKE 'cron\\_last\\_run\\_\\_cron-absturz.%' OR setting_key LIKE 'cron\\_status\\_\\_cron-absturz.%'");
        } catch (\Throwable $e) {
            // DB weg = nichts zu bereinigen
        }
        parent::tearDown();
    }

    private static function setting(string $key): string|false {
        $stmt = Database::getInstance()->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        return $stmt->fetchColumn();
    }

    private function installCrashPlugin(): void {
        @mkdir(self::CRASH_DEST, 0755, true);
        file_put_contents(self::CRASH_DEST . '/plugin.json', json_encode([
            'slug' => self::CRASH_SLUG,
            'name' => 'Cron-Absturz-Test',
            'version' => '1.0.0',
            'core_compatibility' => '>=0.1.0-beta.1',
            'core_supported_max' => '9.9',
            'description' => 'Registriert eine Cron-Aufgabe, die mit einem Fatal Error abstürzt, und eine dahinter.',
            'author' => 'Tests',
            'hooks' => [],
            'entry' => 'Plugin.php',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        file_put_contents(self::CRASH_DEST . '/Plugin.php', <<<'PHP'
<?php
namespace Plugin\CronAbsturzTest;

use App\Service\Scheduler;

class Plugin {

    public function register($hooks): void {
        Scheduler::register('cron-absturz.faellt', 3600, static function (): void {
            // Relativ zur aktuellen Belegung: Ein fester Wert wie 32M
            // scheitert, wenn schon mehr belegt ist - dann gäbe es gar
            // keinen Fatal.
            ini_set('memory_limit', (string)(memory_get_usage(true) + 4 * 1024 * 1024));
            $x = str_repeat('x', 32 * 1024 * 1024);
            echo strlen($x);
        });
        Scheduler::register('cron-absturz.danach', 3600, static function (): void {
        });
    }
}
PHP);
    }
}
