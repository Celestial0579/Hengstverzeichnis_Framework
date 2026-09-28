<?php
// tests/Functional/InstallationsepocheTest.php

namespace Tests\Functional;

use App\Database;
use App\Service\InstallEpoch;

/**
 * Eine Sitzung aus einer früheren Installation gilt nicht mehr (Audit M24).
 *
 * Nach einem Werksreset oder einem Setup mit neuer Datenbank vergab
 * MariaDB die Benutzer-IDs neu; eine offene Alt-Sitzung trug danach das
 * neue Konto mit derselben ID. Setup und Reset würfeln deshalb
 * settings.install_epoch neu. Hier wird der Wechsel direkt in der
 * Datenbank simuliert - den echten Reset prüft
 * tests/Integration/SystemResetTest.php, einen Werksreset über HTTP verträgt
 * die geteilte Functional-Datenbank nicht.
 *
 * Jeder Fall nutzt eigene, frisch angemeldete Clients, und das finally
 * setzt die Epoche zurück: Die übrigen Klassen der Suite teilen sich die
 * Datenbank.
 */
class InstallationsepocheTest extends FunctionalTestCase {

    private static function epoche(): string {
        return (string)InstallEpoch::current(Database::getInstance());
    }

    private static function setzeEpoche(string $wert): void {
        Database::getInstance()->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        )->execute([InstallEpoch::SETTING, $wert]);
    }

    public function testNachDerProvisionierungGibtEsEineEpoche(): void {
        $this->authenticatedClient();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', self::epoche());
    }

    /** (a) Eine volle Anmeldung endet, sobald die Epoche wechselt. */
    public function testVolleSitzungEndetBeiNeuerEpoche(): void {
        $konto = $this->angemeldetOhneFaktor($this->authenticatedClient(), 'epoa');
        $client = $konto['client'];
        $this->assertSame(200, $client->get('/admin')->statusCode);

        $alt = self::epoche();
        try {
            self::setzeEpoche('test-neu');

            $antwort = $client->get('/admin');
            $this->assertSame(302, $antwort->statusCode);
            $this->assertStringStartsWith('/login', (string)$antwort->location());
            $this->assertStringStartsWith('/login', (string)$client->get('/admin')->location(), 'Die Folgeanfrage ist anonym.');

            // Eine neue Anmeldung bindet die neue Epoche und gilt.
            $neu = $this->newClient();
            $login = $neu->post('/login', [
                'csrf_token' => $neu->get('/login')->formField('csrf_token') ?? '',
                'kennung' => $konto['username'],
                'password' => $konto['passwort'],
            ]);
            $this->assertSame('/admin', $login->location(), "Body: {$login->body}");
            $this->assertSame(200, $neu->get('/admin')->statusCode);
        } finally {
            self::setzeEpoche($alt);
        }

        $audit = Database::getInstance()->query(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'Session beendet: Installation wurde zurückgesetzt'"
        )->fetchColumn();
        $this->assertGreaterThan(0, (int)$audit);
    }

    /**
     * (b) Eine halbe Anmeldung (Faktor 1 erbracht, 2FA-Einrichtung offen)
     * darf nach dem Wechsel kein Secret für das Konto mit dieser ID erzeugen.
     */
    public function testHalbeAnmeldungEndetBeiNeuerEpoche(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $username = "epob{$unique}";
        $passwort = 'EpocheTest123!';
        $angelegt = $admin->post('/admin/users/store', [
            'csrf_token' => $admin->get('/admin/users/create')->formField('csrf_token') ?? '',
            'username' => $username,
            'email' => "epob-{$unique}@example.com",
            'password' => $passwort,
            'groups' => [],
        ]);
        $this->assertSame('/admin/users?success=created', $angelegt->location(), "Body: {$angelegt->body}");

        $client = $this->newClient();
        $login = $client->post('/login', [
            'csrf_token' => $client->get('/login')->formField('csrf_token') ?? '',
            'kennung' => $username,
            'password' => $passwort,
        ]);
        $this->assertSame('/2fa/setup', $login->location(), 'Ohne Gruppe gilt die 2FA-Pflicht.');

        $alt = self::epoche();
        try {
            self::setzeEpoche('test-neu');

            $seite = $client->get('/2fa/setup');
            $this->assertSame(302, $seite->statusCode);
            $this->assertSame('/login', $seite->location());
            $this->assertStringNotContainsString('Geheimer Schlüssel', $seite->body);
        } finally {
            self::setzeEpoche($alt);
        }

        // Auch mit wiederhergestellter Epoche bleibt die Identität verworfen.
        $this->assertSame('/login', $client->get('/2fa/setup')->location());
    }

    /**
     * (c) Der Rückfall auf user_id in /2fa/setup: Eine voll angemeldete, aber
     * inzwischen ungültige Sitzung richtet keinen Faktor mehr ein.
     */
    public function testUngueltigeVolleSitzungKommtNichtAn2faSetup(): void {
        $konto = $this->angemeldetOhneFaktor($this->authenticatedClient(), 'epoc');
        $client = $konto['client'];
        Database::getInstance()->prepare("UPDATE users SET session_version = session_version + 1 WHERE id = ?")
            ->execute([$konto['id']]);

        $seite = $client->get('/2fa/setup');
        $this->assertSame(302, $seite->statusCode);
        $this->assertStringStartsWith('/login', (string)$seite->location());
        $this->assertStringNotContainsString('Geheimer Schlüssel', $seite->body);
    }
}
