<?php
// tests/Unit/Views/DependabotAutoMergeTest.php

namespace Tests\Unit\Views;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\WorkflowSchritt;

/**
 * Auto-Merge von Dependabot-PRs nur per Allowlist (Audit N5).
 *
 * ## Was schiefging
 *
 * Der Workflow aktivierte Auto-Merge für jedes Update, das nicht major war.
 * Das passte, solange `composer.json` nur PHPUnit kannte. Seit #353 liefert
 * der Kern aber `web-auth/webauthn-lib` samt Folgepaketen als `vendor/` aus,
 * im Passkey-Anmeldepfad - und deren Updates liefen ohne Durchsicht nach
 * `main`.
 *
 * ## Was die Tests festhalten
 *
 * - Der Merge-Step hängt am Gate, nicht mehr an der alten Major-Bedingung.
 * - Das Gate entscheidet fail-closed: nur Actions, Docker und reine
 *   `require-dev`-Bumps ohne Änderung am Laufzeitteil von `composer.lock`.
 * - Der Lock-Vergleich endet bei jedem Fehler mit false, ohne den Lauf rot
 *   zu machen.
 *
 * Die Skripte werden dafür aus der YAML gelesen und mit bash ausgeführt -
 * ein Textvergleich sagte nichts darüber, ob die Logik stimmt.
 */
class DependabotAutoMergeTest extends TestCase {

    private const GATE = 'Auto-Merge-Freigabe bestimmen';
    private const LOCK = 'Laufzeitteil von composer.lock vergleichen';

    private ?string $tmp = null;

    private static function datei(): string {
        return dirname(__DIR__, 3) . '/.github/workflows/dependabot-auto-merge.yml';
    }

    protected function tearDown(): void {
        if ($this->tmp !== null) {
            foreach (['bin/gh', 'basis.lock', 'kopf.lock'] as $f) {
                @unlink($this->tmp . '/' . $f);
            }
            @rmdir($this->tmp . '/bin');
            @rmdir($this->tmp);
            $this->tmp = null;
        }
    }

    private function brauchtJq(): void {
        if (WorkflowSchritt::programm('jq') === null) {
            $this->markTestSkipped('Kein jq im PATH - das Gate-Skript lässt sich nicht ausführen.');
        }
    }

    /** Der einzige Step, der `gh pr merge` aufruft, muss am Gate hängen. */
    public function testMergeHaengtAmGate(): void {
        $mergeSteps = array_filter(
            WorkflowSchritt::schritte(self::datei()),
            fn(string $text): bool => str_contains($text, 'gh pr merge')
        );

        $this->assertCount(1, $mergeSteps, 'Genau ein Step darf Auto-Merge aktivieren.');
        $this->assertMatchesRegularExpression(
            '/^\s*if:\s*steps\.gate\.outputs\.erlaubt\s*==\s*\'true\'\s*$/m',
            (string)reset($mergeSteps),
            "Der Merge-Step muss an 'steps.gate.outputs.erlaubt == 'true'' hängen. Ohne\n"
            . 'diese Bedingung werden auch Laufzeit-Updates (webauthn-lib) ungeprüft gemergt.'
        );
        $this->assertStringNotContainsString(
            "update-type != 'version-update:semver-major'",
            (string)file_get_contents(self::datei()),
            'Die alte Bedingung "alles außer Major" ist wieder da.'
        );
    }

    /** Werte nur über env - ein `${{ }}` im Skript wäre Skript-Injektion per PR-Metadaten. */
    public function testSkripteOhneAusdruecke(): void {
        foreach ([self::GATE, self::LOCK] as $schritt) {
            $this->assertStringNotContainsString(
                '${{',
                WorkflowSchritt::runBlock(self::datei(), $schritt),
                "Der run-Block von '{$schritt}' enthält einen Ausdruck. Werte bitte über env übergeben."
            );
        }
    }

    /** @return array<string, array{string, string, string, string, string}> */
    public static function gateFaelle(): array {
        $dev = '[{"dependencyName":"phpunit/phpunit","dependencyType":"direct:development","updateType":"version-update:semver-patch"}]';
        $devMajor = '[{"dependencyName":"phpunit/phpunit","dependencyType":"direct:development","updateType":"version-update:semver-major"}]';
        $prod = '[{"dependencyName":"web-auth/webauthn-lib","dependencyType":"direct:production","updateType":"version-update:semver-patch"}]';
        $indirekt = '[{"dependencyName":"spomky-labs/cbor-php","dependencyType":"indirect","updateType":"version-update:semver-patch"}]';
        $gemischt = '[{"dependencyName":"phpunit/phpunit","dependencyType":"direct:development"},'
            . '{"dependencyName":"web-auth/webauthn-lib","dependencyType":"direct:production"}]';
        $action = '[{"dependencyName":"actions/checkout","dependencyType":"direct:production","updateType":"version-update:semver-minor"}]';
        $docker = '[{"dependencyName":"php","dependencyType":"direct:production"}]';

        return [
            'composer dev patch, Lock unverändert' => ['composer', 'version-update:semver-patch', $dev, 'true', 'true'],
            'composer dev patch, Lock geändert' => ['composer', 'version-update:semver-patch', $dev, 'false', 'false'],
            'composer dev patch, Lock-Ergebnis fehlt' => ['composer', 'version-update:semver-patch', $dev, '', 'false'],
            'composer Laufzeit (webauthn-lib)' => ['composer', 'version-update:semver-patch', $prod, 'true', 'false'],
            'composer indirekt (cbor-php)' => ['composer', 'version-update:semver-patch', $indirekt, 'true', 'false'],
            'composer gemischt dev+production' => ['composer', 'version-update:semver-minor', $gemischt, 'true', 'false'],
            'composer leeres DEPS_JSON' => ['composer', 'version-update:semver-patch', '', 'true', 'false'],
            'composer leeres Array' => ['composer', 'version-update:semver-patch', '[]', 'true', 'false'],
            'composer dev major' => ['composer', 'version-update:semver-major', $devMajor, 'true', 'false'],
            'github_actions minor' => ['github_actions', 'version-update:semver-minor', $action, '', 'true'],
            'github_actions major' => ['github_actions', 'version-update:semver-major', $action, '', 'false'],
            'docker ohne update-type' => ['docker', '', $docker, '', 'true'],
            'npm_and_yarn' => ['npm_and_yarn', 'version-update:semver-patch', $dev, 'true', 'false'],
            'leeres Ökosystem' => ['', 'version-update:semver-patch', $dev, 'true', 'false'],
        ];
    }

