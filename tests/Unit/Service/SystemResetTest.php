<?php
// tests/Unit/Service/SystemResetTest.php

namespace Tests\Unit\Service;

use App\Service\SystemReset;
use PHPUnit\Framework\TestCase;

/**
 * Hält die Tabellenliste des Werksresets vollständig (#451, Audit N79).
 *
 * Der Reset leert unter FOREIGN_KEY_CHECKS = 0, und dann feuert weder
 * TRUNCATE noch DELETE ein ON DELETE CASCADE. Jede Tabelle, die per
 * Fremdschlüssel an einer geleerten Tabelle hängt, überlebte den Reset
 * sonst mit verwaisten Zeilen, die einem späteren Datensatz mit derselben
 * Kennung zugeschlagen würden - bei users bis hin zu Gruppenrechten,
 * API-Schlüsseln und Passkeys des Vorgängerkontos.
 *
 * Seit Audit N79 leert der Reset per DELETE, damit die Zähler weiterlaufen;
 * das Verhalten gegen eine echte Datenbank prüft
 * tests/Integration/SystemResetTest.php.
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

    /**
     * Die Addon-Tabellen erkennt der Reset am Präfix. Trüge eine
     * Kerntabelle es, verschwände sie mit den Addon-Daten - oder, schlimmer,
     * das Präfix wäre keine Grenze mehr.
     */
    public function testKeinKerntabellennameTraegtDasAddonPraefix(): void {
        $sql = (string)file_get_contents(self::ROOT . '/database/schema.sql');
        preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?`?(\w+)`?/i', $sql, $treffer);
        $this->assertNotEmpty($treffer[1]);
        foreach ($treffer[1] as $name) {
            $this->assertStringStartsNotWith(\App\Plugin\PluginDataRegistry::PRAEFIX, $name, "Kerntabelle {$name} trägt das Addon-Präfix");
        }
        $this->assertSame('plugin_', \App\Plugin\PluginDataRegistry::PRAEFIX);
        $this->assertContains('login_attempts', SystemReset::TABLES);
        $this->assertNotContains('plugins', SystemReset::TABLES);
        $this->assertNotContains('addon_repos', SystemReset::TABLES);
    }

    /**
     * Kein TRUNCATE mehr im Code von SystemReset (Audit N79): TRUNCATE stellt
     * den AUTO_INCREMENT zurück, und die Kennungen würden neu vergeben.
     * Geprüft wird über die Tokens, Kommentare zählen nicht - der
     * Klassenkommentar erklärt TRUNCATE ausdrücklich.
     */
    public function testResetLeertOhneZaehlerRueckstellung(): void {
        $code = (string)file_get_contents(self::ROOT . '/src/Service/SystemReset.php');
        $literale = [];
        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $literale[] = $token[1];
            }
        }
        $alle = implode("\n", $literale);
        $this->assertDoesNotMatchRegularExpression('/TRUNCATE\s+TABLE/i', $alle);
        $this->assertMatchesRegularExpression('/DELETE\s+FROM/i', $alle);
    }
}
