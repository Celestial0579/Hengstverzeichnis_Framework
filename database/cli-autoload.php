<?php
// database/cli-autoload.php
//
// App-Autoloader für die CLI-Skripte in diesem Verzeichnis - MUSS vor
// config/config.php geladen werden: Die Konfiguration nutzt inzwischen
// selbst App-Klassen (App\Security\ClientIp/TrustedHost für die
// APP_URL-Herleitung), genau wie es public/index.php für den Web-Weg
// vormacht. Ohne Autoloader brachen migrate.php/seed.php/reset.php mit
// "Class App\Security\ClientIp not found" ab (Fund aus #230).
// Gleiche Minimal-Implementierung wie in public/index.php - bewusst kein
// Composer: Die Anwendung läuft ohne vendor/ (siehe docs/development.md).

spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $base_dir = __DIR__ . '/../src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});
