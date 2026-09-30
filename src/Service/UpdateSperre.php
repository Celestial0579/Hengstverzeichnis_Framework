<?php
// src/Service/UpdateSperre.php

namespace App\Service;

/**
 * Exklusive Sperre für alles, was den Codebaum austauscht (Audit M44):
 * Kern-Update, Reparatur aus der Integritätsprüfung und manuelles
 * Addon-Update. Datei `<wurzel>/var/update.lock`, gesperrt per flock().
 *
 * WARUM FLOCK UND NICHT GET_LOCK (App\Service\DbLock). Die Sperre soll ohne
 * Datenbank auskommen: Sie ist zugleich der Lebensbeweis des Rückweg-Journals
 * (App\Service\UpdateJournal), und der Rückweg läuft im frühen Haken von
 * public/index.php, bevor irgendetwas anderes geladen ist - womöglich über
 * einem halb kopierten vendor/. Den flock gibt der Kernel beim Prozessende
 * frei, auch nach einem Fatal Error oder SIGKILL, und unter PHP-FPM zusätzlich
 * mit dem Schließen des Handles am Request-Ende.
 *
 * REENTRANT. performUpdate() nimmt die Sperre, applyUpdateArchive() und die
 * Addon-Phase nehmen sie darunter erneut. Ein Tiefenzähler je Sperrdatei
 * verhindert, dass sich der Prozess selbst aussperrt: Unter Linux sind zwei
 * fopen() derselben Datei im selben Prozess zwei Konkurrenten. Der Schlüssel
 * ist deshalb der NORMALISIERTE Pfad (realpath des var-Verzeichnisses) - mit
 * 'x/' und 'x' stünden sonst zwei Handles gegeneinander.
 *
 * Ohne flock-Unterstützung (einzelne Netzwerk-Dateisysteme) läuft alles wie
 * vor der Sperre weiter, mit Warnung im Fehlerprotokoll und im Audit-Log.
 * Die Scheduler-Sperre (fw-scheduler) hält den Cron-Weg dann trotzdem
 * einzeln.
 */
final class UpdateSperre {

    /** @var array<string, array{griff: resource|null, tiefe: int}> */
    private static array $gehalten = [];

    private function __construct() {}

    /** Pfad der Sperrdatei unter der gegebenen Installationswurzel. */
    public static function datei(string $wurzel): string {
        return rtrim($wurzel, '/') . '/var/update.lock';
    }

    /**
     * Nimmt die Sperre oder wirft UpdateLaeuftBereits.
     *
     * @throws UpdateLaeuftBereits wenn ein anderer Prozess sie hält
     */
    public static function erwerben(string $wurzel, string $zweck): void {
        if (!self::nehmen($wurzel, $zweck, $belegtVon)) {
            throw new UpdateLaeuftBereits(
                'Es läuft bereits ein Update oder eine Reparatur'
                . ($belegtVon !== '' ? " ({$belegtVon})" : '')
                . '. Bitte später erneut versuchen.'
            );
        }
    }

    /**
     * Wie erwerben(), aber ohne Ausnahme - für den Rückweg, der bei belegter
     * Sperre einfach nichts tut.
     */
    public static function versuchen(string $wurzel, string $zweck): bool {
        try {
            return self::nehmen($wurzel, $zweck, $belegtVon);
        } catch (\Throwable $e) {
            error_log('UpdateSperre: ' . $e->getMessage());
            return false;
        }
    }

    /** Gibt eine Stufe frei; die letzte löst den flock. */
    public static function freigeben(string $wurzel): void {
        $schluessel = self::schluessel($wurzel);
        if ($schluessel === null || !isset(self::$gehalten[$schluessel])) {
            return;
        }
        if (--self::$gehalten[$schluessel]['tiefe'] > 0) {
            return;
        }
        $griff = self::$gehalten[$schluessel]['griff'];
        unset(self::$gehalten[$schluessel]);
        if (is_resource($griff)) {
            @ftruncate($griff, 0);
            @flock($griff, LOCK_UN);
            @fclose($griff);
        }
    }

    /** Hält DIESER Prozess die Sperre? */
    public static function haelt(string $wurzel): bool {
        $schluessel = self::schluessel($wurzel);
        return $schluessel !== null && isset(self::$gehalten[$schluessel]);
    }

