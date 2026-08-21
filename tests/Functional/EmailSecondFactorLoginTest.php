<?php
// tests/Functional/EmailSecondFactorLoginTest.php

namespace Tests\Functional;

use App\Security\EmailSecondFactor;

/**
 * Zweiter Faktor per E-Mail (#354) - der vollstaendige Weg ueber HTTP:
 * einschalten im Profil, abmelden, mit dem Code wieder herein.
 *
 * WIE DER TEST AN DEN CODE KOMMT. Gar nicht - und das ist Absicht.
 * Gespeichert wird nur der Abdruck, im Testlauf geht keine Mail hinaus. Der
 * Test loest die Ausstellung ueber die Anwendung aus (damit ist bewiesen,
 * dass sie stattfindet) und setzt danach den Abdruck einer BEKANNTEN Ziffern-
 * folge in dieselbe Zeile. Geprueft wird also der echte Weg vom Formular bis
 * zur Session; nur das Postfach wird ersetzt.
 */
class EmailSecondFactorLoginTest extends FunctionalTestCase {

    private const TESTCODE = '424242';

    /** @var array<int, string> */
    private array $aufraeumen = [];

    protected function tearDown(): void {
        if ($this->aufraeumen !== []) {
            $db = \App\Database::getInstance();
            $stmt = $db->prepare("DELETE FROM users WHERE username = ?");
            foreach ($this->aufraeumen as $name) {
                $stmt->execute([$name]);
            }
            $this->aufraeumen = [];
        }
        parent::tearDown();
    }

    /**
     * Setzt den Abdruck eines bekannten Codes in die bereits von der
     * Anwendung angelegte Zeile. Bricht ab, wenn es sie nicht gibt - dann
     * haette der Test sonst still am eigentlichen Punkt vorbeigeprueft.
     */
    private function codeUnterschieben(string $username, string $purpose): void {
        $db = \App\Database::getInstance();
        $stmt = $db->prepare(
            "UPDATE email_2fa_codes c JOIN users u ON u.id = c.user_id
             SET c.code_hash = ?, c.attempts = 0, c.expires_at = NOW() + INTERVAL 10 MINUTE
             WHERE u.username = ? AND c.purpose = ?"
        );
        $stmt->execute([password_hash(self::TESTCODE, PASSWORD_DEFAULT), $username, $purpose]);

        $pruef = $db->prepare(
            "SELECT COUNT(*) FROM email_2fa_codes c JOIN users u ON u.id = c.user_id
             WHERE u.username = ? AND c.purpose = ?"
        );
        $pruef->execute([$username, $purpose]);
        self::assertSame(
            1,
            (int)$pruef->fetchColumn(),
            "Die Anwendung haette einen Code fuer '{$purpose}' ausstellen muessen - es liegt keiner vor."
        );
    }

    /**
     * Legt ein Konto ohne 2FA-Zwang an, meldet es an, erledigt den
     * Passwortwechsel der Erstanmeldung und gibt den angemeldeten Client
     * zurueck.
     *
     * @return array{0: \Tests\Support\HttpClient, 1: string, 2: string, 3: string} client, username, email, passwort
     */
    private function angemeldetesKonto(string $unique): array {
        $admin = $this->authenticatedClient();
        $groupId = $this->createGroupWithoutTwoFa($admin, "Mailcode {$unique}");

        $username = "mailfaktor{$unique}";
        $email = "mailfaktor-{$unique}@example.com";
        $erst = 'MailfaktorTest123!';
        $passwort = 'MailfaktorNeu456!';

        $createForm = $admin->get('/admin/users/create');
        $angelegt = $admin->post('/admin/users/store', [
            'csrf_token' => $createForm->formField('csrf_token') ?? '',
            'username' => $username,
            'email' => $email,
            'password' => $erst,
            'groups' => [(string)$groupId],
        ]);
        $this->assertSame('/admin/users?success=created', $angelegt->location(), "Anlegen fehlgeschlagen, Body: {$angelegt->body}");
        $this->aufraeumen[] = $username;

        $client = $this->newClient();
        $loginPage = $client->get('/login');
        $login = $client->post('/login', [
            'csrf_token' => $loginPage->formField('csrf_token') ?? '',
            'kennung' => $username,
            'password' => $erst,
        ]);
        $this->assertSame('/force-password-change', $login->location(), "Erstanmeldung fehlgeschlagen, Body: {$login->body}");

        $forcePage = $client->get('/force-password-change');
        $gewechselt = $client->post('/force-password-change', [
            'csrf_token' => $forcePage->formField('csrf_token') ?? '',
            'current_password' => $erst,
            'password' => $passwort,
            'password_confirm' => $passwort,
        ]);
        $this->assertSame('/admin?password_changed=1', $gewechselt->location());

        return [$client, $username, $email, $passwort];
    }

