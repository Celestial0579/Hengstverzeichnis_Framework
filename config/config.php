<?php
// config/config.php

// Database Configuration: Umgebungsvariablen haben Vorrang vor db_config.php
// (welche vom Setup Wizard erzeugt wird und lokale Secrets enthalten kann)
$dbConfigFile = __DIR__ . '/db_config.php';
$dbConfig = file_exists($dbConfigFile) ? require $dbConfigFile : [];

define('DB_HOST', getenv('DB_HOST') ?: ($dbConfig['host'] ?? '127.0.0.1'));
define('DB_PORT', getenv('DB_PORT') ?: ($dbConfig['port'] ?? '3306'));
define('DB_NAME', getenv('DB_NAME') ?: ($dbConfig['name'] ?? 'hengstverzeichnis'));
define('DB_USER', getenv('DB_USER') ?: ($dbConfig['user'] ?? ''));
define('DB_PASS', getenv('DB_PASS') ?: ($dbConfig['pass'] ?? ''));
define('DB_SSL', getenv('DB_SSL') !== false ? in_array(getenv('DB_SSL'), ['true', '1'], true) : !empty($dbConfig['ssl']));
define('DB_SSL_CA', getenv('DB_SSL_CA') ?: ($dbConfig['ssl_ca'] ?? ''));

// Zertifikatsprüfung der DB-Verbindung. Standard ist AN, sobald eine CA-Datei
// hinterlegt ist: Wer eine CA angibt, will damit den Server prüfen - eine
// ausgeschaltete Prüfung machte die Angabe wirkungslos und die Verbindung
// verschlüsselt, aber nicht authentifiziert (ein Angreifer in der Mitte kann
// sich mit einem beliebigen Zertifikat ausgeben). Ohne CA-Datei bleibt es
// beim bisherigen Verhalten, sonst schlüge jede Verbindung mit
// selbstsigniertem Serverzertifikat plötzlich fehl. Ausdrücklich gesetzte
// Werte (Umgebungsvariable oder db_config.php) haben immer Vorrang.
$dbSslVerifyDefault = defined('DB_SSL_CA') && DB_SSL_CA !== '';
if (getenv('DB_SSL_VERIFY') !== false) {
    define('DB_SSL_VERIFY', in_array(getenv('DB_SSL_VERIFY'), ['true', '1'], true));
} elseif (array_key_exists('ssl_verify', $dbConfig)) {
    define('DB_SSL_VERIFY', (bool)$dbConfig['ssl_verify']);
} else {
    define('DB_SSL_VERIFY', $dbSslVerifyDefault);
}

// Trusted Reverse Proxies (siehe src/Security/ClientIp.php): Umgebungsvariable hat
// Vorrang, sonst der über Admin > Systemeinstellungen in db_config.php gespeicherte
// Wert. Damit lässt sich diese sicherheitsrelevante Einstellung auch auf klassischem
// Webhosting konfigurieren, wo Umgebungsvariablen oft nicht zuverlässig ankommen
// (siehe README, Abschnitt "Reverse Proxy & Client-IP-Erkennung").
// WICHTIG: Muss VOR dem ersten Aufruf von ClientIp::isHttps()/resolve() definiert
// werden (siehe unten) - ClientIp cached TRUSTED_PROXIES beim ersten Zugriff pro
// Request; ein späteres define() käme für diesen Request zu spät.
define('TRUSTED_PROXIES', getenv('TRUSTED_PROXIES') !== false ? getenv('TRUSTED_PROXIES') : ($dbConfig['trusted_proxies'] ?? ''));

// Tracking-Domains (siehe Admin > Systemeinstellungen): kommagetrennte Liste von
// https://-Origins (z. B. für Matomo/Google Analytics), die in der weiter unten
// gesetzten CSP freigeschaltet werden - ohne das würde die bewusst strikte
// default-src 'self'-Policy jedes eingebundene externe Tracking-Skript lautlos
// blockieren. Env-Variable hat Vorrang, sonst der über den Admin-Bereich
// gespeicherte Wert (analog zu TRUSTED_PROXIES). Nur echte https://-Origins ohne
// Pfad werden akzeptiert - alles andere wird verworfen (Schutz vor CSP-Header-
// Injection über einen korrupten Konfigurationswert).
$trackingDomainsRaw = getenv('TRACKING_DOMAINS') !== false ? getenv('TRACKING_DOMAINS') : ($dbConfig['tracking_domains'] ?? '');
$trackingDomainsList = array_values(array_filter(array_map('trim', explode(',', $trackingDomainsRaw)), function ($d) {
    return $d !== '' && preg_match('#^https://[a-zA-Z0-9.-]+(:\d+)?$#', $d) === 1;
}));
define('TRACKING_DOMAINS', implode(',', $trackingDomainsList));

