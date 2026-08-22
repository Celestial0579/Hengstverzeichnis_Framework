<?php
// tests/Functional/SprachAddonTest.php

namespace Tests\Functional;

/**
 * Die Verdrahtung des Sprach-Erweiterungspunkts (#344) über den echten Weg.
 *
 * WARUM ES DIESEN TEST BRAUCHT — UND WARUM ER HIER STEHT UND NICHT NUR IM
 * ADDONS-REPO. `Translator::registerCoreLocale()` lässt sich bequem im
 * Unit-Test aufrufen; die Frage, ob der `PluginManager` beim Booten
 * überhaupt jemals `lang/core/` bemerkt, beantwortet das nicht. Genau diese
 * Lücke — eine Mechanik, die im Code steht und nirgends aufgerufen wird —
 * hat in derselben Runde schon einmal zugeschlagen
 * (`UpdateService::ABGELOESTE_ADDONS`, #339). Eine Gegenprobe deckte hier
 * dasselbe auf: Das Entfernen der Verdrahtung liess jeden Test grün.
 *
 * Das Addon dieses Tests wird zur Laufzeit angelegt und danach wieder
 * entfernt. Ein echtes Sprach-Addon liegt im Addons-Repo; hier geht es allein
 * um den Kern-Anteil.
 */
class SprachAddonTest extends FunctionalTestCase {

    private const SLUG = 'sprache-testlokal';

    private string $verzeichnis = '';

