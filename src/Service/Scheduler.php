<?php
// src/Service/Scheduler.php

namespace App\Service;

use App\Database;
use App\Helper\BoundedKey;
use App\Helper\LongRunning;

/**
 * Class Scheduler
 *
 * Grundlegende Cron-/Scheduler-Infrastruktur (#67): eine einfache Registry
 * für periodisch auszuführende Aufgaben. Voraussetzung für spätere Kern-
 * Features wie automatisierte externe Backups (#59) und einen E-Mail-Digest
 * für Admins/Editoren (#52) - beide registrieren künftig ihre eigene Aufgabe
 * hier, statt eine eigene Scheduling-Logik mitzubringen.
 *
 * Es gibt bewusst KEINEN dauerhaft laufenden PHP-Prozess (klassisches
 * Request-Modell, siehe docs/architecture.md) - "Cron" bedeutet hier
 * ausschließlich: ein von außen angestoßener HTTP-Request (System-Cron gegen
 * App\Controllers\CronController::run(), oder ein manueller Admin-Klick
 * unter /admin/cron) prüft beim Eintreffen, welche registrierten Aufgaben
 * fällig sind, und führt diese synchron innerhalb dieses einen Requests aus.
 *
 * ABLAUF JE AUFGABE (Audit M38, M39):
 *
 * 1. Vorprüfung ohne Sperre: fällig?
 * 2. Sperre per GET_LOCK (App\Service\DbLock). Hält ein anderer Cron-Aufruf
 *    sie, wird die Aufgabe übersprungen (`skipped`) - ein Backup, das länger
 *    als eine Minute läuft, startet so nicht bei jedem weiteren Aufruf
 *    parallel neu. Ist GET_LOCK auf der Plattform nicht nutzbar, läuft die
 *    Aufgabe nur mit dem Claim aus Schritt 4.
 * 3. Unter der Sperre erneut prüfen: Ein paralleler Aufruf kann sie gerade
 *    beendet haben.
 * 4. Claim: Der Zeitstempel wird VOR dem Callback gesetzt. `lastRunAt` ist
 *    damit der Startzeitpunkt, das Intervall zählt von Start zu Start.
 * 5. Callback. Bei einem Throwable und `retryOnFailure` wird der alte
 *    Zeitstempel zurückgeschrieben.
 *
 * FEHLERISOLATION. Jedes Throwable einer Aufgabe wird gefangen, im Audit-Log
 * protokolliert und blockiert die übrigen Aufgaben desselben Laufs nicht.
 * Ein Fatal Error (Speicher erschöpft) beendet dagegen den ganzen Request.
 * Weil der Claim schon gesetzt ist, ist die Aufgabe beim nächsten Aufruf
 * nicht mehr fällig, und die übrigen laufen - früher begann jeder Aufruf
 * wieder mit der abstürzenden Aufgabe, und alle dahinter liefen nie mehr.
 * Ein Shutdown-Handler hält den Abbruch im Aufgabenstatus und im Audit-Log
 * fest. Ein harter Abbruch zählt bewusst NICHT als Fehlschlag im Sinne von
 * `retryOnFailure`: Sonst stünde eine deterministisch abstürzende Aufgabe
 * sofort wieder vorn.
 *
 * Aufgabenstatus: `cron_status__<name>` in `settings`, JSON mit `status`
 * (running|ok|error|aborted), `startedAt`, `finishedAt` und `error`.
 */
final class Scheduler {

    /** Obergrenze für Intervalle in Stunden (Audit N67): ein Jahr. */
    public const MAX_INTERVAL_HOURS = 8760;

    /**
     * Ab so vielen Sekunden gilt ein Lauf mit Status `running`, dessen Sperre
     * frei ist, als ohne Rückmeldung abgebrochen. Die Karenz verhindert, dass
     * ein gerade startender Lauf kurz als "abgebrochen" erscheint.
     */
    private const ABORTED_GRACE_SECONDS = 60;

    /** Maximale Länge des gespeicherten Fehlertexts. */
    private const STATUS_ERROR_LENGTH = 500;

    /** Speicherreserve für den Shutdown-Handler nach Speichererschöpfung. */
    private const SHUTDOWN_RESERVE_BYTES = 262144;

    /** @var array<string, array{intervalSeconds:int, callback:callable, retryOnFailure:bool}> */
    private static array $tasks = [];

    /** Name der Aufgabe, deren Callback gerade läuft. */
    private static ?string $currentTask = null;

    private static ?int $currentStartedAt = null;

    private static bool $shutdownRegistered = false;

    private static ?string $reserve = null;

    private static bool $lockUnavailableReported = false;

    private function __construct() {}

