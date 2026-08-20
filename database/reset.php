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

echo "===============================================\n";
echo " Hengstverzeichnis Framework - CLI Reset Tool\n";
echo "===============================================\n";

try {
    $db = Database::getInstance();

    // Audit-Log bleibt über Resets hinweg erhalten (analog zu AdminController::resetSystem())
    $db->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $db->exec("TRUNCATE TABLE horse_persons;");
    $db->exec("TRUNCATE TABLE horse_registrations;");
    $db->exec("TRUNCATE TABLE password_resets;");
    $db->exec("TRUNCATE TABLE gdpr_requests;");
    $db->exec("TRUNCATE TABLE horses;");
    // contact_id_map gehört mit geleert (#336): Sie bildet alte Personen-/
    // Stationskennungen auf Kontakte ab. TRUNCATE feuert kein ON DELETE
    // CASCADE, ihre Zeilen überlebten den Reset also und zeigten danach auf
    // Kennungen, die die neue Installation frisch vergibt.
    $db->exec("TRUNCATE TABLE contact_id_map;");
    $db->exec("TRUNCATE TABLE contacts;");
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
