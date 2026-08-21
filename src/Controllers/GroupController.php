<?php
// src/Controllers/GroupController.php

namespace App\Controllers;

use App\Database;
use App\Helper\Paginator;
use App\Permission\EmailRequirement;
use App\Permission\PermissionRegistry;
use App\Service\AuditLogger;
use PDO;

/**
 * Class GroupController
 *
 * Admin-only Verwaltung des Gruppen-/Berechtigungssystems (#66): eingebaute
 * Gruppen (admin/editor/public) plus beliebig viele eigene Gruppen, sowie
 * die Berechtigungsmatrix (Modul × Aktion) je Gruppe.
 *
 * Sicherheits-Leitplanken (siehe auch docs/user-groups-plan.md, Abschnitt 8):
 * - `admin` hat serverseitig hart codiert immer alle Rechte
 *   (BaseController::hasPermission()) - dafür gibt es absichtlich keine
 *   editierbaren Berechtigungs-Zeilen, und die Gruppe ist nie über
 *   `user_groups` zuweisbar (siehe PROTECTED_PERMISSION_SLUGS,
 *   UserController::syncUserGroups()).
 * - `public` (nicht angemeldete Besucher) erhält NIE etwas anderes als
 *   Lese-Rechte (siehe GUEST_ALLOWED_ACTIONS/restrictForGuest()) - sowohl in
 *   der View (deaktivierte Nicht-view-Checkboxen, kein Kopieren-Formular) als
 *   auch hier serverseitig (Filterung jeder Schreib-Anfrage) durchgesetzt.
 *   Der Login-Zwang (BaseController::checkAuth()) schützt zwar die
 *   Kern-Controller, aber NICHT jede Route: Plugin-Routen sind laut
 *   Dokumentation selbst für ihren Schutz zuständig und prüfen teils
 *   ausschließlich hasPermission() - eine versehentlich an `public` vergebene
 *   Verwaltungs-Berechtigung würde solche Routen für Anonyme öffnen (#218).
 *   Deshalb wird die Beschränkung hier hart serverseitig erzwungen und die
 *   Gruppe ist zusätzlich nie einem echten Benutzer zuweisbar.
 * - `admin` und `public` sind damit die EINZIGEN beiden Sonderfälle im
 *   gesamten Gruppensystem. Jede andere Gruppe - auch die eingebaute `editor` -
 *   verhält sich identisch zu einer eigenen Gruppe: frei editierbare
 *   Berechtigungen, frei zuweisbar über `user_groups`, startet bei Anlage ohne
 *   jede Berechtigung (erbt also effektiv von `public`, nicht von `editor`).
 *   Mitgliedschaft ist grundsätzlich nur explizit möglich (siehe
 *   BaseController::userGroupIds()) - es gibt keine "Standardgruppe" mehr, der
 *   ein Benutzer automatisch angehört.
 * - Eingebaute Gruppen (admin/editor/public) können nicht gelöscht werden.
 */
class GroupController extends BaseController {

    /**
     * Gruppen, deren Berechtigungs-MATRIX serverseitig nie verändert werden darf
     * (siehe updatePermissions()/copyPermissions()) - `admin` hat systemseitig
     * immer implizit alle Rechte und braucht daher nie eigene
     * group_permissions-Zeilen. Die Gast-Gruppe `public` ist hier bewusst NICHT
     * (mehr) enthalten: ihre Lese-Rechte steuern, was nicht angemeldete Besucher
     * öffentlich sehen dürfen, und müssen daher editierbar bleiben. Editierbar
     * heißt aber NICHT uneingeschränkt: alles jenseits von GUEST_ALLOWED_ACTIONS
     * wird für `public` serverseitig verworfen (restrictForGuest(), #218), denn
     * checkAuth() schützt nur die Kern-Controller - Plugin-Routen prüfen teils
     * allein hasPermission() und stünden mit einer Verwaltungs-Zeile für
     * `public` jedem Anonymen offen.
     * Betrifft NICHT die Zuweisbarkeit einer Gruppe zu Benutzern (siehe
     * NON_ASSIGNABLE_SLUGS) - `admin` MUSS Benutzern zuweisbar sein, sonst
     * könnte nie ein Administrator angelegt werden.
     */
    public const PROTECTED_PERMISSION_SLUGS = ['admin'];

