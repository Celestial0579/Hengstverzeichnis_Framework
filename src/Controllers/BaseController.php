<?php
// src/Controllers/BaseController.php

namespace App\Controllers;

use App\Database;

/**
 * Class BaseController
 *
 * Abstrakter Basis-Controller für alle Controller-Klassen der Anwendung.
 * Stellt globale Funktionalitäten bereit:
 * - Automatische Laden von Verbands- & Systemeinstellungen
 * - Sicheres Rendern von View-Templates inkl. Layout
 * - Anti-Infostealer & Session-Fingerprint Authentifizierungsprüfung (`checkAuth()`)
 * - Gruppen-basierte Rechteprüfung (`requireAdmin()`, `hasPermission()`)
 * - Individuelle Fehlerseiten (403 Forbidden, 404 Not Found, 500 Server Error)
 * - Validierung reservierter System-Benutzernamen
 */
abstract class BaseController {
    
    /**
     * Aus der Datenbank geladene globale Systemeinstellungen.
     * @var array
     */
    protected array $settings = [];

    /**
     * Request-lokaler Cache der Gruppen-IDs des aktuellen Benutzers (#66).
     * @var array<int, int>|null
     */
    private ?array $groupIdsCache = null;

    /**
     * Request-lokaler Cache, ob der aktuelle Benutzer Mitglied der Gruppe
     * `admin` ist (siehe isAdmin()).
     * @var bool|null
     */
    private ?bool $isAdminCache = null;

    /**
     * Basis-Konstruktor. Lädt automatisch alle Einstellungen aus der Datenbank.
     */
    public function __construct() {
        $this->loadSettings();
        $this->initLocale();
    }

    /**
     * Lädt alle Key-Value Einstellungen aus der `settings`-Tabelle.
     * Stellt Standardwerte bereit, falls die Datenbank noch nicht initialisiert wurde.
     */
    private function loadSettings(): void {
        try {
            $db = Database::getInstance();
            $stmt = $db->query("SELECT setting_key, setting_value FROM settings");
            $rows = $stmt->fetchAll();
            
            foreach ($rows as $row) {
                $this->settings[$row['setting_key']] = $row['setting_value'];
            }
        } catch (\Exception $e) {
            // Im Setup-Modus oder bei nicht existierender Datenbank Fallback-Werte nutzen
            $this->settings = [
                'site_name' => 'Hengstverzeichnis (Setup Mode)',
                'primary_color' => '#2c3e50',
                'secondary_color' => '#18bc9c',
                'site_logo' => '',
                'logo_url' => ''
            ];
        }
    }

    /**
     * Setzt die aktive Sprache für den restlichen Request (#48, siehe
     * App\I18n\Translator). Reihenfolge: admin-konfigurierte Standardsprache
     * (`settings.language`, siehe AdminController::updateSystemSettings())
     * als Basis, per-Session-Übersteuerung über `?lang=xx` für Besucher, die
     * diese nicht sprechen - bewusst in der Session statt nur im Query-Param,
     * damit die Wahl über die gesamte weitere Navigation erhalten bleibt.
     * Ungültige/unbekannte Locale-Werte werden von Translator::init() selbst
     * sicher auf die Fallback-Sprache abgebildet.
     */
    private function initLocale(): void {
        $available = \App\I18n\Translator::getAvailableLocales();

        $requested = $_GET['lang'] ?? null;
        if (is_string($requested) && isset($available[$requested])) {
            $_SESSION['locale'] = $requested;
        }

        $locale = $_SESSION['locale'] ?? ($this->settings['language'] ?? 'de');
        \App\I18n\Translator::init((string)$locale);
    }

    /**
     * Rendert ein View-Template innerhalb des zentralen Haupt-Layouts (`src/Views/layout.php`).
     * 
     * @param string $view Name der View-Datei (ohne Endung .php)
     * @param array $data Variablen-Array, das in der View verfügbar gemacht wird
     */
    protected function render(string $view, array $data = []): void {
        // Variablen aus dem Data-Array für die View extrahieren
        extract($data);
        
        // Globale Einstellungen automatisch in jeder View bereitstellen
        $settings = $this->settings;

        // Inhalt der spezifischen View im Ausgabepuffer abfangen
        ob_start();
        $viewFile = __DIR__ . "/../Views/{$view}.php";
        if (file_exists($viewFile)) {
            require $viewFile;
        } else {
            echo "View '{$view}' nicht gefunden.";
        }
        $content = ob_get_clean();

        // Haupt-Layout rendern und den abgefangenen $content injizieren
        require __DIR__ . "/../Views/layout.php";
    }

