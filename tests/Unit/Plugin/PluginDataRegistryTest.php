<?php
// tests/Unit/Plugin/PluginDataRegistryTest.php

namespace Tests\Unit\Plugin;

use App\Plugin\PluginDataRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Prüft das Datenregister der Addon-Deinstallation (#338).
 *
 * Der Schwerpunkt liegt auf dem, was das Register ABLEHNEN muss. Ein Manifest
 * ist eine Datei im Addon-Verzeichnis - die Angaben darin sind eine
 * Behauptung des Addons. Wäre sie ungeprüft, trüge ein Addon
 * `"tables": ["users"]` ein, und ein Klick auf "Daten löschen" nähme die
 * Benutzerkonten mit.
 */
class PluginDataRegistryTest extends TestCase {

    private string $wurzel;

    protected function setUp(): void {
        $this->wurzel = sys_get_temp_dir() . '/hv-register-' . bin2hex(random_bytes(6));
        mkdir($this->wurzel . '/storage/plugin_demo', 0777, true);
        mkdir($this->wurzel . '/storage/logs', 0777, true);
        mkdir($this->wurzel . '/public/uploads', 0777, true);
        mkdir($this->wurzel . '/plugins', 0777, true);
        mkdir($this->wurzel . '/config', 0777, true);
    }

    protected function tearDown(): void {
        if (is_dir($this->wurzel)) {
            $eintraege = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->wurzel, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($eintraege as $eintrag) {
                $eintrag->isDir() ? @rmdir($eintrag->getPathname()) : @unlink($eintrag->getPathname());
            }
            @rmdir($this->wurzel);
        }
    }

    public function testNimmtEigeneTabellenVerzeichnisseUndEinstellungen(): void {
        $register = PluginDataRegistry::fuer([
            'owns' => [
                'tables' => ['plugin_demo_notizen'],
                'directories' => ['storage/plugin_demo'],
                'settings' => ['plugin_demo_begruessung'],
            ],
        ], $this->wurzel);

        $this->assertSame(['plugin_demo_notizen'], $register['tables']);
        $this->assertSame(['plugin_demo_begruessung'], $register['settings']);
        $this->assertCount(1, $register['directories']);
        $this->assertStringEndsWith('/storage/plugin_demo', $register['directories'][0]);
        $this->assertSame([], $register['abgelehnt']);
    }

    /**
     * Der Fall, gegen den die Präfix-Regel existiert: Ohne sie nähme ein
     * "Daten löschen" die Benutzerkonten mit.
     */
    public function testLehntFremdeTabelleAb(): void {
        $register = PluginDataRegistry::fuer([
            'owns' => ['tables' => ['users', 'plugin_demo_ok']],
        ], $this->wurzel);

        $this->assertSame(['plugin_demo_ok'], $register['tables'], 'users darf nicht im Register landen');
        $this->assertCount(1, $register['abgelehnt']);
        $this->assertStringContainsString('users', $register['abgelehnt'][0]);
    }

    public function testLehntFremdenEinstellungsschluesselAb(): void {
        $register = PluginDataRegistry::fuer([
            'owns' => ['settings' => ['site_name']],
        ], $this->wurzel);

        $this->assertSame([], $register['settings']);
        $this->assertCount(1, $register['abgelehnt']);
    }

    /**
     * Geprüft wird über realpath(), nicht über die Zeichenkette - sonst käme
     * man mit '../' heraus.
     */
    public function testLehntVerzeichnisAusserhalbDerInstallationAb(): void {
        $register = PluginDataRegistry::fuer([
            'owns' => ['directories' => ['storage/plugin_demo/../../..']],
        ], $this->wurzel);

        $this->assertSame([], $register['directories']);
        $this->assertNotSame([], $register['abgelehnt']);
    }

    /**
     * @param string $verzeichnis
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('geschuetzteOrte')]
    public function testLehntGeschuetzteOrteAb(string $verzeichnis): void {
        $register = PluginDataRegistry::fuer([
            'owns' => ['directories' => [$verzeichnis]],
        ], $this->wurzel);

        $this->assertSame([], $register['directories'], "{$verzeichnis} darf nicht löschbar sein");
        $this->assertNotSame([], $register['abgelehnt']);
    }

    public static function geschuetzteOrte(): array {
        return [
            'Upload-Verzeichnis' => ['public/uploads'],
            'Addon-Verzeichnis' => ['plugins'],
            'Konfiguration' => ['config'],
            'Protokolle' => ['storage/logs'],
            // Der interessante Fall: Ein Addon, das "storage" beansprucht,
            // nähme storage/logs mit - obwohl "storage" selbst nirgends in
            // der Tabu-Liste steht.
            'Elternverzeichnis eines geschützten Ortes' => ['storage'],
        ];
    }

    public function testFehlendesVerzeichnisIstKeinFehler(): void {
        $register = PluginDataRegistry::fuer([
            'owns' => ['directories' => ['storage/gibt-es-nicht']],
        ], $this->wurzel);

        $this->assertSame([], $register['directories']);
        $this->assertSame([], $register['abgelehnt'], 'Ein bereits entferntes Verzeichnis ist keine Auffälligkeit');
    }

    public function testOhneRegisterPassiertNichts(): void {
        $register = PluginDataRegistry::fuer([], $this->wurzel);

        $this->assertSame([], $register['tables']);
        $this->assertSame([], $register['directories']);
        $this->assertSame([], $register['settings']);
        $this->assertSame([], $register['abgelehnt']);
    }

    public function testKaputtesRegisterWirdGemeldetStattIgnoriert(): void {
        $register = PluginDataRegistry::fuer(['owns' => 'alles'], $this->wurzel);

        $this->assertNotSame([], $register['abgelehnt'], 'Ein unbrauchbares "owns" darf nicht stillschweigend durchgehen');
    }

    // ---- Deklariert vs. nicht deklariert (Audit M30) -------------------

    /**
     * Ein leeres Register ist eine Aussage ("ich lege nichts an"), ein
     * fehlendes nicht. Die Rückfrage darf beides nicht gleich behandeln -
     * vorher stand in beiden Fällen "rückstandsfrei".
     */
    public function testDeklariertUnterscheidetFehlendesVonLeeremRegister(): void {
        $this->assertFalse(PluginDataRegistry::fuer([], $this->wurzel)['deklariert']);
        $this->assertTrue(PluginDataRegistry::fuer(['owns' => []], $this->wurzel)['deklariert']);
        $this->assertTrue(PluginDataRegistry::fuer(['owns' => ['tables' => ['plugin_demo_x']]], $this->wurzel)['deklariert']);
        $this->assertFalse(PluginDataRegistry::fuer(['owns' => 'alles'], $this->wurzel)['deklariert']);

        // vorschau() reicht den Schlüssel durch (ohne Datenbank: keine Zahlen).
        $this->assertTrue(PluginDataRegistry::vorschau(PluginDataRegistry::fuer(['owns' => []], $this->wurzel))['deklariert']);
        $this->assertFalse(PluginDataRegistry::vorschau(PluginDataRegistry::fuer([], $this->wurzel))['deklariert']);
    }

    // ---- Unregistrierte Reste (Audit M30) ------------------------------

    /** Bindestriche im Slug werden zu Unterstrichen, wie in den Tabellennamen der Addons. */
    public function testUnregistrierteResteFindetTabellenMitSlugPraefix(): void {
        $reste = PluginDataRegistry::unregistrierteReste(
            'verkaufs-boerse',
            ['tables' => [], 'directories' => []],
            ['plugin_verkaufs_boerse_listings', 'plugin_verkaufs_boerse', 'plugin_anderes', 'users', 'plugin_verkaufsboerse_x'],
            [],
            $this->wurzel
        );

        $this->assertSame(['plugin_verkaufs_boerse', 'plugin_verkaufs_boerse_listings'], $reste['tables']);
        $this->assertSame([], $reste['directories']);
    }

    public function testUnregistrierteResteIgnoriertRegistrierteUndFremdRegistrierteTabellen(): void {
        $reste = PluginDataRegistry::unregistrierteReste(
            'demo',
            ['tables' => ['plugin_demo_notizen'], 'directories' => []],
            ['plugin_demo_notizen', 'plugin_demo_geteilt', 'plugin_demo_rest', 'plugin_demo_extra_daten'],
            ['plugin_demo_geteilt'],
            $this->wurzel,
            // Ein anderes Addon "demo-extra": Seine Tabellen gehören eher ihm.
            ['demo-extra']
        );

        $this->assertSame(['plugin_demo_rest'], $reste['tables']);
    }

    /** plugin_foo darf plugin_foobar nicht treffen - nur das Präfix selbst oder Präfix + "_". */
    public function testPraefixOhneTrennerTrifftKeinAnderesAddon(): void {
        $reste = PluginDataRegistry::unregistrierteReste(
            'foo',
            ['tables' => [], 'directories' => []],
            ['plugin_foobar', 'plugin_foobar_x', 'plugin_foo', 'plugin_foo_x'],
            [],
            $this->wurzel
        );

        $this->assertSame(['plugin_foo', 'plugin_foo_x'], $reste['tables']);
    }

    public function testUnregistrierteResteFindetAblageverzeichnisAberKeineTabuOrte(): void {
        mkdir($this->wurzel . '/storage/plugin_demo_archiv');
        mkdir($this->wurzel . '/storage/plugin_demobar');
        // Ein Symlink mit passendem Namen, der auf einen geschützten Ort zeigt:
        // darf weder gemeldet noch (später) angefasst werden.
        symlink($this->wurzel . '/storage/logs', $this->wurzel . '/storage/plugin_demo_logs');
        $aussen = sys_get_temp_dir() . '/hv-register-aussen-' . bin2hex(random_bytes(4));
        mkdir($aussen);
        symlink($aussen, $this->wurzel . '/storage/plugin_demo_aussen');
        try {
            $register = PluginDataRegistry::fuer(['owns' => ['directories' => ['storage/plugin_demo']]], $this->wurzel);
            $reste = PluginDataRegistry::unregistrierteReste('demo', $register, [], [], $this->wurzel);

            $this->assertSame([realpath($this->wurzel . '/storage/plugin_demo_archiv')], $reste['directories']);

            // Ohne Register ist auch storage/plugin_demo selbst ein Rest.
            $ohne = PluginDataRegistry::unregistrierteReste('demo', ['tables' => [], 'directories' => []], [], [], $this->wurzel);
            $this->assertSame([
                realpath($this->wurzel . '/storage/plugin_demo'),
                realpath($this->wurzel . '/storage/plugin_demo_archiv'),
            ], $ohne['directories']);
        } finally {
            @unlink($this->wurzel . '/storage/plugin_demo_logs');
            @unlink($this->wurzel . '/storage/plugin_demo_aussen');
            @rmdir($aussen);
        }
    }
}
