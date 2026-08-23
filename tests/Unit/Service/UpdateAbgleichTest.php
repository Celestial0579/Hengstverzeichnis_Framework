<?php
// tests/Unit/Service/UpdateAbgleichTest.php

namespace Tests\Unit\Service;

use App\Service\Integritaet;
use App\Service\UpdateService;
use PHPUnit\Framework\TestCase;

/**
 * Tests für den Abgleich der KERN-Pfade beim Update (#403).
 *
 * Der Anlass steht in #403: Mit #344 wanderten zehn Sprachen aus `lang/` in
 * Addons. `copyTree()` löscht nie, also blieben die alten Kerndateien auf
 * jeder in-place aktualisierten Installation liegen - und
 * `Translator::loadTable()` zieht die Kerndatei dem Addon ausdrücklich vor.
 *
 * Warum das die vorhandenen Update-Tests nicht gefunden haben: Sie bauen ihre
 * Archive synthetisch Datei für Datei auf und legen sie über ein Ziel, das
 * genau dazu passt. Ein Ziel, das MEHR enthält als das Archiv, kam darin nie
 * vor - und das ist der ganze Fall.
 */
class UpdateAbgleichTest extends TestCase {

    /** @var array<int, string> */
    private array $aufraeumen = [];

    protected function tearDown(): void {
        foreach ($this->aufraeumen as $dir) {
            $this->removeTree($dir);
        }
        $this->aufraeumen = [];
    }

    // ---- Der Fall aus #403 ----------------------------------------------

    /**
     * Der Kern liefert nur noch de/en; die Installation hat aus v0.8.0 noch
     * zehn weitere Sprachen. Nach dem Update müssen sie weg sein.
     */
    public function testAbgeloesteSprachdateienWerdenEntfernt(): void {
        $abgeloest = ['cs', 'da', 'fi', 'fr', 'it', 'lb', 'nb', 'nl', 'pl', 'sv'];

        $alteSprachdateien = array_combine(
            array_map(static fn(string $l): string => "lang/{$l}.php", $abgeloest),
            array_fill(0, count($abgeloest), "<?php\nreturn ['a' => 'v0.8.0'];\n")
        );

        $archiv = $this->baueArchiv([
            'config/config.php' => "<?php\nconst CORE_VERSION = '0.9.0';\n",
            'lang/de.php'       => "<?php\nreturn ['a' => 'A'];\n",
            'lang/en.php'       => "<?php\nreturn ['a' => 'A'];\n",
            'src/App.php'       => "<?php\n// neu\n",
        ], $alteSprachdateien);

        $ziel = $this->baueInstallation(array_merge(
            [
                'config/config.php' => "<?php\nconst CORE_VERSION = '0.8.0';\n",
                'lang/de.php'       => "<?php\nreturn ['a' => 'alt'];\n",
                'lang/en.php'       => "<?php\nreturn ['a' => 'alt'];\n",
                'src/App.php'       => "<?php\n// alt\n",
            ],
            $alteSprachdateien
        ));

        UpdateService::applyUpdateArchive($archiv, $ziel);

        foreach ($abgeloest as $locale) {
            $this->assertFileDoesNotExist(
                "{$ziel}/lang/{$locale}.php",
                "lang/{$locale}.php stammt aus v0.8.0 und wird vom Release nicht mehr "
                . "mitgeliefert - es muss beim Update verschwinden, sonst gewinnt es "
                . "im Translator gegen das Sprach-Addon (#403)."
            );
        }

        // Und die, die es noch gibt, sind auf dem neuen Stand.
        $this->assertFileExists("{$ziel}/lang/de.php");
        $this->assertStringContainsString("'A'", (string)file_get_contents("{$ziel}/lang/de.php"));
    }

    /** Auch ein ganzes abgelöstes Kern-Verzeichnis muss verschwinden. */
    public function testAbgeloestesKernVerzeichnisVerschwindetGanz(): void {
        $archiv = $this->baueArchiv([
            'config/config.php' => "<?php\nconst CORE_VERSION = '0.9.0';\n",
            'src/Neu.php'       => "<?php\n",
        ], [
            'src/Alt/Weg.php'            => "<?php\n// weg\n",
            'src/Alt/Tiefer/AuchWeg.php' => "<?php\n// auch weg\n",
        ]);
        $ziel = $this->baueInstallation([
            'config/config.php'          => "<?php\nconst CORE_VERSION = '0.8.0';\n",
            'src/Neu.php'                => "<?php\n",
            'src/Alt/Weg.php'            => "<?php\n// weg\n",
            'src/Alt/Tiefer/AuchWeg.php' => "<?php\n// auch weg\n",
        ]);

        UpdateService::applyUpdateArchive($archiv, $ziel);

        $this->assertDirectoryDoesNotExist("{$ziel}/src/Alt");
    }

