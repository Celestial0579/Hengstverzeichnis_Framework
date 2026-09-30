<?php
// src/Helper/LongRunning.php

namespace App\Helper;

/**
 * Bereitet einen Request auf eine lange Laufzeit vor (Audit M39).
 *
 * Ein Cron-Lauf erstellt womöglich ein externes Backup, verschickt Mails
 * oder spielt ein Update ein. Brach PHP ihn nach `max_execution_time` ab
 * oder beendete ihn ein Verbindungsabbruch des aufrufenden `curl`, blieb die
 * Arbeit halb erledigt liegen. `allow()` hebt das Zeitlimit auf und lässt
 * den Request weiterlaufen, wenn der Client auflegt.
 *
 * Was hier NICHT greift: `request_terminate_timeout` von PHP-FPM und die
 * Timeouts eines vorgeschalteten Proxys beenden den Worker trotzdem. Der
 * Scheduler erkennt solche Abbrüche an seinem Aufgabenstatus (siehe
 * `Scheduler::registeredTasks()`).
 *
 * Aufrufer mit Admin-Session sollen vorher `session_write_close()` aufrufen.
 * Sonst hält der lange Lauf die Session-Datei gesperrt, und jeder andere Tab
 * desselben Admins wartet bis zum Ende.
 */
final class LongRunning {

    private function __construct() {}

    public static function allow(): void {
        // function_exists: Shared-Hoster sperren set_time_limit gern per
        // disable_functions. Dann bleibt es beim konfigurierten Limit.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        ignore_user_abort(true);
    }
}
