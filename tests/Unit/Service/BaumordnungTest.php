<?php
// tests/Unit/Service/BaumordnungTest.php

namespace Tests\Unit\Service;

use App\Service\Baumordnung;
use PHPUnit\Framework\TestCase;

/**
 * Tests für App\Service\Baumordnung (#403).
 *
 * Der wichtigste Test hier ist der auf VOLLSTÄNDIGKEIT. Eine Einordnung, die
 * Lücken hat, ist schlimmer als keine: Sie behauptet, die Eigentumsfrage sei
 * beantwortet, und wer sich darauf verlässt, übersieht genau das Neue. Ein
 * Verzeichnis, das jemand ins Release aufnimmt und einzuordnen vergisst,
 * muss deshalb die Testsuite rot machen - nicht still durchrutschen.
 */
class BaumordnungTest extends TestCase {

    /** Wurzel der Installation, aus der dieser Test läuft. */
    private static function wurzel(): string {
        return dirname(__DIR__, 3);
    }

    // ---- Vollständigkeit -------------------------------------------------

    /**
     * JEDER Pfad der obersten Ebene des Release-Archivs muss eingeordnet sein.
     *
     * Die oberste Ebene reicht, weil die Einordnung über den längsten
     * passenden Eintrag greift: Was unter `src/` liegt, erbt dessen Art. Wer
     * ein neues Verzeichnis NEBEN src/ anlegt, muss es nennen.
     */
    public function testJederPfadDesArchivsIstEingeordnet(): void {
        $unbekannt = [];
        foreach (self::archivEintraegeObersteEbene() as $eintrag) {
            if (Baumordnung::klasse($eintrag) === null) {
                $unbekannt[] = $eintrag;
            }
        }

        $this->assertSame(
            [],
            $unbekannt,
            "Nicht eingeordnete Pfade im Release-Archiv: " . implode(', ', $unbekannt)
            . "\nJeder Pfad muss in App\\Service\\Baumordnung::ORDNUNG stehen - als KERN "
            . "(kommt aus dem Release, darf abgeglichen werden), BETREIBER (gehört dem "
            . "Betreiber, nie anfassen) oder LAUFZEIT (entsteht im Betrieb)."
        );
    }

    /**
     * Umgekehrt: Kein Eintrag der Einordnung darf ins Leere zeigen.
     *
     * Ein Pfad, den es nicht mehr gibt, ist kein harmloser Rest - er
     * suggeriert einen Schutz oder eine Zuständigkeit, die niemand mehr
     * braucht, und verstellt den Blick auf die Einträge, die zählen.
     * Ausgenommen sind Pfade, die es im Repo naturgemäß nicht gibt, weil sie
     * erst beim Betrieb entstehen.
     */
    public function testKeinEintragZeigtInsLeere(): void {
        $entstehenErstImBetrieb = ['.env', 'config/db_config.php', 'var'];

        $tot = [];
        foreach (array_keys(Baumordnung::alle()) as $pfad) {
            if (in_array($pfad, $entstehenErstImBetrieb, true)) {
                continue;
            }
            if (!file_exists(self::wurzel() . '/' . $pfad)) {
                $tot[] = $pfad;
            }
        }

        $this->assertSame([], $tot, 'Einträge ohne Entsprechung im Baum: ' . implode(', ', $tot));
    }

    // ---- Die Eigentumsfrage ---------------------------------------------

    /**
     * Der längste Eintrag gewinnt - sonst wären gemischte Verzeichnisse nicht
     * darstellbar, und genau die gibt es: public/ gehört dem Kern,
     * public/uploads dem Betreiber.
     */
    public function testLaengsterEintragGewinnt(): void {
        $this->assertSame(Baumordnung::KERN, Baumordnung::klasse('public'));
        $this->assertSame(Baumordnung::KERN, Baumordnung::klasse('public/css/app.css'));
        $this->assertSame(Baumordnung::BETREIBER, Baumordnung::klasse('public/uploads'));
        $this->assertSame(Baumordnung::BETREIBER, Baumordnung::klasse('public/uploads/tief/bild.jpg'));

        $this->assertSame(Baumordnung::KERN, Baumordnung::klasse('config/config.php'));
        $this->assertSame(Baumordnung::BETREIBER, Baumordnung::klasse('config/db_config.php'));
    }

    /**
     * Die beiden fail-closed-Richtungen. Sie zeigen bewusst in
     * ENTGEGENGESETZTE Richtungen, und dieser Test hält das fest:
     *
     * - Beim Kopieren heißt "nicht eingeordnet": trotzdem kopieren. Sonst
     *   würde ein vergessenes Kern-Verzeichnis still nicht mehr ausgeliefert.
     * - Beim Löschen heißt "nicht eingeordnet": Finger weg. Sonst würde ein
     *   vergessenes Betreiber-Verzeichnis still gelöscht.
     *
     * Vergessen kostet damit eine liegengebliebene Datei, nie eine verlorene.
     */
    public function testUnbekanntePfadeSindWederGeschuetztNochAbgleichbar(): void {
        $unbekannt = 'ein-verzeichnis-das-niemand-eingeordnet-hat';

        $this->assertNull(Baumordnung::klasse($unbekannt));
        $this->assertFalse(
            Baumordnung::istBetreiber($unbekannt),
            'Unbekannt darf das Kopieren nicht blockieren - sonst fehlt neuer Kerncode still.'
        );
        $this->assertFalse(
            Baumordnung::darfAbgeglichenWerden($unbekannt),
            'Unbekannt darf NIE gelöscht werden.'
        );
    }

