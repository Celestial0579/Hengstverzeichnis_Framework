<?php
// tests/Unit/Service/WebDavClientTest.php

namespace Tests\Unit\Service;

use App\Service\WebDavClient;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Audit N82: Basis-URL und hrefs werden gleich dekodiert.
     *
     * @return array<string, array{0: string, 1: array<int, string>}>
     */
    public static function kodierungsFaelle(): array {
        $basis = 'https://cloud.example.org/remote.php/dav/files/max%40verein.de/Meine%20Backups';
        return [
            'rohe @-hrefs' => [$basis, [
                '/remote.php/dav/files/max@verein.de/Meine Backups/backups/',
                '/remote.php/dav/files/max@verein.de/Meine Backups/backups/a.sql',
                '/remote.php/dav/files/max@verein.de/Meine Backups/backups/b.sql',
            ]],
            'kodierte hrefs' => [$basis, [
                '/remote.php/dav/files/max%40verein.de/Meine%20Backups/backups/',
                '/remote.php/dav/files/max%40verein.de/Meine%20Backups/backups/a.sql',
                '/remote.php/dav/files/max%40verein.de/Meine%20Backups/backups/b.sql',
            ]],
            'absolute hrefs' => [$basis, [
                'https://cloud.example.org/remote.php/dav/files/max%40verein.de/Meine%20Backups/backups/',
                'https://cloud.example.org/remote.php/dav/files/max%40verein.de/Meine%20Backups/backups/a.sql',
                'HTTPS://cloud.example.org:8443/remote.php/dav/files/max@verein.de/Meine Backups/backups/b.sql',
            ]],
            'Basis ohne Pfad' => ['https://dav.example.org', [
                '/backups/',
                '/backups/a.sql',
                '/backups/b.sql',
            ]],
            'Selbst-Eintrag ohne Schrägstrich' => ['https://dav.example.org/ziel', [
                '/ziel/backups',
                '/ziel/backups/a.sql',
                '/ziel/backups/b.sql',
                '/ziel/backups/unterordner/',
            ]],
        ];
    }

    /**
     * @param array<int, string> $hrefs
     */
    #[DataProvider('kodierungsFaelle')]
    public function testKeysFromHrefsFindetDieSchluessel(string $basis, array $hrefs): void {
        $this->assertSame(
            [['key' => 'backups/a.sql'], ['key' => 'backups/b.sql']],
            WebDavClient::keysFromHrefs($hrefs, $basis, 'backups')
        );
    }

    public function testAehnlicherOrdnernameWirdNichtGetroffen(): void {
        $keys = WebDavClient::keysFromHrefs([
            '/backups/backups/',
            '/backups/backups/a.sql',
            '/backups-alt/backups/x.sql',
        ], 'https://dav.example.org/backups', 'backups');

        $this->assertSame([['key' => 'backups/a.sql']], $keys);
    }

    public function testFremderPfadWirft(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('passt nicht zur eingetragenen Adresse');
        WebDavClient::keysFromHrefs([
            '/anderer/pfad/backups/',
            '/anderer/pfad/backups/a.sql',
        ], 'https://dav.example.org/remote.php/dav/files/verband', 'backups');
    }

    public function testLeereListeErgibtLeereListe(): void {
        $this->assertSame([], WebDavClient::keysFromHrefs([], 'https://dav.example.org/x', 'backups'));
    }
}
