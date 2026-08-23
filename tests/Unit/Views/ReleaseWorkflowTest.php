<?php
// tests/Unit/Views/ReleaseWorkflowTest.php

namespace Tests\Unit\Views;

use PHPUnit\Framework\TestCase;

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
 */
class ReleaseWorkflowTest extends TestCase {

    private static function workflow(): string {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/.github/workflows/release.yml');
    }

    /** `:latest` darf nur ohne Suffix gesetzt werden. */
    public function testLatestNurFuerStabileVersionen(): void {
        $w = self::workflow();

        $this->assertMatchesRegularExpression(
            '/type=raw,value=latest,enable=\$\{\{\s*!contains\(steps\.version\.outputs\.version,\s*\'-\'\)\s*\}\}/',
            $w,
            "Das Docker-Tag ':latest' braucht eine enable-Bedingung. Ohne sie verschiebt\n"
            . 'jede Vorabversion das Tag, auf das Produktionsinstallationen zeigen.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/type=raw,value=latest\s*$/m',
            $w,
            "Es gibt noch ein unbedingtes ':latest'."
        );
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
