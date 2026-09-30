<?php
// tests/Integration/HorseMediaTest.php

namespace Tests\Integration;

use App\Database;
use App\Helper\HorseImagePath;
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
    private string $tmp;

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

        // Seit Audit N68 loescht HorseMedia echte Dateien - nie in der
        // echten Ablage.
        $this->tmp = sys_get_temp_dir() . '/hv_horsemedia_' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/horses', 0755, true);
        mkdir($this->tmp . '/legacy', 0755, true);
        HorseImagePath::overrideForTests($this->tmp . '/horses', $this->tmp . '/legacy');
    }

    protected function tearDown(): void {
        HorseImagePath::overrideForTests(null, null);
        foreach (['/horses', '/legacy', ''] as $unter) {
            foreach (glob($this->tmp . $unter . '/{,.}*', GLOB_BRACE) ?: [] as $datei) {
                if (is_file($datei)) {
                    @unlink($datei);
                }
            }
        }
        @rmdir($this->tmp . '/horses');
        @rmdir($this->tmp . '/legacy');
        @rmdir($this->tmp);
    }

    /** Legt die Datei samt Vorschaubildern in der Temp-Ablage an. */
    private function datei(string $name): void {
        $dir = HorseImagePath::dir();
        file_put_contents($dir . '/' . $name, 'bild');
        $stamm = pathinfo($name, PATHINFO_FILENAME);
        file_put_contents($dir . '/' . $stamm . '_thumb.jpg', 'vorschau');
        file_put_contents($dir . '/' . $stamm . '_card.jpg', 'karte');
    }

    private function setzeBildUrl(?string $wert, ?int $horseId = null): void {
        self::$db->prepare('UPDATE horses SET image_url = ? WHERE id = ?')->execute([$wert, $horseId ?? $this->horseId]);
    }

    private function mainIds(?int $horseId = null): array {
        $stmt = self::$db->prepare('SELECT id FROM horse_media WHERE horse_id = ? AND is_main = 1 ORDER BY id');
        $stmt->execute([$horseId ?? $this->horseId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function zeile(string $datei, int $sort, int $isMain = 0, string $typ = 'image'): int {
        $stmt = self::$db->prepare(
            'INSERT INTO horse_media (horse_id, type, file_name, video_url, is_main, sort_order) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $this->horseId,
            $typ,
            $typ === 'image' ? $datei : null,
            $typ === 'video' ? $datei : null,
            $isMain,
            $sort,
        ]);

        return (int)self::$db->lastInsertId();
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

        $zwei = (int)self::$db->query("SELECT id FROM horse_media WHERE file_name = '/uploads/horses/zwei.jpg'")->fetchColumn();

        HorseMedia::loeschen($eins);

        $this->assertSame('/uploads/horses/zwei.jpg', $this->bildUrl());
        // Audit N69: der Nachfolger ist auch GEKENNZEICHNET, und nur er.
        $this->assertSame([$zwei], $this->mainIds());
        $this->assertTrue(HorseMedia::hatHauptbild($this->horseId));
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

    // --- Audit N69: die Invariante ------------------------------------------

    public function testDerNaechsteUploadWirdNichtUngefragtHauptbild(): void {
        $a = $this->bild('a.jpg', 10);
        $this->bild('b.jpg', 20);
        $this->bild('c.jpg', 30);
        HorseMedia::loeschen($a);

        $d = $this->bild('d.jpg', 40);

        $this->assertSame('/uploads/horses/b.jpg', $this->bildUrl());
        $this->assertNotContains($d, $this->mainIds());
    }

    public function testEinUploadMitKleinererSortierungVerdraengtDasAngezeigteBildNicht(): void {
        // Altbestand ohne Kennzeichnung, das angezeigte Bild hat sort 20.
        $b = $this->zeile('/uploads/horses/b.jpg', 20);
        $this->setzeBildUrl('/uploads/horses/b.jpg');

        $this->bild('d.jpg', 5);

        $this->assertSame('/uploads/horses/b.jpg', $this->bildUrl());
        $this->assertSame([$b], $this->mainIds());
    }

    public function testLoeschenEinesNebenbildsLaesstDasHauptbildStehen(): void {
        $eins = $this->bild('eins.jpg', 10);
        $zwei = $this->bild('zwei.jpg', 20);

        HorseMedia::loeschen($zwei);

        $this->assertSame('/uploads/horses/eins.jpg', $this->bildUrl());
        $this->assertSame([$eins], $this->mainIds());
    }

    public function testAltbestandOhneKennzeichnungWirdBeimNaechstenUploadGeheilt(): void {
        $b = $this->zeile('/uploads/horses/b.jpg', 10);
        $this->zeile('/uploads/horses/c.jpg', 20);
        $this->setzeBildUrl('/uploads/horses/b.jpg');

        $this->bild('neu.jpg', 30);

        $this->assertSame([$b], $this->mainIds());
        $this->assertSame('/uploads/horses/b.jpg', $this->bildUrl());
    }

    public function testEsGibtNachDemAbgleichNurEinHauptbild(): void {
        $a = $this->zeile('/uploads/horses/a.jpg', 10, 1);
        $this->zeile('/uploads/horses/b.jpg', 20, 1);
        $video = $this->zeile('https://vimeo.com/1', 30, 1, 'video');
        $this->setzeBildUrl('/uploads/horses/a.jpg');

        HorseMedia::syncMainImage($this->horseId);

        $this->assertSame([$a], $this->mainIds());
        $this->assertNotContains($video, $this->mainIds());
        $this->assertSame('/uploads/horses/a.jpg', $this->bildUrl());
    }

    /** Wie HorseController::store(): image_url zuerst, dann die Zeile. */
    public function testAnlegenUeberStoreMachtDasHochgeladeneBildZumHauptbild(): void {
        $this->setzeBildUrl('/uploads/horses/x.jpg');

        $x = $this->bild('x.jpg', 10);

        $this->assertSame([$x], $this->mainIds());
        $this->assertSame('/uploads/horses/x.jpg', $this->bildUrl());
    }

    // --- Audit M40: Katalogfoto ohne Medienzeile ----------------------------

    public function testEinAltesHauptbildUeberlebtDasLoeschenEinesVideos(): void {
        $this->setzeBildUrl('/uploads/horses/alt.jpg');
        $video = HorseMedia::hinzufuegen($this->horseId, null, 'https://vimeo.com/12345', null);

        HorseMedia::loeschen($video);

        $this->assertSame('/uploads/horses/alt.jpg', $this->bildUrl());
        $this->assertCount(1, $this->mainIds());
    }

    public function testEinNeuesBildVerdraengtDasAlteHauptbildNicht(): void {
        $this->setzeBildUrl('/uploads/horses/alt.jpg');

        $this->bild('neu.jpg');

        $this->assertSame('/uploads/horses/alt.jpg', $this->bildUrl());
        $this->assertSame(2, (int)self::$db->query('SELECT COUNT(*) FROM horse_media')->fetchColumn());
    }

    public function testBeimAnlegenEntstehtKeineDoppelteZeile(): void {
        $this->setzeBildUrl('/uploads/horses/x.jpg');

        $this->bild('x.jpg');

        $this->assertSame(1, (int)self::$db->query('SELECT COUNT(*) FROM horse_media')->fetchColumn());
    }

    public function testAlsHauptbildNimmtDasAltfotoMit(): void {
        $this->setzeBildUrl('/uploads/horses/alt.jpg');
        $neu = $this->zeile('/uploads/horses/neu.jpg', 10);

        $this->assertTrue(HorseMedia::setzeHauptbild($this->horseId, $neu));

        $stmt = self::$db->prepare('SELECT is_main FROM horse_media WHERE horse_id = ? AND file_name = ?');
        $stmt->execute([$this->horseId, '/uploads/horses/alt.jpg']);
        $this->assertSame(0, (int)$stmt->fetchColumn(), 'Das Altfoto bleibt als Nebenbild erhalten.');
        $this->assertSame([$neu], $this->mainIds());
        $this->assertSame('/uploads/horses/neu.jpg', $this->bildUrl());
    }

    // --- Audit N68: Dateien beim Loeschen -----------------------------------

    public function testDasGeloeschteHauptbildNimmtDateiUndVorschaubilderMit(): void {
        $this->datei('eins.jpg');
        $eins = $this->bild('eins.jpg');

        HorseMedia::loeschen($eins);

        $dir = HorseImagePath::dir();
        $this->assertFileDoesNotExist($dir . '/eins.jpg');
        $this->assertFileDoesNotExist($dir . '/eins_thumb.jpg');
        $this->assertFileDoesNotExist($dir . '/eins_card.jpg');
        $this->assertNull($this->bildUrl());
    }

    public function testNachDemLoeschenDesHauptbildsBleibtDieDateiDesNachfolgers(): void {
        $this->datei('eins.jpg');
        $this->datei('zwei.jpg');
        $eins = $this->bild('eins.jpg', 10);
        $this->bild('zwei.jpg', 20);

        HorseMedia::loeschen($eins);

        $this->assertFileDoesNotExist(HorseImagePath::dir() . '/eins.jpg');
        $this->assertFileExists(HorseImagePath::dir() . '/zwei.jpg');
        $this->assertFileExists(HorseImagePath::dir() . '/zwei_thumb.jpg');
    }

    public function testEineDateiDieEinAnderesPferdFuehrtBleibt(): void {
        $this->datei('geteilt.jpg');
        // Anderes Pferd im Papierkorb, abweichende Pfadschreibweise.
        self::$db->exec("INSERT INTO horses (name, image_url, deleted_at) VALUES ('Im Papierkorb', 'uploads/horses/geteilt.jpg', NOW())");
        $id = $this->bild('geteilt.jpg');

        HorseMedia::loeschen($id);

        $this->assertFileExists(HorseImagePath::dir() . '/geteilt.jpg');
    }

    // --- Audit N59: Dateien endgueltig geloeschter Pferde -------------------

    public function testBilddateienVonPferdenSammeltMedienUndImageUrl(): void {
        $this->bild('medium.jpg');
        HorseMedia::hinzufuegen($this->horseId, null, 'https://vimeo.com/1', null);
        self::$db->exec("INSERT INTO horses (name, image_url) VALUES ('Ohne Zeile', '/uploads/horses/nur_url.jpg')");
        $ohneZeile = (int)self::$db->lastInsertId();

        $werte = HorseMedia::bilddateienVonPferden([$this->horseId, $ohneZeile, 0, -3]);
        sort($werte);

        $this->assertSame(['/uploads/horses/medium.jpg', '/uploads/horses/nur_url.jpg'], $werte);
        $this->assertSame([], HorseMedia::bilddateienVonPferden([]));
    }

    public function testVerwaisteDateienEntfernenLaesstReferenzierteStehen(): void {
        foreach (['x.jpg', 'y.jpg', 'z.jpg'] as $name) {
            $this->datei($name);
        }
        $this->zeile('/uploads/horses/x.jpg', 10);
        self::$db->exec("INSERT INTO horses (name, image_url, deleted_at) VALUES ('Papierkorb', '/uploads/horses/y.jpg', NOW())");

        $anzahl = HorseMedia::verwaisteDateienEntfernen([
            '/uploads/horses/x.jpg', '/uploads/horses/y.jpg', '/uploads/horses/z.jpg',
        ]);

        $dir = HorseImagePath::dir();
        $this->assertSame(1, $anzahl);
        $this->assertFileExists($dir . '/x.jpg');
        $this->assertFileExists($dir . '/y.jpg');
        $this->assertFileDoesNotExist($dir . '/z.jpg');
        $this->assertFileDoesNotExist($dir . '/z_thumb.jpg');
        $this->assertFileDoesNotExist($dir . '/z_card.jpg');
    }

    public function testVerwaisteDateienEntfernenIgnoriertPfadausbrueche(): void {
        // Eine Datei neben der Ablage, die ein '../'-Wert treffen wuerde.
        file_put_contents($this->tmp . '/x.jpg', 'draussen');

        $this->assertSame(0, HorseMedia::verwaisteDateienEntfernen(['../x', '.', '', '..', '/uploads/horses/..']));
        $this->assertFileExists($this->tmp . '/x.jpg');
    }
}
