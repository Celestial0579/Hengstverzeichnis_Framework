<?php
// tests/Unit/Security/PasskeyZeremonieTest.php

namespace Tests\Unit\Security;

use App\Security\Passkeys;
use PHPUnit\Framework\TestCase;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;

/**
 * Die Zeremonie muss dieselben Signaturverfahren kennen wie die
 * Registrierung (#353).
 *
 * ## Der Fehler, gegen den das steht
 *
 * `CeremonyStepManagerFactory` setzt von sich aus **nur ES256 und RS256**.
 * Die Registrierung bietet aber fünf an — ES256/384/512, RS256 und Ed25519 —,
 * und `CheckAlgorithm` prüft dort gegen genau diese Liste, lässt Ed25519 also
 * durch.
 *
 * Das ergäbe die unangenehmste Sorte Fehler: Ein SoloKey oder Nitrokey ließe
 * sich anstandslos registrieren und beim ersten Anmeldeversuch abweisen
 * („Unsupported algorithm"), ohne dass der Benutzer versteht, warum. Der
 * Schlüssel wäre im Profil zu sehen und trotzdem nutzlos.
 *
 * Beim Beheben ist mir prompt der nächste Fehler unterlaufen:
 * `Manager::create()` nimmt **keine** Argumente, die Algorithmen kommen über
 * `add()`. Ein Manager, dem man sie als Array übergibt, kennt anschließend
 * gar keine — die Anmeldung wäre für JEDEN Schlüssel fehlgeschlagen, also
 * schlimmer als vorher. Deshalb prüft dieser Test jedes Verfahren einzeln
 * nach, statt nur die Anzahl zu zählen.
 */
class PasskeyZeremonieTest extends TestCase {

    /** COSE-Kennung => Name, in der Reihenfolge aus registrierungsOptionen(). */
    private const VERFAHREN = [
        -7   => 'ES256',
        -35  => 'ES384',
        -36  => 'ES512',
        -257 => 'RS256',
        -8   => 'Ed25519',
    ];

    /**
     * Gefragt wird die FABRIK, nicht die Hilfsmethode.
     *
     * Die erste Fassung dieses Tests holte sich `algorithmen()` direkt. Der
     * blieb grün, als ich zur Gegenprobe den Aufruf von
     * `setAlgorithmManager()` entfernte — er prüfte die Liste, nicht ihre
     * Verwendung. Dieselbe Lücke zwischen Funktion und Verdrahtung wie bei
     * `fromRow()`, und im selben Durchgang.
     */
    public function testDieZeremonieKenntJedesAngeboteneVerfahren(): void {
        $fabrik = (new \ReflectionMethod(Passkeys::class, 'zeremonieFabrik'))->invoke(null);
        $manager = (new \ReflectionProperty($fabrik, 'algorithmManager'))->getValue($fabrik);

        foreach (self::VERFAHREN as $id => $name) {
            $manager->get($id);   // wirft, wenn unbekannt
            $this->addToAssertionCount(1);
        }
    }

    /**
     * Und der Beleg, dass es die Standardfabrik NICHT tut — sonst wäre der
     * Test oben eine Selbstverständlichkeit und niemand wüsste, warum
     * `setAlgorithmManager()` überhaupt gerufen wird.
     */
    public function testDieStandardfabrikKenntEd25519Nicht(): void {
        $fabrik = new CeremonyStepManagerFactory();
        $standard = (new \ReflectionProperty($fabrik, 'algorithmManager'))->getValue($fabrik);

        $standard->get(-7);   // ES256 kennt sie
        $this->addToAssertionCount(1);

        $this->expectException(\InvalidArgumentException::class);
        $standard->get(-8);   // Ed25519 nicht
    }

    /**
     * Die Liste in `registrierungsOptionen()` und die der Zeremonie müssen
     * deckungsgleich sein. Getestet über den Quelltext, weil die Optionen
     * ohne Datenbank nicht zu bauen sind — und weil genau das
     * Auseinanderlaufen der Fehler war.
     */
    public function testRegistrierungUndZeremonieBietenDasselbeAn(): void {
        $quelle = (string)file_get_contents(dirname(__DIR__, 3) . '/src/Security/Passkeys.php');

        preg_match('/pubKeyCredParams.*?\]\s*,/s', $quelle, $t);
        $block = $t[0] ?? '';
        if ($block === '') {
            // Die Optionen stehen ohne benannten Parameter da - dann den
            // Abschnitt zwischen create( und AuthenticatorSelectionCriteria.
            $von = strpos($quelle, 'PublicKeyCredentialParameters::create');
            $bis = strpos($quelle, 'AuthenticatorSelectionCriteria::create');
            $block = substr($quelle, (int)$von, (int)$bis - (int)$von);
        }

        foreach (self::VERFAHREN as $name) {
            $this->assertStringContainsString(
                $name . '::ID',
                $block,
                "Die Registrierung bietet {$name} nicht (mehr) an, die Zeremonie kennt es aber. "
                . 'Beide Listen gehören deckungsgleich - sonst registriert sich ein Schlüssel, '
                . 'der sich nie wieder anmelden kann, oder umgekehrt.'
            );
        }
    }
}
