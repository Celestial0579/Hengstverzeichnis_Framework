<?php
// tests/Functional/DigestSettingsTest.php

namespace Tests\Functional;

/**
 * HTTP-Funktionstests für die E-Mail-Digest-Verwaltung (#52,
 * src/Controllers/AdminController.php: digestSettings()/
 * updateDigestSettings()/testDigest()): Admin-Pflicht, CSRF-Schutz und dass
 * gespeicherte Einstellungen auf der Seite wieder auftauchen. Der manuelle
 * "Jetzt prüfen"-Button meldet in dieser Testumgebung ohne offene
 * Match-Vorschläge/Papierkorb-Einträge und ohne konfigurierten SMTP stets
 * "nichts zu berichten" (siehe tests/Integration/DigestServiceTest.php für
 * die eigentliche Zähl-/Versandlogik).
 */
class DigestSettingsTest extends FunctionalTestCase {

    public function testDigestSettingsPageRequiresAdmin(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $editor = $this->createAndLoginEditor($admin, "digesttester{$unique}", "digest-test-{$unique}@example.com");

        $response = $editor->get('/admin/digest');
        $this->assertSame(403, $response->statusCode);
    }

    public function testDigestSettingsPageIsReachableForAdmin(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->get('/admin/digest');
        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('E-Mail-Digest', $response->body);
    }

    public function testUpdateDigestSettingsRequiresCsrfToken(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->post('/admin/digest', ['digest_enabled' => '1']);
        $this->assertSame(403, $response->statusCode);
    }

    public function testSavedSettingsAreReflectedOnTheSettingsPage(): void {
        $admin = $this->authenticatedClient();

        $formPage = $admin->get('/admin/digest');
        $response = $admin->post('/admin/digest', [
            'csrf_token' => $formPage->formField('csrf_token') ?? '',
            'digest_enabled' => '1',
            'digest_interval_hours' => '8',
            'digest_recipient_groups' => ['admin', 'editor'],
        ]);
        $this->assertSame('/admin/digest?success=1', $response->location());

        $settingsPage = $admin->get('/admin/digest');
        $this->assertSame('8', $settingsPage->formField('digest_interval_hours'));
    }

    /**
     * Empfängergruppen sind wählbar: Die Auswahl wird gespeichert und auf
     * der Seite als angehakte Checkboxen reflektiert; ohne jede Gruppe wird
     * das Speichern abgelehnt (ein leerer Verteiler fiele sonst erst beim
     * nächsten fehlgeschlagenen Lauf auf).
     */
    public function testRecipientGroupsAreSelectableAndAtLeastOneIsRequired(): void {
        $admin = $this->authenticatedClient();

        $formPage = $admin->get('/admin/digest');
        // Standard (ohne gespeicherte Auswahl): admin + editor angehakt.
        $this->assertMatchesRegularExpression('/value="admin"\s+checked/', $formPage->body);
        $this->assertMatchesRegularExpression('/value="editor"\s+checked/', $formPage->body);

        // Nur admin auswählen.
        $response = $admin->post('/admin/digest', [
            'csrf_token' => $formPage->formField('csrf_token') ?? '',
            'digest_enabled' => '0',
            'digest_interval_hours' => '24',
            'digest_recipient_groups' => ['admin', 'gibt-es-nicht'],
        ]);
        $this->assertSame('/admin/digest?success=1', $response->location());

        $reloaded = $admin->get('/admin/digest');
        $this->assertMatchesRegularExpression('/value="admin"\s+checked/', $reloaded->body);
        $this->assertDoesNotMatchRegularExpression('/value="editor"\s+checked/', $reloaded->body);

        // Der unbekannte Slug darf nicht durchgerutscht sein - und das lässt
        // sich AN DER SEITE nicht ablesen: Die Kästchen entstehen aus der
        // groups-Tabelle, ein Slug ohne Gruppe rendert gar kein Kästchen.
        // Ohne diese Zeile wäre der Test auch dann grün, wenn die
        // Positivliste in AdminController::updateDigestSettings() wegfiele
        // und 'admin,gibt-es-nicht' gespeichert würde.
        $this->assertSame(
            'admin',
            $this->setting('digest_recipient_groups'),
            'Ein unbekannter Gruppen-Slug muss serverseitig herausgefiltert werden, nicht nur unsichtbar bleiben'
        );

        // Und der Fall, der ohne die Positivliste zum stillen leeren
        // Verteiler führt: AUSSCHLIESSLICH unbekannte Slugs. Nach dem Filtern
        // bleibt nichts übrig, das muss abgelehnt werden.
        $nurUnbekannt = $admin->post('/admin/digest', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'digest_enabled' => '0',
            'digest_interval_hours' => '24',
            'digest_recipient_groups' => ['gibt-es-nicht', 'auch-nicht'],
        ]);
        $this->assertSame('/admin/digest?error=no_recipient_groups', $nurUnbekannt->location());
        $this->assertSame('admin', $this->setting('digest_recipient_groups'), 'Die abgelehnte Eingabe darf den Bestand nicht überschreiben');

        // Leere Auswahl wird abgelehnt.
        $rejected = $admin->post('/admin/digest', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'digest_enabled' => '0',
            'digest_interval_hours' => '24',
        ]);
        $this->assertSame('/admin/digest?error=no_recipient_groups', $rejected->location());

        // Aufräumen: zurück auf Standard, damit Folge-Tests unbeeinflusst sind.
        $admin->post('/admin/digest', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'digest_enabled' => '0',
            'digest_interval_hours' => '24',
            'digest_recipient_groups' => ['admin', 'editor'],
        ]);
    }

    public function testTestDigestRequiresCsrfToken(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->post('/admin/digest/test', []);
        $this->assertSame(403, $response->statusCode);
    }

    public function testTestDigestWithNothingToReportRedirectsAsSkipped(): void {
        $admin = $this->authenticatedClient();

        $response = $admin->post('/admin/digest/test', [
            'csrf_token' => $this->currentCsrfToken($admin),
        ]);

        $this->assertSame('/admin/digest?success=digest_skipped', $response->location());
    }

    /**
     * Liest einen gespeicherten Wert direkt aus der Datenbank.
     *
     * Nötig, weil die Ansicht ihre Kästchen aus der `groups`-Tabelle baut: Ein
     * gespeicherter Slug ohne zugehörige Gruppe erzeugt dort gar kein Element
     * und ist am HTML nicht zu erkennen.
     */
    private function setting(string $schluessel): ?string {
        $stmt = \App\Database::getInstance()->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute([$schluessel]);
        $wert = $stmt->fetchColumn();
        return $wert === false ? null : (string)$wert;
    }
}
