<?php
// tests/Integration/KontoSicherheitTest.php

namespace Tests\Integration;

use App\Security\EmailSecondFactor;
use App\Security\KontoSicherheit;
use App\Security\SecondFactors;
use PHPUnit\Framework\TestCase;

/**
 * Die Nebenwirkungen sicherheitsrelevanter Kontoänderungen (Audit M16, N60)
 * gegen MariaDB.
 */
class KontoSicherheitTest extends TestCase {

    use KontoTestbestand;

    protected function setUp(): void {
        self::$db->exec("DELETE FROM email_2fa_codes");
        self::$db->exec("DELETE FROM api_keys");
        self::$db->exec("DELETE FROM users");
        $_SESSION = [];
    }

    private function mitAntrag(string $name, array $mehr = []): int {
        return $this->kontoAnlegen($name, $mehr + [
            'pending_email' => $name . '-neu@example.org',
            'pending_email_token' => hash('sha256', 'token-' . $name),
            'pending_email_expires_at' => date('Y-m-d H:i:s', time() + 48 * 3600),
        ]);
    }

    private function apiSchluesselAnlegen(int $userId): void {
        self::$db->prepare(
            "INSERT INTO api_keys (user_id, label, token_hash, token_prefix, expires_at)
             VALUES (?, 'IT', ?, 'hv_test', NOW() + INTERVAL 30 DAY)"
        )->execute([$userId, hash('sha256', random_bytes(16))]);
    }

