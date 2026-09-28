<?php
// tests/Functional/EnvErsteinrichtungTest.php

namespace Tests\Functional;

use App\Security\Totp;
use Tests\Support\AuxiliaryServer;
use Tests\Support\HttpClient;
use Tests\Support\WegwerfDatenbank;

/**
 * Vollautomatische Ersteinrichtung ueber ADMIN_* (Audit H2).
 *
 * Frueher bekam der ERSTE Aufrufer von /setup die Sitzung des frisch
 * angelegten Admins - ohne das Passwort zu kennen. Er richtete seinen eigenen
 * zweiten Faktor ein und sperrte den Betreiber aus. Und ein ungueltiger
 * ADMIN_*-Wert (etwa das README-Beispiel "admin", ein reservierter Name) fiel
 * still auf den Wizard zurueck, der dem ersten Besucher das Admin-Formular
 * anbot.
 *
 * Der geteilte Testserver ist laengst eingerichtet. Diese Klasse startet
 * deshalb eigene App-Instanzen auf leeren Wegwerf-Datenbanken.
 */
class EnvErsteinrichtungTest extends FunctionalTestCase {

    private const PORT_GUELTIG = 8793;
    private const PORT_UNGUELTIG = 8794;

    private const ADMIN_NAME = 'h2verbandsadmin';
    private const ADMIN_MAIL = 'h2-admin@example.com';
    private const ADMIN_PW = 'H2-Ersteinrichtung-9!';

    private static ?AuxiliaryServer $gueltig = null;
    private static ?AuxiliaryServer $ungueltig = null;

    private static function dbGueltig(): string {
        return WegwerfDatenbank::name('env_ersteinrichtung');
    }

    private static function dbUngueltig(): string {
        return WegwerfDatenbank::name('env_ersteinrichtung_ungueltig');
    }

