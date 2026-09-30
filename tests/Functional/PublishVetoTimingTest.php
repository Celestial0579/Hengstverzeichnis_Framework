<?php
// tests/Functional/PublishVetoTimingTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Audit N49: Das Formular schrieb is_published = 1 schon im Haupt-INSERT/
 * -UPDATE und nahm das Häkchen erst am Ende bei einem Addon-Einwand zurück.
 * Brach das Speichern dazwischen ab, blieb das Pferd ungeprüft öffentlich.
 * Jetzt wird erst nach dem Speichern über HorsePublishVeto veröffentlicht -
 * auch im CSV-Import.
 *
 * Ein zur Laufzeit erzeugtes Fixture-Plugin (Muster HorseDeleteHooksTest)
 * erhebt einen Einwand, wenn der Name 'SPERRE' enthält, und protokolliert
 * bei horse.after_save und horse.publish_blockers den DB-Wert von
 * is_published.
 */
class PublishVetoTimingTest extends FunctionalTestCase {

    private const SLUG = 'publish-veto-timing';
    private const PLUGIN_DEST = __DIR__ . '/../../plugins/' . self::SLUG;

    private static function recordFile(): string {
        return sys_get_temp_dir() . '/hv-publish-veto-timing.jsonl';
    }

    private function db(): \PDO {
        return Database::getInstance();
    }

    protected function tearDown(): void {
        self::removePluginDir();
        @unlink(self::recordFile());
        parent::tearDown();
    }

