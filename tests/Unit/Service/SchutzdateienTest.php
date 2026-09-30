<?php
// tests/Unit/Service/SchutzdateienTest.php

namespace Tests\Unit\Service;

use App\Service\Schutzdateien;
use PHPUnit\Framework\TestCase;

/**
 * Die Schutzdateien unter public/uploads (Audit N19) und der
 * Migrationsschritt, der sie auf Bestandsinstallationen herstellt.
 */
class SchutzdateienTest extends TestCase {

    private string $uploads;

    protected function setUp(): void {
        $this->uploads = sys_get_temp_dir() . '/' . uniqid('hengst_uploads_');
        mkdir($this->uploads, 0755, true);
    }

    protected function tearDown(): void {
        @chmod($this->uploads, 0755);
        @unlink($this->uploads . '/horses/.htaccess');
        @rmdir($this->uploads . '/horses');
        @unlink($this->uploads . '/.htaccess');
        foreach (glob($this->uploads . '/{,.}*', GLOB_BRACE) ?: [] as $rest) {
            if (is_file($rest)) {
                @unlink($rest);
            }
        }
        @rmdir($this->uploads);
    }

    /**
     * Drift-Schutz: Die Konstanten sind die ausgelieferten Dateien, Byte für
     * Byte. Wer eine der Dateien ändert, muss die Konstante mitziehen (und
     * die bisherige Fassung als Altfassung aufnehmen).
     */
    public function testKonstantenEntsprechenDenDateienImRepo(): void {
        $wurzel = dirname(__DIR__, 3);
        $this->assertSame(file_get_contents($wurzel . '/public/uploads/.htaccess'), Schutzdateien::UPLOADS);
        $this->assertSame(file_get_contents($wurzel . '/public/uploads/horses/.htaccess'), Schutzdateien::HORSES);
    }

    public function testFehlendeWerdenAngelegtAuchHorses(): void {
        $meldungen = Schutzdateien::sicherstellen($this->uploads, $this->uploads . '/horses');

        $this->assertIsArray($meldungen);
        $this->assertCount(2, $meldungen);
        $this->assertSame(Schutzdateien::UPLOADS, file_get_contents($this->uploads . '/.htaccess'));
        $this->assertSame(Schutzdateien::HORSES, file_get_contents($this->uploads . '/horses/.htaccess'));
        $this->assertSame([], glob($this->uploads . '/.htaccess.*.tmp'), 'Keine Temp-Reste');
    }

    public function testAktuelleFassungenErgebenKeineMeldung(): void {
        mkdir($this->uploads . '/horses');
        file_put_contents($this->uploads . '/.htaccess', Schutzdateien::UPLOADS);
        file_put_contents($this->uploads . '/horses/.htaccess', Schutzdateien::HORSES);

        $this->assertSame([], Schutzdateien::sicherstellen($this->uploads, $this->uploads . '/horses'));
    }

    /**
     * Die Fassung aus v0.7.1 bis v0.8.0-beta.2 - mit dem falsch herum
     * wirkenden Referer-Filter (#367) -, einmal per FTP im ASCII-Modus
     * hochgeladen (CRLF). Ein roher Hash erkennte sie nicht; sie muss
     * trotzdem ersetzt werden.
     */
    public function testBekannteAltfassungWirdAuchMitCrlfErsetzt(): void {
        $alt = $this->altfassungV071();
        file_put_contents($this->uploads . '/.htaccess', str_replace("\n", "\r\n", $alt) . "\r\n\r\n");
        mkdir($this->uploads . '/horses');
        file_put_contents($this->uploads . '/horses/.htaccess', Schutzdateien::HORSES);

        $meldungen = Schutzdateien::sicherstellen($this->uploads, $this->uploads . '/horses');

        $this->assertSame(['Schutzdatei public/uploads/.htaccess auf die aktuelle Fassung gebracht'], $meldungen);
        $this->assertSame(Schutzdateien::UPLOADS, file_get_contents($this->uploads . '/.htaccess'));
    }

    public function testFremderInhaltBleibtUndWirdGemeldet(): void {
        file_put_contents($this->uploads . '/.htaccess', "# eigene Regeln\nRequire all granted\n");

        $meldungen = Schutzdateien::sicherstellen($this->uploads, $this->uploads . '/horses');

        $this->assertIsArray($meldungen);
        $this->assertStringContainsString('NICHT ersetzt', implode("\n", $meldungen));
        $this->assertSame("# eigene Regeln\nRequire all granted\n", file_get_contents($this->uploads . '/.htaccess'));
        $this->assertSame(Schutzdateien::HORSES, file_get_contents($this->uploads . '/horses/.htaccess'));
    }

    /**
     * Nicht beschreibbar -> null (kein Marker im Migrationsschritt). Als
     * root greifen Dateirechte nicht; deshalb liegt dort, wo das
     * horses-Verzeichnis hin müsste, eine DATEI - mkdir scheitert immer.
     */
    public function testNichtBeschreibbarLiefertNull(): void {
        file_put_contents($this->uploads . '/horses', 'im Weg');

        $this->assertNull(Schutzdateien::sicherstellen($this->uploads, $this->uploads . '/horses'));
        @unlink($this->uploads . '/horses');
    }

    private function altfassungV071(): string {
        // Unverändert aus `git show v0.7.1:public/uploads/.htaccess`; ihr
        // normalisierter Hash steht in Schutzdateien::ALTFASSUNGEN.
        $inhalt = file_get_contents(__DIR__ . '/../../Support/uploads-htaccess-v0.7.1.txt');
        $this->assertNotFalse($inhalt);
        return $inhalt;
    }
}