    // ---- Was der Abgleich NICHT anfassen darf ---------------------------

    /**
     * Betreiberdaten bleiben, auch wenn sie in einem KERN-Verzeichnis liegen.
     * public/ gehört dem Kern, public/uploads dem Betreiber - das ist der
     * Grund, warum die Baumordnung den längsten Eintrag gewinnen lässt.
     */
    public function testBetreiberdatenInEinemKernVerzeichnisBleiben(): void {
        $archiv = $this->baueArchiv([
            'config/config.php'  => "<?php\nconst CORE_VERSION = '0.9.0';\n",
            'public/index.php'   => "<?php\n// neu\n",
            'public/css/app.css' => "body{}\n",
        ]);
        $ziel = $this->baueInstallation([
            'config/config.php'            => "<?php\nconst CORE_VERSION = '0.8.0';\n",
            'public/index.php'             => "<?php\n// alt\n",
            'public/css/app.css'           => "body{old}\n",
            'public/uploads/bild.jpg'      => "JPEGDATEN",
            'public/uploads/tief/mehr.jpg' => "MEHR",
            'storage/horses/7/foto.jpg'    => "FOTO",
            'plugins/fremd/plugin.php'     => "<?php\n",
            'config/db_config.php'         => "<?php\n// Zugangsdaten\n",
            '.env'                         => "GEHEIM=1\n",
        ]);

        UpdateService::applyUpdateArchive($archiv, $ziel);

        foreach ([
            'public/uploads/bild.jpg', 'public/uploads/tief/mehr.jpg',
            'storage/horses/7/foto.jpg', 'plugins/fremd/plugin.php',
            'config/db_config.php', '.env',
        ] as $pfad) {
            $this->assertFileExists("{$ziel}/{$pfad}", "{$pfad} gehört dem Betreiber und muss bleiben.");
        }
    }

    /** In Protokollen steht Betriebsgeschichte - LAUFZEIT wird nicht abgeglichen. */
    public function testProtokolleBleibenLiegen(): void {
        $archiv = $this->baueArchiv([
            'config/config.php'      => "<?php\nconst CORE_VERSION = '0.9.0';\n",
            'storage/logs/.gitkeep'  => "",
        ]);
        $ziel = $this->baueInstallation([
            'config/config.php'     => "<?php\nconst CORE_VERSION = '0.8.0';\n",
            'storage/logs/.gitkeep' => "",
            'storage/logs/app.log'  => "wichtig\n",
        ]);

        UpdateService::applyUpdateArchive($archiv, $ziel);

        $this->assertFileExists("{$ziel}/storage/logs/app.log");
    }

    /**
     * Ein Verzeichnis, das niemand eingeordnet hat, bleibt unangetastet.
     * Fail-closed in die richtige Richtung: im Zweifel eine Leiche, nie ein
     * Datenverlust.
     */
    public function testNichtEingeordnetesVerzeichnisBleibt(): void {
        $archiv = $this->baueArchiv([
            'config/config.php' => "<?php\nconst CORE_VERSION = '0.9.0';\n",
        ]);
        $ziel = $this->baueInstallation([
            'config/config.php'          => "<?php\nconst CORE_VERSION = '0.8.0';\n",
            'irgendwas-eigenes/datei.txt' => "vom Betreiber\n",
        ]);

        UpdateService::applyUpdateArchive($archiv, $ziel);

        $this->assertFileExists("{$ziel}/irgendwas-eigenes/datei.txt");
    }

    /**
     * Bringt das Archiv ein Kern-Verzeichnis GAR NICHT mit, wird das Ziel
     * nicht geleert. "Fehlt im Archiv" heißt "unvollständiges Archiv", nicht
     * "alles löschen" - sonst machte ein abgeschnittenes Zip aus einem Update
     * eine Löschung.
     */
    public function testFehltDasVerzeichnisImArchivWirdNichtsGeloescht(): void {
        $archiv = $this->baueArchiv([
            'config/config.php' => "<?php\nconst CORE_VERSION = '0.9.0';\n",
            'src/Neu.php'       => "<?php\n",
        ]);
        $ziel = $this->baueInstallation([
            'config/config.php' => "<?php\nconst CORE_VERSION = '0.8.0';\n",
            'src/Neu.php'       => "<?php\n",
            'lang/de.php'       => "<?php\nreturn [];\n",
            'lang/fr.php'       => "<?php\nreturn [];\n",
        ]);

        UpdateService::applyUpdateArchive($archiv, $ziel);

        $this->assertFileExists("{$ziel}/lang/de.php", 'Das Archiv bringt lang/ nicht mit - dann wird lang/ auch nicht geleert.');
        $this->assertFileExists("{$ziel}/lang/fr.php");
    }

