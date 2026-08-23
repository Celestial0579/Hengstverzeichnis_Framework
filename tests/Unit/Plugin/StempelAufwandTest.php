<?php
// tests/Unit/Plugin/StempelAufwandTest.php

namespace Tests\Unit\Plugin;

use App\Plugin\PluginManager;
use PHPUnit\Framework\TestCase;

/**
 * Der Aufwand des Verzeichnis-Stempels und seine Wachstumsgrenze (#400).
 *
 * ## Warum die Entscheidung „nichts tun" einen Test bekommt
 *
 * Weil sie sonst wie ein Versäumnis aussieht. Der Stempel läuft bei JEDER
 * Anfrage über jede Datei jedes aktivierten Addons — das ist die
 * Manipulationserkennung aus #224, und die Frequenz ist der Grund, warum sie
 * trägt. Wer das für unoptimiert hält und einen Zwischenspeicher einbaut,
 * schafft ein Fenster, in dem eine geänderte Datei als freigegeben gilt.
 *
 * Gemessen kostet der Lauf heute rund 4 % einer Seitenanfrage. Dafür die
 * Erkennung zu schwächen wäre ein schlechtes Geschäft. Was stattdessen
 * eingebaut wurde, ist eine Grenze: Wenn es teuer wird, steht es im
 * Adminbereich — statt jemandem als „die Seite ist langsam geworden"
 * aufzufallen, der die Ursache nicht kennt.
 */
class StempelAufwandTest extends TestCase {

    /** @var array<int, string> */
    private array $aufraeumen = [];

    protected function setUp(): void {
        PluginManager::stempelZaehlerZuruecksetzen();
    }

    protected function tearDown(): void {
        PluginManager::stempelZaehlerZuruecksetzen();
        $this->raeumeAuf();
    }

    /** Ohne Lauf gibt es nichts zu melden. */
    public function testOhneStempelLaufIstNichtsTeuer(): void {
        $this->assertSame(0, PluginManager::stempelDateien());
        $this->assertSame(0.0, PluginManager::stempelDauerMs());
        $this->assertFalse(PluginManager::stempelIstTeuer());
    }

    /**
     * Der Zähler zählt, was der Stempel WIRKLICH geprüft hat.
     *
     * Gezählt werden DATEIEN, nicht Verzeichnisse: Der Iterator liefert im
     * Standardmodus keine Verzeichnisse, und ihn dafür umzustellen hieße,
     * einen Sicherheitspfad für eine Anzeige anzufassen. Das Kostenmodell ist
     * entsprechend auf Dateien geeicht (siehe
     * PluginManager::STEMPEL_MIKROSEKUNDEN_JE_DATEI).
     *
     * Ein deaktiviertes oder memoisiertes Addon taucht nicht auf, weil der
     * Zähler in computeDirStamp() sitzt und nicht in einer eigenen Schleife.
     */
    public function testDerZaehlerZaehltDieGeprueftenDateien(): void {
        $wurzel = $this->baueBaum([
            'a/Plugin.php'        => '<?php',
            'a/lang/de.php'       => '<?php',
            'a/lang/en.php'       => '<?php',
            'a/views/liste.php'   => '<?php',
            'a/assets/stil.css'   => 'body{}',
            'a/assets/bild.svg'   => '<svg/>',
            'a/manifest.json'     => '{}',
        ]);

        $this->rufeComputeDirStamp($wurzel . '/a');

        // 7 Dateien in 3 Unterverzeichnissen - die Verzeichnisse selbst
        // zaehlen nicht mit.
        $this->assertSame(7, PluginManager::stempelDateien());
    }

    /** Mehrere Addons summieren sich - der Zähler gilt für den ganzen Request. */
    public function testMehrereAddonsSummierenSich(): void {
        $wurzel = $this->baueBaum([
            'a/Plugin.php' => '<?php',
            'b/Plugin.php' => '<?php',
        ]);

        $this->rufeComputeDirStamp($wurzel . '/a');
        $this->rufeComputeDirStamp($wurzel . '/b');

        $this->assertSame(2, PluginManager::stempelDateien());
    }

