<?php
// tests/Functional/ContactGdprHooksTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Kontrakt-Test für `contact.anonymized` und `contact.erased` (Audit N45,
 * siehe docs/plugin-development.md).
 *
 * Addons mit eigenen Daten am Kontakt (Mitgliedsstatus samt
 * CiviCRM-Zuordnung, Opt-outs der Kontaktanfrage) erfuhren von einer
 * DSGVO-Aktion nichts. Bei der Anonymisierung greift kein
 * Fremdschlüssel-CASCADE, und ein Addon ohne FK hinterließ beim endgültigen
 * Löschen Waisen.
 *
 * Zugesichert: genau ein Aufruf je Kontakt und Pfad, NACH dem Commit, mit
 * Anlass 'dsgvo' bzw. 'papierkorb'; kein Aufruf ohne Erfolg; ein werfender
 * Handler bricht nichts ab. Recorder-Plugin nach dem Muster von
 * ContactMergeHookTest.
 */
class ContactGdprHooksTest extends FunctionalTestCase {

    private const SLUG = 'contact-gdpr-hook-recorder';
    private const PLUGIN_DEST = __DIR__ . '/../../plugins/' . self::SLUG;

    /** @var int[] */
    private array $contactIds = [];

    protected function tearDown(): void {
        foreach ($this->contactIds as $id) {
            Database::getInstance()->prepare("DELETE FROM contacts WHERE id = ?")->execute([$id]);
        }
        self::removePluginDir();
        @unlink(self::recordFile());
        parent::tearDown();
    }

    private static function recordFile(): string {
        return sys_get_temp_dir() . '/hv-contact-gdpr-hook-recorder.jsonl';
    }

    public function testAllErasurePathsFireExactlyOnceAfterCommit(): void {
        $admin = $this->authenticatedClient();
        $this->enableRecorder($admin);
        $db = Database::getInstance();

        try {
            $u = uniqid();
            $anon = $this->kontakt("Hook Anon {$u}");
            $dsgvo = $this->kontakt("Hook DSGVO WIRFT {$u}");
            $einzeln = $this->kontakt("Hook Papierkorb {$u}", true);
            $leer1 = $this->kontakt("Hook Leeren A {$u}", true);
            $leer2 = $this->kontakt("Hook Leeren B {$u}", true);

            $r = $this->gdpr($admin, 'anonymize-person', $anon);
            $this->assertSame("/admin/gdpr?success=anonymized&person_id={$anon}", $r->location(), $r->body);
            $r = $this->gdpr($admin, 'delete-person', $dsgvo);
            $this->assertSame("/admin/gdpr?success=deleted&person_id={$dsgvo}", $r->location(), $r->body);
            $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM contacts WHERE id = {$dsgvo}")->fetchColumn(), 'Ein werfender Handler rollt nichts zurück');

            $r = $admin->post('/admin/trash/permanent-delete', [
                'csrf_token' => $this->currentCsrfToken($admin), 'type' => 'contact', 'id' => (string)$einzeln,
            ]);
            $this->assertSame('/admin/trash?success=purged', $r->location(), $r->body);
            $r = $admin->post('/admin/trash/empty', ['csrf_token' => $this->currentCsrfToken($admin)]);
            $this->assertSame('/admin/trash?success=emptied', $r->location(), $r->body);

            // Kein Erfolg, kein Hook.
            $r = $this->gdpr($admin, 'delete-person', $dsgvo);
            $this->assertStringContainsString('error=not_found', (string)$r->location());

            $e = $this->events();
            $this->assertSame([['anonymized', $anon, null, "Hook Anon {$u}", "Anonymisierte Person (#{$anon})"]], $this->fuer($e, $anon));
            $this->assertSame([['erased', $dsgvo, 'dsgvo', "Hook DSGVO WIRFT {$u}", null]], $this->fuer($e, $dsgvo));
            $this->assertSame([['erased', $einzeln, 'papierkorb', "Hook Papierkorb {$u}", null]], $this->fuer($e, $einzeln));
            $this->assertSame([['erased', $leer1, 'papierkorb', "Hook Leeren A {$u}", null]], $this->fuer($e, $leer1));
            $this->assertSame([['erased', $leer2, 'papierkorb', "Hook Leeren B {$u}", null]], $this->fuer($e, $leer2));
        } finally {
            $this->disableRecorder($admin);
        }
    }

