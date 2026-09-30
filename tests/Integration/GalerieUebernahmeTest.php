<?php
// tests/Integration/GalerieUebernahmeTest.php

namespace Tests\Integration;

use App\Helper\ContainerAblage;
use App\Helper\HorseImagePath;
use App\Service\SchemaMigrator;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Die Übernahme der Galerie aus dem Addon in den Kern (#339).
 *
 * WARUM DAS EINEN EIGENEN TEST BRAUCHT. Hier werden Dateien VERSCHOBEN, und
 * die Migration läuft nicht nur einmal: `run()` führt sie bei jedem
 * Versionssprung erneut aus, und ein Betreiber kann sie über
 * `database/migrate.php` von Hand anstoßen. Ein zweiter Lauf, der Medien
 * verdoppelt oder Dateien verliert, fällt beim ersten Mal nicht auf.
 *
 * Geprüft wird gegen eine echte MariaDB und gegen echte Dateien in einem
 * Wegwerf-Verzeichnis - HorseImagePath ist dafür überschreibbar.
 */
class GalerieUebernahmeTest extends TestCase {

    private static PDO $pdo;
    private string $ziel;
    private string $quelle;

    public static function setUpBeforeClass(): void {
        if (!defined('DB_HOST')) {
            self::markTestSkipped('Keine Test-Datenbank konfiguriert (DB_HOST fehlt) - siehe tests/bootstrap.php.');
        }

        self::$pdo = new PDO(
            "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    protected function setUp(): void {
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $tabelle) {
            self::$pdo->exec("DROP TABLE IF EXISTS `{$tabelle}`");
        }
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        try {
            self::$pdo->exec(file_get_contents(__DIR__ . '/../../database/schema.sql'));
        } catch (PDOException $e) {
            // Ignorieren, analog zu SetupController::provision()
        }

        $basis = sys_get_temp_dir() . '/hv_galerie_' . bin2hex(random_bytes(5));
        $this->ziel = $basis . '/horses';
        $this->quelle = $basis . '/plugin_galerie';
        mkdir($this->ziel, 0755, true);
        mkdir($this->quelle, 0755, true);

        HorseImagePath::overrideForTests($this->ziel, $basis . '/legacy');
        HorseImagePath::overrideGalerieLegacyDirsForTests([$this->quelle]);
    }

    protected function tearDown(): void {
        ContainerAblage::overrideForTests(null, null);
        HorseImagePath::overrideForTests(null, null);
        HorseImagePath::overrideGalerieLegacyDirsForTests(null);

        foreach ([$this->ziel, $this->quelle] as $verzeichnis) {
            if (!is_dir($verzeichnis)) {
                continue;
            }
            foreach (glob($verzeichnis . '/*') ?: [] as $datei) {
                @unlink($datei);
            }
            @rmdir($verzeichnis);
        }
        @rmdir(dirname($this->ziel));
    }

    /** Legt die Addon-Tabelle an, wie sie das Addon `galerie` hinterlässt. */
    private function addonTabelle(): void {
        self::$pdo->exec(
            "CREATE TABLE `plugin_galerie_media` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `horse_id` INT NOT NULL,
                `type` ENUM('image','video') NOT NULL,
                `file_path` VARCHAR(255) NULL DEFAULT NULL,
                `video_url` VARCHAR(255) NULL DEFAULT NULL,
                `caption` VARCHAR(255) NULL DEFAULT NULL,
                `sort_order` INT NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function pferd(string $name, ?string $imageUrl = null): int {
        $stmt = self::$pdo->prepare('INSERT INTO horses (name, image_url, is_published) VALUES (?, ?, 1)');
        $stmt->execute([$name, $imageUrl]);

        return (int)self::$pdo->lastInsertId();
    }

    private function addonMedium(int $horseId, string $typ, ?string $datei, ?string $video, int $sort = 10): void {
        $stmt = self::$pdo->prepare(
            'INSERT INTO `plugin_galerie_media` (horse_id, type, file_path, video_url, sort_order) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$horseId, $typ, $datei, $video, $sort]);
    }

    /** Zwingt die Migration, ihre Datenschritte erneut auszuführen. */
    private function migriere(): array {
        self::$pdo->exec("DELETE FROM settings WHERE setting_key IN ('schema_version', 'migration_339_galerie_uebernahme')");

        return SchemaMigrator::run(self::$pdo);
    }

    public function testMedienUndDateienWandernInDenKern(): void {
        $this->addonTabelle();
        $pferd = $this->pferd('Uebernahme');
        file_put_contents($this->quelle . '/gal_eins.jpg', 'bilddaten');
        $this->addonMedium($pferd, 'image', 'gal_eins.jpg', null, 10);
        $this->addonMedium($pferd, 'video', null, 'https://example.org/v', 20);

        $this->migriere();

        $zeilen = self::$pdo->query(
            'SELECT type, file_name, video_url, is_main FROM horse_media ORDER BY sort_order ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(2, $zeilen);
        $this->assertSame('/uploads/horses/gal_eins.jpg', $zeilen[0]['file_name']);
        $this->assertSame(1, (int)$zeilen[0]['is_main'], 'Ohne bisheriges Hauptbild wird das erste Bild eines.');
        $this->assertSame('https://example.org/v', $zeilen[1]['video_url']);

        $this->assertFileExists($this->ziel . '/gal_eins.jpg', 'Die Datei muss in der Kernablage liegen.');
        $this->assertFileDoesNotExist($this->quelle . '/gal_eins.jpg', 'Und nicht mehr in der alten.');

        $stmt = self::$pdo->prepare('SELECT image_url FROM horses WHERE id = ?');
        $stmt->execute([$pferd]);
        $this->assertSame('/uploads/horses/gal_eins.jpg', $stmt->fetchColumn());
    }

    /**
     * Der eigentliche Punkt: Ein zweiter Lauf darf nichts verdoppeln. Die
     * Migration läuft bei jedem Versionssprung erneut.
     */
    public function testEinZweiterLaufVerdoppeltNichts(): void {
        $this->addonTabelle();
        $pferd = $this->pferd('Zweimal');
        file_put_contents($this->quelle . '/gal_zwei.jpg', 'bilddaten');
        $this->addonMedium($pferd, 'image', 'gal_zwei.jpg', null, 10);

        $this->migriere();
        $nachEins = (int)self::$pdo->query('SELECT COUNT(*) FROM horse_media')->fetchColumn();

        $this->migriere();
        $nachZwei = (int)self::$pdo->query('SELECT COUNT(*) FROM horse_media')->fetchColumn();

        $this->assertSame(1, $nachEins);
        $this->assertSame(1, $nachZwei, 'Der zweite Lauf darf keine zweite Zeile anlegen.');
    }

    /**
     * Ein vorhandenes `horses.image_url` bleibt das Hauptbild und bekommt
     * seine eigene Medienzeile - sonst kennte die Medienliste ausgerechnet
     * das wichtigste Bild nicht.
     */
    public function testEinVorhandenesHauptbildBleibtHauptbildUndBekommtEineZeile(): void {
        $this->addonTabelle();
        $pferd = $this->pferd('Mit Hauptbild', '/uploads/horses/haupt.jpg');
        file_put_contents($this->quelle . '/gal_drei.jpg', 'bilddaten');
        $this->addonMedium($pferd, 'image', 'gal_drei.jpg', null, 10);

        $this->migriere();

        $zeilen = self::$pdo->query(
            'SELECT file_name, is_main FROM horse_media ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(2, $zeilen);
        $this->assertSame('/uploads/horses/haupt.jpg', $zeilen[0]['file_name']);
        $this->assertSame(1, (int)$zeilen[0]['is_main']);
        $this->assertSame(0, (int)$zeilen[1]['is_main']);

        $stmt = self::$pdo->prepare('SELECT image_url FROM horses WHERE id = ?');
        $stmt->execute([$pferd]);
        $this->assertSame('/uploads/horses/haupt.jpg', $stmt->fetchColumn(), 'Das Hauptbild darf nicht wechseln.');
    }

    /**
     * Eine Zeile ohne Datei wird NICHT übernommen - ein Medieneintrag, der
     * ins Leere zeigt, wäre schlimmer als keiner. Gemeldet wird es trotzdem.
     */
    public function testEinEintragOhneDateiWirdUebersprungenUndGemeldet(): void {
        $this->addonTabelle();
        $pferd = $this->pferd('Ohne Datei');
        $this->addonMedium($pferd, 'image', 'verschwunden.jpg', null, 10);

        $schritte = $this->migriere();

        $this->assertSame(0, (int)self::$pdo->query('SELECT COUNT(*) FROM horse_media')->fetchColumn());
        $this->assertStringContainsString(
            'weil die Datei fehlte',
            implode(' | ', $schritte),
            'Ein uebersprungener Eintrag gehoert in die Meldung, nicht ins Schweigen.'
        );
    }

    /** Ohne das Addon gibt es nichts zu übernehmen - und das ist der Normalfall. */
    public function testOhneDasAddonMeldetDieMigrationNichts(): void {
        $this->pferd('Ohne Addon');

        $schritte = $this->migriere();

        $this->assertStringNotContainsString('Galerie (#339)', implode(' | ', $schritte));
    }

    private const MOUNTINFO_WURZEL = "612 540 0:52 / / rw,relatime - overlay overlay rw\n";

    private function marker(): ?string {
        $stmt = self::$pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute(['migration_339_galerie_uebernahme']);
        $wert = $stmt->fetchColumn();

        return $wert === false ? null : (string)$wert;
    }

    /**
     * Im Container ohne eigenes Volume auf storage/horses wartet die
     * Übernahme aus public/uploads/plugin_galerie (Audit M31).
     *
     * Die Quelle liegt dort im Volume uploads_data; rename() fiele über die
     * Dateisystemgrenze auf Kopieren und Löschen zurück, und die Bilder
     * landeten im Container-Dateisystem. Ausgesetzt wird der ganze Schritt:
     * Würde nur die Datei übersprungen, zählte die Zeile als "ohne Datei",
     * der Marker stünde, und die Übernahme wäre endgültig verloren.
     */
    public function testGalerieUebernahmeWartetAufPersistentenSpeicher(): void {
        $volumeQuelle = dirname($this->ziel) . '/public/uploads/plugin_galerie';
        mkdir($volumeQuelle, 0755, true);
        HorseImagePath::overrideGalerieLegacyDirsForTests([$this->quelle, $volumeQuelle]);
        try {
            $this->addonTabelle();
            $pferd = $this->pferd('Im Volume');
            file_put_contents($volumeQuelle . '/gal_volume.jpg', 'volumebild');
            $this->addonMedium($pferd, 'image', 'gal_volume.jpg', null, 10);

            ContainerAblage::overrideForTests(true, self::MOUNTINFO_WURZEL);
            $schritte = $this->migriere();

            $this->assertFileExists($volumeQuelle . '/gal_volume.jpg', 'Ohne Volume bleibt das Bild im Volume uploads_data');
            $this->assertFileDoesNotExist($this->ziel . '/gal_volume.jpg');
            $this->assertSame(0, (int)self::$pdo->query('SELECT COUNT(*) FROM horse_media')->fetchColumn(), 'Keine Medienzeile, solange die Datei nicht übernommen ist');
            $this->assertNull($this->marker(), 'Kein Marker - die Übernahme muss nach dem Nachrüsten erneut laufen');
            $this->assertStringContainsString('kein eigenes Volume', implode(' | ', $schritte));

            // Mit benanntem Volume auf storage/horses wird übernommen.
            ContainerAblage::overrideForTests(
                true,
                self::MOUNTINFO_WURZEL . '700 612 254:1 /var/lib/docker/volumes/hengst_horses_data/_data '
                    . realpath($this->ziel) . " rw,relatime - ext4 /dev/vda1 rw\n"
            );
            $this->migriere();

            $this->assertFileExists($this->ziel . '/gal_volume.jpg');
            $this->assertFileDoesNotExist($volumeQuelle . '/gal_volume.jpg');
            $this->assertSame(1, (int)self::$pdo->query("SELECT COUNT(*) FROM horse_media WHERE file_name = '/uploads/horses/gal_volume.jpg'")->fetchColumn());
            $this->assertNotNull($this->marker());
        } finally {
            foreach (glob($volumeQuelle . '/*') ?: [] as $datei) {
                @unlink($datei);
            }
            @rmdir($volumeQuelle);
            @rmdir(dirname($volumeQuelle));
            @rmdir(dirname($volumeQuelle, 2));
        }
    }

    /**
     * Die Ablage storage/plugin_galerie liegt im Container wie storage/horses
     * im Container-Dateisystem - von dort zu verschieben verliert nichts, also
     * wird auch ohne Volume übernommen.
     */
    public function testGalerieAusStorageWirdAuchOhneVolumeUebernommen(): void {
        $this->addonTabelle();
        $pferd = $this->pferd('Im Layer');
        file_put_contents($this->quelle . '/gal_layer.jpg', 'layerbild');
        $this->addonMedium($pferd, 'image', 'gal_layer.jpg', null, 10);

        ContainerAblage::overrideForTests(true, self::MOUNTINFO_WURZEL);
        $this->migriere();

        $this->assertFileExists($this->ziel . '/gal_layer.jpg');
        $this->assertNotNull($this->marker());
    }
}
