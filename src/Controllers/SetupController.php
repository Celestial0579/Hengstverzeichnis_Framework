<?php
// src/Controllers/SetupController.php

namespace App\Controllers;

use App\Database;
use PDO;
use PDOException;

class SetupController extends BaseController {

    private static function dbConfigFilePath(): string {
        return __DIR__ . '/../../config/db_config.php';
    }

    /**
     * Liest die aktuelle config/db_config.php ein (leeres Array, falls sie nicht
     * existiert - z. B. bei rein Env-Var-basierten Deployments).
     */
    public static function readDbConfig(): array {
        $file = self::dbConfigFilePath();
        return file_exists($file) ? (require $file) : [];
    }

    /**
     * Schreibt einen einzelnen Schlüssel in config/db_config.php, bestehende Werte
     * bleiben erhalten. Für sicherheitsrelevante Einstellungen (z. B. TRUSTED_PROXIES),
     * die auch ohne Umgebungsvariablen-Unterstützung (klassisches Webhosting) über den
     * Admin-Bereich konfigurierbar sein müssen.
     *
     * Lesen, Ändern und Schreiben laufen unter einer exklusiven Sperre auf
     * config/.db_config.lock (Audit N56): Zwei gleichzeitige Speichervorgänge
     * (etwa trusted_proxies und tracking_domains aus zwei Tabs) lasen sonst
     * denselben Stand, und der zweite überschrieb den Wert des ersten. Ist
     * die Sperrdatei nicht anlegbar, geht es ohne Sperre weiter - die
     * atomare Umbenennung schützt die Datei selbst weiterhin.
     *
     * Ein unveränderter Wert wird nicht neu geschrieben.
     */
    public static function writeDbConfigValue(string $key, $value): bool {
        $path = self::dbConfigFilePath();
        $sperre = @fopen(dirname($path) . '/.db_config.lock', 'c');
        if ($sperre === false) {
            error_log('Konnte config/.db_config.lock nicht anlegen - db_config.php wird ohne Sperre geschrieben.');
        } elseif (!@flock($sperre, LOCK_EX)) {
            error_log('Konnte config/.db_config.lock nicht sperren - db_config.php wird ohne Sperre geschrieben.');
        }

        try {
            // Unter der Sperre den AKTUELLEN Stand lesen: readDbConfig()
            // lädt per `require`, und opcache könnte innerhalb von
            // revalidate_freq noch den Stand vor dem letzten Schreiben
            // liefern - dann ginge dessen Wert verloren.
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($path, true);
            }
            $config = self::readDbConfig();
            if (array_key_exists($key, $config) && $config[$key] === $value) {
                return true;
            }
            $config[$key] = $value;
            $content = "<?php\n// Auto-generated database configuration\nreturn " . var_export($config, true) . ";\n";
            return self::writeDbConfigFile($content);
        } finally {
            if ($sperre !== false) {
                @flock($sperre, LOCK_UN);
                @fclose($sperre);
            }
        }
    }

    /**
     * Schreibt config/db_config.php atomar mit den Rechten 0600
     * (App\Helper\AtomicFile, Audit N56).
     *
     * Die Datei enthält das Datenbank-Passwort und den APP_KEY im Klartext -
     * mit dem Schlüssel lassen sich alle verschlüsselt abgelegten Geheimnisse
     * entschlüsseln (SMTP-, S3-, FTPS-, WebDAV-Zugänge und die TOTP-Secrets,
     * siehe App\Security\Crypto). Deshalb nur für den Eigentümer lesbar;
     * auf geteiltem Webhosting ist "für jeden Systembenutzer lesbar" genau
     * der Fall, den man nicht will. Scheitert das Schreiben, bleibt die alte
     * Datei unverändert - früher blieb eine gekürzte Datei und ein
     * verlorener APP_KEY zurück.
     *
     * Voraussetzung: config/ ist für den PHP-Benutzer beschreibbar (die
     * temporäre Datei entsteht dort), und bei gesetztem Sticky-Bit gehört
     * db_config.php diesem Benutzer.
     */
    private static function writeDbConfigFile(string $content): bool {
        return \App\Helper\AtomicFile::write(self::dbConfigFilePath(), $content, 0600);
    }

    public static function needsSetup(): bool {
        $dbConfigFile = self::dbConfigFilePath();
        $hasEnvConfig = self::isDbConfiguredViaEnv();
        if (!file_exists($dbConfigFile) && !$hasEnvConfig) {
            return true;
        }

        try {
            $db = Database::getInstance();
            // Zusätzlich gegen tatsächlich existierende, nicht gelöschte Benutzer
            // prüfen: verwaiste user_groups-Zeilen (z. B. nach einem Werksreset)
            // dürfen die Ersteinrichtung nicht blockieren (#118).
            $stmt = $db->query("
                SELECT COUNT(*) FROM user_groups ug
                JOIN `groups` g ON g.id = ug.group_id
                -- BEWUSST OHNE deactivated_at (#358): Ein deaktiviertes
                -- Admin-Konto zaehlt weiterhin als vorhandener Administrator.
                -- Sonst hielte sich die Installation nach einer Sperre fuer
                -- uneingerichtet und boete den Setup-Assistenten wieder an -
                -- also einen Weg, sich ohne Anmeldung ein Adminkonto anzulegen.
                JOIN users u ON u.id = ug.user_id AND u.deleted_at IS NULL
                WHERE g.slug = 'admin'
            ");
            $count = (int)$stmt->fetchColumn();
            return $count === 0;
        } catch (\PDOException $e) {
            // Table 'users' does not exist yet -> needs setup
            if ($e->getCode() === '42S02' || strpos($e->getMessage(), '42S02') !== false || strpos($e->getMessage(), "doesn't exist") !== false) {
                return true;
            }
            // Connection error when db_config.php exists -> do NOT redirect to setup loop
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function isDbConfiguredViaEnv(): bool {
        return getenv('DB_HOST') !== false || getenv('DB_USER') !== false || getenv('DB_PASS') !== false;
    }

    /**
     * Passwoerter aus Beispielen (.env.example, frueheres README). Wer sie
     * uebernimmt, hat ein bekanntes Admin-Passwort.
     */
    public const PLATZHALTER_PASSWOERTER = ['change-me', 'change-me-too'];

    /**
     * Ist der erste Admin per Umgebung vorgegeben? Wahr, sobald EINE der
     * ADMIN_*-Variablen nicht leer ist. Leere Zeilen aus .env.example kommen
     * per env_file als leere Strings an und zaehlen nicht.
     *
     * Dann gilt fail-closed (Audit H2): Entweder sind alle Werte vollstaendig
     * und gueltig, oder es erscheint eine Fehlerseite OHNE Formular. Frueher
     * fiel ein ungueltiger Wert still auf den Wizard zurueck - und der bot
     * dem ersten beliebigen Besucher das Admin-Formular an. Das bisherige
     * README-Beispiel ADMIN_USERNAME=admin (reserviert) loeste genau das aus.
     */
    private static function envAdminAngefordert(): bool {
        foreach (['ADMIN_USERNAME', 'ADMIN_EMAIL', 'ADMIN_PASSWORD'] as $name) {
            $wert = getenv($name);
            if ($wert !== false && trim($wert) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Prueft die per Umgebung vorgegebenen Admin-Werte mit denselben Regeln
     * wie jedes andere Anlegen. Die Meldungen nennen nur Variablennamen, nie
     * Werte - die Seite ist vor der Einrichtung anonym erreichbar.
     *
     * @return array<int, string>
     */
    public static function envAdminFehler(string $username, string $email, string $password): array {
        $fehler = [];

        foreach (\App\Security\LoginIdentifier::usernameErrors($username) as $meldung) {
            $fehler[] = 'ADMIN_USERNAME: ' . $meldung;
        }
        if (trim($username) !== '' && \App\Service\UserProvisioning::istReservierterName($username)) {
            $fehler[] = 'ADMIN_USERNAME: Dieser Benutzername ist aus Sicherheitsgruenden reserviert.';
        }
        if (trim($email) === '' || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
            $fehler[] = 'ADMIN_EMAIL: keine gueltige E-Mail-Adresse.';
        }
        if (strlen($password) < \App\Service\UserProvisioning::MIN_PASSWORD_LENGTH) {
            $fehler[] = 'ADMIN_PASSWORD: mindestens ' . \App\Service\UserProvisioning::MIN_PASSWORD_LENGTH . ' Zeichen.';
        } elseif (in_array(strtolower(trim($password)), self::PLATZHALTER_PASSWOERTER, true)) {
            $fehler[] = 'ADMIN_PASSWORD: Das Beispielpasswort ist nicht zulaessig - bitte ein eigenes, starkes Passwort setzen.';
        }

        return $fehler;
    }

    /** @return array{username: string, email: string, password: string} */
    private static function envAdminRohwerte(): array {
        return [
            'username' => trim((string)getenv('ADMIN_USERNAME')),
            'email' => trim((string)getenv('ADMIN_EMAIL')),
            'password' => (string)getenv('ADMIN_PASSWORD'),
        ];
    }

    private static function envSiteName(): ?string {
        $siteName = getenv('SITE_NAME');
        if ($siteName === false) {
            return null;
        }
        $siteName = trim($siteName);
        return $siteName !== '' ? $siteName : null;
    }

    public function showSetup(): void {
        if (!self::needsSetup()) {
            header("Location: /login");
            exit;
        }

        $dbFromEnv = self::isDbConfiguredViaEnv();
        $siteFromEnv = self::envSiteName();

        // Vollautomatische Ersteinrichtung: Der erste Admin ist per Umgebung
        // vorgegeben. Dieser Zweig vergibt KEINE Sitzung (Audit H2) - wer
        // /setup aufruft, ist irgendein Besucher und hat nichts bewiesen.
        // Angelegt wird nur das Konto, das der Betreiber vorgegeben hat; die
        // Anmeldung verlangt danach ADMIN_PASSWORD auf /login.
        if (self::envAdminAngefordert()) {
            $admin = self::envAdminRohwerte();
            $fehler = self::envAdminFehler($admin['username'], $admin['email'], $admin['password']);
            if (!$dbFromEnv) {
                $fehler[] = 'Die Datenbankverbindung (DB_HOST/DB_USER/DB_PASS) ist nicht per Umgebungsvariable gesetzt.';
            }
            if ($siteFromEnv === null) {
                $fehler[] = 'SITE_NAME fehlt.';
            }
            if (empty(getenv('APP_KEY'))) {
                $fehler[] = 'APP_KEY ist nicht gesetzt.';
            }

            $nurHinweis = ['nurHinweis' => true, 'hideDb' => true, 'hideSite' => true];
            if ($fehler !== []) {
                http_response_code(503);
                $this->render('setup', array_merge([
                    'title' => 'Einrichtung - Hengstverzeichnis Framework',
                    'errors' => $fehler,
                ], $nurHinweis));
                return;
            }

            // APP_URL ist für die Env-Einrichtung bewusst KEINE Pflicht
            // (CI, Docker-Healthchecks), aber dringend empfohlen: Ohne feste
            // Stamm-URL bleiben Token-Mails gesperrt (Audit M6), bis ein
            // Admin sie in den Systemeinstellungen festlegt. Das Dashboard
            // warnt; hier steht es für den Betreiber im Server-Log.
            if (!self::appUrlAusUmgebung() && !\App\Security\TrustedHost::hasAllowlist()) {
                error_log('ERSTEINRICHTUNG (Umgebung): APP_URL ist nicht gesetzt - Passwort-Reset-, Verifizierungs- und '
                    . 'Adressbestätigungs-Mails bleiben gesperrt, bis APP_URL, TRUSTED_HOSTS oder die Stamm-URL '
                    . '(Admin > Systemeinstellungen) gesetzt ist.');
            }

            $this->provision(
                getenv('DB_HOST') ?: '127.0.0.1',
                getenv('DB_PORT') ?: '3306',
                getenv('DB_NAME') ?: 'hengstverzeichnis',
                getenv('DB_USER') ?: '',
                getenv('DB_PASS') ?: '',
                in_array(getenv('DB_SSL'), ['true', '1'], true),
                in_array(getenv('DB_SSL_VERIFY'), ['true', '1'], true),
                getenv('DB_SSL_CA') ?: '',
                $siteFromEnv,
                $admin['username'],
                $admin['email'],
                $admin['password'],
                false,
                false,
                $nurHinweis
            );
            return;
        }

        // Reduzierter Wizard: Abschnitte, die bereits per Env-Variable feststehen, werden
        // ausgeblendet, damit nicht versehentlich bereits konfigurierte Werte überschrieben werden.
        $this->render('setup', [
            'title' => 'Einrichtung - Hengstverzeichnis Framework',
            'hideDb' => $dbFromEnv,
            'hideSite' => $siteFromEnv !== null,
        ] + self::stammUrlAnzeige());
    }

    /** Ist APP_URL per Umgebung gesetzt? Dann entfällt das Feld Stamm-URL. */
    private static function appUrlAusUmgebung(): bool {
        return trim((string)(getenv('APP_URL') ?: '')) !== '';
    }

    /**
     * View-Variablen für das Feld Stamm-URL (Audit M6): ausblenden, wenn
     * APP_URL gesetzt ist; sonst ein Vorschlag aus der aufgerufenen Adresse,
     * aber nur, wenn er die strenge Prüfung besteht (nie localhost oder
     * private IPs). Der Vorschlag wird erst mit dem Absenden übernommen.
     *
     * @return array{hideBaseUrl: bool, baseUrlSuggestion: ?string}
     */
    private static function stammUrlAnzeige(): array {
        $ausUmgebung = self::appUrlAusUmgebung();
        return [
            'hideBaseUrl' => $ausUmgebung,
            'baseUrlSuggestion' => $ausUmgebung ? null : \App\Security\BaseUrl::suggestion(),
        ];
    }

    public function processSetup(): void {
        if (!self::needsSetup()) {
            header("Location: /login");
            exit;
        }

        // Ist der erste Admin per Umgebung vorgegeben, legt ihn ausschliesslich
        // der Env-Pfad an - ein Formular-POST darf kein eigenes Konto
        // einschleusen (Audit H2). Bewusst VOR der CSRF-Pruefung: Der Guard
        // aendert keinen Zustand.
        if (self::envAdminAngefordert()) {
            header("Location: /setup");
            exit;
        }

        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $dbFromEnv = self::isDbConfiguredViaEnv();
        $siteFromEnv = self::envSiteName();

        // DB Fields (Umgebungsvariablen haben Vorrang, falls der DB-Abschnitt ausgeblendet war)
        $dbHost = getenv('DB_HOST') ?: trim($_POST['db_host'] ?? '127.0.0.1');
        $dbPort = getenv('DB_PORT') ?: trim($_POST['db_port'] ?? '3306');
        $dbName = getenv('DB_NAME') ?: trim($_POST['db_name'] ?? 'hengstverzeichnis');
        $dbUser = getenv('DB_USER') ?: trim($_POST['db_user'] ?? 'root');
        $dbPass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : ($_POST['db_pass'] ?? '');

        // DB SSL/TLS Fields
        $dbSsl = getenv('DB_SSL') !== false ? in_array(getenv('DB_SSL'), ['true', '1'], true) : !empty($_POST['db_ssl']);
        $dbSslVerify = getenv('DB_SSL_VERIFY') !== false ? in_array(getenv('DB_SSL_VERIFY'), ['true', '1'], true) : !empty($_POST['db_ssl_verify']);
        $dbSslCa = getenv('DB_SSL_CA') !== false ? getenv('DB_SSL_CA') : trim($_POST['db_ssl_ca'] ?? '');

        // App Fields
        $siteName = $siteFromEnv ?? trim($_POST['site_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';

        $errors = [];

        // Stamm-URL (Audit M6): optional, aber - wenn angegeben - mit
        // derselben strengen Prüfung wie in den Systemeinstellungen. Eine
        // lokale Adresse, die hier durchginge, würde dort bei jedem späteren
        // Speichern abgelehnt. Mit gesetztem APP_URL ist das Feld
        // ausgeblendet und wird ignoriert.
        $baseUrl = null;
        $baseUrlEingabe = self::appUrlAusUmgebung() ? '' : trim((string)($_POST['base_url'] ?? ''));
        if ($baseUrlEingabe !== '') {
            $baseUrl = \App\Security\BaseUrl::normalize($baseUrlEingabe);
            if ($baseUrl === null) {
                $errors[] = 'Die Stamm-URL ist ungültig (http:// oder https:// mit öffentlichem Hostnamen). Für Testinstallationen das Feld leer lassen.';
            }
        }

        if (!$dbFromEnv) {
            if (empty($dbHost)) $errors[] = "Bitte geben Sie den Datenbank-Server (Host) ein.";
            if (empty($dbPort)) $errors[] = "Bitte geben Sie den Datenbank-Port ein.";
            if (empty($dbName)) $errors[] = "Bitte geben Sie den Datenbank-Namen ein.";
            if (empty($dbUser)) $errors[] = "Bitte geben Sie den Datenbank-Benutzer ein.";
        }

        // Formatprüfung der TATSÄCHLICH verwendeten Werte - unabhängig davon,
        // ob der DB-Abschnitt im Formular sichtbar war (App\Security\DbIdentifier).
        //
        // Die Prüfung des Namens stand früher im Zweig darüber, und
        // $dbFromEnv ist wahr, sobald IRGENDEINE der Variablen
        // DB_HOST/DB_USER/DB_PASS gesetzt ist - DB_NAME zählt gar nicht mit.
        // Wer also DB_HOST setzt, aber DB_NAME nicht, bekam den Namen
        // weiterhin aus $_POST, ungeprüft, und er landet in provision()
        // interpoliert in `DROP DATABASE` und `CREATE DATABASE` (Bezeichner
        // lassen sich nicht als Parameter binden). Der Wizard ist vor der
        // Einrichtung unauthentifiziert erreichbar.
        if ($dbName !== '' && !\App\Security\DbIdentifier::isValidDatabaseName($dbName)) {
            $errors[] = "Der Datenbank-Name darf nur Buchstaben, Ziffern und Unterstriche enthalten (max. 64 Zeichen).";
        }
        if ($dbHost !== '' && !\App\Security\DbIdentifier::isValidHost($dbHost)) {
            $errors[] = "Der Datenbank-Server enthält unzulässige Zeichen.";
        }
        if ($dbPort !== '' && !\App\Security\DbIdentifier::isValidPort($dbPort)) {
            $errors[] = "Der Datenbank-Port muss eine Zahl zwischen 1 und 65535 sein.";
        }

        if ($siteFromEnv === null && empty($siteName)) $errors[] = "Bitte geben Sie einen Namen für den Verband / die Seite ein.";
        if (empty($username)) $errors[] = "Bitte geben Sie einen Benutzernamen ein.";
        if ($this->isReservedUsername($username)) $errors[] = "Der Benutzername '{$username}' ist aus Sicherheitsgründen reserviert und darf nicht verwendet werden.";
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Bitte geben Sie eine gültige E-Mail-Adresse ein.";
        if (strlen($password) < 8) $errors[] = "Das Passwort muss mindestens 8 Zeichen lang sein.";
        if ($password !== $passwordConfirm) $errors[] = "Die Passwörter stimmen nicht überein.";

        $renderExtra = ['hideDb' => $dbFromEnv, 'hideSite' => $siteFromEnv !== null] + self::stammUrlAnzeige();

        if (!empty($errors)) {
            $this->render('setup', array_merge([
                'title' => 'Einrichtung - Hengstverzeichnis Framework',
                'errors' => $errors,
                'old' => $_POST,
            ], $renderExtra));
            return;
        }

        $overwriteDb = !empty($_POST['overwrite_db']);

        $this->provision(
            $dbHost, $dbPort, $dbName, $dbUser, $dbPass,
            $dbSsl, $dbSslVerify, $dbSslCa,
            $siteName, $username, $email, $password,
            $overwriteDb,
            !$dbFromEnv,
            array_merge(['old' => $_POST], $renderExtra),
            startSession: true,
            baseUrl: $baseUrl
        );
    }

    /**
     * Führt die eigentliche Ersteinrichtung durch: DB anlegen, Schema importieren,
     * Admin-Konto erstellen. Wird sowohl vom klassischen Formular (processSetup)
     * als auch von der vollautomatischen Env-Var-Ersteinrichtung (showSetup) genutzt.
     *
     * @param bool $writeDbConfigFile Nur wahr, wenn die DB-Zugangsdaten NICHT bereits per
     *   Umgebungsvariable vorliegen - sonst würde eine überflüssige config/db_config.php
     *   entstehen, obwohl die App bereits rein über Env-Variablen lauffähig ist.
     * @param array $errorRenderExtra Zusätzliche View-Variablen (alte Eingaben, hideDb/hideSite),
     *   die bei einem Fehler zusammen mit der Fehlermeldung erneut gerendert werden.
     * @param bool $startSession Nur der Wizard setzt das: Dort hat der Anfragende das
     *   Passwort soeben selbst eingegeben, das ist der Nachweis des ersten Faktors. Die
     *   Env-Einrichtung vergibt keine Sitzung (Audit H2). Default fail-safe: false.
     */
    private function provision(
        string $dbHost, string $dbPort, string $dbName, string $dbUser, string $dbPass,
        bool $dbSsl, bool $dbSslVerify, string $dbSslCa,
        string $siteName, string $username, string $email, string $password,
        bool $overwriteDb, bool $writeDbConfigFile, array $errorRenderExtra = [],
        bool $startSession = false, ?string $baseUrl = null
    ): void {
        // Build PDO Options including SSL if enabled
        $pdoOptions = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        if ($dbSsl) {
            if (defined('PDO::MYSQL_ATTR_SSL_CA') && !empty($dbSslCa)) {
                $pdoOptions[PDO::MYSQL_ATTR_SSL_CA] = $dbSslCa;
            }
            if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                $pdoOptions[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $dbSslVerify;
            }
        }

        // Test Database Connection (Connect to MySQL server first)
        // Derselbe DSN-Helfer wie im laufenden Betrieb - auch für einen
        // Unix-Socket als Host (Audit N55).
        $dsnWithoutDb = Database::buildDsn($dbHost, $dbPort, null);
        try {
            $testPdo = new PDO($dsnWithoutDb, $dbUser, $dbPass, $pdoOptions);

            if ($overwriteDb) {
                // Drop database if user requested overwrite
                $testPdo->exec("DROP DATABASE IF EXISTS `$dbName`");
            }

            // Create database if not exists and select it
            $testPdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $testPdo->exec("USE `$dbName`");

        } catch (PDOException $e) {
            if (!$startSession) {
                $this->envEinrichtungFehlgeschlagen($e->getMessage(), $errorRenderExtra);
                return;
            }
            $this->render('setup', array_merge([
                'title' => 'Einrichtung - Hengstverzeichnis Framework',
                'errors' => ['Datenbank-Verbindung fehlgeschlagen: ' . $e->getMessage()],
            ], $errorRenderExtra));
            return;
        }

        if ($writeDbConfigFile) {
            // Save DB Config File
            $configContent = "<?php\n// Auto-generated database configuration\nreturn " . var_export([
                'host' => $dbHost,
                'port' => $dbPort,
                'name' => $dbName,
                'user' => $dbUser,
                'pass' => $dbPass,
                'ssl'  => $dbSsl,
                'ssl_verify' => $dbSslVerify,
                'ssl_ca' => $dbSslCa,
                'app_key' => bin2hex(random_bytes(32)),
                // Explizit 'production': eine per Setup-Wizard eingerichtete Instanz ist eine
                // echte Installation, keine lokale Entwicklungsumgebung - PHP-Fehlerdetails
                // dürfen Besuchern nicht angezeigt werden (siehe config/config.php).
                'app_env' => 'production',
            ], true) . ";\n";

            if (!self::writeDbConfigFile($configContent)) {
                $this->render('setup', array_merge([
                    'title' => 'Einrichtung - Hengstverzeichnis Framework',
                    'errors' => ['Konnte config/db_config.php nicht schreiben. Bitte Schreibrechte im Ordner config/ prüfen.'],
                ], $errorRenderExtra));
                return;
            }
        }

        // Import SQL Schema automatically
        $schemaFile = __DIR__ . '/../../database/schema.sql';
        if (file_exists($schemaFile)) {
            $sql = file_get_contents($schemaFile);
            try {
                $testPdo->exec($sql);
            } catch (PDOException $e) {
                error_log('SCHEMA-IMPORT-FEHLER: ' . $e->getMessage());
            }
        }

        try {
            // Neue Installationsepoche (Audit M24, App\Service\InstallEpoch):
            // NACH dem Schema-Import (vorher gibt es keine settings-Tabelle)
            // und VOR dem Anlegen des Admins. Bei overwrite_db und bei einer
            // neuen Datenbank vergibt MariaDB die Benutzer-IDs wieder ab 1 -
            // eine Alt-Sitzung mit user_id 1 aus der vorherigen Installation
            // trüge sonst das neue Admin-Konto. In beiden Wegen, auch wenn
            // die Env-Einrichtung selbst keine Sitzung vergibt.
            $epoche = \App\Service\InstallEpoch::renew($testPdo);

            // Save Site Name setting
            $stmt = $testPdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('site_name', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$siteName, $siteName]);

            // Stamm-URL aus dem Assistenten (Audit M6) - bereits über
            // App\Security\BaseUrl::normalize() geprüft. Die
            // Env-Einrichtung übergibt null: Dort gilt APP_URL.
            if ($baseUrl !== null) {
                $stmt = $testPdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('base_url', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$baseUrl, $baseUrl]);
            }

            // Create Admin User (must_change_password = 0 since password was set during setup)
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $testPdo->prepare("INSERT INTO users (username, email, password_hash, must_change_password) VALUES (?, ?, ?, 0)");
            $stmt->execute([$username, $email, $passwordHash]);
            $newUserId = $testPdo->lastInsertId();

            // Mitgliedschaft in der Gruppe `admin` (#66) - einziges Rechtesystem,
            // macht diesen Benutzer zum vollwertigen Administrator. Die Gruppe wurde
            // bereits durch den oben importierten schema.sql geseedet.
            $adminGroupId = $testPdo->query("SELECT id FROM `groups` WHERE slug = 'admin'")->fetchColumn();
            if ($adminGroupId) {
                $stmt = $testPdo->prepare("INSERT IGNORE INTO user_groups (user_id, group_id) VALUES (?, ?)");
                $stmt->execute([$newUserId, $adminGroupId]);
            }

            if ($startSession) {
                // Wizard: Der Anfragende hat das Passwort soeben selbst
                // eingegeben - das ist der Nachweis des ersten Faktors.
                // Die Epoche kommt aus $testPdo: Das ist die Datenbank, in der
                // das Konto soeben entstanden ist - App\Database kann beim
                // Wizard noch auf die alte zeigen.
                \App\Service\LoginSession::forgetIdentity();
                \App\Service\LoginSession::beginSecondFactor((int)$newUserId, $epoche);
                header("Location: /2fa/setup");
                exit;
            }

            // Env-Einrichtung (Audit H2): keine Sitzung. Die GANZE Identität
            // wird abgeraeumt, nicht nur die Pending-ID:
            // AuthController::twofaTargetUserId() nimmt `pending_2fa_user_id ??
            // user_id`, und nach einer Neueinrichtung beginnen die Benutzer-IDs
            // wieder bei 1 - eine alte user_id in dieser Sitzung zeigte sonst
            // auf das neue, faktorlose Konto. Die neue Installationsepoche
            // (oben) verwirft solche Sitzungen ohnehin überall; das hier ist
            // der doppelte Boden für genau diese Anfrage.
            \App\Service\LoginSession::forgetIdentity();
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            error_log('ERSTEINRICHTUNG (Umgebung): Admin-Konto angelegt, ausgeloest von ' . \App\Security\ClientIp::resolve());
            header("Location: /login?success=setup_completed");
            exit;

        } catch (\Exception $e) {
            if (!$startSession) {
                // Ein paralleler Erstaufruf war schneller: Die Instanz ist
                // eingerichtet. Ohne diese Weiche saehe der anonyme Besucher
                // "Duplicate entry '<ADMIN_USERNAME>'" - die halbe Kennung.
                if (!self::needsSetup()) {
                    header("Location: /login?success=setup_completed");
                    exit;
                }
                $this->envEinrichtungFehlgeschlagen($e->getMessage(), $errorRenderExtra);
                return;
            }
            $this->render('setup', array_merge([
                'title' => 'Einrichtung - Hengstverzeichnis Framework',
                'errors' => ['Einrichtungsfehler: ' . $e->getMessage()],
            ], $errorRenderExtra));
        }
    }

    /**
     * Fehler der Env-Einrichtung: Details nur ins Server-Log. Der Aufrufer ist
     * anonym und bekommt weder die Rohmeldung der Datenbank noch ein Formular.
     */
    private function envEinrichtungFehlgeschlagen(string $detail, array $renderExtra): void {
        error_log('ERSTEINRICHTUNG (Umgebung): ' . $detail);
        http_response_code(503);
        $this->render('setup', array_merge([
            'title' => 'Einrichtung - Hengstverzeichnis Framework',
            'errors' => ['Automatische Ersteinrichtung fehlgeschlagen – Details stehen im Server-Log.'],
        ], $renderExtra, ['nurHinweis' => true]));
    }
}
