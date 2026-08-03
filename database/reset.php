<?php
// database/reset.php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Database.php';

use App\Database;

echo "===============================================\n";
echo " Hengstverzeichnis Framework - CLI Reset Tool\n";
echo "===============================================\n";

try {
    $db = Database::getInstance();

    $db->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $db->exec("TRUNCATE TABLE horse_persons;");
    $db->exec("TRUNCATE TABLE breeding_stations;");
    $db->exec("TRUNCATE TABLE password_resets;");
    $db->exec("TRUNCATE TABLE gdpr_requests;");
    $db->exec("TRUNCATE TABLE audit_logs;");
    $db->exec("TRUNCATE TABLE horses;");
    $db->exec("TRUNCATE TABLE persons;");
    $db->exec("TRUNCATE TABLE users;");
    $db->exec("TRUNCATE TABLE settings;");
    $db->exec("SET FOREIGN_KEY_CHECKS = 1;");

    $dbConfigFile = __DIR__ . '/../config/db_config.php';
    if (file_exists($dbConfigFile)) {
        @unlink($dbConfigFile);
    }

    echo "[SUCCESS] Das System und die Datenbank-Konfiguration wurden vollständig zurückgesetzt.\n";
    echo "Rufen Sie Ihre Domain im Browser auf (/setup), um das System neu einzurichten.\n";

} catch (Exception $e) {
    echo "[FEHLER] Zurücksetzen fehlgeschlagen: " . $e->getMessage() . "\n";
}
