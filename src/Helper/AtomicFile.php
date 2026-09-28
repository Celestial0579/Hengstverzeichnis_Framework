<?php
// src/Helper/AtomicFile.php

namespace App\Helper;

/**
 * Schreibt eine Datei atomar (Audit N56).
 *
 * WARUM. config/db_config.php ist in klassischen Installationen die einzige
 * Ablage von APP_KEY und DB-Passwort. file_put_contents() kürzt die Datei
 * erst und schreibt dann; scheitert das Schreiben (volle Platte, Quota),
 * bleibt eine leere oder halbe Datei zurück - APP_KEY verloren, alle
 * verschlüsselten Geheimnisse unlesbar, jeder Request ohne Datenbank.
 * Parallele Requests konnten die Datei zudem halb geschrieben per `require`
 * einlesen.
 *
 * WIE. Neue Datei im SELBEN Verzeichnis anlegen, vollständig schreiben,
 * fsync, dann per rename() über das Ziel legen. rename() ist innerhalb eines
 * Dateisystems atomar: Leser sehen entweder die alte oder die neue Datei.
 * Schlägt irgendein Schritt fehl, bleibt das Original unberührt.
 *
 * BEWUSST KEIN tempnam(): Ist das Zielverzeichnis nicht beschreibbar, weicht
 * tempnam() mit einem Notice STILL in das System-Temp-Verzeichnis aus. Die
 * Datei mit APP_KEY und DB-Passwort läge dann in /tmp, und rename() über
 * Dateisystemgrenzen wäre Kopieren plus Löschen, also nicht atomar.
 * Stattdessen ein Zufallsname im Zielverzeichnis, exklusiv angelegt
 * (fopen 'x'): Existiert der Name schon - auch als Symlink -, scheitert das
 * Anlegen, statt einer fremden Datei zu folgen.
 *
 * Alle Dateisystemaufrufe mit @ und Rückgabeprüfung: Der Aufrufer bekommt
 * false, keine Warning (phpunit.xml setzt failOnWarning, und im Betrieb
 * gehört der Pfad nicht auf die Fehlerseite).
 */
final class AtomicFile {

    private function __construct() {}

    /**
     * @param int $mode Dateirechte der neuen Datei (Standard 0600: nur der
     *        Eigentümer - die Datei trägt Geheimnisse). Ein fehlgeschlagenes
     *        chmod ist kein Abbruchgrund, wird aber protokolliert.
     * @return bool true, wenn $path jetzt genau $content enthält
     */
    public static function write(string $path, string $content, int $mode = 0600): bool {
        $tmp = dirname($path) . '/.' . basename($path) . '.' . bin2hex(random_bytes(8)) . '.tmp';

        $fh = @fopen($tmp, 'xb');
        if ($fh === false) {
            return false;
        }

        // VOR dem Schreiben: fopen legt mit 0666 & ~umask an - das Geheimnis
        // stünde sonst kurz für alle lesbar im Verzeichnis.
        if (!@chmod($tmp, $mode)) {
            error_log('AtomicFile: Konnte die Dateirechte von ' . basename($path) . ' nicht auf ' . decoct($mode) . ' setzen - bitte manuell prüfen.');
        }

        $ok = true;
        $laenge = strlen($content);
        $geschrieben = 0;
        while ($geschrieben < $laenge) {
            $n = @fwrite($fh, substr($content, $geschrieben));
            if ($n === false || $n === 0) {
                $ok = false;
                break;
            }
            $geschrieben += $n;
        }
        if ($ok) {
            $ok = @fflush($fh) && @fsync($fh);
        }
        $ok = @fclose($fh) && $ok;

        if (!$ok || !@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }

        // Die Datei wird per `require` gelesen (config/config.php,
        // SetupController::readDbConfig()). Mit validate_timestamps und
        // revalidate_freq sähe ein Folge-Request sonst noch den alten Stand.
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }

        return true;
    }
}
