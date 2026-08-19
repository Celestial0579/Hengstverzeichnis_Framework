<?php
// tests/Functional/UpdateInPlaceDisabledTest.php

namespace Tests\Functional;

use App\Security\Totp;
use Tests\Support\AuxiliaryServer;
use Tests\Support\HttpClient;

/**
 * HTTP-Funktionstests für den Container-Modus der Update-Verwaltung
 * (UPDATE_IN_PLACE=0, siehe config/config.php + UpdateController).
 *
 * Im Container gehört der Anwendungscode root und PHP läuft als www-data - ein
 * durch den Web-Prozess überschreibbarer Codebaum wäre ein RCE-Verstärker.
 * Deshalb ist die In-Place-Selbstaktualisierung dort abgeschaltet: Der Admin
 * sieht weiterhin, DASS ein Update vorliegt, bekommt aber keinen In-Place-Knopf
 * (Verweis auf ein Image-Update, z. B. Watchtower), und ein direkter POST auf
 * /admin/updates/run wird serverseitig abgelehnt, BEVOR irgendein Datei- oder
 * Netzwerkzugriff passiert.
 *
 * Der geteilte Testserver (PhpBuiltInServer) läuft bewusst im Default-Modus
 * (In-Place an) - die bestehenden Zusicherungen in UpdateAdminTest bleiben
 * dadurch unverändert gültig. Diese Klasse startet deshalb eine zweite
 * App-Instanz mit UPDATE_IN_PLACE=0 (gleiche Datenbank, gleicher Code).
 */
class UpdateInPlaceDisabledTest extends FunctionalTestCase {

    private const APP_PORT = 8770;
    private static ?AuxiliaryServer $app = null;

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        self::$app = new AuxiliaryServer(
            self::APP_PORT,
            __DIR__ . '/../../public',
            null,
            [
                'UPDATE_IN_PLACE' => '0',
                'APP_URL' => 'http://127.0.0.1:' . self::APP_PORT,
            ]
        );
        self::$app->start();
    }

    public static function tearDownAfterClass(): void {
        self::$app?->stop();
        self::$app = null;
        parent::tearDownAfterClass();
    }

    /**
     * Admin-Login gegen die Container-Instanz. Gleiche Datenbank/Admin/TOTP wie
     * der geteilte Server - authenticatedClient() stellt das Konto bereit und
     * füllt die Admin-Statics; hier wird derselbe Login-Flow gegen die zweite
     * Instanz wiederholt.
     */
    private function containerAdmin(): HttpClient {
        $this->authenticatedClient();
        self::resetTotpReplayGuard(self::$adminEmail);

        $client = new HttpClient(self::$app->baseUrl());
        $loginPage = $client->get('/login');
        $client->post('/login', [
            'csrf_token' => $loginPage->formField('csrf_token') ?? '',
            'email' => self::$adminEmail,
            'password' => self::$adminPassword,
        ]);
        $verifyPage = $client->get('/login/2fa');
        $verify = $client->post('/login/2fa', [
            'csrf_token' => $verifyPage->formField('csrf_token') ?? '',
            'totp_code' => Totp::getCode(self::$totpSecret),
        ]);
        self::assertSame(
            302,
            $verify->statusCode,
            "2FA-Login gegen die Container-Instanz sollte erfolgreich sein, Body: {$verify->body}"
        );

        return $client;
    }

    public function testUpdatesPageStillLoadsAndShowsVersion(): void {
        $admin = $this->containerAdmin();

        $page = $admin->get('/admin/updates');
        $this->assertSame(200, $page->statusCode);
        // Der Admin sieht weiterhin den installierten Stand - nur der
        // In-Place-Installationsweg entfällt.
        $this->assertStringContainsString('Installierte Version', $page->body);
    }

    /**
     * Der sicherheitskritische Kern: Ein direkter POST auf /admin/updates/run
     * wird im Container-Modus abgelehnt - und zwar mit einem eigenen
     * "deaktiviert"-Hinweis (Verweis auf Watchtower), nicht mit der
     * Backup-Meldung. So ist belegt, dass die Ablehnung VOR der eigentlichen
     * Update-Logik greift (Defense-in-Depth, auch ohne den ausgeblendeten Knopf).
     */
    public function testRunIsRefusedInContainerMode(): void {
        $admin = $this->containerAdmin();

        $response = $admin->post('/admin/updates/run', [
            'csrf_token' => $this->currentCsrfToken($admin),
        ]);

        $location = (string)$response->location();
        $this->assertStringStartsWith('/admin/updates?error=', $location);
        $this->assertStringContainsString('deaktiviert', urldecode($location));
        $this->assertStringContainsString('Watchtower', urldecode($location));
    }

    /**
     * #313, dritte Sperre: Im Container-Modus laesst sich die unbeaufsichtigte
     * Installation gar nicht erst einschalten.
     *
     * Der Grund ist derselbe wie beim direkten Update: Im Container gehoert
     * der Anwendungscode root, PHP laeuft als www-data - ein durch den
     * Web-Prozess ueberschreibbarer Codebaum waere ein RCE-Verstaerker. Eine
     * eingeschaltete Automatik erzeugte hier nur eine taegliche Fehlermail,
     * und ein Wegfall der Sperre bliebe ohne diesen Fall unbemerkt.
     */
    public function testAutoInstallCannotBeEnabledInContainerMode(): void {
        $admin = $this->containerAdmin();

        $response = $admin->post('/admin/updates/automation', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'update_notify' => '1',
            'update_auto_install' => '1',
        ]);

        $location = (string)$response->location();
        $this->assertStringStartsWith('/admin/updates?error=', $location);
        $this->assertStringContainsString('deaktiviert', urldecode($location));

        $stmt = \App\Database::getInstance()->prepare(
            'SELECT setting_value FROM settings WHERE setting_key = ?'
        );
        $stmt->execute(['update_auto_install']);
        $wert = $stmt->fetchColumn();
        $this->assertSame(
            '0',
            $wert === false ? '0' : (string)$wert,
            'Die Automatik darf im Container-Modus nicht in den Einstellungen landen'
        );
    }

    /**
     * CSRF-Schutz gilt auch im Container-Modus (die Ablehnung darf den
     * Token-Check nicht aushebeln).
     */
    public function testRunStillRequiresCsrfToken(): void {
        $admin = $this->containerAdmin();

        $response = $admin->post('/admin/updates/run', []);
        $this->assertSame(403, $response->statusCode);
    }
}
