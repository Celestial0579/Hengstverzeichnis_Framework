<?php
// src/Service/DigestService.php

namespace App\Service;

use App\Database;

/**
 * Class DigestService
 *
 * Optionaler periodischer E-Mail-Digest an Admins/Editoren (#52): fasst
 * Ereignisse zusammen, für die es aktuell keine sofortige Benachrichtigung
 * gibt - im Unterschied zu DSGVO-Anfragen, die bereits über
 * `Mailer::sendDsgvoNotification()` sofort benachrichtigt werden (siehe
 * Design-Entscheidung im Issue). Baut auf der Cron-/Scheduler-
 * Infrastruktur (#67) auf, analog zu App\Service\BackupService (#59).
 *
 * Inhalt bewusst auf die im Issue genannten zwei Punkte beschränkt:
 * - Anzahl offener Blutlinien-Match-/Merge-Vorschläge (App\Service\MatchSuggestionFinder)
 * - Anzahl Papierkorb-Einträge, die sich der 30-Tage-Frist nähern (siehe
 *   TrashController::permanentDelete() für die Bedeutung dieser Frist: ab
 *   30 Tagen dürfen auch Editoren - nicht nur Admins - ein Element
 *   endgültig löschen)
 *
 * Meldet stets den AKTUELLEN Stand zum Zeitpunkt des Digest-Laufs, nicht
 * ein Delta "neu seit dem letzten Digest" - Match-Vorschläge sind reine
 * Berechnung zur Anfragezeit ohne eigenen Zeitstempel, ein Delta wäre daher
 * nur über zusätzlichen persistenten Zustand nachbildbar, ohne dass der
 * Issue-Text dafür einen zwingenden Bedarf nennt.
 */
final class DigestService {

    private const TASK_NAME = 'digest.admin_editor';
    private const DEFAULT_INTERVAL_HOURS = 24;

    /** Tage vor Erreichen der 30-Tage-Löschfrist, ab denen ein Papierkorb-Eintrag als "bald ablaufend" zählt. */
    private const TRASH_WARNING_WINDOW_DAYS = 7;

    public static function registerScheduledTask(): void {
        $settings = self::loadSettings();
        if (($settings['digest_enabled'] ?? '') !== '1') {
            return;
        }

        $intervalHours = max(1, (int)($settings['digest_interval_hours'] ?? self::DEFAULT_INTERVAL_HOURS));
        Scheduler::register(self::TASK_NAME, $intervalHours * 3600, [self::class, 'run']);
    }

    /**
     * Führt einen einzelnen Digest-Lauf durch: Stand ermitteln, bei
     * Bedarf an alle Admin-/Editor-Adressen versenden. Gibt es nichts zu
     * berichten, wird bewusst NICHTS versendet (kein "alles ruhig"-Spam).
     *
     * @throws \RuntimeException Bei vollständigem Fehlschlag (keine
     *                           Empfänger oder Versand an niemanden
     *                           erfolgreich) - der Aufrufer (Scheduler)
     *                           protokolliert das zusätzlich zentral im
     *                           Audit-Log, analog zu App\Service\BackupService.
     *                           Ein NUR teilweiser Fehlschlag (einzelne
     *                           Empfänger nicht erreichbar) gilt dagegen als
     *                           Erfolg des Laufs, da der Digest trotzdem sein
     *                           Ziel erreicht hat.
     */
    public static function run(): void {
        $matchSuggestionCount = self::countOpenMatchSuggestions();
        $expiringTrashCount = self::countExpiringTrashItems();

        if ($matchSuggestionCount === 0 && $expiringTrashCount === 0) {
            self::recordStatus('ok', null, 0);
            return;
        }

        $recipients = self::loadRecipients();
        if (empty($recipients)) {
            $message = 'Keine Admin-/Editor-E-Mail-Adressen gefunden.';
            self::recordStatus('error', $message, 0);
            throw new \RuntimeException($message);
        }

        $mailer = new Mailer();
        $sentCount = 0;
        $failedRecipients = [];
        foreach ($recipients as $recipientEmail) {
            if ($mailer->sendAdminDigest($recipientEmail, $matchSuggestionCount, $expiringTrashCount)) {
                $sentCount++;
            } else {
                $failedRecipients[] = $recipientEmail;
            }
        }

        if ($sentCount === 0) {
            $message = 'Versand an keinen der Empfänger erfolgreich (E-Mail-Konfiguration prüfen).';
            self::recordStatus('error', $message, 0);
            throw new \RuntimeException($message);
        }

        $error = empty($failedRecipients) ? null : ('Fehlgeschlagen für: ' . implode(', ', $failedRecipients));
        self::recordStatus('ok', $error, $sentCount);
    }

    private static function countOpenMatchSuggestions(): int {
        return count(MatchSuggestionFinder::findAll());
    }

    private static function countExpiringTrashItems(): int {
        $db = Database::getInstance();
        $thresholdDays = 30 - self::TRASH_WARNING_WINDOW_DAYS;

        $count = 0;
        foreach (['horses', 'persons', 'breeding_stations', 'users'] as $table) {
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM `$table`
                WHERE deleted_at IS NOT NULL
                  AND deleted_at <= DATE_SUB(NOW(), INTERVAL ? DAY)
                  AND deleted_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $stmt->execute([$thresholdDays]);
            $count += (int)$stmt->fetchColumn();
        }

        return $count;
    }

    /**
     * @return array<int, string>
     */
    private static function loadRecipients(): array {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT email FROM users WHERE role IN ('admin', 'editor') AND deleted_at IS NULL");
        return array_values(array_filter($stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    private static function recordStatus(string $status, ?string $error, int $sentCount): void {
        $db = Database::getInstance();
        $values = [
            'digest_last_status' => $status,
            'digest_last_run_at' => (string)time(),
            'digest_last_error' => $error ?? '',
            'digest_last_sent_count' => (string)$sentCount,
        ];
        foreach ($values as $key => $value) {
            $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$key, $value, $value]);
        }

        AuditLogger::log(
            $sentCount > 0 ? "E-Mail-Digest an {$sentCount} Empfänger versendet" : 'E-Mail-Digest-Lauf ohne Versand',
            'email',
            $error
        );
    }

    /**
     * @return array<string, string>
     */
    private static function loadSettings(): array {
        try {
            $db = Database::getInstance();
            $stmt = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'digest_%'");
            $settings = [];
            foreach ($stmt->fetchAll() as $row) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            return $settings;
        } catch (\Throwable $e) {
            // Fail-safe analog zu App\Service\BackupService::loadSettings() -
            // registerScheduledTask() läuft bei jedem Request-Bootstrap, auch
            // bevor die Datenbank eingerichtet ist (Setup-Assistent).
            return [];
        }
    }
}
