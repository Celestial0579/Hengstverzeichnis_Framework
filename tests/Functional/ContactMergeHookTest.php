<?php
// tests/Functional/ContactMergeHookTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Kontrakt-Test für `contact.merged` (Audit M33, siehe
 * docs/plugin-development.md).
 *
 * Zusammenführen legt einen Datensatz still. Addons, die eigene Daten an
 * einem Kontakt führen (Opt-out gegen Kontaktanfragen, Mitgliedsstatus),
 * erfuhren davon bis dahin nichts - ihre Daten blieben an der Quelle hängen
 * und verschwanden beim Leeren des Papierkorbs über den FK-CASCADE. Jetzt
 * feuert nach dem Commit `contact.merged` und danach `contact.deleted` für die
 * Quelle.
 *
 * Zugesichert und hier geprüft:
 * - Reihenfolge merged -> deleted, genau einmal je Vorgang.
 * - Payload: Quelle im Stand VOR dem Zusammenführen, Ziel im Stand DANACH.
 * - Die Hooks feuern NACH dem Commit: Ein Handler, der selbst in die
 *   Datenbank schaut, sieht die Quelle im Papierkorb und contact_id_map
 *   umgehängt.
 * - Ein Handler, der wirft, rollt nichts zurück.
 * - Ohne Erfolg (merge_invalid, CSRF-Fehler) feuert nichts.
 *
 * Recorder-Plugin nach dem Muster von HorseDeleteHooksTest: Die JSONL-Datei
 * liegt außerhalb des Plugin-Verzeichnisses, weil die Integritätsprüfung
 * des PluginManagers das Plugin sonst nach dem ersten Schreiben abschaltet.
 */
class ContactMergeHookTest extends FunctionalTestCase {

    private const SLUG = 'contact-merge-hook-recorder';
    private const PLUGIN_DEST = __DIR__ . '/../../plugins/' . self::SLUG;

    /** @var array<int, array{0: string, 1: int}> Selbst angelegte Alt-Kennungen */
    private array $alteKennungen = [];

    protected function tearDown(): void {
        $weg = Database::getInstance()->prepare("DELETE FROM contact_id_map WHERE old_type = ? AND old_id = ?");
        foreach ($this->alteKennungen as [$typ, $alteId]) {
            $weg->execute([$typ, $alteId]);
        }
        self::removePluginDir();
        @unlink(self::recordFile());
        parent::tearDown();
    }

    private static function recordFile(): string {
        return sys_get_temp_dir() . '/hv-contact-merge-hook-recorder.jsonl';
    }

