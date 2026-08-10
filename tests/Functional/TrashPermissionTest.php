<?php
// tests/Functional/TrashPermissionTest.php

namespace Tests\Functional;

use App\Database;

/**
 * HTTP-Funktionstests für die Papierkorb-Zugriffsschranken (#127,
 * TrashController::authorizeForType()):
 *  - Benutzerkonten (type=user) sind ausschließlich Administratoren vorbehalten -
 *    ein Nicht-Admin mit horses.delete darf ein gelöschtes Benutzerkonto NICHT
 *    wiederherstellen (sonst wäre das ein Kontoübernahme-Pfad, da checkAuth()
 *    über users.deleted_at sperrt).
 *  - Ohne <modul>.delete gibt es für den jeweiligen Typ ein 403.
 *  - Nicht-Admins dürfen erst nach Ablauf der 30-Tage-Aufbewahrung endgültig löschen.
 *  - Ein manipulierter type-Wert ist ein No-Op (Redirect zurück zum Papierkorb).
 *
 * Zusätzlich (#222): Das Papierkorb-Leeren löscht Pferde chargenweise über
 * EIN DELETE ... WHERE id IN (...) - der Test unten stellt sicher, dass dabei
 * trotzdem je Pferd horse.before_delete und horse.deleted feuern und am Ende
 * wirklich alle selektierten Pferde weg sind.
 */
class TrashPermissionTest extends FunctionalTestCase {

    /**
     * Zur Testlaufzeit generiertes Recorder-Plugin für den Batch-Test (#222),
     * Muster wie HorseDeleteHooksTest - eigener Slug und eigene Logdatei, damit
     * sich beide Testklassen in einem Suite-Lauf nicht gegenseitig die Fixtures
     * unter den Füßen wegräumen.
     */
    private const PLUGIN_DEST = __DIR__ . '/../../plugins/trash-batch-recorder';

    protected function tearDown(): void {
        self::removePluginDir();
        @unlink(self::recordFile());
        parent::tearDown();
    }

    /**
     * Die Aufzeichnung liegt bewusst AUSSERHALB des Plugin-Verzeichnisses: Die
     * Code-Integritätsprüfung des PluginManagers würde das Plugin sonst nach
     * dem ersten Schreiben in das eigene Verzeichnis abschalten (siehe
     * HorseDeleteHooksTest::recordFile()).
     */
    private static function recordFile(): string {
        return sys_get_temp_dir() . '/hv-trash-batch-recorder.jsonl';
    }

    private function db(): \PDO {
        return Database::getInstance();
    }

    /**
     * CSRF-Token einer Nicht-Admin-Sitzung: das Token ist pro Session fest
     * (Router::generateCsrfToken()), daher genügt irgendeine für den Benutzer
     * erreichbare Seite mit einem unbedingt gerenderten Formular - die
     * öffentliche DSGVO-Seite rendert immer eines.
     */
    private function csrfTokenFor(\Tests\Support\HttpClient $client): string {
        return $client->get('/dsgvo')->formField('csrf_token') ?? '';
    }

    private function createCustomGroup(\Tests\Support\HttpClient $admin, string $name): int {
        $groupsPage = $admin->get('/admin/groups');
        $response = $admin->post('/admin/groups/create', [
            'csrf_token' => $groupsPage->formField('csrf_token') ?? '',
            'name' => $name,
        ]);
        preg_match('/group=(\d+)/', (string)$response->location(), $matches);
        $this->assertNotEmpty($matches, "Konnte neue Gruppen-ID nicht ermitteln, Body: {$response->body}");
        return (int)$matches[1];
    }