    #[DataProvider('gateFaelle')]
    public function testGateEntscheidung(string $oekosystem, string $updateTyp, string $deps, string $lock, string $erwartet): void {
        $this->brauchtJq();

        [$exit, $outputs, $ausgabe] = WorkflowSchritt::ausfuehren(
            WorkflowSchritt::runBlock(self::datei(), self::GATE),
            [
                'ECOSYSTEM' => $oekosystem,
                'UPDATE_TYPE' => $updateTyp,
                'DEPS_JSON' => $deps,
                'LAUFZEIT_LOCK' => $lock,
            ],
            sys_get_temp_dir()
        );

        $this->assertSame(0, $exit, "Das Gate darf den Lauf nicht rot machen.\n" . $ausgabe);
        $this->assertSame($erwartet, $outputs['erlaubt'] ?? null, "Falsche Entscheidung.\n" . $ausgabe);
        if ($erwartet !== 'true') {
            $this->assertStringContainsString('::notice::', $ausgabe, 'Ein "nein" braucht eine sichtbare Begründung im Lauf.');
        }
    }

    /**
     * @return array<string, array{mixed, mixed, bool, string}>
     */
    public static function lockFaelle(): array {
        $basis = [
            'packages' => [
                ['name' => 'web-auth/webauthn-lib', 'version' => '5.2.3', 'dist' => ['reference' => 'aaa111']],
                ['name' => 'spomky-labs/cbor-php', 'version' => '3.1.0', 'dist' => ['reference' => 'bbb222']],
            ],
            'packages-dev' => [
                ['name' => 'phpunit/phpunit', 'version' => '12.0.1', 'dist' => ['reference' => 'ccc333']],
            ],
        ];
        $nurDev = $basis;
        $nurDev['packages-dev'][0]['version'] = '12.0.2';
        $nurDev['packages-dev'][0]['dist']['reference'] = 'ddd444';
        $laufzeit = $nurDev;
        $laufzeit['packages'][1]['version'] = '3.1.1';

        return [
            'nur packages-dev geändert' => [$basis, $nurDev, false, 'true'],
            'Laufzeitpaket mitgezogen' => [$basis, $laufzeit, false, 'false'],
            'gh scheitert' => [$basis, $nurDev, true, 'false'],
            'ungültiges JSON' => ['{kaputt', '{kaputt', false, 'false'],
            'ohne packages' => [['packages-dev' => []], ['packages-dev' => []], false, 'false'],
        ];
    }

    #[DataProvider('lockFaelle')]
    public function testLockVergleich(mixed $basis, mixed $kopf, bool $ghScheitert, string $erwartet): void {
        $this->brauchtJq();

        $this->tmp = sys_get_temp_dir() . '/dependabot-lock-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/bin', 0700, true);
        file_put_contents($this->tmp . '/basis.lock', is_string($basis) ? $basis : json_encode($basis));
        file_put_contents($this->tmp . '/kopf.lock', is_string($kopf) ? $kopf : json_encode($kopf));

        // Stub für `gh api`: liefert je nach ref= die passende Lockdatei.
        // Dass kein echter API-Aufruf passiert, prüft der Test gleich mit:
        // Ohne den Stub vorn im PATH gäbe es kein gültiges Ergebnis.
        $stub = <<<'SH'
            #!/bin/sh
            [ "${GH_STUB_FEHLER}" = "1" ] && { echo "HTTP 502" >&2; exit 1; }
            for a in "$@"; do
              case "$a" in
                *ref=basis-sha) cat "${GH_STUB_DIR}/basis.lock"; exit 0 ;;
                *ref=kopf-sha) cat "${GH_STUB_DIR}/kopf.lock"; exit 0 ;;
              esac
            done
            echo "unerwarteter Aufruf: $*" >&2
            exit 1
            SH;
        file_put_contents($this->tmp . '/bin/gh', $stub . "\n");
        chmod($this->tmp . '/bin/gh', 0700);

        [$exit, $outputs, $ausgabe] = WorkflowSchritt::ausfuehren(
            WorkflowSchritt::runBlock(self::datei(), self::LOCK),
            [
                'GH_TOKEN' => 'x',
                'REPO' => 'owner/repo',
                'BASE_SHA' => 'basis-sha',
                'HEAD_SHA' => 'kopf-sha',
                'GH_STUB_DIR' => $this->tmp,
                'GH_STUB_FEHLER' => $ghScheitert ? '1' : '0',
            ],
            $this->tmp,
            $this->tmp . '/bin'
        );

        $this->assertSame(0, $exit, "Der Lock-Vergleich darf den Lauf nicht rot machen - das Gate entscheidet.\n" . $ausgabe);
        $this->assertSame($erwartet, $outputs['laufzeit_unveraendert'] ?? null, "Falsches Ergebnis.\n" . $ausgabe);
    }
}
