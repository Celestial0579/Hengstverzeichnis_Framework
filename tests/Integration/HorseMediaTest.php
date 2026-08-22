<?php
// tests/Integration/HorseMediaTest.php

namespace Tests\Integration;

use App\Database;
use App\Service\HorseMedia;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Fotos und Video-Links je Pferd (#339).
 *
 * Der heikle Teil ist nicht das Anlegen, sondern die Kopplung an
 * `horses.image_url`: Katalogkarte, Admin-Liste, Startseite, JSON-API und
 * drei Addons lesen diese Spalte. Wer sie aus dem Tritt bringt, merkt es
 * nicht an einer Fehlermeldung, sondern daran, dass im Katalog Bilder
 * fehlen - oder falsche stehen.
 */
class HorseMediaTest extends TestCase {

    private static PDO $db;
    private int $horseId;

    public static function setUpBeforeClass(): void {
        if (!defined('DB_HOST')) {
            self::markTestSkipped('Keine Test-Datenbank konfiguriert (DB_HOST fehlt) - siehe tests/bootstrap.php.');
        }

        $setupPdo = new PDO(
            "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $setupPdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        foreach ($setupPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $setupPdo->exec("DROP TABLE IF EXISTS `$table`");
        }
        $setupPdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        try {
            $setupPdo->exec(file_get_contents(__DIR__ . '/../../database/schema.sql'));
        } catch (PDOException $e) {
            // Ignorieren, analog zu SetupController::provision()
        }

        self::$db = Database::getInstance();
    }

    protected function setUp(): void {
        self::$db->exec('DELETE FROM horse_media');
        self::$db->exec('DELETE FROM horses');
        self::$db->exec("INSERT INTO horses (name, is_published) VALUES ('Testhengst', 1)");
        $this->horseId = (int)self::$db->lastInsertId();
    }

    private function bild(string $name, ?int $sort = null): int {
        return HorseMedia::hinzufuegen($this->horseId, '/uploads/horses/' . $name, null, null, $sort);
    }

    private function bildUrl(): ?string {
        $stmt = self::$db->prepare('SELECT image_url FROM horses WHERE id = ?');
        $stmt->execute([$this->horseId]);
        $wert = $stmt->fetchColumn();

        return $wert === false ? null : $wert;
    }

    /**
     * Das erste Bild wird von selbst zum Hauptbild. Ohne das stuende ein
     * Pferd mit Fotos im Katalog ohne Bild da - `image_url` bliebe leer,
     * obwohl Bilder vorhanden sind.
     */
    public function testDasErsteBildWirdHauptbildUndFuelltImageUrl(): void {
        $id = $this->bild('eins.jpg');

        $this->assertGreaterThan(0, $id);
        $this->assertTrue(HorseMedia::hatHauptbild($this->horseId));
        $this->assertSame('/uploads/horses/eins.jpg', $this->bildUrl());
    }

    public function testEinZweitesBildAendertDasHauptbildNicht(): void {
        $this->bild('eins.jpg');
        $this->bild('zwei.jpg');

        $this->assertSame('/uploads/horses/eins.jpg', $this->bildUrl());
        $this->assertSame(
            1,
            (int)self::$db->query('SELECT COUNT(*) FROM horse_media WHERE is_main = 1')->fetchColumn(),
            'Es darf immer nur EIN Hauptbild geben.'
        );
    }

    public function testDasHauptbildLaesstSichWechselnUndBleibtEindeutig(): void {
        $this->bild('eins.jpg');
        $zwei = $this->bild('zwei.jpg');

        $this->assertTrue(HorseMedia::setzeHauptbild($this->horseId, $zwei));
        $this->assertSame('/uploads/horses/zwei.jpg', $this->bildUrl());
        $this->assertSame(1, (int)self::$db->query('SELECT COUNT(*) FROM horse_media WHERE is_main = 1')->fetchColumn());
    }

    /**
     * Ein Video kann kein Hauptbild sein - `horses.image_url` traegt eine
     * Bilddatei, und der Katalog wuerde daraus ein kaputtes <img> bauen.
     */
    public function testEinVideoKannNichtHauptbildWerden(): void {
        $this->bild('eins.jpg');
        $video = HorseMedia::hinzufuegen($this->horseId, null, 'https://vimeo.com/12345', null);

        $this->assertGreaterThan(0, $video);
        $this->assertFalse(HorseMedia::setzeHauptbild($this->horseId, $video));
        $this->assertSame('/uploads/horses/eins.jpg', $this->bildUrl());
    }

    /**
     * Ein fremdes Medium darf ueber die eigene Pferdeseite nicht zum
     * Hauptbild werden.
     */
    public function testEinFremdesMediumWirdNichtHauptbild(): void {
        $this->bild('eins.jpg');
        self::$db->exec("INSERT INTO horses (name) VALUES ('Anderer')");
        $anderes = (int)self::$db->lastInsertId();
        $fremd = HorseMedia::hinzufuegen($anderes, '/uploads/horses/fremd.jpg', null, null);

        $this->assertFalse(HorseMedia::setzeHauptbild($this->horseId, $fremd));
        $this->assertSame('/uploads/horses/eins.jpg', $this->bildUrl());
    }

    /**
     * Wird das Hauptbild geloescht, rueckt das naechste nach. Sonst stuende
     * das Pferd ploetzlich ohne Foto da, obwohl noch welche vorhanden sind.
     */
    public function testNachDemLoeschenDesHauptbildsRuecktDasNaechsteNach(): void {
        $eins = $this->bild('eins.jpg', 10);
        $this->bild('zwei.jpg', 20);

        HorseMedia::loeschen($eins);

        $this->assertSame('/uploads/horses/zwei.jpg', $this->bildUrl());
    }

    public function testOhneBilderWirdImageUrlGeleert(): void {
        $id = $this->bild('eins.jpg');
        HorseMedia::loeschen($id);

        $this->assertNull($this->bildUrl(), 'Kein Bild heisst NULL, nicht der alte Wert.');
    }

    /**
     * Ein Video-Link landet in einem href auf einer oeffentlichen Seite.
     * `javascript:` und `data:` haben dort nichts zu suchen - und ein
     * Redakteur mit horses.edit ist kein Grund, darauf zu verzichten.
     */
    /**
     * Nur bekannte Video-Plattformen, nur https - uebernommen aus dem
     * abgeloesten Addon. Die Feinheiten der Allowlist pruefen
     * tests/Unit/Service/HorseMediaVideoUrlTest.php; hier geht es darum, dass
     * die Ablehnung bis in die Tabelle durchschlaegt.
     */
    public function testNurErlaubteVideoHostsLandenInDerTabelle(): void {
        foreach (['javascript:alert(1)', 'http://youtube.com/x', 'https://evil.tld/v', 'kein-link'] as $kaputt) {
            $this->assertSame(0, HorseMedia::hinzufuegen($this->horseId, null, $kaputt, null), $kaputt);
        }
        $this->assertSame(0, (int)self::$db->query('SELECT COUNT(*) FROM horse_media')->fetchColumn());

        $this->assertGreaterThan(
            0,
            HorseMedia::hinzufuegen($this->horseId, null, 'https://vimeo.com/12345', null)
        );
    }

    public function testOhneBildUndOhneVideoEntstehtNichts(): void {
        $this->assertSame(0, HorseMedia::hinzufuegen($this->horseId, null, null, 'nur eine Bildunterschrift'));
        $this->assertSame(0, (int)self::$db->query('SELECT COUNT(*) FROM horse_media')->fetchColumn());
    }

    public function testMedienVerschwindenMitDemPferd(): void {
        $this->bild('eins.jpg');
        self::$db->prepare('DELETE FROM horses WHERE id = ?')->execute([$this->horseId]);

        $this->assertSame(0, (int)self::$db->query('SELECT COUNT(*) FROM horse_media')->fetchColumn());
    }

    public function testDieReihenfolgeBestimmtDieAnzeige(): void {
        $this->bild('spaet.jpg', 30);
        $this->bild('frueh.jpg', 10);

        $namen = array_column(HorseMedia::forHorse($this->horseId), 'file_name');

        $this->assertSame(['/uploads/horses/frueh.jpg', '/uploads/horses/spaet.jpg'], $namen);
    }
}
