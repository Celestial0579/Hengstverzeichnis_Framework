<?php
// src/Helper/BoundedKey.php

namespace App\Helper;

/**
 * Setzt Einstellungs- und Modulschlüssel so zusammen, dass sie in ihre Spalte
 * passen (Audit N74).
 *
 * DAS PROBLEM. `settings.setting_key` und `group_permissions.module` sind
 * VARCHAR(50). Schlüssel wie `cron_last_run__<Aufgabe>` oder
 * `feature_visibility__<Funktion>` entstehen aber aus Namen, die ein Addon
 * frei wählt. Ab 36 bzw. 31 Zeichen Namenslänge sprengte der Schlüssel die
 * Spalte: Im Strict-Mode (Standard bei MariaDB/MySQL) scheiterte das
 * Speichern mit einer Ausnahme - bei Cron-Aufgaben sogar im catch-Zweig des
 * Schedulers, sodass alle folgenden Aufgaben des Laufs ausfielen. Ohne
 * Strict-Mode wurde still abgeschnitten, und der gespeicherte Wert wurde nie
 * wiedergefunden.
 *
 * WARUM HASH STATT ABSCHNEIDEN ODER ABWEISEN. Abgeschnittene Schlüssel
 * kollidieren (zwei lange Namen mit gleichem Anfang) oder werden nie
 * wiedergefunden. Abgewiesene Namen ließen die Aufgabe bzw. die
 * Zusatzfunktion still ausfallen (FeatureGate ist fail-closed). Ein Hash über
 * den vollen Namen ist deterministisch, praktisch kollisionsfrei und passt
 * immer. Schlüssel, die schon heute passen, bleiben bitgleich - bestehende
 * Einstellungen gelten weiter.
 *
 * Der Preis: Ein gehashter Schlüssel ist in der Datenbank nicht mehr lesbar.
 * Wer ihn braucht, holt ihn über die Methode, die ihn bildet (etwa
 * `FeatureRegistry::settingKey()`), statt ihn selbst zusammenzusetzen.
 */
final class BoundedKey {

    /** Länge von `settings.setting_key` (database/schema.sql). */
    public const SETTING_KEY_LENGTH = 50;

    /** Länge von `group_permissions.module` (database/schema.sql). */
    public const PERMISSION_MODULE_LENGTH = 50;

    /**
     * Mindestanzahl Hash-Zeichen hinter dem Präfix. 16 Hex-Zeichen sind 64
     * Bit - genug, dass zwei Addon-Namen nicht zufällig zusammenfallen.
     */
    private const MIN_HASH_LENGTH = 16;

    private function __construct() {}

    /**
     * Gibt `$prefix . $suffix` unverändert zurück, solange es höchstens
     * `$maxLength` Zeichen hat (gezählt in Zeichen, nicht Bytes - so zählt
     * auch die Spalte). Sonst `$prefix` plus so viele Zeichen des
     * SHA-256-Hashes des Suffixes, wie in die Spalte passen.
     *
     * @throws \LogicException wenn das Präfix so lang ist, dass kein
     *         ausreichend langer Hash mehr dahinter passt - ein
     *         Programmierfehler, kein Laufzeitzustand.
     */
    public static function fit(string $prefix, string $suffix, int $maxLength = self::SETTING_KEY_LENGTH): string {
        $room = $maxLength - mb_strlen($prefix, 'UTF-8');
        if ($room < self::MIN_HASH_LENGTH) {
            throw new \LogicException(sprintf(
                'Präfix "%s" lässt bei höchstens %d Zeichen keinen Platz für einen Hash.',
                $prefix,
                $maxLength
            ));
        }

        $key = $prefix . $suffix;
        if (mb_strlen($key, 'UTF-8') <= $maxLength) {
            return $key;
        }

        return $prefix . substr(hash('sha256', $suffix), 0, $room);
    }
}