    public function testNonAdminCannotRestoreDeletedUserAccount(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $db = $this->db();

        // Editor mit horses.view + horses.delete über eine eigene Gruppe.
        $groupId = $this->createCustomGroup($admin, "Trash-Tester {$unique}");
        $this->setGroupPermissions($admin, $groupId, ['horses' => ['view', 'delete']]);
        $editor = $this->createAndLoginEditor($admin, "trashtester{$unique}", "trash-{$unique}@example.com", [$groupId]);

        // Admin legt ein Opfer-Konto an und verschiebt es in den Papierkorb.
        $createForm = $admin->get('/admin/users/create');
        $admin->post('/admin/users/store', [
            'csrf_token' => $createForm->formField('csrf_token') ?? '',
            'username' => "trashvictim{$unique}",
            'email' => "trash-victim-{$unique}@example.com",
            'password' => 'VictimTest123!',
        ]);
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute(["trashvictim{$unique}"]);
        $victimId = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $victimId);

        $admin->post('/admin/users/delete', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'id' => (string)$victimId,
        ]);
        $stmt = $db->prepare("SELECT deleted_at FROM users WHERE id = ?");
        $stmt->execute([$victimId]);
        $this->assertNotNull($stmt->fetchColumn(), 'Opfer-Konto sollte im Papierkorb liegen');

        // Der Editor (horses.delete, aber kein Admin) versucht die Wiederherstellung.
        $restoreResponse = $editor->post('/admin/trash/restore', [
            'csrf_token' => $this->csrfTokenFor($editor),
            'type' => 'user',
            'id' => (string)$victimId,
        ]);
        $this->assertSame(403, $restoreResponse->statusCode, 'Benutzerkonten im Papierkorb sind Administratoren vorbehalten');

        // deleted_at ist unverändert gesetzt - das Konto bleibt gesperrt.
        $stmt = $db->prepare("SELECT deleted_at FROM users WHERE id = ?");
        $stmt->execute([$victimId]);
        $this->assertNotNull($stmt->fetchColumn(), 'users.deleted_at darf durch den abgewiesenen Request nicht geleert werden');

        // Der Admin selbst darf wiederherstellen.
        $adminRestore = $admin->post('/admin/trash/restore', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'type' => 'user',
            'id' => (string)$victimId,
        ]);
        $this->assertSame('/admin/trash?success=restored', $adminRestore->location());
        $stmt = $db->prepare("SELECT deleted_at FROM users WHERE id = ?");
        $stmt->execute([$victimId]);
        $this->assertNull($stmt->fetchColumn());
    }

    public function testNonAdminPermanentDeleteRespectsRetentionPeriod(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $db = $this->db();

        $groupId = $this->createCustomGroup($admin, "Trash-Retention {$unique}");
        $this->setGroupPermissions($admin, $groupId, ['horses' => ['view', 'delete']]);
        $editor = $this->createAndLoginEditor($admin, "retention{$unique}", "retention-{$unique}@example.com", [$groupId]);

        // Frisch (vor 2 Tagen) gelöschtes Pferd direkt in der DB anlegen.
        $stmt = $db->prepare("INSERT INTO horses (name, deleted_at) VALUES (?, DATE_SUB(NOW(), INTERVAL 2 DAY))");
        $stmt->execute(["Retention-Pferd {$unique}"]);
        $horseId = (int)$db->lastInsertId();

        // Nicht-Admin: endgültiges Löschen vor Ablauf der 30 Tage wird verweigert.
        $response = $editor->post('/admin/trash/permanent-delete', [
            'csrf_token' => $this->csrfTokenFor($editor),
            'type' => 'horse',
            'id' => (string)$horseId,
        ]);
        $this->assertSame('/admin/trash?error=retention_period_30_days', $response->location());

        $stmt = $db->prepare("SELECT COUNT(*) FROM horses WHERE id = ?");
        $stmt->execute([$horseId]);
        $this->assertSame(1, (int)$stmt->fetchColumn(), 'Das Pferd darf vor Ablauf der Aufbewahrungsfrist nicht endgültig gelöscht sein');

        // Nach Ablauf der Frist (31 Tage) darf derselbe Editor endgültig löschen.
        $db->prepare("UPDATE horses SET deleted_at = DATE_SUB(NOW(), INTERVAL 31 DAY) WHERE id = ?")->execute([$horseId]);
        $response = $editor->post('/admin/trash/permanent-delete', [
            'csrf_token' => $this->csrfTokenFor($editor),
            'type' => 'horse',
            'id' => (string)$horseId,
        ]);
        $this->assertSame('/admin/trash?success=purged', $response->location());
        $stmt = $db->prepare("SELECT COUNT(*) FROM horses WHERE id = ?");
        $stmt->execute([$horseId]);
        $this->assertSame(0, (int)$stmt->fetchColumn());
    }

    public function testWithoutDeletePermissionTrashActionsAreForbidden(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $db = $this->db();

        // Editor mit reiner Leseberechtigung (kein horses.delete).
        $groupId = $this->createCustomGroup($admin, "Trash-ReadOnly {$unique}");
        $this->setGroupPermissions($admin, $groupId, ['horses' => ['view']]);
        $editor = $this->createAndLoginEditor($admin, "readonly{$unique}", "readonly-{$unique}@example.com", [$groupId]);

        $stmt = $db->prepare("INSERT INTO horses (name, deleted_at) VALUES (?, NOW())");
        $stmt->execute(["ReadOnly-Pferd {$unique}"]);
        $horseId = (int)$db->lastInsertId();

        $restoreResponse = $editor->post('/admin/trash/restore', [
            'csrf_token' => $this->csrfTokenFor($editor),
            'type' => 'horse',
            'id' => (string)$horseId,
        ]);
        $this->assertSame(403, $restoreResponse->statusCode, 'Ohne horses.delete keine Papierkorb-Aktion für Pferde');

        $stmt = $db->prepare("SELECT deleted_at FROM horses WHERE id = ?");
        $stmt->execute([$horseId]);
        $this->assertNotNull($stmt->fetchColumn(), 'Das Pferd muss im Papierkorb bleiben');

        // Manipulierter type-Wert: No-Op-Redirect zurück zum Papierkorb statt
        // irgendeiner ungeprüften Aktion.
        $unknownTypeResponse = $editor->post('/admin/trash/restore', [
            'csrf_token' => $this->csrfTokenFor($editor),
            'type' => 'settings',
            'id' => '1',
        ]);
        $this->assertSame('/admin/trash', $unknownTypeResponse->location());

        // Aufräumen, damit der Test-Datensatz nachfolgende Klassen nicht stört.
        $db->prepare("DELETE FROM horses WHERE id = ?")->execute([$horseId]);
    }

    /**
     * Batch-Kontrakt des Papierkorb-Leerens (#222): Mehrere Pferde liegen im
     * Papierkorb, ein Admin leert ihn - gelöscht wird chargenweise per
     * DELETE ... WHERE id IN (...), aber die Lösch-Hooks (#164) müssen
     * weiterhin JE Pferd feuern (before_delete mit permanent=true vor dem
     * Löschen, deleted danach, jeweils mit dem vollen Datensatz), und am Ende
     * ist keines der Pferde mehr in der Datenbank.
     */
    public function testEmptyTrashDeletesAllHorsesInBatchAndFiresHooksPerHorse(): void {
        $admin = $this->authenticatedClient();
        self::installPluginFixture();

        $toggleResponse = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => 'trash-batch-recorder',
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggleResponse->location());

        try {
            $unique = uniqid();
            $db = $this->db();

            // Mehrere Pferde direkt als Papierkorb-Einträge anlegen - der Weg
            // über die Oberfläche ist hier nicht Testgegenstand, das Leeren ist es.
            $horseIds = [];
            $insert = $db->prepare("INSERT INTO horses (name, deleted_at) VALUES (?, NOW())");
            for ($i = 1; $i <= 3; $i++) {
                $insert->execute(["Batch-Pferd {$i} {$unique}"]);
                $horseIds[(int)$db->lastInsertId()] = "Batch-Pferd {$i} {$unique}";
            }

            $response = $admin->post('/admin/trash/empty', [
                'csrf_token' => $this->currentCsrfToken($admin),
            ]);
            $this->assertSame('/admin/trash?success=emptied', $response->location());

            // Alle Pferde sind endgültig weg - das Batch-DELETE hat die gesamte
            // selektierte ID-Menge erwischt, nicht nur die erste.
            $placeholders = implode(',', array_fill(0, count($horseIds), '?'));
            $stmt = $db->prepare("SELECT COUNT(*) FROM horses WHERE id IN ({$placeholders})");
            $stmt->execute(array_keys($horseIds));
            $this->assertSame(0, (int)$stmt->fetchColumn(), 'Nach dem Leeren darf keines der Pferde mehr existieren');

            // Je Pferd exakt before_delete (permanent=true) gefolgt von deleted,
            // jeweils mit dem vollen Datensatz in der Payload. Gefiltert auf die
            // eigenen IDs, da die geteilte Testdatenbank beim Leeren auch
            // Alt-Pferde anderer Testklassen treffen kann.
            foreach ($horseIds as $horseId => $horseName) {
                $events = $this->recordedEventsFor($horseId);
                $this->assertSame(
                    [['before_delete', true], ['deleted', null]],
                    array_map(fn($e) => [$e['hook'], $e['permanent']], $events),
                    "Pferd {$horseId}: Hooks müssen trotz Batch-DELETE je Pferd feuern"
                );
                foreach ($events as $event) {
                    $this->assertSame($horseName, $event['name'], "Hook {$event['hook']} muss den vollen Datensatz erhalten");
                }
            }
        } finally {
            $admin->post('/admin/plugins/toggle', [
                'csrf_token' => $this->currentCsrfToken($admin),
                'slug' => 'trash-batch-recorder',
                'enable' => '0',
            ]);
        }
    }

    /**
     * Liest die vom Recorder-Plugin geschriebenen JSON-Zeilen, gefiltert auf
     * eine Pferde-ID (Muster wie HorseDeleteHooksTest::recordedEventsFor()).
     *
     * @return array<int, array{hook:string, id:int, name:?string, permanent:?bool}>
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
            'slug' => 'trash-batch-recorder',
            'name' => 'Trash-Batch-Recorder (Test-Fixture)',
            'version' => '1.0.0',
            'core_compatibility' => '>=0.1.0-beta.1',
            'core_supported_max' => '9.9',
            'description' => 'Zeichnet die Lösch-Hooks beim Papierkorb-Leeren (#222) für den Batch-Kontrakt-Test auf.',
            'author' => 'tests/Functional/TrashPermissionTest',
            'hooks' => ['horse.before_delete', 'horse.deleted'],
            'entry' => 'Plugin.php',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        file_put_contents(self::PLUGIN_DEST . '/Plugin.php', <<<'PHP'
<?php
// Test-Fixture: zeichnet horse.before_delete/horse.deleted beim Papierkorb-Leeren
// (#222) als JSON-Zeilen auf.

namespace Plugin\TrashBatchRecorder;

use App\Plugin\HookManager;

class Plugin {

    public function register(HookManager $hooks): void {
        $hooks->addAction('horse.before_delete', [$this, 'onBeforeDelete']);
        $hooks->addAction('horse.deleted', [$this, 'onDeleted']);
    }

    public function onBeforeDelete(int $horseId, array $horse, bool $permanent): void {
        $this->record('before_delete', $horseId, $horse, $permanent);
    }

    public function onDeleted(int $horseId, array $horse): void {
        $this->record('deleted', $horseId, $horse);
    }

    private function record(string $hook, int $horseId, array $horse, ?bool $permanent = null): void {
        // Ausserhalb des Plugin-Verzeichnisses, sonst schaltet die
        // Integritätsprüfung des PluginManagers den Recorder selbst ab.
        file_put_contents(sys_get_temp_dir() . '/hv-trash-batch-recorder.jsonl', json_encode([
            'hook' => $hook,
            'id' => $horseId,
            'name' => $horse['name'] ?? null,
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
