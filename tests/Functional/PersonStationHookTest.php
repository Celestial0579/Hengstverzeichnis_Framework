<?php
// tests/Functional/PersonStationHookTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Die Erweiterungspunkte `person.detail_sections` und
 * `station.detail_sections`.
 *
 * Anlass ist die geplante Kontaktanfrage
 * ([Addons #106](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/106)):
 * Ein Addon soll auf der Personen- und der Stationsseite ein Formular anbieten
 * können, **ohne** dass die Adresse dafür öffentlich werden muss. Ohne diese
 * Hooks gäbe es dort keinen Platz dafür.
 *
 * Geprüft wird mit dem Referenz-Plugin aus `docs/examples/demo-plugin`, das für
 * die Testdauer in das gitignorete `plugins/`-Verzeichnis kopiert wird -
 * dasselbe Muster wie in HorseDetailSectionsHookTest.
 */
class PersonStationHookTest extends FunctionalTestCase {

    private const PLUGIN_DEST = __DIR__ . '/../../plugins/hooktest-addon';

    protected function tearDown(): void {
        foreach (glob(self::PLUGIN_DEST . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir(self::PLUGIN_DEST);
        try {
            Database::getInstance()->prepare("DELETE FROM plugins WHERE slug = ?")->execute(['hooktest-addon']);
        } catch (\Throwable $e) {
            // DB weg = nichts zu bereinigen
        }
        parent::tearDown();
    }

    public function testBothPublicPagesOfferAnExtensionPoint(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        $this->installHookPlugin();

        // Plugin über den echten Endpunkt aktivieren.
        $toggle = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => 'hooktest-addon',
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggle->location(), "Aktivieren fehlgeschlagen: {$toggle->body}");

        // Person und Station anlegen, beide veröffentlicht.
        $form = $admin->get('/admin/persons/create');
        $admin->post('/admin/persons/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => "Hook Person {$unique}",
            'is_published' => '1',
        ]);
        $stmt = $db->prepare("SELECT id FROM persons WHERE name = ?");
        $stmt->execute(["Hook Person {$unique}"]);
        $personId = (int)$stmt->fetchColumn();

        $form = $admin->get('/admin/breeding-stations/create');
        $admin->post('/admin/breeding-stations/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => "Hook Station {$unique}",
            'is_published' => '1',
        ]);
        $stmt = $db->prepare("SELECT id FROM breeding_stations WHERE name = ?");
        $stmt->execute(["Hook Station {$unique}"]);
        $stationId = (int)$stmt->fetchColumn();

        $guest = $this->newClient();

        $personSeite = $guest->get('/person?id=' . $personId);
        $this->assertSame(200, $personSeite->statusCode);
        $this->assertStringContainsString('HOOK-PERSON-MARKER', $personSeite->body, 'person.detail_sections muss gerendert werden');

        $stationSeite = $guest->get('/station?id=' . $stationId);
        $this->assertSame(200, $stationSeite->statusCode);
        $this->assertStringContainsString('HOOK-STATION-MARKER', $stationSeite->body, 'station.detail_sections muss gerendert werden');

        // Und die Admin-Formulare - dort haengt das Opt-out des
        // Kontaktanfrage-Addons dran, statt einer Spalte im Kern.
        $editPerson = $admin->get('/admin/persons/edit?id=' . $personId);
        $this->assertStringContainsString('HOOK-PERSON-EDIT-MARKER', $editPerson->body, 'person.edit_sections muss gerendert werden');
        $editStation = $admin->get('/admin/breeding-stations/edit?id=' . $stationId);
        $this->assertStringContainsString('HOOK-STATION-EDIT-MARKER', $editStation->body, 'station.edit_sections muss gerendert werden');

        // Gegenprobe: Ohne aktives Plugin bleibt kein leerer Rahmen stehen.
        $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => 'hooktest-addon',
            'enable' => '0',
        ]);
        $personSeite = $this->newClient()->get('/person?id=' . $personId);
        $this->assertStringNotContainsString('HOOK-PERSON-MARKER', $personSeite->body);
    }

    private function installHookPlugin(): void {
        @mkdir(self::PLUGIN_DEST, 0755, true);
        file_put_contents(self::PLUGIN_DEST . '/plugin.json', json_encode([
            'slug' => 'hooktest-addon',
            'name' => 'Hook-Test-Addon',
            'version' => '1.0.0',
            'core_compatibility' => '>=0.1.0-beta.1',
            'core_supported_max' => '9.9',
            'description' => 'Prüft die Erweiterungspunkte der Personen- und Stationsseite.',
            'author' => 'Tests',
            'hooks' => ['person.detail_sections', 'station.detail_sections', 'person.edit_sections', 'station.edit_sections'],
            'entry' => 'Plugin.php',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        file_put_contents(self::PLUGIN_DEST . '/Plugin.php', <<<'PHP'
<?php
namespace Plugin\HooktestAddon;

class Plugin {
    public function register($hooks): void {
        $hooks->addFilter('person.detail_sections', function (array $sections, array $person, array $horsesByRole): array {
            $sections[] = '<div>HOOK-PERSON-MARKER</div>';
            return $sections;
        });
        $hooks->addFilter('station.detail_sections', function (array $sections, array $station, array $horses): array {
            $sections[] = '<div>HOOK-STATION-MARKER</div>';
            return $sections;
        });
        $hooks->addFilter('person.edit_sections', function (array $sections, array $person): array {
            $sections[] = '<div>HOOK-PERSON-EDIT-MARKER</div>';
            return $sections;
        });
        $hooks->addFilter('station.edit_sections', function (array $sections, array $station): array {
            $sections[] = '<div>HOOK-STATION-EDIT-MARKER</div>';
            return $sections;
        });
    }
}
PHP);
    }
}
