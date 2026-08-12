<?php
// tests/Functional/HorseRegistrationsTest.php

namespace Tests\Functional;

use App\Database;

/**
 * HTTP-Funktionstests für die weiteren Lebensnummern (#246,
 * horse_registrations): Pflege über das Admin-Formular (Normalisierung,
 * Ersetzen, Leeren, "nicht übermittelt"-Schutz), das unangetastete
 * foreign_ueln-Kompatibilitätsfeld, die öffentliche Anzeige samt Fallback
 * und die Katalog-Suche über die neuen Nummern.
 *
 * Alles in einer Testmethode (analog HorseLifecycleFieldsTest): die Schritte
 * bauen aufeinander auf, und jede weitere authenticatedClient()-Sitzung
 * kostet einen vollen Login+2FA-Roundtrip.
 */
class HorseRegistrationsTest extends FunctionalTestCase {

    public function testRegistrationsRoundtrip(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $name = "Registrierung Mehrfach {$unique}";
        $db = Database::getInstance();

        $registrationsOf = function (int $horseId) use ($db): array {
            $stmt = $db->prepare("SELECT registration_number FROM horse_registrations WHERE horse_id = ? ORDER BY sort_order ASC, id ASC");
            $stmt->execute([$horseId]);
            return $stmt->fetchAll(\PDO::FETCH_COLUMN);
        };

        // 1. Anlegen mit Normalisierung: trimmen, Leereinträge, Duplikate
        // (case-insensitiv), Überlängen (> 50 Zeichen) und die Primärnummer
        // (ueln) werden verworfen; die Reihenfolge bleibt stabil.
        $form = $admin->get('/admin/horses/create');
        $csrf = $form->formField('csrf_token') ?? '';
        $response = $admin->post('/admin/horses/store', [
            'csrf_token' => $csrf,
            'name' => $name,
            'ueln' => "DE 456 {$unique}",
            'registrations_present' => '1',
            'registrations' => [
                "  NOR 111 {$unique}  ",
                '',
                "nor 111 {$unique}",
                "DE 456 {$unique}",
                str_repeat('X', 60),
                "SWE 222 {$unique}",
            ],
            'status' => 'active',
            'is_published' => '1',
        ]);
        $this->assertSame('/admin/horses?success=created', $response->location());

        $stmt = $db->prepare("SELECT id, foreign_ueln FROM horses WHERE name = ?");
        $stmt->execute([$name]);
        $horse = $stmt->fetch();
        $horseId = (int)$horse['id'];
        $this->assertGreaterThan(0, $horseId);
        $this->assertSame(["NOR 111 {$unique}", "SWE 222 {$unique}"], $registrationsOf($horseId));
        $this->assertNull($horse['foreign_ueln'], 'Das Formular befüllt foreign_ueln seit #246 nicht mehr');

        // 2. Das Bearbeiten-Formular zeigt die Nummern zur Pflege an.
        $editPage = $admin->get('/admin/horses/edit?id=' . $horseId);
        $this->assertStringContainsString("NOR 111 {$unique}", $editPage->body);
        $this->assertStringContainsString("SWE 222 {$unique}", $editPage->body);
        $editCsrf = $editPage->formField('csrf_token') ?? '';

        // Kompatibilitätsfeld für die folgenden Schritte direkt befüllen
        // (der reguläre Weg dafür ist der CSV-Import).
        $db->prepare("UPDATE horses SET foreign_ueln = ? WHERE id = ?")->execute(["ALT 999 {$unique}", $horseId]);

        // 3. Update ersetzt die Liste vollständig (add/remove) - und lässt
        // das nicht übermittelte foreign_ueln-Feld unangetastet.
        $response = $admin->post('/admin/horses/update', [
            'csrf_token' => $editCsrf,
            'id' => (string)$horseId,
            'name' => $name,
            'ueln' => "DE 456 {$unique}",
            'registrations_present' => '1',
            'registrations' => ["DK 333 {$unique}", "NOR 111 {$unique}"],
            'status' => 'active',
            'is_published' => '1',
        ]);
        $this->assertSame('/admin/horses?success=updated', $response->location());
        $this->assertSame(["DK 333 {$unique}", "NOR 111 {$unique}"], $registrationsOf($horseId));

        $stmt = $db->prepare("SELECT foreign_ueln FROM horses WHERE id = ?");
        $stmt->execute([$horseId]);
        $this->assertSame("ALT 999 {$unique}", $stmt->fetchColumn(), 'Ein normaler Formular-Edit darf das foreign_ueln-Kompatibilitätsfeld nicht nullen');

        // 4. Ein POST OHNE registrations-Schlüssel und ohne Marker (z. B. ein
        // Skript, das das Feature nicht kennt) lässt den Bestand unangetastet -
        // analog zum breeding_station-COALESCE (#214).
        $response = $admin->post('/admin/horses/update', [
            'csrf_token' => $editCsrf,
            'id' => (string)$horseId,
            'name' => $name,
            'ueln' => "DE 456 {$unique}",
            'status' => 'active',
            'is_published' => '1',
        ]);
        $this->assertSame('/admin/horses?success=updated', $response->location());
        $this->assertSame(["DK 333 {$unique}", "NOR 111 {$unique}"], $registrationsOf($horseId), 'Ohne übermittelten registrations-Block muss der Bestand erhalten bleiben');

        // 5. Öffentliche Detailseite zeigt die weiteren Lebensnummern aus der
        // Kindtabelle; das (befüllte) foreign_ueln-Feld tritt dahinter zurück.
        $guest = $this->newClient();
        $detail = $guest->get('/horse?id=' . $horseId);
        $this->assertSame(200, $detail->statusCode);
        $this->assertStringContainsString('Weitere Lebensnummern', $detail->body);
        $this->assertStringContainsString("DK 333 {$unique}", $detail->body);
        $this->assertStringContainsString("NOR 111 {$unique}", $detail->body);
        $this->assertStringNotContainsString("ALT 999 {$unique}", $detail->body, 'Bei vorhandenen Kindzeilen zeigt die Seite nicht zusätzlich das Kompatibilitätsfeld');

        // 6. Katalog-Suche (AJAX) findet das Pferd über eine weitere
        // Lebensnummer - sowohl über die allgemeine Suche als auch über das
        // UELN-Suchfeld.
        $filtered = $guest->get('/katalog?ajax=1&search=' . urlencode("DK 333 {$unique}"));
        $this->assertSame(200, $filtered->statusCode);
        $payload = json_decode($filtered->body, true);
        $this->assertSame(1, $payload['count'], 'Die allgemeine Suche muss das Pferd über die Registriernummer finden');
        $this->assertStringContainsString(htmlspecialchars($name), $payload['cards_html']);

        $filtered = $guest->get('/katalog?ajax=1&q_ueln=' . urlencode("DK 333 {$unique}"));
        $payload = json_decode($filtered->body, true);
        $this->assertSame(1, $payload['count'], 'Das UELN-Suchfeld muss das Pferd über die Registriernummer finden');

        // 7. Fallback der Anzeige: Ein Pferd OHNE Kindzeilen, aber mit
        // befülltem foreign_ueln (Bestand/CSV-Import) zeigt weiterhin das Feld.
        $fallbackName = "Registrierung Fallback {$unique}";
        $response = $admin->post('/admin/horses/store', [
            'csrf_token' => $csrf,
            'name' => $fallbackName,
            'status' => 'active',
            'is_published' => '1',
        ]);
        $this->assertSame('/admin/horses?success=created', $response->location());
        $stmt = $db->prepare("SELECT id FROM horses WHERE name = ?");
        $stmt->execute([$fallbackName]);
        $fallbackId = (int)$stmt->fetchColumn();
        $db->prepare("UPDATE horses SET foreign_ueln = ? WHERE id = ?")->execute(["FIN 777 {$unique}", $fallbackId]);

        $detail = $guest->get('/horse?id=' . $fallbackId);
        $this->assertSame(200, $detail->statusCode);
        $this->assertStringContainsString('Lebensnummer Ursprungsland', $detail->body);
        $this->assertStringContainsString("FIN 777 {$unique}", $detail->body);

        // 8. Leeren: Marker ohne einen einzigen registrations-Eintrag (alle
        // Zeilen im Formular entfernt) löscht den Bestand bewusst.
        $response = $admin->post('/admin/horses/update', [
            'csrf_token' => $editCsrf,
            'id' => (string)$horseId,
            'name' => $name,
            'ueln' => "DE 456 {$unique}",
            'registrations_present' => '1',
            'status' => 'active',
            'is_published' => '1',
        ]);
        $this->assertSame('/admin/horses?success=updated', $response->location());
        $this->assertSame([], $registrationsOf($horseId), 'Der Marker registrations_present muss eine komplett geleerte Liste speichern');
    }
}
