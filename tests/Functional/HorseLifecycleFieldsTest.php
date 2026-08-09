<?php
// tests/Functional/HorseLifecycleFieldsTest.php

namespace Tests\Functional;

use App\Database;

/**
 * HTTP-Funktionstests für den Stammdaten-Ausbau (#188): Geburtsdatum
 * (birth_date führend, birth_year abgeleitet), Stockmaß, Todesjahr und den
 * Status-Split (status = Zuchtstatus, is_deceased/death_year = Lebensstatus).
 *
 * Alles in einer Testmethode (analog HorseSexValidationTest): die Schritte
 * bauen aufeinander auf, und jede weitere authenticatedClient()-Sitzung
 * kostet einen vollen Login+2FA-Roundtrip.
 */
class HorseLifecycleFieldsTest extends FunctionalTestCase {

    public function testLifecycleFieldsRoundtrip(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $livingName = "Lifecycle Lebend {$unique}";
        $deceasedName = "Lifecycle Verstorben {$unique}";
        $db = Database::getInstance();

        $idByName = function (string $name) use ($db): int {
            $stmt = $db->prepare("SELECT id FROM horses WHERE name = ?");
            $stmt->execute([$name]);
            return (int)$stmt->fetchColumn();
        };

        // 1. Anlegen mit birth_date + abweichendem birth_year: das Datum ist
        // führend, das Jahr wird abgeleitet; height_cm wird gespeichert; ein
        // ungültiger status-POST fällt auf die Whitelist zurück ('active').
        $form = $admin->get('/admin/horses/create');
        $csrf = $form->formField('csrf_token') ?? '';
        $response = $admin->post('/admin/horses/store', [
            'csrf_token' => $csrf,
            'name' => $livingName,
            'birth_date' => '1994-06-13',
            'birth_year' => '2001',
            'height_cm' => '146',
            'status' => 'deceased',
            'is_published' => '1',
        ]);
        $this->assertSame('/admin/horses?success=created', $response->location());
        $livingId = $idByName($livingName);
        $this->assertGreaterThan(0, $livingId);

        $stmt = $db->prepare("SELECT birth_date, birth_year, height_cm, status, is_deceased, death_year FROM horses WHERE id = ?");
        $stmt->execute([$livingId]);
        $row = $stmt->fetch();
        $this->assertSame('1994-06-13', $row['birth_date']);
        $this->assertSame(1994, (int)$row['birth_year'], 'birth_year muss aus birth_date abgeleitet werden, der POST-Wert 2001 ist zu ignorieren');
        $this->assertSame(146, (int)$row['height_cm']);
        $this->assertSame('active', $row['status'], "Unbekannter status-Wert ('deceased' ist seit dem Split keiner mehr) muss auf 'active' zurückfallen");
        $this->assertSame(0, (int)$row['is_deceased']);
        $this->assertNull($row['death_year']);

        // 2. Todesjahr vor dem Geburtsjahr wird abgelehnt - kein Datensatz.
        $response = $admin->post('/admin/horses/store', [
            'csrf_token' => $csrf,
            'name' => $deceasedName,
            'birth_year' => '1994',
            'death_year' => '1990',
        ]);
        $this->assertSame('/admin/horses?error=death_before_birth', $response->location());
        $this->assertSame(0, $idByName($deceasedName), 'Abgelehnter Speichervorgang darf kein Pferd anlegen');

        // 3. Ein gesetztes Todesjahr impliziert is_deceased=1, auch ohne
        // angehakte Checkbox; der Zuchtstatus bleibt davon unabhängig 'active'.
        $response = $admin->post('/admin/horses/store', [
            'csrf_token' => $csrf,
            'name' => $deceasedName,
            'birth_year' => '1994',
            'death_year' => '2018',
            'status' => 'active',
            'is_published' => '1',
        ]);
        $this->assertSame('/admin/horses?success=created', $response->location());
        $deceasedId = $idByName($deceasedName);
        $stmt->execute([$deceasedId]);
        $row = $stmt->fetch();
        $this->assertSame(1, (int)$row['is_deceased'], 'death_year muss is_deceased implizieren');
        $this->assertSame(2018, (int)$row['death_year']);
        $this->assertSame('active', $row['status'], 'Lebens- und Zuchtstatus sind unabhängig: verstorben und dennoch aktiv geführt');

        // 3b. Bereichs-Normalisierung (Review-Befund): Werte jenseits der
        // Formulargrenzen (rein clientseitige min/max-Attribute) dürfen weder
        // in einen SMALLINT-Überlauf (500) laufen noch gespeichert werden -
        // sie werden serverseitig zu NULL, wie beim ungültigen birth_date.
        $outOfRangeName = "Lifecycle Ausreisser {$unique}";
        $response = $admin->post('/admin/horses/store', [
            'csrf_token' => $csrf,
            'name' => $outOfRangeName,
            'height_cm' => '66000',
            'death_year' => '66000',
        ]);
        $this->assertSame('/admin/horses?success=created', $response->location(), 'Out-of-Range-Werte dürfen keinen DB-Fehler auslösen');
        $stmt->execute([$idByName($outOfRangeName)]);
        $row = $stmt->fetch();
        $this->assertNull($row['height_cm'], 'Stockmaß außerhalb 50-250 muss zu NULL werden');
        $this->assertNull($row['death_year'], 'Todesjahr außerhalb 1600-Folgejahr muss zu NULL werden');
        $this->assertSame(0, (int)$row['is_deceased'], 'Ein verworfenes Todesjahr darf nicht als verstorben zählen');

        // 4. Update-Pfad: birth_date entfernen lässt birth_year direkt
        // editierbar; Verstorben-Checkbox ohne Jahr genügt.
        $editPage = $admin->get('/admin/horses/edit?id=' . $livingId);
        $response = $admin->post('/admin/horses/update', [
            'csrf_token' => $editPage->formField('csrf_token') ?? '',
            'id' => (string)$livingId,
            'name' => $livingName,
            'birth_date' => '',
            'birth_year' => '1995',
            'is_deceased' => '1',
            'status' => 'inactive',
            'is_published' => '1',
        ]);
        $this->assertSame('/admin/horses?success=updated', $response->location());
        $stmt->execute([$livingId]);
        $row = $stmt->fetch();
        $this->assertNull($row['birth_date']);
        $this->assertSame(1995, (int)$row['birth_year']);
        $this->assertSame(1, (int)$row['is_deceased']);
        $this->assertNull($row['death_year']);
        $this->assertSame('inactive', $row['status']);

        // Für die öffentlichen Prüfungen unten das erste Pferd wieder auf
        // "lebend mit vollem Datum" zurücksetzen.
        $admin->post('/admin/horses/update', [
            'csrf_token' => $editPage->formField('csrf_token') ?? '',
            'id' => (string)$livingId,
            'name' => $livingName,
            'birth_date' => '1994-06-13',
            'height_cm' => '146',
            'status' => 'active',
            'is_published' => '1',
        ]);

        // 5. Öffentliche Detailseite: volles Datum (de-Format), Stockmaß und
        // beim verstorbenen Pferd das ✝-Badge mit Todesjahr.
        $guest = $this->newClient();
        $detail = $guest->get('/horse?id=' . $livingId);
        $this->assertSame(200, $detail->statusCode);
        $this->assertStringContainsString('13.06.1994', $detail->body);
        $this->assertStringContainsString('146 cm', $detail->body);
        $this->assertStringNotContainsString('✝', $detail->body);

        $detail = $guest->get('/horse?id=' . $deceasedId);
        $this->assertStringContainsString('✝', $detail->body);
        $this->assertStringContainsString('2018', $detail->body);

        // 6. Katalogfilter (AJAX): q_status=deceased mappt auf is_deceased,
        // q_status=active filtert den Zuchtstatus. search=$unique isoliert
        // von fremden Testdaten (geteilte DB).
        $filtered = $guest->get('/katalog?ajax=1&search=' . urlencode($unique) . '&q_status=deceased');
        $this->assertSame(200, $filtered->statusCode);
        $payload = json_decode($filtered->body, true);
        $this->assertSame(1, $payload['count'], 'q_status=deceased sollte genau das verstorbene Pferd finden');
        $this->assertStringContainsString(htmlspecialchars($deceasedName), $payload['cards_html']);

        $filtered = $guest->get('/katalog?ajax=1&search=' . urlencode($unique) . '&q_status=active');
        $payload = json_decode($filtered->body, true);
        $this->assertSame(2, $payload['count'], 'q_status=active ist der Zuchtstatus und umfasst auch das verstorbene, aktiv geführte Pferd');
    }
}
