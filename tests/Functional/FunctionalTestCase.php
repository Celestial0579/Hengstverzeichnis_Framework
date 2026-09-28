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

    /**
     * TOTP-Secret des zuletzt über createAndLoginEditor() angelegten Editors -
     * für Tests, die anschließend eine Step-up-Reauth (#112) mit dem aktuellen
     * Code dieses Editors durchführen müssen.
     */
    protected ?string $lastEditorTotpSecret = null;

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
        return $this->csrfTokenFrom($client, '/admin/users/create');
    }

    /**
     * Token von einer AUSDRÜCKLICH genannten Seite - und ein harter Abbruch,
     * wenn dort keins steht.
     *
     * WARUM DER ABBRUCH. currentCsrfToken() lieferte früher stillschweigend
     * einen leeren String, wenn die Seite kein Formular hergab. Für einen
     * Admin passiert das nie; für jeden anderen Benutzer schon, denn
     * /admin/users/create verlangt das Recht `users`. Ein Redakteur bekommt
     * dort 403 - und sein anschliessender POST scheitert dann am CSRF-Check,
     * der VOR jeder Rechteprüfung steht.
     *
     * Die Folge ist ein Test, der aus dem falschen Grund grün ist: Er
     * behauptet "die Rechteprüfung greift" und hat sie nie erreicht. Entfernt
     * jemand die Rechteprüfung im Controller, bleibt er trotzdem grün. Genau
     * das ist in dieser Suite mehrfach passiert und nur in einer Gegenprobe
     * aufgefallen.
     *
     * Deshalb bricht das Holen jetzt ab, statt eine leere Zeichenkette
     * weiterzureichen. Wer einen Nicht-Admin testet, nennt eine Seite, die
     * dieser Benutzer auch sehen darf - siehe editorCsrfToken().
     */
    protected function csrfTokenFrom(HttpClient $client, string $pfad): string {
        $seite = $client->get($pfad);
        $token = $seite->formField('csrf_token') ?? '';
        if ($token === '') {
            self::fail(sprintf(
                "Kein CSRF-Token auf %s (HTTP %d).\n"
                . "Diese Sitzung darf die Seite offenbar nicht sehen. Ein leeres Token würde den "
                . "folgenden POST am CSRF-Check scheitern lassen - der Test wäre grün, ohne die "
                . "eigentliche Prüfung je erreicht zu haben. Hole das Token von einer Seite, die "
                . "dieser Benutzer sehen darf (für Redakteure: editorCsrfToken()).",
                $pfad,
                $seite->statusCode
            ));
        }
        return $token;
    }

    /**
     * Token für einen Benutzer OHNE Verwaltungsrechte an Konten.
     *
     * Probiert der Reihe nach Seiten durch, die ein Redakteur mit den
     * üblichen Rechten sehen kann. Findet keine davon ein Formular, bricht es
     * ab statt leer zurückzukommen - dieselbe Begründung wie bei
     * csrfTokenFrom().
     */
    protected function editorCsrfToken(HttpClient $client): string {
        // Das Dashboard ist die richtige Quelle: Es rendert ein Formular mit
        // Token und steht JEDER angemeldeten Sitzung offen -
        // AdminController::dashboard() prüft nur checkAuth(), keine
        // Einzelberechtigung. Damit funktioniert es auch für einen
        // Testbenutzer ganz ohne Gruppen, und die Tests müssen ihren
        // Rechte-Zuschnitt nicht verbiegen, nur um an ein Token zu kommen.
        return $this->csrfTokenFrom($client, '/admin');
    }

    /**
     * Setzt den TOTP-Replay-Schutz (#111, users.last_totp_timeslice) für einen
     * Benutzer zurück. Nötig, weil die Functional-Suite denselben Admin-Account
     * bewusst viele Male pro 30-Sekunden-Fenster einloggt und dabei denselben
     * TOTP-Code wiederverwendet - in Produktion ist genau das verboten
     * (single-use pro Zeitschlitz, siehe Totp::verifyCodeReturnSlice()); der
     * Replay-Schutz selbst wird separat in TotpReplayTest über den echten
     * HTTP-Flow abgesichert. Direkter DB-Zugriff aus dem PHPUnit-Prozess,
     * analog zu tests/Integration (DB_*-Konstanten, siehe tests/bootstrap.php).
     */
    /**
     * Extrahiert das serverseitig erzeugte TOTP-Secret aus der /2fa/setup-Seite
     * (#112: Secret/Backup-Codes sind Server-State in der Session und werden
     * nicht mehr als Formularfelder zurückgeschickt - die Seite zeigt das
     * Secret aber weiterhin zur manuellen Eingabe in die Authentikator-App an).
     */
    protected static function extractTotpSecret(\Tests\Support\HttpResponse $setupPage): ?string {
        preg_match('/Geheimer Schlüssel:\s*<strong>([A-Z2-7]+)<\/strong>/u', $setupPage->body, $matches);
        return $matches[1] ?? null;
    }

    protected static function resetTotpReplayGuard(string $email): void {
        $db = \App\Database::getInstance();
        $stmt = $db->prepare("UPDATE users SET last_totp_timeslice = NULL WHERE email = ?");
        $stmt->execute([$email]);
    }

    /**
     * Konto-ID zu einem Benutzernamen, direkt aus der Datenbank.
     *
     * NICHT `userIdVon()` nennen: EmailSecondFactorLoginTest hat eine private
     * Methode dieses Namens, und eine private Methode in der Unterklasse neben
     * einer protected gleichen Namens hier bricht die ganze Suite mit einem
     * Fatal Error ab ("Access level ... must be protected").
     */
    protected static function kontoIdNachName(string $username): int {
        $stmt = \App\Database::getInstance()->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $id = (int)$stmt->fetchColumn();
        self::assertGreaterThan(0, $id, "Konto '{$username}' nicht gefunden.");
        return $id;
    }

    /**
     * Legt einen Passkey-Datensatz direkt in der Datenbank an.
     *
     * Eine echte Registrierung braucht einen Authenticator (siehe
     * tests/Integration/PasskeysTest.php). Für die Schranken, die an der
     * bloßen EXISTENZ eines Passkeys hängen - Faktorweiche, Step-up-Regel,
     * Admin-Reset -, genügt der Datensatz; anmelden lässt sich damit nicht.
     */
    protected static function legeTestPasskeyAn(int $userId, string $label = 'Test-Passkey'): int {
        $db = \App\Database::getInstance();
        $db->prepare(
            "INSERT INTO user_passkeys (user_id, credential_id, credential, label, sign_count, created_at)
             VALUES (?, ?, ?, ?, 0, NOW())"
        )->execute([$userId, base64_encode('FT-TEST-' . bin2hex(random_bytes(12))), '{"test":true}', $label]);
        return (int)$db->lastInsertId();
    }

    /**
     * Holt für eine angemeldete Sitzung mit TOTP die frische Bestätigung
     * (App\Security\StepUp) über den echten Weg: GET und POST /2fa/reauth.
     *
     * Der Step-up verbraucht den TOTP-Zeitschlitz (#111). Der Replay-Schutz
     * wird deshalb vorher UND nachher zurückgesetzt - sonst scheitert der
     * nächste Code desselben 30-Sekunden-Fensters im selben Test.
     */
    protected function stepUpMitTotp(HttpClient $client, string $email, string $passwort, string $secret, string $fuer = 'profil'): void {
        self::resetTotpReplayGuard($email);
        $seite = $client->get('/2fa/reauth?fuer=' . urlencode($fuer));
        self::assertSame(200, $seite->statusCode, "Die Bestätigungsseite muss erreichbar sein, Body: {$seite->body}");

        $antwort = $client->post('/2fa/reauth', [
            'csrf_token' => $seite->formField('csrf_token') ?? '',
            'password' => $passwort,
            'totp_code' => Totp::getCode($secret),
            'fuer' => $fuer,
        ]);
        self::assertSame(
            \App\Security\StepUp::ziel($fuer),
            $antwort->location(),
            "Step-up mit TOTP fehlgeschlagen, Body: {$antwort->body}"
        );
        self::resetTotpReplayGuard($email);
    }

    /**
     * Ersetzt den Abdruck eines von der Anwendung ausgestellten Mailcodes
     * durch den eines bekannten Codes - im Testlauf geht keine Mail hinaus.
     * Bricht ab, wenn die Anwendung gar keinen ausgestellt hat.
     */
    protected static function bekanntenMailcodeSetzen(int $userId, string $purpose, string $code): void {
        $db = \App\Database::getInstance();
        $stmt = $db->prepare(
            "UPDATE email_2fa_codes SET code_hash = ?, attempts = 0, expires_at = NOW() + INTERVAL 10 MINUTE
             WHERE user_id = ? AND purpose = ?"
        );
        $stmt->execute([password_hash($code, PASSWORD_DEFAULT), $userId, $purpose]);
        self::assertSame(1, $stmt->rowCount(), "Die Anwendung hätte einen Code für '{$purpose}' ausstellen müssen.");
    }

    /**
     * Legt ein Konto in einer Gruppe OHNE 2FA-Pflicht an, meldet es an und
     * erledigt den Passwortwechsel der Erstanmeldung. Das Konto hat danach
     * keinen zweiten Faktor.
     *
     * @return array{client: HttpClient, username: string, email: string, passwort: string, id: int}
     */
    protected function angemeldetOhneFaktor(HttpClient $admin, string $prefix, string $email = ''): array {
        $unique = uniqid();
        $groupId = $this->createGroupWithoutTwoFa($admin, "Ohne Faktor {$prefix} {$unique}");
        $username = $prefix . $unique;
        $email = $email !== '' ? $email : "{$prefix}-{$unique}@example.com";
        $erst = 'OhneFaktorTest123!';
        $passwort = 'OhneFaktorNeu456!';

        $createForm = $admin->get('/admin/users/create');
        $angelegt = $admin->post('/admin/users/store', [
            'csrf_token' => $createForm->formField('csrf_token') ?? '',
            'username' => $username,
            'email' => $email,
            'password' => $erst,
            'groups' => [(string)$groupId],
        ]);
        self::assertSame('/admin/users?success=created', $angelegt->location(), "Anlegen fehlgeschlagen, Body: {$angelegt->body}");

        $client = $this->newClient();
        $login = $client->post('/login', [
            'csrf_token' => $client->get('/login')->formField('csrf_token') ?? '',
            'kennung' => $username,
            'password' => $erst,
        ]);
        self::assertSame('/force-password-change', $login->location(), "Erstanmeldung fehlgeschlagen, Body: {$login->body}");

        $gewechselt = $client->post('/force-password-change', [
            'csrf_token' => $client->get('/force-password-change')->formField('csrf_token') ?? '',
            'current_password' => $erst,
            'password' => $passwort,
            'password_confirm' => $passwort,
        ]);
        self::assertSame('/admin?password_changed=1', $gewechselt->location());

        return [
            'client' => $client,
            'username' => $username,
            'email' => $email,
            'passwort' => $passwort,
            'id' => self::kontoIdNachName($username),
        ];
    }

    /** Dasselbe für den geteilten Admin der Suite. */
    protected function adminStepUp(HttpClient $admin, string $fuer = 'profil'): void {
        self::ensureProvisioned();
        $this->stepUpMitTotp($admin, (string)self::$adminEmail, (string)self::$adminPassword, (string)self::$totpSecret, $fuer);
    }

    /**
     * Liefert einen frischen, aber bereits vollständig eingeloggten Client
     * (Passwort-Login + 2FA-Verifikation über den echten HTTP-Flow).
     */
    protected function authenticatedClient(): HttpClient {
        self::ensureProvisioned();
        self::resetTotpReplayGuard(self::$adminEmail);
        $client = $this->newClient();

        $loginPage = $client->get('/login');
        $loginResponse = $client->post('/login', [
            'csrf_token' => $loginPage->formField('csrf_token') ?? '',
            'kennung' => self::$adminEmail,
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
            'kennung' => $email,
            'password' => $password,
        ]);
        self::assertSame(
            '/2fa/setup',
            $loginResponse->location(),
            "Erstanmeldung des Test-Editors sollte zur 2FA-Einrichtung führen, Body: {$loginResponse->body}"
        );

        $setupPage = $client->get('/2fa/setup');
        $secret = self::extractTotpSecret($setupPage);
        self::assertNotNull($secret, 'Konnte TOTP-Secret nicht aus /2fa/setup extrahieren');
        $this->lastEditorTotpSecret = $secret;

        $enableResponse = $client->post('/2fa/enable', [
            'csrf_token' => $setupPage->formField('csrf_token') ?? '',
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
            // Das bisherige Passwort ist Pflicht, damit eine übernommene
            // Sitzung das Konto nicht dauerhaft an sich binden kann.
            'current_password' => $password,
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
    /**
     * Standard-Leserechte der eingebauten Gast-Gruppe (`public`), gespiegelt
     * aus dem Seed in database/schema.sql.
     *
     * An EINER Stelle, weil mehrere Tests die Rechte dieser Gruppe ersetzen und
     * danach wiederherstellen müssen: Der Endpunkt löscht die Menge komplett und
     * legt sie neu an. Standen die Vorgaben je Test hartkodiert da, veraltete
     * eine davon beim nächsten neuen Gastrecht - und der Test, der es braucht,
     * fiel weit entfernt und ohne erkennbaren Zusammenhang um. Genau das ist
     * beim Hinzukommen von persons.view (#293) passiert.
     *
     * @var array<string, array<int, string>>
     */
    /**
     * Seit #336 gibt es EIN Modul `contacts` statt der beiden getrennten
     * `persons` und `breeding_stations`. Wer diese Konstanten setzt, muss die
     * neuen Namen nennen - mit den alten naehme er der Gast-Gruppe
     * `contacts.view` und damit die halbe oeffentliche Flaeche, ohne dass es
     * nach einem Rechtefehler aussaehe.
     */
    protected const GUEST_DEFAULT_PERMISSIONS = [
        'horses' => ['view'],
        'contacts' => ['view'],
    ];

    protected const EDITOR_DEFAULT_PERMISSIONS = [
        'horses' => ['view', 'internal', 'create', 'edit', 'delete', 'publish'],
        'contacts' => ['view', 'internal', 'create', 'edit', 'delete', 'publish'],
    ];

    /**
     * Ermittelt die ID einer eingebauten Gruppe (Administrator/Editor/Öffentlich)
     * über das "Gruppe zur Bearbeitung auswählen"-Dropdown in /admin/groups -
     * dieses listet immer ALLE Gruppen vollständig, unabhängig von Suche/Pagination
     * der Übersichtstabelle (siehe GroupController::index()).
     */
    /**
     * Legt eine Gruppe OHNE 2FA-Pflicht an und gibt ihre ID zurueck (#84).
     *
     * Fuer Tests, die den Anmeldeweg selbst untersuchen: Ein Konto ohne
     * 2FA-Zwang landet nach dem Passwort direkt im Ziel, statt zuerst durch
     * die TOTP-Einrichtung zu muessen.
     */
    protected function createGroupWithoutTwoFa(HttpClient $admin, string $name): int {
        $groupsPage = $admin->get('/admin/groups');
        $createResponse = $admin->post('/admin/groups/create', [
            'csrf_token' => $groupsPage->formField('csrf_token') ?? '',
            'name' => $name,
        ]);
        preg_match('/group=(\d+)/', (string)$createResponse->location(), $matches);
        self::assertNotEmpty($matches, "Konnte Gruppen-ID zu '{$name}' nicht ermitteln, Body: {$createResponse->body}");
        $groupId = (int)$matches[1];

        $toggle = $admin->post('/admin/groups/require-2fa', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'group_id' => (string)$groupId,
            // require_2fa bewusst NICHT gesetzt -> 0
        ]);
        self::assertSame("/admin/groups?group={$groupId}&success=require_2fa_updated", $toggle->location());

        return $groupId;
    }

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
    /**
     * Warum die Ersteinrichtung nicht griff - in der Reihenfolge, in der es
     * tatsaechlich vorkommt.
     *
     * WARUM DAS HIER STEHT. Die Meldung nannte frueher nur die
     * Umgebungsvariablen. Die haeufigste Ursache ist aber eine ANDERE: eine
     * Testdatenbank, in der noch ein Lauf von vorhin steht. Dann meldet
     * /setup "schon eingerichtet", leitet nach /login, und ALLE Tests der
     * Suite scheitern - jeder mit einem Fehlerbild, das nach einem Codefehler
     * aussieht. Wer der genannten Spur folgt, prueft eine halbe Stunde lang
     * Umgebungsvariablen, die in Ordnung sind.
     */
    private static function setupDiagnose(): string {
        $fehlend = [];
        foreach (['ADMIN_EMAIL', 'ADMIN_USERNAME', 'ADMIN_PASSWORD', 'SITE_NAME', 'APP_KEY', 'DB_NAME'] as $name) {
            if (getenv($name) === false || getenv($name) === '') {
                $fehlend[] = $name;
            }
        }
        if ($fehlend !== []) {
            return 'Diese Umgebungsvariablen fehlen: ' . implode(', ', $fehlend) . '. ';
        }

        try {
            $db = \App\Database::getInstance();
            $konten = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
            if ($konten > 0) {
                return sprintf(
                    'Die Testdatenbank "%s" ist NICHT LEER (%d Konto/Konten) - /setup haelt die Instanz '
                    . 'fuer bereits eingerichtet und leitet nach /login. Datenbank neu anlegen und den '
                    . 'Lauf wiederholen. ',
                    getenv('DB_NAME') ?: '?',
                    $konten
                );
            }
        } catch (\Throwable $e) {
            return 'Die Testdatenbank ist nicht erreichbar (' . $e->getMessage() . '). ';
        }

        return 'Umgebungsvariablen gesetzt und Datenbank leer - die Ursache liegt woanders. ';
    }

    private static function ensureProvisioned(): void {
        if (self::$adminEmail !== null) {
            return;
        }

        self::$adminEmail = getenv('ADMIN_EMAIL') ?: 'functional-test-admin@example.com';
        self::$adminPassword = getenv('ADMIN_PASSWORD') ?: 'FunctionalTest123!';

        $client = new HttpClient(PhpBuiltInServer::baseUrl());

        // Die Env-Ersteinrichtung vergibt KEINE Sitzung (Audit H2): Sie legt
        // das Konto an und leitet auf /login. Erst ADMIN_PASSWORD fuehrt zur
        // 2FA-Einrichtung - genau wie beim Betreiber.
        $setupResponse = $client->get('/setup');
        self::assertSame(
            '/login?success=setup_completed',
            $setupResponse->location(),
            "Automatische Ersteinrichtung sollte zu /login?success=setup_completed weiterleiten. "
            . ($setupResponse->statusCode === 503 ? 'HTTP 503: ADMIN_*/APP_KEY/SITE_NAME/DB_* ungueltig. ' : '')
            . self::setupDiagnose() . "Body: {$setupResponse->body}"
        );

        // Eingebaute Gegenprobe zu H2: Die provisionierende Sitzung kommt
        // ohne Passwort nicht an die 2FA-Einrichtung des neuen Admins.
        self::assertSame(
            '/login',
            $client->get('/2fa/setup')->location(),
            'Die Env-Ersteinrichtung darf keine Sitzung fuer das neue Admin-Konto vergeben (Audit H2).'
        );

        $loginPage = $client->get('/login');
        $loginResponse = $client->post('/login', [
            'csrf_token' => $loginPage->formField('csrf_token') ?? '',
            'kennung' => self::$adminEmail,
            'password' => self::$adminPassword,
        ]);
        self::assertSame(
            '/2fa/setup',
            $loginResponse->location(),
            "Erste Anmeldung des Env-Admins sollte zur 2FA-Einrichtung fuehren, Body: {$loginResponse->body}"
        );

        $setupPage = $client->get('/2fa/setup');
        $secret = self::extractTotpSecret($setupPage);
        self::assertNotNull($secret, 'Konnte TOTP-Secret nicht aus /2fa/setup extrahieren');
        self::$totpSecret = $secret;

        $enableResponse = $client->post('/2fa/enable', [
            'csrf_token' => $setupPage->formField('csrf_token') ?? '',
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
