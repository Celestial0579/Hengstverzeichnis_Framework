<?php
// tests/Integration/DatabaseTlsTest.php

namespace Tests\Integration;

use App\Database;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * GHSA-jpw7-6m4r-v92q gegen einen echten Server: DB_SSL ohne CA-Datei muss
 * verschlüsseln, und eine verlangte Prüfung muss wirklich prüfen.
 *
 * Braucht einen Server mit TLS. MariaDB ab 11.4 erzeugt sich dafür selbst ein
 * Zertifikat (selbstsigniert, also für keine CA des Systems gültig) - genau
 * das nutzt der Prüffall. Ohne TLS am Server überspringt sich die Klasse.
 */
class DatabaseTlsTest extends TestCase {

    public static function setUpBeforeClass(): void {
        if (!defined('DB_HOST')) {
            self::markTestSkipped('Keine Test-Datenbank konfiguriert (DB_HOST fehlt) - siehe tests/bootstrap.php.');
        }
        $have = (string)self::verbindung([])->query("SELECT @@have_ssl")->fetchColumn();
        if ($have !== 'YES') {
            self::markTestSkipped("Der Test-Server bietet kein TLS an (have_ssl = {$have}).");
        }
    }

    public function testOhneCaDateiIstDieVerbindungVerschluesselt(): void {
        $db = self::verbindung(Database::tlsOptionen(true, '', false));

        $this->assertNotSame('', self::cipher($db));
    }

    /** Gegenprobe: die Optionen, die der Kern bis zum Fix übergab. */
    public function testNurDerPruefschalterVerschluesseltNicht(): void {
        $db = self::verbindung([\Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false]);

        $this->assertSame('', self::cipher($db));
    }

    public function testEineVerlangtePruefungLehntEinSelbstsigniertesZertifikatAb(): void {
        $warnungen = [];
        set_error_handler(static function (int $nr, string $text) use (&$warnungen): bool {
            $warnungen[] = $text;
            return true;
        }, E_WARNING);
        try {
            self::verbindung(Database::tlsOptionen(true, '', true));
            $this->fail('Mit DB_SSL_VERIFY darf ein selbstsigniertes Server-Zertifikat nicht durchgehen.');
        } catch (PDOException $e) {
            $this->addToAssertionCount(1);
        } finally {
            restore_error_handler();
        }
    }

    /** @param array<int, mixed> $optionen */
    private static function verbindung(array $optionen): PDO {
        return new PDO(
            Database::buildDsn((string)DB_HOST, (string)DB_PORT, (string)DB_NAME),
            DB_USER,
            DB_PASS,
            $optionen + [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private static function cipher(PDO $db): string {
        return (string)($db->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_NUM)[1] ?? '');
    }
}