// Fremde Seiten, die eine Embed-Ansicht dieses Verzeichnisses per <iframe>
// einbinden duerfen (#260). LEER = niemand, und das ist der Auslieferungszustand:
// Ohne ausdrueckliche Freigabe bleibt die Frame-Sperre fuer JEDE Seite bestehen,
// auch fuer die Embed-Routen selbst.
//
// Dieselbe Pruefung wie bei TRACKING_DOMAINS und aus demselben Grund: Nur echte
// https://-Origins ohne Pfad. Ein korrupter Konfigurationswert koennte sonst
// ueber frame-ancestors weitere CSP-Direktiven einschleusen - und anders als beim
// Tracking-Wert ginge es hier um die Clickjacking-Sperre selbst.
//
// Kein Platzhalter, keine Wildcard-Subdomains: '*.example.com' zu erlauben hiesse,
// jeder Subdomain dieser Fremd-Domain zu vertrauen; eine uebernommene Subdomain
// reicht dann fuer einen Clickjacking-Rahmen.
$embedDomainsRaw = getenv('EMBED_ALLOWED_DOMAINS') !== false ? getenv('EMBED_ALLOWED_DOMAINS') : ($dbConfig['embed_allowed_domains'] ?? '');
$embedDomainsList = array_values(array_filter(array_map('trim', explode(',', $embedDomainsRaw)), function ($d) {
    return $d !== '' && preg_match('#^https://[a-zA-Z0-9.-]+(:\d+)?$#', $d) === 1;
}));
define('EMBED_ALLOWED_DOMAINS', implode(',', $embedDomainsList));

// Origins eines externen CAPTCHA-Anbieters (Turnstile, hCaptcha, ...), die ein
// Addon ueber die Hooks aus #258 einbindet.
//
// WARUM EIN EIGENER WERT UND NICHT TRACKING_DOMAINS: Beide Anbieter rendern ihr
// Widget in einem IFRAME. TRACKING_DOMAINS erweitert script-src, img-src und
// connect-src - aber die Policy hatte ueberhaupt kein frame-src, es griff also
// der Rueckfall auf default-src 'self', und das Widget blieb lautlos leer.
// Ein Anbieter, der nur in script-src steht, laedt sein Skript und scheitert
// danach am Rahmen; genau die Sorte Fehler, die man dem CAPTCHA-Addon
// zuschreibt statt der CSP.
//
// Getrennt von TRACKING_DOMAINS, weil es zwei verschiedene Entscheidungen sind:
// Wer Tracking zulaesst, will damit nicht automatisch fremde Rahmen zulassen.
$captchaDomainsRaw = getenv('CAPTCHA_DOMAINS') !== false ? getenv('CAPTCHA_DOMAINS') : ($dbConfig['captcha_domains'] ?? '');
$captchaDomainsList = array_values(array_filter(array_map('trim', explode(',', $captchaDomainsRaw)), function ($d) {
    return $d !== '' && preg_match('#^https://[a-zA-Z0-9.-]+(:\d+)?$#', $d) === 1;
}));
define('CAPTCHA_DOMAINS', implode(',', $captchaDomainsList));

// Vertrauenswürdige Host-Header (Issue #116, siehe App\Security\TrustedHost):
// Kommagetrennte Hostnamen (führender Punkt = beliebige Subdomain). Wird nur für
// den dynamischen APP_URL-/Mailer-Fallback ausgewertet, wenn weder base_url noch
// die Umgebungsvariable APP_URL gesetzt sind. Auflösung analog TRUSTED_PROXIES:
// Env-Variable hat Vorrang, sonst db_config.php.
define('TRUSTED_HOSTS', getenv('TRUSTED_HOSTS') !== false ? getenv('TRUSTED_HOSTS') : ($dbConfig['trusted_hosts'] ?? ''));

