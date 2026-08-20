<?php
// src/Controllers/TrashController.php

namespace App\Controllers;

use App\Database;

class TrashController extends BaseController {

    /**
     * Abbildung der Papierkorb-Typen auf das jeweilige Berechtigungs-Modul (#66).
     * Papierkorb-Operationen (Wiederherstellen/endgültig Löschen/Leeren) gehören
     * zur Lösch-Domäne des jeweiligen Inhaltstyps: Wer ein Element überhaupt in
     * den Papierkorb verschieben darf (`<modul>.delete`, siehe z. B.
     * HorseController::delete()), darf es auch wiederherstellen oder endgültig
     * entfernen. `user` ist bewusst nicht enthalten - Benutzerkonten sind
     * ausschließlich Administratoren vorbehalten (siehe unten).
     *
     * Seit der Kontaktliste (#336) gibt es statt 'person' und
     * 'breeding_station' nur noch 'contact'. Die alten Typwerte stehen hier
     * BEWUSST nicht als Alias daneben, und der Grund ist kein Ordnungssinn,
     * sondern ein Loeschschaden: Die alten Kennungen waren je Tabelle
     * vergeben, Person 5 und Station 5 gab es beide. Ein Formular aus einem
     * noch offenen Tab schickte nach dem Umzug also eine Kennung, die jetzt
     * zu einem voellig anderen Kontakt gehoert - "endgueltig loeschen" traefe
     * den Falschen. Unbekannte Typen laufen in authorizeForType() in den
     * No-Op-Zweig zurueck zum Papierkorb; die Seite wird dann neu geladen und
     * zeigt die richtigen Kennungen.
     */
    private const TYPE_MODULE_MAP = [
        'horse' => 'horses',
        'contact' => 'contacts',
    ];

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
    }

    public static function getTrashCount(): int {
        try {
            $db = Database::getInstance();
            $userId = $_SESSION['user_id'] ?? null;
            $isAdmin = \App\Permission\GroupMembership::isAdmin($userId);

            // Nur das zählen, was der aktuelle Benutzer auch tatsächlich verwalten
            // darf - andernfalls würde die Badge-Zahl im Menü Elemente offenlegen,
            // auf die der Benutzer über den Papierkorb gar nicht zugreifen darf.
            // Alle erlaubten Counts in EINER Query statt mehreren Roundtrips, da
            // die Badge bei jedem Backend-Seitenaufruf gerendert wird (#134).
            $subselects = [];
            if (self::userCanManage($userId, $isAdmin, 'horses')) {
                $subselects[] = "(SELECT COUNT(*) FROM horses WHERE deleted_at IS NOT NULL)";
            }
            if (self::userCanManage($userId, $isAdmin, 'contacts')) {
                $subselects[] = "(SELECT COUNT(*) FROM contacts WHERE deleted_at IS NOT NULL)";
            }
            if ($isAdmin) {
                $subselects[] = "(SELECT COUNT(*) FROM users WHERE deleted_at IS NOT NULL)";
            }

            if (empty($subselects)) {
                return 0;
            }

            return (int)$db->query("SELECT " . implode(' + ', $subselects))->fetchColumn();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Statische Berechtigungs-Prüfung "darf Benutzer X Elemente des Moduls
     * verwalten (löschen)" für Kontexte ohne Controller-Instanz (getTrashCount()).
     * Spiegelt BaseController::hasPermission() wider: `admin` hat immer alle
     * Rechte, sonst muss eine passende `group_permissions`-Zeile mit action
     * 'delete' vorliegen. Fail-closed bei DB-Fehlern.
     */
    private static function userCanManage(?int $userId, bool $isAdmin, string $module): bool {
        if ($isAdmin) {
            return true;
        }
        if ($userId === null) {
            return false;
        }
        try {
            $groupIds = \App\Permission\GroupMembership::groupIds($userId);
            if (empty($groupIds)) {
                return false;
            }
            $db = Database::getInstance();
            $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
            $stmt = $db->prepare("SELECT COUNT(*) FROM group_permissions WHERE module = ? AND action = 'delete' AND group_id IN ({$placeholders})");
            $stmt->execute(array_merge([$module], $groupIds));
            return (int)$stmt->fetchColumn() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function index(): void {
        $db = Database::getInstance();
        $isAdmin = $this->isAdmin();

        // Fail-closed: jede Inhalts-Sektion nur laden, wenn der Benutzer für das
        // jeweilige Modul die Lösch-Berechtigung besitzt (siehe TYPE_MODULE_MAP).
        // Ohne diese Prüfung könnte jeder eingeloggte Benutzer - unabhängig von
        // seinen Gruppenrechten - fremde Papierkorb-Inhalte einsehen und darüber
        // die Aktionen unten auslösen.
        $deletedHorses = $this->hasPermission('horses', 'delete')
            ? $db->query("SELECT * FROM horses WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC")->fetchAll() : [];
        // Kein `SELECT *`, obwohl der Papierkorb ein Admin-Pfad ist (#336): Die
        // Liste braucht fuenf Felder, und eine Positivliste kann nicht dadurch
        // undicht werden, dass jemand die View spaeter um eine Spalte
        // erweitert - dieselbe Lehre wie aus #293, nur eine Etage hoeher.
        $deletedContacts = $this->hasPermission('contacts', 'delete')
            ? $db->query("SELECT id, name, contact_person, contact_info, deleted_at FROM contacts WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC")->fetchAll() : [];
        $deletedUsers = $isAdmin ? $db->query("SELECT * FROM users WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC")->fetchAll() : [];

        $totalCount = count($deletedHorses) + count($deletedContacts) + count($deletedUsers);

        $this->render('admin_trash', [
            'title' => 'Papierkorb',
            'deletedHorses' => $deletedHorses,
            'deletedContacts' => $deletedContacts,
            'deletedUsers' => $deletedUsers,
            'totalCount' => $totalCount,
            'isAdmin' => $isAdmin
        ]);
    }

    /**
     * Erzwingt die zum Papierkorb-Typ passende Berechtigung und bricht sonst mit
     * einer protokollierten 403-Seite ab. Gemeinsame Zugriffsschranke für
     * restore()/permanentDelete(): Benutzerkonten sind Administratoren
     * vorbehalten, alle anderen Typen erfordern `<modul>.delete`. Unbekannte
     * Typen führen zurück zum Papierkorb (No-Op), damit ein manipulierter
     * `type`-Wert keine ungeprüfte Aktion auslöst.
     *
     * @return bool True, wenn die Aktion fortgesetzt werden darf.
     */
    private function authorizeForType(string $type): bool {
        if ($type === 'user') {
            if (!$this->isAdmin()) {
                $this->renderForbidden("Zugriff verweigert: Benutzerkonten dürfen ausschließlich von Administratoren verwaltet werden.");
            }
            return true;
        }

        if (isset(self::TYPE_MODULE_MAP[$type])) {
            $this->requirePermission(self::TYPE_MODULE_MAP[$type], 'delete');
            return true;
        }

        header("Location: /admin/trash");
        exit;
    }

    public function restore(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $type = $_POST['type'] ?? '';
        $id = (int)($_POST['id'] ?? 0);

        // Serverseitige Berechtigungsprüfung (renderForbidden/requirePermission
        // brechen intern mit 403 ab, wenn sie fehlschlägt).
        $this->authorizeForType($type);

        if ($id > 0) {
            $db = Database::getInstance();
            $stmt = match ($type) {
                'horse' => $db->prepare("UPDATE horses SET deleted_at = NULL WHERE id = ?"),
                'contact' => $db->prepare("UPDATE contacts SET deleted_at = NULL WHERE id = ?"),
                'user' => $db->prepare("UPDATE users SET deleted_at = NULL WHERE id = ?"),
                default => null,
            };

            if ($stmt !== null) {
                $stmt->execute([$id]);

                \App\Service\AuditLogger::log("Element aus Papierkorb wiederhergestellt", "trash", "Typ: {$type}, ID: {$id}");

                // Plugin-Hook (#164): NACH der Wiederherstellung, mit dem dann
                // aktuellen Datensatz (deleted_at bereits NULL) - z. B. damit ein
                // Plugin beim Soft-Delete deaktivierte Daten reaktivieren kann.
                if ($type === 'horse') {
                    $rowStmt = $db->prepare("SELECT * FROM horses WHERE id = ?");
                    $rowStmt->execute([$id]);
                    $horse = $rowStmt->fetch() ?: [];
                    $this->hooks()->doAction('horse.restored', $id, $horse);
                }
            }
        }

        header("Location: /admin/trash?success=restored");
        exit;
    }

    public function permanentDelete(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $isAdmin = $this->isAdmin();
        $type = $_POST['type'] ?? '';
        $id = (int)($_POST['id'] ?? 0);

        // Serverseitige Berechtigungsprüfung (bricht intern mit 403 ab bzw. leitet
        // bei unbekanntem Typ zurück).
        $this->authorizeForType($type);

        $validTypes = ['horse', 'contact', 'user'];

        if (in_array($type, $validTypes, true) && $id > 0) {
            $db = Database::getInstance();

            // Check if item is older than 30 days
            $selectStmt = match ($type) {
                'horse' => $db->prepare("SELECT deleted_at FROM horses WHERE id = ?"),
                'contact' => $db->prepare("SELECT deleted_at FROM contacts WHERE id = ?"),
                'user' => $db->prepare("SELECT deleted_at FROM users WHERE id = ?"),
            };
            $selectStmt->execute([$id]);
            $deletedAt = $selectStmt->fetchColumn();

            $isOlderThan30Days = $deletedAt && (strtotime($deletedAt) <= strtotime('-30 days'));

            // Rule: Permanent deletion allowed if user is Admin OR if item is older than 30 days
            if ($isAdmin || $isOlderThan30Days) {
                // Plugin-Hook (#164): VOR dem endgültigen Löschen - die letzte
                // Gelegenheit für Plugins, den Datensatz noch zu lesen.
                $horse = [];
                if ($type === 'horse') {
                    $rowStmt = $db->prepare("SELECT * FROM horses WHERE id = ?");
                    $rowStmt->execute([$id]);
                    $horse = $rowStmt->fetch() ?: [];
                    $this->hooks()->doAction('horse.before_delete', $id, $horse, true);
                }

                // Beim Kontakt raeumen die Fremdschluessel auf (#336): Die
                // Zuordnungen in horse_persons.contact_id fallen mit
                // (ON DELETE CASCADE), waehrend horse_persons.station_contact_id
                // und horses.breeding_station_id auf NULL gehen (ON DELETE SET
                // NULL). Beide Richtungen sind gewollt und im Schema
                // begruendet - deshalb steht hier weiterhin ein schlichtes
                // DELETE und kein Vorab-Aufraeumen von Hand.
                $deleteStmt = match ($type) {
                    'horse' => $db->prepare("DELETE FROM horses WHERE id = ?"),
                    'contact' => $db->prepare("DELETE FROM contacts WHERE id = ?"),
                    'user' => $db->prepare("DELETE FROM users WHERE id = ?"),
                };
                $deleteStmt->execute([$id]);

                // Plugin-Hook (#164): NACH dem endgültigen Löschen (der FK-Cascade
                // hat abhängige Zeilen bereits entfernt).
                if ($type === 'horse') {
                    $this->hooks()->doAction('horse.deleted', $id, $horse);
                }

                \App\Service\AuditLogger::log("Element endgültig gelöscht", "trash", "Typ: {$type}, ID: {$id}");

                header("Location: /admin/trash?success=purged");
            } else {
                header("Location: /admin/trash?error=retention_period_30_days");
            }
            exit;
        }

        header("Location: /admin/trash");
        exit;
    }

    public function emptyTrash(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $isAdmin = $this->isAdmin();
        $db = Database::getInstance();

        if ($isAdmin) {
            // Admins can clear all trash immediately
            $this->deleteHorsesWithHooks($db, "deleted_at IS NOT NULL");
            $db->exec("DELETE FROM contacts WHERE deleted_at IS NOT NULL");
            $db->exec("DELETE FROM users WHERE deleted_at IS NOT NULL");

            \App\Service\AuditLogger::log("Papierkorb geleert (Admin)", "trash", "Alle gelöschten Elemente endgültig bereinigt");
        } else {
            // Nicht-Admins können pro Modul nur dann (und nur >30 Tage alte)
            // Elemente bereinigen, wenn sie die jeweilige Lösch-Berechtigung
            // besitzen - ein Benutzer ohne Rechte bereinigt so nichts.
            if ($this->hasPermission('horses', 'delete')) {
                $this->deleteHorsesWithHooks($db, "deleted_at IS NOT NULL AND deleted_at <= DATE_SUB(NOW(), INTERVAL 30 DAY)");
            }
            if ($this->hasPermission('contacts', 'delete')) {
                $db->exec("DELETE FROM contacts WHERE deleted_at IS NOT NULL AND deleted_at <= DATE_SUB(NOW(), INTERVAL 30 DAY)");
            }

            \App\Service\AuditLogger::log("Papierkorb bereinigt (>30 Tage)", "trash", "Ältere Elemente durch Editor bereinigt");
        }

        header("Location: /admin/trash?success=emptied");
        exit;
    }

    /**
     * Obergrenze je Lösch-Charge in deleteHorsesWithHooks() (#222): deckelt
     * sowohl die Placeholder-Anzahl des IN(...)-DELETEs als auch die Dauer der
     * zugehörigen Transaktion (Sperrfenster auf `horses` inkl. FK-Cascades).
     */
    private const DELETE_BATCH_SIZE = 500;

    /**
     * Endgültiges Löschen von Pferden inkl. der Lösch-Hooks (#164). Der frühere
     * pauschale Bulk-DELETE lieferte keine Datensätze für die Hook-Payload -
     * deshalb erst SELECT *, dann je Pferd horse.before_delete, ein einziges
     * DELETE ... WHERE id IN (...) je Charge, danach je Pferd horse.deleted
     * (#222 - vorher ein DELETE pro Pferd, bei großen Papierkörben über
     * tausend Queries in einem Request). $condition ist eine feste, hier im
     * Controller definierte Bedingung, kein Benutzereingang.
     *
     * Gelöscht wird dabei exakt die selektierte ID-Menge - nicht erneut über
     * die Bedingung. Das löst die Race-Condition zwischen SELECT und DELETE:
     * Ein Pferd, das ZWISCHEN beiden frisch in den Papierkorb wandert, ist in
     * der ID-Liste nicht enthalten und stirbt daher nicht ohne
     * before_delete-Hook; es bleibt schlicht bis zum nächsten Leeren liegen.
     *
     * Jede Charge (max. DELETE_BATCH_SIZE IDs) läuft in einer eigenen
     * Transaktion: Das DELETE ist damit je Charge atomar (kein halb geleerter
     * Zustand innerhalb einer Charge, etwa bei einem FK-Fehler mitten im
     * Statement), ohne bei sehr großen Papierkörben eine minutenlange
     * Riesen-Transaktion aufzuspannen. Die Hooks feuern bewusst AUSSERHALB der
     * Transaktion - Plugin-Handler (Audit-Log-INSERTs, eigene Queries) sollen
     * das Sperrfenster nicht verlängern und ein werfender Handler kein bereits
     * committetes Löschen "zurückrollen" können.
     */
    private function deleteHorsesWithHooks(\PDO $db, string $condition): void {
        $horses = $db->query("SELECT * FROM horses WHERE {$condition}")->fetchAll();
        if (!$horses) {
            return;
        }

        foreach (array_chunk($horses, self::DELETE_BATCH_SIZE) as $batch) {
            foreach ($batch as $horse) {
                $this->hooks()->doAction('horse.before_delete', (int)$horse['id'], $horse, true);
            }

            $ids = array_map(static fn(array $horse): int => (int)$horse['id'], $batch);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $db->beginTransaction();
            try {
                $db->prepare("DELETE FROM horses WHERE id IN ({$placeholders})")->execute($ids);
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollBack();
                throw $e;
            }

            foreach ($batch as $horse) {
                $this->hooks()->doAction('horse.deleted', (int)$horse['id'], $horse);
            }
        }
    }
}
