<?php
// tests/Functional/UpdateAdminTest.php

namespace Tests\Functional;

/**
 * HTTP-Funktionstests für die Update-Verwaltung (#85, siehe UpdateController/
 * UpdateService): Admin-Pflicht, CSRF-Schutz und die zentrale
 * Sicherheits-Leitplanke, dass ein Update ohne konfiguriertes Backup
 * abgelehnt wird, BEVOR irgendein Netzwerkzugriff oder Dateizugriff passiert.
 * Der vollständige Update-Durchlauf (Download + Anwenden) ist bewusst nicht
 * Teil der Functional-Suite (er würde die laufende Testinstallation
 * überschreiben) - die Anwendungslogik ist in UpdateServiceTest (Unit)
 * netzwerkfrei abgedeckt.
 */
class UpdateAdminTest extends FunctionalTestCase {

    public function testUpdatesPageRequiresAdmin(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $editor = $this->createAndLoginEditor($admin, "updater{$unique}", "update-test-{$unique}@example.com");

        $this->assertSame(403, $editor->get('/admin/updates')->statusCode);
    }

    public function testUpdatesPageShowsVersionAndBackupWarning(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->get('/admin/updates');
        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('Installierte Version', $response->body);
        // In der Testumgebung sind keine Backups konfiguriert.
        $this->assertStringContainsString('Automatische Backups sind nicht konfiguriert', $response->body);
    }

    public function testRunRequiresCsrfToken(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->post('/admin/updates/run', []);
        $this->assertSame(403, $response->statusCode);
    }

    public function testChannelSelectionIsShownAndPersisted(): void {
        $admin = $this->authenticatedClient();

        // Default: Kanal Stabil, Auswahlfeld mit beiden Optionen vorhanden.
        $page = $admin->get('/admin/updates');
        $this->assertStringContainsString('Update-Kanal', $page->body);
        $this->assertStringContainsString('Beta (Vorabversionen einbeziehen)', $page->body);
        $this->assertStringContainsString('Kanal: Stabil', $page->body);
        $this->assertStringContainsString('Downgrade findet niemals statt', $page->body);

        // Beta-Opt-in speichern (Redirect führt zur Release-Prüfung; hier wird
        // bewusst nicht gefolgt, um keinen Netzwerkzugriff im Test auszulösen).
        $save = $admin->post('/admin/updates/channel', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'update_channel' => 'beta',
        ]);
        $this->assertSame('/admin/updates?check=1&channel_saved=1', $save->location());

        $afterSave = $admin->get('/admin/updates');
        $this->assertStringContainsString('Kanal: Beta', $afterSave->body);

