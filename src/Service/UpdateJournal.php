<?php
// src/Service/UpdateJournal.php

namespace App\Service;

/**
 * Persistentes Write-ahead-Journal für Update und Reparatur (Audit M45).
 *
 * Bis dahin stand der Rückweg nur im Speicher des laufenden Prozesses, die
 * Sicherungskopien im System-Temp. Ein Zeit- oder Speicherlimit, ein
 * Kompilierfehler oder ein vom Webserver beendeter Worker übersprang das
 * catch - zurück blieb ein Mischstand aus zwei Versionen, und weil
 * config/config.php früh kopiert wurde, hielt sich die Installation danach
 * für aktuell.
 *
 * Jetzt wird jede Dateioperation VOR ihrer Ausführung hier vermerkt:
 *
 *     <wurzel>/var/update-journal/          0700
 *         journal.jsonl                     eine JSON-Zeile je Eintrag
 *         sicherungen/                      0700, die überschriebenen Dateien
 *         rueckweg.php                      Kopie von update-rueckweg.php
 *
 * Zurückgerollt wird von der klassenfreien update-rueckweg.php (siehe dort,
 * warum klassenfrei). Diese Klasse legt das Journal an, schreibt es fort und
 * schließt es; den Rückweg ruft sie nur auf.
 *
 * Pfade stehen RELATIV zur Wurzel im Journal, Sicherungen nur mit ihrem
 * Dateinamen. Der Rückweg verwirft alles, was aus der Wurzel bzw. aus
 * sicherungen/ herausführt.
 */
final class UpdateJournal {

    public const VERZEICHNIS = 'var/update-journal';
    public const JOURNAL = 'journal.jsonl';
    public const RUECKWEG = 'rueckweg.php';
    public const HINWEIS = 'var/update-abbruch.json';

    private string $wurzel;

    /** @var resource */
    private $griff;

    private bool $geschlossen = false;

    /** @param resource $griff */
    private function __construct(string $wurzel, $griff) {
        $this->wurzel = $wurzel;
        $this->griff = $griff;
    }

    public static function verzeichnis(string $wurzel): string {
        return rtrim($wurzel, '/') . '/' . self::VERZEICHNIS;
    }

    public static function journalDatei(string $wurzel): string {
        return self::verzeichnis($wurzel) . '/' . self::JOURNAL;
    }

    /** Die Vorlage des Rückweg-Skripts im Codebaum. */
    public static function rueckwegVorlage(): string {
        return __DIR__ . '/update-rueckweg.php';
    }

    /**
     * Legt ein neues Journal an. Nur unter der UpdateSperre - und erst, wenn
     * ein verwaistes Journal zurückgerollt ist (verwaistesZurueckrollen()).
     *
     * @param array<string, mixed> $kopf zweck, von, nach, entpackt, download
     */
    public static function oeffnen(string $wurzel, array $kopf): self {
        $wurzel = rtrim($wurzel, '/');
        if (!UpdateSperre::haelt($wurzel)) {
            throw new \LogicException('UpdateJournal::oeffnen() nur unter der Update-Sperre.');
        }
        $verzeichnis = self::verzeichnis($wurzel);
        if (is_file($verzeichnis . '/' . self::JOURNAL)) {
            throw new \RuntimeException(
                'Ein unterbrochenes Update ist noch nicht zurückgerollt (' . self::VERZEICHNIS . '/' . self::JOURNAL . ').'
            );
        }
        // Reste ohne journal.jsonl (Abbruch beim Aufräumen) sind wertlos.
        self::entferneBaum($verzeichnis);

        if (!@mkdir($verzeichnis, 0700, true) && !is_dir($verzeichnis)) {
            throw new \RuntimeException('Update-Journal: ' . self::VERZEICHNIS . ' kann nicht angelegt werden - ist var/ beschreibbar?');
        }
        @chmod($verzeichnis, 0700);

        // ERSTE Aktion: das Rückweg-Skript aus dem noch konsistenten
        // Altstand ablegen. Danach darf der Codebaum halb kopiert sein.
        $ziel = $verzeichnis . '/' . self::RUECKWEG;
        $temp = $ziel . '.tmp';
        if (!@copy(self::rueckwegVorlage(), $temp) || !@rename($temp, $ziel)) {
            @unlink($temp);
            throw new \RuntimeException('Update-Journal: Das Rückweg-Skript kann nicht abgelegt werden.');
        }

        $sicherungen = $verzeichnis . '/sicherungen';
        if (!@mkdir($sicherungen, 0700) && !is_dir($sicherungen)) {
            throw new \RuntimeException('Update-Journal: Sicherungsverzeichnis kann nicht angelegt werden.');
        }
        @chmod($sicherungen, 0700);

        $griff = @fopen($verzeichnis . '/' . self::JOURNAL, 'x');
        if ($griff === false) {
            throw new \RuntimeException('Update-Journal: ' . self::JOURNAL . ' kann nicht angelegt werden.');
        }

        $journal = new self($wurzel, $griff);
        $journal->schreibe([
            'art' => 'kopf',
            'format' => 1,
            'zweck' => (string)($kopf['zweck'] ?? 'Update'),
            'von' => (string)($kopf['von'] ?? ''),
            'nach' => (string)($kopf['nach'] ?? ''),
            'pid' => getmypid(),
            'seit' => date('c'),
            'entpackt' => isset($kopf['entpackt']) ? (string)$kopf['entpackt'] : null,
            'download' => isset($kopf['download']) ? (string)$kopf['download'] : null,
        ]);
        return $journal;
    }