    private static function serverPdo(): \PDO {
        return new \PDO(
            sprintf('mysql:host=%s;port=%s;charset=utf8mb4', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '3306'),
            (string)getenv('DB_USER'),
            (string)getenv('DB_PASS'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
    }

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        // LEER anlegen, nicht nur loeschen: Bei 'Unknown database' haelt
        // needsSetup() die Instanz fuer eingerichtet und leitet auf /login.
        $pdo = self::serverPdo();
        foreach ([self::dbGueltig(), self::dbUngueltig()] as $db) {
            $pdo->exec("DROP DATABASE IF EXISTS `{$db}`");
            $pdo->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }

        $basis = ['SITE_NAME' => 'H2-Testverband', 'APP_KEY' => 'h2-functional-test-key-not-for-production'];

        self::$gueltig = new AuxiliaryServer(self::PORT_GUELTIG, __DIR__ . '/../../public', null, $basis + [
            'DB_NAME' => self::dbGueltig(),
            'ADMIN_USERNAME' => self::ADMIN_NAME,
            'ADMIN_EMAIL' => self::ADMIN_MAIL,
            'ADMIN_PASSWORD' => self::ADMIN_PW,
        ]);
        self::$gueltig->start();

        // Das fruehere README-Beispiel: "admin" ist ein reservierter Name.
        self::$ungueltig = new AuxiliaryServer(self::PORT_UNGUELTIG, __DIR__ . '/../../public', null, $basis + [
            'DB_NAME' => self::dbUngueltig(),
            'ADMIN_USERNAME' => 'admin',
            'ADMIN_EMAIL' => 'h2-ungueltig@example.com',
            'ADMIN_PASSWORD' => self::ADMIN_PW,
        ]);
        self::$ungueltig->start();
    }

    public static function tearDownAfterClass(): void {
        self::$gueltig?->stop();
        self::$ungueltig?->stop();
        $pdo = self::serverPdo();
        foreach ([self::dbGueltig(), self::dbUngueltig()] as $db) {
            $pdo->exec("DROP DATABASE IF EXISTS `{$db}`");
        }
        parent::tearDownAfterClass();
    }

    private function client(AuxiliaryServer $server): HttpClient {
        return new HttpClient($server->baseUrl());
    }

    public function testAnonymerErstaufrufBekommtKeineAdminSitzung(): void {
        $besucher = $this->client(self::$gueltig);

        $this->assertSame('/setup', $besucher->get('/')->location());

        $setup = $besucher->get('/setup');
        $this->assertSame('/login?success=setup_completed', $setup->location(), "Body: {$setup->body}");
        $this->assertStringContainsString('Ersteinrichtung ist abgeschlossen', $besucher->get('/login?success=setup_completed')->body);

        // Der Kern von H2: Dieselbe Sitzung kommt ohne Passwort nicht an die
        // 2FA-Einrichtung des neuen Admins.
        $zweiFa = $besucher->get('/2fa/setup');
        $this->assertSame('/login', $zweiFa->location());
        $this->assertStringNotContainsString('Geheimer Schlüssel', $zweiFa->body);

        $aktivieren = $besucher->post('/2fa/enable', [
            'csrf_token' => $besucher->get('/login')->formField('csrf_token') ?? '',
            'confirm_backup' => '1',
            'totp_code' => '000000',
        ]);
        $this->assertSame('/login', $aktivieren->location());
        $this->assertSame('/login', $besucher->get('/admin')->location());

        $pdo = self::serverPdo();
        $stmt = $pdo->prepare(
            'SELECT u.totp_enabled, u.totp_secret, g.slug
             FROM `' . self::dbGueltig() . '`.users u
             JOIN `' . self::dbGueltig() . '`.user_groups ug ON ug.user_id = u.id
             JOIN `' . self::dbGueltig() . '`.`groups` g ON g.id = ug.group_id
             WHERE u.username = ?'
        );
        $stmt->execute([self::ADMIN_NAME]);
        $konto = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($konto, 'Das vorgegebene Admin-Konto muss angelegt sein.');
        $this->assertSame('admin', $konto['slug']);
        $this->assertSame(0, (int)$konto['totp_enabled']);
        $this->assertNull($konto['totp_secret'], 'Niemand darf dem Konto einen zweiten Faktor angehaengt haben.');
    }

    #[\PHPUnit\Framework\Attributes\Depends('testAnonymerErstaufrufBekommtKeineAdminSitzung')]
    public function testBetreiberRichtetMitAdminPasswordEin(): void {
        $betreiber = $this->client(self::$gueltig);

        $this->assertSame('/login', $betreiber->get('/setup')->location(), 'Die Instanz ist eingerichtet.');

        $falsch = $betreiber->post('/login', [
            'csrf_token' => $betreiber->get('/login')->formField('csrf_token') ?? '',
            'kennung' => self::ADMIN_MAIL,
            'password' => 'falsches-Passwort-1',
        ]);
        $this->assertNull($falsch->location());
        $this->assertStringContainsString('Ungültige Zugangsdaten', $falsch->body);

        $login = $betreiber->post('/login', [
            'csrf_token' => $betreiber->get('/login')->formField('csrf_token') ?? '',
            'kennung' => self::ADMIN_MAIL,
            'password' => self::ADMIN_PW,
        ]);
        $this->assertSame('/2fa/setup', $login->location(), "Body: {$login->body}");

        $seite = $betreiber->get('/2fa/setup');
        $secret = self::extractTotpSecret($seite);
        $this->assertNotNull($secret);
        $aktivieren = $betreiber->post('/2fa/enable', [
            'csrf_token' => $seite->formField('csrf_token') ?? '',
            'confirm_backup' => '1',
            'totp_code' => Totp::getCode($secret),
        ]);
        $this->assertSame(302, $aktivieren->statusCode, "Body: {$aktivieren->body}");
        $this->assertSame(200, $betreiber->get('/admin')->statusCode);
    }

    public function testUngueltigeAdminVariablenOeffnenKeinFormular(): void {
        $besucher = $this->client(self::$ungueltig);

        $seite = $besucher->get('/setup');
        $this->assertSame(503, $seite->statusCode, "Body: {$seite->body}");
        $this->assertStringContainsString('ADMIN_USERNAME', $seite->body);
        $this->assertStringNotContainsString('<form action="/setup" method="POST">', $seite->body);
        $this->assertStringNotContainsString('name="password_confirm"', $seite->body);
        $this->assertStringNotContainsString('name="username"', $seite->body);

        // Auch ein direkter POST darf kein eigenes Konto einschleusen - und
        // zwar wegen des Guards, nicht wegen des fehlenden CSRF-Tokens.
        $post = $besucher->post('/setup', [
            'username' => 'eindringling',
            'email' => 'eindringling@example.com',
            'password' => 'Eindringling-123!',
            'password_confirm' => 'Eindringling-123!',
            'site_name' => 'Fremd',
        ]);
        $this->assertSame('/setup', $post->location(), "Body: {$post->body}");

        $pdo = self::serverPdo();
        $tabelle = $pdo->query("SHOW TABLES FROM `" . self::dbUngueltig() . "` LIKE 'users'")->fetchColumn();
        if ($tabelle !== false) {
            $this->assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM `' . self::dbUngueltig() . '`.users')->fetchColumn());
        } else {
            $this->assertFalse($tabelle);
        }
    }
}
