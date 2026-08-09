<?php
// tests/Functional/HorseDeleteHooksTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Kontrakt-Test für die Lösch-/Papierkorb-Hooks (#164, siehe
 * docs/plugin-development.md): horse.before_delete / horse.trashed /
 * horse.restored / horse.deleted über den vollen Lebenszyklus
 * anlegen → Papierkorb → wiederherstellen → Papierkorb → endgültig löschen,
 * plus das Papierkorb-Leeren (feuert je Pferd, nicht pauschal).
 *
 * Nutzt ein zur Testlaufzeit generiertes Recorder-Plugin (Muster wie
 * HorseDetailSectionsHookTest mit dem Demo-Plugin), das jede Hook-Ausführung
 * als JSON-Zeile in eine Datei im (gitignoreten) Plugin-Verzeichnis schreibt -
 * die Datei ist der Kanal zwischen dem php -S-Subprozess der App und diesem
 * PHPUnit-Prozess.
 */
class HorseDeleteHooksTest extends FunctionalTestCase {

    private const PLUGIN_DEST = __DIR__ . '/../../plugins/delete-hook-recorder';

    protected function tearDown(): void {
        self::removePluginDir();
        @unlink(self::recordFile());
        parent::tearDown();
    }

    /**
     * Die Aufzeichnung liegt bewusst AUSSERHALB des Plugin-Verzeichnisses: Der
     * PluginManager prüft die Code-Integrität des gesamten Verzeichnisses und
     * lädt das Plugin ab dem nächsten Request nicht mehr, sobald sich dessen
     * Inhalt ändert ("Plugin-Code seit Aktivierung geändert") - eine ins eigene
     * Verzeichnis geschriebene Logdatei schaltet den Recorder also selbst ab.
     */
    private static function recordFile(): string {
        return sys_get_temp_dir() . '/hv-delete-hook-recorder.jsonl';
    }

    public function testDeleteHooksFireAcrossFullLifecycle(): void {
        $admin = $this->authenticatedClient();
        self::installPluginFixture();

        $toggleResponse = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => 'delete-hook-recorder',
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggleResponse->location());