    public function wurzel(): string {
        return $this->wurzel;
    }

    public function sicherungsVerzeichnis(): string {
        return self::verzeichnis($this->wurzel) . '/sicherungen';
    }

    /**
     * Vermerkt eine Dateioperation, BEVOR sie ausgeführt wird. Wirft, wenn
     * das nicht gelingt - dann unterbleibt die Operation.
     *
     * @param string $art restore|deleted (mit $sicherung) oder created|created_dirs|rmdir
     */
    public function anhaengen(string $art, string $pfad, ?string $sicherung = null): void {
        $rel = $this->relativ($pfad);
        $eintrag = in_array($art, ['restore', 'deleted'], true)
            ? ['art' => $art, 'sicherung' => $sicherung === null ? null : basename($sicherung), 'original' => $rel]
            : ['art' => $art, 'pfad' => $rel];
        $this->schreibe($eintrag);
    }

    /**
     * Schließt das Journal: zuerst journal.jsonl (danach gilt der Lauf als
     * abgeschlossen), dann Sicherungen und Skript.
     */
    public function abschliessen(): void {
        if ($this->geschlossen) {
            return;
        }
        $this->geschlossen = true;
        if (is_resource($this->griff)) {
            @fclose($this->griff);
        }
        $verzeichnis = self::verzeichnis($this->wurzel);
        @unlink($verzeichnis . '/' . self::JOURNAL);
        self::entferneBaum($verzeichnis);
    }

    public function istGeschlossen(): bool {
        return $this->geschlossen;
    }

    /** Liegt ein (offenes oder verwaistes) Journal? */
    public static function offen(string $wurzel): bool {
        return is_file(self::journalDatei($wurzel));
    }

