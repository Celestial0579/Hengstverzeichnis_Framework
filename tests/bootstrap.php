<?php
// tests/bootstrap.php
//
// PHPUnit-Bootstrap. Lädt bewusst NICHT config/config.php, da dieses eine
// Datenbankverbindung (bzw. für Integrationstests eine eigene Testdatenbank)
// voraussetzt und Security-Header/Sessions für PHP_SAPI 'cli' nicht relevant
// sind. Stattdessen werden hier nur die Konstanten definiert, die einzelne
// Klassen für Unit-Tests ohne vollständiges App-Bootstrapping benötigen.

require __DIR__ . '/../vendor/autoload.php';

// App\Security\Crypto::getKey() wirft bewusst eine Exception, wenn APP_KEY
// nicht gesetzt ist (Fail-Closed, siehe Kommentar dort) - für Tests daher ein
// fester, ausschließlich lokal in diesem Prozess bekannter Test-Schlüssel.
if (!defined('APP_KEY')) {
    define('APP_KEY', 'phpunit-test-key-not-for-production-use');
}

// tests/Integration/ ruft App\Database::getInstance() direkt in diesem Prozess auf
// und braucht dafür DB_HOST/DB_NAME/etc. als Konstanten (siehe src/Database.php).
// Nur definieren, wenn eine Test-Datenbank per Umgebungsvariable konfiguriert ist
// (CI setzt DB_HOST für den "Integration"-Suite-Lauf, siehe .github/workflows/tests.yml)
// - Unit-Tests fassen App\Database nie an und laufen unverändert ohne DB weiter.
// tests/Functional/ startet die App als eigenen php -S-Subprozess (siehe
// tests/Support/PhpBuiltInServer.php) und liest DB_* dort separat aus der
// Prozessumgebung - diese Konstanten hier werden von Functional-Tests nicht
// direkt benutzt, das Setzen ist aber harmlos.
if (getenv('DB_HOST') !== false && !defined('DB_HOST')) {
    define('DB_HOST', getenv('DB_HOST'));
    define('DB_PORT', getenv('DB_PORT') ?: '3306');
    define('DB_NAME', getenv('DB_NAME') ?: 'hengstverzeichnis_test');
    define('DB_USER', getenv('DB_USER') ?: 'root');
    define('DB_PASS', getenv('DB_PASS') ?: '');
    define('APP_ENV', getenv('APP_ENV') ?: 'development');
}
