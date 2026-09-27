<?php
// tests/Unit/Controllers/SetupEnvAdminTest.php

namespace Tests\Unit\Controllers;

use App\Controllers\SetupController;
use PHPUnit\Framework\TestCase;

/**
 * Pruefregeln fuer die per Umgebung vorgegebenen Admin-Werte (Audit H2).
 *
 * Ein ungueltiger Wert fiel frueher still auf den Wizard zurueck - und der
 * bot dem ersten anonymen Besucher das Admin-Formular an. Jetzt gilt
 * fail-closed; diese Regeln entscheiden, wann.
 */
class SetupEnvAdminTest extends TestCase {

    public function testGueltigeWerteErgebenKeineFehler(): void {
        $this->assertSame([], SetupController::envAdminFehler('verbandsadmin', 'admin@example.com', 'Ein-starkes-Passwort-1'));
    }

    /** @return array<string, array{0:string, 1:string, 2:string}> */
    public static function ungueltigeWerte(): array {
        return [
            'leerer Name' => ['', 'admin@example.com', 'Ein-starkes-Passwort-1'],
            'reserviert' => ['admin', 'admin@example.com', 'Ein-starkes-Passwort-1'],
            'reserviert, Gross/Leerraum' => [' Admin ', 'admin@example.com', 'Ein-starkes-Passwort-1'],
            'Name mit @' => ['chef@verband', 'admin@example.com', 'Ein-starkes-Passwort-1'],
            'Name ueber 50 Zeichen' => [str_repeat('a', 51), 'admin@example.com', 'Ein-starkes-Passwort-1'],
            'keine Adresse' => ['verbandsadmin', 'keine-adresse', 'Ein-starkes-Passwort-1'],
            'Passwort zu kurz' => ['verbandsadmin', 'admin@example.com', 'kurz123'],
            'Platzhalter change-me-too' => ['verbandsadmin', 'admin@example.com', 'change-me-too'],
            'Platzhalter CHANGE-ME' => ['verbandsadmin', 'admin@example.com', ' CHANGE-ME '],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ungueltigeWerte')]
    public function testUngueltigeWerteWerdenAbgewiesen(string $name, string $email, string $passwort): void {
        $fehler = SetupController::envAdminFehler($name, $email, $passwort);
        $this->assertNotSame([], $fehler);

        // Die Seite ist vor der Einrichtung anonym erreichbar: Keine Meldung
        // darf einen der Werte wiedergeben.
        foreach ($fehler as $meldung) {
            if (trim($passwort) !== '') {
                $this->assertStringNotContainsString(trim($passwort), $meldung);
            }
            if (strlen(trim($name)) > 3) {
                $this->assertStringNotContainsString(trim($name), $meldung);
            }
        }
    }
}
