<?php
// tests/Unit/Helper/TelUrlTest.php

namespace Tests\Unit\Helper;

use App\Helper\TelUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * App\Helper\TelUrl macht eine von Hand eingetragene Telefonnummer für ein
 * `tel:`-Verweisziel maschinenlesbar (#359).
 *
 * Der wichtigste Teil dieser Tests ist der, der etwas NICHT tut: Aus einer
 * führenden `0` darf keine Landesvorwahl werden. Das wäre geraten, und der
 * Bestand enthält Deckstationen in Dänemark und Norwegen - `+49` davorzusetzen
 * erzeugte dort eine falsche, tatsächlich wählbare Nummer. Das ist schlimmer
 * als gar kein Link.
 */
class TelUrlTest extends TestCase {

    /** @return array<string, array{0: string, 1: string}> */
    public static function waehlbareNummern(): array {
        return [
            'schlicht' => ['030123456', 'tel:030123456'],
            'mit Leerzeichen' => ['030 12 34 56', 'tel:030123456'],
            'mit Bindestrich' => ['0301-234567', 'tel:0301234567'],
            'mit Schraegstrich' => ['0301/234567', 'tel:0301234567'],
            'mit Klammern' => ['(0301) 234567', 'tel:0301234567'],
            'mit Punkten' => ['0301.234.567', 'tel:0301234567'],
            'international' => ['+49 301 234567', 'tel:+49301234567'],
            'international kompakt' => ['+4530123456', 'tel:+4530123456'],
            'aussen Leerraum' => ['  030123456  ', 'tel:030123456'],
        ];
    }

    #[DataProvider('waehlbareNummern')]
    public function testWaehlbareNummernWerdenVerlinkt(string $eingabe, string $erwartet): void {
        // Die Gliederungszeichen fallen weg, die Ziffernfolge bleibt.
        $this->assertSame($erwartet, TelUrl::hrefOrNull($eingabe));
    }

    /**
     * Die geklammerte Null nach der Landesvorwahl bedeutet "beim Wählen aus
     * dem Ausland entfällt sie". Würde sie wie eine gewöhnliche Klammer nur
     * entfernt, entstünde +490301… - eine Nummer, die es nicht gibt, die aber
     * tatsächlich gewählt würde. Das wäre schlimmer als kein Link.
     */
    public function testGeklammerteNullEntfaelltInternational(): void {
        $this->assertSame('tel:+49301234567', TelUrl::hrefOrNull('+49 (0) 301 234567'));
        $this->assertSame('tel:+49301234567', TelUrl::hrefOrNull('+49(0)301234567'));
    }

    /**
     * Der eigentliche Punkt: Die nationale Schreibweise bleibt national.
     */
    public function testFuehrendeNullBleibtStehen(): void {
        $this->assertSame(
            'tel:030123456',
            TelUrl::hrefOrNull('030 123456'),
            'Aus einer fuehrenden 0 darf keine Landesvorwahl erfunden werden - der Bestand ist nicht nur deutsch.'
        );
    }

    public function testFuehrendesPlusBleibtErhalten(): void {
        $this->assertSame('tel:+4712345678', TelUrl::hrefOrNull('+47 12 34 56 78'));
    }

    /** @return array<string, array{0: ?string}> */
    public static function nichtWaehlbar(): array {
        return [
            'null' => [null],
            'leer' => [''],
            'nur Leerraum' => ['   '],
            'Freitext' => ['auf Anfrage'],
            'Nummer mit Zusatz' => ['0301 234567 (nur vormittags)'],
            'zu kurz' => ['123'],
            'Buchstaben drin' => ['0301-ABCDEF'],
        ];
    }

    /**
     * Was keine eindeutige Nummer ist, bleibt Text. Ein `tel:`-Link darauf
     * würde beim Antippen irgendetwas wählen - die Zeile ist dann lieber
     * unverlinkt, so wie sie es vor #359 überall war.
     */
    #[DataProvider('nichtWaehlbar')]
    public function testNichtWaehlbaresBleibtText(?string $eingabe): void {
        $this->assertNull(TelUrl::hrefOrNull($eingabe));
    }
}
