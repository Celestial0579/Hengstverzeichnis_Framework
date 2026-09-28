<?php
// tests/Functional/AdminDashboardMitgliedsstatusTest.php

namespace Tests\Functional;

/**
 * Dashboard-Hinweis auf Mitgliedsstatus-Werte, die nach dem Sprung aus v0.7
 * nur noch im stillgelegten Altbestand `persons_pre_contacts` stehen
 * (Audit N78). Die Regel selbst prüft
 * tests/Integration/MitgliedsstatusAltbestandTest.php; hier geht es um
 * Sichtbarkeit (nur Admins), den Link und das Verschwinden nach der
 * Übernahme durch das Addon.
 *
 * Die Testinstanz hat keinen eigenen Altbestand; der Test legt ihn an und
 * räumt ihn in tearDown() wieder weg - die Datenbank ist geteilter Zustand
 * der ganzen Suite.
 */
class AdminDashboardMitgliedsstatusTest extends FunctionalTestCase {

    private const HINWEIS = 'Mitgliedsstatus aus v0.7';

    private bool $angelegt = false;
    private ?int $kontaktId = null;

    /** Erst nach authenticatedClient(): Das stellt die Installation bereit. */
    private function altbestandAnlegen(): void {
        $db = \App\Database::getInstance();
        $this->assertSame(
            0,
            $db->query("SHOW TABLES LIKE 'persons_pre_contacts'")->rowCount(),
            'Vorbedingung: Die Testinstanz hat keinen eigenen Altbestand.'
        );
        $this->angelegt = true;

        $db->prepare('INSERT INTO contacts (name) VALUES (?)')->execute(['Altbestand ' . bin2hex(random_bytes(3))]);
        $this->kontaktId = (int)$db->lastInsertId();

        $db->exec(
            'CREATE TABLE `persons_pre_contacts` (
                `id` INT NOT NULL PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL,
                `membership_status` VARCHAR(100) NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $db->prepare('INSERT INTO persons_pre_contacts (id, name, membership_status) VALUES (?, ?, ?)')
            ->execute([$this->kontaktId, 'Altbestand', 'Nichtmitglied NO']);
    }

    protected function tearDown(): void {
        if ($this->angelegt) {
            $db = \App\Database::getInstance();
            $db->exec('DROP TABLE IF EXISTS `persons_pre_contacts`');
            $db->prepare('DELETE FROM settings WHERE setting_key = ?')->execute(['plugin_mitgliedsstatus_uebernahme']);
            if ($this->kontaktId !== null) {
                $db->prepare('DELETE FROM contacts WHERE id = ?')->execute([$this->kontaktId]);
            }
        }
        parent::tearDown();
    }

    public function testAdminSiehtDenHinweisBisZurUebernahme(): void {
        $admin = $this->authenticatedClient();
        $this->assertStringNotContainsString(self::HINWEIS, $admin->get('/admin')->body, 'Ohne Altbestand kein Hinweis.');

        $this->altbestandAnlegen();

        $seite = $admin->get('/admin');
        $this->assertSame(200, $seite->statusCode);
        $this->assertStringContainsString(self::HINWEIS, $seite->body);
        $this->assertStringContainsString('1 Kontakt führt', $seite->body);
        $this->assertStringContainsString('<a href="/admin/plugins/store"', $seite->body);
        // Der Link muss auch wirklich eine Seite treffen.
        $this->assertSame(200, $admin->get('/admin/plugins/store')->statusCode);

        // Das Addon hat übernommen: Marker mit quelle.
        \App\Database::getInstance()->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES ('plugin_mitgliedsstatus_uebernahme', ?)"
        )->execute([json_encode(['gesamt' => 1, 'zugeordnet' => 0, 'offen' => 1, 'quelle' => 'persons_pre_contacts', 'bestand' => 0])]);

        $this->assertStringNotContainsString(self::HINWEIS, $admin->get('/admin')->body);
    }

    public function testEditorSiehtDenHinweisNicht(): void {
        $admin = $this->authenticatedClient();
        $editor = $this->createAndLoginEditor(
            $admin,
            'msalt_' . bin2hex(random_bytes(3)),
            'msalt_' . bin2hex(random_bytes(3)) . '@example.com'
        );
        $this->altbestandAnlegen();

        $seite = $editor->get('/admin');

        $this->assertSame(200, $seite->statusCode);
        $this->assertStringNotContainsString(self::HINWEIS, $seite->body);
    }
}
