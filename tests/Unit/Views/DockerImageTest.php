<?php
// tests/Unit/Views/DockerImageTest.php

namespace Tests\Unit\Views;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Statische Schranken für das offizielle Docker-Image (Audit M31, N38, N41).
 *
 * Das Image selbst baut und prüft tests/docker/image-smoke.sh (CI-Job
 * "Docker-Image"). Hier steht, was sich ohne Docker prüfen lässt und bei
 * jeder Änderung am Dockerfile still kaputtgehen könnte:
 *  - var/ gehört www-data (N38), sonst greift der Wartungsmodus nicht und die
 *    Datenmigration kann nichts ablegen.
 *  - Die Upload-Sperren kommen aus einer Apache-Konfiguration im Image, nicht
 *    aus der .htaccess im Volume (N41) - und ihre Endungsliste driftet nicht
 *    von der .htaccess weg.
 *  - storage/horses ist als VOLUME deklariert, und zwar als LETZTE
 *    Anweisung (M31): Was danach noch dort hineingeschrieben würde, verwürfe
 *    Docker still.
 *  - .dockerignore hält Laufzeitreste heraus und die Sperren herein.
 */
class DockerImageTest extends TestCase {

    private static function datei(string $pfad): string {
        $voll = dirname(__DIR__, 3) . '/' . $pfad;
        self::assertFileExists($voll);

        return (string)file_get_contents($voll);
    }

    /**
     * Die Anweisungen des Dockerfiles der Laufzeit-Stufe: Kommentare raus,
     * Fortsetzungszeilen zusammengefügt.
     *
     * @return list<string>
     */
    private static function anweisungen(): array {
        $zeilen = [];
        $aktuell = '';
        foreach (preg_split('/\R/', self::datei('Dockerfile')) ?: [] as $zeile) {
            $getrimmt = trim($zeile);
            if ($getrimmt === '' || str_starts_with($getrimmt, '#')) {
                continue;
            }
            if (str_ends_with($getrimmt, '\\')) {
                $aktuell .= substr($getrimmt, 0, -1) . ' ';
                continue;
            }
            $zeilen[] = preg_replace('/\s+/', ' ', trim($aktuell . $getrimmt));
            $aktuell = '';
        }

        // Nur die letzte Stufe (das ausgelieferte Image).
        $letzteFrom = 0;
        foreach ($zeilen as $i => $z) {
            if (str_starts_with($z, 'FROM ')) {
                $letzteFrom = $i;
            }
        }

        return array_values(array_slice($zeilen, $letzteFrom));
    }

    private static function index(array $anweisungen, string $muster): int {
        foreach ($anweisungen as $i => $z) {
            if (preg_match($muster, $z) === 1) {
                return $i;
            }
        }
        self::fail("Keine Anweisung passt auf {$muster}");
    }

    public function testLaufzeitverzeichnisseSindFuerWwwDataBeschreibbar(): void {
        $a = self::anweisungen();
        $run = $a[self::index($a, '/^RUN .*chown -R www-data:www-data /')];

        preg_match('/mkdir -p ([^&]+)/', $run, $mkdir);
        preg_match('/chown -R www-data:www-data ([^&]+)/', $run, $chown);
        preg_match('/chmod -R u\+rwX ([^&]+)/', $run, $chmod);
        $this->assertNotEmpty($mkdir);
        $this->assertNotEmpty($chown);
        $this->assertNotEmpty($chmod);

        $angelegt = preg_split('/\s+/', trim($mkdir[1]));
        foreach (['plugins', 'storage/horses', 'storage/logs', 'var'] as $dir) {
            $this->assertContains($dir, $angelegt, "mkdir -p muss {$dir} anlegen");
        }
        foreach ([$chown[1], $chmod[1]] as $liste) {
            $dirs = preg_split('/\s+/', trim($liste));
            foreach (['public/uploads', 'plugins', 'storage', 'var'] as $dir) {
                $this->assertContains($dir, $dirs, "{$dir} muss www-data gehören und beschreibbar sein: {$run}");
            }
            $this->assertNotContains('.', $dirs, 'Der Code bleibt root - nie den ganzen Baum an www-data geben');
            $this->assertNotContains('src', $dirs);
            $this->assertNotContains('config', $dirs);
        }
    }