    public function testMergedFiresAfterCommitThenDeletedAndSurvivesAThrowingHandler(): void {
        $admin = $this->authenticatedClient();
        $this->enableRecorder($admin);
        $db = Database::getInstance();

        try {
            $unique = uniqid();
            $zielId = $this->kontakt($admin, "Hook Ziel {$unique}", []);
            $quelleId = $this->kontakt($admin, "Hook Quelle {$unique}", ['email' => "hook-{$unique}@example.com"]);
            $alteId = $this->alteKennung($quelleId);

            $response = $this->zusammenfuehren($admin, $quelleId, $zielId);
            $this->assertStringStartsWith('/admin/contacts?success=merged', (string)$response->location(), $response->body);

            $events = $this->recordedEventsFor($quelleId);
            $this->assertSame(
                [['merged', $quelleId, $zielId], ['deleted', $quelleId, null]],
                array_map(static fn(array $e): array => [$e['hook'], $e['source_id'], $e['target_id']], $events),
                'Genau einmal contact.merged, danach contact.deleted für die Quelle'
            );

            $merged = $events[0];
            $this->assertNull($merged['source_deleted_at'], '$source ist der Stand VOR dem Zusammenführen');
            $this->assertSame("Hook Quelle {$unique}", $merged['source_name']);
            $this->assertSame("hook-{$unique}@example.com", $merged['target_email'], '$target ist der Stand DANACH, mit dem aufgefüllten Feld');
            // Beleg "nach dem Commit": Der Handler liest über eine eigene
            // Abfrage den festgeschriebenen Stand.
            $this->assertNotNull($merged['db_source_deleted_at'], 'Im Handler liegt die Quelle bereits im Papierkorb');
            $this->assertSame($zielId, $merged['db_map_contact'][(string)$alteId] ?? null, 'Im Handler ist contact_id_map bereits umgehängt');

            $this->assertNull($events[1]['source_deleted_at'], 'contact.deleted erhält wie bei delete() den Stand vor dem Papierkorb');

            // Ein Handler, der wirft: Der Merge bleibt bestehen.
            $zielWirft = $this->kontakt($admin, "Hook Ziel WIRFT {$unique}", []);
            $quelleWirft = $this->kontakt($admin, "Hook Quelle 2 {$unique}", []);
            $response = $this->zusammenfuehren($admin, $quelleWirft, $zielWirft);
            $this->assertStringStartsWith('/admin/contacts?success=merged', (string)$response->location(), $response->body);
            $stmt = $db->prepare("SELECT deleted_at FROM contacts WHERE id = ?");
            $stmt->execute([$quelleWirft]);
            $this->assertNotNull($stmt->fetchColumn(), 'Ein Addon-Fehler darf den Merge nicht zurückrollen');
            $this->assertSame(
                ['merged', 'deleted'],
                array_column($this->recordedEventsFor($quelleWirft), 'hook'),
                'contact.deleted feuert auch nach einem werfenden contact.merged-Handler'
            );
        } finally {
            $this->disableRecorder($admin);
        }
    }

    public function testNothingFiresWhenTheMergeIsRefused(): void {
        $admin = $this->authenticatedClient();
        $this->enableRecorder($admin);
        $db = Database::getInstance();

        try {
            $unique = uniqid();
            $zielId = $this->kontakt($admin, "Kein Hook Ziel {$unique}", []);
            $quelleId = $this->kontakt($admin, "Kein Hook Quelle {$unique}", []);

            $response = $this->zusammenfuehren($admin, $quelleId, $quelleId);
            $this->assertSame('/admin/contacts?error=merge_invalid', $response->location());

            $response = $admin->post('/admin/contacts/merge', [
                'csrf_token' => 'ungültig',
                'source_id' => (string)$quelleId,
                'target_id' => (string)$zielId,
            ]);
            $this->assertSame(403, $response->statusCode);

            // Die Quelle liegt schon im Papierkorb: merge_invalid, obwohl die
            // Prüfung jetzt erst unter Sperre in der Transaktion geschieht.
            $db->prepare("UPDATE contacts SET deleted_at = NOW() WHERE id = ?")->execute([$quelleId]);
            $response = $this->zusammenfuehren($admin, $quelleId, $zielId);
            $this->assertSame('/admin/contacts?error=merge_invalid', $response->location());

            $this->assertSame([], $this->recordedEventsFor($quelleId), 'Ohne erfolgreichen Merge darf kein Hook feuern');
        } finally {
            $this->disableRecorder($admin);
        }
    }

    // ---- Helfer --------------------------------------------------------

