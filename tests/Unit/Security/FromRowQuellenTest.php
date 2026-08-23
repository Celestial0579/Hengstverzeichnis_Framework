<?php
// tests/Unit/Security/FromRowQuellenTest.php

namespace Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Jede Abfrage, deren Zeile nach `SecondFactors::fromRow()` geht, muss `id`
 * mitselektieren (#353).
 *
 * ## Warum ein Test auf den Quelltext und nicht auf das Verhalten
 *
 * Das Verhalten IST getestet — `fromRow()` wirft, wenn weder `passkey_count`
 * noch `id` dasteht. Nur schlägt dieser Wurf erst zur Laufzeit zu, an einer
 * Stelle mitten im Anmeldeweg, und nur wenn genau dieser Zweig durchlaufen
 * wird. Eine Abfrage, der `id` fehlt, soll auffallen, BEVOR sie jemand
 * ausliefert.
 *
 * Genau so ist es passiert: Die Abfrage in
 * `AuthController::process2faReauth()` holte kein `id`. Der Step-up-Schutz
 * vor einer 2FA-Neukonfiguration hielt ein Passkey-only-Konto damit für
 * ungeschützt — also für keines, das den Schutz braucht. Aufgefallen ist es
 * erst, als fromRow() zu werfen begann.
 */
class FromRowQuellenTest extends TestCase {

    /** @return array<int, array{0: string}> */
    public static function dateienMitFromRow(): array {
        $wurzel = dirname(__DIR__, 3) . '/src';
        $treffer = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($wurzel, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $datei) {
            if (!$datei->isFile() || $datei->getExtension() !== 'php') {
                continue;
            }
            $inhalt = (string)file_get_contents($datei->getPathname());
            if (str_contains($inhalt, 'fromRow(') && !str_contains($inhalt, 'function fromRow')) {
                $treffer[] = [$datei->getPathname()];
            }
        }

        return $treffer;
    }

    #[DataProvider('dateienMitFromRow')]
    public function testJedeSelectMitFaktorSpaltenHoltAuchDieId(string $pfad): void {
        $inhalt = (string)file_get_contents($pfad);

        // Alle SELECT-Listen bis zum FROM einsammeln.
        preg_match_all('/SELECT\s+(.+?)\s+FROM\s+`?users`?/is', $inhalt, $treffer);

        $ohneId = [];
        foreach ($treffer[1] as $spalten) {
            $spalten = strtolower(preg_replace('/\s+/', ' ', $spalten) ?? '');
            // Nur Abfragen, die ueberhaupt Faktor-Spalten holen - andere
            // users-Abfragen gehen nicht nach fromRow().
            if (!str_contains($spalten, 'totp_enabled') && !str_contains($spalten, 'email_2fa_enabled')) {
                continue;
            }
            if (preg_match('/(^|,|\s)id(\s|,|$)/', $spalten) !== 1) {
                $ohneId[] = trim($spalten);
            }
        }

        $this->assertSame(
            [],
            $ohneId,
            basename($pfad) . ": Eine users-Abfrage holt Faktor-Spalten, aber kein `id`.\n"
            . "Passkeys stehen nicht in dieser Zeile - ohne die Kennung kann SecondFactors\n"
            . "die Frage 'hat dieses Konto einen Passkey' nicht beantworten und wirft.\n"
            . "Betroffen: " . implode(' | ', $ohneId)
        );
    }
}