        try {
            $unique = uniqid();
            $lifecycleName = "DeleteHooks Lebenszyklus {$unique}";
            $bulkName = "DeleteHooks Bulk {$unique}";
            $db = Database::getInstance();

            // 1. Pferd anlegen (unveröffentlicht genügt - die Hooks hängen nicht
            // an der öffentlichen Sichtbarkeit).
            $form = $admin->get('/admin/horses/create');
            $csrf = $form->formField('csrf_token') ?? '';
            $response = $admin->post('/admin/horses/store', [
                'csrf_token' => $csrf,
                'name' => $lifecycleName,
                'status' => 'active',
            ]);
            $this->assertSame('/admin/horses?success=created', $response->location());
            $stmt = $db->prepare("SELECT id FROM horses WHERE name = ?");
            $stmt->execute([$lifecycleName]);
            $horseId = (int)$stmt->fetchColumn();
            $this->assertGreaterThan(0, $horseId);

            // 2. Papierkorb -> wiederherstellen -> Papierkorb -> endgültig löschen.
            $response = $admin->post('/admin/horses/delete', ['csrf_token' => $csrf, 'id' => (string)$horseId]);
            $this->assertSame('/admin/horses?success=deleted', $response->location());

            $response = $admin->post('/admin/trash/restore', ['csrf_token' => $csrf, 'type' => 'horse', 'id' => (string)$horseId]);
            $this->assertSame('/admin/trash?success=restored', $response->location());

            $response = $admin->post('/admin/horses/delete', ['csrf_token' => $csrf, 'id' => (string)$horseId]);
            $this->assertSame('/admin/horses?success=deleted', $response->location());

            $response = $admin->post('/admin/trash/permanent-delete', ['csrf_token' => $csrf, 'type' => 'horse', 'id' => (string)$horseId]);
            $this->assertSame('/admin/trash?success=purged', $response->location());

            // 3. Aufzeichnung prüfen: exakte Abfolge und Payload-Kontrakt.
            $events = $this->recordedEventsFor($horseId);
            $this->assertSame([
                ['before_delete', false],
                ['trashed', null],
                ['restored', null],
                ['before_delete', false],
                ['trashed', null],
                ['before_delete', true],
                ['deleted', null],
            ], array_map(fn($e) => [$e['hook'], $e['permanent']], $events));

            // Jede Payload trägt den kompletten Datensatz (hier stellvertretend
            // der Name) - auch beim endgültigen Löschen.
            foreach ($events as $event) {
                $this->assertSame($lifecycleName, $event['name'], "Hook {$event['hook']} muss den vollen Datensatz erhalten");
            }
            // restored liefert den Stand NACH der Wiederherstellung (deleted_at
            // wieder NULL), trashed den Stand VOR dem Soft-Delete.
            $this->assertNull($events[2]['deleted_at'], 'horse.restored: deleted_at muss wieder NULL sein');
            $this->assertNull($events[1]['deleted_at'], 'horse.trashed: Payload ist der Stand vor dem Soft-Delete');

            // 4. Papierkorb-Leeren feuert die Hooks JE Pferd (kein pauschaler
            // Bulk-DELETE mehr an den Plugins vorbei).
            $response = $admin->post('/admin/horses/store', [
                'csrf_token' => $csrf,
                'name' => $bulkName,
                'status' => 'active',
            ]);
            $this->assertSame('/admin/horses?success=created', $response->location());
            $stmt->execute([$bulkName]);
            $bulkId = (int)$stmt->fetchColumn();

            $response = $admin->post('/admin/horses/delete', ['csrf_token' => $csrf, 'id' => (string)$bulkId]);
            $this->assertSame('/admin/horses?success=deleted', $response->location());

            $response = $admin->post('/admin/trash/empty', ['csrf_token' => $csrf]);
            $this->assertSame('/admin/trash?success=emptied', $response->location());

            $bulkEvents = $this->recordedEventsFor($bulkId);
            $bulkHooks = array_map(fn($e) => [$e['hook'], $e['permanent']], $bulkEvents);
            $this->assertSame([
                ['before_delete', false],
                ['trashed', null],
                ['before_delete', true],
                ['deleted', null],
            ], $bulkHooks);
            $this->assertSame($bulkName, end($bulkEvents)['name']);
        } finally {
            $admin->post('/admin/plugins/toggle', [
                'csrf_token' => $this->currentCsrfToken($admin),
                'slug' => 'delete-hook-recorder',
                'enable' => '0',
            ]);
        }
    }

    /**
     * Liest die vom Recorder-Plugin geschriebenen JSON-Zeilen und filtert auf
     * die Pferde-ID - die geteilte Testdatenbank kann beim Papierkorb-Leeren
     * auch Alt-Pferde anderer Testklassen treffen, die hier nicht zählen.
     *
     * @return array<int, array{hook:string, id:int, name:?string, deleted_at:?string, permanent:?bool}>
     */
    private function recordedEventsFor(int $horseId): array {
        $this->assertFileExists(self::recordFile(), 'Recorder-Plugin hat keine Hook-Aufrufe aufgezeichnet');
        $events = [];
        foreach (file(self::recordFile(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $event = json_decode($line, true);
            if (is_array($event) && (int)$event['id'] === $horseId) {
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
            'slug' => 'delete-hook-recorder',
            'name' => 'Delete-Hook-Recorder (Test-Fixture)',
            'version' => '1.0.0',
            'core_compatibility' => '>=0.1.0-beta.1',
            'description' => 'Zeichnet die Lösch-/Papierkorb-Hooks (#164) für den Kontrakt-Test auf.',
            'author' => 'tests/Functional/HorseDeleteHooksTest',
            'hooks' => ['horse.before_delete', 'horse.trashed', 'horse.restored', 'horse.deleted'],
            'entry' => 'Plugin.php',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        file_put_contents(self::PLUGIN_DEST . '/Plugin.php', <<<'PHP'
<?php
// Test-Fixture: zeichnet die Lösch-/Papierkorb-Hooks (#164) als JSON-Zeilen auf.

namespace Plugin\DeleteHookRecorder;

use App\Plugin\HookManager;

class Plugin {

    public function register(HookManager $hooks): void {
        $hooks->addAction('horse.before_delete', [$this, 'onBeforeDelete']);
        $hooks->addAction('horse.trashed', [$this, 'onTrashed']);
        $hooks->addAction('horse.restored', [$this, 'onRestored']);
        $hooks->addAction('horse.deleted', [$this, 'onDeleted']);
    }

    public function onBeforeDelete(int $horseId, array $horse, bool $permanent): void {
        $this->record('before_delete', $horseId, $horse, $permanent);
    }

    public function onTrashed(int $horseId, array $horse): void {
        $this->record('trashed', $horseId, $horse);
    }

    public function onRestored(int $horseId, array $horse): void {
        $this->record('restored', $horseId, $horse);
    }

    public function onDeleted(int $horseId, array $horse): void {
        $this->record('deleted', $horseId, $horse);
    }

    private function record(string $hook, int $horseId, array $horse, ?bool $permanent = null): void {
        // Ausserhalb des Plugin-Verzeichnisses: siehe Kommentar zu recordFile()
        // im Test - die Integritätsprüfung des PluginManagers würde das Plugin
        // sonst nach dem ersten Schreiben abschalten.
        file_put_contents(sys_get_temp_dir() . '/hv-delete-hook-recorder.jsonl', json_encode([
            'hook' => $hook,
            'id' => $horseId,
            'name' => $horse['name'] ?? null,
            'deleted_at' => $horse['deleted_at'] ?? null,
            'permanent' => $permanent,
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
