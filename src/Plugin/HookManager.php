<?php
// src/Plugin/HookManager.php

namespace App\Plugin;

/**
 * Class HookManager
 *
 * Zentrale Hook-/Event-Registry des Plugin-Systems (#56). Erlaubt Plugins,
 * sich auf klar definierte Erweiterungspunkte im Kern zu registrieren, statt
 * Kernklassen zu verändern oder von ihnen zu erben.
 *
 * Sicherheits-Leitplanke: Jeder einzelne Plugin-Callback läuft in eigener
 * try/catch-Isolation. Eine Exception oder ein Error in einem Plugin bricht
 * nur diesen einen Hook-Aufruf ab (protokolliert im Audit-Log, Kategorie
 * "plugin") - niemals die gesamte Anfrage/Seite. Reine PHP-Fatal-Errors
 * (z. B. Parse-Fehler in der Plugin-Datei selbst) lassen sich systembedingt
 * nicht abfangen; dafür sorgt bereits App\Plugin\PluginManager, indem es
 * Plugin-Dateien nur beim Bootstrap einmalig lädt (siehe dortiges try/catch
 * um require_once).
 */
class HookManager {

    /** @var array<string, array<int, array{priority:int, callback:callable}>> */
    private array $actions = [];

    /** @var array<string, array<int, array{priority:int, callback:callable}>> */
    private array $filters = [];

    /**
     * Registriert einen Callback für einen Action-Hook (reagiert, verändert keinen Wert).
     */
    public function addAction(string $hook, callable $callback, int $priority = 10): void {
        $this->actions[$hook][] = ['priority' => $priority, 'callback' => $callback];
    }

    /**
     * Führt alle registrierten Callbacks für einen Action-Hook aus (nach Priorität sortiert).
     */
    public function doAction(string $hook, mixed ...$args): void {
        if (empty($this->actions[$hook])) {
            return;
        }

        foreach ($this->sortedByPriority($this->actions[$hook]) as $entry) {
            try {
                call_user_func($entry['callback'], ...$args);
            } catch (\Throwable $e) {
                $this->logHookFailure($hook, $e);
            }
        }
    }

    /**
     * Registriert einen Callback für einen Filter-Hook (verändert und gibt einen Wert zurück).
     */
    public function addFilter(string $hook, callable $callback, int $priority = 10): void {
        $this->filters[$hook][] = ['priority' => $priority, 'callback' => $callback];
    }

    /**
     * Lässt einen Wert durch alle registrierten Filter-Callbacks eines Hooks laufen.
     * Schlägt ein Callback fehl, bleibt der Wert aus dem vorherigen Schritt unverändert
     * erhalten (kein Abbruch der gesamten Filter-Kette).
     */
    public function applyFilters(string $hook, mixed $value, mixed ...$args): mixed {
        if (empty($this->filters[$hook])) {
            return $value;
        }

        foreach ($this->sortedByPriority($this->filters[$hook]) as $entry) {
            try {
                $value = call_user_func($entry['callback'], $value, ...$args);
            } catch (\Throwable $e) {
                $this->logHookFailure($hook, $e);
            }
        }

        return $value;
    }

    /**
     * @param array<int, array{priority:int, callback:callable}> $entries
     * @return array<int, array{priority:int, callback:callable}>
     */
    private function sortedByPriority(array $entries): array {
        usort($entries, fn($a, $b) => $a['priority'] <=> $b['priority']);
        return $entries;
    }

    private function logHookFailure(string $hook, \Throwable $e): void {
        \App\Service\AuditLogger::log(
            "Plugin-Hook fehlgeschlagen: {$hook}",
            "plugin",
            $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
        );
    }
}
