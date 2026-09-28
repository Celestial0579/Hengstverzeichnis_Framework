<?php
// tests/Unit/Views/ReleaseWorkflowTest.php

namespace Tests\Unit\Views;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\WorkflowSchritt;

/**
 * Schranken im Release-Workflow (#353, aus der adversarischen Prüfung).
 *
 * ## Warum der Workflow einen Test bekommt
 *
 * Er läuft genau dann, wenn man ihn am wenigsten beobachtet: beim Setzen
 * eines Tags. Ein Fehler dort fällt nicht in der CI auf, sondern bei einem
 * Betreiber — und zwar als etwas, das er nie angefordert hat.
 *
 * Zwei Befunde stehen dahinter:
 *
 * 1. **`:latest` wanderte auf jede Vorabversion.** Die Kanaltrennung gab es
 *    nur für den GitHub-Release (`prerelease`), nicht für das Docker-Tag. Wer
 *    laut README `:latest` für den produktiven Betrieb fährt und den in
 *    `docker-compose.yml` vorgeschlagenen Watchtower aktiviert hat, hätte
 *    beim nächsten Lauf eine Beta eingespielt bekommen.
 *
 * 2. **`workflow_dispatch` auf einem Branch ergab `version=main`.** Docker-Tags
 *    `main` und `latest`, ein Zip `...-main.zip`, und ein GitHub-Release mit
 *    `tag_name: main`, das einen Git-Tag gleichen Namens neben den Branch legt
 *    und das „Latest release"-Abzeichen bekommt — vor der eigentlichen Version.
 *
 * 3. **`:latest` wanderte auch rückwärts** (Audit N37). „Ohne '-'" traf auch
 *    ein Patch-Release einer älteren Linie und einen erneuten Lauf eines alten
 *    Tags. Watchtower-Installationen wären auf älteren Code zurückgefallen,
 *    gegen ein Schema, das sich nicht zurückrollen lässt. Seitdem entscheidet
 *    `is_latest` im Version-Step: nur die höchste Version der Form `vX.Y.Z`.
 */
class ReleaseWorkflowTest extends TestCase {

    private const VERSION_STEP = 'Determine version from tag';

    /** @var list<string> */
    private array $aufraeumen = [];

    private static function datei(): string {
        return dirname(__DIR__, 3) . '/.github/workflows/release.yml';
    }

    private static function workflow(): string {
        return (string)file_get_contents(self::datei());
    }

    protected function tearDown(): void {
        foreach ($this->aufraeumen as $pfad) {
            self::loeschen($pfad);
        }
        $this->aufraeumen = [];
    }

    /**
     * Temporäres Git-Repository mit einem leeren Commit und den Tags.
     *
     * @param list<string> $tags
     */
    private function repoMitTags(array $tags): string {
        $git = WorkflowSchritt::programm('git');
        if ($git === null) {
            $this->fail('Kein git im PATH - ein Umgebungsfehler ist kein Ergebnis.');
        }
        $repo = sys_get_temp_dir() . '/release-tags-' . bin2hex(random_bytes(6));
        mkdir($repo, 0700);
        $this->aufraeumen[] = $repo;

        $befehle = [
            [$git, 'init', '-q'],
            [$git, '-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', '-c', 'commit.gpgsign=false',
                'commit', '-q', '--allow-empty', '-m', 'leer'],
        ];
        foreach ($tags as $tag) {
            $befehle[] = [$git, '-c', 'tag.gpgsign=false', 'tag', $tag];
        }
        foreach ($befehle as $befehl) {
            $p = proc_open($befehl, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $repo,
                ['PATH' => (string)getenv('PATH'), 'HOME' => $repo, 'GIT_CONFIG_NOSYSTEM' => '1']);
            if (!is_resource($p) || proc_close($p) !== 0) {
                $this->fail('Git-Befehl fehlgeschlagen: ' . implode(' ', $befehl));
            }
        }
        return $repo;
    }

