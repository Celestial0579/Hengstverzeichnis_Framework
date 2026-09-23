<?php
// tests/Unit/Service/SystemResetTest.php

namespace Tests\Unit\Service;

use App\Service\SystemReset;
use PHPUnit\Framework\TestCase;

/**
 * Hält die Tabellenliste des Werksresets vollständig (#451).
 *
 * TRUNCATE feuert kein ON DELETE CASCADE. Jede Tabelle, die per
 * Fremdschlüssel an einer geleerten Tabelle hängt, überlebte den Reset
 * sonst mit Zeilen, die nach der Neuvergabe der Kennungen einem fremden
 * Datensatz gehören - bei users bis hin zu Gruppenrechten, API-Schlüsseln
 * und Passkeys des Vorgängerkontos.
 */
class SystemResetTest extends TestCase {

    private const ROOT = __DIR__ . '/../../..';

    /**
     * @return array<string, list<string>> Tabelle => Tabellen, auf die sie per Fremdschlüssel zeigt
     */
    private static function foreignKeysFromSchema(): array {
        $sql = (string)file_get_contents(self::ROOT . '/database/schema.sql');
        preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?`?(\w+)`?\s*\((.*?)\)\s*ENGINE/s', $sql, $tables, PREG_SET_ORDER);

        $map = [];
        foreach ($tables as [, $name, $body]) {
            preg_match_all('/REFERENCES `?(\w+)`?/', $body, $refs);
            $map[$name] = array_values(array_unique($refs[1]));
        }
        return $map;
    }

    public function testJedeTabelleMitFremdschluesselAufGeleerteTabelleWirdMitgeleert(): void {
        $schema = self::foreignKeysFromSchema();
        $this->assertNotEmpty($schema, 'schema.sql wurde nicht gelesen');

        foreach ($schema as $table => $targets) {
            foreach ($targets as $target) {
                if (in_array($target, SystemReset::TABLES, true)) {
                    $this->assertContains(
                        $table,
                        SystemReset::TABLES,
                        "{$table} zeigt auf {$target}, das der Reset leert - ohne {$table} blieben verwaiste Zeilen stehen"
                    );
                }
            }
        }
    }

    public function testJedeGelisteteTabelleExistiertImSchema(): void {
        $schema = self::foreignKeysFromSchema();
        foreach (SystemReset::TABLES as $table) {
            $this->assertArrayHasKey($table, $schema, "{$table} steht nicht in database/schema.sql");
        }
    }

    public function testAuditLogUndGruppenBleibenErhalten(): void {
        $this->assertNotContains('audit_logs', SystemReset::TABLES);
        $this->assertNotContains('groups', SystemReset::TABLES);
        $this->assertNotContains('group_permissions', SystemReset::TABLES);
    }

    /**
     * Beide Reset-Wege nutzen dieselbe Liste - der CLI-Weg lief zuletzt
     * auseinander, weil jeder seine eigene TRUNCATE-Folge hatte.
     */
    public function testBeideResetWegeNutzenSystemReset(): void {
        foreach (['database/reset.php', 'src/Controllers/AdminController.php'] as $file) {
            $code = (string)file_get_contents(self::ROOT . '/' . $file);
            $this->assertStringContainsString('SystemReset::truncateAll(', $code, $file);
            $this->assertDoesNotMatchRegularExpression('/TRUNCATE\s+TABLE/i', $code, "{$file} leert Tabellen an SystemReset vorbei");
        }
    }
}
