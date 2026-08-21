<?php
// src/Service/DormantAccountService.php

namespace App\Service;

use App\Database;
use PDO;

/**
 * Konten ohne zweiten Faktor und ohne E-Mail-Adresse nach 180 Tagen
 * deaktivieren - nicht löschen (#358).
 *
 * WORUM ES GEHT. Mit #348 und Addons#131 entstehen absichtlich Konten ohne
 * E-Mail-Adresse: Das Verwaltungsteam legt sie für Mitglieder an, das
 * Erstpasswort geht auf Papier oder mündlich heraus. Wer sich nie anmeldet,
 * bleibt in diesem Zustand hängen - ein Zugang mit einem Erstpasswort, das
 * irgendwo in einem Postfach liegt, und sonst nichts. Nach 180 Tagen ist das
 * kein ruhendes Konto mehr, sondern ein offener Zugang ohne Eigentümer.
 *
 * WARUM EIN EIGENER FRISTANKER (`unprotected_since`). `created_at` taugt
 * nicht: Wer einem drei Jahre alten Konto die Adresse entzieht, wäre damit
 * sofort überfällig, und wer eine hinterlegt, bekäme die Frist nie
 * zurückgesetzt. Die Frist zielt auf den ZUSTAND, nicht auf das Alter des
 * Kontos. Diese Klasse ist der einzige Schreiber des Ankers.
 *
 * WARUM DEAKTIVIEREN UND NICHT LÖSCHEN. Ein gelöschtes Konto nimmt seine
 * Zuordnungen und seine Spur im Protokoll mit; eine Sperre ist umkehrbar. Bis
 * v0.8 gab es den Unterschied gar nicht - was der Code "deaktiviert" nannte,
 * war `deleted_at`, also der Papierkorb.
 */
final class DormantAccountService {

    /** Frist in Tagen, nach der ein ungeschütztes Konto deaktiviert wird. */
    public const DORMANT_DAYS = 180;

    /** Vorwarnfenster für den Digest: so viele Tage vorher wird gemeldet. */
    public const WARNING_WINDOW_DAYS = 14;

    /**
     * Höchstzahl je Lauf. Nicht aus Leistungsgründen, sondern als Bremse:
     * Wenn eine Fehlkonfiguration den halben Bestand fällig stellt, soll der
     * erste Lauf auffallen, bevor er alles abgeräumt hat.
     */
    public const MAX_PER_RUN = 500;

    /** Stabiler Grundschlüssel - die Oberfläche übersetzt ihn. */
    public const REASON_DORMANT = 'dormant_no_factor_no_email';

    public const TASK_NAME = 'users.deactivate_dormant';

    /** Ab wann die Regel auf dieser Installation gilt (Karenz nach dem Update). */
    private const SETTING_RULE_ACTIVE_SINCE = 'dormant_rule_active_since';

    private function __construct() {}

    /**
     * Meldet die tägliche Aufgabe an. Bewusst ohne Datenbankzugriff - das
     * läuft im Bootstrap JEDES Requests (siehe public/index.php).
     */
    public static function registerScheduledTask(): void {
        Scheduler::register(self::TASK_NAME, 86400, [self::class, 'run']);
    }

    /**
     * Die EINE Definition von "ungeschützt": kein zweiter Faktor UND keine
     * E-Mail-Adresse.
     *
     * Steht hier und nur hier - und die Liste der Verfahren steht nicht
     * einmal hier, sondern in App\Security\SecondFactors. Mit #354 kam der
     * Mailcode dazu; kommen Passkeys (#353), aendert sich dort eine Zeile und
     * Fristanker, Vorwarnung und Deaktivierung ziehen von selbst mit. Zwei
     * Kopien dieser Bedingung waeren genau die Drift, an der die Regel spaeter
     * auseinanderliefe.
     *
     * Dass ein Konto mit Mailcode-Faktor zwangslaeufig auch eine Adresse hat,
     * macht den ersten Teil der Bedingung fuer dieses Verfahren zwar
     * rechnerisch entbehrlich - aber die Bedingung soll lesbar sagen, was sie
     * meint, und nicht auf einer Nebenbedingung eines einzelnen Verfahrens
     * ruhen.
     */
    private static function unprotectedPredicate(string $alias = 'u'): string {
        return "NOT " . \App\Security\SecondFactors::sqlHasAnyFactor($alias) . " "
             . "AND ({$alias}.email IS NULL OR {$alias}.email = '')";
    }

