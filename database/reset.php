<?php
// database/reset.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Dieses Skript darf nur über die CLI ausgeführt werden.');
}

require_once __DIR__ . '/cli-autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Database.php';

use App\Database;
use App\Service\SystemReset;

echo "===============================================\n";
echo " Hengstverzeichnis Framework - CLI Reset Tool\n";
echo "===============================================\n";

try {
    $db = Database::getInstance();

    // Dieselbe Tabellenliste wie AdminController::resetSystem() (#451).
    // Das Audit-Log bleibt über Resets hinweg erhalten.
    SystemReset::truncateAll($db);

    $dbConfigFile = __DIR__ . '/../config/db_config.php';
    if (file_exists($dbConfigFile)) {
        @unlink($dbConfigFile);
    }

    echo "[SUCCESS] Das System und die Datenbank-Konfiguration wurden vollständig zurückgesetzt.\n";
    echo "Rufen Sie Ihre Domain im Browser auf (/setup), um das System neu einzurichten.\n";

} catch (Exception $e) {
    echo "[FEHLER] Zurücksetzen fehlgeschlagen: " . $e->getMessage() . "\n";
}