    /**
     * Liest ein Journal, validiert und ohne Kopf - für Diagnose und Tests.
     * Abgeschnittene letzte Zeilen und Pfade außerhalb der Wurzel bzw. der
     * Sicherungen werden verworfen (dieselben Regeln wie im Rückweg).
     *
     * @return array{kopf: array<string, mixed>, eintraege: array<int, array<string, mixed>>, verworfen: int}|null
     */
    public static function lesen(string $wurzel): ?array {
        $datei = self::journalDatei($wurzel);
        if (!is_file($datei)) {
            return null;
        }
        $kopf = [];
        $eintraege = [];
        $verworfen = 0;
        foreach (@file($datei, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $zeile) {
            $e = json_decode($zeile, true);
            if (!is_array($e) || !is_string($e['art'] ?? null)) {
                $verworfen++;
                continue;
            }
            if ($e['art'] === 'kopf') {
                $kopf = $e;
                continue;
            }
            $pfad = $e['original'] ?? $e['pfad'] ?? null;
            if (!is_string($pfad) || !self::istSichererPfad($pfad)
                || (isset($e['sicherung']) && (!is_string($e['sicherung']) || str_contains($e['sicherung'], '/')))) {
                $verworfen++;
                continue;
            }
            $eintraege[] = $e;
        }
        return ['kopf' => $kopf, 'eintraege' => $eintraege, 'verworfen' => $verworfen];
    }

    /**
     * Rollt ein verwaistes Journal zurück - aber nur, wenn die Update-Sperre
     * frei ist, und dann UNTER ihr. Innerhalb eines laufenden Updates ist der
     * Erwerb reentrant.
     *
     * @return string 'keins' | 'zurueckgerollt' | 'belegt' | 'unvollstaendig'
     */
    public static function verwaistesZurueckrollen(string $wurzel, string $ursache = ''): string {
        if (!self::offen($wurzel)) {
            return 'keins';
        }
        if (!UpdateSperre::versuchen($wurzel, 'Rückweg eines abgebrochenen Laufs')) {
            return 'belegt';
        }
        try {
            return self::rueckweg()($wurzel, true, $ursache);
        } finally {
            UpdateSperre::freigeben($wurzel);
        }
    }

    /**
     * Der Rückweg aus der Vorlage im Codebaum - für Aufrufer, die die
     * Sperre bereits halten (Shutdown-Handler).
     */
    public static function rueckweg(): \Closure {
        /** @var \Closure $fn */
        $fn = require self::rueckwegVorlage();
        return $fn;
    }

    // ---- Hinweis auf einen abgebrochenen Lauf ---------------------------

    public static function hinweisDatei(string $wurzel): string {
        return rtrim($wurzel, '/') . '/' . self::HINWEIS;
    }

    /**
     * Der zuletzt zurückgerollte Abbruch, oder null.
     *
     * @return array<string, mixed>|null
     */
    public static function letzterAbbruch(string $wurzel): ?array {
        $datei = self::hinweisDatei($wurzel);
        if (!is_file($datei)) {
            return null;
        }
        $daten = json_decode((string)@file_get_contents($datei), true);
        return is_array($daten) ? $daten : null;
    }

    /** @param array<string, mixed> $felder */
    public static function hinweisAktualisieren(string $wurzel, array $felder): void {
        $daten = self::letzterAbbruch($wurzel);
        if ($daten === null) {
            return;
        }
        $datei = self::hinweisDatei($wurzel);
        $temp = $datei . '.' . getmypid() . '.tmp';
        $json = (string)json_encode(array_merge($daten, $felder), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (@file_put_contents($temp, $json) === false || !@rename($temp, $datei)) {
            @unlink($temp);
        }
    }

    public static function abbruchHinweisLoeschen(string $wurzel): void {
        @unlink(self::hinweisDatei($wurzel));
    }

    // ---- intern ------------------------------------------------------------

    /** @param array<string, mixed> $eintrag */
    private function schreibe(array $eintrag): void {
        if ($this->geschlossen) {
            throw new \LogicException('Update-Journal ist bereits geschlossen.');
        }
        $zeile = json_encode($eintrag, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($zeile === false) {
            throw new \RuntimeException('Update-Journal: Eintrag nicht kodierbar.');
        }
        $zeile .= "\n";
        // fflush je Zeile: Nach einem Prozess-Tod steht sie im Page-Cache
        // und damit in der Datei. Gegen Stromausfall hilft das nicht (kein
        // fsync je Zeile) - bewusst, das wäre bei Tausenden Dateien teuer.
        if (@fwrite($this->griff, $zeile) !== strlen($zeile) || !@fflush($this->griff)) {
            throw new \RuntimeException('Update-Journal: Schreiben fehlgeschlagen (Platte voll?) - die Dateioperation unterbleibt.');
        }
    }

    private function relativ(string $pfad): string {
        $praefix = $this->wurzel . '/';
        if (!str_starts_with($pfad, $praefix)) {
            throw new \RuntimeException("Update-Journal: {$pfad} liegt außerhalb der Installation.");
        }
        $rel = substr($pfad, strlen($praefix));
        if (!self::istSichererPfad($rel)) {
            throw new \RuntimeException("Update-Journal: unzulässiger Pfad {$rel}.");
        }
        return $rel;
    }

    private static function istSichererPfad(string $rel): bool {
        if ($rel === '' || $rel[0] === '/' || str_contains($rel, "\0") || str_contains($rel, '\\')) {
            return false;
        }
        foreach (explode('/', $rel) as $teil) {
            if ($teil === '' || $teil === '.' || $teil === '..') {
                return false;
            }
        }
        return $rel !== 'var/update.lock' && !str_starts_with($rel, self::VERZEICHNIS);
    }

    private static function entferneBaum(string $pfad): void {
        if (is_link($pfad) || is_file($pfad)) {
            @unlink($pfad);
            return;
        }
        if (!is_dir($pfad)) {
            return;
        }
        foreach (array_diff(@scandir($pfad) ?: [], ['.', '..']) as $eintrag) {
            self::entferneBaum($pfad . '/' . $eintrag);
        }
        @rmdir($pfad);
    }
}
