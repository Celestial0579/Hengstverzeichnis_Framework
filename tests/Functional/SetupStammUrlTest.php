<?php
// tests/Functional/SetupStammUrlTest.php

namespace Tests\Functional;

use Tests\Support\AuxiliaryServer;
use Tests\Support\HttpClient;
use Tests\Support\WegwerfDatenbank;

/**
 * Das Feld „Stamm-URL“ im Einrichtungsassistenten (Audit M6).
 *
 * Ohne Stamm-URL verschickt eine Instanz keine Token-Mails. Der Assistent
 * fragt sie deshalb ab - mit derselben strengen Prüfung wie die
 * Systemeinstellungen: Eine lokale Adresse, die hier durchginge, würde dort
 * bei jedem späteren Speichern abgelehnt.
 *
 * Der geteilte Testserver ist per Umgebung eingerichtet. Diese Klasse
 * startet eigene App-Instanzen auf leeren Wegwerf-Datenbanken: eine OHNE
 * ADMIN_* (also mit Formular) und eine mit gesetztem APP_URL.
 */
class SetupStammUrlTest extends FunctionalTestCase {

    private const PORT_WIZARD = 8795;
    private const PORT_APP_URL = 8796;

    private static ?AuxiliaryServer $wizard = null;
    private static ?AuxiliaryServer $mitAppUrl = null;

    private static function dbWizard(): string {
        return WegwerfDatenbank::name('setup_stamm_url');
    }

    private static function dbAppUrl(): string {
        return WegwerfDatenbank::name('setup_stamm_url_env');
    }

    private static function serverPdo(): \PDO {
        return new \PDO(
            \App\Database::buildDsn((string)(getenv('DB_HOST') ?: '127.0.0.1'), (string)(getenv('DB_PORT') ?: '3306'), null),
            (string)getenv('DB_USER'),
            (string)getenv('DB_PASS'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
    }

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        $pdo = self::serverPdo();
        foreach ([self::dbWizard(), self::dbAppUrl()] as $db) {
            $pdo->exec("DROP DATABASE IF EXISTS `{$db}`");
            $pdo->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }

        // Leere ADMIN_*-Werte zählen nicht (wie leere Zeilen aus .env.example):
        // Der Assistent zeigt das Formular.
        $ohneAdmin = [
            'SITE_NAME' => 'Stamm-URL-Testverband',
            'APP_KEY' => 'stamm-url-functional-test-key-not-for-production',
            'ADMIN_USERNAME' => '',
            'ADMIN_EMAIL' => '',
            'ADMIN_PASSWORD' => '',
            'APP_URL' => '',
        ];

        self::$wizard = new AuxiliaryServer(self::PORT_WIZARD, __DIR__ . '/../../public', null, $ohneAdmin + [
            'DB_NAME' => self::dbWizard(),
        ]);
        self::$wizard->start();

        self::$mitAppUrl = new AuxiliaryServer(self::PORT_APP_URL, __DIR__ . '/../../public', null, [
            'DB_NAME' => self::dbAppUrl(),
            'APP_URL' => 'https://env-verband.example',
        ] + $ohneAdmin);
        self::$mitAppUrl->start();
    }

    public static function tearDownAfterClass(): void {
        self::$wizard?->stop();
        self::$mitAppUrl?->stop();
        $pdo = self::serverPdo();
        foreach ([self::dbWizard(), self::dbAppUrl()] as $db) {
            $pdo->exec("DROP DATABASE IF EXISTS `{$db}`");
        }
        parent::tearDownAfterClass();
    }

    /** @return array<string, string> */
    private function formular(HttpClient $client, string $baseUrl): array {
        return [
            'csrf_token' => $client->get('/setup')->formField('csrf_token') ?? '',
            'base_url' => $baseUrl,
            'username' => 'stammurladmin',
            'email' => 'stamm-url-admin@example.com',
            'password' => 'Stamm-Url-Einrichtung-7!',
            'password_confirm' => 'Stamm-Url-Einrichtung-7!',
        ];
    }

    private function gespeicherteStammUrl(string $db): string|false {
        $pdo = self::serverPdo();
        if ($pdo->query("SHOW TABLES FROM `{$db}` LIKE 'settings'")->fetchColumn() === false) {
            return false;
        }
        return $pdo->query("SELECT setting_value FROM `{$db}`.settings WHERE setting_key = 'base_url'")->fetchColumn();
    }

    public function testFeldOhneVorschlagBeiLokalerAdresse(): void {
        $seite = (new HttpClient(self::$wizard->baseUrl()))->get('/setup');

        $this->assertSame(200, $seite->statusCode, "Body: {$seite->body}");
        $this->assertStringContainsString('name="base_url"', $seite->body);
        $this->assertSame('', $seite->formField('base_url'), '127.0.0.1 besteht die Prüfung nicht und wird nicht vorgeschlagen.');
        $this->assertStringContainsString('lokale oder interne Adresse', $seite->body);
    }

    public function testLokaleStammUrlWirdAbgelehnt(): void {
        $client = new HttpClient(self::$wizard->baseUrl());

        $antwort = $client->post('/setup', $this->formular($client, 'http://localhost:8080'));

        $this->assertNull($antwort->location(), "Body: {$antwort->body}");
        $this->assertStringContainsString('Die Stamm-URL ist ungültig', $antwort->body);
        $this->assertFalse($this->gespeicherteStammUrl(self::dbWizard()), 'Es darf nichts eingerichtet sein.');
    }

    #[\PHPUnit\Framework\Attributes\Depends('testLokaleStammUrlWirdAbgelehnt')]
    public function testGueltigeStammUrlWirdGespeichert(): void {
        $client = new HttpClient(self::$wizard->baseUrl());

        $antwort = $client->post('/setup', $this->formular($client, 'https://verband.example'));

        $this->assertSame('/2fa/setup', $antwort->location(), "Body: {$antwort->body}");
        $this->assertSame('https://verband.example/', $this->gespeicherteStammUrl(self::dbWizard()));
    }

    /**
     * Mit APP_URL entfällt das Feld; ein trotzdem gesendeter Wert wird
     * ignoriert - APP_URL ist die Stamm-URL.
     */
    public function testMitAppUrlKeinFeld(): void {
        $client = new HttpClient(self::$mitAppUrl->baseUrl());

        $seite = $client->get('/setup');
        $this->assertSame(200, $seite->statusCode, "Body: {$seite->body}");
        $this->assertStringNotContainsString('name="base_url"', $seite->body);

        $antwort = $client->post('/setup', $this->formular($client, 'https://anders.example'));
        $this->assertSame('/2fa/setup', $antwort->location(), "Body: {$antwort->body}");
        $this->assertFalse($this->gespeicherteStammUrl(self::dbAppUrl()));
    }
}
