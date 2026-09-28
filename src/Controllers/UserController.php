<?php
// src/Controllers/UserController.php

namespace App\Controllers;

use App\Database;
use App\Helper\Paginator;
use App\Security\KontoSicherheit;
use App\Security\LoginIdentifier;

class UserController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requireAdmin();
    }

    public function index(): void {
        $db = Database::getInstance();
        // group_names: für die Anzeige aggregierte Gruppenmitgliedschaft (#66) -
        // ersetzt die frühere "Rolle"-Spalte, seit Gruppen das einzige Rechtesystem sind.
        $stmt = $db->query("
            SELECT u.id, u.username, u.email, u.created_at, u.totp_enabled, u.email_2fa_enabled,
                   u.deactivated_at, u.deactivated_reason,
                   -- Passkeys zaehlen als Faktor (Audit N60). Ohne sie galt ein
                   -- Passkey-Konto hier als ungeschuetzt, und der Reset-Knopf fehlte.
                   (SELECT COUNT(*) FROM user_passkeys pk WHERE pk.user_id = u.id) AS passkey_count,
                   GROUP_CONCAT(g.name ORDER BY g.is_builtin DESC, g.name SEPARATOR ', ') AS group_names
            FROM users u
            LEFT JOIN user_groups ug ON ug.user_id = u.id
            LEFT JOIN `groups` g ON g.id = ug.group_id
            WHERE u.deleted_at IS NULL
            GROUP BY u.id
            ORDER BY u.username ASC
        ");
        $users = $stmt->fetchAll();

        // Suche + Seitengrößen-Auswahl/Pagination (10/25/50/100/alle), analog zu
        // GroupController::index() - siehe App\Helper\Paginator.
        $search = trim((string)($_GET['search'] ?? ''));
        $searchableUsers = Paginator::search($users, $search, ['username', 'email']);
        $perPage = Paginator::readPerPage($_GET);
        $result = Paginator::paginate($searchableUsers, $perPage, (int)($_GET['page'] ?? 1));

        $this->render('admin_users', [
            'title' => 'Benutzer verwalten',
            'users' => $result['items'],
            'search' => $search,
            'totalUsersUnfiltered' => count($users),
            'perPage' => $perPage,
            'perPageOptions' => Paginator::PER_PAGE_OPTIONS,
            'page' => $result['page'],
            'totalPages' => $result['totalPages'],
            'totalUsers' => $result['total'],
        ]);
    }

    public function create(): void {
        $db = Database::getInstance();
        $assignableGroups = $this->assignableGroups($db);

        $this->render('admin_user_form', [
            'title' => 'Neuen Benutzer anlegen',
            'user' => null,
            'assignableGroups' => $assignableGroups,
            'userGroupIds' => []
        ]);
    }

    public function store(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        // Auch Fehler-Renders brauchen die Gruppenliste + die getroffene Auswahl -
        // sonst verschwindet die Gruppen-Selectbox aus dem Formular und der
        // nächste Submit löscht alle Gruppenzugehörigkeiten (#123).
        $db = Database::getInstance();
        $assignableGroups = $this->assignableGroups($db);
        $selectedGroupIds = array_map('intval', (array)($_POST['groups'] ?? []));

        // Der fachliche Teil steckt in UserProvisioning (#384) - was ein
        // gueltiges Konto ausmacht, steht dort und nur dort. Hier bleibt, was
        // wirklich zum Formular gehoert: Eingaben, Fehleranzeige,
        // Weiterleitung und die Willkommensmail.
        $ergebnis = \App\Service\UserProvisioning::create(
            $db,
            $username,
            $email,
            $password,
            $selectedGroupIds,
            'Benutzerverwaltung'
        );

        if (!$ergebnis->erfolgreich()) {
            $this->render('admin_user_form', [
                'title' => 'Neuen Benutzer anlegen',
                'user' => null,
                'errors' => $ergebnis->errors,
                'old' => $_POST,
                'assignableGroups' => $assignableGroups,
                'userGroupIds' => $selectedGroupIds
            ]);
            return;
        }

        // Send Welcome E-Mail with initial credentials if requested.
        // Ohne Adresse gibt es nichts zu versenden - das Erstpasswort
        // geht dann auf Papier heraus (#348).
        if (!empty($_POST['send_welcome_email']) && $email !== '') {
            $mailer = new \App\Service\Mailer();
            $mailer->sendWelcomeEmail($email, $username, $password);
        }

        header("Location: /admin/users?success=created");
        exit;
    }

    public function edit(): void {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            header("Location: /admin/users");
            exit;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT id, username, email, pending_email, pending_email_expires_at FROM users WHERE id = ?"
        );
        $stmt->execute([$id]);
        $user = $stmt->fetch();

        if (!$user) {
            header("Location: /admin/users");
            exit;
        }

        $assignableGroups = $this->assignableGroups($db);

        $stmt = $db->prepare("SELECT group_id FROM user_groups WHERE user_id = ?");
        $stmt->execute([$id]);
        $userGroupIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

        $this->render('admin_user_form', [
            'title' => 'Benutzer bearbeiten',
            'user' => $user,
            'assignableGroups' => $assignableGroups,
            'userGroupIds' => $userGroupIds,
            // Aktive API-Schlüssel des Kontos (#217): Ein Admin muss bei einem
            // Kompromittierungsverdacht sehen können, welche Schlüssel ein
            // Konto besitzt, und sie widerrufen können - forUser() liefert nur
            // Metadaten (Bezeichnung, Anzeige-Präfix, Zeitstempel), nie den
            // Schlüsselwert selbst (der existiert ohnehin nur als Hash).
            'apiKeys' => \App\Security\ApiKey::forUser((int)$user['id']),
        ]);
    }

    public function update(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            header("Location: /admin/users");
            exit;
        }
        // Das eigene Konto des Admins oder ein fremdes? Davon haengt ab, ob
        // ein neu gesetztes Passwort bei der naechsten Anmeldung gewechselt
        // werden muss (Audit N13).
        $fremdesKonto = $id !== (int)($_SESSION['user_id'] ?? 0);

        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        // Auch Fehler-Renders brauchen die Gruppenliste + die getroffene Auswahl -
        // sonst verschwindet die Gruppen-Selectbox aus dem Formular und der
        // nächste Submit löscht alle Gruppenzugehörigkeiten (#123).
        $db = Database::getInstance();
        $assignableGroups = $this->assignableGroups($db);
        $selectedGroupIds = array_map('intval', (array)($_POST['groups'] ?? []));

        // Selbstschutz: Der eingeloggte Admin darf sich nicht selbst die
        // `admin`-Gruppe entziehen - sonst könnte sich der letzte Administrator
        // versehentlich aussperren (#123). Steht VOR der Pruefung, weil die
        // Adresspflicht sich nach der Gruppenmenge richtet, die tatsaechlich
        // gespeichert wird - nicht nach der uebermittelten.
        $groupIds = $selectedGroupIds;
        if (!$fremdesKonto && $this->isAdmin()) {
            $adminGroupId = (int)$db->query("SELECT id FROM `groups` WHERE slug = 'admin'")->fetchColumn();
            if ($adminGroupId > 0 && !in_array($adminGroupId, $groupIds, true)) {
                $groupIds[] = $adminGroupId;
            }
        }

        // Vor dem Schreiben festhalten: Eine Adressaenderung muss offene
        // Mailcodes verwerfen (#354, siehe unten).
        $stmt = $db->prepare("SELECT email FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $adresseVorher = trim((string)($stmt->fetchColumn() ?: ''));

        $errors = LoginIdentifier::usernameErrors($username);
        if ($this->isReservedUsername($username)) {
            $errors[] = "Der Benutzername '{$username}' ist aus Sicherheitsgründen reserviert und darf nicht gewählt werden.";
        }
        // Adresspflicht nach Rechten (#348) - gegen die Gruppen, die gleich
        // gespeichert werden. Die Pruefung steht VOR jedem UPDATE: Ein Konto,
        // dem gerade das Bearbeitungsrecht gegeben wird, darf nicht zuerst
        // ohne Adresse gespeichert und danach abgelehnt werden.
        $errors = array_merge($errors, $this->emailFehler($db, $email, $groupIds));
        $errors = array_merge($errors, $this->vergebenFehler($db, $id, $username, $email));

        if ($errors !== []) {
            $this->render('admin_user_form', [
                'title' => 'Benutzer bearbeiten',
                'user' => ['id' => $id, 'username' => $username, 'email' => $email],
                'errors' => $errors,
                'assignableGroups' => $assignableGroups,
                'userGroupIds' => $selectedGroupIds
            ]);
            return;
        }

        if (!empty($password)) {
            if (strlen($password) < 8) {
                $this->render('admin_user_form', [
                    'title' => 'Benutzer bearbeiten',
                    'user' => ['id' => $id, 'username' => $username, 'email' => $email],
                    'errors' => ['Das Passwort muss mindestens 8 Zeichen lang sein.'],
                    'assignableGroups' => $assignableGroups,
                    'userGroupIds' => $selectedGroupIds
                ]);
                return;
            }
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $offenerAntrag = KontoSicherheit::offenerAdressantrag($id);
            // session_version erhöhen: Bestehende Sessions des Benutzers werden
            // durch die Admin-Passwortänderung beendet (#113, siehe
            // BaseController::checkAuth()).
            //
            // must_change_password bei einem FREMDEN Konto (Audit N13): Das
            // Passwort kennt jetzt die Verwaltung - und wer den Zettel sieht,
            // auf dem es weitergegeben wird. So war es dokumentiert
            // (docs/database.md), umgesetzt war es nicht. Der Zwang greift
            // nach dem zweiten Faktor (LoginSession::establish()). Beim
            // eigenen Konto des Admins entfällt er.
            //
            // Ein offener Adressantrag endet im selben Statement (Audit M16).
            $stmt = $db->prepare(
                "UPDATE users SET username = ?, email = ?, password_hash = ?,
                        session_version = session_version + 1,
                        " . ($fremdesKonto ? 'must_change_password = 1, ' : '') . KontoSicherheit::ADRESSANTRAG_LEEREN . "
                 WHERE id = ?"
            );
            // Leere Eingabe = keine Adresse (NULL), nicht Leerstring - siehe store().
            if (!$this->speichern($stmt, [$username, $email === '' ? null : $email, $passwordHash, $id])) {
                $this->formularFehler($id, $username, $email, $assignableGroups, $selectedGroupIds);
                return;
            }

            // Offene Mailcodes und alle API-Schlüssel des Kontos (#354,
            // #217): Das Neusetzen des Passworts durch einen Admin ist die
            // typische Incident-Response - neben den Sessions
            // (session_version, oben) dürfen weder ein Code, der schon
            // unterwegs ist, noch zuvor angelegte Schlüssel den Reset
            // überleben. Die session_version-Kopplung in
            // ApiKey::authenticate() lehnt Schlüssel bereits implizit ab;
            // der Widerruf macht das dauerhaft und sichtbar (revoked_at).
            // Gilt bewusst auch für das eigene Admin-Konto - nur die Session
            // bleibt erhalten (siehe unten), Schlüssel sind neu anzulegen.
            $revokedKeys = KontoSicherheit::nachPasswortwechsel($id, $offenerAntrag);
            if ($revokedKeys > 0) {
                \App\Service\AuditLogger::log(
                    "API-Schlüssel widerrufen (Passwort neu gesetzt)",
                    "security",
                    "{$revokedKeys} aktive(r) API-Schlüssel von Benutzer ID {$id} nach Passwort-Neusetzung durch Admin widerrufen"
                );
            }
            if ($fremdesKonto) {
                \App\Service\AuditLogger::log(
                    "Passwort durch Verwaltung neu gesetzt",
                    "users",
                    "Benutzer ID {$id}: Wechsel bei nächster Anmeldung erzwungen"
                );
            }

            // Ändert der Admin das eigene Passwort, übernimmt seine gerade
            // aktive Session den neuen Stand und bleibt angemeldet.
            if (!$fremdesKonto) {
                $stmt = $db->prepare("SELECT session_version FROM users WHERE id = ?");
                $stmt->execute([$id]);
                $_SESSION['session_version'] = (int)$stmt->fetchColumn();
            }
        } else {
            $stmt = $db->prepare("UPDATE users SET username = ?, email = ? WHERE id = ?");
            if (!$this->speichern($stmt, [$username, $email === '' ? null : $email, $id])) {
                $this->formularFehler($id, $username, $email, $assignableGroups, $selectedGroupIds);
                return;
            }
        }

        // Adresse entfernt heisst: kein Mailcode-Faktor mehr (#354). Ohne das
        // stuende `email_2fa_enabled = 1` neben `email IS NULL` - ein Konto,
        // dessen zweiter Faktor an eine Adresse geht, die es nicht mehr gibt.
        // Die Anmeldung wuerde einen Code erzeugen, den niemand bekommt.
        if ($email === '') {
            $stmt = $db->prepare("UPDATE users SET email_2fa_enabled = 0 WHERE id = ?");
            $stmt->execute([$id]);
        }

        // Bei JEDER Adressaenderung offene Codes verwerfen (#354) - nicht nur
        // beim Entfernen und nicht nur, wenn zugleich ein Passwort gesetzt
        // wurde. Der Fall, den das trifft: Ein uebernommenes Postfach, ein
        // bereits ausgeloester Anmeldecode darin, und der Admin traegt die
        // Adresse um. Ohne das Verwerfen bleibt der Code im ALTEN Postfach
        // zehn Minuten lang gueltig. ProfileController::confirmNewEmail()
        // macht es aus derselben Begruendung.
        //
        // Ebenso einen offenen Adressantrag (Audit M16): Traegt die
        // Verwaltung die Adresse bei einem Verdacht um, darf ein vom
        // Angreifer gestellter Antrag danach nicht mehr bestaetigt werden.
        if ($email !== $adresseVorher) {
            \App\Security\EmailSecondFactor::discard($id);
            KontoSicherheit::adressantragVerwerfen($id, 'Adressänderung durch Verwaltung');
        }

        $this->syncUserGroups($db, $id, $groupIds);

        \App\Service\AuditLogger::log("Benutzer aktualisiert", "users", "Benutzer ID {$id}: {$username} ({$email})");

        // If updating self, keep the displayed username in sync
        if (!$fremdesKonto) {
            $_SESSION['username'] = $username;
        }

        header("Location: /admin/users?success=updated");
        exit;
    }

    /**
     * POST /admin/users/reactivate - eine Deaktivierung aufheben (#358).
     *
     * Der Fristanker wird dabei mit zurückgesetzt (siehe
     * DormantAccountService::reactivate()). Ohne das deaktivierte der nächste
     * Nachtlauf dasselbe Konto sofort wieder, und der Knopf hier wäre eine
     * Attrappe.
     */
    public function reactivate(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            header("Location: /admin/users?error=unknown_user");
            exit;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT username, deactivated_reason FROM users WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$id]);
        $konto = $stmt->fetch();

        if (!$konto || !\App\Service\DormantAccountService::reactivate($id)) {
            header("Location: /admin/users?error=unknown_user");
            exit;
        }

        \App\Service\AuditLogger::log(
            'Konto wieder eingeschaltet',
            'users',
            sprintf('%s (vorheriger Grund: %s)', $konto['username'], $konto['deactivated_reason'] ?? '-')
        );

        header("Location: /admin/users?success=reactivated");
        exit;
    }

    public function delete(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $id = $_POST['id'] ?? null;

        // Selbstlöschung wird nicht ausgeführt - und sagt das jetzt auch
        // (#228): Vorher lief der Versuch still in den success=deleted-
        // Redirect, der Admin sah "erfolgreich", obwohl nichts geschah.
        if ($id && $id == $_SESSION['user_id']) {
            header("Location: /admin/users?error=self_delete");
            exit;
        }

        if ($id) {
            $db = Database::getInstance();
            $stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $deletedUsername = $stmt->fetchColumn() ?: "ID {$id}";

            $stmt = $db->prepare("UPDATE users SET deleted_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);

            \App\Service\AuditLogger::log("Benutzer in Papierkorb verschoben", "users", "Benutzer: {$deletedUsername} (ID: {$id})");
        }

        header("Location: /admin/users?success=deleted");
        exit;
    }

    /**
     * POST /admin/users/reset-2fa - ALLE zweiten Faktoren zurücksetzen.
     *
     * Alle, nicht nur TOTP (#354) und seit Audit N60 auch die Passkeys. Wer
     * "2FA zurücksetzen" drückt, will den Benutzer wieder hereinlassen - ein
     * übrig gebliebener Faktor liesse ihn genau davor stehen, jetzt ohne
     * Backup-Codes, und ein gestohlener Passkey bliebe gültig.
     *
     * Der Reset beendet ausserdem alle Sitzungen und widerruft die
     * API-Schlüssel (Entscheidung D07): Danach ist das Konto faktorlos, und
     * eine noch lebende Sitzung - etwa auf dem gestohlenen Gerät - könnte
     * sonst ohne jeden Nachweis sofort einen eigenen Faktor einrichten.
     * Siehe KontoSicherheit::zweiteFaktorenZuruecksetzen().
     */
    public function reset2fa(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            header("Location: /admin/users");
            exit;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT username, email FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $ziel = $stmt->fetch();
        if (!$ziel) {
            header("Location: /admin/users?error=unknown_user");
            exit;
        }
        $targetUsername = (string)$ziel['username'];

        $ergebnis = KontoSicherheit::zweiteFaktorenZuruecksetzen($id);

        // Das eigene Konto: Die eigene Sitzung übernimmt den neuen Stand -
        // wie beim Neusetzen des eigenen Passworts in update().
        if ($id === (int)($_SESSION['user_id'] ?? 0)) {
            $stmt = $db->prepare("SELECT session_version FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['session_version'] = (int)$stmt->fetchColumn();
        }

        \App\Service\AuditLogger::log(
            "2FA zurückgesetzt",
            "users",
            sprintf(
                'Alle zweiten Faktoren (App, Mailcode, %d Passkey(s)) für Benutzer %s (ID: %d) durch Admin '
                . 'zurückgesetzt; Sitzungen beendet, %d API-Schlüssel widerrufen',
                $ergebnis['passkeys'],
                $targetUsername,
                $id,
                $ergebnis['api_schluessel']
            )
        );

        KontoSicherheit::hinweisSenden(
            $id,
            'Ihre Zwei-Faktor-Anmeldung wurde zurückgesetzt',
            sprintf(
                'Die Verwaltung hat am %s alle zweiten Faktoren Ihres Kontos zurückgesetzt '
                . '(Authentikator-App, Mailcode und Passkeys). Alle Sitzungen wurden beendet und Ihre '
                . 'API-Schlüssel widerrufen. Bei der nächsten Anmeldung richten Sie Ihren zweiten Faktor neu ein.',
                date('d.m.Y \u\m H:i')
            ),
            (string)($ziel['email'] ?? '')
        );

        header("Location: /admin/users?success=2fa_reset");
        exit;
    }

    /**
     * Widerruft alle aktiven API-Schlüssel eines Kontos auf einen Schlag
     * (POST /admin/users/revoke-api-keys, #217). Bewusst HIER und nicht im
     * ApiKeyController: Der ist reiner Selfservice für die EIGENEN Schlüssel -
     * die fremdverwaltende Aktion gehört in den admin-geschützten
     * Benutzer-Kontext (checkAuth() + requireAdmin() im Konstruktor), direkt
     * neben die Passwort-Neusetzung, deren Incident-Response sie ergänzt.
     * Kein Einzel-Widerruf: Bei einem Kompromittierungsverdacht ist "alle weg,
     * der Benutzer legt sich neue an" der sichere und erklärbare Zustand.
     */
    public function revokeApiKeys(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            header("Location: /admin/users");
            exit;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $targetUsername = $stmt->fetchColumn() ?: "ID {$id}";

        $revokedKeys = \App\Security\ApiKey::revokeAllForUser($id);
        // Auch "0 widerrufen" wird protokolliert: Die Aktion ist eine bewusste
        // Sicherheitsmaßnahme, ihr Ergebnis gehört nachvollziehbar ins Log.
        \App\Service\AuditLogger::log(
            "API-Schlüssel widerrufen (Admin)",
            "security",
            "{$revokedKeys} aktive(r) API-Schlüssel von Benutzer {$targetUsername} (ID: {$id}) durch Admin widerrufen"
        );

        header("Location: /admin/users/edit?id={$id}&success=api_keys_revoked");
        exit;
    }

    /**
     * Benutzername oder Adresse schon bei einem ANDEREN Konto (Audit N52)?
     *
     * Ausdruecklich einschliesslich Papierkorb: Die UNIQUE-Indizes umfassen
     * auch geloeschte Konten. Bisher lief das UPDATE in den Duplikatsfehler
     * und endete mit HTTP 500.
     *
     * @return array<int, string>
     */
    private function vergebenFehler(\PDO $db, int $id, string $username, string $email): array {
        $stmt = $db->prepare(
            "SELECT username, email FROM users
             WHERE id <> ? AND (username = ? OR (? <> '' AND email = ?))"
        );
        $stmt->execute([$id, $username, $email, $email]);

        // Vergleich wie die Datenbank-Kollation: ohne Gross-/Kleinschreibung.
        $gleich = static fn(string $a, string $b): bool => mb_strtolower($a, 'UTF-8') === mb_strtolower($b, 'UTF-8');
        $fehler = [];
        foreach ($stmt->fetchAll() as $zeile) {
            if ($gleich((string)$zeile['username'], $username)) {
                $fehler['username'] = 'Der Benutzername ist bereits vergeben (auch Konten im Papierkorb zählen).';
            }
            if ($email !== '' && $gleich((string)($zeile['email'] ?? ''), $email)) {
                $fehler['email'] = 'Die E-Mail-Adresse ist bereits vergeben (auch Konten im Papierkorb zählen).';
            }
        }
        return array_values($fehler);
    }

    /**
     * Führt ein UPDATE auf `users` aus. Ein Duplikatsfehler (1062) ist ein
     * Formularfehler, kein Absturz - die Vorprüfung oben sieht ein
     * Wettrennen zweier gleichzeitiger Änderungen nicht.
     *
     * @param array<int, mixed> $werte
     */
    private function speichern(\PDOStatement $stmt, array $werte): bool {
        try {
            $stmt->execute($werte);
            return true;
        } catch (\PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $assignableGroups
     * @param array<int, int> $selectedGroupIds
     */
    private function formularFehler(int $id, string $username, string $email, array $assignableGroups, array $selectedGroupIds): void {
        $this->render('admin_user_form', [
            'title' => 'Benutzer bearbeiten',
            'user' => ['id' => $id, 'username' => $username, 'email' => $email],
            'errors' => ['E-Mail oder Benutzername bereits vergeben.'],
            'assignableGroups' => $assignableGroups,
            'userGroupIds' => $selectedGroupIds
        ]);
    }

    /**
     * Pruefung der Adresse beim Anlegen und Aendern eines Kontos (#348).
     *
     * Zwei verschiedene Fehler, die gern verwechselt werden: Eine ANGEGEBENE
     * Adresse muss gueltig sein - immer. Eine FEHLENDE ist nur dann ein
     * Fehler, wenn die Gruppen des Kontos mehr als Lesen erlauben.
     *
     * @param array<int, int> $groupIds Gruppen, die gespeichert werden sollen
     * @return array<int, string>
     */
    private function emailFehler(\PDO $db, string $email, array $groupIds): array {
        // Die Regel steht seit #384 in UserProvisioning - Anlegen und Aendern
        // muessen sich darueber einig sein, was eine gueltige Adresslage ist.
        return \App\Service\UserProvisioning::adressFehler($db, $email, $groupIds);
    }

    /**
     * Alle Gruppen, die einem Benutzer über `user_groups` zugewiesen werden
     * dürfen (#66) - jede Gruppe außer GroupController::NON_ASSIGNABLE_SLUGS
     * (`public` ist ausschließlich für nicht angemeldete Besucher gedacht).
     * Das schließt ausdrücklich die eingebauten Gruppen `admin` und `editor`
     * mit ein - Mitgliedschaft in JEDER Gruppe ist bewusst zuzuweisen, kein
     * automatischer Standard (siehe BaseController::userGroupIds()). `admin`
     * MUSS hier zuweisbar sein, sonst könnte nie ein Administrator angelegt
     * werden (siehe GroupController::PROTECTED_PERMISSION_SLUGS für den davon
     * unabhängigen Schutz ihrer Berechtigungs-Matrix).
     *
     * @return array<int, array{id:int, name:string, is_builtin:int}>
     */
    private function assignableGroups(\PDO $db): array {
        $nonAssignable = \App\Controllers\GroupController::NON_ASSIGNABLE_SLUGS;
        $placeholders = implode(',', array_fill(0, count($nonAssignable), '?'));
        $stmt = $db->prepare("SELECT id, name, is_builtin FROM `groups` WHERE slug NOT IN ({$placeholders}) ORDER BY is_builtin DESC, name ASC");
        $stmt->execute($nonAssignable);
        return $stmt->fetchAll();
    }

    /**
     * Gleicht die Gruppen eines Benutzers mit der übermittelten Auswahl ab
     * (#66, siehe docs/user-groups-plan.md). Mitgliedschaft ist für JEDE
     * Gruppe ausschließlich explizit über `user_groups` (siehe
     * BaseController::userGroupIds()) - hier werden bewusst nur
     * assignableGroups() akzeptiert, damit eine manipulierte Anfrage niemals
     * `public` über user_groups zuweisen kann (`admin` ist hier absichtlich
     * NICHT ausgeschlossen, siehe assignableGroups()).
     *
     * @param array<int, mixed> $groupIds
     */
    private function syncUserGroups(\PDO $db, int $userId, array $groupIds): void {
        $stmt = $db->prepare("DELETE FROM user_groups WHERE user_id = ?");
        $stmt->execute([$userId]);

        $groupIds = array_map('intval', $groupIds);
        $groupIds = array_filter($groupIds, fn($id) => $id > 0);
        if (empty($groupIds)) {
            return;
        }

        $nonAssignable = \App\Controllers\GroupController::NON_ASSIGNABLE_SLUGS;
        $groupPlaceholders = implode(',', array_fill(0, count($groupIds), '?'));
        $nonAssignablePlaceholders = implode(',', array_fill(0, count($nonAssignable), '?'));
        $stmt = $db->prepare("SELECT id FROM `groups` WHERE slug NOT IN ({$nonAssignablePlaceholders}) AND id IN ({$groupPlaceholders})");
        $stmt->execute(array_merge($nonAssignable, array_values($groupIds)));
        $validIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if (empty($validIds)) {
            return;
        }

        $insertStmt = $db->prepare("INSERT IGNORE INTO user_groups (user_id, group_id) VALUES (?, ?)");
        foreach ($validIds as $groupId) {
            $insertStmt->execute([$userId, (int)$groupId]);
        }
    }
}
