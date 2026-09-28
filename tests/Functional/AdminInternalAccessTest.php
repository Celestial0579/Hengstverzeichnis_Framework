<?php
// tests/Functional/AdminInternalAccessTest.php

namespace Tests\Functional;

use App\Database;

/**
 * "Lesen" ist nicht "Intern lesen" (Audit M10, M13).
 *
 * WORUM ES GEHT. `view` an Pferden und Kontakten war zugleich das öffentliche
 * Sichtrecht und der volle Lesezugriff in der Verwaltung. Jedes Konto einer
 * Gruppe mit `contacts.view` - etwa der Standardgruppe der
 * Selbstregistrierung - las in /admin/contacts E-Mail, Telefon, Anschrift
 * und Notiz aller Kontakte, auch der unveröffentlichten; mit `horses.view`
 * sah es unveröffentlichte Pferde samt Fotos und alle Kontaktnamen als
 * Vorschlagsliste.
 *
 * Jetzt öffnet `view` nur den veröffentlichten Bestand ohne private
 * Kontaktdaten. Mehr gibt `internal` oder ein Bearbeitungsrecht. Geprüft
 * wird jeweils beides: dass Nur-Leser das Interne NICHT sehen, und dass
 * `internal` bzw. `edit` es weiterhin zeigen - sonst bewiese der erste Teil
 * auch eine kaputte Liste.
 */
class AdminInternalAccessTest extends FunctionalTestCase {

    /** @var list<int> */
    private array $pferde = [];
    /** @var list<int> */
    private array $kontakte = [];
    /** @var list<string> */
    private array $dateien = [];

