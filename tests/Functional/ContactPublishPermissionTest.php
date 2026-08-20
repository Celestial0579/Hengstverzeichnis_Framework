<?php
// tests/Functional/ContactPublishPermissionTest.php

namespace Tests\Functional;

use App\Database;
use Tests\Support\HttpClient;

/**
 * Die Massen-Veröffentlichung von Kontakten und das Recht, das sie schützt
 * (#374).
 *
 * WARUM DAS EINEN EIGENEN TEST BRAUCHT. POST /admin/contacts/publish schaltet
 * beliebig viele Kontaktdatensätze in einem Rutsch öffentlich. Kontakte tragen
 * seit der Zusammenlegung (#336) Name, Ansprechpartner, Straße, PLZ, Ort,
 * E-Mail, Telefon und Mobil - personenbezogene Daten natürlicher Personen.
 * Geschützt war der Endpunkt allein durch eine Zeile requirePermission() und
 * den CSRF-Check, und keine dieser Zusicherungen wurde von einem Test
 * gehalten: Fiele die Zeile bei einem Umbau weg oder würde sie durch das
 * nicht abbrechende hasPermission() ersetzt (beide Methoden stehen in der
 * Datei dicht beieinander), veröffentlichte jeder Benutzer mit reinem
 * contacts.view per direktem POST den kompletten Bestand - einschließlich der
 * Datensätze, die nach einem DSGVO-Widerspruch bewusst depubliziert wurden.
 *
 * Geprüft wird deshalb nicht nur der Statuscode, sondern datenbankseitig, dass
 * ein abgelehnter Aufruf auch WIRKLICH nichts geschrieben hat.
 */
class ContactPublishPermissionTest extends FunctionalTestCase {

    public function testBulkPublishIsRefusedWithoutThePublishPermission(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $u = uniqid();

        $kontaktId = $this->kontakt($admin, "Freigabe Kontakt {$u}");
        $this->setzePubliziert($db, $kontaktId, 0);

        $gruppe = $this->createCustomGroup($admin, "Kontakte ohne Freigaberecht {$u}");
        // Bewusst ohne 'publish' - alles andere darf der Benutzer.
        $this->setGroupPermissions($admin, $gruppe, ['contacts' => ['view', 'create', 'edit']]);
        $redakteur = $this->createAndLoginEditor($admin, "pubtest{$u}", "pub-{$u}@example.com", [$gruppe]);

        $liste = $redakteur->get('/admin/contacts');
        $this->assertSame(200, $liste->statusCode, 'Die Liste ist mit contacts.view erreichbar - sonst prüft der POST die falsche Hürde');

        $antwort = $redakteur->post('/admin/contacts/publish', [
            'csrf_token' => $this->tokenFuer($redakteur),
            'ids' => [(string)$kontaktId],
            'publish' => '1',
        ]);

        $this->assertSame(403, $antwort->statusCode, "Erwartet wurde 403, Body: {$antwort->body}");
        $this->assertSame(0, $this->istPubliziert($db, $kontaktId), 'Ein abgelehnter Massen-Publish darf auch nichts geschrieben haben');
    }

    /** Die Gegenprobe: Mit dem Recht geht es durch - sonst prüft der Test oben nur, dass irgendetwas 403 liefert. */
    public function testBulkPublishWorksWithThePermission(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $u = uniqid();

        $kontaktId = $this->kontakt($admin, "Freigabe erlaubt {$u}");
        $this->setzePubliziert($db, $kontaktId, 0);

        $antwort = $admin->post('/admin/contacts/publish', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'ids' => [(string)$kontaktId],
            'publish' => '1',
        ]);

