<?php
// tests/Unit/Service/WebDavClientTest.php

namespace Tests\Unit\Service;

use App\Service\WebDavClient;
use PHPUnit\Framework\TestCase;

/**
 * Reiner Unit-Test ohne Netzwerk/DB für
 * WebDavClient::parsePropfindHrefs() (#93) - die PROPFIND-Antwort-Parsing-
 * Logik, isoliert von der eigentlichen HTTP-Übertragung (siehe
 * tests/Integration/WebDavClientTest.php für den Transport-Weg über den
 * Fake-WebDAV-Server), analog zu tests/Unit/Service/S3ClientSignatureTest.php.
 */
class WebDavClientTest extends TestCase {

    public function testExtractsHrefsWithLowercaseDNamespacePrefix(): void {
        $xml = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:">'
            . '<d:response><d:href>/backups/</d:href></d:response>'
            . '<d:response><d:href>/backups/file1.sql</d:href></d:response>'
            . '</d:multistatus>';

        $this->assertSame(['/backups/', '/backups/file1.sql'], WebDavClient::parsePropfindHrefs($xml));
    }

    public function testExtractsHrefsRegardlessOfNamespacePrefix(): void {
        // Manche Server (u. a. ownCloud-Varianten) nutzen andere Präfixe wie
        // "D:" oder "lp1:" statt "d:" - parsePropfindHrefs() muss namespace-
        // basiert (nicht Präfix-basiert) suchen.
        $xml = '<?xml version="1.0"?><D:multistatus xmlns:D="DAV:">'
            . '<D:response><D:href>/backups/file.sql</D:href></D:response>'
            . '</D:multistatus>';

        $this->assertSame(['/backups/file.sql'], WebDavClient::parsePropfindHrefs($xml));
    }

    public function testEmptyResponseBodyReturnsEmptyArray(): void {
        $this->assertSame([], WebDavClient::parsePropfindHrefs(''));
    }

    public function testMalformedXmlReturnsEmptyArrayInsteadOfThrowing(): void {
        $this->assertSame([], WebDavClient::parsePropfindHrefs('<nicht<gueltiges xml'));
    }

    public function testBlankHrefsAreSkipped(): void {
        $xml = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:">'
            . '<d:response><d:href>   </d:href></d:response>'
            . '<d:response><d:href>/backups/real.sql</d:href></d:response>'
            . '</d:multistatus>';

        $this->assertSame(['/backups/real.sql'], WebDavClient::parsePropfindHrefs($xml));
    }
}