    // ---- Der Beweis --------------------------------------------------------

    /**
     * DER Fall, an dem die erste Fassung dieses Abgleichs gescheitert wäre.
     *
     * Ein Betreiber hat eine eigene Übersetzung nach `lang/` gelegt - die
     * Vorrangregel in `Translator::loadTable()` gibt das ausdrücklich her.
     * Für das Update sieht sie genauso aus wie eine Leiche aus v0.8.0: eine
     * Datei in einem Kern-Verzeichnis, die das Archiv nicht mitbringt.
     *
     * Der Unterschied ist der Inhalt. Passt die Prüfsumme nicht zu dem, was
     * wir je ausgeliefert haben, gehört die Datei jemand anderem.
     */
    public function testEigeneDateiDesBetreibersBleibtUndWirdGemeldet(): void {
        $archiv = $this->baueArchiv([
            'config/config.php' => "<?php\nconst CORE_VERSION = '0.9.0';\n",
            'lang/de.php'       => "<?php\nreturn ['a' => 'A'];\n",
        ], [
            // Das haben WIR mal ausgeliefert.
            'lang/fr.php' => "<?php\nreturn ['a' => 'unsere Fassung'];\n",
        ]);

        $ziel = $this->baueInstallation([
            'config/config.php' => "<?php\nconst CORE_VERSION = '0.8.0';\n",
            'lang/de.php'       => "<?php\nreturn ['a' => 'alt'];\n",
            // Der Betreiber hat sie angepasst - andere Prüfsumme.
            'lang/fr.php'       => "<?php\nreturn ['a' => 'meine eigene Uebersetzung'];\n",
            // Und eine ganz eigene Sprache dazugelegt.
            'lang/xx.php'       => "<?php\nreturn ['a' => 'Fantasiesprache'];\n",
        ]);

        UpdateService::applyUpdateArchive($archiv, $ziel);

        $this->assertFileExists("{$ziel}/lang/fr.php", 'Angepasste Datei: Prüfsumme passt nicht, also bleibt sie.');
        $this->assertStringContainsString(
            'meine eigene Uebersetzung',
            (string)file_get_contents("{$ziel}/lang/fr.php")
        );
        $this->assertFileExists("{$ziel}/lang/xx.php", 'Unbekannte Datei: nie von uns, also bleibt sie.');

        $this->assertEqualsCanonicalizing(
            ['lang/fr.php', 'lang/xx.php'],
            UpdateService::unklareFunde(),
            'Was nicht entfernt wurde, muss gemeldet werden - eine Leiche, von der niemand weiß, '
            . 'ist genau der Zustand, aus dem #403 entstanden ist.'
        );
    }

    /**
     * Dieselbe Datei, unverändert: Prüfsumme passt, also ist bewiesen, dass
     * sie von uns stammt - und sie wird entfernt. Der Gegentest zum
     * vorherigen; ohne ihn wäre "bleibt liegen" trivial zu erfüllen.
     */
    public function testUnveraenderteDateiWirdEntferntUndNichtGemeldet(): void {
        $unsere = "<?php\nreturn ['a' => 'unsere Fassung'];\n";

        $archiv = $this->baueArchiv([
            'config/config.php' => "<?php\nconst CORE_VERSION = '0.9.0';\n",
            'lang/de.php'       => "<?php\nreturn ['a' => 'A'];\n",
        ], ['lang/fr.php' => $unsere]);

        $ziel = $this->baueInstallation([
            'config/config.php' => "<?php\nconst CORE_VERSION = '0.8.0';\n",
            'lang/de.php'       => "<?php\nreturn ['a' => 'alt'];\n",
            'lang/fr.php'       => $unsere,
        ]);

        UpdateService::applyUpdateArchive($archiv, $ziel);

        $this->assertFileDoesNotExist("{$ziel}/lang/fr.php");
        $this->assertSame([], UpdateService::unklareFunde());
    }

    /**
     * Ein Pfad kann über die Versionen mehrere Inhalte gehabt haben - die
     * Liste führt ihn dann mehrfach. Eine Installation, die auf einem
     * ÄLTEREN Stand stehengeblieben ist, muss trotzdem aufgeräumt werden.
     */
    public function testAuchEinAelterInhaltDesselbenPfadesGiltAlsBeweis(): void {
        $sehrAlt = "<?php\nreturn ['a' => 'Fassung aus v0.4.0'];\n";

        $archiv = $this->baueArchiv([
            'config/config.php' => "<?php\nconst CORE_VERSION = '0.9.0';\n",
            'lang/de.php'       => "<?php\nreturn [];\n",
        ], []);

        // Beweisliste von Hand: derselbe Pfad mit ZWEI Inhalten.
        $this->ergaenzeBeweise($archiv, [
            ['lang/fr.php', $sehrAlt],
            ['lang/fr.php', "<?php\nreturn ['a' => 'Fassung aus v0.8.0'];\n"],
        ]);

        $ziel = $this->baueInstallation([
            'config/config.php' => "<?php\nconst CORE_VERSION = '0.4.0';\n",
            'lang/de.php'       => "<?php\nreturn [];\n",
            'lang/fr.php'       => $sehrAlt,
        ]);

        UpdateService::applyUpdateArchive($archiv, $ziel);

        $this->assertFileDoesNotExist("{$ziel}/lang/fr.php");
    }