    protected function tearDown(): void {
        $db = Database::getInstance();
        foreach ($this->pferde as $id) {
            $db->prepare('DELETE FROM horse_persons WHERE horse_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM horses WHERE id = ?')->execute([$id]);
        }
        foreach ($this->kontakte as $id) {
            $db->prepare('DELETE FROM contacts WHERE id = ?')->execute([$id]);
        }
        foreach ($this->dateien as $datei) {
            @unlink($datei);
        }
        $this->pferde = $this->kontakte = $this->dateien = [];
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // M10: Kontaktliste
    // ------------------------------------------------------------------

    public function testKontaktlisteOhneInterneEinsicht(): void {
        $admin = $this->authenticatedClient();
        $k = 'Kliste' . substr(uniqid(), -6);
        $marker = [
            'email' => "privat-{$k}@example.org",
            'phone' => "0431-{$k}-77",
            'street' => "Geheimweg {$k}",
            'contact_info' => "Notiz {$k} intern",
            'contact_person' => "Ansprech {$k}",
        ];
        $this->kontakt("{$k} Offen", true, $marker);
        $this->kontakt("{$k} Verborgen", false);

        [$konto, $gruppe] = $this->kontoMitGruppe($admin, 'kontaktleser');
        $this->setGroupPermissions($admin, $gruppe, ['contacts' => ['view']]);

        $liste = $konto->get('/admin/contacts?q_name=' . urlencode($k));
        $this->assertSame(200, $liste->statusCode);
        $this->assertStringContainsString("{$k} Offen", $liste->body);
        $this->assertStringNotContainsString("{$k} Verborgen", $liste->body, 'Unveröffentlichte Kontakte nur mit interner Einsicht');
        foreach ($marker as $feld => $wert) {
            $this->assertStringNotContainsString($wert, $liste->body, "{$feld} darf ohne interne Einsicht nicht erscheinen");
        }
        foreach (['q_email', 'q_contact', 'q_contact_public', 'q_postal_code'] as $filter) {
            $this->assertStringNotContainsString('name="' . $filter . '"', $liste->body, "Filterfeld {$filter} muss fehlen");
        }
        $this->assertStringNotContainsString('Warning', $liste->body);
        $this->assertStringNotContainsString('Undefined array key', $liste->body);

        // Die Suche läuft nicht über private Felder - sonst verriete die
        // Trefferliste sie.
        $suche = $konto->get('/admin/contacts?search=' . urlencode($marker['email']));
        $this->assertStringNotContainsString("{$k} Offen", $suche->body);
        $suche = $konto->get('/admin/contacts?search=' . urlencode($marker['phone']));
        $this->assertStringNotContainsString("{$k} Offen", $suche->body);

        // q_email wirkt nicht und landet nicht in Links.
        $gefiltert = $konto->get('/admin/contacts?q_name=' . urlencode($k) . '&q_email=nichts-passt-hierzu');
        $this->assertStringContainsString("{$k} Offen", $gefiltert->body, 'q_email darf ohne interne Einsicht nicht filtern');
        $this->assertStringNotContainsString('nichts-passt-hierzu', $gefiltert->body);

        // Der Freigabe-Filter wäre ein Orakel.
        $orakel = $konto->get('/admin/contacts?q_name=' . urlencode($k) . '&q_contact_public=1');
        $this->assertStringContainsString("{$k} Offen", $orakel->body, 'q_contact_public darf ohne interne Einsicht nicht filtern');

        $unveroeffentlicht = $konto->get('/admin/contacts?published=0&q_name=' . urlencode($k));
        $this->assertStringNotContainsString("{$k} Verborgen", $unveroeffentlicht->body);

        // Gegenprobe: internal bzw. edit zeigen alles.
        foreach ([['view', 'internal'], ['view', 'edit']] as $rechte) {
            $this->setGroupPermissions($admin, $gruppe, ['contacts' => $rechte]);
            $voll = $konto->get('/admin/contacts?q_name=' . urlencode($k));
            $bezeichnung = implode('+', $rechte);
            $this->assertStringContainsString("{$k} Verborgen", $voll->body, $bezeichnung);
            foreach ($marker as $feld => $wert) {
                $this->assertStringContainsString($wert, $voll->body, "{$bezeichnung}: {$feld}");
            }
            $this->assertStringContainsString('name="q_email"', $voll->body, $bezeichnung);
        }
    }

    // ------------------------------------------------------------------
    // M13: Pferdeliste, Suche, Fotos, Kontaktvorschläge
    // ------------------------------------------------------------------

    public function testPferdelisteOhneInterneEinsicht(): void {
        $admin = $this->authenticatedClient();
        $k = 'Pliste' . substr(uniqid(), -6);
        $farbe = "Geheimfarbe{$k}";
        $p1 = $this->pferd("{$k} Offen", true);
        $p2 = $this->pferd("{$k} Verborgen", false, $farbe);
        $kontaktVerborgen = $this->kontakt("{$k} Besitzer Verborgen", false);
        $kontaktOffen = $this->kontakt("{$k} Besitzer Offen", true);
        $db = Database::getInstance();
        $db->prepare("INSERT INTO horse_persons (horse_id, contact_id, role) VALUES (?, ?, 'owner')")->execute([$p1, $kontaktVerborgen]);
        $db->prepare("INSERT INTO horse_persons (horse_id, contact_id, role) VALUES (?, ?, 'breeder')")->execute([$p1, $kontaktOffen]);

        [$konto, $gruppe] = $this->kontoMitGruppe($admin, 'pferdeleser');
        $this->setGroupPermissions($admin, $gruppe, ['horses' => ['view']]);

        $liste = $konto->get('/admin/horses?q_name=' . urlencode($k));
        $this->assertSame(200, $liste->statusCode);
        $this->assertStringContainsString("{$k} Offen", $liste->body);
        $this->assertStringNotContainsString("{$k} Verborgen", $liste->body, 'Unveröffentlichte Pferde nur mit interner Einsicht');
        $this->assertStringNotContainsString($farbe, $liste->body, 'Die Farbauswahl verriete das unveröffentlichte Pferd');
        $this->assertStringNotContainsString("{$k} Besitzer", $liste->body, 'Ohne contacts.view keine Kontaktnamen');
        $this->assertStringNotContainsString('name="q_owner"', $liste->body);
        $this->assertStringNotContainsString('Warning', $liste->body);

        $unveroeffentlicht = $konto->get('/admin/horses?published=0&q_name=' . urlencode($k));
        $this->assertStringNotContainsString("{$k} Verborgen", $unveroeffentlicht->body);

        // q_owner wird ohne contacts.view nicht gelesen: Die Treffermenge
        // bleibt, egal ob der Name passt.
        $mitBesitzer = $konto->get('/admin/horses?q_name=' . urlencode($k) . '&q_owner=' . urlencode('kein Besitzer dieses Namens'));
        $this->assertStringContainsString("{$k} Offen", $mitBesitzer->body);

        $suche = $konto->get('/admin/horses/search?q=' . urlencode("{$k} Verborgen"));
        $this->assertSame([], json_decode($suche->body, true));

        $this->assertSame(404, $konto->get('/media/horse-image?id=' . $p2)->statusCode);
        $this->assertSame(200, $konto->get('/media/horse-image?id=' . $p1)->statusCode);

        // Mit contacts.view: Vorschläge nur aus veröffentlichten Kontakten.
        $this->setGroupPermissions($admin, $gruppe, ['horses' => ['view'], 'contacts' => ['view']]);
        $mitKontakten = $konto->get('/admin/horses?q_name=' . urlencode($k));
        $this->assertStringContainsString('name="q_owner"', $mitKontakten->body);
        $this->assertStringContainsString('value="' . "{$k} Besitzer Offen" . '"', $mitKontakten->body);
        $this->assertStringNotContainsString("{$k} Besitzer Verborgen", $mitKontakten->body);

        // Gegenprobe: internal bzw. edit zeigen das unveröffentlichte Pferd.
        foreach ([['view', 'internal'], ['view', 'edit']] as $rechte) {
            $this->setGroupPermissions($admin, $gruppe, ['horses' => $rechte]);
            $bezeichnung = implode('+', $rechte);
            $voll = $konto->get('/admin/horses?q_name=' . urlencode($k));
            $this->assertStringContainsString("{$k} Verborgen", $voll->body, $bezeichnung);
            $this->assertStringContainsString($farbe, $voll->body, $bezeichnung);
            $treffer = json_decode($konto->get('/admin/horses/search?q=' . urlencode("{$k} Verborgen"))->body, true);
            $this->assertCount(1, $treffer, $bezeichnung);
            $this->assertSame(200, $konto->get('/media/horse-image?id=' . $p2)->statusCode, $bezeichnung);
        }
    }

    // ------------------------------------------------------------------
    // Hilfen
    // ------------------------------------------------------------------

    /** @return array{0: \Tests\Support\HttpClient, 1: int} */
    private function kontoMitGruppe(\Tests\Support\HttpClient $admin, string $praefix): array {
        $konto = $this->angemeldetOhneFaktor($admin, $praefix);
        $gruppe = (int)Database::getInstance()
            ->query('SELECT group_id FROM user_groups WHERE user_id = ' . (int)$konto['id'])->fetchColumn();
        $this->assertGreaterThan(0, $gruppe);
        return [$konto['client'], $gruppe];
    }

    private function pferd(string $name, bool $veroeffentlicht, ?string $farbe = null): int {
        $dir = \App\Helper\HorseImagePath::dir();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $datei = 'intern_' . uniqid() . '.png';
        file_put_contents($dir . '/' . $datei, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));
        $this->dateien[] = $dir . '/' . $datei;

        $db = Database::getInstance();
        $db->prepare("INSERT INTO horses (name, sex, color, image_url, is_published) VALUES (?, 'mare', ?, ?, ?)")
            ->execute([$name, $farbe, '/uploads/horses/' . $datei, $veroeffentlicht ? 1 : 0]);
        $id = (int)$db->lastInsertId();
        $this->pferde[] = $id;
        return $id;
    }

    /** @param array<string, string> $felder */
    private function kontakt(string $name, bool $veroeffentlicht, array $felder = []): int {
        $felder += ['name' => $name, 'city' => 'Kiel', 'is_published' => $veroeffentlicht ? 1 : 0, 'contact_public' => 0];
        $spalten = array_keys($felder);
        $db = Database::getInstance();
        $db->prepare(sprintf(
            'INSERT INTO contacts (%s) VALUES (%s)',
            implode(', ', $spalten),
            implode(', ', array_fill(0, count($spalten), '?'))
        ))->execute(array_values($felder));
        $id = (int)$db->lastInsertId();
        $this->kontakte[] = $id;
        return $id;
    }
}
