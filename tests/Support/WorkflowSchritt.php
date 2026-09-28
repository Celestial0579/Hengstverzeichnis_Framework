<?php
// tests/Support/WorkflowSchritt.php

namespace Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Liest einzelne Steps aus einer GitHub-Actions-Workflow-Datei und führt
 * deren `run:`-Block mit bash aus (Audit N5/N37).
 *
 * ## Warum kein YAML-Parser
 *
 * symfony/yaml ist keine Abhängigkeit des Projekts, und nur für Tests eine
 * weitere Bibliothek in `composer.lock` zu ziehen, lohnt nicht. Gebraucht
 * wird nur ein kleiner, klar umrissener Ausschnitt: ein Step, gefunden über
 * `- name: …`, und darin der Blockskalar `run: |`. Beides folgt in unseren
 * Workflows festen Einrückungsregeln. Findet der Helfer etwas nicht, schlägt
 * der Test mit Erklärung fehl, statt still einen leeren Block zu prüfen.
 *
 * ## Warum ausführen statt nur Textvergleich
 *
 * Die Logik in diesen Steps (Auto-Merge-Gate, `is_latest`) entscheidet über
 * Dinge, die man erst bemerkt, wenn es zu spät ist: einen ungeprüften Merge
 * nach `main`, ein zurückgehängtes `:latest`. Ein Regex auf den Text sagt
 * nichts darüber, ob das Skript tut, was der Kommentar behauptet.
 */
final class WorkflowSchritt {

    /**
     * Gesamter Text des Steps mit dem Namen `$schritt` (inklusive der Zeile
     * `- name:`), mit Originaleinrückung.
     */
    public static function text(string $datei, string $schritt): string {
        foreach (self::schritte($datei) as $name => $text) {
            if ($name === $schritt) {
                return $text;
            }
        }
        Assert::fail("Step '{$schritt}' nicht in {$datei} gefunden. Umbenannt? Dann auch den Test anpassen.");
    }

    /**
     * Alle Steps der Datei, Schlüssel ist der Wert von `name:`.
     *
     * @return array<string, string>
     */
    public static function schritte(string $datei): array {
        $zeilen = self::zeilen($datei);
        $ergebnis = [];
        $anzahl = count($zeilen);

        for ($i = 0; $i < $anzahl; $i++) {
            if (!preg_match('/^(\s*)- name:\s*(.+?)\s*$/', $zeilen[$i], $m)) {
                continue;
            }
            $einrueckung = strlen($m[1]);
            $name = trim($m[2], "\"'");
            $block = [$zeilen[$i]];
            for ($j = $i + 1; $j < $anzahl; $j++) {
                $z = $zeilen[$j];
                if (trim($z) !== '' && self::einrueckung($z) <= $einrueckung) {
                    break;
                }
                $block[] = $z;
            }
            $ergebnis[$name] = rtrim(implode("\n", $block)) . "\n";
        }

        return $ergebnis;
    }

    /**
     * Der `run: |`-Block des Steps, ohne gemeinsame Einrückung. `env:` und
     * andere Schlüssel dürfen davor oder danach stehen.
     */
    public static function runBlock(string $datei, string $schritt): string {
        $zeilen = explode("\n", self::text($datei, $schritt));
        $anzahl = count($zeilen);

        for ($i = 0; $i < $anzahl; $i++) {
            if (!preg_match('/^(\s*)(?:- )?run:\s*\|\s*$/', $zeilen[$i], $m)) {
                continue;
            }
            $schluessel = strlen($m[1]);
            $block = [];
            for ($j = $i + 1; $j < $anzahl; $j++) {
                $z = $zeilen[$j];
                if (trim($z) !== '' && self::einrueckung($z) <= $schluessel) {
                    break;
                }
                $block[] = $z;
            }
            while ($block !== [] && trim(end($block)) === '') {
                array_pop($block);
            }
            if ($block === []) {
                break;
            }
            $min = min(array_map(
                fn(string $z): int => self::einrueckung($z),
                array_filter($block, fn(string $z): bool => trim($z) !== '')
            ));
            return implode("\n", array_map(
                fn(string $z): string => trim($z) === '' ? '' : substr($z, $min),
                $block
            )) . "\n";
        }

        Assert::fail("Step '{$schritt}' in {$datei} hat keinen Blockskalar 'run: |'.");
    }

