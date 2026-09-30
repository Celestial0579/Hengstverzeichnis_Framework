<?php
// tests/Functional/HorseFieldValidationTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Audit N48: Zu lange Eingaben und doppelte UELN liefen im Pferdeformular
 * erst beim INSERT/UPDATE in einen DB-Fehler - eine Fehlerseite, und beim
 * Anlegen blieb das schon abgelegte Foto verwaist liegen. Jetzt prüft der
 * Controller vorher und meldet verständlich.
 */
class HorseFieldValidationTest extends FunctionalTestCase {

    private function db(): \PDO {
        return Database::getInstance();
    }

    private function dateienImFotoordner(): int {
        $dir = \App\Helper\HorseImagePath::dir();
        return is_dir($dir) ? count(array_diff(scandir($dir) ?: [], ['.', '..'])) : 0;
    }

    private function jpeg(): string {
        $bild = imagecreatetruecolor(8, 8);
        ob_start();
        imagejpeg($bild);
        return (string)ob_get_clean();
    }

    public function testTooLongFieldIsRefusedBeforeThePhotoIsStored(): void {
        $admin = $this->authenticatedClient();
        $u = uniqid();
        $vorher = $this->dateienImFotoordner();

        $form = $admin->get('/admin/horses/create');
        $response = $admin->postFile('/admin/horses/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => "Zu lang {$u}",
            'status' => 'active',
            'sire_ueln' => str_repeat('1', 16),
        ], 'horse_image', 'foto.jpg', $this->jpeg(), 'image/jpeg');

        $this->assertSame(302, $response->statusCode);
        $this->assertSame('/admin/horses?error=too_long&field=sire_ueln', $response->location());
        $this->assertSame(0, (int)$this->db()->query("SELECT COUNT(*) FROM horses WHERE name = " . $this->db()->quote("Zu lang {$u}"))->fetchColumn());
        $this->assertSame($vorher, $this->dateienImFotoordner(), 'Kein verwaistes Foto');

        $liste = $admin->get('/admin/horses?error=too_long&field=sire_ueln');
        $this->assertStringContainsString('„UELN des Vaters“ ist zu lang (max. 15 Zeichen)', html_entity_decode($liste->body));
        $liste = $admin->get('/admin/horses?error=too_long&field=%3Cscript%3E');
        $this->assertStringContainsString('Nicht gespeichert: Eine Eingabe ist zu lang.', $liste->body);
    }

    public function testDuplicateUelnIsReportedInsteadOfAServerError(): void {
        $admin = $this->authenticatedClient();
        $db = $this->db();
        $u = strtoupper(substr(md5(uniqid('', true)), 0, 10));

        $db->prepare("INSERT INTO horses (name, ueln, deleted_at) VALUES (?, ?, NOW())")->execute(["Im Papierkorb {$u}", "DE TRASH{$u}"]);
        $trash = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO horses (name, ueln) VALUES (?, ?)")->execute(["Aktiv {$u}", "DE AKTIV{$u}"]);
        $aktiv = (int)$db->lastInsertId();

        $form = $admin->get('/admin/horses/create');
        $csrf = $form->formField('csrf_token') ?? '';
        $response = $admin->post('/admin/horses/store', ['csrf_token' => $csrf, 'name' => "Neu {$u}", 'status' => 'active', 'ueln' => "DE TRASH{$u}"]);
        $this->assertSame(302, $response->statusCode);
        $this->assertSame('/admin/horses?error=ueln_taken_trash', $response->location());

        $response = $admin->post('/admin/horses/store', ['csrf_token' => $csrf, 'name' => "Neu {$u}", 'status' => 'active', 'ueln' => strtolower("DE AKTIV{$u}")]);
        $this->assertSame(302, $response->statusCode);
        $this->assertSame('/admin/horses?error=ueln_taken', $response->location());
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM horses WHERE name = " . $db->quote("Neu {$u}"))->fetchColumn());

        // Bearbeiten: 101 Zeichen Name, dann die UELN eines anderen Pferds.
        $db->prepare("INSERT INTO horses (name, color) VALUES (?, 'Braun')")->execute(["Bearbeitet {$u}"]);
        $eigen = (int)$db->lastInsertId();
        $basis = ['csrf_token' => $csrf, 'id' => (string)$eigen, 'status' => 'active', 'color' => 'Rappe'];

        $response = $admin->post('/admin/horses/update', $basis + ['name' => str_repeat('n', 101)]);
        $this->assertSame('/admin/horses?error=too_long&field=name', $response->location());
        $row = $db->query("SELECT name, color FROM horses WHERE id = {$eigen}")->fetch();
        $this->assertSame("Bearbeitet {$u}", $row['name']);
        $this->assertSame('Braun', $row['color'], 'Datensatz unverändert');

        $response = $admin->post('/admin/horses/update', $basis + ['name' => "Bearbeitet {$u}", 'ueln' => "DE AKTIV{$u}"]);
        $this->assertSame(302, $response->statusCode);
        $this->assertSame('/admin/horses?error=ueln_taken', $response->location());
        $this->assertNull($db->query("SELECT ueln FROM horses WHERE id = {$eigen}")->fetchColumn());

        // Die eigene UELN bleibt speicherbar.
        $response = $admin->post('/admin/horses/update', array_replace($basis, ['id' => (string)$aktiv]) + ['name' => "Aktiv {$u}", 'ueln' => "DE AKTIV{$u}"]);
        $this->assertSame('/admin/horses?success=updated', $response->location());

        $db->exec("DELETE FROM horses WHERE id IN ({$trash}, {$aktiv}, {$eigen})");
    }

    public function testFormAnnouncesTheColumnWidths(): void {
        $admin = $this->authenticatedClient();
        $form = $admin->get('/admin/horses/create');
        $this->assertMatchesRegularExpression('~name="sire_ueln"[^>]*maxlength="15"~', $form->body);
        $this->assertMatchesRegularExpression('~name="dam_ueln"[^>]*maxlength="15"~', $form->body);
        $this->assertMatchesRegularExpression('~name="name"[^>]*maxlength="100"~', $form->body);
        $this->assertStringContainsString('max. 15 Zeichen', $form->body);
    }
}