    /**
     * Registriert eine periodisch auszuführende Aufgabe.
     *
     * @param string $name Eindeutiger, stabiler Bezeichner (z. B. "backup.external") -
     *                      dient zugleich als Schlüssel für den Zeitstempel des letzten
     *                      Starts. Beliebig lang; überlange Namen werden im
     *                      Einstellungsschlüssel intern gehasht (Audit N74).
     * @param int $intervalSeconds Mindestabstand zwischen zwei Starts.
     * @param callable $callback Auszuführende Aufgabe, ohne Argumente aufgerufen.
     * @param bool $retryOnFailure Bei true wird der Zeitstempel bei einer fehlgeschlagenen
     *                             Ausführung (Throwable) auf den vorherigen Wert
     *                             zurückgesetzt, sodass die Aufgabe beim nächsten Cron-Lauf
     *                             sofort erneut versucht wird, statt das volle Intervall
     *                             abzuwarten. Standard false. Ein harter Abbruch (Fatal)
     *                             setzt nie zurück.
     */
    public static function register(string $name, int $intervalSeconds, callable $callback, bool $retryOnFailure = false): void {
        self::$tasks[$name] = [
            'intervalSeconds' => $intervalSeconds,
            'callback' => $callback,
            'retryOnFailure' => $retryOnFailure,
        ];
    }

