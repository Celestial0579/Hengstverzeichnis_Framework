<?php
// tests/Unit/Helper/BoundedKeyTest.php

namespace Tests\Unit\Helper;

use App\Helper\BoundedKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Schlüssel, die in ihre Spalte passen (Audit N74).
 *
 * Kern sind zwei Zusagen: Alle heute gebildeten Schlüssel bleiben bitgleich
 * (sonst gingen gespeicherte Einstellungen verloren), und überlange werden
 * deterministisch auf genau die Spaltenlänge gebracht.
 */
class BoundedKeyTest extends TestCase {

    /**
     * @return array<string, array{0:string, 1:string}>
     */
    public static function realKeys(): array {
        return [
            'Kontaktanfrage-Aufräumen' => ['cron_last_run__', 'kontaktanfrage.aufraeumen'],
            'Mitglieder-Abgleich' => ['cron_last_run__', 'mitglieder-konten.abgleich'],
            'Backup' => ['cron_last_run__', 'backup.external'],
            'Unbestätigte Konten' => ['cron_last_run__', 'users.purge_unverified'],
            'Referenz-Feature' => ['feature_visibility__', 'beispiel-schaufenster'],
            'Demo-Feature' => ['feature_visibility__', 'demo-premium'],
            'Captcha-DSGVO' => ['captcha_provider_', 'dsgvo'],
        ];
    }

    #[DataProvider('realKeys')]
    public function testRealKeysStayUnchanged(string $prefix, string $suffix): void {
        $this->assertSame($prefix . $suffix, BoundedKey::fit($prefix, $suffix));
    }

    public function testExactlyFiftyCharactersStayUnchanged(): void {
        $suffix = str_repeat('a', 50 - strlen('cron_last_run__'));
        $this->assertSame('cron_last_run__' . $suffix, BoundedKey::fit('cron_last_run__', $suffix));
    }

    public function testFiftyOneCharactersAreHashedToExactlyFifty(): void {
        $suffix = str_repeat('a', 51 - strlen('cron_last_run__'));
        $key = BoundedKey::fit('cron_last_run__', $suffix);

        $this->assertSame(50, mb_strlen($key));
        $this->assertStringStartsWith('cron_last_run__', $key);
        $this->assertSame('cron_last_run__' . substr(hash('sha256', $suffix), 0, 35), $key);
    }

    public function testHashIsDeterministicAndDistinguishesSuffixes(): void {
        $a = str_repeat('x', 60) . '.eins';
        $b = str_repeat('x', 60) . '.zwei';

        $this->assertSame(BoundedKey::fit('feature_', $a), BoundedKey::fit('feature_', $a));
        $this->assertNotSame(BoundedKey::fit('feature_', $a), BoundedKey::fit('feature_', $b));
    }

    public function testMultibyteNamesAreCountedInCharacters(): void {
        // 35 Zeichen, aber 70 Bytes: passt in VARCHAR(50) (Zeichen) und
        // bleibt deshalb unverändert.
        $suffix = str_repeat('ä', 35);
        $this->assertSame('cron_last_run__' . $suffix, BoundedKey::fit('cron_last_run__', $suffix));

        $longer = str_repeat('ä', 36);
        $this->assertSame(50, mb_strlen(BoundedKey::fit('cron_last_run__', $longer)));
    }

    public function testTooLongPrefixIsAProgrammingError(): void {
        $this->expectException(\LogicException::class);
        BoundedKey::fit(str_repeat('p', 40), 'x');
    }

    /**
     * Die Konstanten müssen zu den Spalten passen - sonst hasht der Helfer
     * zu spät (Strict-Mode-Fehler) oder unnötig früh.
     */
    public function testConstantsMatchSchemaColumns(): void {
        $schema = (string)file_get_contents(__DIR__ . '/../../../database/schema.sql');

        $this->assertMatchesRegularExpression('/`setting_key` VARCHAR\(' . BoundedKey::SETTING_KEY_LENGTH . '\)/', $schema);

        $this->assertSame(1, preg_match('/CREATE TABLE IF NOT EXISTS `group_permissions` \((.*?)\n\)/s', $schema, $m), 'group_permissions fehlt im Schema');
        $this->assertMatchesRegularExpression('/`module` VARCHAR\(' . BoundedKey::PERMISSION_MODULE_LENGTH . '\)/', $m[1]);
    }
}