    /**
     * Führt `$skript` mit `bash -e -o pipefail` aus - so, wie GitHub Actions
     * einen run-Block ausführt.
     *
     * `$env` ersetzt die Umgebung vollständig (PATH wird aus dem Prozess
     * übernommen, wenn nicht angegeben; `$pfadVorne` wird davor gesetzt, etwa
     * für einen `gh`-Stub). GITHUB_OUTPUT zeigt auf eine frische Datei.
     *
     * @param array<string, string> $env
     * @return array{0: int, 1: array<string, string>, 2: string} Exit-Code, Outputs, Ausgabe (stdout+stderr)
     */
    public static function ausfuehren(string $skript, array $env, string $cwd, ?string $pfadVorne = null): array {
        $bash = self::bash();

        $skriptDatei = (string)tempnam(sys_get_temp_dir(), 'wf-skript-');
        $outputDatei = (string)tempnam(sys_get_temp_dir(), 'wf-output-');
        file_put_contents($skriptDatei, $skript);

        $pfad = $env['PATH'] ?? (string)getenv('PATH');
        if ($pfadVorne !== null) {
            $pfad = $pfadVorne . PATH_SEPARATOR . $pfad;
        }
        $env['PATH'] = $pfad;
        $env['GITHUB_OUTPUT'] = $outputDatei;
        $env += ['HOME' => sys_get_temp_dir(), 'LC_ALL' => 'C', 'GIT_CONFIG_NOSYSTEM' => '1'];

        $prozess = proc_open(
            [$bash, '-e', '-o', 'pipefail', $skriptDatei],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $cwd,
            $env
        );
        if (!is_resource($prozess)) {
            @unlink($skriptDatei);
            @unlink($outputDatei);
            Assert::fail('bash liess sich nicht starten - ein Umgebungsfehler ist kein Ergebnis.');
        }
        $ausgabe = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($prozess);

        $outputs = [];
        foreach (file($outputDatei, FILE_IGNORE_NEW_LINES) ?: [] as $zeile) {
            $pos = strpos($zeile, '=');
            if ($pos !== false) {
                $outputs[substr($zeile, 0, $pos)] = substr($zeile, $pos + 1);
            }
        }
        @unlink($skriptDatei);
        @unlink($outputDatei);

        return [$exit, $outputs, $ausgabe];
    }

    /** Absoluter Pfad eines Programms im PATH, oder null. */
    public static function programm(string $name): ?string {
        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $verzeichnis) {
            $kandidat = rtrim($verzeichnis, '/') . '/' . $name;
            if ($verzeichnis !== '' && is_file($kandidat) && is_executable($kandidat)) {
                return $kandidat;
            }
        }
        return null;
    }

    private static function bash(): string {
        $bash = self::programm('bash');
        if ($bash === null) {
            Assert::fail('Kein bash im PATH. Die Workflow-Skripte laufen auf dem Runner mit bash; '
                . 'ohne bash prüft dieser Test nichts - und ein Umgebungsfehler ist kein Ergebnis.');
        }
        return $bash;
    }

    /** @return list<string> */
    private static function zeilen(string $datei): array {
        $inhalt = @file_get_contents($datei);
        if ($inhalt === false) {
            Assert::fail("Workflow-Datei {$datei} nicht lesbar.");
        }
        return explode("\n", str_replace("\r\n", "\n", $inhalt));
    }

    private static function einrueckung(string $zeile): int {
        return strlen($zeile) - strlen(ltrim($zeile, ' '));
    }
}
