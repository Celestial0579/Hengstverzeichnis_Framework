<?php
// src/Controllers/BaseController.php

namespace App\Controllers;

use App\Database;
use PDO;

abstract class BaseController {
    
    protected array $settings = [];

    public function __construct() {
        $this->loadSettings();
    }

    /**
     * Loads all settings from the database into the $settings array.
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
            // If the database isn't set up yet, provide defaults to avoid fatal errors during setup
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
     * Renders a view file inside the main layout.
     * 
     * @param string $view Name of the view file (without .php)
     * @param array $data Data to extract into the view scope
     */
    protected function render(string $view, array $data = []): void {
        // Extract data to make variables available in the view
        extract($data);
        
        // Make settings available to all views automatically
        $settings = $this->settings;

        // Capture the output of the specific view
        ob_start();
        $viewFile = __DIR__ . "/../Views/{$view}.php";
        if (file_exists($viewFile)) {
            require $viewFile;
        } else {
            echo "View '{$view}' not found.";
        }
        $content = ob_get_clean();

        // Include the main layout which will output the captured $content
        require __DIR__ . "/../Views/layout.php";
    }

    /**
     * Verifies authentication, validates anti-infostealer session fingerprint, handles inactivity timeout and forces password change if required.
     */
    protected function checkAuth(): void {
        if (!isset($_SESSION['user_id'])) {
            header("Location: /login");
            exit;
        }

        // 1. Anti-Infostealer & Anti-Session-Hijacking: User-Agent Fingerprint Validation
        $currentAgentHash = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
        if (isset($_SESSION['user_agent_hash']) && !hash_equals($_SESSION['user_agent_hash'], $currentAgentHash)) {
            // Mismatched User-Agent (Stolen session cookie transferred to another browser/machine)
            \App\Service\AuditLogger::log("Session-Hijacking Versuch abgefangen", "auth", "User-Agent Mismatch für User ID " . $_SESSION['user_id']);
            
            $_SESSION = [];
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
            }
            session_destroy();
            header("Location: /login?error=session_hijacked");
            exit;
        }

        // 2. Inactivity Timeout (2 Hours = 7200 seconds)
        $now = time();
        $maxInactivity = 7200;
        if (isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity'] > $maxInactivity)) {
            \App\Service\AuditLogger::log("Session wegen Inaktivität beendet", "auth", "User ID " . $_SESSION['user_id']);
            
            $_SESSION = [];
            session_destroy();
            header("Location: /login?error=session_expired");
            exit;
        }
        $_SESSION['last_activity'] = $now;

        // 3. Periodic Session Token Rotation (Regenerate ID every 15 minutes = 900 seconds)
        if (!isset($_SESSION['last_token_rotation'])) {
            $_SESSION['last_token_rotation'] = $now;
        } elseif ($now - $_SESSION['last_token_rotation'] > 900) {
            session_regenerate_id(true);
            $_SESSION['last_token_rotation'] = $now;
        }

        // 4. Force Password Change check
        if (!empty($_SESSION['must_change_password'])) {
            $uri = $_SERVER['REQUEST_URI'] ?? '';
            if (strpos($uri, '/force-password-change') === false && strpos($uri, '/logout') === false) {
                header("Location: /force-password-change");
                exit;
            }
        }
    }

    /**
     * Ensures current logged in user has admin role, otherwise aborts with 403 Forbidden.
     */
    protected function requireAdmin(): void {
        if (($_SESSION['role'] ?? '') !== 'admin') {
            $this->renderForbidden("Zugriff verweigert: Diese Funktion steht nur Administratoren zur Verfügung.");
        }
    }

    /**
     * Renders a custom 403 Forbidden error page and logs the permission security event in AuditLog.
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
     * Renders a custom 404 Not Found error page.
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
     * Renders a custom 500 Internal Server Error page.
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
     * Checks if a username is in the list of reserved/privileged system verbs.
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
