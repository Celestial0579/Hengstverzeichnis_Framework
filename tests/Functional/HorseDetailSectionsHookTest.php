<?php
// tests/Functional/HorseDetailSectionsHookTest.php

namespace Tests\Functional;

use Tests\Support\HttpClient;

/**
 * HTTP-Funktionstests für den Datenvertrag des Filter-Hooks
 * `horse.detail_sections` (Issue #151, siehe PublicController::horseDetail()).
 *
 * Der Hook selbst war getestet (tests/Unit/Plugin/HookManagerTest.php), sein
 * INHALT nicht: Ein Plugin bekommt `$horse` erst, nachdem die öffentlichen
 * Sichtbarkeitsfilter (#121/#122) gelaufen sind. Als diese Filter eingeführt
 * wurden, verschwand der Abschnitt eines Addons, das sich auf
 * `$horse['station_email']` verließ - im Framework fiel das nicht auf, weil kein
 * einziger Test den eigenen Erweiterungspunkt end-to-end abdeckte. Genau das
 * holt diese Klasse nach; die Zusicherungen stehen in docs/plugin-development.md
 * ("Was in $horse und $horsePersons steht").
 *
 * Nutzt das Referenz-Plugin aus docs/examples/demo-plugin, das für die Testdauer
 * in das gitignorete plugins/-Verzeichnis kopiert und über den echten
 * HTTP-Endpunkt aktiviert wird (Muster wie FeatureVisibilityTest). Sein
 * Detailabschnitt hängt den Marker `data-demo-station="1"` nur an, wenn
 * `$horse['station_email']` gesetzt ist.
 */
class HorseDetailSectionsHookTest extends FunctionalTestCase {

    private const PLUGIN_SRC = __DIR__ . '/../../docs/examples/demo-plugin';
    private const PLUGIN_DEST = __DIR__ . '/../../plugins/demo-plugin';
    private const STATION_MARKER = 'data-demo-station="1"';

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

    /**
     * Der Hook sieht Deckstationsdaten genau dann, wenn sie auch öffentlich
     * gezeigt werden dürfen - und der denormalisierte Stationsname darf nicht
     * am Filter vorbei auf die Seite gelangen.
     */
    public function testDetailSectionsHookSeesOnlyPubliclyVisibleStationData(): void {
        $admin = $this->authenticatedClient();
        self::installPluginFixture();

        $toggleResponse = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => 'demo-plugin',
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggleResponse->location());

