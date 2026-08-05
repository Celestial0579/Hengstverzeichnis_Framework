<?php
// src/Service/DatabaseDumper.php

namespace App\Service;

use App\Database;
use PDO;

/**
 * Class DatabaseDumper
 *
 * Reine-PHP-Alternative zu `mysqldump` (#59): erzeugt einen vollständig
 * wiederherstellbaren SQL-Dump über PDO, ohne auf ein `mysqldump`-Client-
 * Binary im Container/Hosting angewiesen zu sein (im mitgelieferten
 * Dockerfile nicht installiert, auf klassischem Webhosting oft nicht
 * verfügbar oder `shell_exec` gesperrt) - passend zur "keine externen
 * Abhängigkeiten"-Philosophie des Kerns.
 */
final class DatabaseDumper {

    /**
     * Erzeugt einen vollständigen SQL-Dump (Schema + Daten) aller Tabellen
     * der aktuellen Datenbank. Tabellen werden vor dem Neuanlegen gelöscht
     * (`DROP TABLE IF EXISTS`) und Fremdschlüssel-Prüfungen für die Dauer
     * des Imports deaktiviert, damit die Wiederherstellungsreihenfolge
     * unabhängig von Fremdschlüssel-Abhängigkeiten funktioniert.
     */
    public static function dump(): string {
        $pdo = Database::getInstance();
        $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();

        $lines = [];
        $lines[] = '-- Automatisches Backup (#59) - ' . gmdate('Y-m-d H:i:s') . ' UTC';
        $lines[] = '-- Datenbank: ' . $dbName;
        $lines[] = 'SET FOREIGN_KEY_CHECKS=0;';
        $lines[] = 'SET NAMES utf8mb4;';
        $lines[] = '';

        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $lines[] = self::dumpTable($pdo, $table);
        }

        $lines[] = 'SET FOREIGN_KEY_CHECKS=1;';

        return implode("\n", $lines);
    }

    private static function dumpTable(PDO $pdo, string $table): string {
        $quotedTable = '`' . str_replace('`', '``', $table) . '`';

        $createStmt = $pdo->query("SHOW CREATE TABLE {$quotedTable}")->fetch();
        $createSql = $createStmt['Create Table'] ?? $createStmt[1] ?? '';

        $lines = [];
        $lines[] = "-- Tabelle: {$table}";
        $lines[] = "DROP TABLE IF EXISTS {$quotedTable};";
        $lines[] = "{$createSql};";

        $stmt = $pdo->query("SELECT * FROM {$quotedTable}");
        $columns = null;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($columns === null) {
                $columns = array_map(fn($col) => '`' . str_replace('`', '``', $col) . '`', array_keys($row));
            }
            $values = array_map(
                fn($value) => $value === null ? 'NULL' : $pdo->quote((string)$value),
                array_values($row)
            );
            $lines[] = "INSERT INTO {$quotedTable} (" . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ');';
        }

        $lines[] = '';
        return implode("\n", $lines);
    }
}
