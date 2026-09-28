<?php
// tests/Unit/DatabaseDsnTest.php

namespace Tests\Unit;

use App\Database;
use PHPUnit\Framework\TestCase;

/**
 * Ein DSN-Helfer für Betrieb, Einrichtung und CLI-Skripte (Audit N55): Ein
 * Socketpfad als Host wird zu `unix_socket=`, sonst `host=`/`port=`. Ohne
 * Datenbankverbindung.
 */
class DatabaseDsnTest extends TestCase {

    public function testUnixSocket(): void {
        $this->assertSame(
            'mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=hv;charset=utf8mb4',
            Database::buildDsn('/var/run/mysqld/mysqld.sock', '3306', 'hv')
        );
    }

    public function testUnixSocketOhneDatenbankFuerDieEinrichtung(): void {
        $this->assertSame(
            'mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4',
            Database::buildDsn('/run/mysqld/mysqld.sock', '3306', null)
        );
    }

    public function testTcpMitDatenbank(): void {
        $this->assertSame(
            'mysql:host=127.0.0.1;port=3306;dbname=hv;charset=utf8mb4',
            Database::buildDsn('127.0.0.1', '3306', 'hv')
        );
    }

    public function testTcpOhneDatenbank(): void {
        $this->assertSame('mysql:host=db;port=3307;charset=utf8mb4', Database::buildDsn('db', '3307', null));
        $this->assertSame('mysql:host=db;port=3306;charset=utf8mb4', Database::buildDsn('db', '3306', ''));
    }

    public function testZeichensatz(): void {
        $this->assertSame('mysql:host=db;port=3306;dbname=hv;charset=latin1', Database::buildDsn('db', '3306', 'hv', 'latin1'));
    }
}
