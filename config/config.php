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
define('DB_SSL_VERIFY', getenv('DB_SSL_VERIFY') !== false ? in_array(getenv('DB_SSL_VERIFY'), ['true', '1'], true) : !empty($dbConfig['ssl_verify']));
define('DB_SSL_CA', getenv('DB_SSL_CA') ?: ($dbConfig['ssl_ca'] ?? ''));

// Application Base URL (dynamic resolution based on HTTP request or environment)
$dynamicScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$dynamicHost = $_SERVER['HTTP_HOST'] ?? 'hengstverzeichnis.de';
define('APP_URL', getenv('APP_URL') ?: ($dynamicScheme . $dynamicHost));

// Application Secret Key for AES-256-GCM Encryption
define('APP_KEY', getenv('APP_KEY') ?: ($dbConfig['app_key'] ?? ''));

// Environment
define('APP_ENV', getenv('APP_ENV') ?: 'development');

// Security Headers & Anti-Infostealer Session Security
if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        header("X-Content-Type-Options: nosniff");
        header("X-Frame-Options: SAMEORIGIN");
        header("X-XSS-Protection: 1; mode=block");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        header("Permissions-Policy: camera=(), microphone=(), geolocation=()");

        // Content-Security-Policy: 'unsafe-inline' bei script-/style-src ist aktuell nötig,
        // da die Views durchgehend onclick=-Attribute und inline style= nutzen (kein
        // Nonce-/Hash-basiertes Setup). object-src/base-uri/form-action/frame-ancestors
        // bieten trotzdem echten Zusatzschutz ohne Änderungen an den Views.
        header("Content-Security-Policy: " . implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline'",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com",
            "img-src 'self' data:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]));
    }

    if (session_status() === PHP_SESSION_NONE) {
        // Strict Session Configuration against Infostealers & Hijacking
        ini_set('session.use_strict_mode', 1);
        ini_set('session.use_only_cookies', 1);
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.cookie_lifetime', 0); // In-memory session cookie (no persistent disk storage)

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
        if ($isHttps) {
            ini_set('session.cookie_secure', 1);
            // W3C Cookie Prefix: __Host- enforces Secure, Path=/, and no Domain attribute
            session_name('__Host-HENGST_SESSID');
        } else {
            session_name('HENGST_SESSID');
        }

        session_start();
    }
}

// Basic Error Reporting (adjust for production)
if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}
