<?php
// tests/Functional/EmbedLayoutTest.php

namespace Tests\Functional;

/**
 * Minimal-Layout und gezielte Lockerung der Frame-Sperre (#260).
 *
 * Der heikle Teil ist nicht das Layout, sondern die Sicherheitsentscheidung
 * dahinter. Drei Dinge müssen zusammenpassen, und jedes einzelne hebt die
 * Wirkung der anderen auf, wenn es kippt:
 *
 * 1. **Ohne Freigabe ändert sich nichts.** `EMBED_ALLOWED_DOMAINS` ist im
 *    Auslieferungszustand leer; dann bleibt die Frame-Sperre auch für die
 *    Embed-Ansicht bestehen. Ein Addon, das eine Embed-Route anbietet, darf
 *    die Sperre nicht als Nebenwirkung öffnen.
 * 2. **Das Minimal-Layout lockert von sich aus nichts.** `?embed=1` ist eine
 *    Darstellungsfrage. Wer beides koppelt, öffnet die Sperre für jeden, der
 *    den Parameter anhängt.
 * 3. **Normale Seiten bleiben unangetastet.**
 *
 * Diese Suite läuft ohne konfigurierte Allowlist - sie prüft damit genau den
 * Auslieferungszustand, also den Fall, der bei jeder Installation gilt, die
 * das Feature gar nicht nutzt.
 */
class EmbedLayoutTest extends FunctionalTestCase {

    public function testNormalCatalogKeepsHeaderFooterAndFrameProtection(): void {
        $response = $this->newClient()->get('/katalog');
        $this->assertSame(200, $response->statusCode);

        $this->assertStringContainsString('<header>', $response->body, 'Die normale Seite braucht ihren Kopfbereich.');
        $this->assertStringContainsString('<footer', $response->body, 'Die normale Seite braucht ihre Fußzeile.');
        $this->assertSame('SAMEORIGIN', $response->header('X-Frame-Options'));
        $this->assertStringContainsString("frame-ancestors 'self'", (string)$response->header('Content-Security-Policy'));
    }

    public function testEmbedViewDropsHeaderAndFooterButKeepsTheming(): void {
        $response = $this->newClient()->get('/katalog?embed=1');
        $this->assertSame(200, $response->statusCode);

        $this->assertStringNotContainsString('<header>', $response->body, 'Im Rahmen hat der Kopfbereich nichts zu suchen.');
        $this->assertStringNotContainsString('<footer', $response->body, 'Im Rahmen hat die Fußzeile nichts zu suchen.');

        // Ein iframe erbt KEIN CSS von der einbettenden Seite - ohne diese
        // beiden stünde das Snippet ungestylt in einer fremden Seite.
        $this->assertStringContainsString('/css/style.css', $response->body, 'Das Grund-Stylesheet fehlt im Rahmen.');
        $this->assertStringContainsString('--primary-color:', $response->body, 'Die Markenfarben fehlen im Rahmen.');

        // Der Rahmeninhalt ist keine eigenständige Seite und darf nicht mit der
        // echten Katalogseite um Suchergebnisse konkurrieren.
        $this->assertStringContainsString('noindex', $response->body, 'Der Rahmeninhalt muss noindex tragen.');

        // Der Darkmode-FOUC-Fix gilt auch im Rahmen: Ein iframe rendert
        // eigenständig, das Aufblitzen passiert dort genauso (#91).
        $this->assertStringContainsString('data-theme', $response->body, 'Der FOUC-Fix fehlt im Rahmen.');

        // Der Inhalt selbst muss natürlich noch da sein.
        $this->assertStringContainsString('catalog-grid', $response->body);
    }

    /**
     * Der eigentliche Sicherheitspunkt. Ohne konfigurierte Allowlist bleibt die
     * Frame-Sperre bestehen - auch für die Embed-Ansicht. Wäre das anders,
     * genügte das Anhängen von `?embed=1`, um jede Installation
     * clickjacking-fähig zu machen.
     */
    public function testEmbedViewDoesNotLoosenFrameProtectionWithoutAnAllowlist(): void {
        $response = $this->newClient()->get('/katalog?embed=1');

        $this->assertSame(
            'SAMEORIGIN',
            $response->header('X-Frame-Options'),
            'Ohne freigegebene Domains muss X-Frame-Options stehen bleiben.'
        );
        $this->assertStringContainsString(
            "frame-ancestors 'self'",
            (string)$response->header('Content-Security-Policy'),
            'Ohne freigegebene Domains muss frame-ancestors auf self bleiben.'
        );
    }

    /**
     * Die Allowlist wird nur über die Konfiguration gesetzt, nie über die
     * Anfrage. Ein Parameter, der sie beeinflussen könnte, wäre die Sperre
     * selbst - deshalb hier ausdrücklich geprüft.
     */
    public function testFrameProtectionCannotBeLoosenedByRequestParameters(): void {
        foreach ([
            '/katalog?embed=1&embed_allowed_domains=https://angreifer.example',
            '/katalog?embed=1&EMBED_ALLOWED_DOMAINS=https://angreifer.example',
            '/katalog?embed=https://angreifer.example',
        ] as $url) {
            $response = $this->newClient()->get($url);
            $this->assertSame(
                'SAMEORIGIN',
                $response->header('X-Frame-Options'),
                "Die Frame-Sperre liess sich ueber die Anfrage lockern: {$url}"
            );
            $this->assertStringNotContainsString(
                'angreifer.example',
                (string)$response->header('Content-Security-Policy'),
                "Ein Anfrageparameter ist in die CSP gelangt: {$url}"
            );
        }
    }