    protected function setUp(): void {
        parent::setUp();

        $this->verzeichnis = dirname(__DIR__, 2) . '/plugins/' . self::SLUG;
        @mkdir($this->verzeichnis . '/lang/core', 0755, true);

        file_put_contents($this->verzeichnis . '/plugin.json', json_encode([
            'slug' => self::SLUG,
            'name' => 'Sprache: Testlokal',
            'version' => '1.0.0',
            'core_compatibility' => '>=0.8.0-beta.1',
            'core_supported_max' => '0.9',
            'description' => 'Nur für tests/Functional/SprachAddonTest.php.',
            'entry' => 'Plugin.php',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        file_put_contents(
            $this->verzeichnis . '/Plugin.php',
            "<?php\nnamespace Plugin\\SpracheTestlokal;\nclass Plugin {}\n"
        );

        // Eine echte Sprache mit dem VOLLSTÄNDIGEN Schlüsselsatz wäre für den
        // Zweck überflüssig - geprüft wird die Verdrahtung, nicht die
        // Übersetzung. Der Rückfall auf Deutsch deckt den Rest ab, und genau
        // das gehört mitgeprüft.
        file_put_contents(
            $this->verzeichnis . '/lang/core/nl.php',
            "<?php\nreturn ['nav.home' => 'Startpagina van de test'];\n"
        );
    }

    protected function tearDown(): void {
        foreach (['lang/core/nl.php', 'Plugin.php', 'plugin.json'] as $datei) {
            @unlink($this->verzeichnis . '/' . $datei);
        }
        foreach (['lang/core', 'lang', ''] as $unter) {
            @rmdir(rtrim($this->verzeichnis . '/' . $unter, '/'));
        }

        $db = \App\Database::getInstance();
        $db->prepare('DELETE FROM plugins WHERE slug = ?')->execute([self::SLUG]);

        parent::tearDown();
    }

    public function testEinSprachAddonMachtSeineSpracheWaehlbarUndUebersetzt(): void {
        // ZUERST der Admin-Client: Er stoesst die Ersteinrichtung an. Ohne
        // ihn antwortet die Startseite mit einer Weiterleitung auf /setup,
        // und jede Zusicherung auf ihren Inhalt waere gegenstandslos - die
        // "vorher"-Pruefung unten sah dann eine LEERE Seite und galt als
        // erfuellt, ohne je einen Sprachumschalter geprueft zu haben.
        $admin = $this->authenticatedClient();
        $gast = $this->newClient();

        // Vorher: Der Kern kennt den Namen, bietet die Sprache aber nicht an.
        $vorher = $gast->get('/');
        $this->assertSame(200, $vorher->statusCode);
        $this->assertStringContainsString('<select id="footer-lang-select"', $vorher->body, 'Voraussetzung: Der Umschalter ist da.');
        $this->assertStringNotContainsString('>Nederlands</option>', $vorher->body);

        // Und ?lang=nl bleibt folgenlos: Der Versuch wird nicht uebernommen,
        // die naechste Seite ist weiterhin deutsch.
        $gast->get('/?lang=nl');
        $this->assertStringContainsString('lang="de"', $gast->get('/')->body);

        $antwort = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $antwort->location(), "Body: {$antwort->body}");

        // Nachher: Die Sprache steht im Umschalter, und ihr Text kommt an.
        $nachher = $gast->get('/');
        $this->assertStringContainsString('>Nederlands</option>', $nachher->body);

        $niederlaendisch = $gast->get('/?lang=nl');
        $this->assertStringContainsString('lang="nl"', $niederlaendisch->body);
        $this->assertStringContainsString('Startpagina van de test', $niederlaendisch->body);

        // Und der Rest der Seite bleibt lesbar: Was das Addon nicht
        // uebersetzt, faellt auf Deutsch zurueck - nie auf einen leeren Platz.
        $this->assertStringContainsString('Impressum', $niederlaendisch->body);

        $gast->get('/?lang=de');
    }

    /**
     * Eine Bildanfrage darf die Sprachwahl nicht loeschen (#378).
     *
     * Die Bild-Kurzschluesse in public/index.php ueberspringen den
     * Plugin-Bootstrap absichtlich - sie holen einen Byte-Strom aus. Dort
     * kennt der Kern nur Deutsch und Englisch. Bis #378 hielt die
     * Auswahlregel jede Addon-Sprache deshalb fuer deaktiviert und LOESCHTE
     * die Wahl aus der Sitzung: Ein Besucher auf Niederlaendisch, dessen
     * Browser ein einziges Pferdefoto nachlaedt, sah die naechste Seite auf
     * Deutsch. Betroffen war seit #344 jeder ausser de/en, und der Grund war
     * nirgends sichtbar.
     *
     * Der Test geht ueber HTTP und damit ueber den echten Kurzschluss - ein
     * Unit-Test auf die Auswahlregel wuerde die Verdrahtung nicht beweisen.
     * Genau daran ist #344 schon einmal vorbeigelaufen.
     */
    public function testEineBildanfrageLoeschtDieSprachwahlNicht(): void {
        $admin = $this->authenticatedClient();
        $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);

        $gast = $this->newClient();
        $this->assertStringContainsString('lang="nl"', $gast->get('/?lang=nl')->body, 'Voraussetzung: die Wahl greift.');

        // Der Kurzschluss. Die Kennung muss es nicht geben - schon der
        // ABLEHNENDE Weg lief durch resolveRequestLocale(), und genau dort
        // sass der Fehler. Ein vorhandenes Bild waere derselbe Pfad mit mehr
        // Aufbau.
        $bild = $gast->get('/media/horse-image?id=999999');
        $this->assertNotSame(200, $bild->statusCode, 'Voraussetzung: die Kennung gibt es nicht.');

        // Und danach ist die Wahl noch da.
        $this->assertStringContainsString(
            'lang="nl"',
            $gast->get('/')->body,
            'Eine Bildanfrage hat die Sprachwahl geloescht - der Kurzschluss kennt die Sprach-Addons nicht (#378).'
        );

        $gast->get('/?lang=de');
    }

    /**
     * Wird das Addon wieder abgeschaltet, verschwindet die Sprache - und die
     * Oberfläche fällt sauber auf Deutsch zurück, statt halb übersetzt zu
     * bleiben.
     */
    public function testNachDemAbschaltenIstDieSpracheWiederWeg(): void {
        $admin = $this->authenticatedClient();
        $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);

        $gast = $this->newClient();
        $this->assertStringContainsString('lang="nl"', $gast->get('/?lang=nl')->body);

        $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '0',
        ]);

        $danach = $this->newClient();
        $this->assertStringContainsString('lang="de"', $danach->get('/?lang=nl')->body);
        $this->assertStringNotContainsString('>Nederlands</option>', $danach->get('/')->body);
    }
}
