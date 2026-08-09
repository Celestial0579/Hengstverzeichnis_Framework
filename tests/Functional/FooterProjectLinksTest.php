<?php
// tests/Functional/FooterProjectLinksTest.php

namespace Tests\Functional;

/**
 * Footer-Projektlinks (#184-#187): Handbuch (Wiki), Diskussionen, Fehler
 * melden (Issues) und Lizenz müssen auf jeder öffentlichen Seite im Footer
 * stehen und als externe Links (target="_blank" rel="noopener") öffnen.
 */
class FooterProjectLinksTest extends FunctionalTestCase {

    public function testFooterContainsProjectLinksInBothLocales(): void {
        $repo = 'https://github.com/Celestial0579/Hengstverzeichnis_Framework';

        $response = $this->newClient()->get('/');
        $this->assertSame(200, $response->statusCode);
        foreach (['/wiki', '/discussions', '/issues/new', '/blob/main/LICENSE'] as $path) {
            $this->assertStringContainsString(
                '<a href="' . $repo . $path . '" target="_blank" rel="noopener">',
                $response->body,
                "Footer-Link auf {$path} fehlt"
            );
        }
        $this->assertStringContainsString('Fehler melden', $response->body);
        $this->assertStringContainsString('Handbuch', $response->body);

        // Englische Beschriftungen über den Sprachumschalter (Session-Cookie
        // hält die Locale, deshalb derselbe Client).
        $client = $this->newClient();
        $english = $client->get('/?lang=en');
        $this->assertSame(200, $english->statusCode);
        $this->assertStringContainsString('Report a bug', $english->body);
        $this->assertStringContainsString('Manual', $english->body);
    }
}