    /**
     * Die CSP wird seit #260 an einer Stelle gebaut, damit die zwei Fassungen
     * nicht auseinanderdriften. Wer eine Direktive ergänzt, muss sie in beiden
     * Fassungen haben - hier belegt am Vergleich der Direktivnamen.
     */
    public function testBothPolicyVariantsCarryTheSameDirectives(): void {
        $default = \App\Security\ContentSecurityPolicy::build();
        $embed = \App\Security\ContentSecurityPolicy::build(['https://partner.example']);

        $names = static function (string $policy): array {
            $out = [];
            foreach (explode(';', $policy) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $out[] = explode(' ', $part)[0];
                }
            }
            sort($out);
            return $out;
        };

        $this->assertSame(
            $names($default),
            $names($embed),
            'Die beiden Policy-Fassungen führen unterschiedliche Direktiven - genau die Drift, gegen die die gemeinsame Stelle existiert.'
        );
        $this->assertStringContainsString("frame-ancestors 'self' https://partner.example", $embed);
        $this->assertStringContainsString("frame-ancestors 'self'", $default);
        $this->assertStringNotContainsString('partner.example', $default);
    }

    /**
     * `'self'` bleibt auch bei gesetzter Allowlist enthalten: Ein Tippfehler in
     * der Freigabe darf nicht dazu führen, dass die eigene Oberfläche sich
     * selbst aussperrt.
     */
    public function testOwnOriginStaysAllowedEvenWithAnAllowlist(): void {
        $embed = \App\Security\ContentSecurityPolicy::build(['https://partner.example']);
        $this->assertMatchesRegularExpression("/frame-ancestors 'self'\\s/", $embed);
    }

    /**
     * Die Gegenrichtung - und der eigentliche Zweck von #260: MIT freigegebenen
     * Domains muss die Lockerung auch tatsächlich greifen.
     *
     * Dafür läuft eine zweite App-Instanz mit gesetztem
     * `EMBED_ALLOWED_DOMAINS` (dasselbe Muster wie beim SSO-Test): Der geteilte
     * Testserver bleibt bewusst unkonfiguriert, damit die
     * "ohne Freigabe passiert nichts"-Zusicherungen oben gültig bleiben.
     *
     * Ohne diesen Test wäre nur belegt, dass das Feature AUS ist.
     */
    public function testWithAllowlistTheEmbedViewLoosensFramingButNormalPagesDoNot(): void {
        $server = new \Tests\Support\AuxiliaryServer(
            port: 8791,
            docroot: __DIR__ . '/../../public',
            env: ['EMBED_ALLOWED_DOMAINS' => 'https://partner.example,https://zweiter.example'],
        );
        $server->start();

        try {
            $client = new \Tests\Support\HttpClient($server->baseUrl());

            $embed = $client->get('/katalog?embed=1');
            $this->assertSame(200, $embed->statusCode);

            // X-Frame-Options kann keine Allowlist ausdrücken (ALLOW-FROM ist
            // zurückgezogen). Bliebe er stehen, blockierte ein Browser, der ihn
            // kennt, trotz Freigabe - er MUSS also weg.
            $this->assertNull(
                $embed->header('X-Frame-Options'),
                'X-Frame-Options muss für eine Embed-Antwort entfernt werden - sonst überstimmt er die Freigabe.'
            );

            $csp = (string)$embed->header('Content-Security-Policy');
            $this->assertStringContainsString('https://partner.example', $csp);
            $this->assertStringContainsString('https://zweiter.example', $csp);
            $this->assertStringContainsString(
                "frame-ancestors 'self' https://partner.example https://zweiter.example",
                $csp,
                'Die eigene Herkunft muss neben der Allowlist erhalten bleiben.'
            );

            // Und der Kern der Entscheidung: Die Freigabe gilt NUR für die
            // Embed-Ansicht. Eine normale Seite bleibt gesperrt, sonst wäre aus
            // der gezielten Lockerung eine pauschale geworden.
            $normal = $client->get('/katalog');
            $this->assertSame(
                'SAMEORIGIN',
                $normal->header('X-Frame-Options'),
                'Die normale Katalogseite darf trotz Allowlist nicht einbettbar werden.'
            );
            $this->assertStringContainsString(
                "frame-ancestors 'self';",
                (string)$normal->header('Content-Security-Policy') . ';',
                'Die normale Katalogseite darf die Allowlist nicht führen.'
            );
            $this->assertStringNotContainsString(
                'partner.example',
                (string)$normal->header('Content-Security-Policy'),
                'Die Allowlist ist auf eine normale Seite durchgeschlagen.'
            );
        } finally {
            $server->stop();
        }
    }

    /**
     * Die dritte Stelle aus #260. `X-Frame-Options` darf NICHT zusätzlich in
     * public/.htaccess gesetzt werden: Apache setzt seine Header nach PHP und
     * würde den für eine Embed-Antwort entfernten Header wieder anfügen - die
     * Freigabe wäre still aufgehoben, und kein Test, der nur PHP-Antworten
     * prüft, würde es merken. Deshalb prüft dieser Test die Datei selbst.
     */
    public function testHtaccessDoesNotReintroduceXFrameOptions(): void {
        $htaccess = file_get_contents(__DIR__ . '/../../public/.htaccess');
        $this->assertIsString($htaccess);

        $active = array_filter(
            array_map('trim', explode("\n", $htaccess)),
            static fn(string $line): bool => $line !== '' && !str_starts_with($line, '#')
        );

        foreach ($active as $line) {
            $this->assertStringNotContainsStringIgnoringCase(
                'X-Frame-Options',
                $line,
                'public/.htaccess setzt X-Frame-Options wieder - das hebt die Embed-Freigabe still auf (#260).'
            );
        }
    }
}