    // ---- Helfer --------------------------------------------------------

    private function gdpr(\Tests\Support\HttpClient $admin, string $aktion, int $id): \Tests\Support\HttpResponse {
        return $admin->post('/admin/gdpr/' . $aktion, [
            'csrf_token' => $this->currentCsrfToken($admin),
            'person_id' => (string)$id,
            'request_id' => '0',
        ]);
    }

    private function kontakt(string $name, bool $papierkorb = false): int {
        $db = Database::getInstance();
        $db->prepare("INSERT INTO contacts (name, deleted_at) VALUES (?, " . ($papierkorb ? 'NOW()' : 'NULL') . ")")->execute([$name]);
        $id = (int)$db->lastInsertId();
        $this->contactIds[] = $id;
        return $id;
    }

    /** @return array<int, array<string, mixed>> */
    private function events(): array {
        if (!is_file(self::recordFile())) {
            return [];
        }
        return array_map(
            static fn(string $z): array => (array)json_decode($z, true),
            file(self::recordFile(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []
        );
    }

    /** @return array<int, array<int, mixed>> hook, id, anlass, name im Payload, name in der DB */
    private function fuer(array $events, int $id): array {
        $treffer = [];
        foreach ($events as $e) {
            if ((int)$e['id'] === $id) {
                $treffer[] = [$e['hook'], (int)$e['id'], $e['anlass'], $e['name'], $e['db_name']];
            }
        }
        return $treffer;
    }

    private function enableRecorder(\Tests\Support\HttpClient $admin): void {
        self::installPluginFixture();
        $response = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $response->location(), $response->body);
    }

    private function disableRecorder(\Tests\Support\HttpClient $admin): void {
        $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '0',
        ]);
    }

    private static function installPluginFixture(): void {
        self::removePluginDir();
        @unlink(self::recordFile());
        mkdir(self::PLUGIN_DEST, 0777, true);

        file_put_contents(self::PLUGIN_DEST . '/plugin.json', json_encode([
            'slug' => self::SLUG,
            'name' => 'Contact-GDPR-Hook-Recorder (Test-Fixture)',
            'version' => '1.0.0',
            'core_compatibility' => '>=0.1.0-beta.1',
            'core_supported_max' => '9.9',
            'description' => 'Zeichnet contact.anonymized und contact.erased für den Kontrakt-Test auf.',
            'author' => 'tests/Functional/ContactGdprHooksTest',
            'hooks' => ['contact.anonymized', 'contact.erased'],
            'entry' => 'Plugin.php',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        file_put_contents(self::PLUGIN_DEST . '/Plugin.php', <<<'PHP'
<?php
// Test-Fixture: zeichnet contact.anonymized und contact.erased als JSON-Zeilen auf.

namespace Plugin\ContactGdprHookRecorder;

use App\Database;
use App\Plugin\HookManager;

class Plugin {

    public function register(HookManager $hooks): void {
        $hooks->addAction('contact.anonymized', [$this, 'onAnonymized']);
        $hooks->addAction('contact.erased', [$this, 'onErased']);
    }

    public function onAnonymized(int $contactId, array $vorher): void {
        $this->record('anonymized', $contactId, null, $vorher);
    }

    public function onErased(int $contactId, array $contact, string $anlass): void {
        $this->record('erased', $contactId, $anlass, $contact);
        if (str_contains((string)($contact['name'] ?? ''), 'WIRFT')) {
            throw new \RuntimeException('Absichtlicher Fehler im Test-Handler');
        }
    }

    private function record(string $hook, int $id, ?string $anlass, array $zeile): void {
        // Eigene Abfrage: Der Handler sieht den festgeschriebenen Stand.
        $stmt = Database::getInstance()->prepare("SELECT name FROM contacts WHERE id = ?");
        $stmt->execute([$id]);
        $dbName = $stmt->fetchColumn();
        file_put_contents(sys_get_temp_dir() . '/hv-contact-gdpr-hook-recorder.jsonl', json_encode([
            'hook' => $hook, 'id' => $id, 'anlass' => $anlass,
            'name' => $zeile['name'] ?? null, 'db_name' => $dbName === false ? null : $dbName,
        ]) . "\n", FILE_APPEND | LOCK_EX);
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
