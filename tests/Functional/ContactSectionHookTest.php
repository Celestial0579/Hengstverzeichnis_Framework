<?php
// tests/Functional/ContactSectionHookTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Die Erweiterungspunkte der Kontaktseite: `contact.detail_sections` und
 * `contact.edit_sections` - samt der Aliasse `person.*` und `station.*`.
 *
 * Anlass ist die geplante Kontaktanfrage
 * ([Addons #106](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/106)):
 * Ein Addon soll auf der Kontaktseite ein Formular anbieten können, **ohne**
 * dass die Adresse dafür öffentlich werden muss. Ohne diese Hooks gäbe es dort
 * keinen Platz dafür.
 *
 * Mit #336 sind aus Personen- und Stationsseite eine Kontaktseite geworden,
 * und aus den beiden Hook-Namen einer. Die alten Namen bleiben bis v0.9.0 als
 * ALIAS bestehen und feuern zusätzlich mit denselben Argumenten - das ist eine
 * Zusage an Addons, die bereits im Feld sind (siehe
 * docs/kontaktliste-umstellung.md und docs/plugin-development.md). Eine Zusage,
 * die niemand prüft, ist keine: Deshalb hält dieser Test nicht nur fest, DASS
 * die Aliasse feuern, sondern auch, dass sie DIESELBEN Argumente bekommen und
 * in der dokumentierten Reihenfolge laufen (contact.* -> person.* ->
 * station.*, jeweils auf dem Ergebnis des vorherigen).
 *
 * Geprüft wird mit einem eigenen Test-Plugin, das für die Testdauer in das
 * gitignorete `plugins/`-Verzeichnis geschrieben wird - dasselbe Muster wie in
 * HorseDetailSectionsHookTest. Es hängt je Hook einen Abschnitt an, der den
 * Hook-Namen und einen Fingerabdruck der empfangenen Argumente trägt.
 */
class ContactSectionHookTest extends FunctionalTestCase {

    private const PLUGIN_DEST = __DIR__ . '/../../plugins/hooktest-addon';

    /** Sichtbarer Marker jedes eingehängten Abschnitts. */
    private const MARKER = 'HOOK-KONTAKT-MARKER';

    /**
     * Die dokumentierte Reihenfolge der Kette. Sie ist keine Kosmetik: Für
     * Filter ist sie das, was am Ende im HTML steht - ein Addon, das den alten
     * Namen registriert hat, muss den Beitrag des neuen VORFINDEN und nicht
     * überschreiben.
     *
     * @var array<int, string>
     */
    private const DETAIL_KETTE = [
        'contact.detail_sections',
        'person.detail_sections',
        'station.detail_sections',
    ];

    /** @var array<int, string> */
    private const EDIT_KETTE = [
        'contact.edit_sections',
        'person.edit_sections',
        'station.edit_sections',
    ];

    /** @var array<int, int> */
    private array $contactIds = [];
    /** @var array<int, int> */
    private array $horseIds = [];

    protected function tearDown(): void {
        foreach (glob(self::PLUGIN_DEST . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir(self::PLUGIN_DEST);
        try {
            $db = Database::getInstance();
            $db->prepare("DELETE FROM plugins WHERE slug = ?")->execute(['hooktest-addon']);
            foreach ($this->horseIds as $id) {
                $db->prepare("DELETE FROM horses WHERE id = ?")->execute([$id]);
            }
            foreach ($this->contactIds as $id) {
                $db->prepare("DELETE FROM contacts WHERE id = ?")->execute([$id]);
            }
        } catch (\Throwable $e) {
            // DB weg = nichts zu bereinigen
        }
        $this->horseIds = [];
        $this->contactIds = [];
        parent::tearDown();
    }

    public function testContactPageOffersTheExtensionPointAndFiresTheOldNamesAsAliases(): void {
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

        // Ein veröffentlichter Kontakt über den echten Admin-Endpunkt. Seit
        // #336 gibt es dafür genau eine Maske - die getrennten Formulare für
        // Personen und Stationen sind mit den Tabellen entfallen.
        $kontaktName = "Hook Kontakt {$unique}";
        $form = $admin->get('/admin/contacts/create');
        $admin->post('/admin/contacts/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $kontaktName,
            'is_published' => '1',
        ]);
        $stmt = $db->prepare("SELECT id FROM contacts WHERE name = ?");
        $stmt->execute([$kontaktName]);
        $kontaktId = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $kontaktId, 'Der Testkontakt wurde nicht angelegt');
        $this->contactIds[] = $kontaktId;

        // Ein Pferd, das an BEIDEN Steckplätzen derselben Zuordnungszeile
        // hängt: als Person (Züchter) und als Deckstation. Damit sind
        // $horsesByRole und $stationHorses beide gefüllt - ein Alias, der
        // seine Argumente verlöre oder nur teilweise bekäme, fiele sonst
        // nicht auf, weil leere Arrays untereinander gleich aussehen.
        $db->prepare("INSERT INTO horses (name, sex, is_published, created_at) VALUES (?, 'stallion', 1, NOW())")
           ->execute(["Hook Pferd {$unique}"]);
        $pferdId = (int)$db->lastInsertId();
        $this->horseIds[] = $pferdId;
        $db->prepare(
            "INSERT INTO horse_persons (horse_id, contact_id, role, station_contact_id)
             VALUES (?, ?, 'breeder', ?)"
        )->execute([$pferdId, $kontaktId, $kontaktId]);

        $gast = $this->newClient();
        $seite = $gast->get('/kontakt?id=' . $kontaktId);
        $this->assertSame(200, $seite->statusCode, "Die Kontaktseite ist nicht erreichbar: {$seite->body}");

        $gefeuert = $this->hookAufrufeIn($seite->body);

        $this->assertSame(
            self::DETAIL_KETTE,
            array_keys($gefeuert),
            'Der neue Hook contact.detail_sections muss feuern - und die alten Namen als Alias hinterher, '
            . 'in der dokumentierten Reihenfolge'
        );
        $this->assertCount(
            1,
            array_unique(array_values($gefeuert)),
            'Die Aliasse müssen DIESELBEN Argumente bekommen wie contact.detail_sections'
        );

        // Und der Fingerabdruck darf nicht leer sein - sonst wäre "alle gleich"
        // trivial erfüllt.
        $fingerabdruck = reset($gefeuert);
        $this->assertStringContainsString("id={$kontaktId};", $fingerabdruck, 'Der Hook bekommt den Kontakt selbst');
        $this->assertStringContainsString('rollen=breeder;', $fingerabdruck, 'Und die Pferde nach Rolle gruppiert');
        $this->assertStringContainsString('stationspferde=1', $fingerabdruck, 'Und die Pferde, die hier stehen');

        // Das Admin-Formular - dort hängt das Opt-out des
        // Kontaktanfrage-Addons dran, statt einer Spalte im Kern.
        $editSeite = $admin->get('/admin/contacts/edit?id=' . $kontaktId);
        $editHooks = $this->hookAufrufeIn($editSeite->body);
        $this->assertSame(
            self::EDIT_KETTE,
            array_keys($editHooks),
            'contact.edit_sections und seine beiden Aliasse müssen im Bearbeitungsformular feuern'
        );
        $this->assertCount(
            1,
            array_unique(array_values($editHooks)),
            'Auch im Formular bekommen die Aliasse dieselben Argumente'
        );
        $this->assertStringContainsString("id={$kontaktId};", (string)reset($editHooks));

        // Gegenprobe: Ohne aktives Plugin bleibt kein leerer Rahmen stehen.
        $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => 'hooktest-addon',
            'enable' => '0',
        ]);
        $ohnePlugin = $this->newClient()->get('/kontakt?id=' . $kontaktId);
        $this->assertStringNotContainsString(self::MARKER, $ohnePlugin->body);
        $this->assertSame([], $this->hookAufrufeIn($ohnePlugin->body));
    }

    /**
     * Liest die vom Test-Plugin hinterlassenen Marker aus dem HTML.
     *
     * @return array<string, string> Hook-Name => Fingerabdruck der Argumente,
     *         in der Reihenfolge des Auftretens im Dokument (= Reihenfolge der
     *         Filterkette, weil die View die Abschnitte in Array-Reihenfolge
     *         ausgibt).
     */
    private function hookAufrufeIn(string $html): array {
        preg_match_all('/data-hook="([a-z._]+)" data-args="([^"]*)"/', $html, $treffer, PREG_SET_ORDER);
        $aufrufe = [];
        foreach ($treffer as $t) {
            $aufrufe[$t[1]] = html_entity_decode($t[2], ENT_QUOTES, 'UTF-8');
        }
        return $aufrufe;
    }

    private function installHookPlugin(): void {
        @mkdir(self::PLUGIN_DEST, 0755, true);
        file_put_contents(self::PLUGIN_DEST . '/plugin.json', json_encode([
            'slug' => 'hooktest-addon',
            'name' => 'Hook-Test-Addon',
            'version' => '1.0.0',
            'core_compatibility' => '>=0.1.0-beta.1',
            'core_supported_max' => '9.9',
            'description' => 'Prüft die Erweiterungspunkte der Kontaktseite samt ihrer Aliasse.',
            'author' => 'Tests',
            'hooks' => [
                'contact.detail_sections', 'person.detail_sections', 'station.detail_sections',
                'contact.edit_sections', 'person.edit_sections', 'station.edit_sections',
            ],
            'entry' => 'Plugin.php',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        file_put_contents(self::PLUGIN_DEST . '/Plugin.php', <<<'PHP'
<?php
namespace Plugin\HooktestAddon;

class Plugin {

    public function register($hooks): void {
        // Fingerabdruck der Argumente: Er muss bei contact.* und bei beiden
        // Aliassen Zeichen fuer Zeichen derselbe sein - genau das ist die
        // Zusage "mit denselben Argumenten".
        $fingerabdruck = static function (array $contact, array $horsesByRole, array $stationHorses): string {
            $rollen = array_keys($horsesByRole);
            sort($rollen);
            return 'id=' . ($contact['id'] ?? '?')
                . ';name=' . ($contact['name'] ?? '?')
                . ';rollen=' . implode(',', $rollen)
                . ';pferde=' . array_sum(array_map('count', $horsesByRole))
                . ';stationspferde=' . count($stationHorses);
        };

        $abschnitt = static function (string $hook, string $args): string {
            return '<div data-hook="' . $hook . '" data-args="' . htmlspecialchars($args, ENT_QUOTES) . '">'
                . 'HOOK-KONTAKT-MARKER</div>';
        };

        foreach (['contact.detail_sections', 'person.detail_sections', 'station.detail_sections'] as $hook) {
            $hooks->addFilter(
                $hook,
                static function (array $sections, array $contact, array $horsesByRole, array $stationHorses)
                    use ($hook, $fingerabdruck, $abschnitt): array {
                    $sections[] = $abschnitt($hook, $fingerabdruck($contact, $horsesByRole, $stationHorses));
                    return $sections;
                }
            );
        }

        foreach (['contact.edit_sections', 'person.edit_sections', 'station.edit_sections'] as $hook) {
            $hooks->addFilter(
                $hook,
                static function (array $sections, array $contact) use ($hook, $abschnitt): array {
                    $sections[] = $abschnitt($hook, 'id=' . ($contact['id'] ?? '?') . ';name=' . ($contact['name'] ?? '?'));
                    return $sections;
                }
            );
        }
    }
}
PHP);
    }
}
