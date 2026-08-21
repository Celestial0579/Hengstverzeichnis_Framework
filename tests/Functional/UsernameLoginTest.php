<?php
// tests/Functional/UsernameLoginTest.php

namespace Tests\Functional;

/**
 * Anmeldung ueber den Benutzernamen (#348) - ueber den echten HTTP-Weg.
 *
 * Drei Dinge, die nur hier zu pruefen sind, weil sie am Zusammenspiel von
 * Formular, Abfrage und Zaehler haengen:
 *
 * 1. Beide Kennungen fuehren zum selben Konto.
 * 2. Eine mehrdeutige Kennung laesst NIEMANDEN herein (fail-closed).
 * 3. Der Zaehler haengt am Konto, nicht an der Schreibweise - sonst gaebe die
 *    zweite Kennung einem Angreifer die doppelte Zahl an Versuchen.
 */
class UsernameLoginTest extends FunctionalTestCase {

    /** @var array<int, string> Direkt in der DB angelegte Konten, die wieder weg muessen. */
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
     * @return array{0: string, 1: string, 2: string} username, email, passwort
     */
    private function leserKonto(\Tests\Support\HttpClient $admin, string $unique): array {
        $groupId = $this->createGroupWithoutTwoFa($admin, "Nur lesen {$unique}");

        $username = "kennung{$unique}";
        $email = "kennung-{$unique}@example.com";
        $passwort = 'KennungTest123!';

        $createForm = $admin->get('/admin/users/create');
        $response = $admin->post('/admin/users/store', [
            'csrf_token' => $createForm->formField('csrf_token') ?? '',
            'username' => $username,
            'email' => $email,
            'password' => $passwort,
            'groups' => [(string)$groupId],
        ]);
        $this->assertSame('/admin/users?success=created', $response->location(), "Anlegen fehlgeschlagen, Body: {$response->body}");
        $this->aufraeumen[] = $username;

        return [$username, $email, $passwort];
    }

    private function anmelden(string $kennung, string $passwort): \Tests\Support\HttpResponse {
        $client = $this->newClient();
        $loginPage = $client->get('/login');

        return $client->post('/login', [
            'csrf_token' => $loginPage->formField('csrf_token') ?? '',
            'kennung' => $kennung,
            'password' => $passwort,
        ]);
    }

    public function testAnmeldungMitBenutzernameUndMitAdresseFuehrenZumSelbenKonto(): void {
        $admin = $this->authenticatedClient();
        [$username, $email, $passwort] = $this->leserKonto($admin, uniqid());

        // Erstanmeldung ueber den Benutzernamen: Passwortwechsel-Zwang greift,
        // also war die Anmeldung erfolgreich.
        $this->assertSame(
            '/force-password-change',
            $this->anmelden($username, $passwort)->location(),
            'Der Benutzername muss als Anmeldekennung gelten.'
        );

        // Und ueber die Adresse genauso.
        $this->assertSame(
            '/force-password-change',
            $this->anmelden($email, $passwort)->location(),
            'Die E-Mail-Adresse bleibt daneben gueltig.'
        );
    }

    public function testGrossschreibungDerKennungSpieltKeineRolle(): void {
        $admin = $this->authenticatedClient();
        [$username, , $passwort] = $this->leserKonto($admin, uniqid());

        $this->assertSame(
            '/force-password-change',
            $this->anmelden(strtoupper($username), $passwort)->location(),
            'Die Datenbank vergleicht ohne Ruecksicht auf Gross- und Kleinschreibung - die Anmeldung auch.'
        );
    }

    /**
     * Der Fall, den die Migration meldet: Ein Bestandskonto traegt als
     * Benutzernamen die E-Mail-Adresse eines anderen. Dann ist die Eingabe
     * mehrdeutig - und es kommt NIEMAND herein, statt dass geraten wird.
     */
    public function testEineMehrdeutigeKennungLaesstNiemandenHerein(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        [, $email, $passwort] = $this->leserKonto($admin, $unique);

        // Ein solches Konto laesst sich ueber die Oberflaeche nicht mehr
        // anlegen (das `@`-Verbot); der Bestand kann es aber enthalten.
        $db = \App\Database::getInstance();
        $stmt = $db->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
        $stmt->execute([$email, "zwilling-{$unique}@example.com", password_hash($passwort, PASSWORD_DEFAULT)]);
        $this->aufraeumen[] = $email;

        $antwort = $this->anmelden($email, $passwort);

        $this->assertNull($antwort->location(), 'Eine mehrdeutige Kennung darf zu keiner Anmeldung fuehren.');
        $this->assertStringContainsString(
            'Ungültige Zugangsdaten.',
            $antwort->body,
            'Die Meldung bleibt generisch - sie darf den Grund nicht verraten.'
        );
    }

    public function testDerZaehlerHaengtAmKontoUndNichtAnDerSchreibweise(): void {
        $admin = $this->authenticatedClient();
        [$username, $email, ] = $this->leserKonto($admin, uniqid());

        // Fuenf Fehlversuche ueber den Benutzernamen schoepfen das Limit aus.
        for ($i = 0; $i < 5; $i++) {
            $antwort = $this->anmelden($username, 'definitiv-falsch');
            $this->assertStringContainsString('Ungültige Zugangsdaten.', $antwort->body);
        }

        // Derselbe Zaehler muss auch fuer die ANDERE Kennung desselben Kontos
        // greifen. Ohne das haette ein Angreifer schlicht zwei Toepfe.
        $ueberAdresse = $this->anmelden($email, 'definitiv-falsch');
        $this->assertStringContainsString(
            'Zu viele fehlgeschlagene Anmeldeversuche',
            $ueberAdresse->body,
            'Benutzername und Adresse desselben Kontos teilen sich einen Zaehler.'
        );
    }

    public function testEinBenutzernameMitAtWirdBeimAnlegenAbgelehnt(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        $createForm = $admin->get('/admin/users/create');
        $antwort = $admin->post('/admin/users/store', [
            'csrf_token' => $createForm->formField('csrf_token') ?? '',
            'username' => "mit-at-{$unique}@example.com",
            'email' => "regulaer-{$unique}@example.com",
            'password' => 'KennungTest123!',
        ]);

        $this->assertNull($antwort->location(), 'Das Anlegen darf nicht gelingen.');
        $this->assertStringContainsString('darf kein', $antwort->body);
    }
}