    /**
     * Ohne Beweisliste im Archiv wird NICHTS entfernt und ALLES gemeldet.
     *
     * Das ist der Übergangsfall: Releases vor #403 bringen die Liste nicht
     * mit. Ein Update mit einem solchen Archiv darf nicht raten - es hat
     * keine Grundlage dafür.
     */
    public function testOhneBeweislisteWirdNichtsEntfernt(): void {
        $archiv = $this->baueArchiv([
            'config/config.php' => "<?php\nconst CORE_VERSION = '0.9.0';\n",
            'lang/de.php'       => "<?php\nreturn [];\n",
        ], null);

        $ziel = $this->baueInstallation([
            'config/config.php' => "<?php\nconst CORE_VERSION = '0.8.0';\n",
            'lang/de.php'       => "<?php\nreturn [];\n",
            'lang/fr.php'       => "<?php\nreturn [];\n",
            'lang/it.php'       => "<?php\nreturn [];\n",
        ]);

        UpdateService::applyUpdateArchive($archiv, $ziel);

        $this->assertFileExists("{$ziel}/lang/fr.php");
        $this->assertFileExists("{$ziel}/lang/it.php");
        $this->assertEqualsCanonicalizing(
            ['lang/fr.php', 'lang/it.php'],
            UpdateService::unklareFunde()
        );
    }

    /**
     * Ein Beweis gilt nur für SEINEN Pfad. Dieselbe Prüfsumme unter einem
     * anderen Namen ist kein Freibrief - sonst genügte es, eine beliebige
     * abgelöste Datei an eine andere Stelle zu kopieren, um sie löschbar zu
     * machen.
     */
    public function testBeweisGiltNurFuerSeinenPfad(): void {
        $inhalt = "<?php\nreturn ['a' => 'x'];\n";

        $archiv = $this->baueArchiv([
            'config/config.php' => "<?php\nconst CORE_VERSION = '0.9.0';\n",
            'lang/de.php'       => "<?php\nreturn [];\n",
        ], ['lang/fr.php' => $inhalt]);

        $ziel = $this->baueInstallation([
            'config/config.php' => "<?php\nconst CORE_VERSION = '0.8.0';\n",
            'lang/de.php'       => "<?php\nreturn [];\n",
            'lang/it.php'       => $inhalt,   // gleicher Inhalt, anderer Pfad
        ]);

        UpdateService::applyUpdateArchive($archiv, $ziel);

        $this->assertFileExists("{$ziel}/lang/it.php");
        $this->assertSame(['lang/it.php'], UpdateService::unklareFunde());
    }

    /**
     * Die ZWEITE Beweisquelle: das Manifest der Installation selbst.
     *
     * `ABGELOESTE-DATEIEN.txt` entsteht aus der Git-Historie und kann deshalb
     * nichts über Dateien sagen, die erst beim Bauen entstehen - ein
     * künftiges `vendor/` etwa. Das eigene Manifest schließt die Lücke: Was
     * ihm exakt entspricht, ist nachweislich unsere Datei und seither
     * unangetastet.
     */
    public function testDasEigeneManifestGiltEbenfallsAlsBeweis(): void {
        $alt = "<?php\n// Fassung, die diese Installation ausgeliefert bekam\n";

        // Das Archiv kennt die Datei NICHT und hat auch keinen Beweis dafür.
        $archiv = $this->baueArchiv([
            'config/config.php' => "<?php\nconst CORE_VERSION = '0.9.0';\n",
            'src/Neu.php'       => "<?php\n",
        ], []);

        $ziel = $this->baueInstallation([
            'config/config.php' => "<?php\nconst CORE_VERSION = '0.8.0';\n",
            'src/Neu.php'       => "<?php\n",
            'src/Alt.php'       => $alt,
        ]);

        // ... aber die Installation weiß, dass sie sie mal bekommen hat.
        file_put_contents(
            $ziel . '/' . Integritaet::MANIFEST,
            "# Manifest dieser Installation\n" . hash('sha256', $alt) . "  src/Alt.php\n"
        );

        UpdateService::applyUpdateArchive($archiv, $ziel);

        $this->assertFileDoesNotExist($ziel . '/src/Alt.php');
        $this->assertSame([], UpdateService::unklareFunde());
    }

