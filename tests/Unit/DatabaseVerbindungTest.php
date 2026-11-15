<?php
// tests/Unit/DatabaseVerbindungTest.php

namespace Tests\Unit;

use App\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Was am Verbindungsaufbau ohne Datenbank prüfbar ist: die TLS-Optionen
 * (#520) und die Absage an persistente Verbindungen (#519). Der Aufbau selbst
 * steht in tests/Integration/DatabaseNeueVerbindungTest.php.
 */
class DatabaseVerbindungTest extends TestCase {

    public function testOhneTlsBleibenDieOptionenLeer(): void {
        $this->assertSame([], Database::tlsOptionen(false, '/etc/ssl/eigene-ca.pem', true));
    }

    public function testCaDateiUndPruefungKommenOhneVeralteteKonstantenAn(): void {
        $this->brauchtPdoMysql();

        [$optionen, $meldungen] = $this->mitMeldungen(
            static fn(): array => Database::tlsOptionen(true, '/etc/ssl/eigene-ca.pem', true)
        );

        $this->assertSame([], $meldungen, 'Der Bau der TLS-Optionen darf keine Deprecation auslösen (#520).');
        $this->assertSame(
            [
                \Pdo\Mysql::ATTR_SSL_CA => '/etc/ssl/eigene-ca.pem',
                \Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true,
            ],
            $optionen
        );
    }

    public function testEineAbgeschaltetePruefungWirdAusdruecklichUebergeben(): void {
        $this->brauchtPdoMysql();

        $optionen = Database::tlsOptionen(true, '/etc/ssl/eigene-ca.pem', false);

        // Nicht weggelassen, sondern false: Mit CA-Datei prüft der Treiber sonst von sich aus.
        $this->assertArrayHasKey(\Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT, $optionen);
        $this->assertFalse($optionen[\Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT]);
    }

    /**
     * Gegenprobe zu testCaDateiUndPruefungKommenOhneVeralteteKonstantenAn():
     * Die Konstante, die der Kern bis #520 benutzt hat, löst unter PHP 8.5
     * genau die Meldung aus, die jener Test ausschließt.
     */
    public function testDieFruehereKonstanteLoestEineDeprecationAus(): void {
        $this->brauchtPdoMysql();
        if (!defined('PDO::MYSQL_ATTR_SSL_CA')) {
            $this->markTestSkipped('PDO::MYSQL_ATTR_SSL_CA gibt es in dieser PHP-Version nicht mehr.');
        }

        [, $meldungen] = $this->mitMeldungen(static fn(): mixed => constant('PDO::MYSQL_ATTR_SSL_CA'));

        $this->assertCount(1, $meldungen);
        $this->assertStringContainsString('MYSQL_ATTR_SSL_CA', $meldungen[0]);
    }

    public function testEinePersistenteVerbindungWirdAbgelehnt(): void {
        // Vor jedem Verbindungsversuch: Der Test läuft ohne Datenbank und ohne DB_*-Konstanten.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PDO::ATTR_PERSISTENT');

        Database::neueVerbindung([PDO::ATTR_PERSISTENT => true]);
    }

    private function brauchtPdoMysql(): void {
        if (!class_exists(\Pdo\Mysql::class)) {
            $this->markTestSkipped('pdo_mysql ist nicht geladen - ohne den Treiber gibt es keine TLS-Optionen.');
        }
    }

    /**
     * Führt $aufruf aus und sammelt die Deprecations, die er auslöst.
     *
     * @return array{0: mixed, 1: list<string>}
     */
    private function mitMeldungen(callable $aufruf): array {
        $meldungen = [];
        set_error_handler(static function (int $nr, string $text) use (&$meldungen): bool {
            $meldungen[] = $text;
            return true;
        }, E_DEPRECATED | E_USER_DEPRECATED);
        try {
            $ergebnis = $aufruf();
        } finally {
            restore_error_handler();
        }

        return [$ergebnis, $meldungen];
    }
}
