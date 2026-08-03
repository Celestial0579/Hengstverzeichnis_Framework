<?php
// src/Controllers/BaseController.php

namespace App\Controllers;

use App\Database;
use PDO;

/**
 * Class BaseController
 * 
 * Abstrakter Basis-Controller für alle Controller-Klassen der Anwendung.
 * Stellt globale Funktionalitäten bereit:
 * - Automatische Laden von Verbands- & Systemeinstellungen
 * - Sicheres Rendern von View-Templates inkl. Layout
 * - Anti-Infostealer & Session-Fingerprint Authentifizierungsprüfung (`checkAuth()`)
 * - Rollen-basierte Rechteprüfung (`requireAdmin()`)
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
     * Basis-Konstruktor. Lädt automatisch alle Einstellungen aus der Datenbank.
     */
    public function __construct() {
        $this->loadSettings();
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
     */
    protected function checkAuth(): void {
        if (!isset($_SESSION['user_id'])) {
            header("Location: /login");
            exit;
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
     * Stellt sicher, dass der angemeldete Benutzer Administrator-Rechte besitzt.
     * Bricht andernfalls mit einer protokollierten 403-Forbidden Seite ab.
     */
    protected function requireAdmin(): void {
        if (($_SESSION['role'] ?? '') !== 'admin') {
            $this->renderForbidden("Zugriff verweigert: Diese Funktion steht ausschließlich Administratoren zur Verfügung.");
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
            'title' => '403 - Zugriff verweigert',
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
            'title' => '404 - Seite nicht gefunden',
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
            'title' => '500 - Serverfehler',
            'message' => $message
        ]);
        exit;
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
}
