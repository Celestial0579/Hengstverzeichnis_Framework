<?php
// tests/Functional/MatchLabelTest.php

namespace Tests\Functional;

use App\Database;
use Tests\Support\HttpClient;

/**
 * POST /admin/matches/label und seine art-abhängige Rechteprüfung (#355,
 * Testlücke gefunden als #376).
 *
 * WARUM DIESE ROUTE BESONDERS IST. Das verlangte Recht steht nicht fest,
 * sondern hängt vom POST-Feld `art` ab:
 *
 *     $this->requirePermission($art === 'horse' ? 'horses' : 'contacts', 'edit');
 *
 * Weil `art` aus der Anfrage stammt und über das Recht entscheidet, wäre eine
 * falsche Zuordnung dieser Abbildung ein stiller Rechtebruch - etwa wenn
 * MatchLabel::ARTEN später um eine dritte Art wächst, die dann in den
 * contacts-Zweig fiele. Bis #376 prüfte das nichts: weder die Route noch der
 * Dienst MatchLabel hatten einen einzigen Test.
 *
 * Und die Wirkung ist keine Kleinigkeit: Ein gesetztes Label unterdrückt ein
 * Dublettenpaar DAUERHAFT aus der Vorschlagsliste - genau der Liste, über die
 * DSGVO-Löschanfragen ihre Personen finden.
 */
class MatchLabelTest extends FunctionalTestCase {

    public function testHorsePermissionDoesNotAllowLabellingContacts(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $u = uniqid();

        $a = $this->kontakt($admin, "Label Kontakt A {$u}");
        $b = $this->kontakt($admin, "Label Kontakt B {$u}");

        $gruppe = $this->createCustomGroup($admin, "Pferde ja, Kontakte nur lesen {$u}");
        $this->setGroupPermissions($admin, $gruppe, [
            'horses' => ['view', 'edit'],
            // 'create' nur, damit /admin/contacts/create als Token-Quelle
            // erreichbar ist - entscheidend ist das FEHLENDE 'edit'.
            'contacts' => ['view', 'create'],
        ]);
        $redakteur = $this->createAndLoginEditor($admin, "labeltest{$u}", "label-{$u}@example.com", [$gruppe]);

        $antwort = $redakteur->post('/admin/matches/label', [
            'csrf_token' => $this->tokenFuer($redakteur),
            'art' => 'contact',
            'a' => (string)$a,
            'b' => (string)$b,
            'label' => 'different',
        ]);

        $this->assertSame(403, $antwort->statusCode, "Erwartet wurde 403, Body: {$antwort->body}");
        $this->assertSame(0, $this->labelAnzahl($db), 'Ein abgelehnter Aufruf darf nichts geschrieben haben');
    }

    /**
     * Die Gegenprobe in beide Richtungen: Mit dem passenden Recht geht es
     * durch. Ohne diesen Fall prüfte der Test oben nur, dass irgendetwas 403
     * liefert - nicht, dass die Abbildung stimmt.
     */
    public function testTheMatchingPermissionLetsItThrough(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $u = uniqid();

        $a = $this->kontakt($admin, "Label durch A {$u}");
        $b = $this->kontakt($admin, "Label durch B {$u}");

        $antwort = $admin->post('/admin/matches/label', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'art' => 'contact',
            'a' => (string)$a,
            'b' => (string)$b,
            'label' => 'different',
        ]);

        $this->assertSame('/admin/matches?success=label', $antwort->location());
        $this->assertSame(1, $this->labelAnzahl($db));

        // Ein leeres Label widerruft - eine falsch gesetzte Trennung darf
        // nicht endgültig sein.
        $admin->post('/admin/matches/label', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'art' => 'contact',
            'a' => (string)$a,
            'b' => (string)$b,
            'label' => '',
        ]);
        $this->assertSame(0, $this->labelAnzahl($db), 'Der Widerruf muss die Zeile entfernen');
    }

    public function testUnknownKindIsRejected(): void {
        $admin = $this->authenticatedClient();

        $antwort = $admin->post('/admin/matches/label', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'art' => 'stute',
            'a' => '1',
            'b' => '2',
            'label' => 'different',
        ]);

        $this->assertSame(404, $antwort->statusCode);
    }

    public function testAPairMustBeTwoDifferentRecords(): void {
        $admin = $this->authenticatedClient();
        $u = uniqid();
        $a = $this->kontakt($admin, "Label selbst {$u}");

        $antwort = $admin->post('/admin/matches/label', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'art' => 'contact',
            'a' => (string)$a,
            'b' => (string)$a,
            'label' => 'different',
        ]);

        $this->assertSame(404, $antwort->statusCode);
    }

    public function testLabellingRequiresCsrfToken(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $u = uniqid();
        $a = $this->kontakt($admin, "Label CSRF A {$u}");
        $b = $this->kontakt($admin, "Label CSRF B {$u}");

        $antwort = $admin->post('/admin/matches/label', [
            'art' => 'contact',
            'a' => (string)$a,
            'b' => (string)$b,
            'label' => 'different',
        ]);

        $this->assertSame(403, $antwort->statusCode);
        $this->assertSame(0, $this->labelAnzahl($db));
    }

    /**
     * ?note[]=x löste bis #376 einen TypeError aus - MatchLabel::setzen()
     * erwartet ?string, bekam aber ein Array. Wirkung war eine 500-Seite;
     * seither wird ein Nicht-String wie "keine Notiz" behandelt.
     */
    public function testAnArrayInsteadOfANoteDoesNotBlowUp(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $u = uniqid();
        $a = $this->kontakt($admin, "Label Notiz A {$u}");
        $b = $this->kontakt($admin, "Label Notiz B {$u}");

        $antwort = $admin->post('/admin/matches/label', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'art' => 'contact',
            'a' => (string)$a,
            'b' => (string)$b,
            'label' => 'different',
            'note' => ['kein', 'String'],
        ]);

        $this->assertNotSame(500, $antwort->statusCode, 'Eine fehlgeformte Notiz darf keine 500-Seite ergeben');
        $this->assertSame('/admin/matches?success=label', $antwort->location());

        $stmt = $db->prepare('SELECT note FROM match_labels WHERE kind = ? AND left_id = ? AND right_id = ?');
        $stmt->execute(['contact', min($a, $b), max($a, $b)]);
        $this->assertNull($stmt->fetchColumn() ?: null, 'Aus einem Array darf keine Notiz werden');
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

    private function labelAnzahl(\PDO $db): int {
        return (int)$db->query("SELECT COUNT(*) FROM match_labels WHERE kind = 'contact'")->fetchColumn();
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
