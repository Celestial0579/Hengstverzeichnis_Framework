<?php
// tests/Integration/OneTimeProofsTest.php

namespace Tests\Integration;

use App\Security\OneTimeProofs;
use PHPUnit\Framework\TestCase;

/**
 * Backup-Codes und TOTP-Zeitschlitze werden genau einmal verbraucht (Audit
 * N43) - auch bei parallelen Anfragen, gegen MariaDB.
 */
class OneTimeProofsTest extends TestCase {

    use KontoTestbestand;
    use ParalleleArbeiter;

    protected function setUp(): void {
        self::$db->exec("DELETE FROM users");
    }

    /**
     * Zehn Codes wie bei der Einrichtung; bcrypt mit Kosten 10, damit die
     * Tests nicht unnötig lange rechnen.
     *
     * @return array{0: int, 1: list<string>} Konto-ID und Klartextcodes
     */
    private function kontoMitCodes(string $name, int $kosten = 10): array {
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4)));
        }
        $abdruecke = array_map(
            static fn(string $c): string => password_hash($c, PASSWORD_BCRYPT, ['cost' => $kosten]),
            $codes
        );
        $id = $this->kontoAnlegen($name, ['backup_codes' => json_encode($abdruecke)]);
        return [$id, $codes];
    }

    /** @return list<mixed> */
    private function abdruecke(int $userId): array {
        $codes = json_decode((string)$this->zeile($userId)['backup_codes'], true);
        $this->assertIsArray($codes);
        return $codes;
    }

    public function testEinCodeGiltGenauEinmal(): void {
        [$id, $codes] = $this->kontoMitCodes('otp_seriell');

        // Schreibweise wie auf dem Ausdruck: mit Bindestrich, klein.
        $eingabe = strtolower(substr($codes[3], 0, 4) . '-' . substr($codes[3], 4));
        $this->assertTrue(OneTimeProofs::redeemBackupCode($id, $eingabe));
        $this->assertFalse(OneTimeProofs::redeemBackupCode($id, $codes[3]), 'Verbraucht ist verbraucht.');
        $this->assertCount(9, $this->abdruecke($id));
        $this->assertFalse(OneTimeProofs::redeemBackupCode($id, 'FALSCH00'));
        $this->assertFalse(OneTimeProofs::redeemBackupCode($id, ''));
    }

    public function testParalleleEinloesungDesselbenCodesGiltEinmal(): void {
        [$id, $codes] = $this->kontoMitCodes('otp_parallel');

        $ausgaben = $this->parallelAusfuehren(array_fill(0, 5, ['backup', (string)$id, $codes[5]]));

        $this->assertSame(1, self::anzahl($ausgaben, 'OK'), implode('', $ausgaben));
        $this->assertCount(9, $this->abdruecke($id));
    }

    /**
     * Zwei verschiedene Codes gleichzeitig: Keine Einlösung darf einen von der
     * anderen verbrauchten Code wiederherstellen. Bisher schrieb die
     * langsamere ihren Satz "alle außer meinem" zurück - der Code der
     * schnelleren galt danach wieder.
     */
    public function testParalleleVerschiedeneCodesStellenNichtsWiederHer(): void {
        [$id, $codes] = $this->kontoMitCodes('otp_zwei');

        $ausgaben = $this->parallelAusfuehren([
            ['backup', (string)$id, $codes[1]],
            ['backup', (string)$id, $codes[8]],
        ]);
        $erfolge = self::anzahl($ausgaben, 'OK');

        $this->assertGreaterThanOrEqual(1, $erfolge, implode('', $ausgaben));
        $this->assertCount(10 - $erfolge, $this->abdruecke($id));

        // Ein eingelöster Code gilt kein zweites Mal.
        foreach ([[$codes[1], $ausgaben[0]], [$codes[8], $ausgaben[1]]] as [$code, $ausgabe]) {
            if (str_contains($ausgabe, 'ERGEBNIS=OK')) {
                $this->assertFalse(OneTimeProofs::redeemBackupCode($id, $code), 'Verbrauchter Code wiederhergestellt.');
            }
        }
    }

    /**
     * Wird der Codesatz erneuert, während eine Einlösung noch prüft, bleibt
     * der neue Satz unangetastet (Audit N43). Bisher schrieb die laufende
     * Einlösung den Rest des ALTEN Satzes darüber.
     *
     * Die Prüfschleife dauert hier absichtlich lange (Kosten 12, gesuchter
     * Code an letzter Stelle); der Arbeiter meldet BEREIT, bevor er liest.
     */
    public function testAustauschWaehrendDerPruefungBleibtBestehen(): void {
        [$id, $codes] = $this->kontoMitCodes('otp_austausch', 12);

        $lauf = $this->parallelStarten([['backup', (string)$id, $codes[9]]]);
        self::wartenBis($lauf['start'] + 0.3);

        $neu = json_encode([password_hash('NEUERCOD', PASSWORD_BCRYPT, ['cost' => 10])]);
        self::$db->prepare("UPDATE users SET backup_codes = ? WHERE id = ?")->execute([$neu, $id]);

        $ausgaben = $this->einsammeln($lauf);
        $this->assertStringContainsString('BEREIT', $ausgaben[0]);
        $this->assertSame(0, self::anzahl($ausgaben, 'OK'), $ausgaben[0]);
        $this->assertSame($neu, (string)$this->zeile($id)['backup_codes'], 'Der neue Satz muss byte-gleich bleiben.');
    }

    public function testTotpSchlitzKannNurSteigen(): void {
        $id = $this->kontoAnlegen('otp_totp', ['last_totp_timeslice' => 100]);

        $this->assertFalse(OneTimeProofs::consumeTotpSlice($id, 100), 'Derselbe Schlitz ist verbraucht.');
        $this->assertFalse(OneTimeProofs::consumeTotpSlice($id, 99), 'Ein älterer Schlitz darf den Wert nicht senken.');
        $this->assertSame(100, (int)$this->zeile($id)['last_totp_timeslice']);

        $this->assertTrue(OneTimeProofs::consumeTotpSlice($id, 101));
        $this->assertSame(101, (int)$this->zeile($id)['last_totp_timeslice']);

        $ohne = $this->kontoAnlegen('otp_totp_neu');
        $this->assertTrue(OneTimeProofs::consumeTotpSlice($ohne, 5), 'Ohne Vorwert gilt jeder Schlitz.');
    }

    public function testParalleleTotpEinloesungGiltEinmal(): void {
        $id = $this->kontoAnlegen('otp_totp_par', ['last_totp_timeslice' => 101]);

        $ausgaben = $this->parallelAusfuehren(array_fill(0, 10, ['totp', (string)$id, '102']));

        $this->assertSame(1, self::anzahl($ausgaben, 'OK'), implode('', $ausgaben));
        $this->assertSame(102, (int)$this->zeile($id)['last_totp_timeslice']);
    }

    public function testGeloeschteOderDeaktivierteKontenBestehenNie(): void {
        [$geloescht, $codesA] = $this->kontoMitCodes('otp_geloescht');
        self::$db->prepare("UPDATE users SET deleted_at = NOW() WHERE id = ?")->execute([$geloescht]);
        [$deaktiviert, $codesB] = $this->kontoMitCodes('otp_deaktiviert');
        self::$db->prepare("UPDATE users SET deactivated_at = NOW() WHERE id = ?")->execute([$deaktiviert]);

        $this->assertFalse(OneTimeProofs::redeemBackupCode($geloescht, $codesA[0]));
        $this->assertFalse(OneTimeProofs::redeemBackupCode($deaktiviert, $codesB[0]));
        $this->assertFalse(OneTimeProofs::consumeTotpSlice($geloescht, 999));
        $this->assertFalse(OneTimeProofs::consumeTotpSlice($deaktiviert, 999));
    }
}
