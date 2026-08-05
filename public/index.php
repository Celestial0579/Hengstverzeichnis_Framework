<?php
// public/index.php

// Simple autoloader for our App namespace (vor config.php registriert, da diese
// bereits App\Security\ClientIp für die Reverse-Proxy-Erkennung benötigt)
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $base_dir = __DIR__ . '/../src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

require_once __DIR__ . '/../config/config.php';

use App\Router;
use App\Controllers\SetupController;
use App\Plugin\PluginManager;

// Plugin-System (#56): Scannt plugins/, lädt nur zuvor über /admin/plugins
// aktivierte Plugins. Muss vor der Routen-Registrierung laufen, damit
// Controller-Hooks (siehe BaseController::hooks()) und ggf. zusätzliche
// Plugin-Routen (siehe unten, nach den Kern-Routen) zur Verfügung stehen.
$pluginManager = PluginManager::getInstance();
$pluginManager->boot();

$router = new Router();

// Setup Routes
$router->get('/setup', [App\Controllers\SetupController::class, 'showSetup']);
$router->post('/setup', [App\Controllers\SetupController::class, 'processSetup']);

// Auto-redirect to /setup if no admin account exists
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$parsedPath = strtok($requestUri, '?');

if ($parsedPath !== '/setup' && SetupController::needsSetup()) {
    header("Location: /setup");
    exit;
}

// Define basic routes
$router->get('/', [App\Controllers\PublicController::class, 'index']);
$router->get('/katalog', [App\Controllers\PublicController::class, 'catalog']);
$router->get('/hengst', [App\Controllers\PublicController::class, 'horseDetail']); // Requires ?id=
$router->get('/station', [App\Controllers\PublicController::class, 'stationDetail']); // Requires ?id=

// Compliance Routes
$router->get('/impressum', [App\Controllers\PublicController::class, 'impressum']);
$router->get('/datenschutz', [App\Controllers\PublicController::class, 'datenschutz']);
$router->get('/dsgvo', [App\Controllers\PublicController::class, 'dsgvoForm']);
$router->post('/dsgvo', [App\Controllers\PublicController::class, 'dsgvoSubmit']);

// Authentication Routes
$router->get('/login', [App\Controllers\AuthController::class, 'loginForm']);
$router->post('/login', [App\Controllers\AuthController::class, 'loginSubmit']);
$router->post('/logout', [App\Controllers\AuthController::class, 'logout']);
$router->get('/forgot-password', [App\Controllers\AuthController::class, 'forgotPassword']);
$router->post('/forgot-password', [App\Controllers\AuthController::class, 'sendResetLink']);
$router->get('/reset-password', [App\Controllers\AuthController::class, 'resetPassword']);
$router->post('/reset-password', [App\Controllers\AuthController::class, 'updatePassword']);
$router->get('/force-password-change', [App\Controllers\AuthController::class, 'showForcePasswordChange']);
$router->post('/force-password-change', [App\Controllers\AuthController::class, 'processForcePasswordChange']);

// 2FA Routes
$router->get('/2fa/setup', [App\Controllers\AuthController::class, 'show2faSetup']);
$router->post('/2fa/enable', [App\Controllers\AuthController::class, 'enable2fa']);
$router->get('/2fa/verify', [App\Controllers\AuthController::class, 'show2faVerify']);
$router->post('/2fa/verify', [App\Controllers\AuthController::class, 'process2faVerify']);
$router->get('/login/2fa', [App\Controllers\AuthController::class, 'show2faVerify']);
$router->post('/login/2fa', [App\Controllers\AuthController::class, 'process2faVerify']);
$router->get('/2fa/backup', [App\Controllers\AuthController::class, 'showBackupCode']);
$router->post('/2fa/backup', [App\Controllers\AuthController::class, 'processBackupCode']);

// Admin Dashboard & Settings Routes
$router->get('/admin', [App\Controllers\AdminController::class, 'dashboard']);
$router->get('/admin/settings', [App\Controllers\AdminController::class, 'settings']);
$router->post('/admin/settings', [App\Controllers\AdminController::class, 'updateSettings']);
$router->get('/admin/system-settings', [App\Controllers\AdminController::class, 'systemSettings']);
$router->post('/admin/system-settings', [App\Controllers\AdminController::class, 'updateSystemSettings']);
$router->get('/admin/mail-settings', [App\Controllers\AdminController::class, 'mailSettings']);
$router->post('/admin/mail-settings', [App\Controllers\AdminController::class, 'updateMailSettings']);
$router->post('/admin/mail-settings/test', [App\Controllers\AdminController::class, 'testMail']);
$router->post('/admin/reset', [App\Controllers\AdminController::class, 'resetSystem']);

// Admin GDPR Management Routes
$router->get('/admin/gdpr', [App\Controllers\GdprController::class, 'index']);
$router->post('/admin/gdpr/update-status', [App\Controllers\GdprController::class, 'updateStatus']);
$router->post('/admin/gdpr/anonymize-person', [App\Controllers\GdprController::class, 'anonymizePerson']);
$router->post('/admin/gdpr/delete-person', [App\Controllers\GdprController::class, 'deletePerson']);

