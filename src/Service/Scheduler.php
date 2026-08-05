<?php
// src/Service/Scheduler.php

namespace App\Service;

use App\Database;

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
 * Jede Aufgabe läuft in eigener try/catch-Isolation (analog zu
 * App\Plugin\HookManager): eine fehlschlagende Aufgabe protokolliert den
 * Fehler im Audit-Log und blockiert nie die übrigen Aufgaben desselben Laufs.
 */
final class Scheduler {

    /** @var array<string, array{intervalSeconds:int, callback:callable, retryOnFailure:bool}> */
    private static array $tasks = [];

    private function __construct() {}

    /**
     * Registriert eine periodisch auszuführende Aufgabe.
     *
     * @param string $name Eindeutiger, stabiler Bezeichner (z. B. "backup.external") -
     *                      dient zugleich als Schlüssel für den zuletzt-ausgeführt-Zeitstempel.
     * @param int $intervalSeconds Mindestabstand zwischen zwei Ausführungen.
     * @param callable $callback Auszuführende Aufgabe, ohne Argumente aufgerufen.
     * @param bool $retryOnFailure Bei true wird der Zeitstempel bei einer fehlgeschlagenen
     *                             Ausführung NICHT aktualisiert, sodass die Aufgabe beim
     *                             nächsten Cron-Lauf sofort erneut versucht wird, statt das
     *                             volle Intervall abzuwarten. Standard false (fehlgeschlagene
     *                             Aufgaben laufen regulär erst zum nächsten Intervall erneut).
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
     * @return array<int, array{name:string, status:'ok'|'error', error?:string}>
     */
    public static function runDue(): array {
        $results = [];

        foreach (self::$tasks as $name => $task) {
            if (!self::isDue($name, $task['intervalSeconds'])) {
                continue;
            }

            try {
                call_user_func($task['callback']);
                self::markRan($name);
                $results[] = ['name' => $name, 'status' => 'ok'];
            } catch (\Throwable $e) {
                if (!$task['retryOnFailure']) {
                    self::markRan($name);
                }
                AuditLogger::log(
                    "Cron-Aufgabe fehlgeschlagen: {$name}",
                    'cron',
                    $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
                );
                $results[] = ['name' => $name, 'status' => 'error', 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * @return array<int, array{name:string, intervalSeconds:int, lastRunAt:?int}>
     */
    public static function registeredTasks(): array {
        $out = [];
        foreach (self::$tasks as $name => $task) {
            $out[] = [
                'name' => $name,
                'intervalSeconds' => $task['intervalSeconds'],
                'lastRunAt' => self::lastRunAt($name),
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

    private static function isDue(string $name, int $intervalSeconds): bool {
        $lastRun = self::lastRunAt($name);
        return $lastRun === null || (time() - $lastRun) >= $intervalSeconds;
    }

    private static function markRan(string $name): void {
        $db = Database::getInstance();
        $now = (string)time();
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([self::settingKey($name), $now, $now]);
    }

    private static function settingKey(string $name): string {
        return 'cron_last_run__' . $name;
    }

    /**
     * Nur für Tests: Registry zwischen Testfällen zurücksetzen, damit sich
     * Tests nicht gegenseitig über den statischen Zustand beeinflussen
     * (analog zu App\I18n\Translator::resetForTests()).
     */
    public static function resetForTests(): void {
        self::$tasks = [];
    }
}