        // Unbekannte Werte fallen serverseitig auf Stabil zurück.
        $reset = $admin->post('/admin/updates/channel', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'update_channel' => 'nightly-kaputt',
        ]);
        $this->assertSame('/admin/updates?check=1&channel_saved=1', $reset->location());
        $this->assertStringContainsString('Kanal: Stabil', $admin->get('/admin/updates')->body);
    }

    public function testChannelSaveRequiresCsrfToken(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->post('/admin/updates/channel', ['update_channel' => 'beta']);
        $this->assertSame(403, $response->statusCode);
    }

    /**
     * #313: Die Route POST /admin/updates/automation existierte in keinem
     * Test - weder die CSRF-Ablehnung noch die Admin-Pflicht noch die drei
     * Sperren der unbeaufsichtigten Installation.
     *
     * Was daran haengt: Faellt eine der Sperren weg - etwa weil
     * BackupService::isConfigured() kuenftig einen anderen Rueckgabetyp
     * liefert und der Truthiness-Check still zu true wird -, laesst sich
     * update_auto_install=1 auf einer Instanz ohne Backup und ohne Mailversand
     * speichern. Der Scheduler tauscht danach unbeaufsichtigt und ohne
     * vorheriges Backup Produktivcode aus, und niemand bekommt eine Mail
     * darueber: genau der stille Codeaustausch, den der Code laut Kommentar
     * verhindern soll.
     *
     * Jeder Fall belegt zusaetzlich datenbankseitig, dass update_auto_install
     * auf '0' steht. Ein Redirect auf ?error= sagt nur, was die ANTWORT war -
     * nicht, was vorher schon geschrieben wurde.
     */
    public function testAutomationRequiresCsrfToken(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->post('/admin/updates/automation', [
            'update_notify' => '1',
            'update_auto_install' => '1',
        ]);

        $this->assertSame(403, $response->statusCode);
        $this->assertSame('0', $this->setting('update_auto_install'));
    }

    /**
     * Der Endpunkt gehört Administratoren - durchgesetzt im Konstruktor des
     * UpdateControllers.
     *
     * DAS TOKEN MUSS ECHT SEIN. Es kommt deshalb aus einer Gruppe, die dem
     * Testbenutzer eine Seite mit Formular öffnet. Mit einem LEEREN Token
     * (so stand es hier bis #377) prüfte der Test nichts: Nähme jemand das
     * requireAdmin() aus dem Konstruktor, liefe der POST in den CSRF-Check
     * dahinter - und der antwortet ebenfalls mit 403. Der Test wäre grün
     * geblieben, obwohl jeder Redakteur die Update-Automatik hätte umstellen
     * können.
     */
    public function testAutomationRequiresAdmin(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $editor = $this->createAndLoginEditor($admin, "autoupd{$unique}", "auto-update-{$unique}@example.com");

        $response = $editor->post('/admin/updates/automation', [
            'csrf_token' => $this->editorCsrfToken($editor),
            'update_notify' => '1',
            'update_auto_install' => '1',
        ]);

        $this->assertSame(403, $response->statusCode);
        $this->assertSame('0', $this->setting('update_auto_install'));
    }

    /**
     * Sperre 1: Automatisch installieren ohne zu benachrichtigen waere ein
     * stiller Codeaustausch.
     */
    public function testAutoInstallIsRefusedWithoutNotification(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->post('/admin/updates/automation', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'update_auto_install' => '1',
            // update_notify fehlt bewusst
        ]);

        $location = (string)$response->location();
        $this->assertStringStartsWith('/admin/updates?error=', $location);
        $this->assertStringContainsString('Benachrichtigung', urldecode($location));
        $this->assertSame('0', $this->setting('update_auto_install'));
    }

    /**
     * Sperre 2: ohne konfiguriertes externes Backup. In der Testumgebung ist
     * keines eingerichtet (siehe testUpdatesPageShowsVersionAndBackupWarning),
     * die Kombination MIT Benachrichtigung muss also an dieser Huerde
     * scheitern - und nicht an der davor.
     */
    public function testAutoInstallIsRefusedWithoutConfiguredBackup(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->post('/admin/updates/automation', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'update_notify' => '1',
            'update_auto_install' => '1',
        ]);

        $location = (string)$response->location();
        $this->assertStringStartsWith('/admin/updates?error=', $location);
        $this->assertStringContainsString('Backup', urldecode($location));
        $this->assertSame('0', $this->setting('update_auto_install'));
    }

    /**
     * Gegenprobe: Die reine Benachrichtigung ohne Auto-Installation laesst
     * sich speichern. Ohne diesen Fall waere nicht zu unterscheiden, ob die
     * Route die Sperren durchsetzt oder schlicht nie etwas schreibt.
     */
    public function testNotificationAloneIsStoredAndCanBeSwitchedOffAgain(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->post('/admin/updates/automation', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'update_notify' => '1',
        ]);
        $this->assertSame('/admin/updates?automation_saved=1', $response->location());
        $this->assertSame('1', $this->setting('update_notify'));
        $this->assertSame('0', $this->setting('update_auto_install'));

        $aus = $admin->post('/admin/updates/automation', [
            'csrf_token' => $this->currentCsrfToken($admin),
        ]);
        $this->assertSame('/admin/updates?automation_saved=1', $aus->location());
        $this->assertSame('0', $this->setting('update_notify'));
    }

    /**
     * #319: Der Katalog-Abruf haengt nicht mehr am Seitenaufruf. Geprueft wird
     * die beobachtbare Seite davon - der ausdrueckliche Knopf ist da und die
     * Seite laedt ohne ihn.
     */
    public function testUpdatesPageOffersAnExplicitCatalogRefresh(): void {
        $admin = $this->authenticatedClient();

        $page = $admin->get('/admin/updates');
        $this->assertSame(200, $page->statusCode);
        $this->assertStringContainsString(
            '/admin/updates?refresh=1',
            $page->body,
            'Ohne ausdruecklichen Knopf gaebe es keinen Weg mehr, den Katalog sofort zu holen'
        );
    }

    private function setting(string $key): string {
        $stmt = \App\Database::getInstance()->prepare(
            'SELECT setting_value FROM settings WHERE setting_key = ?'
        );
        $stmt->execute([$key]);
        $wert = $stmt->fetchColumn();

        // Fehlender Schluessel bedeutet "nicht eingeschaltet" - fuer die
        // Zusicherungen oben derselbe Fall wie '0'.
        return $wert === false ? '0' : (string)$wert;
    }

    public function testRunIsRejectedWithoutConfiguredBackup(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->post('/admin/updates/run', [
            'csrf_token' => $this->currentCsrfToken($admin),
        ]);

        $location = (string)$response->location();
        $this->assertStringStartsWith('/admin/updates?error=', $location);
        $this->assertStringContainsString('Backups', urldecode($location));
    }
}