    private function zusammenfuehren(\Tests\Support\HttpClient $admin, int $quelleId, int $zielId): \Tests\Support\HttpResponse {
        return $admin->post('/admin/contacts/merge', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'source_id' => (string)$quelleId,
            'target_id' => (string)$zielId,
        ]);
    }

    private function alteKennung(int $kontaktId): int {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT COALESCE(MAX(old_id), 0) + 1 FROM contact_id_map WHERE old_type = 'person'");
        $stmt->execute();
        $alteId = (int)$stmt->fetchColumn();
        $db->prepare("INSERT INTO contact_id_map (old_type, old_id, contact_id) VALUES ('person', ?, ?)")
           ->execute([$alteId, $kontaktId]);
        $this->alteKennungen[] = ['person', $alteId];
        return $alteId;
    }

    /** @param array<string, string> $felder */
    private function kontakt(\Tests\Support\HttpClient $admin, string $name, array $felder): int {
        $admin->post('/admin/contacts/store', array_merge([
            'csrf_token' => $this->currentCsrfToken($admin),
            'name' => $name,
        ], $felder));
        $stmt = Database::getInstance()->prepare("SELECT id FROM contacts WHERE name = ?");
        $stmt->execute([$name]);
        $id = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $id, "Kontakt '{$name}' wurde nicht angelegt");
        return $id;
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recordedEventsFor(int $quelleId): array {
        if (!is_file(self::recordFile())) {
            return [];
        }
        $events = [];
        foreach (file(self::recordFile(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $event = json_decode($line, true);
            if (is_array($event) && (int)$event['source_id'] === $quelleId) {
                $events[] = $event;
            }
        }
        return $events;
    }

    private static function installPluginFixture(): void {
        self::removePluginDir();
        @unlink(self::recordFile());
        mkdir(self::PLUGIN_DEST, 0777, true);

        file_put_contents(self::PLUGIN_DEST . '/plugin.json', json_encode([
            'slug' => self::SLUG,
            'name' => 'Contact-Merge-Hook-Recorder (Test-Fixture)',
            'version' => '1.0.0',
            'core_compatibility' => '>=0.1.0-beta.1',
            'core_supported_max' => '9.9',
            'description' => 'Zeichnet contact.merged und contact.deleted für den Kontrakt-Test auf.',
            'author' => 'tests/Functional/ContactMergeHookTest',
            'hooks' => ['contact.merged', 'contact.deleted'],
            'entry' => 'Plugin.php',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        file_put_contents(self::PLUGIN_DEST . '/Plugin.php', <<<'PHP'
<?php
// Test-Fixture: zeichnet contact.merged und contact.deleted als JSON-Zeilen auf.

namespace Plugin\ContactMergeHookRecorder;

use App\Database;
use App\Plugin\HookManager;

class Plugin {

    public function register(HookManager $hooks): void {
        $hooks->addAction('contact.merged', [$this, 'onMerged']);
        $hooks->addAction('contact.deleted', [$this, 'onDeleted']);
    }

    public function onMerged(int $sourceId, int $targetId, array $source, array $target): void {
        // Eigene Abfrage: Belegt, dass der Hook den festgeschriebenen Stand sieht.
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT deleted_at FROM contacts WHERE id = ?");
        $stmt->execute([$sourceId]);
        $dbDeletedAt = $stmt->fetchColumn();
        $map = [];
        foreach ($db->query("SELECT old_id, contact_id FROM contact_id_map WHERE old_type = 'person'")->fetchAll() as $zeile) {
            if ((int)$zeile['contact_id'] === $targetId || (int)$zeile['contact_id'] === $sourceId) {
                $map[(string)$zeile['old_id']] = (int)$zeile['contact_id'];
            }
        }
        $this->record([
            'hook' => 'merged',
            'source_id' => $sourceId,
            'target_id' => $targetId,
            'source_name' => $source['name'] ?? null,
            'source_deleted_at' => $source['deleted_at'] ?? null,
            'target_email' => $target['email'] ?? null,
            'db_source_deleted_at' => $dbDeletedAt === false ? null : $dbDeletedAt,
            'db_map_contact' => $map,
        ]);
        if (str_contains((string)($target['name'] ?? ''), 'WIRFT')) {
            throw new \RuntimeException('Absichtlicher Fehler im Test-Handler');
        }
    }

    public function onDeleted(int $contactId, array $contact): void {
        $this->record([
            'hook' => 'deleted',
            'source_id' => $contactId,
            'target_id' => null,
            'source_deleted_at' => $contact['deleted_at'] ?? null,
        ]);
    }

    private function record(array $event): void {
        file_put_contents(sys_get_temp_dir() . '/hv-contact-merge-hook-recorder.jsonl', json_encode($event) . "\n", FILE_APPEND | LOCK_EX);
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
