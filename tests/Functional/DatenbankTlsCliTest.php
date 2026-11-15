<?php
// tests/Functional/DatenbankTlsCliTest.php

namespace Tests\Functional;

use PHPUnit\Framework\TestCase;

/**
 * GHSA-jpw7-6m4r-v92q auf der Kommandozeile: database/migrate.php beachtet
 * DB_SSL und DB_SSL_VERIFY, und ein entfernter Host fällt nicht still auf
 * einen lokalen Socket zurück.
 *
 * Jeder Fall läuft als eigener PHP-Prozess, weil die DB_*-Konstanten aus der
 * Umgebung kommen. Gegen die Datenbank der Functional-Suite; migrate.php ist
 * dort ein No-Op (Schema aktuell).
 */
class DatenbankTlsCliTest extends TestCase {

    protected function setUp(): void {
        if (getenv('DB_HOST') === false) {
            $this->markTestSkipped('Keine Test-Datenbank konfiguriert (DB_HOST fehlt).');
        }
    }

    public function testMigrateBeachtetDieZertifikatspruefung(): void {
        $this->brauchtTlsAmServer();

        // Mit Prüfung scheitert der Aufbau am selbstsignierten Zertifikat -
        // vor dem Fix lief migrate.php hier ohne TLS durch (Exit-Code 0).
        [$code, $ausgabe] = self::php(['database/migrate.php'], ['DB_SSL' => 'true', 'DB_SSL_VERIFY' => 'true']);
        $this->assertSame(1, $code, $ausgabe);

        // Gegenprobe: verschlüsselt ohne Prüfung geht es.
        [$code, $ausgabe] = self::php(['database/migrate.php'], ['DB_SSL' => 'true', 'DB_SSL_VERIFY' => 'false']);
        $this->assertSame(0, $code, $ausgabe);
    }

    public function testDieVerbindungDerKommandozeileIstVerschluesselt(): void {
        $this->brauchtTlsAmServer();
        $skript = 'require "database/cli-autoload.php"; require "config/config.php"; '
            . 'echo \App\Database::neueVerbindung()->query("SHOW SESSION STATUS LIKE \'Ssl_cipher\'")->fetch(PDO::FETCH_NUM)[1];';

        [$code, $ausgabe] = self::php(['-r', $skript], ['DB_SSL' => 'true', 'DB_SSL_VERIFY' => 'false']);

        $this->assertSame(0, $code, $ausgabe);
        $this->assertNotSame('', trim($ausgabe));
    }

    public function testEntfernterHostFaelltNichtAufDenLokalenSocketZurueck(): void {
        $socket = null;
        foreach (['/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock'] as $kandidat) {
            if (file_exists($kandidat)) {
                $socket = $kandidat;
            }
        }
        if ($socket === null) {
            $this->markTestSkipped('Kein lokaler MySQL-Socket - der Rückfall ist hier nicht nachstellbar.');
        }
        // Gegenprobe: Über den Socket kommen diese Zugangsdaten an.
        [$code, $ausgabe] = self::php(['-r', self::verbinde()], ['DB_HOST' => $socket]);
        if ($code !== 0) {
            $this->markTestSkipped("Der lokale Socket nimmt die Zugangsdaten der Suite nicht an: {$ausgabe}");
        }

        [$code, $ausgabe] = self::php(['-r', self::verbinde()], ['DB_HOST' => 'hv-rueckfall.invalid']);

        $this->assertNotSame(0, $code, 'Ein nicht erreichbarer entfernter Host darf nicht still über den lokalen Socket verbinden.');
        $this->assertStringNotContainsString('verbunden', $ausgabe);
    }

    private static function verbinde(): string {
        return 'require "database/cli-autoload.php"; require "config/config.php"; '
            . '\App\Database::neueVerbindung(); echo "verbunden";';
    }

    private function brauchtTlsAmServer(): void {
        [$code, $ausgabe] = self::php(['-r', 'require "database/cli-autoload.php"; require "config/config.php"; '
            . 'echo \App\Database::neueVerbindung()->query("SELECT @@have_ssl")->fetchColumn();'], []);
        if ($code !== 0 || trim($ausgabe) !== 'YES') {
            $this->markTestSkipped('Der Test-Server bietet kein TLS an.');
        }
    }

    /**
     * @param list<string> $argumente
     * @param array<string, string> $umgebung
     * @return array{0: int, 1: string}
     */
    private static function php(array $argumente, array $umgebung): array {
        $prozess = proc_open(
            array_merge([PHP_BINARY], $argumente),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            __DIR__ . '/../..',
            array_merge(getenv(), $umgebung)
        );
        fclose($pipes[0]);
        $ausgabe = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($prozess), $ausgabe];
    }
}
