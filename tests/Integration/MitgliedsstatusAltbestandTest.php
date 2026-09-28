<?php
// tests/Integration/MitgliedsstatusAltbestandTest.php

namespace Tests\Integration;

use App\Service\MitgliedsstatusAltbestand;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\AltbestandV072;
use Tests\Support\WegwerfDatenbank;

/**
 * App\Service\MitgliedsstatusAltbestand (Audit N78): die Einzelfälle der
 * Regel, nach der das Dashboard auf nicht übernommene Mitgliedsstatus-Werte
 * aus v0.7 hinweist.
 *
 * Die Regel ist die des Addons `mitgliedsstatus` ab 1.1.0 (dort
 * Uebernahme::altbestandQuelle()/einmalig()). Jeder Fall hier hat sein
 * Gegenstück in tests/Functional/MitgliedsstatusPluginTest.php der Addons -
 * driften die beiden auseinander, zeigt der Hinweis etwas an, das das Addon
 * nie übernimmt, oder schweigt, obwohl es etwas zu holen gäbe.
 *
 * Grundlage ist das aktuelle database/schema.sql (contacts ohne
 * membership_status) plus ein nachgebauter Altbestand.
 */
class MitgliedsstatusAltbestandTest extends TestCase {

    private static function db(): string {
        return WegwerfDatenbank::name('mitgliedsstatus_altbestand');
    }

    private PDO $pdo;

    protected function setUp(): void {
        if (!defined('DB_HOST')) {
            $this->markTestSkipped('Keine Test-Datenbank konfiguriert (DB_HOST fehlt) - siehe tests/bootstrap.php.');
        }
        $this->pdo = AltbestandV072::aktuell(self::db());
    }

    protected function tearDown(): void {
        if (defined('DB_HOST')) {
            AltbestandV072::entfernen(self::db());
        }
    }

    /**
     * Kontakte 1-3 mit Wert im Altbestand, dazu Leerraum (4), eine ID ohne
     * Kontakt (99) und ein anonymisierter Kontakt (5).
     */
    private function sprungzustand(): void {
        $this->pdo->exec("INSERT INTO contacts (id, name) VALUES
            (1, 'Anna'), (2, 'Bert'), (3, 'Cora'), (4, 'Dora'), (5, 'Anonymisierte Person (#5)')");
        $this->pdo->exec(
            'CREATE TABLE `persons_pre_contacts` (
                `id` INT NOT NULL PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL,
                `membership_status` VARCHAR(100) NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->pdo->exec("INSERT INTO persons_pre_contacts (id, name, membership_status) VALUES
            (1, 'Anna', 'Mitglied'),
            (2, 'Bert', ' Nichtmitglied NO'),
            (3, 'Cora', NULL),
            (4, 'Dora', '\t\r\n'),
            (5, 'Emil', 'Mitglied'),
            (99, 'Weg', 'Mitglied')");
    }

    private function setzen(string $schluessel, string $wert): void {
        $this->pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        )->execute([$schluessel, $wert]);
    }

    public function testSprungAusV07ZaehltNurUebernehmbareWerte(): void {
        $this->sprungzustand();

        // 1 und 2 - nicht 3 (NULL), 4 (nur Leerraum), 5 (anonymisiert), 99 (kein Kontakt).
        $this->assertSame(2, MitgliedsstatusAltbestand::offen($this->pdo));
    }

    public function testOhneAltbestandIstNichtsOffen(): void {
        $this->assertSame(0, MitgliedsstatusAltbestand::offen($this->pdo));

        // Tabelle da, aber ohne die Spalte (ein Altbestand von vor Schritt 854).
        $this->pdo->exec('CREATE TABLE `persons_pre_contacts` (`id` INT PRIMARY KEY, `name` VARCHAR(100))');
        $this->assertSame(0, MitgliedsstatusAltbestand::offen($this->pdo));
    }

    public function testContactsMitSpalteIstKeinSprung(): void {
        $this->sprungzustand();
        $this->pdo->exec('ALTER TABLE `contacts` ADD COLUMN `membership_status` VARCHAR(100) NULL DEFAULT NULL');

        $this->assertSame(0, MitgliedsstatusAltbestand::offen($this->pdo), 'Dann übernimmt das Addon aus contacts.');
    }

    public function testMit395MarkerIstDerAltbestandUeberholt(): void {
        $this->sprungzustand();
        $this->setzen('migration_395_membership_status_faellt', gmdate('c'));

        $this->assertSame(0, MitgliedsstatusAltbestand::offen($this->pdo));
    }

    public function testAddonMarkerKeineSpalteOhneQuelleBleibtOffen(): void {
        $this->sprungzustand();
        $this->setzen('plugin_mitgliedsstatus_uebernahme', json_encode(
            ['zeitpunkt' => gmdate('c'), 'gesamt' => 0, 'zugeordnet' => 0, 'offen' => 0, 'grund' => 'keine-spalte']
        ));

        $this->assertSame(
            2,
            MitgliedsstatusAltbestand::offen($this->pdo),
            'Ein 1.0.0-Marker "keine-spalte" ohne quelle wird von 1.1.0 nachgeholt - der Hinweis bleibt.'
        );
    }

    public function testAddonMarkerMitQuelleSchliesstAb(): void {
        $this->sprungzustand();
        $this->setzen('plugin_mitgliedsstatus_uebernahme', json_encode(
            ['zeitpunkt' => gmdate('c'), 'gesamt' => 2, 'zugeordnet' => 1, 'offen' => 1, 'quelle' => 'persons_pre_contacts', 'bestand' => 0]
        ));
        $this->assertSame(0, MitgliedsstatusAltbestand::offen($this->pdo));

        // Ein 1.0.0-Marker einer Übernahme aus contacts (ohne grund) schliesst
        // ebenfalls ab - das Addon holt dann nichts nach.
        $this->setzen('plugin_mitgliedsstatus_uebernahme', json_encode(
            ['zeitpunkt' => gmdate('c'), 'gesamt' => 3, 'zugeordnet' => 3, 'offen' => 0]
        ));
        $this->assertSame(0, MitgliedsstatusAltbestand::offen($this->pdo));
    }

    public function testGeloeschterKontaktZaehltNicht(): void {
        $this->sprungzustand();
        $this->pdo->exec('DELETE FROM contacts WHERE id = 1');

        $this->assertSame(1, MitgliedsstatusAltbestand::offen($this->pdo));
    }

    public function testFehlerErgibtNull(): void {
        $this->sprungzustand();
        $this->pdo->exec('DROP TABLE settings');

        $this->assertSame(0, MitgliedsstatusAltbestand::offen($this->pdo), 'Ein Hinweis darf das Dashboard nicht brechen.');
    }
}
