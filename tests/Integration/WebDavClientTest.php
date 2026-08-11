<?php
// tests/Integration/WebDavClientTest.php

namespace Tests\Integration;

use App\Service\WebDavClient;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeWebDavServer;

/**
 * Prüft App\Service\WebDavClient (#93) über echte HTTP-Anfragen gegen einen
 * lokalen WebDAV-Mock-Server (tests/Support/fake-webdav-server.php) - analog
 * zu tests/Integration/S3ClientTest.php, da echte Nextcloud-/ownCloud-
 * Zugangsdaten in dieser Umgebung nicht verfügbar sind. Deckt den
 * Transport-Weg ab (URL-Aufbau, automatisches Anlegen des Zielordners,
 * PROPFIND-Response-Parsing), der von der reinen, netzwerkfreien
 * parsePropfindHrefs()-Logik (siehe tests/Unit/Service/WebDavClientTest.php)
 * bewusst nicht abgedeckt wird. Braucht keine Datenbank - läuft daher
 * unabhängig von DB_HOST.
 */
class WebDavClientTest extends TestCase {

    private WebDavClient $client;

    public static function setUpBeforeClass(): void {
        FakeWebDavServer::ensureStarted();
    }

    protected function setUp(): void {
        $this->client = new WebDavClient(
            FakeWebDavServer::baseUrl() . '/test-target',
            'testuser',
            'testpass'
        );
    }

    public function testPutObjectCreatesTargetCollectionAutomatically(): void {
        // putObject() muss den Zielordner selbst per MKCOL anlegen, falls er
        // noch nicht existiert (siehe WebDavClient::ensureParentCollectionExists())
        // - unabhängig davon, ob eine andere Testmethode dieser Klasse ihn
        // (denselben Fake-Server-Prozess/-Speicherordner, siehe setUpBeforeClass())
        // bereits zuvor angelegt hat, MKCOL auf einem bestehenden Ordner wird
        // dort als 405 abgefangen und ignoriert.
        $this->client->putObject('backups/db-1.sql', 'INSERT INTO test...');

        $this->assertDirectoryExists(FakeWebDavServer::storageDir() . '/test-target/backups');
        $this->assertFileExists(FakeWebDavServer::storageDir() . '/test-target/backups/db-1.sql');
    }

    public function testPutObjectStoresBodyVerbatim(): void {
        $content = "Zeile 1\nZeile 2 mit Umlauten äöü\n";
        $this->client->putObject('backups/roundtrip.sql', $content);

        $this->assertSame($content, file_get_contents(FakeWebDavServer::storageDir() . '/test-target/backups/roundtrip.sql'));
    }

    /**
     * Der streamende Datei-Weg (#237) muss byte-identisch zum String-Weg
     * ankommen - geprüft mit Binärinhalt deutlich über der internen
     * 8-KiB-Blockgröße von stream_copy_to_stream() (siehe
     * App\Service\HttpFileUpload), damit der Upload tatsächlich über mehrere
     * Blöcke läuft. Legt den Zielordner wie putObject() selbst per MKCOL an.
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

        $storage = FakeWebDavServer::storageDir() . '/test-target/backups';
        $this->assertSame($content, file_get_contents($storage . '/datei-weg.bin'));
        $this->assertSame(file_get_contents($storage . '/string-weg.bin'), file_get_contents($storage . '/datei-weg.bin'));
    }

    public function testListObjectsShowsUploadedFileButNotTheFolderItself(): void {
        $this->client->putObject('backups/listed.sql', 'x');

        $objects = $this->client->listObjects('backups');

        $keys = array_column($objects, 'key');
        $this->assertContains('backups/listed.sql', $keys);
        $this->assertNotContains('backups', $keys, 'Der Ordner selbst darf nicht als Objekt auftauchen');
    }

    public function testDeleteObjectRemovesItFromListing(): void {
        $this->client->putObject('backups/to-delete.sql', 'x');
        $this->assertContains('backups/to-delete.sql', array_column($this->client->listObjects('backups'), 'key'));

        $this->client->deleteObject('backups/to-delete.sql');

        $this->assertNotContains('backups/to-delete.sql', array_column($this->client->listObjects('backups'), 'key'));
    }

    public function testDeletingAlreadyMissingObjectDoesNotThrow(): void {
        $this->client->deleteObject('backups/nie-hochgeladen.sql');
        $this->addToAssertionCount(1); // keine Exception = bestanden
    }

    public function testListObjectsOnNeverCreatedFolderReturnsEmptyArray(): void {
        $this->assertSame([], $this->client->listObjects('ordner-der-nie-existiert-hat'));
    }
}
