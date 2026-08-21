<?php
// src/Permission/EmailRequirement.php

namespace App\Permission;

use PDO;

/**
 * Wer braucht eine E-Mail-Adresse - und wer nicht? (#348)
 *
 * DIE REGEL, SCHARF FORMULIERT. Eine Adresse ist Pflicht fuer jedes Konto mit
 * Bearbeitungs- oder Veroeffentlichungsrechten. Im Rechtemodell heisst das:
 * jede Aktion ausser `view`, auf jedem Modul, auch auf Addon-Modulen. Wer nur
 * lesen darf, darf ohne Adresse gefuehrt werden - genau dafuer gibt es #348:
 * Verbandsmitglieder mit Einblick, Praktikanten, ein gemeinsames Konto fuer
 * die Geschaeftsstelle. Fuer die heute eine Adresse zu erfinden, ist der
 * schlechteste aller Wege: Sie ist entweder falsch oder gehoert jemand
 * anderem.
 *
 * WARUM DIE PRUEFUNG NICHT NUR BEIM ANLEGEN GREIFT. Es genuegt nicht, beim
 * Anlegen eines Kontos zu pruefen. Bekommt eine Gruppe SPAETER ein
 * Bearbeitungsrecht, haben auf einen Schlag alle ihre Mitglieder eines - auch
 * die ohne Adresse. Deshalb fragt auch die Rechtevergabe hier nach
 * (GroupController::updatePermissions()/copyPermissions()) und nennt die
 * Konten, die zuerst eine Adresse brauchen. Ohne das waere die Regel Zierde.
 *
 * WARUM `admin` GESONDERT STEHT. Die Gruppe `admin` hat systemseitig immer
 * alle Rechte und deshalb absichtlich KEINE Zeilen in `group_permissions`
 * (siehe BaseController::hasPermission()). Wer nur die Tabelle abfragt,
 * haelt Administratoren fuer Nur-Leser - der gefaehrlichste Irrtum, den
 * diese Klasse machen koennte.
 */
final class EmailRequirement {

    /**
     * Die Aktionen, die als reines Lesen gelten.
     *
     * ZWEI, NICHT EINE. Der Kern kennt neben `view` eine zweite Leseaktion:
     * `App\Permission\FeatureRegistry` legt fuer jede Plugin-Zusatzfunktion
     * automatisch `feature_<key>`/`read` an, und `FeatureGate::isVisible()`
     * wertet sie ausdruecklich als Leseberechtigung. Wer nur `view` kennt,
     * haelt eine Gruppe, die eine Plugin-Funktion bloss SEHEN darf, fuer
     * schreibberechtigt - und lehnt genau den Fall ab, fuer den #348 gebaut
     * wurde: Verbandsmitglieder mit Einblick, ohne Adresse. In jeder
     * Installation mit Addons waere die Regel damit unbrauchbar.
     *
     * WARUM EINE POSITIVLISTE UND KEINE LISTE DER SCHREIBAKTIONEN. Plugins
     * duerfen eigene Aktionen anmelden. Eine unbekannte Aktion muss als
     * schreibend gelten - das verlangt hoechstens eine Adresse zu viel. Eine
     * Liste der Schreibaktionen liesse eine unbekannte durchgehen, und das
     * waere ein Konto mit Rechten und ohne Rueckweg.
     */
    public const READ_ONLY_ACTIONS = ['view', 'read'];

    private function __construct() {}

    /**
     * IDs aller Gruppen, deren Mitgliedschaft eine E-Mail-Adresse verlangt.
     *
     * @return array<int, int>
     */
    public static function groupIdsRequiringEmail(PDO $db): array {
        $platzhalter = implode(',', array_fill(0, count(self::READ_ONLY_ACTIONS), '?'));
        $stmt = $db->prepare(
            "SELECT DISTINCT g.id
             FROM `groups` g
             LEFT JOIN group_permissions p ON p.group_id = g.id AND p.action NOT IN ({$platzhalter})
             WHERE g.slug = 'admin' OR p.group_id IS NOT NULL"
        );
        $stmt->execute(self::READ_ONLY_ACTIONS);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Verlangt mindestens eine dieser Gruppen eine Adresse?
     *
     * @param array<int, int> $groupIds
     */
    public static function groupsRequireEmail(PDO $db, array $groupIds): bool {
        $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds), fn(int $id): bool => $id > 0)));
        if ($groupIds === []) {
            return false;
        }

        return array_intersect($groupIds, self::groupIdsRequiringEmail($db)) !== [];
    }

    /**
     * Braucht dieses bestehende Konto eine Adresse - aufgrund seiner
     * derzeitigen Gruppen?
     */
    public static function userRequiresEmail(PDO $db, int $userId): bool {
        $stmt = $db->prepare('SELECT group_id FROM user_groups WHERE user_id = ?');
        $stmt->execute([$userId]);

        return self::groupsRequireEmail($db, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Mitglieder der genannten Gruppen, die keine Adresse hinterlegt haben -
     * die Liste, die eine abgelehnte Rechtevergabe nennen muss.
     *
     * Geloeschte Konten bleiben aussen vor: Sie melden sich nicht an, und
     * ihretwegen soll niemand eine Rechtevergabe nicht durchfuehren koennen.
     * Gesperrte Konten (#358) zaehlen dagegen mit - eine Sperre ist umkehrbar,
     * das Konto kaeme mit dem neuen Recht und ohne Adresse zurueck.
     *
     * Ein Soft-Delete ist ebenfalls umkehrbar. Das ist hier bewusst NICHT
     * beruecksichtigt, sondern an der anderen Stelle: Die Wiederherstellung
     * aus dem Papierkorb prueft selbst (TrashController::restore() ueber
     * userRequiresEmail()). Andernfalls blockierte ein laengst geloeschtes
     * Konto, das nie jemand zurueckholt, dauerhaft die Rechtevergabe.
     *
     * @param array<int, int> $groupIds
     * @return array<int, array{id: int, username: string}>
     */
    public static function accountsWithoutEmail(PDO $db, array $groupIds): array {
        $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds), fn(int $id): bool => $id > 0)));
        if ($groupIds === []) {
            return [];
        }

        $platzhalter = implode(',', array_fill(0, count($groupIds), '?'));
        $stmt = $db->prepare(
            "SELECT DISTINCT u.id, u.username
             FROM users u
             JOIN user_groups ug ON ug.user_id = u.id
             WHERE ug.group_id IN ({$platzhalter})
               AND u.deleted_at IS NULL
               AND (u.email IS NULL OR u.email = '')
             ORDER BY u.username ASC"
        );
        $stmt->execute($groupIds);

        return array_map(
            static fn(array $row): array => ['id' => (int)$row['id'], 'username' => (string)$row['username']],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * Verlangt diese Rechte-Menge (Modul/Aktion-Paare) eine Adresse?
     *
     * Fuer den Fall, dass die Rechte noch gar nicht gespeichert sind - die
     * Pruefung muss VOR dem Schreiben stattfinden.
     *
     * @param array<int, array{module: string, action: string}> $pairs
     */
    public static function pairsRequireEmail(array $pairs): bool {
        foreach ($pairs as $paar) {
            if (!in_array((string)($paar['action'] ?? ''), self::READ_ONLY_ACTIONS, true)) {
                return true;
            }
        }
        return false;
    }
}
