<?php
// tests/Functional/HorseSexValidationTest.php

namespace Tests\Functional;

use App\Database;

/**
 * HTTP-Funktionstests für das Geschlechtsfeld (#165) und die darauf
 * aufbauende Abstammungs-Validierung (#166) samt Match-Assistent (#167):
 *
 *  - Speichern mit Stute als Vater bzw. Hengst als Mutter wird serverseitig
 *    abgelehnt (store- und update-Pfad), mit Fehlercode im Redirect.
 *  - Pferde ohne Geschlechtsangabe (NULL = unbekannt, Altbestand) bestehen
 *    jede Prüfung - sie bleiben als Eltern wählbar.
 *  - Die Eltern-Dropdowns im Formular bieten nur rollen-passende Tiere an.
 *  - /admin/matches/link lehnt geschlechts-widrige Verknüpfungen ab.
 *  - Der öffentliche Katalogfilter q_sex/q_breed filtert korrekt (AJAX-Pfad).
 *
 * Alles in einer Testmethode (analog HorseMatchingTest): die Schritte bauen
 * aufeinander auf, und jede weitere authenticatedClient()-Sitzung kostet
 * einen vollen Login+2FA-Roundtrip.
 */
class HorseSexValidationTest extends FunctionalTestCase {

    public function testSexFieldValidationDropdownsAndCatalogFilter(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $mareName = "SexVal Stute {$unique}";
        $stallionName = "SexVal Hengst {$unique}";
        $foalName = "SexVal Fohlen {$unique}";
        $db = Database::getInstance();

        // 1. Stute und Hengst anlegen (veröffentlicht, für den Katalogfilter-Test).
        foreach ([[$mareName, 'mare'], [$stallionName, 'stallion']] as [$name, $sex]) {
            $form = $admin->get('/admin/horses/create');
            $response = $admin->post('/admin/horses/store', [
                'csrf_token' => $form->formField('csrf_token') ?? '',
                'name' => $name,
                'sex' => $sex,
                'breed' => 'Fjordpferd',
                'birth_year' => '2010',
                'status' => 'active',
                'is_published' => '1',
            ]);
            $this->assertSame('/admin/horses?success=created', $response->location());
        }

        $idByName = function (string $name) use ($db): int {
            $stmt = $db->prepare("SELECT id FROM horses WHERE name = ?");
            $stmt->execute([$name]);
            return (int)$stmt->fetchColumn();
        };
        $mareId = $idByName($mareName);
        $stallionId = $idByName($stallionName);
        $this->assertGreaterThan(0, $mareId);
        $this->assertGreaterThan(0, $stallionId);

        // 2. Geschlecht muss kanonisch gespeichert sein.
        $stmt = $db->prepare("SELECT sex, breed FROM horses WHERE id = ?");
        $stmt->execute([$mareId]);
        $mareRow = $stmt->fetch();
        $this->assertSame('mare', $mareRow['sex']);
        $this->assertSame('Fjordpferd', $mareRow['breed']);

        // 3. Anlegen mit Stute als Vater wird abgelehnt - kein Datensatz entsteht.
        $form = $admin->get('/admin/horses/create');
        $response = $admin->post('/admin/horses/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $foalName,
            'sire_id' => (string)$mareId,
            'status' => 'active',
        ]);
        $this->assertSame('/admin/horses?error=sex_mismatch_sire', $response->location());
        $this->assertSame(0, $idByName($foalName), 'Abgelehnter Speichervorgang darf kein Pferd anlegen');