    public function testContainerKennungUndFotoVolume(): void {
        $a = self::anweisungen();

        $this->assertContains('ENV HV_CONTAINER=1', $a);
        $letzte = $a[array_key_last($a)];
        $this->assertSame('VOLUME ["/var/www/html/storage/horses"]', $letzte, 'VOLUME muss die letzte Anweisung sein');
        $this->assertLessThan(
            array_key_last($a),
            self::index($a, '/^RUN .*chown -R www-data:www-data /'),
            'Die Rechte müssen vor dem VOLUME stehen, sonst sieht Docker sie im Volume nicht'
        );
        $this->assertCount(1, array_filter($a, static fn(string $z) => str_starts_with($z, 'VOLUME ')), 'Genau ein VOLUME: kein Volume auf var/ (wartung.lock soll ein Neuerstellen nicht überleben)');
    }

    public function testUploadRegelnKommenAusDemImage(): void {
        $a = self::anweisungen();
        $docroot = self::index($a, '/^ENV APACHE_DOCUMENT_ROOT=\/var\/www\/html\/public$/');
        $sed = self::index($a, '/^RUN sed .*APACHE_DOCUMENT_ROOT/');
        $copyAlles = self::index($a, '/^COPY \. \.$/');
        $copyConf = self::index($a, '/^COPY docker\/apache-uploads\.conf \/etc\/apache2\/conf-available\/zz-uploads\.conf$/');
        $aktiviert = self::index($a, '/^RUN a2enconf zz-uploads$/');

        $this->assertGreaterThan($docroot, $copyConf);
        $this->assertGreaterThan($sed, $copyConf, 'Nach dem sed: Die Conf nennt ${APACHE_DOCUMENT_ROOT} selbst und soll nicht umgeschrieben werden');
        $this->assertGreaterThan($copyAlles, $copyConf);
        $this->assertGreaterThan($copyConf, $aktiviert);

        $conf = self::datei('docker/apache-uploads.conf');
        $this->assertMatchesRegularExpression(
            '~<Directory "\$\{APACHE_DOCUMENT_ROOT\}/uploads">.*?AllowOverride None.*?php_admin_flag engine off.*?</Directory>~s',
            $conf
        );
        $this->assertMatchesRegularExpression(
            '~<Directory "\$\{APACHE_DOCUMENT_ROOT\}/uploads/horses">\s*Require all denied\s*</Directory>~',
            $conf
        );
        $this->assertMatchesRegularExpression('~<FilesMatch "\^\\\\\.">\s*Require all denied~', $conf, 'Punktdateien bleiben gesperrt');
        $this->assertStringContainsString('Cross-Origin-Resource-Policy "same-origin"', $conf);
        $this->assertStringNotContainsString('AllowOverride All', $conf);
    }

    /** Die Liste gesperrter Endungen darf zwischen .htaccess und Conf nicht auseinanderlaufen. */
    public function testEndungslisteOhneDrift(): void {
        $muster = '~<FilesMatch "([^"]*php[^"]*)">~';
        $this->assertSame(1, preg_match($muster, self::datei('public/uploads/.htaccess'), $htaccess));
        $this->assertSame(1, preg_match($muster, self::datei('docker/apache-uploads.conf'), $conf));
        $this->assertSame($htaccess[1], $conf[1]);

        $bild = '~<FilesMatch "(\\\\\.\(jpe\?g[^"]*)">~';
        $this->assertSame(1, preg_match($bild, self::datei('public/uploads/.htaccess'), $htaccess));
        $this->assertSame(1, preg_match($bild, self::datei('docker/apache-uploads.conf'), $conf));
        $this->assertSame($htaccess[1], $conf[1]);
    }

    /**
     * Emuliert die Mustersemantik von .dockerignore (moby/patternmatcher):
     * Muster der Reihe nach, ein Muster trifft die Datei selbst oder einen
     * ihrer Elternordner, und der letzte Treffer entscheidet.
     */
    private static function imKontext(string $pfad): bool {
        $ausgeschlossen = false;
        foreach (preg_split('/\R/', self::datei('.dockerignore')) ?: [] as $zeile) {
            $muster = trim($zeile);
            if ($muster === '' || str_starts_with($muster, '#')) {
                continue;
            }
            $ausnahme = str_starts_with($muster, '!');
            $muster = trim(ltrim($ausnahme ? substr($muster, 1) : $muster, '/'));
            $muster = rtrim($muster, '/');

            $kandidaten = [$pfad];
            $teile = explode('/', $pfad);
            for ($i = count($teile) - 1; $i > 0; $i--) {
                $kandidaten[] = implode('/', array_slice($teile, 0, $i));
            }
            foreach ($kandidaten as $kandidat) {
                if (fnmatch($muster, $kandidat, FNM_PATHNAME)) {
                    $ausgeschlossen = !$ausnahme;
                    break;
                }
            }
        }

        return !$ausgeschlossen;
    }

