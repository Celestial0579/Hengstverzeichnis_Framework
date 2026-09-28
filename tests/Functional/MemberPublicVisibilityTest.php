<?php
// tests/Functional/MemberPublicVisibilityTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Angemeldete sehen öffentlich nie weniger als ein Gast (Audit N61).
 *
 * WORUM ES GEHT. Die Gast-Gruppe `public` steuert, was im öffentlichen Teil
 * sichtbar ist - aber nur für nicht angemeldete Besucher gehört man ihr an.
 * Die öffentlichen Seiten prüften hasPermission() des aktuellen Benutzers.
 * Ein angemeldetes Mitglied ohne eigenes `view` sah deshalb einen leeren
 * Katalog und bekam 404 auf Pferde-, Kontakt- und Bildseiten, während jeder
 * Gast dieselben Seiten sah. Der naheliegende Umweg, der Mitgliedergruppe
 * "Lesen" zu geben, öffnete dabei die Verwaltungslisten (M10/M13).
 *
 * Jetzt gelten die Leserechte der Gast-Gruppe bei öffentlichen Prüfungen als
 * Untergrenze. Geprüft wird auch die Gegenrichtung: Die Untergrenze öffnet
 * nichts Unveröffentlichtes und keine Verwaltung, und sie folgt der
 * Gast-Gruppe, wenn der Admin ihr etwas entzieht.
 */
class MemberPublicVisibilityTest extends FunctionalTestCase {

    /** @var list<int> */
    private array $pferde = [];
    /** @var list<int> */
    private array $kontakte = [];
    /** @var list<string> */
    private array $dateien = [];

    protected function tearDown(): void {
        $db = Database::getInstance();
        foreach ($this->pferde as $id) {
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

    public function testMitgliedOhneEigeneRechteSiehtWasGaesteSehen(): void {
        $admin = $this->authenticatedClient();
        $kennung = 'Untergrenze' . substr(uniqid(), -6);
        $offen = $this->pferd("{$kennung} Offen", true);
        $intern = $this->pferd("{$kennung} Intern", false);
        $kontakt = $this->kontakt("{$kennung} Kontakt", true);

        // Rechtelose Gruppe, ohne 2FA-Pflicht.
        $mitglied = $this->angemeldetOhneFaktor($admin, 'untergrenze')['client'];

        $katalog = $mitglied->get('/katalog?search=' . urlencode($kennung));
        $this->assertSame(200, $katalog->statusCode);
        $this->assertStringContainsString('/horse?id=' . $offen, $katalog->body, 'Der Katalog muss die Karte des veröffentlichten Pferds zeigen');
        $this->assertStringNotContainsString('/horse?id=' . $intern, $katalog->body);

        $this->assertSame(200, $mitglied->get('/horse?id=' . $offen)->statusCode);
        $this->assertSame(200, $mitglied->get('/kontakt?id=' . $kontakt)->statusCode);
        $this->assertSame(200, $mitglied->get('/media/horse-image?id=' . $offen)->statusCode);

        // Nichts Unveröffentlichtes, keine Verwaltung.
        $this->assertSame(404, $mitglied->get('/horse?id=' . $intern)->statusCode);
        $this->assertSame(404, $mitglied->get('/media/horse-image?id=' . $intern)->statusCode);
        $this->assertSame(403, $mitglied->get('/admin/horses')->statusCode);

        // Die Untergrenze folgt der Gast-Gruppe.
        $gast = $this->findBuiltinGroupId($admin, 'Gast');
        try {
            $this->setGroupPermissions($admin, $gast, ['contacts' => ['view']]);
            $this->assertSame(404, $mitglied->get('/horse?id=' . $offen)->statusCode, 'Ohne horses.view der Gast-Gruppe auch für das Mitglied 404');
            $this->assertSame(404, $mitglied->get('/media/horse-image?id=' . $offen)->statusCode);
        } finally {
            $this->setGroupPermissions($admin, $gast, self::GUEST_DEFAULT_PERMISSIONS);
        }
    }

    private function pferd(string $name, bool $veroeffentlicht): int {
        $dir = \App\Helper\HorseImagePath::dir();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $datei = 'untergrenze_' . uniqid() . '.png';
        file_put_contents($dir . '/' . $datei, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));
        $this->dateien[] = $dir . '/' . $datei;

        $db = Database::getInstance();
        $db->prepare("INSERT INTO horses (name, sex, image_url, is_published) VALUES (?, 'stallion', ?, ?)")
            ->execute([$name, '/uploads/horses/' . $datei, $veroeffentlicht ? 1 : 0]);
        $id = (int)$db->lastInsertId();
        $this->pferde[] = $id;
        return $id;
    }

    private function kontakt(string $name, bool $veroeffentlicht): int {
        $db = Database::getInstance();
        $db->prepare('INSERT INTO contacts (name, city, is_published) VALUES (?, ?, ?)')
            ->execute([$name, 'Kiel', $veroeffentlicht ? 1 : 0]);
        $id = (int)$db->lastInsertId();
        $this->kontakte[] = $id;
        return $id;
    }
}
