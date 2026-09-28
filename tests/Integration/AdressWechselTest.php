<?php
// tests/Integration/AdressWechselTest.php

namespace Tests\Integration;

use App\Service\AdressWechsel;
use PHPUnit\Framework\TestCase;

/**
 * Übernahme einer bestätigten Adresse (Audit N52, N53) gegen MariaDB.
 *
 * Der Wettlauf zweier gleichzeitiger Aufrufe lässt sich deterministisch
 * nachstellen: Der zweite Aufruf ist genau der, dessen UPDATE nach dem
 * ersten läuft - er muss 0 Zeilen treffen und darf `email` nie auf NULL
 * setzen. Vor dem Fix tat er genau das.
 */
class AdressWechselTest extends TestCase {

    use KontoTestbestand;

    private const TOKEN_HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void {
        self::$db->exec("DELETE FROM users");
    }

    private function mitAntrag(string $name, string $neu, string $bis = '+48 hours'): int {
        return $this->kontoAnlegen($name, [
            'unprotected_since' => '2026-01-01 00:00:00',
            'pending_email' => $neu,
            'pending_email_token' => self::TOKEN_HASH,
            'pending_email_expires_at' => date('Y-m-d H:i:s', (int)strtotime($bis)),
        ]);
    }

    public function testDieAdresseWirdUebernommen(): void {
        $id = $this->mitAntrag('aw_ok', 'aw-neu@example.org');

        $this->assertSame(
            AdressWechsel::UEBERNOMMEN,
            AdressWechsel::uebernehmen(self::$db, $id, self::TOKEN_HASH, 'aw-neu@example.org')
        );

        $zeile = $this->zeile($id);
        $this->assertSame('aw-neu@example.org', $zeile['email']);
        $this->assertNull($zeile['pending_email']);
        $this->assertNull($zeile['pending_email_token']);
        $this->assertNull($zeile['unprotected_since']);
    }

    /** DER BEFUND N53: Der zweite Aufruf desselben Links. */
    public function testDerZweiteAufrufSetztDieAdresseNieAufNull(): void {
        $id = $this->mitAntrag('aw_doppelt', 'aw-doppelt@example.org');
        AdressWechsel::uebernehmen(self::$db, $id, self::TOKEN_HASH, 'aw-doppelt@example.org');
        self::$db->prepare("UPDATE users SET unprotected_since = '2026-02-02 00:00:00' WHERE id = ?")->execute([$id]);

        $this->assertSame(
            AdressWechsel::NICHT_MEHR_OFFEN,
            AdressWechsel::uebernehmen(self::$db, $id, self::TOKEN_HASH, 'aw-doppelt@example.org')
        );

        $zeile = $this->zeile($id);
        $this->assertSame('aw-doppelt@example.org', $zeile['email'], 'Die Adresse darf nie NULL werden.');
        $this->assertSame('2026-02-02 00:00:00', $zeile['unprotected_since'], 'Der zweite Aufruf darf nichts mehr schreiben.');
    }

    public function testEinAbgelaufenerAntragWirdNichtUebernommen(): void {
        $id = $this->mitAntrag('aw_alt', 'aw-alt-neu@example.org', '-1 hour');

        $this->assertSame(
            AdressWechsel::NICHT_MEHR_OFFEN,
            AdressWechsel::uebernehmen(self::$db, $id, self::TOKEN_HASH, 'aw-alt-neu@example.org')
        );
        $this->assertSame('aw_alt@example.org', $this->zeile($id)['email']);
    }

    public function testEinFremderTokenAbdruckWirdNichtUebernommen(): void {
        $id = $this->mitAntrag('aw_fremd', 'aw-fremd-neu@example.org');

        $this->assertSame(
            AdressWechsel::NICHT_MEHR_OFFEN,
            AdressWechsel::uebernehmen(self::$db, $id, str_repeat('b', 64), 'aw-fremd-neu@example.org')
        );
        $this->assertSame('aw_fremd@example.org', $this->zeile($id)['email']);
    }

    public function testEinGesperrtesKontoUebernimmtNichts(): void {
        $id = $this->mitAntrag('aw_gesperrt', 'aw-gesperrt-neu@example.org');
        self::$db->prepare("UPDATE users SET deactivated_at = NOW() WHERE id = ?")->execute([$id]);

        $this->assertSame(
            AdressWechsel::NICHT_MEHR_OFFEN,
            AdressWechsel::uebernehmen(self::$db, $id, self::TOKEN_HASH, 'aw-gesperrt-neu@example.org')
        );
    }

    /**
     * DER BEFUND N52: Die Adresse gehört inzwischen einem anderen Konto -
     * hier einem im Papierkorb, den der UNIQUE-Index mitzählt. Kein
     * Duplikatsfehler, sondern ein verworfener Antrag.
     */
    public function testEineVergebeneAdresseVerwirftDenAntrag(): void {
        $id = $this->mitAntrag('aw_vergeben', 'aw-belegt@example.org');
        $this->kontoAnlegen('aw_papierkorb', [
            'email' => 'aw-belegt@example.org',
            'deleted_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame(
            AdressWechsel::VERGEBEN,
            AdressWechsel::uebernehmen(self::$db, $id, self::TOKEN_HASH, 'aw-belegt@example.org')
        );

        $zeile = $this->zeile($id);
        $this->assertSame('aw_vergeben@example.org', $zeile['email']);
        $this->assertNull($zeile['pending_email']);
        $this->assertNull($zeile['pending_email_token'], 'Der Antrag darf nicht festhängen.');
    }

    /**
     * Das Wettrennen, das die Vorprüfung nicht sieht: Die Adresse wird
     * zwischen COUNT und UPDATE vergeben. Nachgestellt, indem die Vorprüfung
     * eine andere Adresse sieht als das UPDATE - der UNIQUE-Index greift, und
     * aus dem Duplikatsfehler wird VERGEBEN statt HTTP 500.
     */
    public function testDerDuplikatsfehlerDesUpdatesWirdAbgefangen(): void {
        $id = $this->mitAntrag('aw_rennen', 'aw-rennen@example.org');
        $this->kontoAnlegen('aw_schneller', ['email' => 'aw-rennen@example.org']);

        $this->assertSame(
            AdressWechsel::VERGEBEN,
            AdressWechsel::uebernehmen(self::$db, $id, self::TOKEN_HASH, 'aw-vorpruefung-sieht-nichts@example.org')
        );
        $this->assertSame('aw_rennen@example.org', $this->zeile($id)['email']);
        $this->assertNull($this->zeile($id)['pending_email_token']);
    }
}
