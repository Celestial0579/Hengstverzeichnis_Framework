<?php
// tests/Functional/PasskeyAdminTest.php

namespace Tests\Functional;

/**
 * HTTP-Funktionstests für Passkeys (#353).
 *
 * Die Zeremonie selbst braucht einen Authenticator und lässt sich nicht
 * nachstellen - siehe tests/Integration/PasskeysTest.php. Was sich sehr wohl
 * prüfen lässt, und was sicherheitlich zählt, sind die **Schranken davor**:
 * Wer darf die Endpunkte überhaupt aufrufen, greift der CSRF-Schutz, und
 * führt der Anmeldeweg an der Passwortprüfung vorbei?
 *
 * Genau dort liegen die Fehler, die man ohne solche Tests nicht findet. Ein
 * Unit-Test auf die Zeremonie beweist nicht, dass sie hinter einer Anmeldung
 * steht.
 */
class PasskeyAdminTest extends FunctionalTestCase {

    /**
     * Die Instanz einmal einrichten lassen, bevor irgendein Gast-Test laeuft.
     *
     * Ohne das leitet /login auf den Einrichtungsassistenten um, und
     * csrfTokenFrom() bricht ab - je nachdem, welcher Test zufaellig zuerst
     * an der Reihe ist. Ein Test, dessen Ergebnis an der Reihenfolge haengt,
     * ist kein Test.
     */
    protected function setUp(): void {
        $this->authenticatedClient();
    }

    // ---- Registrierung: nur angemeldet ----------------------------------

    /**
     * Ein Passkey ist ein Anmeldemittel. Ihn ohne bestehende Anmeldung
     * anzulegen hiesse, dass sich jeder einen Zweitschlüssel für ein fremdes
     * Konto ausstellen lassen könnte.
     */
    public function testRegistrierungBrauchtEineAnmeldung(): void {
        $gast = $this->newClient();

        // MIT gueltigem Token - sonst prueft dieser Test nur den CSRF-Schutz.
        //
        // Genau das war die erste Fassung: Sie schickte gar kein Token, der
        // CSRF-Schutz griff zuerst, und die Gegenprobe (checkAuth() entfernt)
        // blieb gruen. Ein Test, der die Huerde prueft, die er gar nicht
        // meint, sichert nichts - er beruhigt nur.
        $token = $this->csrfTokenFrom($gast, '/login');

        // Die Antwort muss die UMLEITUNG von checkAuth() sein, nicht
        // irgendein Fehlschlag. Der Unterschied ist nicht Kosmetik: Ohne
        // checkAuth() liefe der Endpunkt in einen Absturz, weil
        // $_SESSION['user_id'] fehlt - und ein Absturz ist keine Schranke.
        // Er verschwindet in dem Moment, in dem jemand einen Standardwert
        // einsetzt, und dann steht die Tuer offen.
        //
        // Diese Fassung wurde eingefuehrt, weil die Gegenprobe (checkAuth()
        // entfernt) zweimal gruen blieb: erst weil der CSRF-Schutz zuerst
        // griff, dann weil der Absturz wie eine Abweisung aussah.
        $optionen = $gast->post('/passkeys/optionen', ['csrf_token' => $token]);
        $this->assertSame(
            302,
            $optionen->statusCode,
            'Ohne Anmeldung muss checkAuth() zur Anmeldeseite umleiten.'
        );
        $this->assertSame('/login', $optionen->location());
        $this->assertStringNotContainsString('challenge', $optionen->body);

        $abschluss = $gast->post('/passkeys/registrieren', [
            'csrf_token' => $token,
            'antwort' => '{}',
        ]);
        $this->assertSame('/login', $abschluss->location());

        $entziehen = $gast->post('/passkeys/entziehen', [
            'csrf_token' => $token,
            'id' => '1',
        ]);
        $this->assertSame('/login', $entziehen->location());
    }

    public function testRegistrierungBrauchtEinCsrfToken(): void {
        $admin = $this->authenticatedClient();

        $this->assertSame(403, $admin->post('/passkeys/optionen', [])->statusCode);
        $this->assertSame(403, $admin->post('/passkeys/registrieren', ['antwort' => '{}'])->statusCode);
        $this->assertSame(403, $admin->post('/passkeys/entziehen', ['id' => '1'])->statusCode);
    }

    /**
     * Über localhost IST der Kontext sicher - und das ist keine Nachlässigkeit,
     * sondern die Regel des Browsers: Ohne diese Ausnahme ließe sich WebAuthn
     * nicht entwickeln, weil es dafür ein Zertifikat für 127.0.0.1 bräuchte.
     *
     * Die Testinstanz läuft genau so, deshalb muss der Knopf hier DA sein.
     * Der Test prüft damit die Ausnahme selbst - und stünde sie falsch herum,
     * bekäme jeder Betreiber über eine ungesicherte Verbindung einen Knopf,
     * der ins Leere führt.
     */
    public function testUeberLocalhostIstDerKontextSicherUndDerKnopfDa(): void {
        $admin = $this->authenticatedClient();

        $profil = $admin->get('/profil');

        $this->assertSame(200, $profil->statusCode);
        $this->assertStringContainsString('data-passkey-registrieren', $profil->body);
        $this->assertStringNotContainsString(
            'Über diese Verbindung',
            $profil->body,
            'Der Hinweis auf die ungesicherte Verbindung gehoert hier NICHT hin.'
        );
    }

