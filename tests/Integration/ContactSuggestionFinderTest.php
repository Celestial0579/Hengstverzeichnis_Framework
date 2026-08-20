<?php
// tests/Integration/ContactSuggestionFinderTest.php

namespace Tests\Integration;

use App\Database;
use App\Service\ContactSuggestionFinder;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Prüft App\Service\ContactSuggestionFinder (#355) nach dem Umbau aus #369/#370.
 * Der Dienst hatte bis dahin keinen einzigen Test - beide Befunde des
 * Bug-Scans hätte einer davon gefunden.
 *
 * Zwei Dinge werden geprüft, und das zweite ist das wichtigere:
 *
 * 1. #370: Der Platzhalter-Ausschluss vergleicht WORTWEISE. Mit dem früheren
 *    str_contains() traf das Muster 'nn' jeden Namen mit Doppel-n und warf
 *    Zimmermann, Hermann, Bachmann aus der Suche - im deutschsprachigen
 *    Zuchtwesen ein erheblicher Teil des Bestands.
 *
 * 2. #369: Der Vorfilter, der similar_text() für aussichtslose Paare
 *    überspringt, darf KEIN Paar verlieren. Dafür steht hier ein Orakel: die
 *    vollständige Bewertung jedes Paares ohne jeden Vorfilter, direkt aus den
 *    Konstanten der Klasse nachgebildet. Für einen bewusst grenznahen Bestand
 *    müssen beide Wege dieselbe Menge liefern.
 *
 *    Das ist der Grund, warum das im Issue vorgeschlagene Blocking (gleiche
 *    Anfangsbuchstaben bzw. gleicher SOUNDEX) NICHT umgesetzt wurde: Es geht
 *    von mindestens 88 % Namensähnlichkeit aus. Das gilt nur ohne
 *    Ort-Stützung - mit Ort, PLZ und Land genügen rund 46 %, und dort hätte
 *    Blocking echte Dubletten verschluckt.
 */
class ContactSuggestionFinderTest extends TestCase {

    private static PDO $db;

    public static function setUpBeforeClass(): void {
        if (!defined('DB_HOST')) {
            self::markTestSkipped('Keine Test-Datenbank konfiguriert (DB_HOST fehlt) - siehe tests/bootstrap.php.');
        }

        $setupPdo = new PDO(
            "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $setupPdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        foreach ($setupPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $setupPdo->exec("DROP TABLE IF EXISTS `$table`");
        }
        $setupPdo->exec("SET FOREIGN_KEY_CHECKS = 1");

        try {
            $setupPdo->exec(file_get_contents(__DIR__ . '/../../database/schema.sql'));
        } catch (PDOException $e) {
            // Ignorieren, analog zu SetupController::provision()
        }

        self::$db = Database::getInstance();
    }

    protected function setUp(): void {
        self::$db->exec("DELETE FROM settings WHERE setting_key = 'contact_suggestions_cache'");
        self::$db->exec("DELETE FROM match_labels");
        self::$db->exec("DELETE FROM contacts");
    }

    private function insert(string $name, ?string $city = null, ?string $plz = null, ?string $land = null): int {
        $stmt = self::$db->prepare(
            "INSERT INTO contacts (name, city, postal_code, country, is_published) VALUES (?, ?, ?, ?, 1)"
        );
        $stmt->execute([$name, $city, $plz, $land]);
        return (int)self::$db->lastInsertId();
    }

    /**
     * #370: Beide Namen enthalten 'nn'. Früher flogen sie über den
     * Platzhalter-Filter aus dem Kandidatenfeld, und die Dublette erschien
     * nirgends - die Seite meldete "nichts gefunden".
     */
    public function testDoppeltesNIstKeinPlatzhalter(): void {
        $a = $this->insert('Reitanlage Zimmermann', 'Sottrum', '27367', 'DE');
        $b = $this->insert('Zimmermann Reitanlage GbR', 'Sottrum', '27367', 'DE');

        $ergebnis = ContactSuggestionFinder::findAll(100);

        $this->assertSame(2, $ergebnis['geprueft'], 'Beide Kontakte müssen an der Suche teilnehmen');
        $this->assertSame(0, $ergebnis['uebersprungen']);
        $this->assertSame([[$a, $b]], $this->paarIds($ergebnis));
    }

    /**
     * Die Gegenprobe zum selben Punkt: Namen, die 'nn' oder 'privat' nur als
     * Wortbestandteil führen, dürfen nicht ausgeschlossen werden - aber die
     * echten Platzhalter weiterhin schon.
     */
    public function testEchtePlatzhalterNehmenNichtTeil(): void {
        $this->insert('Nichtmitglied NO', 'Oslo', '0150', 'NO');
        $this->insert('Nichtmitglied NL', 'Utrecht', '3500', 'NL');
        $this->insert('N. N.', null, null, null);
        $a = $this->insert('Hof Hermann', 'Kiel', '24103', 'DE');
        $b = $this->insert('Hof Hermann GbR', 'Kiel', '24103', 'DE');
        $this->insert('Privathof Sonnenblick', 'Kiel', '24103', 'DE');

        $ergebnis = ContactSuggestionFinder::findAll(100);

        $this->assertSame(3, $ergebnis['geprueft'], 'Drei Platzhalter fliegen raus, drei echte bleiben');
        $this->assertSame(3, $ergebnis['uebersprungen'], 'Die Zahl der Übersprungenen wird gemeldet, nicht verschwiegen');
        $this->assertContains([$a, $b], $this->paarIds($ergebnis));
    }

    /**
     * #369, der eigentliche Punkt: Der Vorfilter darf nichts verlieren.
     *
     * Der Bestand ist bewusst grenznah gebaut - kurze gegen lange Namen,
     * gleiche und verschiedene Orte, Paare knapp über und knapp unter der
     * Schwelle. Verglichen wird gegen die vollständige Bewertung ohne
     * Vorfilter.
     */
    public function testVorfilterLiefertDasselbeWieDieVolleBewertung(): void {
        $namen = [
            ['Hof Meier', 'Kiel', '24103', 'DE'],
            ['Hof Meyer', 'Kiel', '24103', 'DE'],
            ['Meierhof', 'Kiel', '24103', 'DE'],
            ['Gestüt Sonnenhof', 'Kiel', '24103', 'DE'],
            ['Gestuet Sonnenhof', 'Hamburg', '20095', 'DE'],
            ['Stall Nord', 'Kiel', '24103', 'DE'],
            ['Stall Nordwest', 'Kiel', '24103', 'DE'],
            ['S. Nord', 'Kiel', '24103', 'DE'],
            ['Reiterhof am Deich', 'Kiel', '24103', 'DE'],
            ['Deich', 'Kiel', '24103', 'DE'],
            ['A', 'Kiel', '24103', 'DE'],
            ['AB', 'Kiel', '24103', 'DE'],
            ['Zuchtbetrieb Lindenallee GbR', 'Bremen', '28195', 'DE'],
            ['Lindenallee', 'Bremen', '28195', 'DE'],
            ['Ponyhof West', null, null, null],
            ['Ponyhof Ost', null, null, null],
        ];
        foreach ($namen as [$name, $city, $plz, $land]) {
            $this->insert($name, $city, $plz, $land);
        }

        $ergebnis = ContactSuggestionFinder::findAll(PHP_INT_MAX);

        $this->assertSame(
            $this->orakelPaare(),
            $this->paarIds($ergebnis),
            'Der Vorfilter aus #369 hat ein Paar verloren oder eines zu viel gefunden'
        );
        $this->assertNotSame([], $this->orakelPaare(), 'Der Bestand muss überhaupt Paare enthalten, sonst prüft der Test nichts');
    }

    /**
     * Ein entschiedenes Paar verschwindet aus der Liste - und zwar unabhängig
     * davon, in welcher Reihenfolge die beiden Kennungen stehen.
     */
    public function testEntschiedenePaareVerschwinden(): void {
        $a = $this->insert('Hof Meier', 'Kiel', '24103', 'DE');
        $b = $this->insert('Hof Meyer', 'Kiel', '24103', 'DE');
        $this->assertSame([[$a, $b]], $this->paarIds(ContactSuggestionFinder::findAll(100)));

        \App\Service\MatchLabel::setzen('contact', $b, $a, \App\Service\MatchLabel::VERSCHIEDEN);

        $this->assertSame([], $this->paarIds(ContactSuggestionFinder::findAll(100)));
    }

    /**
     * Der Zwischenspeicher (#369) darf nie ein veraltetes Ergebnis liefern.
     *
     * Der schärfste Fall ist der, an dem ein Zeitstempel-Abdruck scheitern
     * würde: ZWEI Änderungen in DERSELBEN Sekunde. `contacts.updated_at` ist
     * sekundengenau - nach der ersten Änderung stünde der Stempel bereits auf
     * "jetzt", die zweite bliebe unsichtbar und die Seite zeigte bis zum
     * Ablauf der Frist einen falschen Stand. Die Prüfsumme über die Inhalte
     * kennt das Problem nicht.
     */
    public function testZweiAenderungenInDerselbenSekundeWerdenBeideGesehen(): void {
        $a = $this->insert('Hof Meier', 'Kiel', '24103', 'DE');
        $b = $this->insert('Ganz Anderer Betrieb', 'Bremen', '28195', 'DE');

        $this->assertSame([], $this->paarIds(ContactSuggestionFinder::findAll(100)), 'Vorbedingung: noch keine Dublette');

        // Beide Änderungen ohne Pause - sie fallen in dieselbe Sekunde.
        self::$db->prepare("UPDATE contacts SET name = ? WHERE id = ?")->execute(['Hof Meyer', $b]);
        self::$db->prepare("UPDATE contacts SET city = ?, postal_code = ? WHERE id = ?")->execute(['Kiel', '24103', $b]);

        $this->assertSame(
            [[$a, $b]],
            $this->paarIds(ContactSuggestionFinder::findAll(100)),
            'Der Zwischenspeicher hat eine Änderung derselben Sekunde verschluckt'
        );
    }

    /**
     * Bei unverändertem Bestand wird nicht neu gerechnet - nachgewiesen am
     * abgelegten Wert in `settings`, nicht an der Laufzeit (eine Zeitmessung
     * wäre auf einem belasteten Host kein belastbarer Nachweis).
     */
    public function testUnveraenderterBestandWirdNichtNeuBerechnet(): void {
        $this->insert('Hof Meier', 'Kiel', '24103', 'DE');
        $this->insert('Hof Meyer', 'Kiel', '24103', 'DE');

        ContactSuggestionFinder::findAll(100);
        $ersteAblage = $this->gespeicherterStand();
        $this->assertNotNull($ersteAblage, 'Der erste Lauf muss etwas ablegen');

        // Zeitstempel künstlich zurücksetzen: Würde neu gerechnet, stünde
        // danach wieder die aktuelle Zeit darin.
        $daten = json_decode($ersteAblage, true);
        $daten['zeit'] = time() - 60;
        self::$db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'contact_suggestions_cache'")
            ->execute([json_encode($daten)]);

        ContactSuggestionFinder::findAll(100);

        $zweite = json_decode((string)$this->gespeicherterStand(), true);
        $this->assertSame($daten['zeit'], $zweite['zeit'], 'Es wurde neu gerechnet, obwohl sich nichts geändert hat');
    }

    /**
     * Eine gesetzte Entscheidung muss sofort wirken - nicht erst nach Ablauf
     * der Frist. Sonst sieht der Benutzer den Knopf, den er gerade gedrückt
     * hat, ohne Wirkung.
     */
    public function testEineEntscheidungWirktSofortTrotzZwischenspeicher(): void {
        $a = $this->insert('Hof Meier', 'Kiel', '24103', 'DE');
        $b = $this->insert('Hof Meyer', 'Kiel', '24103', 'DE');

        $this->assertSame([[$a, $b]], $this->paarIds(ContactSuggestionFinder::findAll(100)));
        \App\Service\MatchLabel::setzen('contact', $a, $b, \App\Service\MatchLabel::VERSCHIEDEN);
        $this->assertSame([], $this->paarIds(ContactSuggestionFinder::findAll(100)));
    }

    private function gespeicherterStand(): ?string {
        $wert = self::$db->query(
            "SELECT setting_value FROM settings WHERE setting_key = 'contact_suggestions_cache'"
        )->fetchColumn();
        return is_string($wert) ? $wert : null;
    }

    /** @return array<int, array{0:int,1:int}> */
    private function paarIds(array $ergebnis): array {
        $ids = [];
        foreach ($ergebnis['paare'] as $paar) {
            $x = (int)$paar['a']['id'];
            $y = (int)$paar['b']['id'];
            $ids[] = $x < $y ? [$x, $y] : [$y, $x];
        }
        sort($ids);
        return $ids;
    }

    /**
     * Vollständige Bewertung ohne jeden Vorfilter, direkt aus den Konstanten
     * der Klasse nachgebildet (Reflection, damit eine geänderte Schwelle nicht
     * still am Orakel vorbeiläuft).
     *
     * @return array<int, array{0:int,1:int}>
     */
    private function orakelPaare(): array {
        $spiegel = new \ReflectionClass(ContactSuggestionFinder::class);
        $schwelle = $spiegel->getConstant('SCHWELLE');
        $punkteName = $spiegel->getConstant('PUNKTE_NAME');
        $punkteOrt = $spiegel->getConstant('PUNKTE_ORT');
        $istPlatzhalter = $spiegel->getMethod('istPlatzhalter');
        $normalisiere = $spiegel->getMethod('normalisiere');

        $rows = self::$db->query(
            'SELECT id, name, city, postal_code, country FROM contacts WHERE deleted_at IS NULL ORDER BY id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $kandidaten = [];
        foreach ($rows as $row) {
            if ($istPlatzhalter->invoke(null, (string)$row['name'])) {
                continue;
            }
            $kandidaten[] = $row;
        }

        $paare = [];
        $n = count($kandidaten);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $kandidaten[$i];
                $b = $kandidaten[$j];

                $nameA = $normalisiere->invoke(null, (string)$a['name']);
                $nameB = $normalisiere->invoke(null, (string)$b['name']);
                if ($nameA === '' || $nameB === '') {
                    continue;
                }

                similar_text($nameA, $nameB, $prozent);
                $punkte = (int)round(($prozent / 100) * $punkteName);

                $stuetzen = 0;
                $moeglich = 0;
                foreach (['city', 'postal_code', 'country'] as $feld) {
                    $wertA = $normalisiere->invoke(null, (string)($a[$feld] ?? ''));
                    $wertB = $normalisiere->invoke(null, (string)($b[$feld] ?? ''));
                    if ($wertA === '' || $wertB === '') {
                        continue;
                    }
                    $moeglich++;
                    if ($wertA === $wertB) {
                        $stuetzen++;
                    }
                }
                if ($moeglich > 0) {
                    $punkte += (int)round(($stuetzen / $moeglich) * $punkteOrt);
                }

                if (min(100, $punkte) < $schwelle) {
                    continue;
                }
                $x = (int)$a['id'];
                $y = (int)$b['id'];
                $paare[] = $x < $y ? [$x, $y] : [$y, $x];
            }
        }
        sort($paare);
        return $paare;
    }
}
