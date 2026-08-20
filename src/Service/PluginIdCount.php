<?php
// src/Service/PluginIdCount.php

namespace App\Service;

/**
 * Wie viele Kennungen der Addon-Filter `horse.search_ids` geliefert hat (#371).
 *
 * WOZU EIN EIGENER TYP FÜR EINE ZAHL. HorseSearchSql ist die erzeugende Hälfte
 * der Suche, und tests/Unit/Service/HorseSearchSqlSafetyTest.php nagelt per
 * Reflection fest, dass ihre öffentlichen Methoden AUSSCHLIESSLICH Typen
 * annehmen, in denen kein Anfragewert stecken kann - bisher nur
 * HorseSearchCondition und bool. Der Test prüft die Signatur, nicht das
 * Verhalten, und zwar ausdrücklich, damit niemand später eine Tür einbaut:
 * ein `add(string $fragment)` fiele dort sofort auf.
 *
 * Für die IN-Liste braucht die Klausel aber eine Stückzahl. Ein blankes `int`
 * in der Signatur hätte den Wächter dauerhaft für JEDE künftige Zahl geöffnet,
 * ohne dass es noch jemandem auffällt. Dieser Typ hält ihn geschlossen: Er
 * lässt sich nur aus einem ARRAY bilden, seine Zahl ist also immer eine
 * Array-Länge und nie ein durchgereichter Wert. Ein Anfragewert kann gar nicht
 * die Form annehmen, in der er hier hineinkäme.
 */
final class PluginIdCount {

    private function __construct(public readonly int $anzahl) {}

    /**
     * Der einzige Weg zu einer Zahl: über die Länge der Kennungsliste.
     *
     * @param array<int, int> $ids
     */
    public static function fromIds(array $ids): self {
        return new self(count($ids));
    }

    /** Kein Addon-Filter aktiv. */
    public static function keine(): self {
        return new self(0);
    }
}