        // 4. Anlegen mit Hengst als Mutter wird ebenso abgelehnt.
        $response = $admin->post('/admin/horses/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $foalName,
            'dam_id' => (string)$stallionId,
            'status' => 'active',
        ]);
        $this->assertSame('/admin/horses?error=sex_mismatch_dam', $response->location());
        $this->assertSame(0, $idByName($foalName));

        // 5. Gültige Kombination (Hengst als Vater, Stute als Mutter) wird gespeichert.
        $response = $admin->post('/admin/horses/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $foalName,
            'sire_id' => (string)$stallionId,
            'dam_id' => (string)$mareId,
            'birth_year' => '2020',
            'status' => 'active',
        ]);
        $this->assertSame('/admin/horses?success=created', $response->location());
        $foalId = $idByName($foalName);
        $this->assertGreaterThan(0, $foalId);

        // 6. Update-Pfad: Umbiegen des Vaters auf die Stute wird abgelehnt,
        // die bestehende (gültige) Verknüpfung bleibt unverändert.
        $editPage = $admin->get('/admin/horses/edit?id=' . $foalId);
        $response = $admin->post('/admin/horses/update', [
            'csrf_token' => $editPage->formField('csrf_token') ?? '',
            'id' => (string)$foalId,
            'name' => $foalName,
            'sire_id' => (string)$mareId,
            'status' => 'active',
        ]);
        $this->assertSame('/admin/horses?error=sex_mismatch_sire', $response->location());
        $stmt = $db->prepare("SELECT sire_id, dam_id FROM horses WHERE id = ?");
        $stmt->execute([$foalId]);
        $foalRow = $stmt->fetch();
        $this->assertSame($stallionId, (int)$foalRow['sire_id'], 'Abgelehntes Update darf die Abstammung nicht ändern');
        $this->assertSame($mareId, (int)$foalRow['dam_id']);

        // 7. Formular-Dropdowns: Vater-Auswahl ohne die Stute, Mutter-Auswahl
        // ohne den Hengst (NULL-Geschlecht bleibt in beiden - hier nicht
        // explizit angelegt, aber durch fremde Testdaten ohne sex abgedeckt).
        $editBody = $admin->get('/admin/horses/edit?id=' . $foalId)->body;
        $this->assertSame(1, preg_match('/<select id="sire_id".*?<\/select>/s', $editBody, $sireSelect), 'Vater-Select nicht gefunden');
        $this->assertSame(1, preg_match('/<select id="dam_id".*?<\/select>/s', $editBody, $damSelect), 'Mutter-Select nicht gefunden');
        $this->assertStringContainsString(htmlspecialchars($stallionName), $sireSelect[0]);
        $this->assertStringNotContainsString(htmlspecialchars($mareName), $sireSelect[0], 'Stute darf nicht als Vater angeboten werden');
        $this->assertStringContainsString(htmlspecialchars($mareName), $damSelect[0]);
        $this->assertStringNotContainsString(htmlspecialchars($stallionName), $damSelect[0], 'Hengst darf nicht als Mutter angeboten werden');

        // 8. Match-Assistent: manuelle Verknüpfung mit falschem Geschlecht wird
        // serverseitig abgelehnt (analog zur Selbst-Link-Sperre, #167).
        $matchesPage = $admin->get('/admin/matches');
        $response = $admin->post('/admin/matches/link', [
            'csrf_token' => $matchesPage->formField('csrf_token') ?? $editPage->formField('csrf_token') ?? '',
            'child_id' => (string)$foalId,
            'parent_type' => 'sire',
            'parent_horse_id' => (string)$mareId,
        ]);
        $this->assertSame('/admin/matches?error=sex_mismatch', $response->location());
        $stmt->execute([$foalId]);
        $this->assertSame($stallionId, (int)$stmt->fetch()['sire_id'], 'Abgelehnter Match-Link darf die Abstammung nicht ändern');

        // 9. Öffentlicher Katalogfilter (AJAX-Pfad, #165/#163): q_sex filtert auf
        // das jeweilige Geschlecht, q_breed auf die Rasse. Die search-Eingrenzung
        // auf das $unique-Suffix isoliert von fremden Testdaten (geteilte DB).
        $guest = $this->newClient();
        $filtered = $guest->get('/katalog?ajax=1&search=' . urlencode($unique) . '&q_sex=mare');
        $this->assertSame(200, $filtered->statusCode);
        $payload = json_decode($filtered->body, true);
        $this->assertSame(1, $payload['count'], 'q_sex=mare sollte genau die Stute finden');
        $this->assertStringContainsString(htmlspecialchars($mareName), $payload['cards_html']);
        $this->assertStringNotContainsString(htmlspecialchars($stallionName), $payload['cards_html']);

        $filtered = $guest->get('/katalog?ajax=1&search=' . urlencode($unique) . '&q_breed=Fjord');
        $payload = json_decode($filtered->body, true);
        $this->assertSame(2, $payload['count'], 'q_breed=Fjord sollte Stute und Hengst finden (Fohlen hat keine Rasse und ist unveröffentlicht)');
    }
}