    /**
     * Setzt den Fristanker nach: gesetzt, solange der Zustand anhält, und
     * geleert, sobald er endet. Das Leeren ist der Neubeginn der Frist.
     *
     * @return array{gesetzt: int, geloescht: int}
     */
    public static function refreshMarkers(): array {
        $db = Database::getInstance();
        $bedingung = self::unprotectedPredicate();

        $gesetzt = (int)$db->exec(
            "UPDATE users u
             SET u.unprotected_since = NOW()
             WHERE u.deleted_at IS NULL
               AND u.unprotected_since IS NULL
               AND {$bedingung}"
        );

        // Anker löschen, sobald ein Faktor oder eine Adresse da ist. Ohne
        // diesen Zweig liefe die Frist weiter, obwohl das Konto längst
        // geschützt ist.
        $geloescht = (int)$db->exec(
            "UPDATE users u
             SET u.unprotected_since = NULL
             WHERE u.unprotected_since IS NOT NULL
               AND NOT ({$bedingung})"
        );

        return ['gesetzt' => $gesetzt, 'geloescht' => $geloescht];
    }

    /**
     * Konten, die innerhalb der nächsten $tage fällig werden - für die
     * Vorwarnung im Digest.
     *
     * Die Vorwarnung geht an die Administratoren, nicht an den Betroffenen:
     * Das Konto hat definitionsgemäß keine Adresse, der Eigentümer ist über
     * dieses System gar nicht erreichbar.
     *
     * @return array<int, array{id: int, username: string, due_at: string}>
     */
    public static function dueSoon(int $tage = self::WARNING_WINDOW_DAYS): array {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare(
                "SELECT u.id, u.username,
                        DATE_FORMAT(u.unprotected_since + INTERVAL ? DAY, '%Y-%m-%d') AS due_at
                 FROM users u
                 WHERE u.deleted_at IS NULL
                   AND u.deactivated_at IS NULL
                   AND u.unprotected_since IS NOT NULL
                   AND u.unprotected_since + INTERVAL ? DAY <= NOW() + INTERVAL ? DAY
                 ORDER BY u.unprotected_since ASC
                 LIMIT 200"
            );
            $stmt->execute([self::DORMANT_DAYS, self::DORMANT_DAYS, $tage]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Der tägliche Lauf.
     *
     * @return array{deaktiviert: int, uebersprungen_admin: int, karenz: bool}
     */
    public static function run(): array {
        $db = Database::getInstance();

        self::refreshMarkers();

        // KARENZ NACH DEM UPDATE. Ohne sie deaktivierte der erste Lauf den
        // kompletten Altbestand am selben Tag - ohne dass irgendjemand die
        // Vorwarnung je gesehen hätte. Die Regel gilt erst, wenn sie
        // mindestens ein Vorwarnfenster lang aktiv war.
        $seit = self::ruleActiveSince($db);
        if ($seit === null) {
            self::setRuleActiveSince($db);
            return ['deaktiviert' => 0, 'uebersprungen_admin' => 0, 'karenz' => true];
        }
        if ($seit > time() - self::WARNING_WINDOW_DAYS * 86400) {
            return ['deaktiviert' => 0, 'uebersprungen_admin' => 0, 'karenz' => true];
        }

        $stmt = $db->prepare(
            "SELECT u.id, u.username
             FROM users u
             WHERE u.deleted_at IS NULL
               AND u.deactivated_at IS NULL
               AND u.unprotected_since IS NOT NULL
               AND u.unprotected_since <= NOW() - INTERVAL ? DAY
             ORDER BY u.unprotected_since ASC
             LIMIT " . self::MAX_PER_RUN
        );
        $stmt->execute([self::DORMANT_DAYS]);
        $kandidaten = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $deaktiviert = 0;
        $uebersprungen = 0;
        foreach ($kandidaten as $konto) {
            // DAS LETZTE AKTIVE ADMIN-KONTO WIRD NIE DEAKTIVIERT. Sonst
            // sperrt sich die Installation selbst aus, und niemand kann die
            // Sperre wieder aufheben - die Wiedereinschaltung ist eine
            // Adminfunktion.
            if (self::isLastActiveAdmin((int)$konto['id'])) {
                $uebersprungen++;
                continue;
            }
            if (self::deactivate((int)$konto['id'], self::REASON_DORMANT)) {
                $deaktiviert++;
            }
        }

        if ($deaktiviert > 0 || $uebersprungen > 0) {
            AuditLogger::log(
                'Ruhende Konten deaktiviert',
                'users',
                sprintf(
                    '%d Konto/Konten ohne zweiten Faktor und ohne E-Mail seit mehr als %d Tagen deaktiviert'
                    . ($uebersprungen > 0 ? ', %d als letztes Admin-Konto verschont' : '%s'),
                    $deaktiviert,
                    self::DORMANT_DAYS,
                    $uebersprungen > 0 ? $uebersprungen : ''
                )
            );
        }

        return ['deaktiviert' => $deaktiviert, 'uebersprungen_admin' => $uebersprungen, 'karenz' => false];
    }

    public static function deactivate(int $userId, string $reason): bool {
        try {
            $stmt = Database::getInstance()->prepare(
                "UPDATE users SET deactivated_at = NOW(), deactivated_reason = ?
                 WHERE id = ? AND deleted_at IS NULL AND deactivated_at IS NULL"
            );
            $stmt->execute([$reason, $userId]);
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Wieder einschalten - und den Fristanker mit zurücksetzen.
     *
     * Ohne das Zurücksetzen deaktivierte der nächste Nachtlauf dasselbe Konto
     * sofort wieder: Der Anker stünde ja weiterhin 180 Tage in der
     * Vergangenheit. Der Admin bekäme einen Knopf, der nichts bewirkt.
     */
    public static function reactivate(int $userId): bool {
        try {
            $stmt = Database::getInstance()->prepare(
                "UPDATE users
                 SET deactivated_at = NULL, deactivated_reason = NULL, unprotected_since = NULL
                 WHERE id = ? AND deleted_at IS NULL AND deactivated_at IS NOT NULL"
            );
            $stmt->execute([$userId]);
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Ist das das letzte Konto, das die Installation noch verwalten kann? */
    private static function isLastActiveAdmin(int $userId): bool {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM users u
                 JOIN user_groups ug ON ug.user_id = u.id
                 JOIN `groups` g ON g.id = ug.group_id AND g.slug = 'admin'
                 WHERE u.deleted_at IS NULL AND u.deactivated_at IS NULL AND u.id <> ?"
            );
            $stmt->execute([$userId]);
            $andere = (int)$stmt->fetchColumn();

            if ($andere > 0) {
                return false;
            }

            // Keine anderen Admins - ist DIESES Konto denn eins?
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM user_groups ug
                 JOIN `groups` g ON g.id = ug.group_id AND g.slug = 'admin'
                 WHERE ug.user_id = ?"
            );
            $stmt->execute([$userId]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (\Throwable $e) {
            // Fail-closed in Richtung "nicht anfassen": Im Zweifel lieber ein
            // Konto zu wenig deaktivieren als die Installation aussperren.
            return true;
        }
    }

    private static function ruleActiveSince(PDO $db): ?int {
        try {
            $stmt = $db->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
            $stmt->execute([self::SETTING_RULE_ACTIVE_SINCE]);
            $wert = $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_string($wert) || $wert === '') {
            return null;
        }
        $zeit = strtotime($wert);
        return $zeit === false ? null : $zeit;
    }

    private static function setRuleActiveSince(PDO $db): void {
        try {
            $db->prepare(
                "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            )->execute([self::SETTING_RULE_ACTIVE_SINCE, gmdate('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            // Ohne Marker gilt beim nächsten Lauf erneut Karenz - das ist die
            // sichere Richtung.
        }
    }
}