    /**
     * Und die Gegenprobe dazu: Steht die Datei im eigenen Manifest, wurde
     * aber seither GEÄNDERT, ist der Beweis hinfällig. Sie bleibt.
     */
    public function testGeaenderteDateiGiltAuchMitManifestNichtAlsBeweisbar(): void {
        $archiv = $this->baueArchiv([
            'config/config.php' => "<?php\nconst CORE_VERSION = '0.9.0';\n",
            'src/Neu.php'       => "<?php\n",
        ], []);

        $ziel = $this->baueInstallation([
            'config/config.php' => "<?php\nconst CORE_VERSION = '0.8.0';\n",
            'src/Neu.php'       => "<?php\n",
            'src/Alt.php'       => "<?php\n// vom Betreiber angepasst\n",
        ]);

        file_put_contents(
            $ziel . '/' . Integritaet::MANIFEST,
            "# Manifest\n" . hash('sha256', "<?php\n// unsere Fassung\n") . "  src/Alt.php\n"
        );

        UpdateService::applyUpdateArchive($archiv, $ziel);

        $this->assertFileExists($ziel . '/src/Alt.php');
        $this->assertSame(['src/Alt.php'], UpdateService::unklareFunde());
    }

    // ---- Rückweg ---------------------------------------------------------

    /**
     * Ohne Sicherung wird NICHT gelöscht.
     *
     * Der Abgleich sichert jede Datei, bevor er sie entfernt — sonst könnte
     * `rollback()` sie nicht zurückholen. Schlägt das Sichern fehl, bricht er
     * ab und lässt alles stehen; ein Abgleich ohne Rückweg wäre schlimmer als
     * eine liegengebliebene Datei.
     *
     * ERZWUNGEN ÜBER EIN UNBRAUCHBARES SICHERUNGSVERZEICHNIS. Die frühere
     * Fassung nahm dafür einen 250 Zeichen langen Dateinamen, dessen flacher
     * Sicherungsname NAME_MAX sprengte. Diese Grenze gibt es nicht mehr:
     * `sicherungsname()` kürzt den lesbaren Teil und hängt eine Kurzfassung
     * des Pfades an, damit zwei verschiedene Pfade nicht mehr auf denselben
     * Namen fallen. Der alte Auslöser ist also weggefallen, WEIL er behoben
     * wurde — ein Test darf sich nicht auf eine Schwäche stützen.
     */
    public function testOhneSicherungWirdNichtGeloescht(): void {
        $unsere = "<?php\nreturn ['a' => 'unsere Fassung'];\n";
        $ziel = $this->baueInstallation([
            'config/config.php' => "<?php\nconst CORE_VERSION = '0.8.0';\n",
            'lang/de.php'       => "<?php\nreturn [];\n",
            'lang/fr.php'       => $unsere,
        ]);
        $quelle = $this->baueQuellbaum([
            'config/config.php' => "<?php\nconst CORE_VERSION = '0.9.0';\n",
            'lang/de.php'       => "<?php\nreturn [];\n",
        ], ['lang/fr.php' => $unsere]);

        $journal = $this->leeresJournal();
        $unbrauchbar = $quelle . '/gibt-es-nicht/und-auch-nicht';

        try {
            $this->rufeAbgleich($quelle, $ziel, $unbrauchbar, $journal);
            $this->fail('Der Abgleich hätte abbrechen müssen.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Sicherungskopie', $e->getMessage());
            $this->assertStringContainsString('nichts entfernt', $e->getMessage());
        }

        $this->assertFileExists($ziel . '/lang/fr.php', 'Ohne Sicherung darf nichts verschwinden.');
        $this->assertSame([], $journal['deleted']);
    }

    /**
     * Und der Rückweg: Was der Abgleich entfernt hat, holt `rollback()`
     * zurück — mit seinem Inhalt, nicht als leere Hülle.
     */
    public function testRollbackHoltEntfernteDateienZurueck(): void {
        $fr = "<?php\nreturn ['a' => 'franzoesisch'];\n";
        $it = "<?php\nreturn ['a' => 'italienisch'];\n";

        $ziel = $this->baueInstallation([
            'config/config.php' => "<?php\nconst CORE_VERSION = '0.8.0';\n",
            'lang/de.php'       => "<?php\nreturn [];\n",
            'lang/fr.php'       => $fr,
            'lang/it.php'       => $it,
        ]);
        $quelle = $this->baueQuellbaum([
            'config/config.php' => "<?php\nconst CORE_VERSION = '0.9.0';\n",
            'lang/de.php'       => "<?php\nreturn [];\n",
        ], ['lang/fr.php' => $fr, 'lang/it.php' => $it]);

        $sicherungen = $this->tempVerzeichnis('sicherungen');
        $journal = $this->leeresJournal();

        $entfernt = $this->rufeAbgleich($quelle, $ziel, $sicherungen, $journal);

        $this->assertSame(2, $entfernt);
        $this->assertFileDoesNotExist($ziel . '/lang/fr.php');
        $this->assertFileDoesNotExist($ziel . '/lang/it.php');

        $this->rufeRollback($journal);

        $this->assertSame($fr, (string)file_get_contents($ziel . '/lang/fr.php'));
        $this->assertSame($it, (string)file_get_contents($ziel . '/lang/it.php'));
    }