    /**
     * Überprüft die Benutzeranmeldung, schützt vor Session-Hijacking (Anti-Infostealer)
     * erzwingt Inaktivitäts-Timeouts und prüft auf erforderliche Passwortänderungen.
     *
     * Bleibt die alleinige, vom Gruppen-/Berechtigungssystem (#66) unabhängige
     * Zugriffsschranke für den gesamten Backend-Bereich (/admin/...): Nicht
     * angemeldete Besucher ("public", siehe Gruppe `public` in der Tabelle `groups`)
     * erreichen keine geschützte Controller-Methode, unabhängig davon, ob/welche
     * Berechtigungen aktuell in group_permissions stehen. Die Gruppe `public`
     * erhält zusätzlich nie eigene Berechtigungs-Zeilen (siehe
     * GroupController::updatePermissions() und BaseController::userGroupIds()) -
     * beide Mechanismen wirken unabhängig voneinander, keiner ersetzt den anderen.
     */
    protected function checkAuth(): void {
        if (!isset($_SESSION['user_id'])) {
            header("Location: /login");
            exit;
        }

        // 0. Live-Abgleich mit der Datenbank: Ohne diesen Check bliebe einem Benutzer,
        // dessen Account gelöscht/deaktiviert wurde, der volle Zugriff über seine
        // bestehende Session erhalten - potenziell zeitlich unbegrenzt, da
        // last_activity bei jedem Request erneuert wird (siehe unten, Punkt 2).
        // Berechtigungen selbst (inkl. Admin-Status) werden NICHT in der Session
        // gehalten, sondern bei jedem Aufruf live über GroupMembership/
        // hasPermission() geprüft (#66) - Rechteänderungen wirken so sofort, ohne
        // erneuten Login. Fail-open bei DB-Fehlern (Ausfallsicherheit, wie auch bei
        // RateLimiter).
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("SELECT deleted_at, session_version FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $currentUser = $stmt->fetch();

            if (!$currentUser || $currentUser['deleted_at'] !== null) {
                \App\Service\AuditLogger::log(
                    "Session beendet: Benutzerkonto gelöscht oder deaktiviert",
                    "auth",
                    "User ID " . $_SESSION['user_id']
                );

                $_SESSION = [];
                if (ini_get("session.use_cookies")) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
                }
                session_destroy();
                header("Location: /login?error=account_disabled");
                exit;
            }

            // Session-Invalidierung bei Passwortänderung (#113): session_version
            // wird bei jeder Passwortänderung erhöht (Reset per Mail-Token,
            // erzwungener Wechsel, Admin-Änderung). Sessions, deren beim Login
            // gemerkter Stand nicht mehr passt, werden beendet - eine von einem
            // Angreifer gehaltene Alt-Session überlebt den Passwort-Reset des
            // Opfers so nicht mehr. Sessions ohne gemerkten Stand (Login vor
            // diesem Feature) gelten ebenfalls als veraltet.
            $dbVersion = (int)($currentUser['session_version'] ?? 1);
            $sessionVersion = $_SESSION['session_version'] ?? null;
            if ($sessionVersion === null || (int)$sessionVersion !== $dbVersion) {
                \App\Service\AuditLogger::log(
                    "Session beendet: Passwort wurde geändert",
                    "auth",
                    "User ID " . $_SESSION['user_id']
                );

                $_SESSION = [];
                if (ini_get("session.use_cookies")) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
                }
                session_destroy();
                header("Location: /login?error=session_expired");
                exit;
            }
        } catch (\Throwable $e) {
            // DB-Fehler dürfen bereits eingeloggte Nutzer nicht aussperren
        }

        // 1. Anti-Infostealer & Session-Hijacking Schutz: User-Agent Fingerprint Validierung
        $currentAgentHash = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
        if (isset($_SESSION['user_agent_hash']) && !hash_equals($_SESSION['user_agent_hash'], $currentAgentHash)) {
            // Abweichender User-Agent (Session-Cookie auf anderen Browser/Rechner übertragen)
            \App\Service\AuditLogger::log(
                "Session-Hijacking Versuch abgefangen",
                "auth",
                "User-Agent Mismatch für User ID " . $_SESSION['user_id']
            );
            
            $_SESSION = [];
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
            }
            session_destroy();
            header("Location: /login?error=session_hijacked");
            exit;
        }

        // 2. Inaktivitäts-Timeout (2 Stunden = 7200 Sekunden)
        $now = time();
        $maxInactivity = 7200;
        if (isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity'] > $maxInactivity)) {
            \App\Service\AuditLogger::log(
                "Session wegen Inaktivität beendet",
                "auth",
                "User ID " . $_SESSION['user_id']
            );
            
            $_SESSION = [];
            session_destroy();
            header("Location: /login?error=session_expired");
            exit;
        }
        $_SESSION['last_activity'] = $now;

        // 3. Periodische Session-ID Rotation (Sicherheits-Regenerierung alle 15 Minuten)
        if (!isset($_SESSION['last_token_rotation'])) {
            $_SESSION['last_token_rotation'] = $now;
        } elseif ($now - $_SESSION['last_token_rotation'] > 900) {
            session_regenerate_id(true);
            $_SESSION['last_token_rotation'] = $now;
        }

        // 4. Zwang zur Passwortänderung prüfen (z. B. nach Admin-Passwort-Reset)
        if (!empty($_SESSION['must_change_password'])) {
            $uri = $_SERVER['REQUEST_URI'] ?? '';
            if (strpos($uri, '/force-password-change') === false && strpos($uri, '/logout') === false) {
                header("Location: /force-password-change");
                exit;
            }
        }
    }

    /**
     * Stellt sicher, dass der angemeldete Benutzer Administrator-Rechte besitzt
     * (Mitgliedschaft in der Gruppe `admin`, siehe isAdmin()). Bricht andernfalls
     * mit einer protokollierten 403-Forbidden Seite ab.
     */
    protected function requireAdmin(): void {
        if (!$this->isAdmin()) {
            $this->renderForbidden("Zugriff verweigert: Diese Funktion steht ausschließlich Administratoren zur Verfügung.");
        }
    }

    /**
     * Prüft, ob der aktuelle Benutzer Mitglied der eingebauten Gruppe `admin`
     * ist (#66) - die einzige Stelle mit besonderer Bedeutung im Gruppensystem:
     * Mitglieder haben systemseitig immer alle Rechte (siehe hasPermission())
     * und dürfen den kompletten Backend-Admin-Bereich nutzen (requireAdmin()).
     * Delegiert an App\Permission\GroupMembership, damit es dafür nur EINE
     * Implementierung im gesamten Code gibt (auch für Stellen ohne
     * Controller-Instanz, z. B. TrashController::getTrashCount()). Innerhalb
     * eines Requests gecacht, da mehrere requireAdmin()/hasPermission()-Aufrufe
     * pro Seite üblich sind.
     */
    protected function isAdmin(): bool {
        if ($this->isAdminCache === null) {
            $this->isAdminCache = \App\Permission\GroupMembership::isAdmin($_SESSION['user_id'] ?? null);
        }
        return $this->isAdminCache;
    }

    /**
     * Gruppen-Zugehörigkeit des aktuellen Session-Benutzers (#66, siehe
     * docs/user-groups-plan.md). Security-by-Design: Mitgliedschaft ist
     * ausschließlich explizit über `user_groups`. Jede Gruppe (auch `editor`)
     * ist eine ganz normale Gruppe: ein Benutzer ohne `user_groups`-Zeilen hat
     * keinerlei Rechte, genau wie `public`. Neue Gruppen/Benutzer erben so
     * standardmäßig nichts. Delegiert an App\Permission\GroupMembership (siehe
     * dort), innerhalb eines Requests gecacht, da mehrere
     * hasPermission()-Aufrufe pro Seite üblich sind.
     *
     * @return array<int, int> IDs aller Gruppen, denen der aktuelle Benutzer angehört
     */
    protected function userGroupIds(): array {
        if ($this->groupIdsCache === null) {
            $this->groupIdsCache = \App\Permission\GroupMembership::groupIds($_SESSION['user_id'] ?? null);
        }
        return $this->groupIdsCache;
    }

    /**
     * Prüft, ob der aktuelle Benutzer eine Berechtigung für ein Modul × Aktion aus
     * App\Permission\PermissionRegistry besitzt (#66). Reine Prüfung ohne
     * Seiten-Abbruch, z. B. um einen Button bedingt anzuzeigen - für den erzwingenden
     * Abbruch siehe requirePermission().
     *
     * Sicherheits-Leitplanken:
     * - `admin` hat IMMER alle Rechte, unabhängig vom Inhalt von group_permissions
     *   und nicht über die Admin-UI entziehbar (siehe docs/user-groups-plan.md,
     *   Abschnitt 8) - ihre eigene Berechtigungs-Matrix bleibt deshalb leer.
     * - Fail-closed: fehlt eine passende group_permissions-Zeile oder schlägt die
     *   DB-Abfrage fehl, wird der Zugriff verweigert, nie gewährt.
     */
    protected function hasPermission(string $module, string $action): bool {
        if ($this->isAdmin()) {
            return true;
        }

        $groupIds = $this->userGroupIds();
        if (empty($groupIds)) {
            return false;
        }

        try {
            $db = Database::getInstance();
            $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
            $stmt = $db->prepare("SELECT COUNT(*) FROM group_permissions WHERE module = ? AND action = ? AND group_id IN ({$placeholders})");
            $stmt->execute(array_merge([$module, $action], $groupIds));
            return (int)$stmt->fetchColumn() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Erzwingt eine Modul × Aktion-Berechtigung (#66) und bricht andernfalls mit einer
     * protokollierten 403-Seite ab - im selben Stil wie requireAdmin().
     */
    protected function requirePermission(string $module, string $action): void {
        if (!$this->hasPermission($module, $action)) {
            $this->renderForbidden("Zugriff verweigert: Für diese Aktion fehlt Ihnen die Berechtigung '{$action}' im Bereich '{$module}'.");
        }
    }

    /**
     * Rendert eine individuelle 403 Forbidden Fehlerseite und protokolliert das Sicherheitsereignis im Audit-Log.
     *
     * @param string $message Benutzerfreundliche Fehlermeldung
     */
    public function renderForbidden(string $message = 'Zugriff verweigert: Sie besitzen keine Berechtigung für diese Aktion.'): void {
        $userId = $_SESSION['user_id'] ?? null;
        $username = $_SESSION['username'] ?? 'GUEST';

        \App\Service\AuditLogger::log(
            "403 Forbidden - Zugriff verweigert",
            "security",
            $message . " [Pfad: " . ($_SERVER['REQUEST_URI'] ?? '') . "]",
            $userId,
            $username
        );

        http_response_code(403);
        $this->render('error_403', [
            'title' => \App\I18n\Translator::t('errors.403_title'),
            'message' => $message
        ]);
        exit;
    }

    /**
     * Rendert eine individuelle 404 Not Found Fehlerseite.
     *
     * @param string $message Benutzerfreundliche Fehlermeldung
     */
    public function renderNotFound(string $message = 'Die angeforderte Seite wurde nicht gefunden.'): void {
        http_response_code(404);
        $this->render('error_404', [
            'title' => \App\I18n\Translator::t('errors.404_title'),
            'message' => $message
        ]);
        exit;
    }

    /**
     * Rendert eine individuelle 500 Internal Server Error Fehlerseite.
     *
     * @param string $message Benutzerfreundliche Fehlermeldung
     */
    public function renderServerError(string $message = 'Ein unerwarteter Serverfehler ist aufgetreten.'): void {
        http_response_code(500);
        $this->render('error_500', [
            'title' => \App\I18n\Translator::t('errors.500_title'),
            'message' => $message
        ]);
        exit;
    }

    /**
     * Zugriff auf die zentrale Hook-/Filter-Registry des Plugin-Systems (#56).
     * Wird von Controllern genutzt, um definierten Erweiterungspunkten Plugins
     * die Möglichkeit zu geben, sich einzuklinken (siehe App\Plugin\HookManager
     * für die Sicherheits-Isolation pro Hook-Aufruf).
     */
    protected function hooks(): \App\Plugin\HookManager {
        return \App\Plugin\PluginManager::getInstance()->getHooks();
    }

    /**
     * Prüft, ob ein gewählter Benutzername in der Liste reservierter Systemnamen enthalten ist.
     *
     * @param string $username Zu prüfender Benutzername
     * @return bool True, wenn der Name reserviert ist, sonst false
     */
    protected function isReservedUsername(string $username): bool {
        $reserved = [
            'system', 'sys', 'sysadmin', 'systemadmin', 'system_admin',
            'admin', 'administrator', 'administrateur', 'superadmin', 'super_admin',
            'root', 'superuser', 'su',
            'support', 'help', 'helpdesk', 'service', 'info', 'webmaster', 'hostmaster', 'postmaster', 'security', 'abuse', 'contact',
            'api', 'bot', 'daemon', 'guest', 'test', 'testing', 'demo', 'null', 'undefined'
        ];
        
        return in_array(strtolower(trim($username)), $reserved, true);
    }

    /**
     * Normalisiert den Veröffentlichungs-Filter der Admin-Listen (?published=1|0).
     * Nur die exakten Werte '1' und '0' filtern; jeder andere/fehlende Wert bedeutet
     * "alle anzeigen" und liefert null. Der Rückgabewert ist bewusst ein Integer
     * (0/1), damit er ohne weitere Escaping-Sorgen direkt in eine WHERE-Klausel
     * interpoliert werden kann.
     */
    protected static function normalizePublishedFilter($value): ?int {
        if ($value === '1' || $value === 1) {
            return 1;
        }
        if ($value === '0' || $value === 0) {
            return 0;
        }
        return null;
    }

    /**
     * Baut das an eine Admin-Liste anzuhängende Query-Suffix, um den aktiven
     * Veröffentlichungs-Filter über einen Redirect (nach einer Bulk-Aktion) hinweg
     * zu erhalten - z. B. "&published=0" oder "" (kein Filter aktiv).
     */
    protected static function publishedFilterQuery($value): string {
        $filter = self::normalizePublishedFilter($value);
        return $filter === null ? '' : '&published=' . $filter;
    }
}
