<?php
// tests/Functional/FooterCopyrightGroupingTest.php

namespace Tests\Functional;

/**
 * Zuordnung von Copyright und Links in der Fußzeile (#257).
 *
 * Vorher standen beide Copyright-Angaben zusammen in einer Zeile und ihre Links
 * darunter - welcher Link zu welchem Copyright gehört, war nicht erkennbar. Die
 * inhaltliche Trennung ist eine bewusste Entscheidung (#199): Das Betreiber-(c)
 * deckt Inhalte und Daten der Installation, das Framework-(c) den Code
 * (§ 13 UrhG, AGPL-3.0 § 5(d)).
 *
 * Der Test sichert die Reihenfolge zu, nicht das Aussehen: Betreiber-Copyright →
 * Instanz-Links → Framework-Copyright → Projekt-Links, in zwei getrennten
 * Blöcken. Ein späterer Umbau der Fußzeile darf die Zuordnung nicht wieder
 * einebnen.
 */
class FooterCopyrightGroupingTest extends FunctionalTestCase {

    public function testOwnerAndFrameworkCopyrightAreSeparateBlocksWithTheirOwnLinks(): void {
        $response = $this->newClient()->get('/');
        $this->assertSame(200, $response->statusCode);
        $body = $response->body;

        $this->assertSame(
            2,
            substr_count($body, '<div class="footer-group">'),
            'Die Fußzeile besteht aus genau zwei Blöcken: Betreiber und Framework.'
        );

        $ownerBlock = strpos($body, '<div class="footer-group">');
        $impressum = strpos($body, 'href="/impressum"');
        $frameworkAuthor = strpos($body, 'href="https://github.com/Celestial0579"');
        $manual = strpos($body, '/Hengstverzeichnis_Framework/wiki');

        foreach (['Blockanfang' => $ownerBlock, 'Impressum-Link' => $impressum,
                  'Framework-Urheber' => $frameworkAuthor, 'Handbuch-Link' => $manual] as $label => $pos) {
            $this->assertNotFalse($pos, "{$label} in der Fußzeile nicht gefunden.");
        }

        $this->assertLessThan($impressum, $ownerBlock);
        $this->assertLessThan(
            $frameworkAuthor,
            $impressum,
            'Die Links zur Instanz (Impressum/Datenschutz/DSGVO) gehören zum Betreiber-Copyright '
            . 'und stehen deshalb VOR dem Framework-Copyright.'
        );
        $this->assertLessThan(
            $manual,
            $frameworkAuthor,
            'Die Projekt-Links (Handbuch/Austausch/Fehlermeldung/Lizenz) gehören zum '
            . 'Framework-Copyright und stehen deshalb danach.'
        );

        // Der Kern der Änderung: Zwischen Instanz-Links und Framework-Copyright
        // muss ein Blockwechsel liegen. Stünden beide Copyrights weiterhin in
        // einer gemeinsamen Zeile, wäre die Reihenfolge oben zufällig erfüllbar,
        // ohne dass die Zuordnung sichtbar wäre.
        $between = substr($body, $impressum, $frameworkAuthor - $impressum);
        $this->assertStringContainsString(
            '</div>',
            $between,
            'Betreiber- und Framework-Angaben müssen in getrennten Blöcken stehen, '
            . 'nicht nur in verschiedenen Absätzen desselben Blocks.'
        );
    }
}
