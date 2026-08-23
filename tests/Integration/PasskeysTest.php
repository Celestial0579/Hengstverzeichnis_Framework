<?php
// tests/Integration/PasskeysTest.php

namespace Tests\Integration;

use App\Database;
use App\Security\Passkeys;
use App\Security\SecondFactors;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Passkeys gegen eine ECHTE MariaDB (#353).
 *
 * ## Was hier geprüft wird - und was nicht
 *
 * Die eigentliche Zeremonie braucht einen Authenticator: einen Fingerabdruck,
 * eine Geräte-PIN, einen Sicherheitsschlüssel. Das lässt sich in einer
 * Testsuite nicht nachstellen, und eine nachgebaute Antwort würde die
 * Bibliothek zu Recht abweisen.
 *
 * Ein Test, der das mit selbstgebauten `authData` umgeht, wäre schlimmer als
 * keiner: Er prüfte dann die Signaturprüfung gegen eine Signatur, die der
 * Test selbst erzeugt hat - grün, ohne je etwas gezeigt zu haben. Genau
 * dieser Fehler ist bei der Bewertung einer FREMDEN Bibliothek in dieser
 * Runde schon einmal passiert (siehe Lehre `bibliothek-braucht-meldeweg`).
 *
 * Geprüft wird deshalb hier alles, was um die Zeremonie herum liegt und
 * eigene Entscheidungen trifft: Herkunft der RP-ID, Eigentum beim Entziehen,
 * die Zählung als zweiter Faktor, und die Stabilität des Benutzer-Handles.
 * Die Schranken vor der Zeremonie stehen in
 * tests/Functional/PasskeyAdminTest.php.
 */
class PasskeysTest extends TestCase {

    private PDO $db;
    private int $userId = 0;

    protected function setUp(): void {
        $this->db = Database::getInstance();
        $this->db->exec("DELETE FROM user_passkeys WHERE label LIKE 'IT-TEST%'");

        $benutzer = 'pk_it_' . bin2hex(random_bytes(4));
        $stmt = $this->db->prepare(
            "INSERT INTO users (username, email, password_hash, created_at)
             VALUES (?, ?, ?, NOW())"
        );
        $stmt->execute([$benutzer, $benutzer . '@example.com', password_hash('x', PASSWORD_DEFAULT)]);
        $this->userId = (int)$this->db->lastInsertId();
    }

    protected function tearDown(): void {
        if ($this->userId > 0) {
            $this->db->prepare("DELETE FROM users WHERE id = ?")->execute([$this->userId]);
        }
        $this->db->exec("DELETE FROM settings WHERE setting_key = 'base_url' AND setting_value LIKE '%pk-test%'");
    }

    // ---- RP-ID: die Bindung an die Domain -------------------------------

    /**
     * Die RP-ID kommt aus der KONFIGURATION, nicht aus der Anfrage.
     *
     * Sie bestimmt, wofür ein Passkey gilt. Käme sie aus `HTTP_HOST`, legte
     * der Aufrufer selbst fest, an welche Domain sein Schlüssel gebunden ist -
     * und die Bindung wäre keine.
     */
    public function testRpIdKommtAusDerKonfigurationUndNichtAusDerAnfrage(): void {
        $this->setzeBaseUrl('https://verband.pk-test.example/');
        $_SERVER['HTTP_HOST'] = 'boese.example';

        $this->assertSame('verband.pk-test.example', Passkeys::rpId());
    }

    /** Ein Port gehört nicht in die RP-ID - die Spezifikation kennt dort nur die Domain. */
    public function testRpIdEnthaeltKeinenPort(): void {
        $this->setzeBaseUrl('https://verband.pk-test.example:8443/pfad');

        $this->assertSame('verband.pk-test.example', Passkeys::rpId());
    }

    // ---- Eigentum --------------------------------------------------------

    /**
     * Ein Passkey lässt sich nur vom eigenen Konto entziehen.
     *
     * Die Benutzer-ID steht in der WHERE-Klausel, nicht in einer
     * vorgeschalteten Prüfung - sonst hinge die Berechtigung an der
     * Reihenfolge zweier Abfragen.
     */
    public function testFremderPasskeyLaesstSichNichtEntziehen(): void {
        $passkeyId = $this->legePasskeyAn($this->userId, 'IT-TEST eigen');
        $fremdeId = $this->userId + 999_000;

        $this->assertFalse(
            Passkeys::entziehen($fremdeId, $passkeyId),
            'Ein fremdes Konto darf diesen Passkey nicht entziehen können.'
        );
        $this->assertSame(1, Passkeys::anzahl($this->userId), 'Und er muss noch da sein.');

        $this->assertTrue(Passkeys::entziehen($this->userId, $passkeyId));
        $this->assertSame(0, Passkeys::anzahl($this->userId));
    }

    /** Wird ein Konto gelöscht, gehen seine Passkeys mit (ON DELETE CASCADE). */
    public function testPasskeysVerschwindenMitDemKonto(): void {
        $this->legePasskeyAn($this->userId, 'IT-TEST kaskade');
        $this->assertSame(1, Passkeys::anzahl($this->userId));

        $this->db->prepare("DELETE FROM users WHERE id = ?")->execute([$this->userId]);
        $verwaist = (int)$this->db->query(
            "SELECT COUNT(*) FROM user_passkeys WHERE label = 'IT-TEST kaskade'"
        )->fetchColumn();
        $this->userId = 0;

        $this->assertSame(0, $verwaist);
    }

    /** Dieselbe Credential-ID darf es kein zweites Mal geben. */
    public function testCredentialIdIstEindeutig(): void {
        $id = base64_encode('IT-TEST-eindeutig-' . bin2hex(random_bytes(8)));
        $this->legePasskeyAn($this->userId, 'IT-TEST erster', $id);

        $this->expectException(\PDOException::class);
        $this->legePasskeyAn($this->userId, 'IT-TEST zweiter', $id);
    }

    // ---- Zweiter Faktor --------------------------------------------------

    /**
     * Ein Passkey zählt als zweiter Faktor - und zwar als der stärkste.
     *
     * Die Reihenfolge in SecondFactors::ALL bestimmt, welchen Weg die
     * Anmeldung von sich aus anbietet. Der Passkey steht vorn, weil er als
     * einziger an die Domain gebunden ist.
     */
    public function testPasskeyZaehltAlsZweiterFaktorUndStehtVorn(): void {
        $this->assertSame([], SecondFactors::forUser($this->userId));

        $this->legePasskeyAn($this->userId, 'IT-TEST faktor');

        $faktoren = SecondFactors::forUser($this->userId);
        $this->assertContains(SecondFactors::PASSKEY, $faktoren);
        $this->assertSame(SecondFactors::PASSKEY, $faktoren[0]);
        $this->assertTrue(SecondFactors::any($this->userId));
    }

    /**
     * Und die Mengenabfrage muss dasselbe sagen wie die Einzelabfrage.
     *
     * `sqlHasAnyFactor()` speist die Fristenlogik aus #358. Liefen die beiden
     * auseinander, würde ein Konto mit Passkey als ungeschützt gezählt und
     * bekäme Mahnungen oder würde stillgelegt.
     */
    public function testMengenabfrageUndEinzelabfrageStimmenUeberein(): void {
        $this->legePasskeyAn($this->userId, 'IT-TEST sql');

        $sql = SecondFactors::sqlHasAnyFactor('u');
        $stmt = $this->db->prepare("SELECT {$sql} FROM users u WHERE u.id = ?");
        $stmt->execute([$this->userId]);

        $this->assertSame(
            SecondFactors::any($this->userId),
            (bool)$stmt->fetchColumn(),
            'sqlHasAnyFactor() muss dasselbe sagen wie forUser() - sonst zaehlt die '
            . 'Fristenlogik ein geschuetztes Konto als ungeschuetzt.'
        );
    }

    /**
     * DER TEST, DER GEFEHLT HAT.
     *
     * Der Anmeldeweg ruft `SecondFactors::fromRow()` mit der ROHEN
     * users-Zeile aus `findeKontoFuerAnmeldung()` auf — nicht `forUser()`.
     * Diese Zeile enthält kein `passkey_count`.
     *
     * Die erste Fassung las den Passkey ausschließlich aus diesem Feld. Für
     * ein Konto, dessen einziger zweiter Faktor ein Passkey ist, kam damit
     * eine leere Faktorliste heraus: kein zweiter Faktor, direkt angemeldet.
     * Wer das Passwort hatte, war drin.
     *
     * Mein Integrationstest prüfte `forUser()` und war grün. Die Anmeldung
     * ruft `fromRow()`. Beide grün, die Lücke dazwischen — dasselbe Muster
     * wie bei #344.
     *
     * Dieser Test bildet deshalb die Abfrage des Anmeldewegs Spalte für
     * Spalte nach, statt eine bequeme zu nehmen.
     */
    public function testDerAnmeldewegSiehtDenPasskey(): void {
        $this->legePasskeyAn($this->userId, 'IT-TEST anmeldeweg');

        // Exakt die Spalten aus AuthController::findeKontoFuerAnmeldung().
        $stmt = $this->db->prepare(
            "SELECT id, username, email, password_hash, totp_enabled, email_2fa_enabled,
                    email_verification_token
               FROM users WHERE id = ?"
        );
        $stmt->execute([$this->userId]);
        $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($zeile);
        $this->assertArrayNotHasKey(
            'passkey_count',
            $zeile,
            'Wenn die Anmeldeabfrage das Feld eines Tages doch mitbringt, ist dieser '
            . 'Test wertlos geworden und muss neu gedacht werden.'
        );

        $faktoren = SecondFactors::fromRow($zeile);

        $this->assertContains(
            SecondFactors::PASSKEY,
            $faktoren,
            'Ohne diesen Eintrag meldet sich ein Passkey-only-Konto mit dem Passwort allein an.'
        );
    }

    /**
     * Und die Gegenrichtung: Ohne Passkey darf nichts erfunden werden - sonst
     * verlangte die Anmeldung einen Faktor, den das Konto nicht hat.
     */
    public function testOhnePasskeyMeldetDerAnmeldewegKeinen(): void {
        $stmt = $this->db->prepare("SELECT id, username, totp_enabled, email_2fa_enabled FROM users WHERE id = ?");
        $stmt->execute([$this->userId]);
        $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotContains(SecondFactors::PASSKEY, SecondFactors::fromRow($zeile));
    }

    /**
     * Fehlt beides - weder `passkey_count` noch `id` -, ist das ein
     * Programmierfehler. Er endet in einer Ausnahme und ausdrücklich nicht in
     * einem "dann eben keine Faktoren": Ein Fehlerbild ist harmlos, eine
     * stillschweigend übersprungene Anmeldeprüfung nicht.
     */
    public function testOhneIdUndOhneZaehlerWirdGeworfen(): void {
        $this->expectException(\RuntimeException::class);
        SecondFactors::fromRow(['username' => 'ohne-alles', 'totp_enabled' => 0]);
    }

    // ---- Hilfen ----------------------------------------------------------

    private function legePasskeyAn(int $userId, string $label, ?string $credentialId = null): int {
        $stmt = $this->db->prepare(
            "INSERT INTO user_passkeys (user_id, credential_id, credential, label, sign_count, created_at)
             VALUES (?, ?, ?, ?, 0, NOW())"
        );
        $stmt->execute([
            $userId,
            $credentialId ?? base64_encode('IT-TEST-' . bin2hex(random_bytes(12))),
            '{"test":true}',
            $label,
        ]);
        return (int)$this->db->lastInsertId();
    }

    private function setzeBaseUrl(string $url): void {
        $stmt = $this->db->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES ('base_url', ?)
             ON DUPLICATE KEY UPDATE setting_value = ?"
        );
        $stmt->execute([$url, $url]);
    }
}
