<?php
// src/Helper/TelUrl.php

namespace App\Helper;

/**
 * Macht eine von Hand eingetragene Telefonnummer für ein `tel:`-Verweisziel
 * maschinenlesbar (#359).
 *
 * Öffentlich gezeigte Nummern waren bisher reiner Text - auf dem Telefon ließ
 * sich damit nicht wählen, ohne die Nummer abzuschreiben. Die E-Mail-Adresse
 * daneben ist längst ein `mailto:`-Link, die Website ein geprüfter externer
 * Link; nur das Telefon blieb Text.
 *
 * WAS BEWUSST NICHT PASSIERT: Aus einer führenden `0` wird **keine**
 * Landesvorwahl gemacht. Das wäre geraten - der Bestand enthält unter anderem
 * Deckstationen in Dänemark und Norwegen, und `+49` davorzusetzen erzeugte
 * dort eine falsche Nummer. Ohne Landesvorwahl wählt das Telefon die Nummer
 * weiterhin lokal, also genau so, wie sie dasteht.
 *
 * Die Sichtbarkeit ändert sich durch diesen Helfer nicht: Verlinkt wird nur,
 * was ohnehin schon öffentlich auf der Seite steht.
 */
final class TelUrl {

    /**
     * Zeichen, die in geschriebenen Nummern zur Gliederung dienen und in der
     * Adresse nichts verloren haben: Leerraum, Schrägstrich, Klammern,
     * Bindestrich, Punkt.
     */
    private const TRENNZEICHEN = '/[\s\/()\-.]+/u';

    private function __construct() {}

    /**
     * Liefert das `href`-Ziel (`tel:+49301234567`), wenn sich aus der Eingabe
     * eine wählbare Nummer ergibt - sonst null.
     *
     * Bewusst `null` statt Leerstring, wie bei ExternalUrl: Der Aufrufer soll
     * die Nummer dann als reinen Text ausgeben statt einen toten Verweis zu
     * erzeugen.
     */
    public static function hrefOrNull(?string $phone): ?string {
        $roh = trim((string)$phone);
        if ($roh === '') {
            return null;
        }

        // Ein führendes Plus ist die einzige Information, die keine Ziffer ist
        // und trotzdem zählt - es unterscheidet die internationale Schreibweise
        // von der nationalen.
        $international = str_starts_with($roh, '+');

        // Die geklammerte Null nach der Landesvorwahl ("+49 (0) 301 …") ist
        // eine Schreibkonvention: Sie sagt, dass beim Wählen AUS DEM AUSLAND
        // die Null entfällt. Würde sie wie eine gewöhnliche Klammer nur
        // entfernt, entstünde "+490301…" - eine Nummer, die es nicht gibt, die
        // aber tatsächlich gewählt würde. Sie wird deshalb samt Null verworfen,
        // und zwar nur in der internationalen Schreibweise; national ist eine
        // führende Null echter Bestandteil der Vorwahl.
        $bereinigt = $international
            ? preg_replace('/\(\s*0\s*\)/u', '', $roh) ?? $roh
            : $roh;

        $ziffern = preg_replace(self::TRENNZEICHEN, '', $bereinigt) ?? '';
        $ziffern = ltrim($ziffern, '+');

        // Alles, was jetzt noch keine reine Ziffernfolge ist, war keine
        // Telefonnummer: Durchwahl-Hinweise ("(0)"), Sammelnummern mit
        // Erläuterung, versehentlich im Feld gelandete Freitexte. Solche
        // Einträge bleiben Text - ein tel:-Link darauf würde beim Antippen
        // irgendetwas wählen.
        if ($ziffern === '' || !ctype_digit($ziffern)) {
            return null;
        }

        // Zu kurz für eine wählbare Nummer (Hausnummern, Jahreszahlen, Reste
        // aus Altdaten). Die Grenze ist bewusst niedrig - kurze Servicenummern
        // gibt es, und der Zweifel geht zugunsten des Bestands aus.
        if (strlen($ziffern) < 4) {
            return null;
        }

        return 'tel:' . ($international ? '+' : '') . $ziffern;
    }
}