    private function mitPlugin(\Tests\Support\HttpClient $admin, callable $test): void {
        self::installPluginFixture();
        $toggle = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin), 'slug' => self::SLUG, 'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggle->location());
        try {
            $test();
        } finally {
            $admin->post('/admin/plugins/toggle', [
                'csrf_token' => $this->currentCsrfToken($admin), 'slug' => self::SLUG, 'enable' => '0',
            ]);
        }
    }

    /** @return array<int, array{hook: string, id: int, is_published: int}> */
    private function protokoll(int $id): array {
        if (!is_file(self::recordFile())) {
            return [];
        }
        $eintraege = [];
        foreach (file(self::recordFile(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $zeile) {
            $e = json_decode($zeile, true);
            if (is_array($e) && (int)$e['id'] === $id) {
                $eintraege[] = $e;
            }
        }
        return $eintraege;
    }

    private function veroeffentlicht(int $id): int {
        return (int)$this->db()->query("SELECT is_published FROM horses WHERE id = {$id}")->fetchColumn();
    }

    public function testFirstPublicationHappensOnlyAfterTheVeto(): void {
        $admin = $this->authenticatedClient();
        $db = $this->db();
        $u = uniqid();

        $this->mitPlugin($admin, function () use ($admin, $db, $u): void {
            $form = $admin->get('/admin/horses/create');
            $csrf = $form->formField('csrf_token') ?? '';

            // (1) Unveröffentlichtes Pferd, update mit is_published=1.
            foreach (["Frei {$u}" => 1, "SPERRE {$u}" => 0] as $name => $erwartet) {
                $db->prepare("INSERT INTO horses (name, is_published) VALUES (?, 0)")->execute([$name]);
                $id = (int)$db->lastInsertId();
                $response = $admin->post('/admin/horses/update', [
                    'csrf_token' => $csrf, 'id' => (string)$id, 'name' => $name, 'status' => 'active', 'is_published' => '1',
                ]);
                $this->assertSame('/admin/horses?success=' . ($erwartet ? 'updated' : 'updated_not_published'), $response->location());
                $this->assertSame($erwartet, $this->veroeffentlicht($id));
                $protokoll = $this->protokoll($id);
                $this->assertSame(['after_save', 'publish_blockers'], array_column($protokoll, 'hook'));
                $this->assertSame([0, 0], array_column($protokoll, 'is_published'), "{$name}: an keinem Protokollpunkt stand schon 1");
                $db->exec("DELETE FROM horses WHERE id = {$id}");
            }

            // (3) store mit is_published=1 und SPERRE.
            $response = $admin->post('/admin/horses/store', [
                'csrf_token' => $csrf, 'name' => "Neu SPERRE {$u}", 'status' => 'active', 'is_published' => '1',
            ]);
            $this->assertSame('/admin/horses?success=created_not_published', $response->location());
            $stmt = $db->prepare("SELECT id, is_published FROM horses WHERE name = ?");
            $stmt->execute(["Neu SPERRE {$u}"]);
            $row = $stmt->fetch();
            $this->assertSame(0, (int)$row['is_published']);
            $this->assertSame([0, 0], array_column($this->protokoll((int)$row['id']), 'is_published'));
            $db->exec("DELETE FROM horses WHERE id = " . (int)$row['id']);

            // (4) CSV-Import mit "veröffentlichen": nur das Pferd ohne Einwand.
            $importForm = $admin->get('/admin/import/horses');
            $preview = $admin->postFile(
                '/admin/import/horses/preview',
                ['csrf_token' => $importForm->formField('csrf_token') ?? ''],
                'csv_file',
                'import.csv',
                "name\nCSV Frei {$u}\nCSV SPERRE {$u}\n"
            );
            $this->assertSame(200, $preview->statusCode);
            $commit = $admin->post('/admin/import/horses/commit', [
                'csrf_token' => $preview->formField('csrf_token') ?? '', 'is_published' => '1',
            ]);
            $this->assertSame(200, $commit->statusCode);
            $this->assertStringContainsString('2 Pferd(e) erfolgreich importiert', strip_tags($commit->body));
            $this->assertStringContainsString('wegen eines Addon-Einwands nicht veröffentlicht', $commit->body);
            $this->assertStringContainsString("CSV SPERRE {$u}", $commit->body);
            $this->assertStringContainsString('Sperrwort im Namen', $commit->body);
            $stmt = $db->prepare("SELECT id, is_published FROM horses WHERE name = ?");
            $stmt->execute(["CSV Frei {$u}"]);
            $frei = $stmt->fetch();
            $stmt->execute(["CSV SPERRE {$u}"]);
            $gesperrt = $stmt->fetch();
            $this->assertSame(1, (int)$frei['is_published']);
            $this->assertSame(0, (int)$gesperrt['is_published']);
            $audit = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'Bulk-Import Pferde (CSV)' AND details LIKE ?");
            $audit->execute(['%1 durch Addon-Einwand nicht veröffentlicht (IDs: ' . (int)$gesperrt['id'] . ')']);
            $this->assertSame(1, (int)$audit->fetchColumn());
            $db->exec("DELETE FROM horses WHERE id IN (" . (int)$frei['id'] . ', ' . (int)$gesperrt['id'] . ')');
        });
    }

    /**
     * (2) Regression für den Abbruchpfad: Ein Jahr "70000" in einer
     * Zuordnungszeile lief per (int) in die SMALLINT-Spalte und brach das
     * Speichern NACH dem Haupt-UPDATE ab - mit is_published = 1 und ohne
     * Veto. Jetzt wird es wie das Todesjahr verworfen.
     */
    public function testOutOfRangeYearNoLongerAbortsTheSave(): void {
        $admin = $this->authenticatedClient();
        $db = $this->db();
        $u = uniqid();

        $db->prepare("INSERT INTO horses (name, death_year, is_deceased, is_published) VALUES (?, 2015, 1, 0)")->execute(["Abbruch {$u}"]);
        $id = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO horse_persons (horse_id, role, origin_country, from_year) VALUES (?, 'owner', 'NO', 2018)")->execute([$id]);

        $form = $admin->get("/admin/horses/edit?id={$id}");
        $response = $admin->post('/admin/horses/update', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'id' => (string)$id, 'name' => "Abbruch {$u}", 'status' => 'active', 'death_year' => '2015',
            'is_published' => '1',
            'persons_present' => '1',
            'persons' => [['contact_id' => '', 'role' => 'owner', 'origin_country' => 'NO', 'from_year' => '70000', 'until_year' => '']],
        ]);
        $this->assertSame(302, $response->statusCode);
        $this->assertSame('/admin/horses?success=updated', $response->location());
        $zeilen = $db->query("SELECT from_year FROM horse_persons WHERE horse_id = {$id}")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame([null], $zeilen, 'Die alte Zeile ist ersetzt, das Jahr verworfen');
        $this->assertSame(1, $this->veroeffentlicht($id));
        $db->exec("DELETE FROM horses WHERE id = {$id}");
    }

    private static function installPluginFixture(): void {
        self::removePluginDir();
        @unlink(self::recordFile());
        mkdir(self::PLUGIN_DEST, 0777, true);

        file_put_contents(self::PLUGIN_DEST . '/plugin.json', json_encode([
            'slug' => self::SLUG,
            'name' => 'Publish-Veto-Timing (Test-Fixture)',
            'version' => '1.0.0',
            'core_compatibility' => '>=0.1.0-beta.1',
            'core_supported_max' => '9.9',
            'description' => 'Erhebt Einwände gegen Pferde mit SPERRE im Namen und protokolliert is_published (Audit N49).',
            'author' => 'tests/Functional/PublishVetoTimingTest',
            'hooks' => ['horse.after_save', 'horse.publish_blockers'],
            'entry' => 'Plugin.php',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        file_put_contents(self::PLUGIN_DEST . '/Plugin.php', <<<'PHP'
<?php
// Test-Fixture für PublishVetoTimingTest (Audit N49).

namespace Plugin\PublishVetoTiming;

use App\Plugin\HookManager;

class Plugin {

    public function register(HookManager $hooks): void {
        $hooks->addAction('horse.after_save', [$this, 'onAfterSave']);
        $hooks->addFilter('horse.publish_blockers', [$this, 'blockers']);
    }

    public function onAfterSave(int $horseId, array $postData, bool $isNew): void {
        $this->record('after_save', $horseId);
    }

    public function blockers(array $gruende, int $horseId, array $horse): array {
        $this->record('publish_blockers', $horseId);
        if (str_contains((string)($horse['name'] ?? ''), 'SPERRE')) {
            $gruende[] = 'Sperrwort im Namen';
        }
        return $gruende;
    }

    private function record(string $hook, int $horseId): void {
        $stmt = \App\Database::getInstance()->prepare("SELECT is_published FROM horses WHERE id = ?");
        $stmt->execute([$horseId]);
        file_put_contents(sys_get_temp_dir() . '/hv-publish-veto-timing.jsonl', json_encode([
            'hook' => $hook,
            'id' => $horseId,
            'is_published' => (int)$stmt->fetchColumn(),
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