    private static function loeschen(string $pfad): void {
        if (is_link($pfad) || is_file($pfad)) {
            @unlink($pfad);
            return;
        }
        if (!is_dir($pfad)) {
            return;
        }
        foreach ((array)scandir($pfad) as $eintrag) {
            if ($eintrag !== '.' && $eintrag !== '..' && is_string($eintrag)) {
                self::loeschen($pfad . '/' . $eintrag);
            }
        }
        @rmdir($pfad);
    }

    /** `:latest` hängt an is_latest, nicht mehr an „ohne '-'" (N37). */
    public function testLatestNurFuerStabileVersionen(): void {
        $w = self::workflow();

        $this->assertMatchesRegularExpression(
            '/type=raw,value=latest,enable=\$\{\{\s*steps\.version\.outputs\.is_latest\s*==\s*\'true\'\s*\}\}/',
            $w,
            "Das Docker-Tag ':latest' muss an steps.version.outputs.is_latest == 'true' hängen.\n"
            . 'Sonst verschiebt jede Vorabversion oder jeder Backport das Tag, auf das '
            . 'Produktionsinstallationen zeigen.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/type=raw,value=latest\s*$/m',
            $w,
            "Es gibt noch ein unbedingtes ':latest'."
        );
        $this->assertStringNotContainsString(
            '!contains(steps.version.outputs.version',
            $w,
            "Die alte Bedingung \"ohne '-'\" ist wieder da - sie lässt Backports ':latest' zurückhängen."
        );
    }

    /**
     * „Latest release" auf GitHub folgt derselben Entscheidung - als Vergleich,
     * nicht als Rohwert: Ein fehlender Output ergäbe sonst einen leeren
     * String, und die API setzte für Nicht-Vorabversionen standardmäßig
     * „Latest" (fail-open).
     */
    public function testMakeLatestFolgtDerselbenEntscheidung(): void {
        $w = self::workflow();

        $this->assertMatchesRegularExpression(
            '/^\s*make_latest:\s*\$\{\{\s*steps\.version\.outputs\.is_latest\s*==\s*\'true\'\s*\}\}\s*$/m',
            $w,
            "make_latest muss als steps.version.outputs.is_latest == 'true' gesetzt sein."
        );
        $this->assertDoesNotMatchRegularExpression(
            '/make_latest:\s*\$\{\{\s*steps\.version\.outputs\.is_latest\s*\}\}/',
            $w,
            'make_latest als Rohwert fällt bei fehlendem Output auf "Latest" zurück.'
        );
    }

    /** Werte nur über env - der Version-Step bekommt keinen Ausdruck ins Skript. */
    public function testVersionsStepOhneAusdruecke(): void {
        $this->assertStringNotContainsString(
            '${{',
            WorkflowSchritt::runBlock(self::datei(), self::VERSION_STEP),
            'Der run-Block des Version-Steps enthält einen Ausdruck.'
        );
    }

    /**
     * Tags wie im echten Repository: Vorabversionen, eine ältere Linie,
     * ein Nicht-Versions-Tag.
     */
    private const TAGS = ['v0.8.0', 'v0.8.0-beta.1', 'v0.9.0', 'v0.9.0-beta.6', 'v0.10.0-beta.1', 'v0.8.1', 'foo'];

    /** @return array<string, array{string, list<string>, string, string}> */
    public static function tagFaelle(): array {
        return [
            'höchste stabile Version' => ['v0.9.0', [], 'true', '0.9.0'],
            'Backport-Patch einer älteren Linie' => ['v0.8.1', [], 'false', '0.8.1'],
            'erneuter Lauf eines alten Tags' => ['v0.8.0', [], 'false', '0.8.0'],
            'Vorabversion unter der höchsten' => ['v0.9.0-beta.6', [], 'false', '0.9.0-beta.6'],
            'Vorabversion über der höchsten' => ['v0.10.0-beta.1', [], 'false', '0.10.0-beta.1'],
            'neue Linie wird latest' => ['v0.10.0', ['v0.10.0'], 'true', '0.10.0'],
            'danach ist die alte Linie es nicht mehr' => ['v0.9.0', ['v0.10.0'], 'false', '0.9.0'],
            'nicht streng stabiles Tag bekommt kein latest' => ['v0.11rc1', ['v0.10.0', 'v0.11rc1'], 'false', '0.11rc1'],
            'nicht streng stabiles Tag blockiert latest nicht' => ['v0.10.0', ['v0.10.0', 'v0.11rc1'], 'true', '0.10.0'],
        ];
    }

