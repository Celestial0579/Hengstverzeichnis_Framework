<?php
// tests/Functional/PasswordResetKetteTest.php

namespace Tests\Functional;

use App\Controllers\AuthController;
use App\Database;
use Tests\Support\AuxiliaryServer;
use Tests\Support\FakeSmtpServer;
use Tests\Support\HttpClient;

/**
 * Die Passwort-Reset-Kette von der Anforderung bis zum neuen Passwort (#510).
 *
 * Getestet war davon bisher nur das Einlösen: RegistrationTest und
 * AdressantragIncidentTest legen `hash('sha256', $token)` selbst in
 * `password_resets` und rufen den Link auf. Das AUSSTELLEN umgehen sie damit -
 * schriebe `sendResetLink()` wieder das Token statt des Abdrucks in die
 * Tabelle (#282), bliebe die Suite grün. Ebenso ungeprüft waren ein falsches
 * und ein abgelaufenes Token, und dass der Reset per Link die bestehende
 * Sitzung und die API-Schlüssel des Kontos beendet (#113, #217).
 *
 * WOHER DAS TOKEN KOMMT. Es existiert im Klartext nur in der Mail. Deshalb
 * geht die Mail hier wirklich hinaus - an den Fake-SMTP-Server der Suite - und
 * der Test nimmt den Link aus ihr, wie ein Benutzer es täte.
 *
 * WARUM EINE ZWEITE INSTANZ. Der Mailer prüft das Zertifikat der Gegenstelle
 * vollständig. FakeSmtpServer hinterlegt seine Zertifizierungsstelle in
 * SSL_CERT_FILE; der geteilte Testserver läuft aber schon und hat seine
 * Umgebung beim Start geerbt. Die zweite Instanz startet danach und kennt die
 * Variable - gleicher Code, gleiche Datenbank.
 */
class PasswordResetKetteTest extends FunctionalTestCase {

    use ApiKeyHelper;

    private const APP_PORT = 8774;

    private const MAIL_SCHLUESSEL = [
        'mail_driver', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user',
        'smtp_pass', 'mail_from_email', 'mail_from_name', 'admin_notification_email',
    ];

    /** Passwort eines Kontos aus createAndLoginEditor() nach dem Pflichtwechsel. */
    private const ALTES_PASSWORT = 'EditorTestNeu456!';

    private static ?AuxiliaryServer $app = null;

    /** @var array<string, string>|null */
    private ?array $mailVorher = null;
    private ?string $stammUrlVorher = null;
    private bool $stammUrlGesetzt = false;