    /** @return array<string, array{string, bool}> */
    public static function kontextFaelle(): array {
        return [
            'Wartungs-Marker' => ['var/wartung.lock', false],
            'datenmigration-Ablage' => ['var/datenmigration/export.zip', false],
            'var/.gitkeep' => ['var/.gitkeep', true],
            'Laufzeit-Log' => ['storage/logs/audit_errors.log', false],
            'storage/logs/.gitkeep' => ['storage/logs/.gitkeep', true],
            'Pferdefoto im Arbeitsbaum' => ['storage/horses/a.jpg', false],
            'storage/horses/.gitkeep' => ['storage/horses/.gitkeep', true],
            'Upload-Sperre' => ['public/uploads/.htaccess', true],
            'harte Sperre horses (#366)' => ['public/uploads/horses/.htaccess', true],
            'Altfoto im Webroot' => ['public/uploads/horses/x.jpg', false],
            'Logo' => ['public/uploads/branding/logo.png', false],
            'Apache-Conf' => ['docker/apache-uploads.conf', true],
            'Kern-Code' => ['src/Helper/ContainerAblage.php', true],
            'Geheimnisse' => ['config/db_config.php', false],
            'Tests' => ['tests/docker/image-smoke.sh', false],
        ];
    }

    #[DataProvider('kontextFaelle')]
    public function testDockerignore(string $pfad, bool $erwartet): void {
        $this->assertSame($erwartet, self::imKontext($pfad), $erwartet ? "{$pfad} muss ins Image" : "{$pfad} darf nicht ins Image");
    }

    public function testDockerVerzeichnisNichtImShoppingArchiv(): void {
        $this->assertStringContainsString("':!docker'", self::datei('.github/workflows/release.yml'));
    }

    public function testComposeMountetHorsesData(): void {
        $compose = self::datei('docker-compose.yml');
        $this->assertMatchesRegularExpression('~^\s+- horses_data:/var/www/html/storage/horses\s*$~m', $compose);
        $this->assertMatchesRegularExpression('~^\s+- uploads_data:/var/www/html/public/uploads\s*$~m', $compose);
        $this->assertMatchesRegularExpression('~^volumes:\n(?:\s+.*\n)*?\s+horses_data:\s*$~m', $compose);
        $this->assertDoesNotMatchRegularExpression('~^\s+- [\w_]+:/var/www/html/var\s*$~m', $compose, 'Kein Volume auf var/');
    }

    public function testRauchtestLaeuftInDerCi(): void {
        $workflow = self::datei('.github/workflows/tests.yml');
        $this->assertMatchesRegularExpression('~^  docker-image:\n(?:.*\n)*?\s+run: tests/docker/image-smoke\.sh\s*$~m', $workflow);
        $this->assertStringContainsString('workflow_call:', $workflow, 'Der Job muss auch im Release-Gate laufen');

        $job = substr($workflow, (int)strpos($workflow, "  docker-image:\n"));
        $this->assertStringContainsString('step-security/harden-runner@', $job);
        preg_match_all('~uses: (\S+)~', $job, $uses);
        foreach ($uses[1] as $action) {
            $this->assertMatchesRegularExpression('~@[0-9a-f]{40}$~', $action, "{$action} muss per SHA gepinnt sein");
        }

        $skript = dirname(__DIR__, 3) . '/tests/docker/image-smoke.sh';
        $this->assertFileExists($skript);
        $this->assertTrue(is_executable($skript), 'image-smoke.sh muss ausführbar sein');
        $inhalt = (string)file_get_contents($skript);
        $this->assertStringContainsString('set -euo pipefail', $inhalt);
        $this->assertStringContainsString('trap aufraeumen EXIT', $inhalt);
    }
}