    /** @param list<string> $zusatzTags */
    #[DataProvider('tagFaelle')]
    public function testLatestNurFuerDieHoechsteStabileVersion(string $ref, array $zusatzTags, string $erwartet, string $version): void {
        $repo = $this->repoMitTags(array_merge(self::TAGS, $zusatzTags));

        [$exit, $outputs, $ausgabe] = WorkflowSchritt::ausfuehren(
            WorkflowSchritt::runBlock(self::datei(), self::VERSION_STEP),
            ['GITHUB_REF_TYPE' => 'tag', 'GITHUB_REF_NAME' => $ref],
            $repo
        );

        $this->assertSame(0, $exit, $ausgabe);
        $this->assertSame($version, $outputs['version'] ?? null, $ausgabe);
        $this->assertSame($erwartet, $outputs['is_latest'] ?? null, "is_latest falsch für {$ref}.\n" . $ausgabe);
        if ($erwartet !== 'true') {
            $this->assertStringContainsString('::notice::', $ausgabe, 'Ein ausgelassenes latest braucht eine sichtbare Begründung.');
        }
    }

    public function testBranchLaufEndetOhneOutputs(): void {
        $repo = $this->repoMitTags(self::TAGS);

        [$exit, $outputs, $ausgabe] = WorkflowSchritt::ausfuehren(
            WorkflowSchritt::runBlock(self::datei(), self::VERSION_STEP),
            ['GITHUB_REF_TYPE' => 'branch', 'GITHUB_REF_NAME' => 'main'],
            $repo
        );

        $this->assertSame(1, $exit, $ausgabe);
        $this->assertSame([], $outputs, 'Ein Lauf auf einem Branch darf keine Version und kein is_latest setzen.');
    }

    /** Der Lauf muss auf einem Versions-Tag stehen, nicht auf einem Branch. */
    public function testVersionKommtNurAusEinemVersionsTag(): void {
        $w = self::workflow();

        $this->assertStringContainsString(
            'GITHUB_REF_TYPE',
            $w,
            'Der Workflow prüft nicht, ob er auf einem Tag läuft. Bei "Run workflow" '
            . 'wählt die Oberfläche den Default-Branch, und die Version wird dann "main".'
        );
        $this->assertMatchesRegularExpression(
            '/v\[0-9\]\*\)/',
            $w,
            'Es fehlt die Prüfung, dass der Tag-Name mit v und einer Ziffer beginnt.'
        );
    }

    /**
     * Die Kern-Listen müssen VOR dem Docker-Build entstehen — sonst nimmt
     * `COPY . .` sie nicht mit und die Prüfung ist im Container blind.
     */
    public function testKernListenEntstehenVorDemDockerBuild(): void {
        $w = self::workflow();

        $listen = strpos($w, 'Kern-Listen erzeugen');
        $docker = strpos($w, 'Build and push Docker image');

        $this->assertIsInt($listen, 'Der Schritt "Kern-Listen erzeugen" fehlt.');
        $this->assertIsInt($docker, 'Der Docker-Build-Schritt fehlt.');
        $this->assertLessThan(
            $docker,
            $listen,
            'Die Kern-Listen müssen vor dem Docker-Build erzeugt werden, sonst fehlen sie im Image.'
        );
    }

    /** Ohne volle Historie wäre die Beweisliste still unvollständig. */
    public function testDerCheckoutHoltDieVolleHistorie(): void {
        $this->assertStringContainsString(
            'fetch-depth: 0',
            self::workflow(),
            'scripts/kern-manifest.php liest aus den alten Tags. Ein flacher Klon kennt '
            . 'sie nicht - die Beweisliste wäre unvollständig, ohne dass es auffiele.'
        );
    }
}