    /**
     * Das Kostenmodell stammt aus einer Messung, nicht aus einem Gefühl.
     *
     * 4,5 µs je Eintrag, gemessen über fünf Punkte von 81 bis 10.980
     * Einträgen (#400). Dieser Test hält den Zusammenhang fest, damit
     * niemand die Konstante ändert, ohne die Anzeige mitzudenken.
     */
    public function testDauerFolgtDemGemessenenModell(): void {
        $wurzel = $this->baueBaum(array_combine(
            array_map(static fn(int $i): string => "a/datei{$i}.php", range(1, 100)),
            array_fill(0, 100, '<?php')
        ));

        $this->rufeComputeDirStamp($wurzel . '/a');

        $this->assertSame(100, PluginManager::stempelDateien());
        // 100 x 7,8 us = 0,78 ms
        $this->assertEqualsWithDelta(0.78, PluginManager::stempelDauerMs(), 0.001);
    }

    /**
     * Die Grenze schlägt an - und zwar erst weit oberhalb des heutigen
     * Bestands. Eine Instanz mit 20 Addons liegt bei 60 Dateien, das gesamte
     * Addons-Repo mit 36 Addons bei 126. Die Warnung darf dort nicht
     * erscheinen, sonst wird sie zum Rauschen und niemand liest sie, wenn sie
     * einmal zählt.
     */
    public function testDieGrenzeSchlaegtErstBeiVielenEintraegenAn(): void {
        $wurzel = $this->baueBaum(array_combine(
            array_map(static fn(int $i): string => "a/datei{$i}.php", range(1, 200)),
            array_fill(0, 200, '<?php')
        ));

        // Der heutige Gesamtbestand liegt weit darunter.
        $this->rufeComputeDirStamp($wurzel . '/a');
        $this->assertFalse(
            PluginManager::stempelIstTeuer(),
            '200 Dateien sind mehr als das gesamte Addons-Repo (126) - '
            . 'und noch lange kein Grund zu warnen.'
        );

        // Dreimal derselbe Baum bringt den Zähler über die Schwelle.
        for ($i = 0; $i < 2; $i++) {
            $this->rufeComputeDirStamp($wurzel . '/a');
        }

        $this->assertSame(600, PluginManager::stempelDateien());
        $this->assertTrue(PluginManager::stempelIstTeuer());
    }

    // ---- Hilfen ----------------------------------------------------------

    /** @param array<string, string> $dateien */
    private function baueBaum(array $dateien): string {
        $wurzel = rtrim(sys_get_temp_dir(), '/') . '/hv_stempel_' . bin2hex(random_bytes(6));
        foreach ($dateien as $pfad => $inhalt) {
            $voll = $wurzel . '/' . $pfad;
            if (!is_dir(dirname($voll))) {
                mkdir(dirname($voll), 0755, true);
            }
            file_put_contents($voll, $inhalt);
        }
        $this->aufraeumen[] = $wurzel;
        return $wurzel;
    }

    /**
     * computeDirStamp() ist privat - und soll es bleiben. Sie ist ein
     * Innenteil der Freigabe-Kette, keine Schnittstelle.
     */
    private function rufeComputeDirStamp(string $dir): string {
        $methode = new \ReflectionMethod(PluginManager::class, 'computeDirStamp');
        $instanz = (new \ReflectionClass(PluginManager::class))->newInstanceWithoutConstructor();
        return (string)$methode->invoke($instanz, $dir);
    }

    private function raeumeAuf(): void {
        foreach ($this->aufraeumen as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $eintrag) {
                $eintrag->isDir() ? @rmdir($eintrag->getPathname()) : @unlink($eintrag->getPathname());
            }
            @rmdir($dir);
        }
        $this->aufraeumen = [];
    }
}
