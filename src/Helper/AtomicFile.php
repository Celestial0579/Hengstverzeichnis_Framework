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
        $tmp = self::tempPfad($path);

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
        if (str_ends_with($path, '.php') && function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
        clearstatcache(true, $path);

        return true;
    }

    /**
     * Wie write(), aber den Inhalt schreibt ein Dritter selbst in eine Datei
     * - etwa imagejpeg(), das einen Pfad erwartet (Vorschaubilder, Audit N80).
     *
     * Ablauf: Temp-Datei `.<name>.<hex>.tmp` im Zielverzeichnis exklusiv
     * anlegen, $schreiber($tmp) aufrufen, dann prüfen - Rückgabe true,
     * Größe > 0 und, falls übergeben, $pruefer($tmp). Erst dann chmod und
     * rename(). Bei jedem Fehlschlag verschwindet die Temp-Datei, das Ziel
     * bleibt wie es war.
     *
     * Die Temp-Datei liegt IMMER im Zielverzeichnis: rename() über
     * Dateisystemgrenzen (etwa zwischen Docker-Volumes) wäre Kopieren plus
     * Löschen und damit nicht atomar - bzw. scheitert ganz.
     *
     * Der Prüfer ist bewusst optional: Vorschaubilder müssen vollständig sein
     * (Thumbnails::vollstaendig(), JPEG-Dateiende), ein Original-JPEG ohne
     * Dateiende-Marker ist dagegen erlaubt.
     *
     * @param callable(string): bool $schreiber
     * @param (callable(string): bool)|null $pruefer
     */
    public static function ersetzen(string $path, callable $schreiber, ?callable $pruefer = null, int $mode = 0644): bool {
        $tmp = self::tempPfad($path);

        $fh = @fopen($tmp, 'xb');
        if ($fh === false) {
            return false;
        }
        @fclose($fh);

        try {
            $ok = $schreiber($tmp) === true;
        } catch (\Throwable $e) {
            $ok = false;
        }
        clearstatcache(true, $tmp);
        $ok = $ok && is_file($tmp) && !is_link($tmp) && (int)@filesize($tmp) > 0;
        if ($ok && $pruefer !== null) {
            $ok = $pruefer($tmp) === true;
        }
        if ($ok) {
            $fh = @fopen($tmp, 'rb');
            if ($fh !== false) {
                @fsync($fh);
                @fclose($fh);
            }
            @chmod($tmp, $mode);
        }

        if (!$ok || !@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        clearstatcache(true, $path);

        return true;
    }

    /**
     * Entfernt liegengebliebene Temp-Dateien zu einem Ziel (Prozessabbruch
     * zwischen Anlegen und rename()).
     */
    public static function resteEntfernen(string $path): void {
        // Bewusst kein glob(): Der Dateiname kann Musterzeichen tragen.
        $verzeichnis = dirname($path);
        $praefix = '.' . basename($path) . '.';
        foreach (@scandir($verzeichnis) ?: [] as $eintrag) {
            if (!str_starts_with($eintrag, $praefix) || !str_ends_with($eintrag, '.tmp')
                || preg_match('/^[0-9a-f]{16}$/', substr($eintrag, strlen($praefix), -4)) !== 1) {
                continue;
            }
            $rest = $verzeichnis . '/' . $eintrag;
            if (is_file($rest) && !is_link($rest)) {
                @unlink($rest);
            }
        }
    }

    private static function tempPfad(string $path): string {
        return dirname($path) . '/.' . basename($path) . '.' . bin2hex(random_bytes(8)) . '.tmp';
    }
}
