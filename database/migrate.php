<?php
// database/migrate.php
//
// Dünner CLI-Wrapper um App\Service\SchemaMigrator (#230). Die eigentlichen
// Migrationsschritte leben ausschließlich in der Klasse - dieses Skript baut
// nur eine Verbindung auf, stößt den Lauf an und gibt die durchgeführten
// Schritte aus.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Dieses Skript darf nur über die CLI ausgeführt werden.');
}

require_once __DIR__ . '/cli-autoload.php';
require_once __DIR__ . '/../config/config.php';

use App\Service\SchemaMigrator;

echo "===============================================\n";
echo " Hengstverzeichnis Framework - DB Migration\n";
echo "===============================================\n";

try {
    // Bewusst eine EIGENE PDO-Verbindung statt Database::getInstance(): Der
    // Verbindungsaufbau dort führt die Migration bereits implizit aus
    // (ensureSchemaUpToDate) - dieses Skript könnte danach nur noch "nichts
    // zu tun" melden, statt die tatsächlich durchgeführten Schritte zu zeigen.
    if (strpos(DB_HOST, '/') === 0) {
        $dsn = 'mysql:unix_socket=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    } else {
        $port = defined('DB_PORT') ? DB_PORT : '3306';
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . $port . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    }
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $steps = SchemaMigrator::run($pdo);

    if ($steps === []) {
        echo '[OK] Schema bereits aktuell (Version ' . SchemaMigrator::storedVersion($pdo) . "), nichts zu tun.\n";
    } else {
        foreach ($steps as $step) {
            echo "[MIGRATION] {$step}\n";
        }
    }

    echo "===============================================\n";
    echo "[SUCCESS] Migration erfolgreich abgeschlossen!\n";
    echo "===============================================\n";
} catch (Throwable $e) {
    echo '[FEHLER] Migration fehlgeschlagen: ' . $e->getMessage() . "\n";
    // Fehler-Exitcode, damit Aufrufer (Deploy-Skripte, CI) den Fehlschlag
    // erkennen können - eine Meldung ohne Exitcode ist kein Gate (siehe
    // Lehre "pruefschritt-ohne-exitcode").
    exit(1);
}
