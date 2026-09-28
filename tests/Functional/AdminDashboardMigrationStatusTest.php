<?php
// tests/Functional/AdminDashboardMigrationStatusTest.php

namespace Tests\Functional;

use App\Service\SchemaMigrator;

/**
 * Offene oder gescheiterte Schema-Migrationen erscheinen auf dem
 * Admin-Dashboard (Audit N76). Bisher verschluckte der implizite
 * Migrationsweg jeden Fehler, und niemand sah ihn.
 *
 * Der Status steht mit zeit = jetzt in settings - die Drosselung verhindert
 * dann, dass der Server-Request selbst migriert und den Status wegräumt.
 */
class AdminDashboardMigrationStatusTest extends FunctionalTestCase {

    private ?string $versionVorher = null;
    private bool $gesetzt = false;

    /** Erst nach authenticatedClient(): Das stellt die Installation bereit. */
    private function statusSetzen(): void {
        $this->gesetzt = true;
        $db = \App\Database::getInstance();
        $wert = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'schema_version'")->fetchColumn();
        $this->versionVorher = $wert === false ? null : (string)$wert;

        $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'schema_version'")
            ->execute([(string)(SchemaMigrator::SCHEMA_VERSION - 1)]);
        $db->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        )->execute([SchemaMigrator::STATUS_SCHLUESSEL, json_encode([
            'zustand' => 'offen',
            'von' => SchemaMigrator::SCHEMA_VERSION - 1,
            'ziel' => SchemaMigrator::SCHEMA_VERSION,
            'zeit' => gmdate('c'),
            'meldungen' => ['366_pferdefotos_aus_dem_webroot: <b>storage/horses</b> lässt sich nicht anlegen'],
        ])]);
    }

    protected function tearDown(): void {
        if (!$this->gesetzt) {
            parent::tearDown();
            return;
        }
        $db = \App\Database::getInstance();
        $db->prepare("DELETE FROM settings WHERE setting_key = ?")->execute([SchemaMigrator::STATUS_SCHLUESSEL]);
        if ($this->versionVorher !== null) {
            $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'schema_version'")
                ->execute([$this->versionVorher]);
        }
        parent::tearDown();
    }

    public function testAdminSiehtDenHinweisEscaped(): void {
        $admin = $this->authenticatedClient();
        $this->statusSetzen();

        $seite = $admin->get('/admin');

        $this->assertSame(200, $seite->statusCode);
        $this->assertStringContainsString('Datenbank-Migration unvollständig', $seite->body);
        $this->assertStringContainsString('&lt;b&gt;storage/horses&lt;/b&gt;', $seite->body);
        $this->assertStringNotContainsString('<b>storage/horses</b>', $seite->body);
        $this->assertStringContainsString('php database/migrate.php', $seite->body);
        $this->assertSame(
            (string)(SchemaMigrator::SCHEMA_VERSION - 1),
            (string)\App\Database::getInstance()->query("SELECT setting_value FROM settings WHERE setting_key = 'schema_version'")->fetchColumn(),
            'Die Drosselung hält den Server-Request vom Migrieren ab'
        );
    }

    public function testEditorSiehtDenHinweisNicht(): void {
        $admin = $this->authenticatedClient();
        $editor = $this->createAndLoginEditor($admin, 'migstatus_' . bin2hex(random_bytes(3)), 'migstatus_' . bin2hex(random_bytes(3)) . '@example.com');
        $this->statusSetzen();

        $seite = $editor->get('/admin');

        $this->assertSame(200, $seite->statusCode);
        $this->assertStringNotContainsString('Datenbank-Migration', $seite->body);
    }
}
