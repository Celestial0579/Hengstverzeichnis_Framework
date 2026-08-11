<?php
// src/Service/Maintenance.php

namespace App\Service;

/**
 * Wartungsmodus (#232): Marker-Datei var/wartung.lock + früher Check im
 * Bootstrap (public/index.php, siehe guard()).
 *
 * Anlass ist das Addon `datenmigration`, das beim Import die komplette
 * Datenbank ersetzt (DROP/CREATE/INSERT je Tabelle): Während der wenigen
 * Sekunden des Einspielens dürfen parallele Requests nicht auf halb
 * aufgebaute Tabellen treffen. Deshalb ist der Mechanismus bewusst eine
 * reine Datei-Prüfung OHNE jede Datenbank-Abhängigkeit - der Check muss
 * gerade dann funktionieren, wenn die Datenbank nicht benutzbar ist.
 *
 * Typische Verwendung in einem Werkzeug/Addon:
 *
 *     Maintenance::enable('Datenbank-Restore aus Backup xyz');
 *     try { ... riskante Arbeit ... } finally { Maintenance::disable(); }
 *
 * Der Request, der enable() aufruft, läuft selbst normal weiter (der Guard
 * wurde für ihn ja bereits zu Request-Beginn passiert) - gesperrt werden
 * nur alle NACH dem Setzen des Markers eintreffenden Requests.
 */
final class Maintenance {

    /**
     * Wert für den Retry-After-Header der 503-Antwort in Sekunden. Import/
     * Restore dauern typischerweise Sekunden, nicht Minuten - 30 Sekunden
     * sind für wohlerzogene Clients (Crawler, Monitoring) ein sinnvoller
     * Wiedervorlage-Abstand, ohne dass Besucher unnötig lange fernbleiben.
     */
    private const RETRY_AFTER_SECONDS = 30;

    private function __construct() {}

    /**
     * Absoluter Pfad der Marker-Datei. var/ liegt bewusst außerhalb von
     * public/ (nicht über HTTP erreichbar) und ist per .gitignore vom Repo
     * ausgenommen - der Marker ist Laufzeitzustand, kein Repo-Inhalt.
     */
    public static function lockFile(): string {
        return dirname(__DIR__, 2) . '/var/wartung.lock';
    }

    /**
     * Aktiviert den Wartungsmodus. Der Grund wird zusammen mit dem Zeitpunkt
     * in der Marker-Datei abgelegt - für Betreiber, die bei einem hängen
     * gebliebenen Marker (z. B. nach einem abgestürzten Import) nachvollziehen
     * müssen, WER die Sperre WARUM gesetzt hat. Auf der öffentlichen
     * Hinweisseite erscheint der Grund bewusst NICHT (könnte Interna wie
     * Backup-Namen oder Werkzeug-Details preisgeben).
     */
    public static function enable(string $grund): void {
        $lockFile = self::lockFile();
        $dir = dirname($lockFile);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Wartungsmodus: Verzeichnis {$dir} kann nicht angelegt werden.");
        }

        $payload = json_encode([
            'grund' => $grund,
            'seit' => date('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        // LOCK_EX gegen zwei gleichzeitig schreibende Prozesse - der Inhalt
        // ist rein informativ, aber eine halb geschriebene Datei soll info()
        // trotzdem nie zu sehen bekommen.
        if (@file_put_contents($lockFile, $payload, LOCK_EX) === false) {
            throw new \RuntimeException("Wartungsmodus: Marker-Datei {$lockFile} kann nicht geschrieben werden.");
        }
    }

    /**
     * Beendet den Wartungsmodus. Ein fehlender Marker ist kein Fehler -
     * disable() steht typischerweise in einem finally-Block und muss auch
     * nach einem Fehlschlag VOR enable() gefahrlos aufrufbar sein.
     */
    public static function disable(): void {
        $lockFile = self::lockFile();
        if (is_file($lockFile)) {
            @unlink($lockFile);
        }
    }

    public static function isActive(): bool {
        return is_file(self::lockFile());
    }

    /**
     * Inhalt der Marker-Datei (grund, seit) für Diagnose-Zwecke - oder null,
     * wenn kein Wartungsmodus aktiv oder die Datei nicht lesbar/korrupt ist.
     * Ein von Hand angelegter, leerer Marker (`touch var/wartung.lock`)
     * aktiviert den Wartungsmodus genauso - isActive() prüft nur die
     * Existenz, nie den Inhalt.
     *
     * @return array{grund: string, seit: string}|null
     */
    public static function info(): ?array {
        if (!self::isActive()) {
            return null;
        }
        $raw = @file_get_contents(self::lockFile());
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['grund'], $data['seit'])) {
            return null;
        }
        return ['grund' => (string)$data['grund'], 'seit' => (string)$data['seit']];
    }

    /**
     * Früher Bootstrap-Check (public/index.php): Bei aktivem Wartungsmodus
     * wird der Request mit HTTP 503 + Retry-After und einer schlichten
     * Hinweisseite beendet - VOR Plugin-Boot, Router und jedem
     * Datenbank-Zugriff, damit die Sperre auch bei halb eingespielter
     * Datenbank greift (das ist ihr eigentlicher Zweck, siehe Klassendoku).
     *
     * Admin-Sessions sind bewusst NICHT ausgenommen (das Issue #232 nennt
     * die Ausnahme ausdrücklich als optional): Ohne Datenbank ließe sich die
     * Admin-Eigenschaft nur der unbestätigten Session-Behauptung entnehmen -
     * vor allem aber schützt die Sperre Admins genauso wie Besucher, denn
     * gerade ein Admin-Klick (Speichern, Löschen) zwischen DROP und INSERT
     * ist das Schadensszenario, gegen das der Wartungsmodus existiert. Bei
     * einem Zeitfenster von Sekunden ist die Aussperrung verschmerzbar; ein
     * hängen gebliebener Marker wird per Datei gelöst (rm var/wartung.lock
     * bzw. Maintenance::disable()), nicht per Sonderzugang.
     *
     * Unter CLI (Cron-Skripte, Werkzeuge) ist der Guard ein No-Op - genau
     * die Werkzeuge, die den Wartungsmodus setzen, müssen ja weiterarbeiten
     * können.
     */
    public static function guard(): void {
        if (PHP_SAPI === 'cli') {
            return;
        }
        if (!self::isActive()) {
            return;
        }

        // Locale ohne Datenbank bestimmen: Die reguläre Auflösung
        // (Translator::resolveRequestLocale()) braucht die Settings aus der
        // Datenbank - hier steht nur die bereits in config.php gestartete
        // Session zur Verfügung. Eine früher gewählte Sprache bleibt so
        // erhalten, alle anderen sehen die Fallback-Sprache.
        \App\I18n\Translator::init((string)($_SESSION['locale'] ?? 'de'));

        http_response_code(503);
        header('Retry-After: ' . self::RETRY_AFTER_SECONDS);
        // Fehlerseiten sollen nicht im Cache landen und nach der Wartung
        // weiter ausgeliefert werden.
        header('Cache-Control: no-store');

        require dirname(__DIR__) . '/Views/error_503.php';
        exit;
    }
}