    /**
     * Gruppen, die serverseitig nie über `user_groups` einem echten Benutzer
     * zugewiesen werden dürfen (siehe UserController::assignableGroups()/
     * syncUserGroups()) - `public` ist ausschließlich für nicht angemeldete
     * Besucher gedacht (siehe BaseController::checkAuth()). Kleinere Menge als
     * PROTECTED_PERMISSION_SLUGS: `admin` ist zwar von der Matrix-Bearbeitung
     * geschützt, muss aber regulär zuweisbar sein, sonst gäbe es nie einen Weg,
     * einen Administrator anzulegen.
     */
    public const NON_ASSIGNABLE_SLUGS = ['public'];

    /**
     * Aktionen, die die Gast-Gruppe `public` maximal erhalten darf (#218).
     * Ihre Rechte steuern ausschließlich, was nicht angemeldete Besucher im
     * öffentlichen Teil SEHEN - Schreib- und Verwaltungsaktionen dürfen dort
     * niemals landen: Der Login-Zwang (checkAuth()) deckt nur die
     * Kern-Controller ab, Plugin-Routen prüfen teils allein hasPermission()
     * und wären mit einer solchen Zeile für JEDEN anonymen Besucher offen
     * (konkret ausgenutzt gedacht über "Berechtigungen kopieren von
     * Administrator" auf die Gast-Gruppe). Wird in updatePermissions() UND
     * copyPermissions() über restrictForGuest() durchgesetzt; die View
     * (admin_groups.php) spiegelt dieselbe Regel nur zusätzlich wider.
     */
    public const GUEST_ALLOWED_ACTIONS = ['view'];

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requireAdmin();
    }

    public function index(): void {
        $db = Database::getInstance();
        $groups = $db->query("SELECT * FROM `groups` ORDER BY is_builtin DESC, name ASC")->fetchAll();

        $permissions = [];
        $rows = $db->query("SELECT group_id, module, action FROM group_permissions")->fetchAll();
        foreach ($rows as $row) {
            $permissions[(int)$row['group_id']][$row['module']][$row['action']] = true;
        }

        // Aktuell zur Bearbeitung ausgewählte Gruppe (Dropdown, siehe admin_groups.php) -
        // Kompaktheit: es wird immer nur die Berechtigungsmatrix EINER Gruppe angezeigt,
        // nicht mehr aller Gruppen untereinander. Default: die erste nicht-eingebaute
        // Gruppe (meist "Editor"), da das der häufigste Bearbeitungsfall ist.
        // WICHTIG: Diese Auswahl bezieht sich immer auf die VOLLSTÄNDIGE Gruppenliste,
        // unabhängig von der Pagination der Übersichtstabelle unten (siehe $pagedGroups).
        $groupIds = array_column($groups, 'id');
        $selectedGroupId = isset($_GET['group']) ? (int)$_GET['group'] : 0;
        if (!in_array($selectedGroupId, array_map('intval', $groupIds), true)) {
            $defaultGroup = null;
            foreach ($groups as $g) {
                if (!$g['is_builtin'] || $g['slug'] === 'editor') {
                    $defaultGroup = $g;
                    break;
                }
            }
            $selectedGroupId = $defaultGroup ? (int)$defaultGroup['id'] : (int)($groups[0]['id'] ?? 0);
        }

        // Suche + Pagination der Übersichtstabelle (nicht der Bearbeiten-Auswahl oben,
        // siehe App\Helper\Paginator) - wirkt nur auf $pagedGroups, NICHT auf $groups
        // selbst (die "Gruppe zur Bearbeitung auswählen"- und "Berechtigungen kopieren
        // von"-Dropdowns bleiben unabhängig von Suche/Pagination immer vollständig).
        $search = trim((string)($_GET['search'] ?? ''));
        $searchableGroups = Paginator::search($groups, $search, ['name', 'description']);
        $perPage = Paginator::readPerPage($_GET);
        $result = Paginator::paginate($searchableGroups, $perPage, (int)($_GET['page'] ?? 1));

        // Einmal-Hinweis aus einer abgelehnten Rechtevergabe (#348) - lesen
        // heisst verbrauchen, sonst klebt er am naechsten Seitenaufruf.
        $emailPflichtHinweis = $_SESSION['groups_email_pflicht'] ?? null;
        unset($_SESSION['groups_email_pflicht']);

        $this->render('admin_groups', [
            'title' => 'Gruppen & Berechtigungen',
            'emailPflichtHinweis' => $emailPflichtHinweis,
            'groups' => $groups,
            'pagedGroups' => $result['items'],
            'search' => $search,
            'totalGroupsUnfiltered' => count($groups),
            'permissions' => $permissions,
            'modules' => PermissionRegistry::modules(),
            'selectedGroupId' => $selectedGroupId,
            'totalPermissionCount' => PermissionRegistry::countAll(),
            'perPage' => $perPage,
            'perPageOptions' => Paginator::PER_PAGE_OPTIONS,
            'page' => $result['page'],
            'totalPages' => $result['totalPages'],
            'totalGroups' => $result['total'],
        ]);
    }

    public function createGroup(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (empty($name)) {
            header("Location: /admin/groups?error=name_required");
            exit;
        }

        $slug = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
        if ($slug === '' || in_array($slug, ['admin', 'editor', 'public'], true)) {
            header("Location: /admin/groups?error=invalid_slug");
            exit;
        }

        $db = Database::getInstance();
        try {
            $stmt = $db->prepare("INSERT INTO `groups` (slug, name, description, is_builtin) VALUES (?, ?, ?, 0)");
            $stmt->execute([$slug, $name, $description]);
            $newGroupId = (int)$db->lastInsertId();

            AuditLogger::log("Gruppe angelegt", "groups", "Gruppe: {$name} (Slug: {$slug})");

            header("Location: /admin/groups?group={$newGroupId}&success=created");
        } catch (\Exception $e) {
            header("Location: /admin/groups?error=slug_taken");
        }
        exit;
    }

    public function deleteGroup(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $id = (int)($_POST['id'] ?? 0);
        $db = Database::getInstance();

        $stmt = $db->prepare("SELECT slug, name, is_builtin FROM `groups` WHERE id = ?");
        $stmt->execute([$id]);
        $group = $stmt->fetch();

        // Eingebaute Gruppen sind Teil des Kern-Sicherheitsmodells (siehe
        // BaseController::hasPermission()) und dürfen nicht gelöscht werden.
        if (!$group || (bool)$group['is_builtin']) {
            header("Location: /admin/groups?error=cannot_delete_builtin");
            exit;
        }

        $stmt = $db->prepare("DELETE FROM `groups` WHERE id = ?");
        $stmt->execute([$id]);

        AuditLogger::log("Gruppe gelöscht", "groups", "Gruppe: {$group['name']}");

        header("Location: /admin/groups?success=deleted");
        exit;
    }

    public function updatePermissions(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $groupId = (int)($_POST['group_id'] ?? 0);
        $db = Database::getInstance();

        $stmt = $db->prepare("SELECT slug, name FROM `groups` WHERE id = ?");
        $stmt->execute([$groupId]);
        $group = $stmt->fetch();

        if (!$group) {
            header("Location: /admin/groups?error=unknown_group");
            exit;
        }

        // Serverseitige Durchsetzung zusätzlich zu den deaktivierten Checkboxen in der
        // View - eine manipulierte Anfrage darf diese Regeln nicht umgehen können.
        if (in_array($group['slug'], self::PROTECTED_PERMISSION_SLUGS, true)) {
            header("Location: /admin/groups?error=protected_group");
            exit;
        }

        /** @var array<string, array<int, string>> $selected */
        $selected = $_POST['permissions'] ?? [];
        // Für die Gast-Gruppe werden Nicht-Lese-Aktionen serverseitig verworfen -
        // die deaktivierten Checkboxen der View reichen nicht, eine manipulierte
        // Anfrage darf `public` keine Schreibrechte unterschieben (#218).
        $pairs = $this->restrictForGuest($group, $this->flattenSelectedPermissions($selected));

        // Adresspflicht nach Rechten (#348): Bekommt eine Gruppe ein
        // Bearbeitungs- oder Veroeffentlichungsrecht, haben es auf einen
        // Schlag ALLE ihre Mitglieder - auch die ohne E-Mail-Adresse. Eine
        // Regel, die nur beim Anlegen eines Kontos greift, waere damit Zierde.
        if (!$this->adressenReichen($db, $groupId, $group['name'], $pairs)) {
            header("Location: /admin/groups?group={$groupId}&error=email_required");
            exit;
        }

        $this->replacePermissions($db, $groupId, $pairs);

        AuditLogger::log("Berechtigungen aktualisiert", "groups", "Gruppe: {$group['name']}");

        header("Location: /admin/groups?group={$groupId}&success=permissions_updated");
        exit;
    }

    /**
     * Schaltet die 2FA-Pflicht einer Gruppe um (#84). Für die Gruppen `admin`
     * (2FA fest verdrahtet immer verpflichtend, siehe
     * AuthController::userRequires2fa()) und `public` (meldet sich nie an)
     * serverseitig abgelehnt - zusätzlich zur ausgeblendeten UI, eine
     * manipulierte Anfrage darf das nicht umgehen können.
     */
    public function updateRequire2fa(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $groupId = (int)($_POST['group_id'] ?? 0);
        $db = Database::getInstance();

        $stmt = $db->prepare("SELECT slug, name FROM `groups` WHERE id = ?");
        $stmt->execute([$groupId]);
        $group = $stmt->fetch();

        if (!$group) {
            header("Location: /admin/groups?error=unknown_group");
            exit;
        }

        if (in_array($group['slug'], ['admin', 'public'], true)) {
            header("Location: /admin/groups?error=protected_group");
            exit;
        }

        $require = !empty($_POST['require_2fa']) ? 1 : 0;
        $stmt = $db->prepare("UPDATE `groups` SET require_2fa = ? WHERE id = ?");
        $stmt->execute([$require, $groupId]);

        AuditLogger::log(
            "2FA-Pflicht einer Gruppe geändert",
            "groups",
            "Gruppe: {$group['name']} -> " . ($require ? 'verpflichtend' : 'optional')
        );

        header("Location: /admin/groups?group={$groupId}&success=require_2fa_updated");
        exit;
    }

    /**
     * Kopiert die komplette Berechtigungsmenge einer Quell-Gruppe auf eine Ziel-Gruppe
     * (überschreibt die bisherigen Berechtigungen der Ziel-Gruppe vollständig). Für die
     * Quelle "admin" wird dabei bewusst nicht group_permissions abgefragt (dort gibt es
     * absichtlich keine Zeilen, siehe BaseController::hasPermission()), sondern der
     * vollständige App\Permission\PermissionRegistry-Katalog als "alle Rechte" verwendet -
     * andernfalls würde "von Admin kopieren" fälschlich 0 Berechtigungen übertragen.
     */
    public function copyPermissions(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $sourceGroupId = (int)($_POST['source_group_id'] ?? 0);
        $targetGroupId = (int)($_POST['target_group_id'] ?? 0);
        $db = Database::getInstance();

        $stmt = $db->prepare("SELECT id, slug, name FROM `groups` WHERE id IN (?, ?)");
        $stmt->execute([$sourceGroupId, $targetGroupId]);
        $found = $stmt->fetchAll();
        $bySlugId = [];
        foreach ($found as $row) {
            $bySlugId[(int)$row['id']] = $row;
        }

        $source = $bySlugId[$sourceGroupId] ?? null;
        $target = $bySlugId[$targetGroupId] ?? null;

        if (!$source || !$target) {
            header("Location: /admin/groups?error=unknown_group");
            exit;
        }

        // Ziel bleibt geschützt (admin/public) - siehe updatePermissions() für die Begründung.
        if (in_array($target['slug'], self::PROTECTED_PERMISSION_SLUGS, true)) {
            header("Location: /admin/groups?error=protected_group");
            exit;
        }

        if ($source['slug'] === 'admin') {
            $pairs = PermissionRegistry::allPairs();
        } else {
            $stmt = $db->prepare("SELECT module, action FROM group_permissions WHERE group_id = ?");
            $stmt->execute([$sourceGroupId]);
            $pairs = $stmt->fetchAll();
        }

        // Auch beim Kopieren gilt die Gast-Beschränkung: "von Administrator auf
        // die Gast-Gruppe kopieren" würde sonst per Ein-Klick sämtliche
        // Verwaltungsrechte (inkl. aller Plugin-Aktionen) an Anonyme vergeben (#218).
        $zuUebernehmen = $this->restrictForGuest($target, $pairs);

        // Auch hier die Adresspflicht (#348) - "von Administrator kopieren"
        // ist der schnellste Weg, einer Gruppe auf einen Schlag alle
        // Schreibrechte zu geben.
        if (!$this->adressenReichen($db, $targetGroupId, $target['name'], $zuUebernehmen)) {
            header("Location: /admin/groups?group={$targetGroupId}&error=email_required");
            exit;
        }

        $this->replacePermissions($db, $targetGroupId, $zuUebernehmen);

        AuditLogger::log(
            "Berechtigungen kopiert",
            "groups",
            "Von '{$source['name']}' nach '{$target['name']}'"
        );

        header("Location: /admin/groups?group={$targetGroupId}&success=copied");
        exit;
    }

    /**
     * Duerfen dieser Gruppe die genannten Rechte gegeben werden - oder fehlt
     * Mitgliedern dafuer die E-Mail-Adresse? (#348)
     *
     * Legt im Verweigerungsfall die betroffenen Konten in der Session ab. Ein
     * blosses "geht nicht" waere hier nutzlos: Der Admin muesste raten,
     * welches der Konten gemeint ist. Die Namen passen nicht in eine
     * URL - deshalb die Session, ausgelesen und geleert in index().
     *
     * @param array<int, array{module:string, action:string}> $pairs
     */
    private function adressenReichen(PDO $db, int $groupId, string $groupName, array $pairs): bool {
        if (!EmailRequirement::pairsRequireEmail($pairs)) {
            return true;
        }

        $ohneAdresse = EmailRequirement::accountsWithoutEmail($db, [$groupId]);
        if ($ohneAdresse === []) {
            return true;
        }

        $_SESSION['groups_email_pflicht'] = [
            'gruppe' => $groupName,
            'konten' => array_column($ohneAdresse, 'username'),
        ];

        AuditLogger::log(
            "Rechtevergabe abgelehnt",
            "groups",
            sprintf(
                'Gruppe "%s" sollte Bearbeitungs- oder Veroeffentlichungsrechte bekommen, aber %d '
                . 'Mitglied(er) haben keine E-Mail-Adresse (#348)',
                $groupName,
                count($ohneAdresse)
            )
        );

        return false;
    }

    /**
     * Filtert für die Gast-Gruppe `public` alle Aktionen heraus, die nicht in
     * GUEST_ALLOWED_ACTIONS stehen (siehe dortige Begründung, #218). Für jede
     * andere Gruppe werden die Paare unverändert zurückgegeben - die
     * Beschränkung gilt ausschließlich für Gäste.
     *
     * @param array{slug:string} $group Gruppen-Zeile mit mindestens dem Slug
     * @param array<int, array{module:string, action:string}> $pairs
     * @return array<int, array{module:string, action:string}>
     */
    private function restrictForGuest(array $group, array $pairs): array {
        if ($group['slug'] !== 'public') {
            return $pairs;
        }
        return array_values(array_filter(
            $pairs,
            fn(array $p): bool => in_array($p['action'], self::GUEST_ALLOWED_ACTIONS, true)
        ));
    }

    /**
     * @param array<string, array<int, string>> $selected Aus $_POST['permissions'], Format module => [action, ...]
     * @return array<int, array{module:string, action:string}>
     */
    private function flattenSelectedPermissions(array $selected): array {
        $pairs = [];
        foreach ($selected as $module => $actions) {
            foreach ((array)$actions as $action) {
                $pairs[] = ['module' => (string)$module, 'action' => (string)$action];
            }
        }
        return $pairs;
    }

    /**
     * Ersetzt die komplette group_permissions-Menge einer Gruppe durch $pairs
     * (nur gültige Modul/Aktion-Kombinationen aus PermissionRegistry werden übernommen).
     *
     * @param array<int, array{module:string, action:string}> $pairs
     */
    private function replacePermissions(PDO $db, int $groupId, array $pairs): void {
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("DELETE FROM group_permissions WHERE group_id = ?");
            $stmt->execute([$groupId]);

            $insertStmt = $db->prepare("INSERT INTO group_permissions (group_id, module, action) VALUES (?, ?, ?)");
            foreach ($pairs as $pair) {
                if (PermissionRegistry::isValid($pair['module'], $pair['action'])) {
                    $insertStmt->execute([$groupId, $pair['module'], $pair['action']]);
                }
            }

            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            header("Location: /admin/groups?error=save_failed");
            exit;
        }
    }
}