    /**
     * Zwei Pfade dürfen nicht auf dieselbe Sicherung fallen.
     *
     * `lang/a/b.php` und `lang/a__b.php` ergaben mit dem alten
     * `str_replace('/','__')` denselben Sicherungsnamen. Die zweite Kopie
     * überschrieb die erste, das Journal zeigte zweimal auf dieselbe Datei —
     * und ein Rollback stellte die eine aus dem Inhalt der anderen wieder her.
     * Ohne Fehler, ohne Warnung, mit der Meldung „2 Datei(en) zurückgeholt".
     */
    public function testZweiPfadeFallenNichtAufDieselbeSicherung(): void {
        $tief  = "<?php\nreturn ['woher' => 'lang/a/b.php'];\n";
        $flach = "<?php\nreturn ['woher' => 'lang/a__b.php'];\n";

        $ziel = $this->baueInstallation([
            'config/config.php' => "<?php\nconst CORE_VERSION = '0.8.0';\n",
            'lang/de.php'       => "<?php\nreturn [];\n",
            'lang/a/b.php'      => $tief,
            'lang/a__b.php'     => $flach,
        ]);
        $quelle = $this->baueQuellbaum([
            'config/config.php' => "<?php\nconst CORE_VERSION = '0.9.0';\n",
            'lang/de.php'       => "<?php\nreturn [];\n",
        ], ['lang/a/b.php' => $tief, 'lang/a__b.php' => $flach]);

        $journal = $this->leeresJournal();
        $this->rufeAbgleich($quelle, $ziel, $this->tempVerzeichnis('sicherungen'), $journal);
        $this->rufeRollback($journal);

        $this->assertSame(
            $tief,
            (string)file_get_contents($ziel . '/lang/a/b.php'),
            'lang/a/b.php muss seinen EIGENEN Inhalt zurückbekommen, nicht den von lang/a__b.php.'
        );
        $this->assertSame($flach, (string)file_get_contents($ziel . '/lang/a__b.php'));
    }

    /**
     * Das Manifest der INSTALLATION muss gelesen werden, BEVOR copyTree() es
     * überschreibt.
     *
     * Es ist die zweite Beweisquelle: Was ihm entspricht, ist nachweislich
     * unsere Datei und seither unangetastet. Nur liegt `KERN-SHA256SUMS.txt`
     * auch im Archiv — copyTree() kopiert es mit, und danach steht dort das
     * Manifest des NEUEN Releases, das über die abgelösten Dateien der alten
     * Installation naturgemäß nichts weiß.
     *
     * Die Quelle war damit in jedem echten Release wirkungslos: Sie half nur,
     * wenn das Archiv gar kein Manifest mitbrachte — also ausgerechnet dann
     * nicht, wenn sie gebraucht wird. Dieser Test bildet den Realfall ab:
     * Archiv MIT Manifest.
     */
    public function testDasManifestDerInstallationWirdVorDemUeberschreibenGelesen(): void {
        $alt = "<?php\n// Fassung, die diese Installation ausgeliefert bekam\n";

        // Das Archiv bringt ein EIGENES Manifest mit - wie jedes echte Release.
        $archiv = $this->baueArchiv([
            'config/config.php'   => "<?php\nconst CORE_VERSION = '0.9.0';\n",
            'src/Neu.php'         => "<?php\n",
            'KERN-SHA256SUMS.txt' => "# Manifest des NEUEN Release\n"
                . hash('sha256', "<?php\n") . "  src/Neu.php\n",
        ], []);

        $ziel = $this->baueInstallation([
            'config/config.php' => "<?php\nconst CORE_VERSION = '0.8.0';\n",
            'src/Neu.php'       => "<?php\n",
            'src/Alt.php'       => $alt,
        ]);

        // Das Manifest der Installation kennt src/Alt.php - das des Archivs nicht.
        file_put_contents(
            $ziel . '/KERN-SHA256SUMS.txt',
            "# Manifest dieser Installation\n" . hash('sha256', $alt) . "  src/Alt.php\n"
        );

        UpdateService::applyUpdateArchive($archiv, $ziel);

        $this->assertFileDoesNotExist(
            $ziel . '/src/Alt.php',
            'Der Abgleich muss das Manifest der Installation gelesen haben, bevor copyTree() '
            . 'es mit dem des Archivs überschrieben hat.'
        );
        $this->assertSame([], UpdateService::unklareFunde());

        // Und danach steht das neue Manifest da - das ist richtig so.
        $this->assertStringContainsString(
            'NEUEN Release',
            (string)file_get_contents($ziel . '/KERN-SHA256SUMS.txt')
        );
    }

