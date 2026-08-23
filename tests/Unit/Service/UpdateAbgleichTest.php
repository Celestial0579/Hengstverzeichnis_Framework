<?php
// tests/Unit/Service/UpdateAbgleichTest.php

namespace Tests\Unit\Service;

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

    // ---- Rückweg ---------------------------------------------------------

    /**
     * Bricht das Update NACH dem Abgleich ab, müssen die entfernten Dateien
     * zurückkommen. Ohne Journalisierung wäre der Rückweg genau um das ärmer,
     * was der Abgleich gelöscht hat.
     *
     * Erzwungen wird der Abbruch über eine echte Grenze des Verfahrens: Die
     * Sicherungsablage ist flach, der relative Pfad wird zum Dateinamen. Ist
     * er länger als NAME_MAX (255), lässt sich die Sicherung nicht anlegen -
     * und der Abgleich bricht ab, statt ohne Rückweg zu löschen. Genau das
     * soll er tun.
     *
     * Die Reihenfolge macht den Test scharf: `src` wird vor `lang`
     * abgeglichen (siehe Baumordnung::kernPfade()), src/Alt.php ist also
     * bereits gelöscht und journalisiert, wenn es in lang/ schiefgeht.
     */
    public function testRollbackHoltEntfernteDateienZurueck(): void {
        $zuLang = str_repeat('z', 250) . '.php';

        $archiv = $this->baueArchiv([
            'config/config.php' => "<?php\nconst CORE_VERSION = '0.9.0';\n",
            'lang/de.php'       => "<?php\nreturn ['a' => 'neu'];\n",
            'src/Neu.php'       => "<?php\n// neu\n",
        ], [
            'src/Alt.php'     => "<?php\n// abgeloest\n",
            'lang/' . $zuLang => "<?php\nreturn [];\n",
        ]);
        $ziel = $this->baueInstallation([
            'config/config.php'  => "<?php\nconst CORE_VERSION = '0.8.0';\n",
            'lang/de.php'        => "<?php\nreturn ['a' => 'alt'];\n",
            'lang/fr.php'        => "<?php\nreturn ['a' => 'franzoesisch'];\n",
            'lang/' . $zuLang    => "<?php\nreturn [];\n",
            'src/Neu.php'        => "<?php\n// alt\n",
            'src/Alt.php'        => "<?php\n// abgeloest\n",
        ]);

        try {
            UpdateService::applyUpdateArchive($archiv, $ziel);
            $this->fail('Das Update hätte abbrechen müssen.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('zurückgerollt', $e->getMessage());
        }

        $this->assertFileExists(
            "{$ziel}/src/Alt.php",
            'src/Alt.php war beim Abgleich schon gelöscht - nach dem Rückrollen muss es wieder da sein.'
        );
        $this->assertStringContainsString(
            'abgeloest',
            (string)file_get_contents("{$ziel}/src/Alt.php"),
            'Und zwar mit seinem Inhalt, nicht als leere Hülle.'
        );
        $this->assertStringContainsString(
            "'alt'",
            (string)file_get_contents("{$ziel}/lang/de.php"),
            'Die überschriebene Datei muss ebenfalls auf dem alten Stand sein.'
        );
        $this->assertFileExists("{$ziel}/lang/fr.php", 'Was noch nicht gelöscht war, bleibt ohnehin.');
    }

    /**
     * Und der wichtigere Teil derselben Grenze: Lässt sich die Sicherung
     * nicht anlegen, wird NICHT gelöscht. Ein Abgleich ohne Rückweg wäre
     * schlimmer als eine liegengebliebene Datei.
     */
    public function testOhneSicherungWirdNichtGeloescht(): void {
        $zuLang = str_repeat('z', 250) . '.php';

        $archiv = $this->baueArchiv([
            'config/config.php' => "<?php\nconst CORE_VERSION = '0.9.0';\n",
            'lang/de.php'       => "<?php\nreturn [];\n",
        ], ['lang/' . $zuLang => "<?php\nreturn [];\n"]);
        $ziel = $this->baueInstallation([
            'config/config.php' => "<?php\nconst CORE_VERSION = '0.8.0';\n",
            'lang/de.php'       => "<?php\nreturn [];\n",
            'lang/' . $zuLang   => "<?php\nreturn [];\n",
        ]);

        try {
            UpdateService::applyUpdateArchive($archiv, $ziel);
            $this->fail('Das Update hätte abbrechen müssen.');
        } catch (\RuntimeException) {
            // erwartet
        }

        $this->assertFileExists("{$ziel}/lang/{$zuLang}", 'Ohne Sicherungskopie darf nicht gelöscht werden.');
    }

    /**
     * Ein fehlgeschlagenes Update darf kein leeres Verzeichnisgerüst
     * hinterlassen. Bis #403 journalisierte copyTree() angelegte
     * Verzeichnisse gar nicht - rollback() wusste also nicht, welche neu waren.
     */
    public function testRollbackEntferntNeuAngelegteVerzeichnisse(): void {
        $zuLang = str_repeat('z', 250) . '.php';

        $archiv = $this->baueArchiv([
            'config/config.php'     => "<?php\nconst CORE_VERSION = '0.9.0';\n",
            'src/Ganz/Neu/Tief.php' => "<?php\n",
            'lang/de.php'           => "<?php\nreturn [];\n",
        ], ['lang/' . $zuLang => "<?php\nreturn [];\n"]);
        $ziel = $this->baueInstallation([
            'config/config.php' => "<?php\nconst CORE_VERSION = '0.8.0';\n",
            'lang/de.php'       => "<?php\nreturn [];\n",
            'lang/' . $zuLang   => "<?php\nreturn [];\n",
        ]);

        try {
            UpdateService::applyUpdateArchive($archiv, $ziel);
            $this->fail('Das Update hätte abbrechen müssen.');
        } catch (\RuntimeException) {
            // erwartet
        }

        $this->assertDirectoryDoesNotExist(
            "{$ziel}/src/Ganz",
            'Vom abgebrochenen Update angelegte Verzeichnisse müssen wieder weg sein.'
        );
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
