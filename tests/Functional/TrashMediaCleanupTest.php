<?php
// tests/Functional/TrashMediaCleanupTest.php

namespace Tests\Functional;

use App\Database;
use App\Helper\HorseImagePath;
use Tests\Support\HttpClient;

/**
 * Audit N59: Endgültig gelöschte Pferde nehmen ihre Fotos mit.
 *
 * Bisher entfernten "Endgültig löschen" und "Papierkorb leeren" nur die
 * Datenbankzeilen - der FK-CASCADE nahm die Medienzeilen mit, die Dateien
 * blieben in storage/horses und damit in jeder Sicherung. Jetzt sammelt
 * TrashController die Bilddateien unter der Löschsperre ein und entfernt sie
 * nach Commit und `horse.deleted` - aber nur, was niemand mehr referenziert.
 */
class TrashMediaCleanupTest extends FunctionalTestCase {

    /** @var array<int, string> */
    private array $dateien = [];
    /** @var array<int, int> */
    private array $pferde = [];

    protected function tearDown(): void {
        $db = Database::getInstance();
        foreach ($this->pferde as $id) {
            $db->prepare('DELETE FROM horses WHERE id = ?')->execute([$id]);
        }
        foreach ($this->dateien as $pfad) {
            @unlink($pfad);
        }
        $this->pferde = [];
        $this->dateien = [];
        parent::tearDown();
    }

    /** Legt eine Datei samt Vorschaubild in der Ablage an; gibt den Spaltenwert zurück. */
    private function foto(string $praefix): string {
        $dir = HorseImagePath::dir();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $stamm = $praefix . '_' . uniqid();
        foreach ([$stamm . '.jpg', $stamm . '_thumb.jpg', $stamm . '_card.jpg'] as $name) {
            file_put_contents($dir . '/' . $name, 'bild');
            $this->dateien[] = $dir . '/' . $name;
        }

        return '/uploads/horses/' . $stamm . '.jpg';
    }

    private function pfad(string $spaltenwert): string {
        return HorseImagePath::dir() . '/' . basename($spaltenwert);
    }

    private function vorschau(string $spaltenwert): string {
        return HorseImagePath::dir() . '/' . pathinfo($spaltenwert, PATHINFO_FILENAME) . '_thumb.jpg';
    }

    /** Pferd im Papierkorb mit Hauptbild (image_url + Medienzeile) und einem Nebenbild. */
    private function pferdImPapierkorb(string $haupt, ?string $neben = null): int {
        $db = Database::getInstance();
        $db->prepare("INSERT INTO horses (name, image_url, deleted_at) VALUES (?, ?, NOW())")
            ->execute(['Papierkorbfoto ' . uniqid(), $haupt]);
        $id = (int)$db->lastInsertId();
        $this->pferde[] = $id;

        $zeile = $db->prepare(
            "INSERT INTO horse_media (horse_id, type, file_name, is_main, sort_order) VALUES (?, 'image', ?, ?, ?)"
        );
        $zeile->execute([$id, $haupt, 1, 0]);
        if ($neben !== null) {
            $zeile->execute([$id, $neben, 0, 10]);
        }

        return $id;
    }

    private function endgueltigLoeschen(HttpClient $admin, int $id): void {
        $antwort = $admin->post('/admin/trash/permanent-delete', [
            'csrf_token' => $this->csrfTokenFrom($admin, '/admin/trash'),
            'type' => 'horse',
            'id' => (string)$id,
        ]);
        $this->assertStringContainsString('success=purged', (string)$antwort->location(), "Body: {$antwort->body}");
    }

    public function testEndgueltigesLoeschenEntferntDieFotos(): void {
        $admin = $this->authenticatedClient();
        $haupt = $this->foto('trash_haupt');
        $neben = $this->foto('trash_neben');
        $id = $this->pferdImPapierkorb($haupt, $neben);

        $this->endgueltigLoeschen($admin, $id);

        foreach ([$haupt, $neben] as $wert) {
            $this->assertFileDoesNotExist($this->pfad($wert));
            $this->assertFileDoesNotExist($this->vorschau($wert));
        }
    }

    public function testPapierkorbLeerenEntferntDieFotosAllerPferde(): void {
        $admin = $this->authenticatedClient();
        $eins = $this->foto('trash_leeren_eins');
        $zwei = $this->foto('trash_leeren_zwei');
        $this->pferdImPapierkorb($eins);
        // Ein Pferd nur mit image_url, ohne Medienzeile (v0.8-Bestand, M40).
        $db = Database::getInstance();
        $db->prepare("INSERT INTO horses (name, image_url, deleted_at) VALUES (?, ?, NOW())")
            ->execute(['Papierkorb ohne Zeile ' . uniqid(), $zwei]);
        $this->pferde[] = (int)$db->lastInsertId();

        $antwort = $admin->post('/admin/trash/empty', [
            'csrf_token' => $this->csrfTokenFrom($admin, '/admin/trash'),
        ]);
        $this->assertStringContainsString('success=emptied', (string)$antwort->location(), "Body: {$antwort->body}");

        foreach ([$eins, $zwei] as $wert) {
            $this->assertFileDoesNotExist($this->pfad($wert));
            $this->assertFileDoesNotExist($this->vorschau($wert));
        }
    }

    /** Eine Datei, die ein aktives Pferd noch führt, bleibt. */
    public function testEineGeteilteDateiBleibt(): void {
        $admin = $this->authenticatedClient();
        $geteilt = $this->foto('trash_geteilt');
        $id = $this->pferdImPapierkorb($geteilt);

        $db = Database::getInstance();
        // Abweichende Schreibweise - verglichen wird über den Dateinamen.
        $db->prepare("INSERT INTO horses (name, image_url) VALUES (?, ?)")
            ->execute(['Aktiv mit geteilter Datei ' . uniqid(), ltrim($geteilt, '/')]);
        $this->pferde[] = (int)$db->lastInsertId();

        $this->endgueltigLoeschen($admin, $id);

        $this->assertFileExists($this->pfad($geteilt));
        $this->assertFileExists($this->vorschau($geteilt));
    }
}
