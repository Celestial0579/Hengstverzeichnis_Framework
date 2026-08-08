<?php
// src/Controllers/ApiKeyController.php

namespace App\Controllers;

use App\Permission\PermissionRegistry;
use App\Router;
use App\Security\ApiKey;

/**
 * Class ApiKeyController
 *
 * Selfservice-Verwaltung eigener API-Schlüssel (`/api-keys`) - jeder
 * angemeldete Benutzer verwaltet ausschließlich seine eigenen Schlüssel, es
 * gibt bewusst keine fremdverwaltende Admin-Ansicht (ein Schlüssel ist an die
 * Rechte genau eines Benutzers gebunden, siehe App\Security\ApiKey).
 *
 * Kein requirePermission(): das Anlegen eines Schlüssels verleiht keinerlei
 * zusätzliche Rechte, sondern höchstens eine Teilmenge der bereits vorhandenen
 * (Schnittmenge, siehe ApiKey::permits()). Die Schranke ist daher checkAuth()
 * - wer sich anmelden kann, darf einen Schlüssel für genau das erzeugen, was
 * er ohnehin schon darf.
 */
class ApiKeyController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
    }

    public function index(): void {
        $userId = (int)($_SESSION['user_id'] ?? 0);

        // Nach dem Anlegen wird der Klartext-Schlüssel EINMALIG über die
        // Session weitergereicht (Post/Redirect/Get) und sofort verworfen -
        // so landet er weder in der URL noch in der Datenbank und erscheint
        // nach einem Reload nicht erneut.
        $newToken = $_SESSION['api_key_new_token'] ?? null;
        unset($_SESSION['api_key_new_token']);

        $this->render('api_keys', [
            'title' => 'API-Schlüssel',
            'keys' => ApiKey::forUser($userId),
            'availableScope' => $this->scopeChoices($userId),
            'maxKeys' => ApiKey::MAX_KEYS_PER_USER,
            'newToken' => $newToken,
            'error' => $_GET['error'] ?? null,
            'success' => $_GET['success'] ?? null,
        ]);
    }

    public function create(): void {
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $label = trim($_POST['label'] ?? '');

        // "Alle meine Rechte" (scope = null) vs. bewusste Einschränkung auf
        // ausgewählte Paare. ApiKey::create() prüft zusätzlich serverseitig,
        // dass jeder gewählte Eintrag durch ein Recht des Besitzers gedeckt ist.
        $scope = null;
        if (($_POST['scope_mode'] ?? 'all') === 'custom') {
            $scope = array_values(array_filter(
                (array)($_POST['scope'] ?? []),
                'is_string'
            ));
        }

        $result = ApiKey::create($userId, $label, $scope);

        if (!$result['ok']) {
            header('Location: /api-keys?error=' . urlencode((string)$result['error']));
            exit;
        }

        \App\Service\AuditLogger::log(
            'API-Schlüssel erstellt',
            'security',
            'Bezeichnung: ' . $label . ' (Rechte: ' . ($scope === null ? 'alle eigenen' : implode(', ', $scope)) . ')'
        );

        $_SESSION['api_key_new_token'] = $result['token'];
        header('Location: /api-keys?success=created');
        exit;
    }

    public function revoke(): void {
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $keyId = (int)($_POST['id'] ?? 0);

        // revoke() filtert selbst auf user_id - ein fremder Schlüssel lässt
        // sich darüber nicht widerrufen (IDOR-Schutz).
        if ($keyId > 0 && ApiKey::revoke($userId, $keyId)) {
            \App\Service\AuditLogger::log('API-Schlüssel widerrufen', 'security', 'Schlüssel-ID: ' . $keyId);
            header('Location: /api-keys?success=revoked');
            exit;
        }

        header('Location: /api-keys?error=revoke_failed');
        exit;
    }

    /**
     * Auswahlliste für den Scope: ausschließlich Modul × Aktion-Paare, die der
     * Benutzer aktuell selbst besitzt - inklusive lesbarer Beschriftungen aus
     * der PermissionRegistry.
     *
     * @return array<int, array{key: string, label: string}>
     */
    private function scopeChoices(int $userId): array {
        $modules = PermissionRegistry::modules();
        $choices = [];

        foreach (ApiKey::availableScopeEntries($userId) as $entry) {
            [$module, $action] = explode('.', $entry, 2);
            $moduleLabel = $modules[$module]['label'] ?? $module;
            $actionLabel = $modules[$module]['actions'][$action] ?? $action;
            $choices[] = [
                'key' => $entry,
                'label' => $moduleLabel . ' → ' . $actionLabel,
            ];
        }

        return $choices;
    }
}
