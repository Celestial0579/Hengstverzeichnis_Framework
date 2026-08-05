<?php
// tests/Integration/S3ClientTest.php

namespace Tests\Integration;

use App\Service\S3Client;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeS3Server;

/**
 * Prüft App\Service\S3Client (#59) über echte HTTP-Anfragen gegen einen
 * lokalen S3-kompatiblen Mock-Server (tests/Support/fake-s3-server.php,
 * path-style). Deckt den Transport-Weg ab (URL-Aufbau, Stream-Kontext,
 * Response-Parsing), der von tests/Unit/Service/S3ClientSignatureTest.php
 * (reine Signaturberechnung ohne Netzwerk) bewusst nicht abgedeckt wird.
 * Braucht keine Datenbank - läuft daher unabhängig von DB_HOST.
 */
class S3ClientTest extends TestCase {

    private S3Client $client;

    public static function setUpBeforeClass(): void {
        FakeS3Server::ensureStarted();
    }

    protected function setUp(): void {
        $this->client = new S3Client(
            FakeS3Server::endpoint(),
            'us-east-1',
            'test-bucket',
            'AKIDEXAMPLE',
            'test-secret-key',
            pathStyle: true,
            useHttps: false
        );
    }

    public function testPutObjectThenListObjectsShowsIt(): void {
        $this->client->putObject('backups/db-1.sql', 'INSERT INTO test...');

        $objects = $this->client->listObjects('backups/');

        $keys = array_column($objects, 'key');
        $this->assertContains('backups/db-1.sql', $keys);
    }

    public function testPutObjectStoresBodyVerbatim(): void {
        $content = "Zeile 1\nZeile 2 mit Umlauten äöü\n";
        $this->client->putObject('backups/roundtrip.sql', $content);

        $storedFile = FakeS3Server::storageDir() . '/test-bucket__backups~roundtrip.sql';
        $this->assertFileExists($storedFile);
        $this->assertSame($content, file_get_contents($storedFile));
    }

    public function testDeleteObjectRemovesItFromListing(): void {
        $this->client->putObject('backups/to-delete.sql', 'x');
        $this->assertContains('backups/to-delete.sql', array_column($this->client->listObjects('backups/'), 'key'));

        $this->client->deleteObject('backups/to-delete.sql');

        $this->assertNotContains('backups/to-delete.sql', array_column($this->client->listObjects('backups/'), 'key'));
    }

    public function testListObjectsPrefixFiltersUnrelatedKeys(): void {
        $this->client->putObject('other/unrelated.txt', 'x');
        $this->client->putObject('backups/prefixed.sql', 'x');

        $keys = array_column($this->client->listObjects('backups/'), 'key');

        $this->assertContains('backups/prefixed.sql', $keys);
        $this->assertNotContains('other/unrelated.txt', $keys);
    }

    public function testMissingAuthorizationHeaderIsRejectedByServer(): void {
        // Direkter Aufruf ohne die vom Client gesetzten Signatur-Header, um zu
        // bestätigen, dass der Fake-Server tatsächlich prüft statt alles
        // anzunehmen (sonst wären die obigen Tests wertlos).
        $context = stream_context_create(['http' => ['method' => 'PUT', 'ignore_errors' => true]]);
        $body = @file_get_contents('http://' . FakeS3Server::endpoint() . '/test-bucket/unauthorized.txt', false, $context);

        $this->assertNotFalse($body);
        $statusLine = $http_response_header[0] ?? '';
        $this->assertStringContainsString('403', $statusLine);
    }
}