    private function aktiveSchluessel(int $userId): int {
        $stmt = self::$db->prepare("SELECT COUNT(*) FROM api_keys WHERE user_id = ? AND revoked_at IS NULL");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    private function offeneCodes(int $userId): int {
        $stmt = self::$db->prepare("SELECT COUNT(*) FROM email_2fa_codes WHERE user_id = ?");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    // ---- Passwortwechsel (M16) -----------------------------------------

    public function testNachPasswortwechselEndetAllesAusserDemKonto(): void {
        $id = $this->mitAntrag('ks_pw');
        EmailSecondFactor::issue($id, EmailSecondFactor::PURPOSE_LOGIN);
        $this->apiSchluesselAnlegen($id);
        $this->apiSchluesselAnlegen($id);

        $widerrufen = KontoSicherheit::nachPasswortwechsel($id);

        $this->assertSame(2, $widerrufen);
        $this->assertSame(0, $this->aktiveSchluessel($id));
        $this->assertSame(0, $this->offeneCodes($id));
        $zeile = $this->zeile($id);
        $this->assertNull($zeile['pending_email'], 'Der offene Adressantrag darf den Passwortwechsel nicht überleben.');
        $this->assertNull($zeile['pending_email_token']);
        $this->assertNull($zeile['pending_email_expires_at']);
    }

    /**
     * Der Normalfall der vier Passwortwege: Ihr UPDATE hat den Antrag schon
     * geleert (ADRESSANTRAG_LEEREN). Protokolliert wird trotzdem - einmal.
     */
    public function testDerImUpdateGeleerteAntragWirdEinmalProtokolliert(): void {
        $id = $this->mitAntrag('ks_pwlog');
        $vorher = $this->auditAnzahl('Offener Adressantrag verworfen');

        $antrag = KontoSicherheit::offenerAdressantrag($id);
        $this->assertSame('ks_pwlog-neu@example.org', $antrag);
        self::$db->prepare("UPDATE users SET " . KontoSicherheit::ADRESSANTRAG_LEEREN . " WHERE id = ?")->execute([$id]);
        KontoSicherheit::nachPasswortwechsel($id, $antrag);

        $this->assertSame($vorher + 1, $this->auditAnzahl('Offener Adressantrag verworfen'));
    }

    public function testAdressantragVerwerfenOhneAntragMeldetNichtsUndProtokolliertNichts(): void {
        $id = $this->kontoAnlegen('ks_ohne');
        $vorher = $this->auditAnzahl('Offener Adressantrag verworfen');

        $this->assertNull(KontoSicherheit::offenerAdressantrag($id));
        $this->assertFalse(KontoSicherheit::adressantragVerwerfen($id));
        $this->assertSame($vorher, $this->auditAnzahl('Offener Adressantrag verworfen'));
    }

    public function testAdressantragVerwerfenMitAntrag(): void {
        $id = $this->mitAntrag('ks_mit');
        $vorher = $this->auditAnzahl('Offener Adressantrag verworfen');

        $this->assertTrue(KontoSicherheit::adressantragVerwerfen($id, 'Test'));
        $this->assertNull($this->zeile($id)['pending_email_token']);
        $this->assertSame($vorher + 1, $this->auditAnzahl('Offener Adressantrag verworfen'));
        $this->assertFalse(KontoSicherheit::adressantragVerwerfen($id), 'Ein zweites Mal gibt es nichts zu verwerfen.');
    }

    // ---- 2FA-Reset durch die Verwaltung (N60) --------------------------

    private function vollGeschuetzt(string $name): int {
        $id = $this->mitAntrag($name, [
            'totp_secret' => 'irgendwas',
            'totp_enabled' => 1,
            'email_2fa_enabled' => 1,
            'backup_codes' => json_encode(['a', 'b']),
            'last_totp_timeslice' => 123,
            'session_version' => 3,
        ]);
        $this->passkeyAnlegen($id);
        $this->passkeyAnlegen($id);
        EmailSecondFactor::issue($id, EmailSecondFactor::PURPOSE_LOGIN);
        $this->apiSchluesselAnlegen($id);
        return $id;
    }

    public function testZweiteFaktorenZuruecksetzenRaeumtAllesAb(): void {
        $id = $this->vollGeschuetzt('ks_reset');

        $ergebnis = KontoSicherheit::zweiteFaktorenZuruecksetzen($id);

        $this->assertSame(['passkeys' => 2, 'api_schluessel' => 1], $ergebnis);
        $zeile = $this->zeile($id);
        $this->assertNull($zeile['totp_secret']);
        $this->assertSame(0, (int)$zeile['totp_enabled']);
        $this->assertSame(0, (int)$zeile['email_2fa_enabled']);
        $this->assertNull($zeile['backup_codes']);
        $this->assertNull($zeile['last_totp_timeslice']);
        $this->assertNull($zeile['pending_email_token'], 'Auch ein offener Adressantrag geht mit (M16).');
        $this->assertSame(4, (int)$zeile['session_version'], 'Alle Sitzungen müssen enden (D07).');
        $this->assertSame(0, $this->offeneCodes($id));
        $this->assertSame(0, $this->aktiveSchluessel($id));

        $stmt = self::$db->prepare("SELECT COUNT(*) FROM user_passkeys WHERE user_id = ?");
        $stmt->execute([$id]);
        $this->assertSame(0, (int)$stmt->fetchColumn(), 'Kein Passkey darf den Reset überleben.');

        $this->assertSame([], SecondFactors::forUser($id));
        $stmt = self::$db->prepare("SELECT COUNT(*) FROM users u WHERE u.id = ? AND " . SecondFactors::sqlHasAnyFactor('u'));
        $stmt->execute([$id]);
        $this->assertSame(0, (int)$stmt->fetchColumn(), 'Auch die Mengenabfrage muss das Konto als ungeschützt sehen.');
    }

    public function testOhneSitzungsendeBleibenSitzungenUndSchluessel(): void {
        $id = $this->vollGeschuetzt('ks_nurfaktoren');

        $ergebnis = KontoSicherheit::zweiteFaktorenZuruecksetzen($id, false);

        $this->assertSame(['passkeys' => 2, 'api_schluessel' => 0], $ergebnis);
        $this->assertSame(3, (int)$this->zeile($id)['session_version']);
        $this->assertSame(1, $this->aktiveSchluessel($id));
    }

    /** In einer offenen Transaktion des Aufrufers schreibt der Reset nicht selbst fest. */
    public function testInEinerOffenenTransaktionEntscheidetDerAufrufer(): void {
        $id = $this->vollGeschuetzt('ks_transaktion');

        self::$db->beginTransaction();
        KontoSicherheit::zweiteFaktorenZuruecksetzen($id);
        $this->assertTrue(self::$db->inTransaction(), 'Die fremde Transaktion darf nicht beendet worden sein.');
        self::$db->rollBack();

        $this->assertSame(1, (int)$this->zeile($id)['totp_enabled']);
        $stmt = self::$db->prepare("SELECT COUNT(*) FROM user_passkeys WHERE user_id = ?");
        $stmt->execute([$id]);
        $this->assertSame(2, (int)$stmt->fetchColumn());
    }

    /** Ein Hinweis an ein Konto ohne Adresse ist kein Fehler - und bricht nie ab. */
    public function testHinweisOhneAdresseBrichtNichtAb(): void {
        $id = $this->kontoAnlegen('ks_ohneadresse', ['email' => null]);

        KontoSicherheit::hinweisSenden($id, 'Titel', 'Text');
        KontoSicherheit::hinweisSenden(999999, 'Titel', 'Text');

        $this->addToAssertionCount(1);
    }
}