// Microsoft Entra ID SSO (#42, siehe App\Controllers\EntraSsoController):
// optionale zusätzliche Login-Methode per OIDC. Auflösung analog zu
// TRUSTED_PROXIES: Umgebungsvariable hat Vorrang, sonst db_config.php. Ohne
// vollständige Konfiguration (alle drei Werte) bleibt SSO deaktiviert.
define('ENTRA_TENANT_ID', getenv('ENTRA_TENANT_ID') !== false ? getenv('ENTRA_TENANT_ID') : ($dbConfig['entra_tenant_id'] ?? ''));
define('ENTRA_CLIENT_ID', getenv('ENTRA_CLIENT_ID') !== false ? getenv('ENTRA_CLIENT_ID') : ($dbConfig['entra_client_id'] ?? ''));
define('ENTRA_CLIENT_SECRET', getenv('ENTRA_CLIENT_SECRET') !== false ? getenv('ENTRA_CLIENT_SECRET') : ($dbConfig['entra_client_secret'] ?? ''));

// Generischer OIDC-Login (Authentik, Keycloak, ...): Sind ISSUER_URL,
// CLIENT_ID und CLIENT_SECRET vollständig gesetzt, hat dieser Modus Vorrang
// vor den ENTRA_*-Variablen (die als Microsoft-Kurzform unverändert weiter
// funktionieren). Endpunkte kommen per OIDC-Discovery vom Issuer, siehe
// App\Security\OidcDiscovery und docs/security.md.
define('OIDC_ISSUER_URL', getenv('OIDC_ISSUER_URL') !== false ? getenv('OIDC_ISSUER_URL') : ($dbConfig['oidc_issuer_url'] ?? ''));
define('OIDC_CLIENT_ID', getenv('OIDC_CLIENT_ID') !== false ? getenv('OIDC_CLIENT_ID') : ($dbConfig['oidc_client_id'] ?? ''));
define('OIDC_CLIENT_SECRET', getenv('OIDC_CLIENT_SECRET') !== false ? getenv('OIDC_CLIENT_SECRET') : ($dbConfig['oidc_client_secret'] ?? ''));
define('OIDC_PROVIDER_LABEL', getenv('OIDC_PROVIDER_LABEL') !== false ? getenv('OIDC_PROVIDER_LABEL') : ($dbConfig['oidc_provider_label'] ?? ''));

// Application Base URL (dynamic resolution based on HTTP request or environment)
// isHttps() berücksichtigt X-Forwarded-Proto nur hinter einem via TRUSTED_PROXIES
// als vertrauenswürdig gelisteten Reverse Proxy. Der Host-Fallback nutzt den
// Host-Header nur nach Validierung durch TrustedHost::resolve() (Issue #116) -
// ein gefälschter/ungelisteter Host-Header fließt so nicht in absolute URLs
// (z. B. Passwort-Reset-Links) ein.
$dynamicScheme = \App\Security\ClientIp::isHttps() ? 'https://' : 'http://';
$dynamicHost = \App\Security\TrustedHost::resolve() ?: 'hengstverzeichnis.de';
define('APP_URL', getenv('APP_URL') ?: ($dynamicScheme . $dynamicHost));

// Application Secret Key for AES-256-GCM Encryption
define('APP_KEY', getenv('APP_KEY') ?: ($dbConfig['app_key'] ?? ''));

// Kern-Version (siehe CHANGELOG.md) - wird von App\Plugin\PluginManager gegen das
// 'core_compatibility'-Feld im plugin.json-Manifest jedes Plugins geprüft, bevor es
// geladen wird (siehe docs/plugin-development.md).
define('CORE_VERSION', '0.9.0-beta.4');

// In-Place-Selbstaktualisierung (#85). In einer klassischen/Shared-Hosting-
// Installation gehört der Code demselben Benutzer, unter dem PHP läuft - das
// Update darf ihn dann überschreiben (gleiche Vertrauensgrenze, kein
// Rechtegewinn). Im Container ist das anders: der Code gehört root, PHP läuft
// als www-data; ein durch den Web-Prozess beschreibbarer Codebaum wäre ein
// RCE-Verstärker (jede Schreib-Lücke würde persistent). Deshalb schaltet das
// offizielle Docker-Image UPDATE_IN_PLACE ab und aktualisiert stattdessen über
// ein neues Image (z. B. per Watchtower). Default: an (Shared-Hosting). Siehe
// #158 und docs/releasing.md.
define('UPDATE_IN_PLACE', getenv('UPDATE_IN_PLACE') !== false
    ? in_array(strtolower((string)getenv('UPDATE_IN_PLACE')), ['1', 'true', 'yes', 'on'], true)
    : true);

