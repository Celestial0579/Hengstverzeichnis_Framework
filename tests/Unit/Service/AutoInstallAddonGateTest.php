<?php
// tests/Unit/Service/AutoInstallAddonGateTest.php

namespace Tests\Unit\Service;

use App\Service\UpdateService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Die Addon-Sperre des UNBEAUFSICHTIGTEN Updates (#362).
 *
 * WARUM ES DIESEN TEST GIBT. Bis v0.8.0-beta.1 prüfte der unbeaufsichtigte
 * Lauf ausschliesslich die Versionslinie. Dass ein Minor-Sprung damit auch
 * dann ausblieb, wenn er Addons zerlegt hätte, war ein NEBENEFFEKT: Es galt
 * nur, weil `core_supported_max` zufällig ebenfalls auf Major.Minor läuft.
 * Wer die Reichweite auf `any` stellte, hatte gar keinen Schutz.
 *
 * Ein Nebeneffekt, auf den sich jemand verlässt, ist eine Zusicherung ohne
 * Test - und die hält genau so lange, bis jemand die eine Konstante ändert,
 * die sie zufällig trug. Deshalb steht die Grenze jetzt als eigene, reine
 * Funktion da, und deshalb wird sie hier geprüft.
 *
 * Der manuelle Weg ist ausdrücklich NICHT betroffen: Dort warnt die
 * Update-Seite namentlich, und wer die Warnung liest und trotzdem
 * aktualisiert, entscheidet informiert. Eine Sperre auch dort liesse jeden
 * stranden, dessen Addon nicht mehr gepflegt wird.
 */
class AutoInstallAddonGateTest extends TestCase {

    public function testEinAktivesUnvertraeglichesAddonHaeltDasUpdateAuf(): void {
        $gruende = UpdateService::addonsBlockingAutoInstall([
            self::zeile('galerie', enabled: true, reasonTarget: 'unterstützt höchstens Kern-Linie 0.7'),
        ]);

        $this->assertCount(1, $gruende);
        $this->assertStringContainsString('galerie', $gruende[0]);
        $this->assertStringContainsString('0.7', $gruende[0], 'Der Grund muss mitkommen - sonst steht im Protokoll nur, DASS es klemmt.');
    }

    /**
     * Der Fall, für den die Sperre gebaut wurde: Kern-Release draussen,
     * Addons-Release der neuen Linie noch nicht. Genau dieser Zustand bestand
     * nach v0.8.0-beta.1.
     */
    public function testMehrereAddonsWerdenAlleGenannt(): void {
        $gruende = UpdateService::addonsBlockingAutoInstall([
            self::zeile('galerie', true, 'zu alt'),
            self::zeile('kontaktanfrage', true, 'zu alt'),
            self::zeile('zucht-suche', true, 'zu alt'),
        ]);

        $this->assertCount(3, $gruende);
        $this->assertStringContainsString('kontaktanfrage', implode(' ', $gruende));
    }

    /**
     * Ein deaktiviertes Addon läuft ohnehin nicht mit. Es aufzuhalten hiesse,
     * ein Update wegen etwas zu verweigern, das niemand benutzt - und der
     * Betreiber käme nicht mehr voran, ohne zu verstehen, warum.
     */
    public function testEinDeaktiviertesAddonHaeltNichtsAuf(): void {
        $this->assertSame([], UpdateService::addonsBlockingAutoInstall([
            self::zeile('galerie', enabled: false, reasonTarget: 'unterstützt höchstens Kern-Linie 0.7'),
        ]));
    }

    public function testVertraeglicheAddonsHaltenNichtsAuf(): void {
        $this->assertSame([], UpdateService::addonsBlockingAutoInstall([
            self::zeile('galerie', true, null),
            self::zeile('merkliste', true, null),
        ]));
    }

    public function testOhneAddonsIstNichtsImWeg(): void {
        $this->assertSame([], UpdateService::addonsBlockingAutoInstall([]));
    }

    /**
     * Ein leerer Grund ist kein Grund. AddonOverview liefert `reasonTarget`
     * als null, wenn keine Zielversion vorliegt - daraus darf keine Sperre
     * werden, sonst bliebe jedes Update aus, sobald die Prüfung selbst
     * unvollständig ist. "Konnte nicht prüfen" ist nicht "geprüft, ist kaputt".
     *
     * @param mixed $wert
     */
    #[DataProvider('leereGruende')]
    public function testLeereOderUnbrauchbareGruendeHaltenNichtsAuf(mixed $wert): void {
        $this->assertSame([], UpdateService::addonsBlockingAutoInstall([
            ['slug' => 'galerie', 'enabled' => true, 'reasonTarget' => $wert],
        ]));
    }

    public static function leereGruende(): array {
        return [
            'null' => [null],
            'leerer String' => [''],
            'kein String' => [['zu alt']],
            'Zahl' => [0],
        ];
    }

    // ---- Der entscheidende Fall: gibt es Ersatz? ------------------------

    /**
     * DAS ist das Kriterium (#364), nicht der Versionssprung.
     *
     * Ein erster Entwurf stellte auf den Linienwechsel ab (0.7.x -> 0.8.x).
     * Das war falsch: Updates sollen grundsätzlich automatisch laufen, so wie
     * es Kanal und Reichweite vorgeben. Ein Linienwechsel, für den passende
     * Addon-Fassungen bereitliegen, ist unproblematisch - die Addon-Phase
     * zieht sie nach dem Kern von selbst mit.
     */
    public function testMitPassenderFassungImStoreIstNichtsImWeg(): void {
        $this->assertSame([], UpdateService::addonsBlockingAutoInstall([
            self::zeile('galerie', true, 'unterstützt höchstens Kern-Linie 0.7', availableSupportsTarget: true),
        ]));
    }

    public function testOhnePassendeFassungBrauchtEsAufsicht(): void {
        $gruende = UpdateService::addonsBlockingAutoInstall([
            self::zeile('galerie', true, 'unterstützt höchstens Kern-Linie 0.7', availableSupportsTarget: false),
        ]);

        $this->assertCount(1, $gruende);
        $this->assertStringContainsString('keine passende Fassung', $gruende[0]);
    }

    /**
     * "Konnte nicht prüfen" ist nicht "geprüft, ist in Ordnung". Ohne
     * Katalog-Eintrag gilt die strengere Seite - sonst liefe ein Update
     * ausgerechnet dann durch, wenn niemand sagen kann, was es anrichtet.
     */
    public function testOhneKatalogAussageGiltDieStrengereSeite(): void {
        $gruende = UpdateService::addonsBlockingAutoInstall([
            self::zeile('galerie', true, 'unterstützt höchstens Kern-Linie 0.7', availableSupportsTarget: null),
        ]);

        $this->assertCount(1, $gruende);
        $this->assertStringContainsString('nicht feststellen', $gruende[0]);
    }

    /**
     * Gemischt: eines bekommt Ersatz, eines nicht. Nur das zweite hält auf -
     * und es muss namentlich genannt werden, sonst weiss der Betreiber nicht,
     * wo er ansetzen soll.
     */
    public function testNurDasAddonOhneErsatzHaeltAuf(): void {
        $gruende = UpdateService::addonsBlockingAutoInstall([
            self::zeile('galerie', true, 'zu alt', availableSupportsTarget: true),
            self::zeile('kontaktanfrage', true, 'zu alt', availableSupportsTarget: false),
        ]);

        $this->assertCount(1, $gruende);
        $this->assertStringContainsString('kontaktanfrage', $gruende[0]);
        $this->assertStringNotContainsString('galerie', $gruende[0]);
    }

    // ---- Die Meldung: genau einmal je Zielversion -----------------------

    /**
     * Der Lauf ist TÄGLICH. Ohne Merkzettel stünde jeden Tag dieselbe Mail im
     * Postfach, und spätestens nach der dritten sieht niemand mehr hin.
     */
    public function testDieMeldungKommtNurEinmalJeZielversion(): void {
        $this->assertTrue(
            UpdateService::shouldNotifyBlocked('', '0.8.0'),
            'Beim ersten Mal muss gemeldet werden.'
        );
        $this->assertFalse(
            UpdateService::shouldNotifyBlocked('0.8.0', '0.8.0'),
            'Derselbe Fall am nächsten Tag darf nicht erneut melden.'
        );
    }

    /**
     * Eine NEUE Zielversion ist eine neue Lage - die betroffenen Addons können
     * andere sein, und der Betreiber soll erneut erfahren, dass sein System
     * stehen bleibt.
     */
    public function testEineNeuereZielversionMeldetErneut(): void {
        $this->assertTrue(UpdateService::shouldNotifyBlocked('0.8.0', '0.8.1'));
    }

    /**
     * Ohne Zielversion gibt es nichts zu melden - "konnte nicht prüfen" ist
     * keine Meldung wert und würde nur Rauschen erzeugen.
     */
    public function testOhneZielversionWirdNichtGemeldet(): void {
        $this->assertFalse(UpdateService::shouldNotifyBlocked('', ''));
        $this->assertFalse(UpdateService::shouldNotifyBlocked('0.8.0', ''));
    }

    /**
     * @return array<string, mixed>
     */
    private static function zeile(
        string $slug,
        bool $enabled,
        ?string $reasonTarget,
        ?bool $availableSupportsTarget = null
    ): array {
        return [
            'slug' => $slug,
            'name' => $slug,
            'installedVersion' => '1.0.0',
            'availableVersion' => null,
            'hasUpdate' => false,
            'enabled' => $enabled,
            'manifestError' => null,
            'reasonCurrent' => null,
            'reasonTarget' => $reasonTarget,
            'availableSupportsTarget' => $availableSupportsTarget,
        ];
    }
}
