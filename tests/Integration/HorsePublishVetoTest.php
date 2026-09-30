<?php
// tests/Integration/HorsePublishVetoTest.php

namespace Tests\Integration;

use App\Database;
use App\Plugin\PluginManager;
use App\Service\HorsePublishVeto;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Audit N49: App\Service\HorsePublishVeto ist der einzige Übergang 0→1 für
 * Formular, Massen-Veröffentlichung und CSV-Import.
 */
class HorsePublishVetoTest extends TestCase {

    private static PDO $db;

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
        self::$db->exec("DELETE FROM horses");
        PluginManager::getInstance()->getHooks()->reset();
    }

    protected function tearDown(): void {
        PluginManager::getInstance()->getHooks()->reset();
    }

    private function pferd(string $name, ?string $geloescht = null): int {
        self::$db->prepare("INSERT INTO horses (name, is_published, deleted_at) VALUES (?, 0, ?)")->execute([$name, $geloescht]);
        return (int)self::$db->lastInsertId();
    }

    private function veroeffentlicht(int $id): int {
        return (int)self::$db->query("SELECT is_published FROM horses WHERE id = {$id}")->fetchColumn();
    }

    public function testFreigebenPublishesWithoutAndWithholdsWithObjection(): void {
        $gesehen = [];
        PluginManager::getInstance()->getHooks()->addFilter('horse.publish_blockers', function (array $gruende, int $id, array $horse) use (&$gesehen): array {
            $gesehen[] = (int)$horse['is_published'];
            if (str_contains((string)$horse['name'], 'SPERRE')) {
                $gruende[] = '  Gesperrt  ';
                $gruende[] = '';
                $gruende[] = 42;
            }
            return $gruende;
        });

        $frei = $this->pferd('Frei');
        $this->assertSame([], HorsePublishVeto::freigeben($frei));
        $this->assertSame(1, $this->veroeffentlicht($frei));

        $gesperrt = $this->pferd('Mit SPERRE');
        $this->assertSame(['Gesperrt'], HorsePublishVeto::freigeben($gesperrt), 'Gründe bereinigt');
        $this->assertSame(0, $this->veroeffentlicht($gesperrt));
        $this->assertSame([0, 0], $gesehen, 'Der Filter sieht den Stand vor der Veröffentlichung');

        // Ein bereits veröffentlichtes Pferd mit Einwand fällt zurück.
        self::$db->exec("UPDATE horses SET is_published = 1 WHERE id = {$gesperrt}");
        HorsePublishVeto::freigeben($gesperrt);
        $this->assertSame(0, $this->veroeffentlicht($gesperrt));
        $this->assertSame(['Gesperrt'], HorsePublishVeto::einwaende($gesperrt));
    }

    public function testBrokenFilterCountsAsNoObjection(): void {
        PluginManager::getInstance()->getHooks()->addFilter('horse.publish_blockers', fn() => 'kein Array');
        $id = $this->pferd('Kaputt');
        $this->assertSame([], HorsePublishVeto::freigeben($id));
        $this->assertSame(1, $this->veroeffentlicht($id));
    }

    public function testTrashedHorseStaysUnpublished(): void {
        $id = $this->pferd('Papierkorb', date('Y-m-d H:i:s'));
        $this->assertSame([], HorsePublishVeto::einwaende($id));
        HorsePublishVeto::freigeben($id);
        $this->assertSame(0, $this->veroeffentlicht($id));
    }
}