    /**
     * Ist die Sperre frei? Nur für Diagnose und Tests - wer danach handeln
     * will, muss sie ERWERBEN (sonst Wettlauf zwischen Prüfen und Handeln).
     *
     * @return bool|null null = nicht feststellbar
     */
    public static function istFrei(string $wurzel): ?bool {
        if (self::haelt($wurzel)) {
            return false;
        }
        $datei = self::datei($wurzel);
        if (!is_file($datei)) {
            return true;
        }
        $griff = @fopen($datei, 'r');
        if ($griff === false) {
            return null;
        }
        try {
            if (@flock($griff, LOCK_EX | LOCK_NB, $wuerdeBlockieren)) {
                @flock($griff, LOCK_UN);
                return true;
            }
            return $wuerdeBlockieren ? false : null;
        } finally {
            @fclose($griff);
        }
    }

    /**
     * @param-out string $belegtVon
     */
    private static function nehmen(string $wurzel, string $zweck, ?string &$belegtVon): bool {
        $belegtVon = '';
        $datei = self::datei($wurzel);
        $verzeichnis = dirname($datei);
        // Testziele (UpdateService::overrideBaseDirForTests()) haben kein
        // var/; im Echtbetrieb ist es über den Wartungsmodus ohnehin Pflicht.
        if (!is_dir($verzeichnis) && !@mkdir($verzeichnis, 0775, true) && !is_dir($verzeichnis)) {
            throw new \RuntimeException("Update-Sperre: Verzeichnis {$verzeichnis} kann nicht angelegt werden.");
        }

        $schluessel = self::schluessel($wurzel);
        if ($schluessel !== null && isset(self::$gehalten[$schluessel])) {
            self::$gehalten[$schluessel]['tiefe']++;
            return true;
        }
        $schluessel ??= $datei;

        $griff = @fopen($datei, 'c+');
        if ($griff === false) {
            throw new \RuntimeException("Update-Sperre: {$datei} kann nicht geöffnet werden - ist var/ beschreibbar?");
        }

        if (!@flock($griff, LOCK_EX | LOCK_NB, $wuerdeBlockieren)) {
            if ($wuerdeBlockieren) {
                $belegtVon = self::beschreibe((string)@stream_get_contents($griff, 4096, 0));
                @fclose($griff);
                return false;
            }
            // Dateisystem ohne flock: weiter wie vor der Sperre, aber laut.
            @fclose($griff);
            $meldung = "Update-Sperre: flock() wird für {$datei} nicht unterstützt - {$zweck} läuft ohne Schutz vor einem parallelen Lauf.";
            error_log($meldung);
            try {
                AuditLogger::log('Update-Sperre nicht verfügbar', 'update', $meldung, null, 'SYSTEM');
            } catch (\Throwable) {
                // Audit ohne Datenbank - error_log genügt.
            }
            self::$gehalten[$schluessel] = ['griff' => null, 'tiefe' => 1];
            return true;
        }

        // Rein informativ - für den, der die Datei ansieht, und für die
        // Meldung an einen zweiten Lauf.
        @ftruncate($griff, 0);
        @rewind($griff);
        @fwrite($griff, (string)json_encode([
            'zweck' => $zweck,
            'seit' => date('c'),
            'pid' => getmypid(),
        ], JSON_UNESCAPED_UNICODE));
        @fflush($griff);

        self::$gehalten[$schluessel] = ['griff' => $griff, 'tiefe' => 1];
        return true;
    }

    private static function beschreibe(string $inhalt): string {
        $daten = json_decode($inhalt, true);
        if (!is_array($daten)) {
            return '';
        }
        $zweck = is_string($daten['zweck'] ?? null) ? $daten['zweck'] : '';
        $seit = is_string($daten['seit'] ?? null) ? $daten['seit'] : '';
        return trim($zweck . ($seit !== '' ? " seit {$seit}" : ''));
    }

    /**
     * Normalisierter Schlüssel: realpath des var-Verzeichnisses. null, wenn
     * es (noch) nicht existiert.
     */
    private static function schluessel(string $wurzel): ?string {
        $verzeichnis = realpath(dirname(self::datei($wurzel)));
        return $verzeichnis === false ? null : $verzeichnis . '/update.lock';
    }

    /** Nur für Tests: alle in diesem Prozess gehaltenen Sperren lösen. */
    public static function resetForTests(): void {
        foreach (self::$gehalten as $eintrag) {
            if (is_resource($eintrag['griff'])) {
                @flock($eintrag['griff'], LOCK_UN);
                @fclose($eintrag['griff']);
            }
        }
        self::$gehalten = [];
    }
}
