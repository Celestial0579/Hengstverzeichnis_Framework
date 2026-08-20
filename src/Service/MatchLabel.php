<?php
// src/Service/MatchLabel.php

namespace App\Service;

use App\Database;
use PDO;

/**
 * Class MatchLabel
 *
 * Dauerhafte Entscheidungen über Dubletten-Vorschläge (#355).
 *
 * DAS PROBLEM. `MatchSuggestionFinder` bewertet Kandidatenpaare und zeigt sie
 * unter `/admin/matches`. Die einzige Handlung dort war **verknüpfen**. Was
 * fehlte, ist das Gegenteil: „Das sind zwei verschiedene Pferde."
 *
 * Diese Aussage wurde nirgends gespeichert. Also erschien dasselbe Paar bei
 * jedem Aufruf wieder, und der E-Mail-Digest zählte es weiter als offenen
 * Vorschlag — dauerhaft. Wer einmal geprüft und verworfen hatte, prüfte beim
 * nächsten Mal erneut. Bei der Bereinigung der Deckstationsdaten lebte jede
 * Entscheidung in einem Skript statt im Bestand: Beim nächsten Import beginnt
 * dieselbe Arbeit von vorn.
 *
 * Ein Label macht die Entscheidung zum Teil der Daten.
 *
 * EIN LABEL IST KEINE VERKNÜPFUNG. `VERSCHIEDEN` ändert nichts am Bestand -
 * es legt nur den Vorschlag still, in der Liste **und** im Digest. Und es ist
 * widerrufbar: Eine falsch gesetzte Trennung darf nicht endgültig sein.
 * Zusammenführen bleibt dagegen eine Einbahnstraße; dafür gibt es die Vorschau
 * im Merge-Formular.
 *
 * KANONISCHE REIHENFOLGE. Ein Paar (7, 12) ist dasselbe wie (12, 7). Ohne eine
 * feste Reihenfolge stünden beide Fassungen nebeneinander in der Tabelle, und
 * ein Label, das unter der einen gesetzt wurde, griffe unter der anderen
 * nicht - der Vorschlag käme wieder, obwohl er entschieden ist. Deshalb gilt
 * immer die kleinere ID zuerst; der Primärschlüssel erzwingt es.
 */
final class MatchLabel {

    /** Zusammengeführt - der Vorschlag ist erledigt. */
    public const ZUSAMMENGEFUEHRT = 'merged';

    /** Zwei verschiedene Datensätze - dauerhaft ausblenden, aber widerrufbar. */
    public const VERSCHIEDEN = 'different';

    /**
     * Später entscheiden. Blendet NICHT aus.
     *
     * Wozu dann? Weil „ich habe das angesehen und weiß es nicht" eine andere
     * Aussage ist als „ich war noch nicht da" - und die zweite ist der
     * Vorgabezustand. Ohne diesen Wert müsste, wer unsicher ist, zwischen
     * dauerhaftem Ausblenden und gar nichts wählen, und die meisten wählten
     * dauerhaft.
     */
    public const UNKLAR = 'unclear';

    public const ARTEN = ['horse', 'contact'];
    public const LABELS = [self::ZUSAMMENGEFUEHRT, self::VERSCHIEDEN, self::UNKLAR];

    private function __construct() {}

    /**
     * Setzt oder ersetzt ein Label. Ein leeres Label entfernt den Eintrag -
     * das ist der Widerruf.
     */
    public static function setzen(string $art, int $a, int $b, ?string $label, ?string $notiz = null): void {
        if (!in_array($art, self::ARTEN, true)) {
            throw new \InvalidArgumentException("Unbekannte Art: {$art}");
        }
        [$links, $rechts] = self::kanonisch($a, $b);
        $db = Database::getInstance();

        if ($label === null || $label === '') {
            $stmt = $db->prepare(
                'DELETE FROM match_labels WHERE kind = ? AND left_id = ? AND right_id = ?'
            );
            $stmt->execute([$art, $links, $rechts]);
            AuditLogger::log('Dubletten-Entscheidung widerrufen', 'matches', "{$art} {$links}/{$rechts}");
            return;
        }

        if (!in_array($label, self::LABELS, true)) {
            throw new \InvalidArgumentException("Unbekanntes Label: {$label}");
        }

        $notiz = $notiz === null ? null : mb_substr(trim($notiz), 0, 255);

        $stmt = $db->prepare(
            'INSERT INTO match_labels (kind, left_id, right_id, label, note, user_id, username)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE label = VALUES(label), note = VALUES(note),
                                     user_id = VALUES(user_id), username = VALUES(username),
                                     created_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            $art, $links, $rechts, $label, $notiz,
            !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
            $_SESSION['username'] ?? 'SYSTEM',
        ]);

        AuditLogger::log(
            'Dubletten-Entscheidung gesetzt',
            'matches',
            "{$art} {$links}/{$rechts}: {$label}" . ($notiz !== null && $notiz !== '' ? " ({$notiz})" : '')
        );
    }

    /**
     * Alle Paare einer Art, die als VERSCHIEDEN abgelegt sind - als Menge
     * "links:rechts" zum schnellen Nachschlagen beim Filtern.
     *
     * @return array<string, true>
     */
    public static function ausgeblendete(string $art): array {
        try {
            $stmt = Database::getInstance()->prepare(
                'SELECT left_id, right_id FROM match_labels WHERE kind = ? AND label = ?'
            );
            $stmt->execute([$art, self::VERSCHIEDEN]);
        } catch (\Throwable $e) {
            // Tabelle gibt es (noch) nicht - dann ist nichts ausgeblendet.
            return [];
        }

        $menge = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $zeile) {
            $menge[$zeile['left_id'] . ':' . $zeile['right_id']] = true;
        }
        return $menge;
    }

    /** Alle gesetzten Labels einer Art, für die Anzeige. */
    public static function alle(string $art): array {
        try {
            $stmt = Database::getInstance()->prepare(
                'SELECT left_id, right_id, label, note, username, created_at
                 FROM match_labels WHERE kind = ? ORDER BY created_at DESC'
            );
            $stmt->execute([$art]);
        } catch (\Throwable $e) {
            return [];
        }

        $nach = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $zeile) {
            $nach[$zeile['left_id'] . ':' . $zeile['right_id']] = $zeile;
        }
        return $nach;
    }

    /** Ist dieses Paar als verschieden abgelegt? */
    public static function istAusgeblendet(array $ausgeblendete, int $a, int $b): bool {
        [$links, $rechts] = self::kanonisch($a, $b);
        return isset($ausgeblendete[$links . ':' . $rechts]);
    }

    /** @return array{0:int,1:int} kleinere ID zuerst */
    public static function kanonisch(int $a, int $b): array {
        return $a <= $b ? [$a, $b] : [$b, $a];
    }
}