        $this->assertStringContainsString('success=published', (string)$antwort->location());
        $this->assertSame(1, $this->istPubliziert($db, $kontaktId));
    }

    public function testBulkPublishRequiresCsrfToken(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $u = uniqid();

        $kontaktId = $this->kontakt($admin, "Freigabe CSRF {$u}");
        $this->setzePubliziert($db, $kontaktId, 0);

        $antwort = $admin->post('/admin/contacts/publish', [
            'ids' => [(string)$kontaktId],
            'publish' => '1',
        ]);

        $this->assertSame(403, $antwort->statusCode);
        $this->assertSame(0, $this->istPubliziert($db, $kontaktId));
    }

    /**
     * Die zweite Hälfte derselben Zusicherung: store() und update() lassen
     * is_published ohne das Recht still auf 0 fallen, statt abzubrechen. Das
     * ist Absicht (ein Redakteur soll Kontakte anlegen dürfen), aber es muss
     * auch stimmen.
     */
    public function testCreatingAContactCannotPublishItWithoutThePermission(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $u = uniqid();

        $gruppe = $this->createCustomGroup($admin, "Kontakte anlegen ohne Freigabe {$u}");
        $this->setGroupPermissions($admin, $gruppe, ['contacts' => ['view', 'create', 'edit']]);
        $redakteur = $this->createAndLoginEditor($admin, "pubstore{$u}", "pubstore-{$u}@example.com", [$gruppe]);

        $name = "Heimlich veroeffentlicht {$u}";
        $form = $redakteur->get('/admin/contacts/create');
        $this->assertSame(200, $form->statusCode);
        $redakteur->post('/admin/contacts/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $name,
            'is_published' => '1',   // wird ohne das Recht ignoriert
        ]);

        $stmt = $db->prepare('SELECT is_published FROM contacts WHERE name = ?');
        $stmt->execute([$name]);
        $wert = $stmt->fetchColumn();

        $this->assertNotFalse($wert, 'Der Kontakt muss angelegt worden sein - abgelehnt wird nur die Freigabe');
        $this->assertSame(0, (int)$wert, 'is_published=1 darf ohne contacts.publish nicht durchkommen');
    }

    // ---- Helfer --------------------------------------------------------

    /**
     * CSRF-Token aus einer Seite, die der Redakteur auch sehen DARF.
     *
     * currentCsrfToken() der Basisklasse holt es von /admin/users/create -
     * dafür braucht es das Recht `users`, das ein Redakteur nicht hat. Er
     * bekäme dort 403 und damit ein LEERES Token; der anschließende POST
     * scheiterte dann am CSRF-Check, und der Test bestünde aus dem falschen
     * Grund: Er behauptete, die Rechteprüfung greife, hätte sie aber nie
     * erreicht. Genau so ist es hier beim Schreiben dieses Tests passiert -
     * aufgefallen erst in der Gegenprobe, als die Rechteprüfung entfernt
     * wurde und der Test trotzdem grün blieb.
     */
    private function tokenFuer(HttpClient $client): string {
        $seite = $client->get('/admin/contacts/create');
        $this->assertSame(200, $seite->statusCode, 'Die Token-Quelle muss für diesen Benutzer erreichbar sein');
        $token = $seite->formField('csrf_token') ?? '';
        $this->assertNotSame('', $token, 'Ohne gültiges Token prüft der POST nur den CSRF-Zweig');
        return $token;
    }

    private function kontakt(HttpClient $admin, string $name): int {
        $form = $admin->get('/admin/contacts/create');
        $admin->post('/admin/contacts/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $name,
        ]);
        $stmt = Database::getInstance()->prepare('SELECT id FROM contacts WHERE name = ?');
        $stmt->execute([$name]);
        $id = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $id, "Kontakt '{$name}' wurde nicht angelegt");
        return $id;
    }

    private function setzePubliziert(\PDO $db, int $id, int $wert): void {
        $db->prepare('UPDATE contacts SET is_published = ? WHERE id = ?')->execute([$wert, $id]);
    }

    private function istPubliziert(\PDO $db, int $id): int {
        $stmt = $db->prepare('SELECT is_published FROM contacts WHERE id = ?');
        $stmt->execute([$id]);
        return (int)$stmt->fetchColumn();
    }

    private function createCustomGroup(HttpClient $admin, string $name): int {
        $groupsPage = $admin->get('/admin/groups');
        $response = $admin->post('/admin/groups/create', [
            'csrf_token' => $groupsPage->formField('csrf_token') ?? '',
            'name' => $name,
        ]);
        preg_match('/group=(\d+)/', (string)$response->location(), $matches);
        $this->assertNotEmpty($matches, "Konnte neue Gruppen-ID nicht ermitteln, Body: {$response->body}");
        return (int)$matches[1];
    }
}