    /**
     * Und die Gegenprobe zur Ausnahme: Ein fremder Host ohne TLS ist nicht
     * sicher. Nachgestellt über den Host-Kopf - genau den wertet die
     * Erkennung aus.
     */
    public function testEinFremderHostOhneTlsGiltNichtAlsSicher(): void {
        $admin = $this->authenticatedClient();

        $antwort = $admin->post('/passkeys/optionen', [
            'csrf_token' => $this->currentCsrfToken($admin),
        ], ['Host' => 'verband.example']);

        // Geprueft wird die EIGENSCHAFT, nicht der Zahlenwert: Es darf keine
        // Zeremonie beginnen. Welche Schranke zuerst greift, ist dabei nicht
        // festgelegt - tatsaechlich weist App\Security\TrustedHost den fremden
        // Namen schon ab, bevor die Passkey-Pruefung ueberhaupt drankommt, und
        // antwortet mit einer Umleitung statt mit 400. Das ist die schaerfere
        // Antwort; sie hier auf 400 festzunageln hiesse, den staerkeren Schutz
        // zum Fehlschlag zu erklaeren.
        $this->assertNotSame(
            200,
            $antwort->statusCode,
            'Ohne HTTPS und ohne localhost darf keine Zeremonie beginnen.'
        );
        $this->assertStringNotContainsString(
            'challenge',
            $antwort->body,
            'Und schon gar keine Challenge herausgeben.'
        );
    }

    // ---- Der Abschnitt im Profil -----------------------------------------

    /**
     * Der Abschnitt muss erklären, WARUM ein Passkey stärker ist. „Stärker"
     * ohne Begründung ist eine Behauptung; der Phishing-Schutz ist der Grund,
     * und er ist der einzige echte Unterschied zu einem Code.
     */
    public function testDasProfilBenenntDenUnterschiedZumCode(): void {
        $admin = $this->authenticatedClient();

        $body = $admin->get('/profil')->body;

        $this->assertStringContainsString('Passkeys', $body);
        $this->assertStringContainsString('Phishing', $body);
        $this->assertStringContainsString('nachgebauten Seite', $body);
    }

    // ---- Anmeldeweg: nicht an der Passwortprüfung vorbei ------------------

    /**
     * DER wichtigste Test hier. Die Passkey-Seite steht MITTEN im
     * Anmeldeweg - nach dem Passwort, vor der fertigen Sitzung. Wäre sie ohne
     * den ersten Faktor erreichbar, ginge sie an der Passwortprüfung vorbei.
     */
    public function testDieAnmeldeseiteIstOhneErstenFaktorNichtErreichbar(): void {
        $gast = $this->newClient();

        $seite = $gast->get('/login/passkey');

        $this->assertSame('/login', $seite->location());
    }

    /** Dasselbe für die beiden Endpunkte dahinter. */
    public function testDieAnmeldeEndpunkteSindOhneErstenFaktorVerschlossen(): void {
        $gast = $this->newClient();

        // Ein gueltiges Token von der Anmeldeseite - der Gast kommt an kein
        // anderes. Damit prueft dieser Test wirklich die Anmelde-Schranke und
        // nicht bloss den CSRF-Schutz; der hat seinen eigenen Test.
        $token = $this->csrfTokenFrom($gast, '/login');

        $optionen = $gast->post('/login/passkey/optionen', ['csrf_token' => $token]);
        $this->assertSame(403, $optionen->statusCode);

        $pruefen = $gast->post('/login/passkey/pruefen', [
            'csrf_token' => $token,
            'antwort' => '{}',
        ]);
        $this->assertSame(403, $pruefen->statusCode);
    }

    /**
     * Und der Abschluss erst recht: Er macht aus einer halben Anmeldung eine
     * ganze. Ohne beide Marken in der Sitzung führt er zurück zum Login.
     *
     * EHRLICHER HINWEIS ZUR REICHWEITE. Hier stehen ZWEI Prüfungen
     * hintereinander - eine in PasskeyController::anmeldungAbschliessen(),
     * eine in AuthController::passkeyAbschluss(). Das ist Absicht (die Folge
     * eines Fehlers waere eine Anmeldung als fremde Person), hat aber eine
     * Konsequenz für diesen Test: Wird EINE der beiden entfernt, bleibt er
     * grün, weil die andere übernimmt. Nachgeprüft, nicht vermutet.
     *
     * Von außen ist das nicht feiner aufzulösen - ein Funktionstest kann den
     * Sitzungszustand nicht so setzen, dass nur die eine greift. Der Test
     * sichert also das PAAR, nicht die einzelne Prüfung. Wer eine davon
     * entfernt, muss wissen, dass die Suite es nicht merkt.
     */
    public function testDerAbschlussOhneBestandeneZeremonieFuehrtZumLogin(): void {
        $gast = $this->newClient();

        $this->assertSame('/login', $gast->get('/login/passkey/fertig')->location());
    }

    public function testAnmeldeEndpunkteBrauchenEinCsrfToken(): void {
        $gast = $this->newClient();

        $this->assertSame(403, $gast->post('/login/passkey/optionen', [])->statusCode);
        $this->assertSame(403, $gast->post('/login/passkey/pruefen', ['antwort' => '{}'])->statusCode);
    }

    // ---- Das Skript ------------------------------------------------------

    /** Ohne ausgeliefertes Skript gibt es keine Zeremonie. */
    public function testDasSkriptWirdAusgeliefert(): void {
        $antwort = $this->newClient()->get('/js/passkeys.js');

        $this->assertSame(200, $antwort->statusCode);
        $this->assertStringContainsString('navigator.credentials', $antwort->body);
    }
}
