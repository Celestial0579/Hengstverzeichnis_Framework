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
        // `nachkommen` (Audit N58): Endgültiges Löschen schreibt Name und UELN
        // als Freitext in die Nachkommen und löst die Verknüpfung - diese
        // Nebenwirkung soll VOR dem Klick sichtbar sein. Die Unterabfragen
        // nutzen die FK-Indizes auf sire_id/dam_id und laufen nur über den
        // (kleinen) Papierkorb-Bestand.
        $deletedHorses = $this->hasPermission('horses', 'delete')
            ? $db->query(
                "SELECT h.*,"
                . " (SELECT COUNT(*) FROM horses c WHERE c.sire_id = h.id)"
                . " + (SELECT COUNT(*) FROM horses c WHERE c.dam_id = h.id) AS nachkommen"
                . " FROM horses h WHERE h.deleted_at IS NOT NULL ORDER BY h.deleted_at DESC"
            )->fetchAll() : [];
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

    /**
     * Wuerde dieses Konto nach der Wiederherstellung Rechte haben, fuer die
     * eine E-Mail-Adresse Pflicht ist - und keine besitzen? (#348)
     */
    private function kontoBrauchtAdresse(\PDO $db, int $userId): bool {
        $stmt = $db->prepare("SELECT email FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $adresse = trim((string)($stmt->fetchColumn() ?: ''));

        if ($adresse !== '') {
            return false;
        }

        $braucht = \App\Permission\EmailRequirement::userRequiresEmail($db, $userId);
        if ($braucht) {
            \App\Service\AuditLogger::log(
                "Wiederherstellung abgelehnt",
                "trash",
                sprintf(
                    'Konto ID %d hat keine E-Mail-Adresse, seine Gruppen geben aber Bearbeitungs- '
                    . 'oder Veroeffentlichungsrechte (#348)',
                    $userId
                )
            );
        }

        return $braucht;
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

            // Adresspflicht nach Rechten (#348) - der DRITTE Zeitpunkt.
            //
            // Die Gruppenzugehoerigkeiten ueberleben den Soft-Delete. Bekommt
            // eine Gruppe ein Bearbeitungsrecht, waehrend eines ihrer
            // Mitglieder ohne Adresse im Papierkorb liegt, laesst die Pruefung
            // dort das durch (geloeschte Konten zaehlen bewusst nicht mit,
            // sonst blockierte ein nie zurueckgeholtes Konto die Rechtevergabe
            // fuer immer). Beim Zurueckholen entstuende damit genau der
            // Zustand, den beide anderen Pruefungen verweigern: ein aktives
            // Konto mit Schreibrecht und ohne Rueckweg.
            if ($type === 'user' && $this->kontoBrauchtAdresse($db, $id)) {
                header("Location: /admin/trash?error=email_required");
                exit;
            }

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

            // Nur was im Papierkorb liegt, wird endgültig gelöscht (Audit N57) -
            // für ALLE Benutzer, auch Admins. Der Fall ist ein veralteter Tab
            // (vgl. #336): Das Element wurde inzwischen wiederhergestellt, der
            // alte "Endgültig löschen"-Knopf traf dann den aktiven Datensatz.
            // Ebenso ließ sich so ein aktives Konto - auch das eigene, an der
            // Selbstlöschsperre des UserController vorbei - endgültig löschen.
            if ($deletedAt === false || $deletedAt === null) {
                header("Location: /admin/trash?error=not_in_trash");
                exit;
            }

            $isOlderThan30Days = strtotime($deletedAt) <= strtotime('-30 days');

            if (!$isAdmin && !$isOlderThan30Days) {
                header("Location: /admin/trash?error=retention_period_30_days");
                exit;
            }

            // Frist für Nicht-Admins, die unter Sperre erneut geprüft wird.
            $mindestTage = $isAdmin ? null : 30;

            if ($type === 'contact') {
                // Kontakte über KontaktDsgvo (Audit M11, M23, N45): Namenskopien
                // am Pferd, Dubletten-Entscheidungen und Altkopien (#336) fallen
                // in derselben Transaktion mit. Der Papierkorb-Guard und - für
                // Nicht-Admins - die Frist werden unter Sperre erneut geprüft.
                $geloescht = $this->deleteContactsWithCleanup($db, [$id], $mindestTage);
                if ($geloescht === 0) {
                    // Inzwischen wiederhergestellt bzw. die Frist hat sich unter
                    // Sperre als nicht abgelaufen erwiesen (erneut verschoben).
                    header("Location: /admin/trash?error=not_in_trash");
                    exit;
                }
                \App\Service\AuditLogger::log("Element endgültig gelöscht", "trash", "Typ: {$type}, ID: {$id}");
                header("Location: /admin/trash?success=purged");
                exit;
            }

            if ($type === 'user') {
                // Ein einzelnes DELETE mit Guard ist atomar; rowCount 0 heißt,
                // das Konto wurde zwischen Prüfung und Löschen wiederhergestellt.
                $sql = "DELETE FROM users WHERE id = ? AND deleted_at IS NOT NULL";
                if ($mindestTage !== null) {
                    $sql .= " AND deleted_at <= DATE_SUB(NOW(), INTERVAL " . (int)$mindestTage . " DAY)";
                }
                $deleteStmt = $db->prepare($sql);
                $deleteStmt->execute([$id]);
                if ($deleteStmt->rowCount() === 0) {
                    header("Location: /admin/trash?error=not_in_trash");
                    exit;
                }
                \App\Service\AuditLogger::log("Element endgültig gelöscht", "trash", "Typ: {$type}, ID: {$id}");
                header("Location: /admin/trash?success=purged");
                exit;
            }

            // type === 'horse'
            // Plugin-Hook (#164): VOR dem endgültigen Löschen - die letzte
            // Gelegenheit für Plugins, den Datensatz noch zu lesen. Er kann
            // feuern, obwohl das Löschen danach unterbleibt (inzwischen
            // wiederhergestellt, siehe docs/plugin-development.md).
            $rowStmt = $db->prepare("SELECT * FROM horses WHERE id = ?");
            $rowStmt->execute([$id]);
            $horse = $rowStmt->fetch() ?: [];
            $this->hooks()->doAction('horse.before_delete', $id, $horse, true);

            $ergebnis = $this->pferdeImPapierkorbLoeschen($db, [$id], $mindestTage);
            if ($ergebnis['geloescht'] === []) {
                header("Location: /admin/trash?error=not_in_trash");
                exit;
            }

            // Plugin-Hook (#164): NACH dem endgültigen Löschen (der FK-Cascade
            // hat abhängige Zeilen bereits entfernt, Nachkommen tragen Name und
            // UELN als Freitext).
            $this->hooks()->doAction('horse.deleted', $id, $horse);

            // Erst NACH dem Commit und nach horse.deleted (Audit N59, D15):
            // Ein Plugin, das Fotos archivieren will, findet sie im Hook noch
            // vor. Entfernt wird nur, was nach dem Löschen niemand mehr
            // referenziert; verwaisteDateienEntfernen() wirft nie.
            $fotos = \App\Service\HorseMedia::verwaisteDateienEntfernen($ergebnis['dateien']);

            $details = "Typ: {$type}, ID: {$id}";
            if ($ergebnis['nachkommen'] > 0) {
                $details .= " – {$ergebnis['nachkommen']} Nachkommen: Abstammung als Freitext übernommen";
            }
            if ($fotos > 0) {
                $details .= " – {$fotos} Bilddatei(en) entfernt";
            }
            \App\Service\AuditLogger::log("Element endgültig gelöscht", "trash", $details);

            header("Location: /admin/trash?success=purged");
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
            $pferde = $this->deleteHorsesWithHooks($db, "deleted_at IS NOT NULL", null);
            $this->deleteContactsWithCleanup($db, $this->kontakteImPapierkorb($db, null), null);
            $db->exec("DELETE FROM users WHERE deleted_at IS NOT NULL");

            \App\Service\AuditLogger::log("Papierkorb geleert (Admin)", "trash", "Alle gelöschten Elemente endgültig bereinigt" . self::nachkommenVermerk($pferde['nachkommen']));
        } else {
            // Nicht-Admins können pro Modul nur dann (und nur >30 Tage alte)
            // Elemente bereinigen, wenn sie die jeweilige Lösch-Berechtigung
            // besitzen - ein Benutzer ohne Rechte bereinigt so nichts.
            $nachkommen = 0;
            if ($this->hasPermission('horses', 'delete')) {
                $pferde = $this->deleteHorsesWithHooks($db, "deleted_at IS NOT NULL AND deleted_at <= DATE_SUB(NOW(), INTERVAL 30 DAY)", 30);
                $nachkommen = $pferde['nachkommen'];
            }
            if ($this->hasPermission('contacts', 'delete')) {
                $this->deleteContactsWithCleanup($db, $this->kontakteImPapierkorb($db, 30), 30);
            }

            \App\Service\AuditLogger::log("Papierkorb bereinigt (>30 Tage)", "trash", "Ältere Elemente durch Editor bereinigt" . self::nachkommenVermerk($nachkommen));
        }

        header("Location: /admin/trash?success=emptied");
        exit;
    }

    /**
     * Kennungen der Kontakte im Papierkorb, optional nur älter als $mindestTage.
     *
     * @return int[]
     */
    private function kontakteImPapierkorb(\PDO $db, ?int $mindestTage): array {
        $sql = "SELECT id FROM contacts WHERE deleted_at IS NOT NULL";
        if ($mindestTage !== null) {
            $sql .= " AND deleted_at <= DATE_SUB(NOW(), INTERVAL " . (int)$mindestTage . " DAY)";
        }
        return array_map('intval', $db->query($sql . " ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Endgültiges Löschen von Kontakten aus dem Papierkorb (Audit M11, M23,
     * N45) nach dem Muster von deleteHorsesWithHooks().
     *
     * Früher stand hier ein schlichtes DELETE, und die Fremdschlüssel räumten
     * auf (#336): horse_persons.contact_id per CASCADE, station_contact_id
     * und horses.breeding_station_id per SET NULL. Genau dieses SET NULL
     * machte die Namenskopie der Station am Pferd (horses.breeding_station)
     * zum öffentlichen Freitext - auch bei nie veröffentlichten Kontakten,
     * in Katalog, Detailseite, Stationssuche und /api/horses. Deshalb bereinigt
     * App\Service\KontaktDsgvo diese Kopien, die Dubletten-Entscheidungen und
     * die Altkopien vorher, in derselben Transaktion. Das Protokoll bleibt
     * unverändert - Papierkorb ist kein DSGVO-Verlangen.
     *
     * Je Charge (DELETE_BATCH_SIZE) eine Transaktion. Der Guard
     * `deleted_at IS NOT NULL` (und für Nicht-Admins die Frist) gilt im
     * Sperr-Select und im DELETE, nicht nur in der Vorauswahl: Ein zwischen
     * Auswahl und Charge wiederhergestellter Kontakt bleibt erhalten (Race
     * #222). `contact.erased` feuert NACH dem Commit, nur für tatsächlich
     * gelöschte Kontakte.
     *
     * @param int[] $ids
     * @return int Anzahl tatsächlich gelöschter Kontakte
     */
    private function deleteContactsWithCleanup(\PDO $db, array $ids, ?int $mindestTage): int {
        $anzahl = 0;
        foreach (array_chunk($ids, self::DELETE_BATCH_SIZE) as $charge) {
            $db->beginTransaction();
            try {
                $ergebnis = \App\Service\KontaktDsgvo::loeschen($db, $charge, \App\Service\KontaktDsgvo::PAPIERKORB, $mindestTage);
                $db->commit();
            } catch (\Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }

            foreach ($ergebnis['kontakte'] as $kontaktId => $kontakt) {
                $this->hooks()->doAction('contact.erased', (int)$kontaktId, $kontakt, 'papierkorb');
                $anzahl++;
            }
        }
        return $anzahl;
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
     * Gelöscht wird höchstens die selektierte ID-Menge - nicht erneut über
     * die Bedingung. Das schützt vor Pferden, die ZWISCHEN Auswahl und DELETE
     * frisch in den Papierkorb wandern (sie stürben sonst ohne
     * before_delete-Hook). Vor dem umgekehrten Fall, einem zwischenzeitlich
     * WIEDERHERGESTELLTEN Pferd, schützte die ID-Menge nicht (Audit N57):
     * Das erledigt jetzt pferdeImPapierkorbLoeschen() mit einer Neuprüfung
     * unter Zeilensperre.
     *
     * Jede Charge (max. DELETE_BATCH_SIZE IDs) läuft in einer eigenen
     * Transaktion: Das DELETE ist damit je Charge atomar (kein halb geleerter
     * Zustand innerhalb einer Charge, etwa bei einem FK-Fehler mitten im
     * Statement), ohne bei sehr großen Papierkörben eine minutenlange
     * Riesen-Transaktion aufzuspannen. Die Hooks feuern bewusst AUSSERHALB der
     * Transaktion - Plugin-Handler (Audit-Log-INSERTs, eigene Queries) sollen
     * das Sperrfenster nicht verlängern und ein werfender Handler kein bereits
     * committetes Löschen "zurückrollen" können. horse.deleted feuert nur für
     * tatsächlich gelöschte Pferde. Danach werden deren Bilddateien entfernt
     * (Audit N59).
     *
     * @return array{geloescht: int[], nachkommen: int} siehe pferdeImPapierkorbLoeschen()
     */
    private function deleteHorsesWithHooks(\PDO $db, string $condition, ?int $mindestTage): array {
        $gesamt = ['geloescht' => [], 'nachkommen' => 0];
        $horses = $db->query("SELECT * FROM horses WHERE {$condition}")->fetchAll();
        if (!$horses) {
            return $gesamt;
        }

        foreach (array_chunk($horses, self::DELETE_BATCH_SIZE) as $batch) {
            foreach ($batch as $horse) {
                $this->hooks()->doAction('horse.before_delete', (int)$horse['id'], $horse, true);
            }

            $ids = array_map(static fn(array $horse): int => (int)$horse['id'], $batch);
            $ergebnis = $this->pferdeImPapierkorbLoeschen($db, $ids, $mindestTage);
            $geloescht = array_flip($ergebnis['geloescht']);

            foreach ($batch as $horse) {
                if (isset($geloescht[(int)$horse['id']])) {
                    $this->hooks()->doAction('horse.deleted', (int)$horse['id'], $horse);
                }
            }

            // Bilddateien erst nach Commit und horse.deleted (Audit N59), je
            // Charge - siehe permanentDelete().
            \App\Service\HorseMedia::verwaisteDateienEntfernen($ergebnis['dateien']);

            $gesamt['geloescht'] = array_merge($gesamt['geloescht'], $ergebnis['geloescht']);
            $gesamt['nachkommen'] += $ergebnis['nachkommen'];
        }
        return $gesamt;
    }

    /**
     * Die eine Lösch-Transaktion für Pferde aus dem Papierkorb (Audit N57,
     * N58), gemeinsam für permanentDelete() und emptyTrash().
     *
     * 1. `SELECT … FOR UPDATE` prüft unter Zeilensperre erneut, welche der
     *    Kandidaten noch im Papierkorb liegen (bei $mindestTage zusätzlich:
     *    lange genug). Die Sperre lässt ein gleichzeitiges restore() bis zum
     *    Commit warten; ein bereits committetes Wiederherstellen sieht der
     *    sperrende Read. Ein veralteter Tab trifft so kein aktives Pferd mehr.
     * 2. Erst dann schreibt abstammungAlsFreitextSichern() Name und UELN in
     *    die Nachkommen - nie für Pferde, die stehen bleiben.
     * 3. DELETE mit `AND deleted_at IS NOT NULL` als zweite Absicherung.
     *
     * Rückgabe - auch die Schnittstelle für Folgeschritte NACH dem Commit,
     * etwa das Entfernen der Bilddateien (Audit N59): `geloescht` enthält
     * GENAU die Kennungen, deren Zeilen dieses Statement endgültig entfernt
     * hat; Kandidaten, die inzwischen wiederhergestellt wurden, fehlen darin.
     * `nachkommen` ist die Zahl der umgeschriebenen Nachkommen-Verweise.
     * `dateien` sind die Bild-Spaltenwerte GENAU dieser Pferde, unter der
     * Sperre vor dem DELETE eingesammelt - der FK-CASCADE nimmt die
     * Medienzeilen mit. Der Aufrufer übergibt sie NACH horse.deleted an
     * HorseMedia::verwaisteDateienEntfernen(), das nur Unreferenziertes
     * entfernt (Audit N59).
     * Bei einer Ausnahme wird zurückgerollt und weitergeworfen - dann ist
     * nichts gelöscht, und es werden keine Dateien entfernt.
     *
     * @param int[] $ids Kandidaten
     * @return array{geloescht: int[], nachkommen: int, dateien: string[]}
     */
    private function pferdeImPapierkorbLoeschen(\PDO $db, array $ids, ?int $mindestTage): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return ['geloescht' => [], 'nachkommen' => 0, 'dateien' => []];
        }

        $frist = $mindestTage !== null
            ? " AND deleted_at <= DATE_SUB(NOW(), INTERVAL " . (int)$mindestTage . " DAY)"
            : '';

        $db->beginTransaction();
        try {
            $platzhalter = implode(',', array_fill(0, count($ids), '?'));
            $sperre = $db->prepare("SELECT id FROM horses WHERE id IN ({$platzhalter}) AND deleted_at IS NOT NULL{$frist} FOR UPDATE");
            $sperre->execute($ids);
            $wirklich = array_map('intval', $sperre->fetchAll(\PDO::FETCH_COLUMN));

            if ($wirklich === []) {
                $db->commit();
                return ['geloescht' => [], 'nachkommen' => 0, 'dateien' => []];
            }

            $dateien = \App\Service\HorseMedia::bilddateienVonPferden($wirklich);
            $nachkommen = $this->abstammungAlsFreitextSichern($db, $wirklich);

            $platzhalter = implode(',', array_fill(0, count($wirklich), '?'));
            $db->prepare("DELETE FROM horses WHERE id IN ({$platzhalter}) AND deleted_at IS NOT NULL")->execute($wirklich);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        sort($wirklich);
        return ['geloescht' => $wirklich, 'nachkommen' => $nachkommen, 'dateien' => $dateien];
    }

    /**
     * Übernimmt Name und UELN endgültig gelöschter Elterntiere als Freitext
     * in deren Nachkommen (Audit N58).
     *
     * Beim Verknüpfen wird der Freitext (sire_name/sire_ueln bzw. dam_*)
     * geleert. Das ON DELETE SET NULL des Fremdschlüssels hinterließe deshalb
     * Nachkommen ganz ohne Abstammungsangabe. So bleibt der Platzhalter
     * stehen, und das Match-Werkzeug bzw. ein neu angelegtes Pferd mit
     * derselben UELN kann die Verknüpfung wiederherstellen.
     *
     * - Vorhandener Freitext bleibt erhalten (COALESCE/NULLIF).
     * - Die UELN wird nur übernommen, wenn sie in VARCHAR(15) passt; sonst
     *   bräche der Strict Mode das Löschen ab.
     * - sire_id/dam_id = NULL explizit, auch für Altinstallationen ohne FK.
     * - Nachkommen im Papierkorb werden mitversorgt - eine bewusste Ausnahme
     *   vom Schreibschutz #322, damit sie nach dem Wiederherstellen ihre
     *   Abstammung behalten.
     *
     * Multi-Table-UPDATE mit Self-Join: MariaDB/MySQL erlauben das (die
     * Sperre ER_UPDATE_TABLE_USED gilt nur für Unterabfragen). Läuft in der
     * Transaktion des Aufrufers.
     *
     * @param int[] $elternIds
     * @return int Zahl der umgeschriebenen Nachkommen-Verweise
     */
    private function abstammungAlsFreitextSichern(\PDO $db, array $elternIds): int {
        if ($elternIds === []) {
            return 0;
        }
        $platzhalter = implode(',', array_fill(0, count($elternIds), '?'));

        $vater = $db->prepare(
            "UPDATE horses c JOIN horses p ON c.sire_id = p.id"
            . " SET c.sire_name = COALESCE(NULLIF(c.sire_name, ''), p.name),"
            . " c.sire_ueln = COALESCE(NULLIF(c.sire_ueln, ''), IF(CHAR_LENGTH(p.ueln) <= 15, p.ueln, NULL)),"
            . " c.sire_id = NULL"
            . " WHERE p.id IN ({$platzhalter})"
        );
        $vater->execute($elternIds);

        $mutter = $db->prepare(
            "UPDATE horses c JOIN horses p ON c.dam_id = p.id"
            . " SET c.dam_name = COALESCE(NULLIF(c.dam_name, ''), p.name),"
            . " c.dam_ueln = COALESCE(NULLIF(c.dam_ueln, ''), IF(CHAR_LENGTH(p.ueln) <= 15, p.ueln, NULL)),"
            . " c.dam_id = NULL"
            . " WHERE p.id IN ({$platzhalter})"
        );
        $mutter->execute($elternIds);

        return $vater->rowCount() + $mutter->rowCount();
    }

    /**
     * Audit-Zusatz für emptyTrash() (Audit N58).
     */
    private static function nachkommenVermerk(int $nachkommen): string {
        return $nachkommen > 0
            ? " – {$nachkommen} Nachkommen: Abstammung als Freitext übernommen"
            : '';
    }
}