        try {
            $unique = uniqid();

            $publishedStationName = "VeroeffentlichteStation-{$unique}";
            $publishedStationMail = "veroeffentlicht-{$unique}@example.com";
            $publishedStationId = $this->createStation($admin, $publishedStationName, $publishedStationMail, true);

            $hiddenStationName = "UnveroeffentlichteStation-{$unique}";
            $hiddenStationMail = "unveroeffentlicht-{$unique}@example.com";
            $hiddenStationId = $this->createStation($admin, $hiddenStationName, $hiddenStationMail, false);

            $freeTextStation = "Freitextgestuet-{$unique}";

            $horseWithPublicStation = $this->createHorse($admin, "MitOeffentlicherStation-{$unique}", [
                'persons' => [['role' => 'owner', 'breeding_station_id' => (string)$publishedStationId]],
            ]);
            $horseWithHiddenStation = $this->createHorse($admin, "MitVersteckterStation-{$unique}", [
                'persons' => [['role' => 'owner', 'breeding_station_id' => (string)$hiddenStationId]],
            ]);
            $horseWithFreeText = $this->createHorse($admin, "MitFreitext-{$unique}", [
                'persons' => [['role' => 'owner', 'breeding_station_text' => $freeTextStation]],
            ]);

            $visitor = $this->newClient();

            // 1. Veröffentlichte Station: Der Hook bekommt die Stationsdaten. Das ist
            //    die Zusicherung, auf die sich Addons stützen (siehe #151).
            $public = $visitor->get("/horse?id={$horseWithPublicStation}");
            $this->assertSame(200, $public->statusCode);
            $this->assertStringContainsString(
                self::STATION_MARKER,
                $public->body,
                'Bei veröffentlichter Station muss $horse["station_email"] im Hook gesetzt sein.'
            );
            $this->assertStringContainsString($publishedStationName, $public->body);
            $this->assertStringContainsString($publishedStationMail, $public->body);

            // 2. Unveröffentlichte Station: Der Hook feuert weiterhin, sieht aber keine
            //    Stationsdaten - und der Name darf auch nicht über die denormalisierte
            //    Spalte horses.breeding_station als Fallback auf die Seite gelangen.
            $hidden = $visitor->get("/horse?id={$horseWithHiddenStation}");
            $this->assertSame(200, $hidden->statusCode);
            $this->assertStringContainsString(
                'Demo-Plugin',
                $hidden->body,
                'Der Hook muss weiterhin laufen - nur ohne Stationsdaten.'
            );
            $this->assertStringNotContainsString(
                self::STATION_MARKER,
                $hidden->body,
                'Bei unveröffentlichter Station müssen die station_*-Felder im Hook null sein.'
            );
            $this->assertStringNotContainsString(
                $hiddenStationName,
                $hidden->body,
                'Der Name einer unveröffentlichten Station darf öffentlich nirgends erscheinen - '
                . 'auch nicht über den Fallback auf horses.breeding_station.'
            );
            $this->assertStringNotContainsString($hiddenStationMail, $hidden->body);

            // 3. Freier Text (keine breeding_station_id): bleibt sichtbar - der Schutz
            //    aus Fall 2 darf nicht zu breit greifen.
            $freeText = $visitor->get("/horse?id={$horseWithFreeText}");
            $this->assertSame(200, $freeText->statusCode);
            $this->assertStringContainsString(
                $freeTextStation,
                $freeText->body,
                'Freitext-Deckstationen haben keinen Stations-Datensatz und bleiben öffentlich.'
            );
        } finally {
            $admin->post('/admin/plugins/toggle', [
                'csrf_token' => $this->currentCsrfToken($admin),
                'slug' => 'demo-plugin',
                'enable' => '0',
            ]);
        }
    }

    /**
     * Legt eine Deckstation über den echten Admin-Endpunkt an. Ohne
     * `is_published` bleibt sie unveröffentlicht (Spalten-Default 0).
     */
    private function createStation(HttpClient $admin, string $name, string $email, bool $published): int {
        $form = $admin->get('/admin/breeding-stations/create');
        $payload = [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $name,
            'email' => $email,
        ];
        if ($published) {
            $payload['is_published'] = '1';
        }

        $response = $admin->post('/admin/breeding-stations/store', $payload);
        $this->assertSame(
            '/admin/breeding-stations?success=created',
            $response->location(),
            "Anlegen der Deckstation '{$name}' fehlgeschlagen, Body: {$response->body}"
        );

        return $this->findIdInAdminList($admin, '/admin/breeding-stations', $name);
    }

    /**
     * Legt ein veröffentlichtes Pferd an; `persons` wird als verschachteltes
     * Formularfeld übergeben (siehe HorseController::saveHorsePersons()).
     */
    private function createHorse(HttpClient $admin, string $name, array $extra = []): int {
        $form = $admin->get('/admin/horses/create');
        $response = $admin->post('/admin/horses/store', array_merge([
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $name,
            'status' => 'active',
            // Öffentliche Sichtbarkeit hängt am Veröffentlicht-Flag, nicht am Status -
            // ohne dieses Flag liefert /horse?id= eine 404.
            'is_published' => '1',
        ], $extra));
        $this->assertSame(
            '/admin/horses?success=created',
            $response->location(),
            "Anlegen von '{$name}' fehlgeschlagen, Body: {$response->body}"
        );

        return $this->findIdInAdminList($admin, '/admin/horses', $name);
    }

    /**
     * Liest die ID aus einer Admin-Liste: die Zeile mit `<strong>NAME</strong>`
     * suchen und daraus die erste Zelle nehmen, die nur aus Ziffern besteht
     * (die Checkbox-Zelle davor enthält ein <input> und trifft deshalb nicht).
     * Beide Listen paginieren nicht, ein Durchlauf genügt.
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
