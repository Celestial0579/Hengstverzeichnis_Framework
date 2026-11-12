<?php
// tests/Functional/ContactActionHookTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Die Actions beim Anlegen, Ändern und Löschen eines Kontakts - und der
 * Nachweis, dass die alten Namen `person.*` und `station.*` dabei NICHT mehr
 * feuern (#512).
 *
 * Seit v0.9.0 (#347) feuert der Kern nur noch `contact.after_save` und
 * `contact.deleted`. Für die FILTER der Kontaktseite hält
 * ContactSectionHookTest das fest; für die Actions fehlte das Gegenstück:
 * `contact.after_save` kam in keinem Test des Kerns vor, `contact.deleted` nur
 * beim Zusammenführen (ContactMergeHookTest), und kein Test verlangte, dass
 * `person.after_save`, `station.after_save`, `person.deleted` und
 * `station.deleted` stumm bleiben.
 *
 * WAS OHNE DIESEN TEST UNBEMERKT ZURÜCKKÄME. Wer die Auffächerung für Actions
 * wieder einführt - etwa als Kompatibilitätsbrücke für einen Betreiber, der
 * sich nach dem Update meldet -, lässt ein Addon aus der 0.7-Linie, das
 * `contact.after_save` UND `person.after_save` registriert hat, jede
 * Kontaktänderung doppelt zählen. Das ist der Zählfehler, dessentwegen die
 * Aliasse fielen. Im Addons-Repo ist der Positivpfad gedeckt, aber gegen den
 * dort festgeschriebenen Stand des Kerns - in der CI des Kerns selbst griff
 * nichts.
 *
 * Geprüft wird mit einem Test-Plugin, das ALLE sechs Namen registriert - so,
 * wie es ein Addon aus der Zeit der Aliasse täte - und jeden Aufruf mit dem
 * Namen des Hooks in eine Datei schreibt. Dass `contact.*` darin steht, ist
 * zugleich die Gegenprobe: Der Aufzeichner funktioniert, er hätte einen Alias
 * also gesehen.
 */
class ContactActionHookTest extends FunctionalTestCase {

    private const SLUG = 'contact-action-hook-recorder';
    private const PLUGIN_DEST = __DIR__ . '/../../plugins/' . self::SLUG;

    /** Die mit v0.9.0 entfallenen Namen. Das Test-Plugin registriert sie weiterhin. */
    private const ENTFALLENE_ALIASSE = [
        'person.after_save',
        'station.after_save',
        'person.deleted',
        'station.deleted',
    ];

    /** @var list<int> */
    private array $kontaktIds = [];

    protected function tearDown(): void {
        self::removePluginDir();
        @unlink(self::recordFile());
        try {
            $db = Database::getInstance();
            $db->prepare('DELETE FROM plugins WHERE slug = ?')->execute([self::SLUG]);
            foreach ($this->kontaktIds as $id) {
                $db->prepare('DELETE FROM contacts WHERE id = ?')->execute([$id]);
            }
        } catch (\Throwable $e) {
            // DB weg = nichts zu bereinigen
        }
        $this->kontaktIds = [];
        parent::tearDown();
    }

    private static function recordFile(): string {
        return sys_get_temp_dir() . '/hv-contact-action-hook-recorder.jsonl';
    }

    /**
     * Die seit dem letzten Aufruf aufgezeichneten Hook-Aufrufe; leert die
     * Aufzeichnung.
     *
     * @return list<array<string, mixed>>
     */
    private function aufzeichnung(): array {
        clearstatcache();
        $zeilen = is_file(self::recordFile())
            ? (file(self::recordFile(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])
            : [];
        @unlink(self::recordFile());

        $ereignisse = [];
        foreach ($zeilen as $zeile) {
            $ereignis = json_decode($zeile, true);
            $this->assertIsArray($ereignis, "Unlesbare Zeile in der Aufzeichnung: {$zeile}");
            $ereignisse[] = $ereignis;
        }

        return $ereignisse;
    }

    public function testNurDieContactActionsFeuern(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        self::installPluginFixture();
        $toggle = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggle->location(), "Aktivieren fehlgeschlagen: {$toggle->body}");
        $this->aufzeichnung();

        // 1. Anlegen.
        $name = "Action Kontakt {$unique}";
        $formular = $admin->get('/admin/contacts/create');
        $admin->post('/admin/contacts/store', [
            'csrf_token' => $formular->formField('csrf_token') ?? '',
            'name' => $name,
            'is_published' => '1',
        ]);
        $stmt = $db->prepare('SELECT id FROM contacts WHERE name = ?');
        $stmt->execute([$name]);
        $id = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $id, 'Der Testkontakt wurde nicht angelegt.');
        $this->kontaktIds[] = $id;

        $this->assertSame(
            [['hook' => 'contact.after_save', 'id' => $id, 'neu' => true, 'name' => $name]],
            $this->aufzeichnung(),
            'Beim Anlegen feuert genau einmal contact.after_save. Steht hier mehr, sind die mit v0.9.0 '
            . 'entfernten Aliasse zurück - und ein Addon, das beide Namen registriert hat, zählt doppelt.'
        );

        // 2. Ändern.
        $geaendert = "{$name} geändert";
        $bearbeiten = $admin->get('/admin/contacts/edit?id=' . $id);
        $admin->post('/admin/contacts/update', [
            'csrf_token' => $bearbeiten->formField('csrf_token') ?? '',
            'id' => (string)$id,
            'name' => $geaendert,
            'is_published' => '1',
        ]);
        $stmt = $db->prepare('SELECT name FROM contacts WHERE id = ?');
        $stmt->execute([$id]);
        $this->assertSame($geaendert, $stmt->fetchColumn(), 'Die Änderung wurde nicht gespeichert.');

        $this->assertSame(
            [['hook' => 'contact.after_save', 'id' => $id, 'neu' => false, 'name' => $geaendert]],
            $this->aufzeichnung(),
            'Beim Ändern feuert genau einmal contact.after_save.'
        );

        // 3. Löschen (Papierkorb).
        $admin->post('/admin/contacts/delete', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'id' => (string)$id,
        ]);
        $stmt = $db->prepare('SELECT deleted_at FROM contacts WHERE id = ?');
        $stmt->execute([$id]);
        $this->assertNotNull($stmt->fetchColumn(), 'Der Kontakt liegt nicht im Papierkorb.');

        $geloescht = $this->aufzeichnung();
        $this->assertSame(
            [['hook' => 'contact.deleted', 'id' => $id, 'name' => $geaendert]],
            $geloescht,
            'Beim Löschen feuert genau einmal contact.deleted.'
        );

        // Und ausdrücklich, falls die Listen oben einmal anders geprüft
        // werden: Keiner der entfallenen Namen darf je aufgezeichnet sein.
        // (Die drei Aufzeichnungen oben sind bereits vollständig verglichen;
        // diese Schleife benennt den Alias, wenn es doch geschieht.)
        foreach (self::ENTFALLENE_ALIASSE as $alias) {
            $this->assertNotContains($alias, array_column($geloescht, 'hook'), "Der Alias {$alias} ist mit v0.9.0 entfallen.");
        }
    }

    private static function installPluginFixture(): void {
        self::removePluginDir();
        @unlink(self::recordFile());
        mkdir(self::PLUGIN_DEST, 0777, true);

        file_put_contents(self::PLUGIN_DEST . '/plugin.json', json_encode([
            'slug' => self::SLUG,
            'name' => 'Contact-Action-Hook-Recorder (Test-Fixture)',
            'version' => '1.0.0',
            'core_compatibility' => '>=0.1.0-beta.1',
            'core_supported_max' => '9.9',
            'description' => 'Zeichnet contact.after_save und contact.deleted auf - und die entfallenen Aliasse, falls sie feuern.',
            'author' => 'tests/Functional/ContactActionHookTest',
            'hooks' => array_merge(['contact.after_save', 'contact.deleted'], self::ENTFALLENE_ALIASSE),
            'entry' => 'Plugin.php',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        file_put_contents(self::PLUGIN_DEST . '/Plugin.php', <<<'PHP'
<?php
// Test-Fixture: zeichnet die Kontakt-Actions als JSON-Zeilen auf - unter den
// neuen UND unter den mit v0.9.0 entfallenen Namen, wie ein Addon aus der
// Zeit der Aliasse.

namespace Plugin\ContactActionHookRecorder;

use App\Plugin\HookManager;

class Plugin {

    public function register(HookManager $hooks): void {
        foreach (['contact.after_save', 'person.after_save', 'station.after_save'] as $hook) {
            $hooks->addAction($hook, function (int $id, array $post, bool $neu) use ($hook): void {
                $this->record(['hook' => $hook, 'id' => $id, 'neu' => $neu, 'name' => $post['name'] ?? null]);
            });
        }
        foreach (['contact.deleted', 'person.deleted', 'station.deleted'] as $hook) {
            $hooks->addAction($hook, function (int $id, array $contact) use ($hook): void {
                $this->record(['hook' => $hook, 'id' => $id, 'name' => $contact['name'] ?? null]);
            });
        }
    }

    private function record(array $event): void {
        file_put_contents(
            sys_get_temp_dir() . '/hv-contact-action-hook-recorder.jsonl',
            json_encode($event) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
}
PHP);
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
}