    // ---- Leere Verzeichnisse ----------------------------------------------

    /**
     * DER BEFUND, DER DIESE RUNDE AUSGELÖST HAT.
     *
     * Ein leeres Verzeichnis, das der Betreiber angelegt hat, verschwand
     * ohne jeden Beweis und ohne Meldung. `public/.well-known/acme-challenge`
     * — der Webroot für certbot — ist im Ruhezustand immer leer. Weg waren
     * Besitzer, Rechte und ACL, und die nächste Zertifikatserneuerung schlug
     * fehl.
     *
     * Ein Verzeichnis hat keine Prüfsumme. Der Beweis ist deshalb ein
     * anderer: Es darf nur gehen, wenn DIESER Abgleich es geleert hat.
     */
    public function testLeeresBetreiberVerzeichnisBleibtUndWirdGemeldet(): void {
        $ziel = $this->baueInstallation([
            'config/config.php' => "<?php\nconst CORE_VERSION = '0.8.0';\n",
            'public/index.php'  => "<?php\n",
        ]);
        mkdir($ziel . '/public/.well-known/acme-challenge', 0775, true);
        mkdir($ziel . '/public/kundenbereich', 0755, true);

        $archiv = $this->baueArchiv([
            'config/config.php' => "<?php\nconst CORE_VERSION = '0.9.0';\n",
            'public/index.php'  => "<?php\n",
        ], []);

        UpdateService::applyUpdateArchive($archiv, $ziel);

        $this->assertDirectoryExists($ziel . '/public/.well-known/acme-challenge');
        $this->assertDirectoryExists($ziel . '/public/kundenbereich');
        $this->assertEqualsCanonicalizing(
            // `.well-known` selbst taucht NICHT auf: Es enthaelt
            // acme-challenge, ist also nicht leer. Gemeldet wird nur, was
            // wirklich leer ist und im Archiv fehlt.
            ['public/.well-known/acme-challenge/ (leeres Verzeichnis)',
             'public/kundenbereich/ (leeres Verzeichnis)'],
            UpdateService::unklareFunde(),
            'Was nicht entfernt wird, gehört gemeldet - sonst ist es genau der stille '
            . 'Zustand, gegen den der Abgleich gebaut wurde.'
        );
    }

    /**
     * Die Gegenrichtung: Ein Verzeichnis, das der Abgleich SELBST geleert
     * hat, bestand nachweislich aus unseren Dateien und darf gehen.
     */
    public function testSelbstGeleertesVerzeichnisVerschwindet(): void {
        $inhalt = "<?php\n// abgeloest\n";

        $ziel = $this->baueInstallation([
            'config/config.php'   => "<?php\nconst CORE_VERSION = '0.8.0';\n",
            'src/Neu.php'         => "<?php\n",
            'src/Alt/Weg.php'     => $inhalt,
        ]);
        $archiv = $this->baueArchiv([
            'config/config.php' => "<?php\nconst CORE_VERSION = '0.9.0';\n",
            'src/Neu.php'       => "<?php\n",
        ], ['src/Alt/Weg.php' => $inhalt]);

        UpdateService::applyUpdateArchive($archiv, $ziel);

        $this->assertDirectoryDoesNotExist($ziel . '/src/Alt');
        $this->assertSame([], UpdateService::unklareFunde());
    }

    // ---- Hilfen ----------------------------------------------------------

