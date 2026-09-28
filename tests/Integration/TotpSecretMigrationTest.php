<?php
// tests/Integration/TotpSecretMigrationTest.php

namespace Tests\Integration;

use App\Security\Crypto;
use App\Security\Totp;
use App\Service\SchemaMigrator;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\AltbestandV072;
use Tests\Support\WegwerfDatenbank;

/**
 * SCHEMA_VERSION 24 (Audit N8): users.totp_secret auf VARCHAR(255) und der
 * Datenschritt totp_klartext_verschluesseln.
 *
 * Eigene Wegwerf-Datenbank aus dem aktuellen schema.sql, um eine Version
 * zurückgesetzt und auf eine Altinstallation mit VARCHAR(64) verkleinert.
 */
class TotpSecretMigrationTest extends TestCase {

    private const MARKER = 'migration_totp_klartext_verschluesseln';

    private static function db(): string {
        return WegwerfDatenbank::name('totp_migration');
    }

    protected function setUp(): void {
        if (!defined('DB_HOST')) {
            $this->markTestSkipped('Keine Test-Datenbank konfiguriert (DB_HOST fehlt) - siehe tests/bootstrap.php.');
        }
        SchemaMigrator::setzeSperreWartezeitFuerTests(-1);
    }

    protected function tearDown(): void {
        SchemaMigrator::setzeSperreWartezeitFuerTests(-1);
        if (defined('DB_HOST')) {
            AltbestandV072::entfernen(self::db());
        }
    }

    private static function konto(PDO $pdo, string $name, string $secret, int $aktiv = 1): int {
        $pdo->prepare(
            "INSERT INTO users (username, email, password_hash, totp_secret, totp_enabled) VALUES (?, ?, 'x', ?, ?)"
        )->execute([$name, $name . '@example.com', $secret, $aktiv]);
        return (int)$pdo->lastInsertId();
    }

