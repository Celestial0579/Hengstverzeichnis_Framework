<?php
// tests/Functional/HorseEditSectionsHookTest.php

namespace Tests\Functional;

use Tests\Support\HttpClient;

/**
 * HTTP-Funktionstests für den Filter-Hook `horse.edit_sections` (Issue #255,
 * siehe HorseController::edit()).
 *
 * Der Hook ist das Admin-Gegenstück zu `horse.detail_sections`: Addons können
 * damit einen eigenen Abschnitt in das Bearbeitungsformular eines Hengstes
 * hängen und bekommen die horse_id aus dem Aufrufkontext, statt eine eigene
 * Verwaltungsseite mit Pferdeauswahl zu bauen (Anlass: Addons#87 lud dafür bei
 * jedem Aufruf den kompletten Pferdebestand als <select>).
 *
 * Die wichtigste Zusicherung hier ist die dritte: Der Abschnitt muss AUSSERHALB
 * des Kern-Formulars landen. Verschachtelte <form> sind ungültiges HTML, und
 * beide Abnehmer des Hooks brauchen eigene Formulare (Löschen je Zeile, bei der
 * Galerie zusätzlich ein Datei-Upload). Rutschte der Abschnitt bei einem späteren
 * Umbau der View wieder in das Formular hinein, bliebe das im Framework
 * unbemerkt - kaputt wäre es erst in einem fremden Repository.
 *
 * Nutzt wie HorseDetailSectionsHookTest das Referenz-Plugin aus
 * docs/examples/demo-plugin, das für die Testdauer in das gitignorete
 * plugins/-Verzeichnis kopiert und über den echten HTTP-Endpunkt aktiviert wird.
 */
class HorseEditSectionsHookTest extends FunctionalTestCase {

    private const PLUGIN_SRC = __DIR__ . '/../../docs/examples/demo-plugin';
    private const PLUGIN_DEST = __DIR__ . '/../../plugins/demo-plugin';
    private const EDIT_MARKER = 'data-demo-edit="1"';

    protected function tearDown(): void {
        self::removePluginDir();
        parent::tearDown();
    }

    private static function installPluginFixture(): void {
        self::removePluginDir();
        mkdir(self::PLUGIN_DEST, 0777, true);
        mkdir(self::PLUGIN_DEST . '/lang', 0777, true);
        foreach (['Plugin.php', 'plugin.json'] as $file) {
            copy(self::PLUGIN_SRC . '/' . $file, self::PLUGIN_DEST . '/' . $file);
        }
        foreach (glob(self::PLUGIN_SRC . '/lang/*.php') ?: [] as $langFile) {
            copy($langFile, self::PLUGIN_DEST . '/lang/' . basename($langFile));
        }
    }

    private static function removePluginDir(): void {
        if (!is_dir(self::PLUGIN_DEST)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::PLUGIN_DEST, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir(self::PLUGIN_DEST);
    }

    public function testEditSectionsHookRendersOutsideTheCoreFormAndOnlyWhenEditing(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $horseId = $this->createHorse($admin, "HookBearbeiten-{$unique}");

        // 1. Ohne aktiviertes Plugin bleibt die Seite unverändert - der Kern darf
        //    keinen leeren Kartenrumpf rendern, nur weil der Hook existiert.
        $withoutPlugin = $admin->get("/admin/horses/edit?id={$horseId}");
        $this->assertSame(200, $withoutPlugin->statusCode);
        $this->assertStringNotContainsString(self::EDIT_MARKER, $withoutPlugin->body);

        self::installPluginFixture();
        $toggleResponse = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => 'demo-plugin',
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggleResponse->location());

        try {
            // 2. Im Bearbeitungsformular erscheint der Abschnitt, und das Plugin
            //    kennt das Pferd aus dem Aufrufkontext.
            $edit = $admin->get("/admin/horses/edit?id={$horseId}");
            $this->assertSame(200, $edit->statusCode);
            $this->assertStringContainsString(
                self::EDIT_MARKER,
                $edit->body,
                'Der Hook horse.edit_sections muss im Bearbeitungsformular feuern.'
            );
            $this->assertStringContainsString(
                "#{$horseId}",
                $edit->body,
                'Das Plugin muss die horse_id aus dem Aufrufkontext erhalten - genau dafür gibt es den Hook.'
            );

            // 3. Der Abschnitt steht hinter dem schließenden Tag des Kern-Formulars.
            //    Siehe Klassenkommentar: Das ist die eigentliche Regressionsbremse.
            $formStart = strpos($edit->body, 'action="/admin/horses/update"');
            $this->assertNotFalse($formStart, 'Kern-Formular nicht gefunden - hat sich die action-URL geändert?');
            $formEnd = strpos($edit->body, '</form>', $formStart);
            $this->assertNotFalse($formEnd, 'Kein schließendes </form> nach dem Kern-Formular gefunden.');
            $this->assertGreaterThan(
                $formEnd,
                strpos($edit->body, self::EDIT_MARKER),
                'Plugin-Abschnitte müssen AUSSERHALB des Kern-Formulars stehen. Innerhalb könnten Addons '
                . 'keine eigenen <form> mehr nutzen (verschachtelte Formulare sind ungültiges HTML) und '
                . 'müssten über den Kern-POST speichern, der nur horses.edit geprüft hat.'
            );

            // 4. Beim Anlegen feuert der Hook nicht: Es gibt noch keine horses.id,
            //    ein Abschnitt könnte dort nichts speichern.
            $create = $admin->get('/admin/horses/create');
            $this->assertSame(200, $create->statusCode);
            $this->assertStringNotContainsString(
                self::EDIT_MARKER,
                $create->body,
                'Im Anlege-Formular gibt es noch keine horse_id - der Hook darf dort nicht feuern.'
            );
        } finally {
            $admin->post('/admin/plugins/toggle', [
                'csrf_token' => $this->currentCsrfToken($admin),
                'slug' => 'demo-plugin',
                'enable' => '0',
            ]);
        }
    }

    private function createHorse(HttpClient $admin, string $name): int {
        $form = $admin->get('/admin/horses/create');
        $response = $admin->post('/admin/horses/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $name,
            'status' => 'active',
        ]);
        $this->assertSame(
            '/admin/horses?success=created',
            $response->location(),
            "Anlegen von '{$name}' fehlgeschlagen, Body: {$response->body}"
        );

        return $this->findIdInAdminList($admin, '/admin/horses', $name);
    }

    /**
     * Liest die ID aus der Admin-Liste: die Zeile mit `<strong>NAME</strong>`
     * suchen und daraus die erste Zelle nehmen, die nur aus Ziffern besteht.
     */
    private function findIdInAdminList(HttpClient $admin, string $path, string $name): int {
        $page = $admin->get($path);

        preg_match_all('/<tr[^>]*>((?:(?!<\/tr>).)*?)<\/tr>/s', $page->body, $rowMatches);
        foreach ($rowMatches[1] as $rowHtml) {
            if (!str_contains($rowHtml, '<strong>' . htmlspecialchars($name) . '</strong>')) {
                continue;
            }
            if (preg_match('/<td[^>]*>\s*(\d+)\s*<\/td>/', $rowHtml, $idMatch)) {
                return (int)$idMatch[1];
            }
        }

        $this->fail("Kein Eintrag '{$name}' in {$path} gefunden.");
    }
}
