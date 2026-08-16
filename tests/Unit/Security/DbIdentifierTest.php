<?php
// tests/Unit/Security/DbIdentifierTest.php

namespace Tests\Unit\Security;

use App\Security\DbIdentifier;
use PHPUnit\Framework\TestCase;

/**
 * Datenbankname, Host und Port sind die drei Werte des Setup-Wizards, die
 * nicht als Parameter gebunden werden können - der Name landet interpoliert
 * in `CREATE DATABASE`/`DROP DATABASE`, Host und Port im DSN. Die Regeln
 * dafür liegen deshalb an einer Stelle und sind hier festgenagelt.
 */
class DbIdentifierTest extends TestCase {

    public function testAcceptsOrdinaryDatabaseNames(): void {
        $this->assertTrue(DbIdentifier::isValidDatabaseName('hengstverzeichnis'));
        $this->assertTrue(DbIdentifier::isValidDatabaseName('hengst_prod_2026'));
        $this->assertTrue(DbIdentifier::isValidDatabaseName('DB1'));
    }

    /**
     * Der Backtick ist der eigentlich gefährliche: Der Name steht in
     * `DROP DATABASE \`$name\``, ein Backtick schließt den Bezeichner und
     * alles danach ist SQL.
     */
    public function testRejectsDatabaseNamesThatCouldBreakOutOfTheIdentifier(): void {
        $payloads = [
            'x`; DROP DATABASE `mysql',
            'test`',
            '`',
            'name with space',
            'name;drop',
            'name-mit-strich',
            "name\nzweite_zeile",
            'näme',
            '',
            str_repeat('a', 65),
        ];
        foreach ($payloads as $payload) {
            $this->assertFalse(
                DbIdentifier::isValidDatabaseName($payload),
                'Muss abgelehnt werden: ' . var_export($payload, true)
            );
        }
    }

    public function testAcceptsHostnamesAddressesAndSocketPaths(): void {
        $this->assertTrue(DbIdentifier::isValidHost('db'));
        $this->assertTrue(DbIdentifier::isValidHost('127.0.0.1'));
        $this->assertTrue(DbIdentifier::isValidHost('mariadb.intern.example.org'));
        $this->assertTrue(DbIdentifier::isValidHost('::1'));
        $this->assertTrue(DbIdentifier::isValidHost('[2001:db8::1]'));
        $this->assertTrue(DbIdentifier::isValidHost('/var/run/mysqld/mysqld.sock'));
    }

    /**
     * Semikolon und Gleichheitszeichen hängen weitere Parameter an den DSN -
     * damit ließe sich etwa die Verbindung auf einen anderen Socket umbiegen.
     */
    public function testRejectsHostsThatCouldExtendTheDsn(): void {
        $payloads = [
            'db;unix_socket=/tmp/angreifer.sock',
            'db;charset=latin1',
            'host=db',
            'db ',
            "db\n",
            '',
        ];
        foreach ($payloads as $payload) {
            $this->assertFalse(
                DbIdentifier::isValidHost($payload),
                'Muss abgelehnt werden: ' . var_export($payload, true)
            );
        }
    }

    public function testPortMustBeANumberInRange(): void {
        $this->assertTrue(DbIdentifier::isValidPort('3306'));
        $this->assertTrue(DbIdentifier::isValidPort('1'));
        $this->assertTrue(DbIdentifier::isValidPort('65535'));

        $this->assertFalse(DbIdentifier::isValidPort('0'));
        $this->assertFalse(DbIdentifier::isValidPort('65536'));
        $this->assertFalse(DbIdentifier::isValidPort('3306;x'));
        $this->assertFalse(DbIdentifier::isValidPort('abc'));
        $this->assertFalse(DbIdentifier::isValidPort(''));
    }
}