// Environment: Ist die Instanz ÜBERHAUPT konfiguriert, gilt sie ohne
// ausdrückliche Angabe als echte Installation - also 'production', keine
// Fehlerdetails an Besucher. Nur ein komplett unkonfigurierter Checkout
// (weder DB-Umgebungsvariablen noch db_config.php) gilt als lokale
// Entwicklungsumgebung.
//
// Die Prüfung hing früher allein an der Existenz von config/db_config.php.
// Damit fiel ausgerechnet der in der README als Variante A beschriebene Weg -
// Konfiguration rein über Umgebungsvariablen, wie im Container - auf
// 'development' zurück: display_errors an, und der erste PDO-Fehler zeigte
// dem Besucher DSN samt Datenbankbenutzer. Wer nach Anleitung installiert,
// darf nicht in der unsichereren Betriebsart landen.
$hasDbEnv = getenv('DB_HOST') !== false || getenv('DB_USER') !== false
    || getenv('DB_NAME') !== false || getenv('DB_PASS') !== false;
define('APP_ENV', getenv('APP_ENV')
    ?: ($dbConfig['app_env'] ?? (($hasDbEnv || file_exists($dbConfigFile)) ? 'production' : 'development')));

// Security Headers & Anti-Infostealer Session Security
if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        header("X-Content-Type-Options: nosniff");
        // X-Frame-Options wird seit #260 NUR noch hier gesetzt, nicht mehr
        // zusaetzlich in public/.htaccess. Grund: Der Header kann keine
        // Allowlist ausdruecken (ALLOW-FROM ist tot und wird von keinem
        // aktuellen Browser mehr ausgewertet), die Embed-Freigabe laeuft also
        // ueber CSP frame-ancestors. Eine Embed-Antwort muss X-Frame-Options
        // deshalb ENTFERNEN - und ein zusaetzliches 'Header set' in der
        // .htaccess wuerde es hinterher wieder setzen und die Freigabe still
        // aufheben. Eine Regel, eine Stelle.
        header("X-Frame-Options: SAMEORIGIN");
        // X-XSS-Protection bewusst auf "0", identisch zu public/.htaccess: Der
        // veraltete Browser-XSS-Filter (1; mode=block) kann selbst Lecks/DoS
        // ermöglichen und ist deprecated; OWASP empfiehlt "0" - der Schutz kommt
        // aus der CSP unten. Bis hierher sendete PHP noch den Altwert, den die
        // .htaccess unter Apache überschrieb - unter php -S (Tests, Dev) ging er
        // aber tatsächlich raus. Eine Regel, eine Aussage, an beiden Stellen gleich.
        header("X-XSS-Protection: 0");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        header("Permissions-Policy: camera=(), microphone=(), geolocation=()");

        // Content-Security-Policy: 'unsafe-inline' bei script-/style-src ist aktuell nötig,
        // da die Views durchgehend onclick=-Attribute und inline style= nutzen (kein
        // Nonce-/Hash-basiertes Setup). object-src/base-uri/form-action/frame-ancestors
        // bieten trotzdem echten Zusatzschutz ohne Änderungen an den Views.
        // TRACKING_DOMAINS wird nur bei aktiv konfiguriertem Tracking-Code angehängt -
        // ohne Konfiguration bleibt die Policy unverändert streng.
        // Aufbau der Policy liegt seit #260 in App\Security\ContentSecurityPolicy -
        // die Embed-Antwort muss denselben Header mit einem anderen
        // frame-ancestors erneut senden, und zwei getrennte Fassungen derselben
        // Policy waeren genau die Bauart, die auseinanderdriftet.
        header('Content-Security-Policy: ' . \App\Security\ContentSecurityPolicy::build());
    }

    if (session_status() === PHP_SESSION_NONE) {
        // Strict Session Configuration against Infostealers & Hijacking
        ini_set('session.use_strict_mode', 1);
        ini_set('session.use_only_cookies', 1);
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.cookie_lifetime', 0); // In-memory session cookie (no persistent disk storage)

        if (\App\Security\ClientIp::isHttps()) {
            ini_set('session.cookie_secure', 1);
            // W3C Cookie Prefix: __Host- enforces Secure, Path=/, and no Domain attribute
            session_name('__Host-HENGST_SESSID');
        } else {
            session_name('HENGST_SESSID');
        }

        session_start();
    }
}

// Fehlerbehandlung: In JEDER Umgebung wird alles protokolliert, angezeigt wird
// es nur in der Entwicklung. Siehe App\Service\ErrorHandler - dort steht auch,
// warum das frühere error_reporting(0) in Produktion nicht nur die Anzeige,
// sondern auch die Protokollierung abgeschaltet hat.
\App\Service\ErrorHandler::register(APP_ENV !== 'development');