// Admin Trash / Papierkorb Routes
$router->get('/admin/trash', [App\Controllers\TrashController::class, 'index']);
$router->post('/admin/trash/restore', [App\Controllers\TrashController::class, 'restore']);
$router->post('/admin/trash/permanent-delete', [App\Controllers\TrashController::class, 'permanentDelete']);
$router->post('/admin/trash/empty', [App\Controllers\TrashController::class, 'emptyTrash']);

// Admin User Management Routes (Admin-Only)
$router->get('/admin/users', [App\Controllers\UserController::class, 'index']);
$router->get('/admin/users/create', [App\Controllers\UserController::class, 'create']);
$router->post('/admin/users/store', [App\Controllers\UserController::class, 'store']);
$router->get('/admin/users/edit', [App\Controllers\UserController::class, 'edit']);
$router->post('/admin/users/update', [App\Controllers\UserController::class, 'update']);
$router->post('/admin/users/delete', [App\Controllers\UserController::class, 'delete']);
$router->post('/admin/users/reset-2fa', [App\Controllers\UserController::class, 'reset2fa']);

// Admin Person Management Routes (Persons, Breeders, Owners)
$router->get('/admin/persons', [App\Controllers\PersonController::class, 'index']);
$router->get('/admin/persons/create', [App\Controllers\PersonController::class, 'create']);
$router->post('/admin/persons/store', [App\Controllers\PersonController::class, 'store']);
$router->get('/admin/persons/edit', [App\Controllers\PersonController::class, 'edit']);
$router->post('/admin/persons/update', [App\Controllers\PersonController::class, 'update']);
$router->post('/admin/persons/delete', [App\Controllers\PersonController::class, 'delete']);

// Admin Breeding Station Routes (Gestüte / Deckstationen)
$router->get('/admin/breeding-stations', [App\Controllers\BreedingStationController::class, 'index']);
$router->get('/admin/breeding-stations/create', [App\Controllers\BreedingStationController::class, 'create']);
$router->post('/admin/breeding-stations/store', [App\Controllers\BreedingStationController::class, 'store']);
$router->get('/admin/breeding-stations/edit', [App\Controllers\BreedingStationController::class, 'edit']);
$router->post('/admin/breeding-stations/update', [App\Controllers\BreedingStationController::class, 'update']);
$router->post('/admin/breeding-stations/delete', [App\Controllers\BreedingStationController::class, 'delete']);

// Admin Horse CRUD & Merge Tool Routes
$router->get('/admin/horses', [App\Controllers\HorseController::class, 'index']);
$router->get('/admin/horses/create', [App\Controllers\HorseController::class, 'create']);
$router->post('/admin/horses/store', [App\Controllers\HorseController::class, 'store']);
$router->get('/admin/horses/edit', [App\Controllers\HorseController::class, 'edit']); // Requires ?id=
$router->post('/admin/horses/update', [App\Controllers\HorseController::class, 'update']);
$router->post('/admin/horses/delete', [App\Controllers\HorseController::class, 'delete']);
$router->get('/admin/matches', [App\Controllers\HorseController::class, 'matches']);
$router->post('/admin/matches/link', [App\Controllers\HorseController::class, 'linkMatch']);

// Audit Log Route
$router->get('/admin/logs', [App\Controllers\AdminController::class, 'logs']);

// Admin Plugin Management Routes (#56)
$router->get('/admin/plugins', [App\Controllers\PluginController::class, 'index']);
$router->post('/admin/plugins/toggle', [App\Controllers\PluginController::class, 'toggle']);

// Admin Gruppen-/Berechtigungsverwaltung (#66)
$router->get('/admin/groups', [App\Controllers\GroupController::class, 'index']);
$router->post('/admin/groups/create', [App\Controllers\GroupController::class, 'createGroup']);
$router->post('/admin/groups/delete', [App\Controllers\GroupController::class, 'deleteGroup']);
$router->post('/admin/groups/permissions', [App\Controllers\GroupController::class, 'updatePermissions']);

// Plugin-Routen: von aktivierten Plugins über eine optionale routes()-Methode
// deklariert (siehe App\Plugin\PluginManager::registerPluginRoute()). Der
// Präfix "/plugin/<slug>/" wird dabei zwingend vom PluginManager selbst
// vorangestellt - ein Plugin kann daher nie eine der obigen Kern-Routen
// überschreiben, unabhängig davon, welchen Pfad es selbst anfordert.
foreach ($pluginManager->getPluginRoutes() as $pluginRoute) {
    if ($pluginRoute['method'] === 'POST') {
        $router->post($pluginRoute['path'], $pluginRoute['callback']);
    } else {
        $router->get($pluginRoute['path'], $pluginRoute['callback']);
    }
}

// Dispatch the request
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$router->dispatch($requestUri, $requestMethod);