    /** @var list<string> */
    private array $adressen = [];

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        // Die Reihenfolge zählt: erst der SMTP-Server (setzt SSL_CERT_FILE),
        // dann die Instanz, die die Variable erben soll.
        FakeSmtpServer::ensureStarted();
        self::$app = new AuxiliaryServer(self::APP_PORT, __DIR__ . '/../../public');
        self::$app->start();
    }

    public static function tearDownAfterClass(): void {
        self::$app?->stop();
        self::$app = null;
        // Stellt SSL_CERT_FILE wieder her - spätere Testklassen starten ihre
        // Hilfsserver sonst mit einem Vertrauensspeicher, der nur die
        // Wegwerf-CA kennt.
        FakeSmtpServer::stop();
        parent::tearDownAfterClass();
    }

    protected function setUp(): void {
        $admin = $this->authenticatedClient();
        $db = Database::getInstance();

        $this->mailVorher = [];
        $stmt = $db->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        foreach (self::MAIL_SCHLUESSEL as $schluessel) {
            $stmt->execute([$schluessel]);
            $wert = $stmt->fetchColumn();
            if ($wert !== false) {
                $this->mailVorher[$schluessel] = (string)$wert;
            }
        }

        // Token-Mails gehen nur mit fester Stamm-URL hinaus (Audit M6).
        $this->stammUrlVorher = self::stammUrlSetzen(self::TEST_STAMM_URL);
        $this->stammUrlGesetzt = true;

        // Über die Verwaltung und nicht direkt in die Datenbank: Die App
        // verschlüsselt das SMTP-Passwort mit IHREM Schlüssel, und der ist in
        // der CI ein anderer als der dieses Prozesses.
        $seite = $admin->get('/admin/mail-settings');
        $gespeichert = $admin->post('/admin/mail-settings', [
            'csrf_token' => $seite->formField('csrf_token') ?? '',
            'mail_driver' => 'smtp',
            'smtp_host' => FakeSmtpServer::host(),
            'smtp_port' => (string)FakeSmtpServer::port(),
            'smtp_encryption' => 'tls',
            'smtp_user' => 'absender@example.com',
            'smtp_pass' => 'geheim',
            'mail_from_email' => 'absender@example.com',
            'mail_from_name' => '',
            'admin_notification_email' => '',
        ]);
        $this->assertStringStartsWith('/admin/mail-settings', (string)$gespeichert->location(), "Body: {$gespeichert->body}");
        $this->assertStringNotContainsString('error', (string)$gespeichert->location());

        FakeSmtpServer::clear();
        $db->exec("DELETE FROM login_attempts WHERE type = 'password_reset'");
    }

    protected function tearDown(): void {
        $db = Database::getInstance();

        if ($this->mailVorher !== null) {
            foreach (self::MAIL_SCHLUESSEL as $schluessel) {
                if (array_key_exists($schluessel, $this->mailVorher)) {
                    $db->prepare(
                        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
                    )->execute([$schluessel, $this->mailVorher[$schluessel]]);
                } else {
                    $db->prepare('DELETE FROM settings WHERE setting_key = ?')->execute([$schluessel]);
                }
            }
            $this->mailVorher = null;
        }
        if ($this->stammUrlGesetzt) {
            self::stammUrlSetzen($this->stammUrlVorher);
            $this->stammUrlGesetzt = false;
        }

        $db->exec("DELETE FROM login_attempts WHERE type = 'password_reset'");
        foreach ($this->adressen as $adresse) {
            $db->prepare('DELETE FROM login_attempts WHERE identifier = ? AND type = ?')
                ->execute([AuthController::resetEmpfaengerKey($adresse), AuthController::RESET_EMPFAENGER_TYP]);
            $db->prepare('DELETE FROM password_resets WHERE email = ?')->execute([$adresse]);
        }
        $this->adressen = [];

        parent::tearDown();
    }

    /**
     * Ein Redakteur mit eigener Sitzung (über den geteilten Server).
     *
     * @return array{client: HttpClient, email: string, username: string}
     */
    private function konto(string $kennung): array {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $username = "{$kennung}{$unique}";
        $email = "{$kennung}-{$unique}@example.com";
        $this->adressen[] = $email;

        // Editor-Gruppe: Ohne ein eigenes Recht bietet /api-keys kein
        // Anlege-Formular an (siehe ApiKeyPasswordRevocationTest).
        $client = $this->createAndLoginEditor($admin, $username, $email, [$this->findBuiltinGroupId($admin, 'Editor')]);

        return ['client' => $client, 'email' => $email, 'username' => $username];
    }

    /** Ein Client ohne Sitzung gegen die Instanz, die Mail versenden kann. */
    private function gast(): HttpClient {
        return new HttpClient((string)self::$app?->baseUrl());
    }

    /** Fordert über die echte Route einen Reset an. */
    private function resetAnfordern(string $email): void {
        $gast = $this->gast();
        $antwort = $gast->post('/forgot-password', [
            'csrf_token' => $gast->get('/forgot-password')->formField('csrf_token') ?? '',
            'email' => $email,
        ]);
        $this->assertSame('/forgot-password?sent=1', $antwort->location(), "Body: {$antwort->body}");
    }

    /**
     * Das Token aus der zuletzt eingegangenen Reset-Mail.
     *
     * Die Teile der Mail sind Base64 in 76er-Zeilen; 76 ist durch vier
     * teilbar, die Zeilen lassen sich also einzeln zurückwandeln (dasselbe
     * Verfahren wie in tests/Integration/MailerTokenLinkTest.php).
     */
    private function tokenAusMail(int $erwarteteMails = 1): string {
        $this->assertTrue(
            FakeSmtpServer::waitForMessages($erwarteteMails),
            'Die Reset-Mail ist beim SMTP-Server nicht angekommen.'
        );
        $nachrichten = FakeSmtpServer::messages();
        $roh = (string)end($nachrichten);

        $text = '';
        foreach (preg_split('/\R/', $roh) ?: [] as $zeile) {
            $zeile = trim($zeile);
            if ($zeile !== '' && preg_match('#^[A-Za-z0-9+/=]+$#', $zeile) === 1) {
                $text .= (string)base64_decode($zeile, true);
            }
        }

        $this->assertSame(
            1,
            preg_match('#' . preg_quote(self::TEST_STAMM_URL, '#') . 'reset-password\?token=([0-9a-f]{64})#', $text, $treffer),
            "Kein Reset-Link in der Mail:\n" . $text
        );

        return $treffer[1];
    }

    /** @return array{token: string, expires_at: string}|null */
    private function resetZeile(string $email): ?array {
        $stmt = Database::getInstance()->prepare('SELECT token, expires_at FROM password_resets WHERE email = ?');
        $stmt->execute([$email]);
        $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($zeile) ? $zeile : null;
    }

    private function passwortHash(string $email): string {
        $stmt = Database::getInstance()->prepare('SELECT password_hash FROM users WHERE email = ?');
        $stmt->execute([$email]);

        return (string)$stmt->fetchColumn();
    }

    /** Sendet das Formular des Reset-Dialogs ab. */
    private function neuesPasswortSetzen(HttpClient $client, string $token, string $passwort): \Tests\Support\HttpResponse {
        return $client->post('/reset-password', [
            'csrf_token' => $client->get('/forgot-password')->formField('csrf_token') ?? '',
            'token' => $token,
            'password' => $passwort,
            'password_confirm' => $passwort,
        ]);
    }

    /**
     * Ausgestellt wird der Abdruck, nicht das Token (#282) - geprüft am
     * echten Weg: Das Token steht in der Mail, in der Tabelle sein SHA-256.
     */
    public function testInDerTabelleStehtNurDerAbdruckDesVerschicktenTokens(): void {
        $konto = $this->konto('resetabdruck');

        $this->resetAnfordern($konto['email']);
        $token = $this->tokenAusMail();

        $zeile = $this->resetZeile($konto['email']);
        $this->assertNotNull($zeile, 'Zum angeforderten Reset gehört eine Zeile in password_resets.');
        $this->assertSame(hash('sha256', $token), $zeile['token'], 'Gespeichert ist der SHA-256-Abdruck des verschickten Tokens.');
        $this->assertNotSame($token, $zeile['token'], 'Das Token selbst steht nicht in der Tabelle.');

        // Gültig für rund 15 Minuten, nicht länger.
        $rest = strtotime($zeile['expires_at']) - time();
        $this->assertGreaterThan(13 * 60, $rest);
        $this->assertLessThanOrEqual(15 * 60 + 5, $rest);

        // Und der Link aus der Mail öffnet den Dialog.
        $dialog = $this->gast()->get('/reset-password?token=' . $token);
        $this->assertSame(200, $dialog->statusCode);
        $this->assertSame($token, $dialog->formField('token'));
    }

    /**
     * Ein Token, das nicht ausgestellt wurde - auch eines, das nur an einer
     * Stelle abweicht, und der Abdruck selbst -, öffnet weder den Dialog noch
     * setzt es ein Passwort.
     */
    public function testEinFalschesTokenWirdAbgewiesen(): void {
        $konto = $this->konto('resetfalsch');
        $this->resetAnfordern($konto['email']);
        $token = $this->tokenAusMail();
        $vorher = $this->passwortHash($konto['email']);

        $veraendert = substr($token, 0, -1) . ($token[63] === '0' ? '1' : '0');
        $falsche = [
            'frei erfunden' => bin2hex(random_bytes(32)),
            'ein Zeichen verändert' => $veraendert,
            // Wer die Tabelle lesen kann, kennt den Abdruck - er darf nicht
            // selbst als Token gelten.
            'der Abdruck aus der Tabelle' => hash('sha256', $token),
        ];

        foreach ($falsche as $fall => $falsch) {
            $gast = $this->gast();
            $dialog = $gast->get('/reset-password?token=' . $falsch);
            $this->assertStringContainsString('ungültig oder abgelaufen', $dialog->body, "Dialog ({$fall})");
            $this->assertNull($dialog->formField('token'), "Kein Passwortformular ({$fall})");

            $antwort = $this->neuesPasswortSetzen($gast, $falsch, 'Eingeschlichen789!');
            $this->assertSame('/forgot-password', $antwort->location(), "Absenden ({$fall})");
        }

        $this->assertSame($vorher, $this->passwortHash($konto['email']), 'Das Passwort bleibt unverändert.');
        $this->assertNotNull($this->resetZeile($konto['email']), 'Ein Fehlversuch verbraucht das echte Token nicht.');
    }

    /** Nach Ablauf der Frist gilt auch das richtige Token nicht mehr. */
    public function testEinAbgelaufenesTokenWirdAbgewiesen(): void {
        $konto = $this->konto('resetabgelaufen');
        $this->resetAnfordern($konto['email']);
        $token = $this->tokenAusMail();
        $vorher = $this->passwortHash($konto['email']);

        Database::getInstance()
            ->prepare('UPDATE password_resets SET expires_at = NOW() - INTERVAL 1 SECOND WHERE email = ?')
            ->execute([$konto['email']]);

        $gast = $this->gast();
        $dialog = $gast->get('/reset-password?token=' . $token);
        $this->assertStringContainsString('ungültig oder abgelaufen', $dialog->body);
        $this->assertNull($dialog->formField('token'));

        $antwort = $this->neuesPasswortSetzen($gast, $token, 'ZuSpaet789!');
        $this->assertSame('/forgot-password', $antwort->location());
        $this->assertSame($vorher, $this->passwortHash($konto['email']), 'Das Passwort bleibt unverändert.');
    }

    /**
     * Der Reset ist die typische Reaktion auf einen Kompromittierungsverdacht:
     * Danach darf nichts mehr gelten, was vorher galt - weder die offene
     * Sitzung (#113) noch ein API-Schlüssel (#217), noch der Link selbst.
     */
    public function testDerResetBeendetSitzungSchluesselUndLink(): void {
        $konto = $this->konto('resetkette');
        $sitzung = $konto['client'];
        $schluessel = $this->createApiKey($sitzung, 'Vor dem Reset ' . uniqid());
        $api = $this->newClient();

        // Ausgangslage: Sitzung und Schlüssel gelten.
        $this->assertSame(200, $sitzung->get('/admin')->statusCode);
        $this->assertSame(200, $api->get('/api/horses?per_page=10', $this->bearer($schluessel))->statusCode);

        $this->resetAnfordern($konto['email']);
        $token = $this->tokenAusMail();

        $gast = $this->gast();
        $dialog = $gast->get('/reset-password?token=' . $token);
        $neuesPasswort = 'NachDemReset789!';
        $reset = $gast->post('/reset-password', [
            'csrf_token' => $dialog->formField('csrf_token') ?? '',
            'token' => $token,
            'password' => $neuesPasswort,
            'password_confirm' => $neuesPasswort,
        ]);
        $this->assertSame('/login?success=password_reset', $reset->location(), "Body: {$reset->body}");

        // 1. Die alte Sitzung ist beendet.
        $this->assertStringStartsWith(
            '/login',
            (string)$sitzung->get('/admin')->location(),
            'Eine vor dem Reset bestehende Sitzung darf danach nicht mehr gelten.'
        );

        // 2. Der alte API-Schlüssel ist widerrufen - ausdrücklich, nicht nur
        //    über die session_version abgelehnt.
        $this->assertSame(401, $api->get('/api/horses?per_page=10', $this->bearer($schluessel))->statusCode);
        $stmt = Database::getInstance()->prepare(
            'SELECT COUNT(*) FROM api_keys k JOIN users u ON u.id = k.user_id WHERE u.email = ? AND k.revoked_at IS NULL'
        );
        $stmt->execute([$konto['email']]);
        $this->assertSame(0, (int)$stmt->fetchColumn(), 'Kein Schlüssel des Kontos ist mehr aktiv.');

        // 3. Der Link ist verbraucht: Die Zeile ist weg, ein zweiter Versuch
        //    mit demselben Token scheitert.
        $this->assertNull($this->resetZeile($konto['email']));
        $nochmal = $this->gast();
        $this->assertStringContainsString('ungültig oder abgelaufen', $nochmal->get('/reset-password?token=' . $token)->body);
        $this->assertSame('/forgot-password', $this->neuesPasswortSetzen($nochmal, $token, 'ZweiterVersuch789!')->location());

        // 4. Es gilt das neue Passwort und nur das neue. Der zweite Faktor
        //    bleibt Pflicht - der Reset meldet niemanden an.
        $this->assertTrue(password_verify($neuesPasswort, $this->passwortHash($konto['email'])));
        $anmeldung = $this->newClient();
        $falsch = $anmeldung->post('/login', [
            'csrf_token' => $anmeldung->get('/login')->formField('csrf_token') ?? '',
            'kennung' => $konto['email'],
            'password' => self::ALTES_PASSWORT,
        ]);
        $this->assertNotSame('/login/2fa', $falsch->location(), 'Das alte Passwort gilt nicht mehr.');
        $richtig = $anmeldung->post('/login', [
            'csrf_token' => $anmeldung->get('/login')->formField('csrf_token') ?? '',
            'kennung' => $konto['email'],
            'password' => $neuesPasswort,
        ]);
        $this->assertSame('/login/2fa', $richtig->location(), "Body: {$richtig->body}");
    }

    /**
     * Für eine unbekannte Adresse sieht die Antwort genauso aus - aber es
     * entsteht weder eine Zeile noch eine Mail. Die Route ist kein Orakel
     * dafür, welche Adressen ein Konto haben.
     */
    public function testEineUnbekannteAdresseBekommtDieselbeAntwortAberNichts(): void {
        $unbekannt = 'niemand-' . uniqid() . '@example.com';
        $this->adressen[] = $unbekannt;

        $this->resetAnfordern($unbekannt);

        $this->assertNull($this->resetZeile($unbekannt));
        // Der Versand liefe synchron in der Anfrage - nach der Antwort ist
        // entschieden, ob eine Mail hinausging.
        usleep(300_000);
        $this->assertSame([], FakeSmtpServer::messages(), 'An eine unbekannte Adresse geht keine Mail.');
    }
}
