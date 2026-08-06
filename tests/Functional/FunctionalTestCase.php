<?php
// tests/Functional/FunctionalTestCase.php

namespace Tests\Functional;

use App\Security\Totp;
use PHPUnit\Framework\TestCase;
use Tests\Support\HttpClient;
use Tests\Support\PhpBuiltInServer;

/**
 * Basisklasse für HTTP-getriebene Funktionstests (siehe docs/development.md,
 * Abschnitt "Tests"). Startet einen php -S Subprozess und provisioniert die
 * App darüber genau einmal pro PHPUnit-Prozess (Setup-Wizard-Autoprovisionierung
 * per Umgebungsvariable, siehe SetupController::envAdminCredentials()) - alle
 * Testklassen, die von hier erben, teilen sich denselben Admin-Account.
 */
abstract class FunctionalTestCase extends TestCase {

    protected static ?string $adminEmail = null;
    protected static ?string $adminPassword = null;
    protected static ?string $totpSecret = null;

    public static function setUpBeforeClass(): void {
        PhpBuiltInServer::ensureStarted();
    }

    protected function newClient(): HttpClient {
        return new HttpClient(PhpBuiltInServer::baseUrl());
    }

    /**
     * Liefert ein für die laufende Sitzung gültiges CSRF-Token für Tests, deren
     * Zielseite das Token nur BEDINGT rendert (z. B. admin_plugins.php: das
     * Toggle-Formular samt csrf_token existiert nur pro gefundenem Plugin - in
     * einer frischen CI-Umgebung ohne jedes Plugin unter plugins/ gäbe es dort
     * gar kein Formularfeld zum Auslesen). Das Token ist pro Sitzung fest
     * (Router::generateCsrfToken() legt es einmalig in $_SESSION ab, siehe
     * dort), daher genügt irgendeine Seite mit einem UNBEDINGT gerenderten
     * Formular - hier /admin/users/create.
     */
    protected function currentCsrfToken(HttpClient $client): string {
        return $client->get('/admin/users/create')->formField('csrf_token') ?? '';
    }

    /**
     * Liefert einen frischen, aber bereits vollständig eingeloggten Client
     * (Passwort-Login + 2FA-Verifikation über den echten HTTP-Flow).
     */
    protected function authenticatedClient(): HttpClient {
        self::ensureProvisioned();
        $client = $this->newClient();

        $loginPage = $client->get('/login');
        $loginResponse = $client->post('/login', [
            'csrf_token' => $loginPage->formField('csrf_token') ?? '',
            'email' => self::$adminEmail,
            'password' => self::$adminPassword,
        ]);
        self::assertSame(
            '/login/2fa',
            $loginResponse->location(),
            "Login sollte zur 2FA-Verifikation weiterleiten, Body: {$loginResponse->body}"
        );

        $verifyPage = $client->get('/login/2fa');
        $verifyResponse = $client->post('/login/2fa', [
            'csrf_token' => $verifyPage->formField('csrf_token') ?? '',
            'totp_code' => Totp::getCode(self::$totpSecret),
        ]);
        self::assertSame(
            302,
            $verifyResponse->statusCode,
            "2FA-Verifikation beim Login sollte erfolgreich sein, Body: {$verifyResponse->body}"
        );

        return $client;
    }

    /**
     * Legt über eine admin-authentifizierte Sitzung einen neuen Editor-Benutzer
     * an (optional Mitglied eigener, nicht eingebauter Gruppen - siehe #66) und
     * durchläuft für diesen Benutzer den vollständigen Login-Flow (Passwort,
     * verpflichtendes 2FA-Setup, verpflichtender Passwortwechsel bei
     * Erstanmeldung). Admin hat serverseitig immer alle Rechte
     * (BaseController::hasPermission()), daher brauchen Tests der
     * Berechtigungsdurchsetzung zwingend eine echte Nicht-Admin-Sitzung.
     *
     * @param array<int, int> $customGroupIds
     */
    protected function createAndLoginEditor(
        HttpClient $adminClient,
        string $username,
        string $email,
        array $customGroupIds = []
    ): HttpClient {
        $password = 'EditorTest123!';

        $createForm = $adminClient->get('/admin/users/create');
        $createResponse = $adminClient->post('/admin/users/store', [
            'csrf_token' => $createForm->formField('csrf_token') ?? '',
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'groups' => array_map('strval', $customGroupIds),
        ]);
        self::assertSame(
            '/admin/users?success=created',
            $createResponse->location(),
            "Anlegen des Test-Editor-Benutzers fehlgeschlagen, Body: {$createResponse->body}"
        );

        $client = $this->newClient();

        $loginPage = $client->get('/login');
        $loginResponse = $client->post('/login', [
            'csrf_token' => $loginPage->formField('csrf_token') ?? '',
            'email' => $email,
            'password' => $password,
        ]);
        self::assertSame(
            '/2fa/setup',
            $loginResponse->location(),
            "Erstanmeldung des Test-Editors sollte zur 2FA-Einrichtung führen, Body: {$loginResponse->body}"
        );

        $setupPage = $client->get('/2fa/setup');
        $secret = $setupPage->formField('totp_secret');
        self::assertNotNull($secret, 'Konnte totp_secret nicht aus /2fa/setup extrahieren');

        $enableResponse = $client->post('/2fa/enable', [
            'csrf_token' => $setupPage->formField('csrf_token') ?? '',
            'totp_secret' => $secret,
            'backup_codes' => $setupPage->formField('backup_codes') ?? '[]',
            'confirm_backup' => '1',
            'totp_code' => Totp::getCode($secret),
        ]);
        self::assertSame(
            '/force-password-change',
            $enableResponse->location(),
            "2FA-Aktivierung des Test-Editors sollte zum verpflichtenden Passwortwechsel führen, Body: {$enableResponse->body}"
        );

        $forcePage = $client->get('/force-password-change');
        $newPassword = 'EditorTestNeu456!';
        $changeResponse = $client->post('/force-password-change', [
            'csrf_token' => $forcePage->formField('csrf_token') ?? '',
            'password' => $newPassword,
            'password_confirm' => $newPassword,
        ]);
        self::assertSame(
            '/admin?password_changed=1',
            $changeResponse->location(),
            "Verpflichtender Passwortwechsel des Test-Editors fehlgeschlagen, Body: {$changeResponse->body}"
        );

        return $client;
    }

