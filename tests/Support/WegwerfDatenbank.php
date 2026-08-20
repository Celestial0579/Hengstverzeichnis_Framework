<?php
// tests/Support/WegwerfDatenbank.php

namespace Tests\Support;

/**
 * Namen der Wegwerf-Datenbanken, die einzelne Integrationstests selbst anlegen
 * und wieder löschen.
 *
 * WOZU ÜBERHAUPT KONFIGURIERBAR. Zwei Tests brauchen eine eigene Datenbank
 * neben der regulären Test-Datenbank: DumpAndRestoreTest spielt einen Dump in
 * ein zweites Schema ein, SchemaMigratorTest hebt ein leeres Schema stufenweise
 * an. Beide legen sie mit `CREATE DATABASE` selbst an - und hießen bis v0.8
 * fest `hengst_dumper_restore_target` und `hengst_schema_migrator`.
 *
 * In der CI ist das kein Problem: Dort läuft die Suite als Datenbank-Benutzer
 * mit weiten Rechten. Auf einem gemeinsam genutzten Entwicklungshost ist es
 * eines. Dort hat der Entwicklungs-Benutzer typischerweise Rechte auf einem
 * Namensraum (`dev_%`) und sonst nichts - `CREATE DATABASE hengst_…` scheitert
 * mit "Access denied", und zwar bei ALLEN Tests der Klasse gleichzeitig.
 *
 * Das Ergebnis sah bisher aus wie ein kaputter Test und war ein Rechteproblem.
 * Der naheliegende Ausweg - dem Benutzer die Rechte geben - ändert die
 * Umgebung für alle, die auf demselben Host arbeiten, und muss hinterher
 * zurückgenommen werden. Einmal vergessen, und die Rechte bleiben.
 *
 * Deshalb ein Präfix aus der Umgebung:
 *
 *     HV_TEST_DB_PREFIX=dev_v0_8_0_ composer test -- --testsuite Integration
 *
 * Ohne die Variable bleibt alles wie bisher (`hengst_`), die CI merkt also
 * nichts davon.
 */
final class WegwerfDatenbank {

    private const PRAEFIX_STANDARD = 'hengst_';

    private function __construct() {}

    /**
     * Vollständiger Datenbankname für einen Zweck, z. B. 'schema_migrator'.
     *
     * Der Zweck wird auf das eingeschränkt, was in einem Datenbanknamen ohne
     * Anführungszeichen stehen darf - er landet in `CREATE DATABASE`, und ein
     * Testname ist zwar kein Angreifer, aber die Stelle ist dieselbe.
     */
    public static function name(string $zweck): string {
        $zweck = preg_replace('/[^a-z0-9_]/', '', strtolower($zweck)) ?? '';
        if ($zweck === '') {
            throw new \InvalidArgumentException('Leerer Zweck für eine Wegwerf-Datenbank.');
        }

        $praefix = (string)(getenv('HV_TEST_DB_PREFIX') ?: self::PRAEFIX_STANDARD);
        $praefix = preg_replace('/[^a-z0-9_]/', '', strtolower($praefix)) ?? self::PRAEFIX_STANDARD;

        return $praefix . $zweck;
    }
}