    /**
     * Führt alle fälligen registrierten Aufgaben aus.
     *
     * Ergebnisse: `ok` und `error` für ausgeführte Aufgaben, `skipped` (mit
     * `reason`) für fällige Aufgaben, die gerade ein anderer Cron-Aufruf
     * ausführt. Nicht fällige Aufgaben erscheinen nicht.
     *
     * @return array<int, array{name:string, status:'ok'|'error'|'skipped', error?:string, reason?:string}>
     */
    public static function runDue(): array {
        LongRunning::allow();
        self::armShutdownHandler();

        $results = [];
        foreach (self::$tasks as $name => $task) {
            try {
                $result = self::runTask($name, $task);
            } catch (\Throwable $e) {
                // Erreicht nur Fehler AUSSERHALB des Callbacks (Claim nicht
                // speicherbar, Datenbank weg). Auch sie dürfen die übrigen
                // Aufgaben nicht aufhalten.
                self::auditQuietly(
                    "Cron-Aufgabe fehlgeschlagen: {$name}",
                    $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
                );
                $result = ['name' => $name, 'status' => 'error', 'error' => $e->getMessage()];
            }
            if ($result !== null) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * Führt `$fn` unter derselben Sperre aus, die auch die Cron-Aufgabe
     * `$name` nimmt - z. B. für einen manuellen Testlauf, der nicht parallel
     * zum Cron-Lauf derselben Aufgabe laufen darf. Der Zeitstempel der
     * Aufgabe bleibt unberührt.
     *
     * @return bool false, wenn die Sperre gerade belegt ist ($fn lief nicht);
     *              true, wenn $fn lief. Ausnahmen aus $fn gehen an den Aufrufer.
     */
    public static function runExclusive(string $name, callable $fn): bool {
        $lockKey = self::lockKey($name);
        $lock = DbLock::acquire($lockKey);
        if ($lock === false) {
            return false;
        }
        if ($lock === null) {
            self::reportLockUnavailable();
        }
        try {
            $fn();
        } finally {
            if ($lock === true) {
                DbLock::release($lockKey);
            }
        }
        return true;
    }

    /**
     * Entfernt alles, was der Scheduler zu einer Aufgabe gespeichert hat -
     * für die Deinstallation eines Addons.
     */
    public static function forget(string $name): void {
        $stmt = Database::getInstance()->prepare("DELETE FROM settings WHERE setting_key IN (?, ?)");
        $stmt->execute([self::settingKey($name), self::statusKey($name)]);
    }

    /**
     * Begrenzt ein Intervall in Stunden auf 1 bis MAX_INTERVAL_HOURS (Audit
     * N67). Über float gerechnet: So läuft weder das Produkt mit 3600 über,
     * noch wird ein zu großer float nach int gecastet. Nicht-numerische Werte
     * wie '12abc' ergeben bewusst 1 statt 12.
     */
    public static function clampIntervalHours(mixed $raw): int {
        $value = is_numeric($raw) ? (float)$raw : 0.0;
        if (is_nan($value)) {
            $value = 0.0;
        }
        return (int)min((float)self::MAX_INTERVAL_HOURS, max(1.0, $value));
    }

    /**
     * @return array<int, array{name:string, intervalSeconds:int, lastRunAt:?int,
     *     status:?string, statusAt:?int, error:?string, running:?bool,
     *     state:?string}>
     *
     * `status` ist der gespeicherte Status, `state` die Anzeige: ok, error,
     * aborted, running oder unknown ("läuft oder abgebrochen", wenn sich die
     * Sperre nicht prüfen lässt). `running` sagt, ob gerade eine Verbindung
     * die Sperre hält (null = unbekannt).
     */
    public static function registeredTasks(): array {
        $out = [];
        foreach (self::$tasks as $name => $task) {
            $status = self::readStatus($name);
            $running = DbLock::isHeld(self::lockKey($name));
            $out[] = [
                'name' => $name,
                'intervalSeconds' => $task['intervalSeconds'],
                'lastRunAt' => self::lastRunAt($name),
                'status' => $status['status'] ?? null,
                'statusAt' => $status !== null ? ($status['finishedAt'] ?? $status['startedAt'] ?? null) : null,
                'error' => $status['error'] ?? null,
                'running' => $running,
                'state' => self::displayState($status, $running),
            ];
        }
        return $out;
    }

    public static function lastRunAt(string $name): ?int {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([self::settingKey($name)]);
        $value = $stmt->fetchColumn();
        return $value !== false && $value !== null ? (int)$value : null;
    }

    /**
     * @param array{intervalSeconds:int, callback:callable, retryOnFailure:bool} $task
     * @return array{name:string, status:'ok'|'error'|'skipped', error?:string, reason?:string}|null
     */
    private static function runTask(string $name, array $task): ?array {
        if (!self::isDue(self::lastRunAt($name), $task['intervalSeconds'])) {
            return null;
        }

        $lockKey = self::lockKey($name);
        $lock = DbLock::acquire($lockKey);
        if ($lock === false) {
            return ['name' => $name, 'status' => 'skipped', 'reason' => 'Läuft bereits in einem anderen Cron-Aufruf.'];
        }
        if ($lock === null) {
            // Ohne Sperre liefe auf dieser Plattform sonst gar nichts mehr.
            // Der Claim schützt weiter gegen die meisten Überlappungen.
            self::reportLockUnavailable();
        }

        try {
            // Double-Check unter der Sperre: Ein paralleler Aufruf kann die
            // Aufgabe zwischen Vorprüfung und Sperre ausgeführt haben.
            $previous = self::lastRunAt($name);
            if (!self::isDue($previous, $task['intervalSeconds'])) {
                return null;
            }

            // Claim VOR dem Callback. Scheitert er, läuft der Callback nicht
            // (die Ausnahme geht an runDue()).
            self::markRan($name);
            $startedAt = time();
            self::recordStatus($name, 'running', $startedAt, null, null);

            self::$currentTask = $name;
            self::$currentStartedAt = $startedAt;
            try {
                call_user_func($task['callback']);
            } catch (\Throwable $e) {
                if ($task['retryOnFailure']) {
                    self::restoreLastRun($name, $previous);
                }
                $detail = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
                self::recordStatus($name, 'error', $startedAt, time(), $e->getMessage());
                self::auditQuietly("Cron-Aufgabe fehlgeschlagen: {$name}", $detail);
                return ['name' => $name, 'status' => 'error', 'error' => $e->getMessage()];
            } finally {
                self::$currentTask = null;
                self::$currentStartedAt = null;
            }

            self::recordStatus($name, 'ok', $startedAt, time(), null);
            return ['name' => $name, 'status' => 'ok'];
        } finally {
            if ($lock === true) {
                DbLock::release($lockKey);
            }
        }
    }

    private static function isDue(?int $lastRun, int $intervalSeconds): bool {
        return $lastRun === null || (time() - $lastRun) >= $intervalSeconds;
    }

    private static function markRan(string $name): void {
        $db = Database::getInstance();
        $now = (string)time();
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([self::settingKey($name), $now, $now]);
    }

    /**
     * Schreibt den Zeitstempel von vor dem Claim zurück (retryOnFailure).
     * Eigenes try/catch: Ein Fehler hier darf die Fehlermeldung der Aufgabe
     * nicht überdecken.
     */
    private static function restoreLastRun(string $name, ?int $previous): void {
        try {
            $db = Database::getInstance();
            if ($previous === null) {
                $db->prepare("DELETE FROM settings WHERE setting_key = ?")->execute([self::settingKey($name)]);
            } else {
                $value = (string)$previous;
                $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?")
                    ->execute([self::settingKey($name), $value, $value]);
            }
        } catch (\Throwable $e) {
            error_log("Scheduler: Zeitstempel von {$name} nicht zurückgesetzt: " . $e->getMessage());
        }
    }

    private static function recordStatus(string $name, string $status, ?int $startedAt, ?int $finishedAt, ?string $error): void {
        try {
            $json = json_encode([
                'status' => $status,
                'startedAt' => $startedAt,
                'finishedAt' => $finishedAt,
                'error' => $error !== null ? mb_substr($error, 0, self::STATUS_ERROR_LENGTH, 'UTF-8') : null,
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $stmt = Database::getInstance()->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([self::statusKey($name), $json, $json]);
        } catch (\Throwable $e) {
            error_log("Scheduler: Status von {$name} nicht gespeichert: " . $e->getMessage());
        }
    }

    /**
     * @return array{status:string, startedAt:?int, finishedAt:?int, error:?string}|null
     */
    private static function readStatus(string $name): ?array {
        try {
            $stmt = Database::getInstance()->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
            $stmt->execute([self::statusKey($name)]);
            $raw = $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_string($raw)) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !is_string($data['status'] ?? null)) {
            return null;
        }
        return [
            'status' => $data['status'],
            'startedAt' => isset($data['startedAt']) ? (int)$data['startedAt'] : null,
            'finishedAt' => isset($data['finishedAt']) ? (int)$data['finishedAt'] : null,
            'error' => isset($data['error']) ? (string)$data['error'] : null,
        ];
    }

    /**
     * @param array{status:string, startedAt:?int, finishedAt:?int, error:?string}|null $status
     */
    private static function displayState(?array $status, ?bool $running): ?string {
        if ($status === null) {
            return null;
        }
        if ($status['status'] !== 'running') {
            return $status['status'];
        }
        if ($running === true) {
            return 'running';
        }
        if ($running === null) {
            return 'unknown';
        }
        // Status "running", aber niemand hält die Sperre: Der Worker wurde
        // ohne Rückmeldung beendet (SIGKILL, request_terminate_timeout) -
        // sofern der Start nicht gerade erst war.
        $startedAt = $status['startedAt'] ?? 0;
        return (time() - $startedAt) > self::ABORTED_GRACE_SECONDS ? 'aborted' : 'running';
    }

    /**
     * Legt die Speicherreserve an und registriert den Shutdown-Handler
     * einmal je Request. Er läuft nach dem von App\Service\ErrorHandler;
     * der ruft kein exit auf, die Kette bricht also nicht ab.
     */
    private static function armShutdownHandler(): void {
        self::$reserve = str_repeat("\0", self::SHUTDOWN_RESERVE_BYTES);
        if (self::$shutdownRegistered) {
            return;
        }
        self::$shutdownRegistered = true;
        register_shutdown_function(static function (): void {
            self::handleShutdown();
        });
    }

    /**
     * Hält fest, dass der Request mitten in einer Aufgabe endete - durch
     * einen Fatal Error oder ein exit im Callback.
     */
    private static function handleShutdown(): void {
        self::$reserve = null;
        $name = self::$currentTask;
        if ($name === null) {
            return;
        }
        self::$currentTask = null;

        $detail = 'Request vorzeitig beendet (exit)';
        $last = error_get_last();
        if ($last !== null && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
            $detail = $last['message'] . ' in ' . $last['file'] . ':' . $last['line'];
        }

        self::recordStatus($name, 'aborted', self::$currentStartedAt, time(), $detail);
        self::auditQuietly("Cron-Aufgabe abgebrochen: {$name}", $detail);
    }

    private static function auditQuietly(string $action, string $detail): void {
        try {
            AuditLogger::log($action, 'cron', $detail);
        } catch (\Throwable $e) {
            error_log("Scheduler: Audit-Eintrag nicht geschrieben ({$action}): " . $e->getMessage());
        }
    }

    private static function reportLockUnavailable(): void {
        if (self::$lockUnavailableReported) {
            return;
        }
        self::$lockUnavailableReported = true;
        error_log('Scheduler: GET_LOCK ist nicht verfügbar - Cron-Aufgaben laufen nur mit Zeitstempel-Claim, Überlappungen sind nicht ganz ausgeschlossen.');
    }

    private static function lockKey(string $name): string {
        return 'cron:' . $name;
    }

    private static function settingKey(string $name): string {
        return BoundedKey::fit('cron_last_run__', $name);
    }

    private static function statusKey(string $name): string {
        return BoundedKey::fit('cron_status__', $name);
    }

    /**
     * Nur für Tests: Registry zwischen Testfällen zurücksetzen, damit sich
     * Tests nicht gegenseitig über den statischen Zustand beeinflussen
     * (analog zu App\I18n\Translator::resetForTests()).
     */
    public static function resetForTests(): void {
        self::$tasks = [];
        self::$currentTask = null;
        self::$currentStartedAt = null;
        self::$reserve = null;
        self::$lockUnavailableReported = false;
    }
}