    /** Laufzeitpfade werden nicht abgeglichen - in Protokollen steht Betriebsgeschichte. */
    public function testLaufzeitWirdNichtAbgeglichen(): void {
        $this->assertSame(Baumordnung::LAUFZEIT, Baumordnung::klasse('storage/logs'));
        $this->assertFalse(Baumordnung::darfAbgeglichenWerden('storage/logs/app.log'));
        $this->assertFalse(Baumordnung::darfAbgeglichenWerden('var/cache'));
    }

    /** Pferdefotos liegen in storage/, gehören aber dem Betreiber (#366). */
    public function testPferdefotosSindBetreiberdaten(): void {
        $this->assertSame(Baumordnung::BETREIBER, Baumordnung::klasse('storage/horses'));
        $this->assertTrue(Baumordnung::istBetreiber('storage/horses/42/bild.jpg'));
        $this->assertFalse(Baumordnung::darfAbgeglichenWerden('storage/horses/42/bild.jpg'));
    }

    /**
     * Die früher in UpdateService::PROTECTED_PATHS aufgezählten Pfade müssen
     * weiterhin alle geschützt sein. Der Umzug in die Baumordnung war eine
     * Umstellung der Zuständigkeit, keine Änderung der Wirkung.
     */
    public function testAlleFrueherGeschuetztenPfadeSindEsWeiterhin(): void {
        $frueher = ['config/db_config.php', 'public/uploads', 'storage/horses', 'plugins', '.env'];

        foreach ($frueher as $pfad) {
            $this->assertTrue(
                Baumordnung::istBetreiber($pfad),
                "{$pfad} war geschützt und muss es bleiben."
            );
            $this->assertFalse(
                Baumordnung::darfAbgeglichenWerden($pfad),
                "{$pfad} darf nie abgeglichen werden."
            );
        }

        $this->assertEqualsCanonicalizing($frueher, Baumordnung::geschuetztePfade());
    }

    /** Schrägstriche am Rand dürfen die Antwort nicht ändern. */
    public function testNormalisierung(): void {
        foreach (['src', '/src', 'src/', '//src//', 'src/Service/../Service'] as $variante) {
            $this->assertNotNull(Baumordnung::klasse($variante), "fehlgeschlagen für: {$variante}");
        }
        $this->assertSame(Baumordnung::KERN, Baumordnung::klasse('/lang/'));
        $this->assertNull(Baumordnung::klasse(''));
        $this->assertNull(Baumordnung::klasse('/'));
    }

    /**
     * Ein Eintrag darf nicht zufällig einen NAMENSVERWANDTEN Pfad mitfangen.
     * `public` schützt nicht `publicity`, `src` nicht `srcalt`.
     */
    public function testKeinTrefferAufNamensverwandte(): void {
        $this->assertNull(Baumordnung::klasse('srcalt'));
        $this->assertNull(Baumordnung::klasse('publicity'));
        $this->assertNull(Baumordnung::klasse('langsam'));
        $this->assertNull(Baumordnung::klasse('plugins-alt'));
    }

    // ---- Hilfen ----------------------------------------------------------

    /**
     * Die oberste Ebene dessen, was das Release-Archiv mitbringt - erzeugt mit
     * demselben Pathspec wie .github/workflows/release.yml.
     *
     * @return array<int, string>
     */
    private static function archivEintraegeObersteEbene(): array {
        $wurzel = self::wurzel();
        $ausschluss = self::archivAusschluesse();

        $ausgabe = [];
        $rc = 0;
        exec(
            'git -C ' . escapeshellarg($wurzel) . ' ls-files -- '
            . implode(' ', array_map('escapeshellarg', $ausschluss)) . ' 2>/dev/null',
            $ausgabe,
            $rc
        );

        if ($rc !== 0 || $ausgabe === []) {
            self::fail(
                'Konnte den Inhalt des Release-Archivs nicht ermitteln (git ls-files). '
                . 'Ohne ihn prüft dieser Test nichts - und ein Umgebungsfehler ist kein Ergebnis.'
            );
        }

        $obersteEbene = [];
        foreach ($ausgabe as $pfad) {
            $obersteEbene[explode('/', $pfad)[0]] = true;
        }

        return array_keys($obersteEbene);
    }

    /**
     * Die Ausschlüsse aus dem git-archive-Aufruf in release.yml - aus der
     * Datei GELESEN, nicht hier abgeschrieben. Eine Kopie liefe irgendwann
     * auseinander, und dann prüfte dieser Test ein Archiv, das es nicht gibt.
     *
     * @return array<int, string>
     */
    private static function archivAusschluesse(): array {
        $yml = self::wurzel() . '/.github/workflows/release.yml';
        $inhalt = is_file($yml) ? (string)file_get_contents($yml) : '';

        preg_match_all("/':![^']+'/", $inhalt, $treffer);
        $ausschluss = array_map(static fn(string $s): string => trim($s, "'"), $treffer[0] ?? []);

        if ($ausschluss === []) {
            self::fail(
                'In .github/workflows/release.yml stehen keine git-archive-Ausschlüsse mehr. '
                . 'Entweder hat sich der Release-Schritt geändert - dann gehört dieser Test '
                . 'nachgezogen - oder die Datei ist kaputt.'
            );
        }

        return $ausschluss;
    }
}
