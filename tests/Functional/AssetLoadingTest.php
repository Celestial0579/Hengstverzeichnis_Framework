<?php
// tests/Functional/AssetLoadingTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Ladeverhalten von Skripten, Stylesheets und Bildern (#263).
 *
 * Der Test sichert nicht "es ist schnell" ab - das misst kein PHPUnit -,
 * sondern die vier Entscheidungen, die beim nächsten Optimierungsdurchgang am
 * ehesten versehentlich umgedreht werden:
 *
 * 1. Der Darkmode-FOUC-Fix bleibt inline und synchron im <head>. Wer ihn
 *    auslagert oder mit defer versieht, bringt das Aufblitzen des falschen
 *    Farbschemas zurück (#91).
 * 2. Die drei übrigen Skripte sind ausgelagert und tragen defer. Als
 *    Inline-Block wäre defer wirkungslos - das Attribut gilt laut HTML-Standard
 *    nur für Skripte mit src. Genau diese Falle steckte in der Empfehlung des
 *    Issues.
 * 3. Katalogbilder laden lazy - das Hero-Foto der Detailseite ausdrücklich
 *    NICHT. Es ist dort das LCP-Element; lazy würde die wahrgenommene Ladezeit
 *    verschlechtern statt verbessern.
 * 4. Das Schriften-Stylesheet des Fremdhosts blockiert das Rendern nicht mehr.
 */
class AssetLoadingTest extends FunctionalTestCase {

    /** @var array<int, int> */
    private array $seededHorseIds = [];

    protected function tearDown(): void {
        $db = Database::getInstance();
        foreach ($this->seededHorseIds as $id) {
            $db->prepare("DELETE FROM horses WHERE id = ?")->execute([$id]);
        }
        $this->seededHorseIds = [];
        parent::tearDown();
    }

    public function testFoucPreventionScriptStaysInlineAndSynchronousInTheHead(): void {
        $body = $this->getOk('/');

        $head = substr($body, 0, (int)strpos($body, '</head>'));
        $this->assertStringContainsString(
            'data-theme',
            $head,
            'Der FOUC-Fix muss im <head> stehen - danach ist es für das erste Rendern zu spät.'
        );

        // Der entscheidende Teil: ein Inline-Block, KEIN Verweis auf eine Datei
        // und kein defer/async. Alles davon verschöbe die Ausführung hinter das
        // erste Rendern und brächte damit genau das Aufblitzen zurück.
        $this->assertMatchesRegularExpression(
            '/<script>\s*(?:\/\/[^\n]*\n\s*)*(?:var|let|const|\(function|document|window|try)/',
            $head,
            'Im <head> muss weiterhin ein reiner Inline-<script>-Block ohne Attribute stehen.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<script[^>]*\b(?:defer|async)\b[^>]*>(?=(?:(?!<\/script>).)*data-theme)/s',
            $head,
            'Der FOUC-Fix darf nicht mit defer/async versehen werden.'
        );
    }

    public function testDeferredScriptsAreExternalBecauseDeferIsIgnoredOnInlineBlocks(): void {
        $expected = [
            '/' => '/js/theme-toggle.js',
            '/katalog' => '/js/catalog-filter.js',
        ];

        foreach ($expected as $path => $script) {
            $body = $this->getOk($path);
            $this->assertMatchesRegularExpression(
                '/<script[^>]*\bdefer\b[^>]*\bsrc="' . preg_quote($script, '/') . '"/',
                $body,
                "Auf {$path} fehlt das ausgelagerte, mit defer geladene {$script}."
            );
        }
    }

    /**
     * Gegenprobe zur Auslagerung: Die Dateien müssen auch tatsächlich
     * ausgeliefert werden. Ein <script src> auf eine 404 wäre schlimmer als der
     * vorherige Inline-Block - die Seite bliebe still ohne Funktion.
     */
    public function testExternalScriptsAreActuallyDelivered(): void {
        foreach (['/js/theme-toggle.js', '/js/catalog-filter.js', '/js/pedigree-zoom.js'] as $script) {
            $response = $this->newClient()->get($script);
            $this->assertSame(200, $response->statusCode, "{$script} wird nicht ausgeliefert.");
            $this->assertNotSame('', trim($response->body), "{$script} ist leer.");
        }
    }

    public function testCatalogImagesLoadLazilyButTheHeroPhotoDoesNot(): void {
        $horseId = $this->seedPublishedHorseWithPhoto();

        $catalog = $this->getOk('/katalog');
        $this->assertMatchesRegularExpression(
            '/<img[^>]*\bloading="lazy"[^>]*>/',
            $catalog,
            'Die Katalogkarten laden ihre Bilder nicht lazy.'
        );

        $detail = $this->getOk('/horse?id=' . $horseId);
        $hero = $this->extractTag($detail, 'horse-hero-photo');
        $this->assertNotNull($hero, 'Das Hero-Foto der Detailseite wurde nicht gefunden.');
        $this->assertStringNotContainsString(
            'loading="lazy"',
            $hero,
            'Das Hero-Foto ist das LCP-Element der Detailseite und darf NICHT lazy laden.'
        );
        $this->assertStringContainsString(
            'fetchpriority="high"',
            $hero,
            'Das Hero-Foto soll in der Ladewarteschlange ausdrücklich vorgezogen werden.'
        );
    }

    /**
     * Die öffentliche Seite lädt NICHTS von einem fremden Host.
     *
     * Vorher hing hier die umgekehrte Zusage: Das Schriften-Stylesheet von
     * fonts.googleapis.com sollte wenigstens nicht das Rendern blockieren.
     * Nicht-blockierend war die richtige Antwort auf die falsche Frage - jeder
     * Besucher meldete damit weiterhin IP-Adresse und aufgerufene Seite an
     * einen Dritten, und die Darstellung hing an dessen Verfügbarkeit. Die
     * Schrift kommt jetzt aus dem System-Stack.
     *
     * Der Test prüft deshalb das Ganze statt des Einzelfalls: kein Verweis auf
     * einen fremden Host in der Antwort, egal ob Schrift, Skript oder Bild.
     */
    public function testPublicPageLoadsNothingFromThirdPartyHosts(): void {
        $body = $this->getOk('/');

        $this->assertStringNotContainsString('fonts.googleapis.com', $body);
        $this->assertStringNotContainsString('fonts.gstatic.com', $body);

        // Nur Verweise, die der Browser VON SICH AUS nachlädt: src an
        // Skript/Bild/Rahmen und href an <link>. Gewöhnliche <a href> zu
        // GitHub im Fußbereich sind Navigation - der Besucher entscheidet, ob
        // er darauf klickt, und dabei fließt nichts ungefragt ab.
        preg_match_all('/\bsrc="(https?:\/\/[^"]+)"/i', $body, $srcMatches);
        preg_match_all('/<link\b[^>]*\bhref="(https?:\/\/[^"]+)"/i', $body, $linkMatches);

        $external = array_values(array_filter(
            array_merge($srcMatches[1], $linkMatches[1]),
            static fn (string $url): bool => !str_starts_with($url, \Tests\Support\PhpBuiltInServer::baseUrl())
        ));

        $this->assertSame(
            [],
            $external,
            'Die öffentliche Seite darf keine Ressourcen von fremden Hosts nachladen: ' . implode(', ', $external)
        );

        // Das eigene Grund-Stylesheet bleibt bewusst blockierend: asynchron
        // geladen zeigte die Seite garantiert einmal ungestylt.
        $this->assertMatchesRegularExpression(
            '/<link rel="stylesheet" href="\/css\/style\.css">/',
            $body,
            'style.css soll unverändert blockierend geladen werden.'
        );
    }

    /**
     * Sicherheitsabfragen hängen an data-confirm und werden von einem
     * ausgelagerten Skript ausgewertet - nicht mehr an einem onsubmit-Attribut,
     * in das PHP einen Text hineinschreibt (siehe public/js/confirm-submit.js).
     */
    public function testConfirmDialogsUseDataAttributesInsteadOfInlineHandlers(): void {
        // Das Skript wird auf jeder Seite eingebunden - das prüft die
        // Startseite mit.
        $oeffentlich = $this->getOk('/');
        $this->assertStringContainsString('/js/confirm-submit.js', $oeffentlich, 'Das Skript für die Sicherheitsabfragen fehlt.');

        // Die eigentliche Zusicherung braucht aber eine Seite, auf der
        // Sicherheitsabfragen ÜBERHAUPT vorkommen. Auf '/' steht kein
        // einziges Bestätigungsformular - dort war
        // assertStringNotContainsString('onsubmit=') eine Aussage über nichts
        // und wäre auch dann grün geblieben, wenn jede Admin-Ansicht wieder
        // auf onsubmit="return confirm('…')" zurückgebaut worden wäre.
        $verwaltung = $this->authenticatedClient()->get('/admin/horses');
        $this->assertSame(200, $verwaltung->statusCode);

        // Positiv UND negativ: Ohne den ersten Teil wird der Test wieder
        // inhaltsleer, sobald die Formulare einmal verschwinden.
        $this->assertStringContainsString(
            'data-confirm=',
            $verwaltung->body,
            'Die Pferdeliste muss Sicherheitsabfragen enthalten - sonst prüft die Zeile darunter nichts.'
        );
        $this->assertStringNotContainsString('onsubmit=', $verwaltung->body);
    }

    private function getOk(string $path): string {
        $response = $this->newClient()->get($path);
        $this->assertSame(200, $response->statusCode, "{$path} lieferte nicht 200.");
        return $response->body;
    }

    /** Schneidet das <img>-Tag mit der gesuchten Klasse aus dem HTML heraus. */
    private function extractTag(string $html, string $class): ?string {
        if (preg_match('/<img[^>]*\b' . preg_quote($class, '/') . '\b[^>]*>/', $html, $m) !== 1) {
            return null;
        }
        return $m[0];
    }

    private function seedPublishedHorseWithPhoto(): int {
        $db = Database::getInstance();
        $db->prepare(
            "INSERT INTO horses (name, sex, image_url, is_published, created_at)
             VALUES (?, 'stallion', ?, 1, NOW())"
        )->execute(['Ladeprobe ' . uniqid(), '/uploads/horses/ladeprobe.jpg']);

        $id = (int)$db->lastInsertId();
        $this->seededHorseIds[] = $id;
        return $id;
    }
}