    /**
     * Standardrechte der eingebauten Editor-Gruppe (siehe
     * Database::ensureSchemaUpToDate(), Editor-Defaults-Seeding) - nur
     * Benutzer, die EXPLIZIT dieser Gruppe zugewiesen wurden, erhalten sie
     * (BaseController::userGroupIds(), kein automatischer Standard). Tests der
     * Berechtigungsdurchsetzung über EIGENE Gruppen müssen diese Standardrechte
     * daher meiden (eigene Gruppe statt der eingebauten Editor-Gruppe nutzen),
     * sonst hätte der Testbenutzer die getestete Berechtigung ohnehin schon
     * unabhängig von der eigenen Gruppe - siehe setGroupPermissions().
     *
     * @var array<string, array<int, string>>
     */
    protected const EDITOR_DEFAULT_PERMISSIONS = [
        'horses' => ['view', 'create', 'edit', 'delete', 'publish'],
        'persons' => ['view', 'create', 'edit', 'delete', 'publish'],
        'breeding_stations' => ['view', 'create', 'edit', 'delete', 'publish'],
    ];

    /**
     * Ermittelt die ID einer eingebauten Gruppe (Administrator/Editor/Öffentlich)
     * über das "Gruppe zur Bearbeitung auswählen"-Dropdown in /admin/groups -
     * dieses listet immer ALLE Gruppen vollständig, unabhängig von Suche/Pagination
     * der Übersichtstabelle (siehe GroupController::index()).
     */
    protected function findBuiltinGroupId(HttpClient $admin, string $exactName): int {
        $page = $admin->get('/admin/groups');
        $pattern = '/<option value="(\d+)"[^>]*>\s*' . preg_quote($exactName, '/') . '\b/';
        preg_match($pattern, $page->body, $matches);
        self::assertNotEmpty($matches, "Konnte ID der eingebauten Gruppe '{$exactName}' nicht aus /admin/groups ermitteln");
        return (int)$matches[1];
    }

    /**
     * Ersetzt komplett die Berechtigungen einer (nicht geschützten) Gruppe über
     * den echten HTTP-Endpunkt POST /admin/groups/permissions.
     *
     * @param array<string, array<int, string>> $permissions Modul => [Aktion, ...]
     */
    protected function setGroupPermissions(HttpClient $admin, int $groupId, array $permissions): void {
        $editPage = $admin->get('/admin/groups?group=' . $groupId);
        $response = $admin->post('/admin/groups/permissions', [
            'csrf_token' => $editPage->formField('csrf_token') ?? '',
            'group_id' => (string)$groupId,
            'permissions' => $permissions,
        ]);
        self::assertSame(
            "/admin/groups?group={$groupId}&success=permissions_updated",
            $response->location(),
            "Setzen der Gruppenberechtigungen fehlgeschlagen, Body: {$response->body}"
        );
    }

    /**
     * Führt die vollautomatische Ersteinrichtung durch (GET /setup mit den
     * ADMIN_-, SITE_NAME- und DB_-Umgebungsvariablen, siehe
     * tests/Support/PhpBuiltInServer.php und
     * .github/workflows/tests.yml) genau einmal pro Prozess durch und schließt
     * die verpflichtende 2FA-Einrichtung ab.
     */
    private static function ensureProvisioned(): void {
        if (self::$adminEmail !== null) {
            return;
        }

        self::$adminEmail = getenv('ADMIN_EMAIL') ?: 'functional-test-admin@example.com';
        self::$adminPassword = getenv('ADMIN_PASSWORD') ?: 'FunctionalTest123!';

        $client = new HttpClient(PhpBuiltInServer::baseUrl());

        $setupResponse = $client->get('/setup');
        self::assertSame(
            '/2fa/setup',
            $setupResponse->location(),
            "Automatische Ersteinrichtung sollte zu /2fa/setup weiterleiten - Umgebungsvariablen " .
            "(ADMIN_EMAIL/ADMIN_USERNAME/ADMIN_PASSWORD/SITE_NAME/APP_KEY/DB_*) korrekt gesetzt? " .
            "Body: {$setupResponse->body}"
        );

        $setupPage = $client->get('/2fa/setup');
        $secret = $setupPage->formField('totp_secret');
        self::assertNotNull($secret, 'Konnte totp_secret nicht aus /2fa/setup extrahieren');
        self::$totpSecret = $secret;

        $enableResponse = $client->post('/2fa/enable', [
            'csrf_token' => $setupPage->formField('csrf_token') ?? '',
            'totp_secret' => $secret,
            'backup_codes' => $setupPage->formField('backup_codes') ?? '[]',
            'confirm_backup' => '1',
            'totp_code' => Totp::getCode($secret),
        ]);
        self::assertSame(
            302,
            $enableResponse->statusCode,
            "2FA-Aktivierung während der Ersteinrichtung fehlgeschlagen, Body: {$enableResponse->body}"
        );
    }
}
