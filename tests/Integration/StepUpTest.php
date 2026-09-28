<?php
// tests/Integration/StepUpTest.php

namespace Tests\Integration;

use App\Security\Crypto;
use App\Security\EmailSecondFactor;
use App\Security\SecondFactors;
use App\Security\StepUp;
use App\Security\Totp;
use PHPUnit\Framework\TestCase;

/**
 * Welcher Code bestätigt eine Faktor-Änderung? (Audit N10) - gegen MariaDB,
 * weil TOTP-Zeitschlitz und Mailcode dort liegen.
 *
 * Die Matrix: TOTP-Konten nur mit TOTP, Mailcode-Konten mit dem Mailcode,
 * reine Passkey-Konten mit keinem Code (sie bestätigen mit dem Passkey), und
 * TOTP + Mailcode nur mit TOTP. Bis hierher genügte bei jedem Konto ohne
 * TOTP ein Mailcode - auch wenn der gar nicht sein Faktor war.
 */
class StepUpTest extends TestCase {

    use KontoTestbestand;

    private string $secret = '';

    protected function setUp(): void {
        self::$db->exec("DELETE FROM email_2fa_codes");
        self::$db->exec("DELETE FROM users");
        $this->secret = Totp::generateSecret();
    }

    private function totpKonto(string $name, array $mehr = []): int {
        return $this->kontoAnlegen($name, $mehr + [
            'totp_secret' => Crypto::encrypt($this->secret),
            'totp_enabled' => 1,
        ]);
    }

    /** @return array<int, string> */
    private function faktoren(int $userId): array {
        $faktoren = StepUp::faktoren($userId);
        $this->assertIsArray($faktoren);
        return $faktoren;
    }

    public function testTotpKontoBestaetigtNurMitTotp(): void {
        $id = $this->totpKonto('su_totp');
        $mailcode = EmailSecondFactor::issue($id, EmailSecondFactor::PURPOSE_SETUP);

        $this->assertFalse(
            StepUp::codePruefen($id, $this->zeile($id), $this->faktoren($id), '', $mailcode),
            'Ein Mailcode ist kein Faktor dieses Kontos.'
        );
        $this->assertTrue(StepUp::codePruefen($id, $this->zeile($id), $this->faktoren($id), Totp::getCode($this->secret), ''));
        $this->assertNotNull($this->zeile($id)['last_totp_timeslice'], 'Der Zeitschlitz muss verbraucht sein (#111).');
    }

    /** Derselbe Code ein zweites Mal - der Replay-Schutz gilt auch hier. */
    public function testEinTotpCodeGiltNurEinmal(): void {
        $id = $this->totpKonto('su_replay');
        $code = Totp::getCode($this->secret);

        $this->assertTrue(StepUp::codePruefen($id, $this->zeile($id), $this->faktoren($id), $code, ''));
        $this->assertFalse(StepUp::codePruefen($id, $this->zeile($id), $this->faktoren($id), $code, ''));
    }

    /**
     * Zwei parallele Bestätigungen lesen dieselbe, noch unverbrauchte Zeile
     * (Audit N43). Die zweite darf trotzdem nicht bestehen - der Verbrauch
     * vergleicht mit dem Stand in der Datenbank, nicht mit dem gelesenen.
     */
    public function testParallelGelesenerStandBestehtNurEinmal(): void {
        $id = $this->totpKonto('su_parallel');
        $code = Totp::getCode($this->secret);
        $gelesen = $this->zeile($id);

        $this->assertTrue(StepUp::codePruefen($id, $gelesen, $this->faktoren($id), $code, ''));
        $this->assertFalse(
            StepUp::codePruefen($id, $gelesen, $this->faktoren($id), $code, ''),
            'Mit dem vor dem Verbrauch gelesenen Stand gilt der Code kein zweites Mal.'
        );
    }

    public function testMailcodeKontoBestaetigtMitDemMailcode(): void {
        $id = $this->kontoAnlegen('su_mail', ['email_2fa_enabled' => 1]);
        $this->assertSame([SecondFactors::EMAIL], $this->faktoren($id));

        $this->assertFalse(StepUp::codePruefen($id, $this->zeile($id), $this->faktoren($id), '', '000000'));

        $code = EmailSecondFactor::issue($id, EmailSecondFactor::PURPOSE_SETUP);
        $this->assertTrue(StepUp::codePruefen($id, $this->zeile($id), $this->faktoren($id), '', $code));
    }

    /**
     * DER BEFUND. Ein Konto, dessen einziger Faktor ein Passkey ist, besteht
     * mit keinem Code - auch nicht mit einem gültigen Mailcode an seine
     * Adresse. Sonst ersetzte der Postfachzugang den Passkey.
     */
    public function testReinesPasskeyKontoBestehtMitKeinemCode(): void {
        $id = $this->kontoAnlegen('su_passkey');
        $this->passkeyAnlegen($id);
        $this->assertSame([SecondFactors::PASSKEY], $this->faktoren($id));

        $code = EmailSecondFactor::issue($id, EmailSecondFactor::PURPOSE_SETUP);
        $this->assertFalse(StepUp::codePruefen($id, $this->zeile($id), $this->faktoren($id), '', $code));
        $this->assertFalse(StepUp::codePruefen($id, $this->zeile($id), $this->faktoren($id), Totp::getCode($this->secret), ''));
    }

    public function testTotpUndMailcodeBestaetigtNurMitTotp(): void {
        $id = $this->totpKonto('su_beides', ['email_2fa_enabled' => 1]);
        $code = EmailSecondFactor::issue($id, EmailSecondFactor::PURPOSE_SETUP);

        $this->assertFalse(StepUp::codePruefen($id, $this->zeile($id), $this->faktoren($id), '', $code));
        $this->assertTrue(StepUp::codePruefen($id, $this->zeile($id), $this->faktoren($id), Totp::getCode($this->secret), ''));
    }

    /** Fail-closed (Audit N8): Ein nicht lesbares Secret besteht nie. */
    public function testUnlesbaresSecretBestehtNie(): void {
        $id = $this->kontoAnlegen('su_kaputt', ['totp_secret' => 'v1:kaputt', 'totp_enabled' => 1]);

        $this->assertFalse(StepUp::codePruefen($id, $this->zeile($id), $this->faktoren($id), Totp::getCode($this->secret), ''));
    }

    /**
     * Die Faktoren kommen fail-closed: Ein gelöschtes oder gesperrtes Konto
     * hat keine Liste (null), und null ist kein "braucht keinen Nachweis".
     */
    public function testFaktorenEinesInaktivenKontosSindUnbestimmt(): void {
        $id = $this->kontoAnlegen('su_weg', ['deleted_at' => date('Y-m-d H:i:s')]);

        $this->assertNull(StepUp::faktoren($id));
        $this->assertFalse(StepUp::erfuellt($id));
    }

    public function testKontoOhneFaktorIstErfuellt(): void {
        $id = $this->kontoAnlegen('su_ohne');

        $_SESSION = [];
        $this->assertTrue(StepUp::erfuellt($id));

        $this->passkeyAnlegen($id);
        $this->assertFalse(StepUp::erfuellt($id), 'Ein Passkey zählt als Faktor - auch hier.');
    }
}
