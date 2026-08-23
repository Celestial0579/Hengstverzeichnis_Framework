<?php
// tests/Functional/StempelWarnungTest.php

namespace Tests\Functional;

/**
 * Die Wachstumswarnung für den Verzeichnis-Stempel im Adminbereich (#400).
 *
 * Die Schwellenlogik selbst deckt `StempelAufwandTest` (Unit) ab. Hier geht es
 * um die andere Hälfte, und die ist genauso wichtig: **Die Warnung darf im
 * Normalbetrieb nicht erscheinen.**
 *
 * Eine Warnung, die immer dasteht, ist keine Warnung, sondern Möblierung. Wer
 * sie ein halbes Jahr lang übersieht, übersieht sie auch an dem Tag, an dem
 * sie zählt. Die Schwelle liegt deshalb beim Fünffachen des heutigen
 * Gesamtbestands aller Addons — und dieser Test hält fest, dass sie dort auch
 * bleibt.
 */
class StempelWarnungTest extends FunctionalTestCase {

    public function testImNormalbetriebErscheintKeineWarnung(): void {
        $admin = $this->authenticatedClient();

        $seite = $admin->get('/admin/plugins');

        $this->assertSame(200, $seite->statusCode);
        $this->assertStringNotContainsString(
            'Die Prüfung der Addon-Dateien wird spürbar',
            $seite->body,
            'Die Testinstanz hat eine übliche Zahl von Addons. Erscheint die Warnung hier, '
            . 'ist die Schwelle zu niedrig - und damit wertlos, weil sie zum Rauschen wird.'
        );
    }

    /**
     * Und die Seite selbst muss weiterhin funktionieren. Der Zähler sitzt in
     * `computeDirStamp()`, also mitten in der Freigabe-Kette; ein Fehler dort
     * fiele als kaputte Plugin-Verwaltung auf, nicht als falsche Zahl.
     */
    public function testDiePluginVerwaltungLaeuftWeiterhin(): void {
        $admin = $this->authenticatedClient();

        $seite = $admin->get('/admin/plugins');

        $this->assertSame(200, $seite->statusCode);
        $this->assertStringContainsString('Plugins verwalten', $seite->body);
    }
}