    private function mailcodeEinschalten(\Tests\Support\HttpClient $client, string $username, string $passwort): void {
        $profil = $client->get('/profil');
        $client->post('/profil/2fa/email/code', [
            'csrf_token' => $profil->formField('csrf_token') ?? '',
        ]);
        $this->codeUnterschieben($username, EmailSecondFactor::PURPOSE_SETUP);

        $profil = $client->get('/profil');
        $ein = $client->post('/profil/2fa/email/ein', [
            'csrf_token' => $profil->formField('csrf_token') ?? '',
            'current_password' => $passwort,
            'code' => self::TESTCODE,
        ]);
        $this->assertSame('/profil?success=email_factor_on', $ein->location(), "Einschalten fehlgeschlagen, Body: {$ein->body}");
    }

    public function testDerFaktorLaesstSichEinschaltenUndTraegtDieAnmeldung(): void {
        $unique = uniqid();
        [$client, $username, , $passwort] = $this->angemeldetesKonto($unique);

        $this->mailcodeEinschalten($client, $username, $passwort);

        // Backup-Codes gehoeren dazu: Sie sind der Rueckweg, wenn keine Mail
        // ankommt - und der Versand ist der unzuverlaessigste Teil daran.
        $db = \App\Database::getInstance();
        $stmt = $db->prepare("SELECT backup_codes FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $codes = json_decode((string)$stmt->fetchColumn(), true);
        $this->assertIsArray($codes);
        $this->assertCount(10, $codes, 'Beim Einschalten muessen Backup-Codes entstehen.');

        // Neue Sitzung: Das Passwort fuehrt jetzt zur Codeeingabe, nicht ins Ziel.
        $zweiter = $this->newClient();
        $loginPage = $zweiter->get('/login');
        $login = $zweiter->post('/login', [
            'csrf_token' => $loginPage->formField('csrf_token') ?? '',
            'kennung' => $username,
            'password' => $passwort,
        ]);
        $this->assertStringStartsWith('/login/2fa/email', (string)$login->location(), "Body: {$login->body}");

        $this->codeUnterschieben($username, EmailSecondFactor::PURPOSE_LOGIN);

        $codePage = $zweiter->get('/login/2fa/email');
        $fertig = $zweiter->post('/login/2fa/email', [
            'csrf_token' => $codePage->formField('csrf_token') ?? '',
            'code' => self::TESTCODE,
        ]);
        $this->assertSame('/admin', $fertig->location(), "Anmeldung mit Mailcode fehlgeschlagen, Body: {$fertig->body}");
        $this->assertSame(200, $zweiter->get('/admin')->statusCode);
    }

    public function testEinFalscherCodeLaesstNichtHereinUndVerbrauchtDenRichtigen(): void {
        $unique = uniqid();
        [$client, $username, , $passwort] = $this->angemeldetesKonto($unique);
        $this->mailcodeEinschalten($client, $username, $passwort);

        $zweiter = $this->newClient();
        $loginPage = $zweiter->get('/login');
        $zweiter->post('/login', [
            'csrf_token' => $loginPage->formField('csrf_token') ?? '',
            'kennung' => $username,
            'password' => $passwort,
        ]);
        $this->codeUnterschieben($username, EmailSecondFactor::PURPOSE_LOGIN);

        // Fuenf Fehlversuche verbrauchen den Code (EmailSecondFactor::MAX_ATTEMPTS).
        for ($i = 0; $i < EmailSecondFactor::MAX_ATTEMPTS; $i++) {
            $codePage = $zweiter->get('/login/2fa/email');
            $falsch = $zweiter->post('/login/2fa/email', [
                'csrf_token' => $codePage->formField('csrf_token') ?? '',
                'code' => '000001',
            ]);
            $this->assertNull($falsch->location(), 'Ein falscher Code darf nicht hereinlassen.');
        }

        $codePage = $zweiter->get('/login/2fa/email');
        $mitRichtigem = $zweiter->post('/login/2fa/email', [
            'csrf_token' => $codePage->formField('csrf_token') ?? '',
            'code' => self::TESTCODE,
        ]);
        $this->assertNull(
            $mitRichtigem->location(),
            'Nach den Fehlversuchen ist auch der richtige Code verbraucht.'
        );
    }

    /**
     * Ohne Nachweis von Faktor 1 ist die Codeeingabe kein Weg ins Backend -
     * auch nicht, wenn ein gueltiger Code in der Datenbank liegt.
     */
    public function testOhnePasswortnachweisFuehrtDieCodeseiteZurueckZurAnmeldung(): void {
        $fremd = $this->newClient();

        $this->assertSame('/login', $fremd->get('/login/2fa/email')->location());
    }

    public function testDerFaktorLaesstSichWiederAusschalten(): void {
        $unique = uniqid();
        [$client, $username, , $passwort] = $this->angemeldetesKonto($unique);
        $this->mailcodeEinschalten($client, $username, $passwort);

        $profil = $client->get('/profil');
        $aus = $client->post('/profil/2fa/email/aus', [
            'csrf_token' => $profil->formField('csrf_token') ?? '',
            'current_password' => $passwort,
        ]);
        $this->assertSame('/profil?success=email_factor_off', $aus->location(), "Body: {$aus->body}");

        $zweiter = $this->newClient();
        $loginPage = $zweiter->get('/login');
        $login = $zweiter->post('/login', [
            'csrf_token' => $loginPage->formField('csrf_token') ?? '',
            'kennung' => $username,
            'password' => $passwort,
        ]);
        $this->assertSame('/admin', $login->location(), 'Ohne zweiten Faktor ist die Anmeldung wieder in einem Schritt fertig.');
    }

    /**
     * DIE LUECKE, DIE DIESER TEST ZUHAELT.
     *
     * Bis zur Gegenpruefung dieser Runde fragten /2fa/setup und /2fa/enable
     * ausschliesslich `totp_enabled` ab. Fuer ein Konto, dessen einziger
     * Faktor der Mailcode ist, war die Bedingung falsch - die Step-up-Schranke
     * aus #112 wurde uebersprungen, die Seite gab ein frisches TOTP-Secret
     * aus, und wer nur das Passwort kannte, war nach dem Bestaetigen mit dem
     * EIGENEN Geraet angemeldet. Den Mailcode hatte er nie gesehen; die
     * Backup-Codes des Opfers waren dabei ueberschrieben.
     */
    public function testDerMailcodeLaesstSichNichtUeberDieTotpEinrichtungUmgehen(): void {
        $unique = uniqid();
        [$client, $username, , $passwort] = $this->angemeldetesKonto($unique);
        $this->mailcodeEinschalten($client, $username, $passwort);

        $angreifer = $this->newClient();
        $loginPage = $angreifer->get('/login');
        $login = $angreifer->post('/login', [
            'csrf_token' => $loginPage->formField('csrf_token') ?? '',
            'kennung' => $username,
            'password' => $passwort,
        ]);
        $this->assertStringStartsWith('/login/2fa/email', (string)$login->location());

        // Statt des Codes: der Umweg ueber die TOTP-Einrichtung.
        $setup = $angreifer->get('/2fa/setup');
        $this->assertSame(
            '/login/2fa/email',
            $setup->location(),
            'Mit nur bewiesenem Passwort darf die 2FA-Einrichtung kein Secret ausgeben.'
        );
        $this->assertNull(
            self::extractTotpSecret($setup),
            'Auf dieser Antwort darf ueberhaupt kein Secret stehen.'
        );

        // Und der direkte POST auch nicht - die Schranke muss an beiden Stellen
        // fuer sich allein tragen.
        $enable = $angreifer->post('/2fa/enable', [
            'csrf_token' => $loginPage->formField('csrf_token') ?? '',
            'confirm_backup' => '1',
            'totp_code' => '123456',
        ]);
        $this->assertNotSame('/admin?2fa=enabled', $enable->location(), 'Der direkte POST darf nicht durchgehen.');
        $this->assertNotSame(200, $angreifer->get('/admin')->statusCode, 'Der Angreifer darf nicht angemeldet sein.');

        // Das Konto hat weiterhin KEIN TOTP - es wurde nichts untergeschoben.
        $db = \App\Database::getInstance();
        $stmt = $db->prepare("SELECT totp_enabled FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $this->assertSame(0, (int)$stmt->fetchColumn());
    }

    /**
     * Die Kehrseite: Ein Konto, dessen einziger Faktor der Mailcode ist, muss
     * eine Authentikator-App NACHRUESTEN koennen. Sonst waere die Schranke
     * oben eine Sackgasse - die Reauth-Seite verlangt einen Faktor, und den
     * gibt es fuer dieses Konto nur per E-Mail.
     */
    public function testEinMailcodeKontoKannEineAuthentikatorAppNachruesten(): void {
        $unique = uniqid();
        [$client, $username, , $passwort] = $this->angemeldetesKonto($unique);
        $this->mailcodeEinschalten($client, $username, $passwort);

        // Angemeldet, aber ohne frischen Step-up: Es gibt kein Secret, sondern
        // die Reauth-Seite - und zwar mit dem Feld fuer den Mailcode.
        $reauth = $client->get('/2fa/setup');
        $this->assertNull(self::extractTotpSecret($reauth), 'Ohne Step-up kein Secret.');
        $this->assertStringContainsString('name="email_code"', $reauth->body);

        $client->post('/2fa/reauth/code', [
            'csrf_token' => $reauth->formField('csrf_token') ?? '',
        ]);
        $this->codeUnterschieben($username, EmailSecondFactor::PURPOSE_SETUP);

        $reauth = $client->get('/2fa/setup');
        $freigabe = $client->post('/2fa/reauth', [
            'csrf_token' => $reauth->formField('csrf_token') ?? '',
            'password' => $passwort,
            'email_code' => self::TESTCODE,
        ]);
        $this->assertSame('/2fa/setup', $freigabe->location(), "Step-up fehlgeschlagen, Body: {$freigabe->body}");

        $setup = $client->get('/2fa/setup');
        $secret = self::extractTotpSecret($setup);
        $this->assertNotNull($secret, 'Nach dem Step-up muss die Einrichtung offenstehen.');

        $ein = $client->post('/2fa/enable', [
            'csrf_token' => $setup->formField('csrf_token') ?? '',
            'confirm_backup' => '1',
            'totp_code' => \App\Security\Totp::getCode($secret),
        ]);
        $this->assertSame('/admin?2fa=enabled', $ein->location(), "Body: {$ein->body}");
    }

    /**
     * Ein Konto, dessen einziger Faktor der Mailcode ist, muss von der
     * Profilseite aus an frische Backup-Codes kommen.
     *
     * Sie sind der Rueckweg, wenn keine Mail ankommt - und der Mailversand
     * ist der unzuverlaessigste Teil dieses Verfahrens. Wer den letzten
     * verbraucht hat und keine neuen bekommt, haengt beim naechsten
     * Zustellfehler fest.
     */
    public function testEinMailcodeKontoBekommtNeueBackupCodesUeberDasProfil(): void {
        $unique = uniqid();
        [$client, $username, , $passwort] = $this->angemeldetesKonto($unique);
        $this->mailcodeEinschalten($client, $username, $passwort);

        $db = \App\Database::getInstance();
        $stmt = $db->prepare("SELECT backup_codes FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $vorher = (string)$stmt->fetchColumn();

        // Der Knopf muss auf DIESER Seite stehen - der Hinweis darunter
        // verweist auf ihn.
        $profil = $client->get('/profil');
        $this->assertStringContainsString('/profil/2fa/email/code', $profil->body);

        $client->post('/profil/2fa/email/code', [
            'csrf_token' => $profil->formField('csrf_token') ?? '',
        ]);
        $this->codeUnterschieben($username, EmailSecondFactor::PURPOSE_SETUP);

        $profil = $client->get('/profil');
        $this->assertStringContainsString('name="email_code"', $profil->body, 'Das Formular fuer neue Backup-Codes muss erscheinen.');

        $neu = $client->post('/profil/backup-codes', [
            'csrf_token' => $profil->formField('csrf_token') ?? '',
            'current_password' => $passwort,
            'email_code' => self::TESTCODE,
        ]);
        $this->assertSame('/profil?success=backup_codes', $neu->location(), "Body: {$neu->body}");

        $stmt->execute([$username]);
        $nachher = (string)$stmt->fetchColumn();
        $this->assertNotSame($vorher, $nachher, 'Die Backup-Codes muessen wirklich ersetzt worden sein.');
        $this->assertCount(10, json_decode($nachher, true) ?? []);
    }

    /**
     * Traegt ein Admin die Adresse um, waehrend ein Anmeldecode unterwegs ist,
     * muss der Code sterben - er liegt im ALTEN Postfach.
     */
    public function testEineAdressaenderungDurchDenAdminVerwirftOffeneCodes(): void {
        $unique = uniqid();
        [$client, $username, , $passwort] = $this->angemeldetesKonto($unique);
        $this->mailcodeEinschalten($client, $username, $passwort);

        $zweiter = $this->newClient();
        $loginPage = $zweiter->get('/login');
        $zweiter->post('/login', [
            'csrf_token' => $loginPage->formField('csrf_token') ?? '',
            'kennung' => $username,
            'password' => $passwort,
        ]);
        $this->codeUnterschieben($username, EmailSecondFactor::PURPOSE_LOGIN);

        // Admin traegt NUR die Adresse um (Passwortfeld bleibt leer).
        $admin = $this->authenticatedClient();
        $liste = $admin->get('/admin/users?search=' . urlencode($username));
        preg_match('/\/admin\/users\/edit\?id=(\d+)/', $liste->body, $treffer);
        $this->assertNotEmpty($treffer);
        $userId = (int)$treffer[1];

        $editForm = $admin->get('/admin/users/edit?id=' . $userId);
        $gespeichert = $admin->post('/admin/users/update', [
            'csrf_token' => $editForm->formField('csrf_token') ?? '',
            'id' => (string)$userId,
            'username' => $username,
            'email' => "umgetragen-{$unique}@example.com",
        ]);
        $this->assertSame('/admin/users?success=updated', $gespeichert->location(), "Body: {$gespeichert->body}");

        $codePage = $zweiter->get('/login/2fa/email');
        $mitAltemCode = $zweiter->post('/login/2fa/email', [
            'csrf_token' => $codePage->formField('csrf_token') ?? '',
            'code' => self::TESTCODE,
        ]);
        $this->assertNull(
            $mitAltemCode->location(),
            'Der Code im alten Postfach darf nach dem Umtragen nicht mehr gelten.'
        );
    }

    /**
     * Fuer Administratoren ist der Mailcode nicht zugelassen - er ist der
     * schwaechste Faktor, und "davon abraten" ist keine Schranke.
     */
    public function testAdministratorenBekommenDenMailcodeNichtAngeboten(): void {
        $admin = $this->authenticatedClient();

        $profil = $admin->get('/profil');
        $this->assertStringContainsString('Für Administratoren nicht zugelassen', $profil->body);

        $abgelehnt = $admin->post('/profil/2fa/email/ein', [
            'csrf_token' => $profil->formField('csrf_token') ?? '',
            'current_password' => self::$adminPassword,
            'code' => self::TESTCODE,
        ]);
        $this->assertSame(
            '/profil?error=email_factor_not_allowed',
            $abgelehnt->location(),
            'Die Ablehnung muss serverseitig greifen, nicht nur in der Oberflaeche.'
        );
    }
}