    /**
     * Baut ein Release-Zip mit git-archive-typischem Präfixverzeichnis.
     *
     * @param array<string, string> $dateien   relativer Pfad => Inhalt
     * @param array<string, string>|null $abgeloest relativer Pfad => Inhalt, den
     *        eine FRÜHERE Version dort hatte. Daraus entsteht die Beweisliste
     *        ABGELOESTE-DATEIEN.txt. null = keine Liste im Archiv (wie bei
     *        Releases vor #403).
     */
    private function baueArchiv(array $dateien, ?array $abgeloest = []): string {
        $bau = $this->tempVerzeichnis('archivbau');
        $wurzel = $bau . '/hengstverzeichnis-framework-0.9.0';

        if ($abgeloest !== null) {
            $zeilen = ['# Beweisliste (Test)'];
            foreach ($abgeloest as $pfad => $inhalt) {
                $zeilen[] = hash('sha256', $inhalt) . '  ' . $pfad;
            }
            $dateien[UpdateService::ABGELOESTE_LISTE] = implode("\n", $zeilen) . "\n";
        }

        foreach ($dateien as $pfad => $inhalt) {
            $ziel = $wurzel . '/' . $pfad;
            if (!is_dir(dirname($ziel))) {
                mkdir(dirname($ziel), 0755, true);
            }
            file_put_contents($ziel, $inhalt);
        }

        $zipPfad = $bau . '/release.zip';
        $zip = new \ZipArchive();
        if ($zip->open($zipPfad, \ZipArchive::CREATE) !== true) {
            $this->fail('Test-Zip konnte nicht angelegt werden.');
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($wurzel, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $datei) {
            if (!$datei->isFile()) {
                continue;
            }
            $zip->addFile($datei->getPathname(), substr($datei->getPathname(), strlen($bau) + 1));
        }
        $zip->close();

        return $zipPfad;
    }

    /**
     * Trägt zusätzliche Beweiszeilen in ein bereits gebautes Archiv nach -
     * für den Fall, dass derselbe Pfad mehrere Inhalte hatte, den die
     * Schlüssel-Wert-Form von baueArchiv() nicht abbilden kann.
     *
     * @param array<int, array{0: string, 1: string}> $eintraege [pfad, inhalt]
     */
    private function ergaenzeBeweise(string $zipPfad, array $eintraege): void {
        $zip = new \ZipArchive();
        if ($zip->open($zipPfad) !== true) {
            $this->fail('Test-Zip konnte nicht geöffnet werden.');
        }
        $name = 'hengstverzeichnis-framework-0.9.0/' . UpdateService::ABGELOESTE_LISTE;
        $inhalt = (string)$zip->getFromName($name);
        foreach ($eintraege as [$pfad, $dateiInhalt]) {
            $inhalt .= hash('sha256', $dateiInhalt) . '  ' . $pfad . "\n";
        }
        $zip->addFromString($name, $inhalt);
        $zip->close();
    }

    /**
     * Ein entpackter Quellbaum samt Beweisliste - fuer die Tests, die
     * abgleicheKernPfade() direkt rufen statt ueber applyUpdateArchive().
     *
     * @param array<string, string> $dateien
     * @param array<string, string> $abgeloest
     */
    private function baueQuellbaum(array $dateien, array $abgeloest): string {
        $wurzel = $this->tempVerzeichnis('quellbaum');
        foreach ($dateien as $pfad => $inhalt) {
            $voll = $wurzel . '/' . $pfad;
            if (!is_dir(dirname($voll))) {
                mkdir(dirname($voll), 0755, true);
            }
            file_put_contents($voll, $inhalt);
        }
        $zeilen = ['# Beweisliste (Test)'];
        foreach ($abgeloest as $pfad => $inhalt) {
            $zeilen[] = hash('sha256', $inhalt) . '  ' . $pfad;
        }
        file_put_contents($wurzel . '/' . UpdateService::ABGELOESTE_LISTE, implode("\n", $zeilen) . "\n");
        return $wurzel;
    }

    /** @return array<string, array<int, mixed>> */
    private function leeresJournal(): array {
        return ['restore' => [], 'created' => [], 'created_dirs' => [], 'deleted' => [], 'rmdir' => []];
    }

    /** @param array<string, array<int, mixed>> $journal */
    private function rufeAbgleich(string $quelle, string $ziel, string $sicherungen, array &$journal): int {
        $m = new \ReflectionMethod(UpdateService::class, 'abgleicheKernPfade');
        return (int)$m->invokeArgs(null, [$quelle, $ziel, $sicherungen, &$journal, []]);
    }

    /** @param array<string, array<int, mixed>> $journal */
    private function rufeRollback(array $journal): void {
        (new \ReflectionMethod(UpdateService::class, 'rollback'))->invoke(null, $journal);
    }

    /** @param array<string, string> $dateien */
    private function baueInstallation(array $dateien): string {
        $ziel = $this->tempVerzeichnis('installation');
        foreach ($dateien as $pfad => $inhalt) {
            $voll = $ziel . '/' . $pfad;
            if (!is_dir(dirname($voll))) {
                mkdir(dirname($voll), 0755, true);
            }
            file_put_contents($voll, $inhalt);
        }
        return $ziel;
    }

    private function tempVerzeichnis(string $zweck): string {
        $dir = rtrim(sys_get_temp_dir(), '/') . '/hv_abgleich_' . $zweck . '_' . bin2hex(random_bytes(6));
        mkdir($dir, 0755, true);
        $this->aufraeumen[] = $dir;
        return $dir;
    }

    private function removeTree(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $eintrag) {
            $eintrag->isDir() ? @rmdir($eintrag->getPathname()) : @unlink($eintrag->getPathname());
        }
        @rmdir($dir);
    }
}
