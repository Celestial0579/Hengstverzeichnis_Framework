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
