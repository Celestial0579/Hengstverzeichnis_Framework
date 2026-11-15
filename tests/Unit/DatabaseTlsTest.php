<?php
// tests/Unit/DatabaseTlsTest.php

namespace Tests\Unit;

use App\Database;
use PHPUnit\Framework\TestCase;

/**
 * GHSA-jpw7-6m4r-v92q, ohne Datenbank: Woher die TLS-Angabe kommt, wenn
 * DB_SSL_CA leer ist, und wann der Rückfall auf lokale Sockets gilt. Die
 * Wirkung an einem echten Server prüft tests/Integration/DatabaseTlsTest.php.
 */
class DatabaseTlsTest extends TestCase {

    private string $ordner = '';

    protected function setUp(): void {
        $this->ordner = sys_get_temp_dir() . '/hv_tls_' . bin2hex(random_bytes(4));
        mkdir($this->ordner . '/ini_dir', 0700, true);
        mkdir($this->ordner . '/env_dir', 0700, true);
        mkdir($this->ordner . '/std_dir', 0700, true);
        foreach (['ini.pem', 'env.pem', 'std.pem'] as $datei) {
            file_put_contents($this->ordner . '/' . $datei, "-----BEGIN CERTIFICATE-----\n");
        }
    }

    protected function tearDown(): void {
        foreach (['ini.pem', 'env.pem', 'std.pem'] as $datei) {
            @unlink($this->ordner . '/' . $datei);
        }
        foreach (['ini_dir', 'env_dir', 'std_dir', ''] as $verz) {
            @rmdir($this->ordner . '/' . $verz);
        }
    }

    /** @return array<string, string> */
    private function orte(array $ueber = []): array {
        return $ueber + [
            'default_cert_file' => $this->ordner . '/std.pem',
            'default_cert_file_env' => 'SSL_CERT_FILE',
            'default_cert_dir' => $this->ordner . '/std_dir',
            'default_cert_dir_env' => 'SSL_CERT_DIR',
            'ini_cafile' => '',
            'ini_capath' => '',
        ];
    }

    private static function ohneUmgebung(): callable {
        return static fn(string $name): string => '';
    }

    public function testReihenfolgeWiePhpSelbst(): void {
        $this->brauchtPdoMysql();
        $ca = \Pdo\Mysql::ATTR_SSL_CA;
        $pfad = \Pdo\Mysql::ATTR_SSL_CAPATH;
        $env = fn(string $name): string => match ($name) {
            'SSL_CERT_FILE' => $this->ordner . '/env.pem',
            'SSL_CERT_DIR' => $this->ordner . '/env_dir',
            default => '',
        };

        $this->assertSame([$ca => $this->ordner . '/ini.pem'],
            Database::zertifikatsspeicher($this->orte(['ini_cafile' => $this->ordner . '/ini.pem']), $env));
        $this->assertSame([$pfad => $this->ordner . '/ini_dir'],
            Database::zertifikatsspeicher($this->orte(['ini_capath' => $this->ordner . '/ini_dir']), $env));
        $this->assertSame([$ca => $this->ordner . '/env.pem'], Database::zertifikatsspeicher($this->orte(), $env));
        $this->assertSame([$ca => $this->ordner . '/std.pem'],
            Database::zertifikatsspeicher($this->orte(), self::ohneUmgebung()));
        $this->assertSame([$pfad => $this->ordner . '/std_dir'],
            Database::zertifikatsspeicher($this->orte(['default_cert_file' => $this->ordner . '/fehlt.pem']), self::ohneUmgebung()));
    }

    public function testOhneSpeicherGibtEsKeineAngabe(): void {
        $this->brauchtPdoMysql();
        $nichts = $this->orte(['default_cert_file' => $this->ordner . '/fehlt.pem', 'default_cert_dir' => $this->ordner . '/fehlt']);

        $this->assertSame([], Database::zertifikatsspeicher($nichts, self::ohneUmgebung()));
    }

    public function testOhneCaDateiKommtTrotzdemEineTlsAngabe(): void {
        $this->brauchtPdoMysql();
        if (Database::zertifikatsspeicher(openssl_get_cert_locations(), static fn(string $n): string => (string)getenv($n)) === []) {
            $this->markTestSkipped('Dieses System hat keinen Zertifikatsspeicher.');
        }

        $optionen = Database::tlsOptionen(true, '', false);

        // Gegenprobe zum Stand vor GHSA-jpw7-6m4r-v92q: Dort stand hier nur
        // ATTR_SSL_VERIFY_SERVER_CERT, und mysqlnd forderte kein TLS an.
        $this->assertNotEmpty(array_intersect_key($optionen, [\Pdo\Mysql::ATTR_SSL_CA => 1, \Pdo\Mysql::ATTR_SSL_CAPATH => 1]));
        $this->assertFalse($optionen[\Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT]);
    }

    public function testSocketRueckfallNurZumEigenenRechner(): void {
        foreach (['localhost', 'LOCALHOST', '127.0.0.1', '::1', '[::1]'] as $host) {
            $this->assertTrue(Database::socketRueckfallErlaubt($host), $host);
        }
        foreach (['db', 'db.example.org', '10.0.0.5', '127.0.0.2', '/run/mysqld/mysqld.sock', ''] as $host) {
            $this->assertFalse(Database::socketRueckfallErlaubt($host), $host);
        }
    }

    private function brauchtPdoMysql(): void {
        if (!class_exists(\Pdo\Mysql::class)) {
            $this->markTestSkipped('pdo_mysql ist nicht geladen.');
        }
    }
}
