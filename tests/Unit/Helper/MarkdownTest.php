<?php
// tests/Unit/Helper/MarkdownTest.php

namespace Tests\Unit\Helper;

use App\Helper\Markdown;
use PHPUnit\Framework\TestCase;

class MarkdownTest extends TestCase {

    public function testEmptyAndNullInputReturnEmptyString(): void {
        $this->assertSame('', Markdown::parse(null));
        $this->assertSame('', Markdown::parse(''));
    }

    public function testEscapesRawHtmlToPreventXss(): void {
        $result = Markdown::parse('<script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }

    public function testEscapesHtmlAttributeInjectionAttempt(): void {
        $result = Markdown::parse('"><img src=x onerror=alert(1)>');

        $this->assertStringNotContainsString('<img', $result);
    }

    public function testHeadingsAreConverted(): void {
        $this->assertStringContainsString('<h1', Markdown::parse('# Titel'));
        $this->assertStringContainsString('<h2', Markdown::parse('## Untertitel'));
        $this->assertStringContainsString('<h3', Markdown::parse('### Abschnitt'));
    }

    public function testBoldAndItalic(): void {
        $this->assertStringContainsString('<strong>fett</strong>', Markdown::parse('**fett**'));
        $this->assertStringContainsString('<em>kursiv</em>', Markdown::parse('*kursiv*'));
    }

    public function testMultilineListDoesNotSwallowMarkersAsItalic(): void {
        // Regressionstest für #38: die Kursiv-Regel darf mehrzeilige Listen mit
        // *-Markern nicht als einen einzigen <em>-Block verschlucken.
        $result = Markdown::parse("* Erster Punkt\n* Zweiter Punkt\n* Dritter Punkt");

        $this->assertSame(3, substr_count($result, '<li>'));
        $this->assertStringNotContainsString('<em>', $result);
    }

    public function testListItemsAreWrappedInUl(): void {
        $result = Markdown::parse("- Punkt A\n- Punkt B");

        $this->assertStringContainsString('<ul', $result);
        $this->assertStringContainsString('<li>Punkt A</li>', $result);
        $this->assertStringContainsString('<li>Punkt B</li>', $result);
    }

    public function testHttpLinkIsConverted(): void {
        $result = Markdown::parse('[Klick hier](https://example.com)');

        $this->assertStringContainsString('<a href="https://example.com"', $result);
        $this->assertStringContainsString('rel="noopener noreferrer"', $result);
    }

    public function testJavascriptUriLinkIsNotConvertedToAnchor(): void {
        // Nur http(s)-Schemes werden vom Link-Pattern akzeptiert - javascript:-URIs
        // bleiben als (bereits htmlspecialchars-escapter) Text stehen statt als
        // klickbarer <a href="javascript:...">-Link zu landen.
        $result = Markdown::parse('[Klick hier](javascript:alert(1))');

        $this->assertStringNotContainsString('<a href="javascript:', $result);
    }

    public function testParagraphsAreWrappedAndSeparated(): void {
        $result = Markdown::parse("Erster Absatz.\n\nZweiter Absatz.");

        $this->assertSame(2, substr_count($result, '<p '));
        $this->assertStringContainsString('Erster Absatz.', $result);
        $this->assertStringContainsString('Zweiter Absatz.', $result);
    }

    /**
     * Die Kursiv- und Fett-Regeln liefen früher NACH der Link-Regel über den
     * gesamten Text - also auch über das gerade erzeugte <a href="...">. Eine
     * URL mit Unterstrich oder Sternchen bekam dadurch fertige Tags mitten in
     * den Attributwert geschoben, und ein unpaariges Sternchen hinterließ
     * sogar ein hängendes </em> darin.
     */
    public function testUrlsWithMarkdownCharactersSurviveIntact(): void {
        $result = Markdown::parse('[x](http://a.com/_foo_bar)');
        $this->assertStringContainsString('href="http://a.com/_foo_bar"', $result);
        $this->assertStringNotContainsString('<em>', $result);

        $result = Markdown::parse('*[x](http://a.com/a*b)*');
        $this->assertStringContainsString('href="http://a.com/a*b"', $result);
        $this->assertStringNotContainsString('href="http://a.com/a</em>b"', $result);
    }

    public function testOnlyHttpAndHttpsLinksAreTurnedIntoAnchors(): void {
        // Was nicht als http(s)-URL durchgeht, bleibt Text - kein Link ist
        // besser als einer an eine ungeprüfte Adresse.
        $this->assertStringNotContainsString('<a ', Markdown::parse('[x](javascript:alert(1))'));
        $this->assertStringNotContainsString('<a ', Markdown::parse('[x](http://a.com/" onmouseover=alert(1) x=")'));

        // Der Normalfall bleibt unberührt, inklusive Query-String.
        $result = Markdown::parse('[Beispiel](https://example.org/pfad?a=1&b=2#anker)');
        $this->assertStringContainsString('href="https://example.org/pfad?a=1&amp;b=2#anker"', $result);
        $this->assertStringContainsString('rel="noopener noreferrer"', $result);
    }

    public function testBoldAndItalicStillWorkAlongsideLinks(): void {
        $result = Markdown::parse('**fett** und [link](https://a.de) und *kursiv*');
        $this->assertStringContainsString('<strong>fett</strong>', $result);
        $this->assertStringContainsString('<em>kursiv</em>', $result);
        $this->assertStringContainsString('href="https://a.de"', $result);
    }
}
