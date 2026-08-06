<?php
// tests/Unit/Service/FtpsClientTest.php

namespace Tests\Unit\Service;

use App\Service\FtpsClient;
use PHPUnit\Framework\TestCase;

/**
 * Reiner Unit-Test ohne Netzwerk/DB/FTP-Extension für
 * FtpsClient::joinPath() (#93) - die Pfad-Verknüpfungslogik, isoliert von der
 * eigentlichen FTP-Übertragung (die die `ftp`-Extension voraussetzt und
 * daher hier nicht getestet wird).
 */
class FtpsClientTest extends TestCase {

    public function testJoinsBasePathAndKeyWithSingleSlash(): void {
        $this->assertSame('/backups/db-1.sql', FtpsClient::joinPath('backups', 'db-1.sql'));
    }

    public function testNormalizesDoubleSlashesBetweenBaseAndKey(): void {
        $this->assertSame('/backups/db-1.sql', FtpsClient::joinPath('/backups/', '/db-1.sql'));
    }

    public function testEmptyBasePathYieldsRootPrefixedKey(): void {
        $this->assertSame('/db-1.sql', FtpsClient::joinPath('', 'db-1.sql'));
    }

    public function testEmptyKeyYieldsBasePathAlone(): void {
        $this->assertSame('/backups', FtpsClient::joinPath('backups', ''));
    }

    public function testBothEmptyYieldsRoot(): void {
        $this->assertSame('/', FtpsClient::joinPath('', ''));
    }

    public function testMultiSegmentBasePathIsPreserved(): void {
        $this->assertSame(
            '/hengstverzeichnis-backups/2026/db-1.sql',
            FtpsClient::joinPath('/hengstverzeichnis-backups/2026/', 'db-1.sql')
        );
    }
}
