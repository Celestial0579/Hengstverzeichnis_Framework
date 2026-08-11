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

    /**
     * Der streamende Datei-Weg (#237) muss byte-identisch zum String-Weg
     * ankommen - geprüft mit Binärinhalt deutlich über der internen
     * 8-KiB-Blockgröße von stream_copy_to_stream() (siehe
     * App\Service\HttpFileUpload), damit der Upload tatsächlich über mehrere
     * Blöcke läuft.
     */
    public function testPutObjectFromFileStoresBodyByteIdenticalToPutObject(): void {
        $content = random_bytes(200_000);
        $file = tempnam(sys_get_temp_dir(), 'hv-test-upload-');
        file_put_contents($file, $content);

        try {
            $this->client->putObject('backups/string-weg.bin', $content);
            $this->client->putObjectFromFile('backups/datei-weg.bin', $file);
        } finally {
            unlink($file);
        }

        $viaString = file_get_contents(FakeS3Server::storageDir() . '/test-bucket__backups~string-weg.bin');
        $viaFile = file_get_contents(FakeS3Server::storageDir() . '/test-bucket__backups~datei-weg.bin');
        $this->assertSame($content, $viaFile);
        $this->assertSame($viaString, $viaFile);
    }

    /**
     * Fehlerpfad des Datei-Wegs (#237): Ein nicht erreichbares Ziel muss wie
     * beim String-Weg als RuntimeException enden (BackupService wertet das
     * als fehlgeschlagenen Lauf), nicht als stilles Nichtstun.
     */
    public function testPutObjectFromFileAgainstUnreachableEndpointThrows(): void {
        $client = new S3Client('127.0.0.1:1', 'us-east-1', 'test-bucket', 'AKIDEXAMPLE', 'test-secret-key', pathStyle: true, useHttps: false);
        $file = tempnam(sys_get_temp_dir(), 'hv-test-upload-');
        file_put_contents($file, 'x');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Verbindung fehlgeschlagen');
            $client->putObjectFromFile('backups/unerreichbar.bin', $file);
        } finally {
            unlink($file);
        }
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
