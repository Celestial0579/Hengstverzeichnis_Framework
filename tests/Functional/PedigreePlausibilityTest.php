<?php
// tests/Functional/PedigreePlausibilityTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Widersprüche in der Abstammung werden beim Speichern abgelehnt (#298).
 *
 * Bis dahin prüfte nichts: Erlaubt waren ein Vater, der jünger ist als sein
 * Fohlen, und dasselbe Pferd als Vater UND Mutter. Im Altbestand der
 * Dev-Instanz stecken davon zwölf bzw. ein Fall; sie stammen aus der
 * Migration, hätten aber genauso über das Formular entstehen können.
 *
 * Die Schwelle gab es bereits, nur an der falschen Stelle - `autoLinkMatches()`
 * verknüpft Freitext-Eltern nur bei plausiblem Elternalter, beim manuellen
 * Setzen von `sire_id`/`dam_id` griff sie nicht.
 *
 * Geprüft werden beide Richtungen: Der Widerspruch wird abgelehnt, das
 * Zulässige bleibt zulässig. Die zweite Hälfte ist hier die heiklere - eine
 * Validierung, die auch richtige Eingaben abweist, wäre schlimmer als gar
 * keine.
 */
class PedigreePlausibilityTest extends FunctionalTestCase {

    public function testContradictoryPedigreeIsRefusedButValidOneIsKept(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        $vaterId = $this->anlegen($admin, "Vater {$unique}", 1990, 'stallion');
        $mutterId = $this->anlegen($admin, "Mutter {$unique}", 1992, 'mare');
        $jungerVaterId = $this->anlegen($admin, "Zu jung {$unique}", 2015, 'stallion');
        $ohneJahrId = $this->anlegen($admin, "Ohne Jahr {$unique}", null, 'stallion');

        // 1. Zulässig: Eltern deutlich älter - muss durchgehen.
        $fohlenId = $this->anlegen($admin, "Fohlen {$unique}", 2010, 'mare', [
            'sire_id' => (string)$vaterId,
            'dam_id' => (string)$mutterId,
        ]);
        $stmt = $db->prepare("SELECT sire_id, dam_id FROM horses WHERE id = ?");
        $stmt->execute([$fohlenId]);
        $row = $stmt->fetch();
        $this->assertSame($vaterId, (int)$row['sire_id'], 'Eine plausible Abstammung muss gespeichert werden');
        $this->assertSame($mutterId, (int)$row['dam_id']);

        $editPage = $admin->get('/admin/horses/edit?id=' . $fohlenId);
        $basis = [
            'csrf_token' => $editPage->formField('csrf_token') ?? '',
            'id' => (string)$fohlenId,
            'name' => "Fohlen {$unique}",
            'birth_year' => '2010',
            'status' => 'active',
        ];

        // 2. Vater jünger als das Fohlen - abgelehnt.
        $response = $admin->post('/admin/horses/update', $basis + ['sire_id' => (string)$jungerVaterId]);
        $this->assertSame('/admin/horses?error=sire_not_older', $response->location());

        // 3. Dasselbe Pferd als Vater und Mutter - abgelehnt.
        $response = $admin->post('/admin/horses/update', $basis + [
            'sire_id' => (string)$vaterId,
            'dam_id' => (string)$vaterId,
        ]);
        $this->assertSame('/admin/horses?error=same_sire_and_dam', $response->location());

        // 4. Mutter jünger - eigener Fehlercode, damit die Meldung sagt, welcher
        //    Elternteil gemeint ist.
        $spaeteMutterId = $this->anlegen($admin, "Spaete Mutter {$unique}", 2012, 'mare');
        $response = $admin->post('/admin/horses/update', $basis + ['dam_id' => (string)$spaeteMutterId]);
        $this->assertSame('/admin/horses?error=dam_not_older', $response->location());

        // Nach drei abgelehnten Versuchen steht die ursprüngliche Abstammung
        // unverändert da - eine Ablehnung darf nichts halb speichern.
        $stmt->execute([$fohlenId]);
        $row = $stmt->fetch();
        $this->assertSame($vaterId, (int)$row['sire_id']);
        $this->assertSame($mutterId, (int)$row['dam_id']);

        // 5. Elternteil OHNE Geburtsjahr: nicht prüfbar, also nicht ablehnen -
        //    dieselbe Regel wie im Auto-Linking.
        $response = $admin->post('/admin/horses/update', $basis + ['sire_id' => (string)$ohneJahrId]);
        $this->assertSame('/admin/horses?success=updated', $response->location(), 'Ohne Geburtsjahr ist nichts zu prüfen');

        // 6. Und das Fohlen selbst ohne Geburtsjahr ebenso.
        $response = $admin->post('/admin/horses/update', array_replace($basis, [
            'birth_year' => '',
            'sire_id' => (string)$jungerVaterId,
        ]));
        $this->assertSame('/admin/horses?success=updated', $response->location());
    }

    /**
     * Ein Wallach als Vater bleibt ausdrücklich erlaubt: Ein später
     * kastrierter Hengst wird als `gelding` geführt und hat trotzdem gedeckt.
     * In der Dev-Instanz sind das 80 Fälle - eine Ablehnung hätte reihenweise
     * korrekte Daten unspeicherbar gemacht.
     */
    public function testGeldingIsStillAcceptedAsSire(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        $wallachId = $this->anlegen($admin, "Wallach {$unique}", 1995, 'gelding');
        $fohlenId = $this->anlegen($admin, "Wallachfohlen {$unique}", 2005, 'mare', [
            'sire_id' => (string)$wallachId,
        ]);

        $stmt = $db->prepare("SELECT sire_id FROM horses WHERE id = ?");
        $stmt->execute([$fohlenId]);
        $this->assertSame($wallachId, (int)$stmt->fetchColumn());
    }

    /** @param array<string, string> $extra */
    private function anlegen(\Tests\Support\HttpClient $admin, string $name, ?int $jahr, string $sex, array $extra = []): int {
        $form = $admin->get('/admin/horses/create');
        $admin->post('/admin/horses/store', array_merge([
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $name,
            'birth_year' => $jahr === null ? '' : (string)$jahr,
            'sex' => $sex,
            'status' => 'active',
        ], $extra));

        $stmt = Database::getInstance()->prepare("SELECT id FROM horses WHERE name = ?");
        $stmt->execute([$name]);
        $id = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $id, "Pferd '{$name}' wurde nicht angelegt");
        return $id;
    }
}