    private static function secret(PDO $pdo, int $id): string {
        $stmt = $pdo->prepare("SELECT totp_secret FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return (string)$stmt->fetchColumn();
    }

    private static function breite(PDO $pdo): int {
        $typ = (string)$pdo->query("SHOW COLUMNS FROM `users` LIKE 'totp_secret'")->fetch()['Type'];
        preg_match('/\((\d+)\)/', $typ, $m);
        return (int)$m[1];
    }

    public function testAltinstallationWirdErweitertUndKlartextVerschluesselt(): void {
        $pdo = AltbestandV072::aktuell(self::db());
        SchemaMigrator::run($pdo);
        $this->assertSame(255, self::breite($pdo), 'Frisches schema.sql hat bereits 255');

        // Altinstallation: Spalte schmal, Klartext von vor der Verschlüsselung.
        $pdo->exec("ALTER TABLE `users` MODIFY `totp_secret` VARCHAR(64) NULL");
        $kurz = self::konto($pdo, 'totp_kurz', 'JBSWY3DPEHPK3PXP');
        $lang = self::konto($pdo, 'totp_lang', str_repeat('ABCDEFGH', 4));
        $fremd = self::konto($pdo, 'totp_fremd', base64_encode(random_bytes(44)));
        $fremdAus = self::konto($pdo, 'totp_fremd_aus', base64_encode(random_bytes(44)), 0);
        $echt = self::konto($pdo, 'totp_echt', Crypto::encrypt('KRUGS4ZANFZSAYJA'));
        $echtVorher = self::secret($pdo, $echt);

        $pdo->exec("DELETE FROM settings WHERE setting_key = '" . self::MARKER . "'");
        $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'schema_version'")
            ->execute([(string)(SchemaMigrator::SCHEMA_VERSION - 1)]);

        $schritte = SchemaMigrator::run($pdo);

        $this->assertSame(255, self::breite($pdo));
        $this->assertContains('Spalte users.totp_secret auf VARCHAR(255) erweitert', $schritte);
        $this->assertContains('TOTP: 2 Klartext-Secret(s) verschlüsselt', $schritte);
        $this->assertContains(
            'TOTP: 1 Konto/Konten haben ein mit dem aktuellen APP_KEY nicht lesbares Secret (APP_KEY gewechselt?) – bitte deren 2FA zurücksetzen',
            $schritte
        );

        // Beide Klartexte liegen jetzt verschlüsselt vor - auch der 32-stellige,
        // dessen Chiffretext (76 Zeichen) in VARCHAR(64) nicht gepasst hätte.
        $this->assertFalse(Totp::istKlartextSecret(self::secret($pdo, $kurz)));
        $this->assertSame('JBSWY3DPEHPK3PXP', Crypto::decrypt(self::secret($pdo, $kurz)));
        $this->assertSame(str_repeat('ABCDEFGH', 4), Crypto::decrypt(self::secret($pdo, $lang)));
        $this->assertNull(Totp::entschluesseleSecret(self::secret($pdo, $fremd)), 'Fremder Chiffretext bleibt unlesbar');
        $this->assertSame($echtVorher, self::secret($pdo, $echt), 'Schon verschlüsselte Secrets bleiben unberührt');
        $this->assertNotSame('', self::secret($pdo, $fremdAus));

        $this->assertNotNull(AltbestandV072::einstellung($pdo, self::MARKER));
        $this->assertSame(SchemaMigrator::SCHEMA_VERSION, SchemaMigrator::storedVersion($pdo));

        // Zweiter Lauf: No-Op.
        $kurzNachher = self::secret($pdo, $kurz);
        $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'schema_version'")
            ->execute([(string)(SchemaMigrator::SCHEMA_VERSION - 1)]);
        $zweiter = SchemaMigrator::run($pdo);
        $this->assertSame(
            [sprintf('settings.schema_version auf %d gesetzt (vorher %d)', SchemaMigrator::SCHEMA_VERSION, SchemaMigrator::SCHEMA_VERSION - 1)],
            $zweiter
        );
        $this->assertSame($kurzNachher, self::secret($pdo, $kurz));
    }

    /**
     * Ohne APP_KEY lässt sich nichts verschlüsseln. Der Schritt darf den
     * Klartext dann nicht mit einem Marker für erledigt erklären, sondern
     * meldet sich offen - migrate.php endet mit Exit-Code 2, der Stand bleibt
     * ungestempelt, und der nächste Lauf mit Schlüssel holt es nach.
     */
    public function testOhneAppKeyBleibtDerSchrittOffen(): void {
        $pdo = AltbestandV072::aktuell(self::db());
        SchemaMigrator::run($pdo);
        $pdo->exec("DELETE FROM settings WHERE setting_key = '" . self::MARKER . "'");
        $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'schema_version'")
            ->execute([(string)(SchemaMigrator::SCHEMA_VERSION - 1)]);
        $id = self::konto($pdo, 'totp_ohne_schluessel', 'JBSWY3DPEHPK3PXP');

        $env = getenv();
        unset($env['APP_KEY']);
        $env['DB_NAME'] = self::db();
        $env['DB_HOST'] = DB_HOST;
        $env['DB_PORT'] = (string)DB_PORT;
        $env['DB_USER'] = DB_USER;
        $env['DB_PASS'] = DB_PASS;
        $proc = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/database/migrate.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            $env
        );
        $ausgabe = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        if ($code === 0 && !Totp::istKlartextSecret(self::secret($pdo, $id))) {
            $this->markTestSkipped('config/db_config.php liefert einen app_key - der Fall ohne Schlüssel ist hier nicht herstellbar.');
        }
        $this->assertSame(2, $code, $ausgabe);
        $this->assertStringContainsString('APP_KEY ist nicht gesetzt', $ausgabe);
        $this->assertSame('JBSWY3DPEHPK3PXP', self::secret($pdo, $id));
        $this->assertNull(AltbestandV072::einstellung($pdo, self::MARKER));
        $this->assertSame(SchemaMigrator::SCHEMA_VERSION - 1, SchemaMigrator::storedVersion($pdo));

        // Mit Schlüssel holt der nächste Lauf es nach.
        SchemaMigrator::run($pdo);
        $this->assertSame('JBSWY3DPEHPK3PXP', Crypto::decrypt(self::secret($pdo, $id)));
        $this->assertNotNull(AltbestandV072::einstellung($pdo, self::MARKER));
    }

    public function testOhneTotpSecretsNurVermerken(): void {
        $pdo = AltbestandV072::aktuell(self::db());
        SchemaMigrator::run($pdo);
        $this->assertNotNull(AltbestandV072::einstellung($pdo, self::MARKER), 'Nichts zu tun - trotzdem erledigt');
    }
}
