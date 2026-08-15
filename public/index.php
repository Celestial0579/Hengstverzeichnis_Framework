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

// Wartungsmodus (#232): Muss unmittelbar nach config.php stehen - also VOR
// Plugin-Boot, Router-Aufbau und jedem Datenbank-Zugriff. Der Marker
// (var/wartung.lock) wird von Werkzeugen wie dem Datenmigrations-Import
// gesetzt, während sie die Datenbank ersetzen; ein Request, der hier
// vorbeikäme, träfe auf halb aufgebaute Tabellen. Nach config.php deshalb,
// weil dort Session (für die Sprachwahl der Hinweisseite) und
// Security-Header gesetzt werden - beides ohne Datenbank. Beendet den
// Request bei aktivem Marker mit 503 + Retry-After, siehe Maintenance::guard().
\App\Service\Maintenance::guard();

use App\Router;
use App\Controllers\SetupController;
use App\Plugin\PluginManager;

// Plugin-System (#56): Scannt plugins/, lädt nur zuvor über /admin/plugins
// aktivierte Plugins. Muss vor der Routen-Registrierung laufen, damit
// Controller-Hooks (siehe BaseController::hooks()) und ggf. zusätzliche
// Plugin-Routen (siehe unten, nach den Kern-Routen) zur Verfügung stehen.
$pluginManager = PluginManager::getInstance();
$pluginManager->boot();

// Kern-Cron-Aufgaben registrieren (#67, siehe App\Service\Scheduler): jede
// Aufgabe meldet sich bei jedem Request-Bootstrap selbst an (kein dauerhaft
// laufender Prozess) und wird nur tatsächlich fällig, wenn sie zusätzlich
// über den Admin-Bereich konfiguriert/aktiviert wurde - registerScheduledTask()
// ist dafür jeweils selbst verantwortlich (No-Op ohne Konfiguration).
\App\Service\BackupService::registerScheduledTask();
\App\Service\DigestService::registerScheduledTask();

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
$router->get('/horse', [App\Controllers\PublicController::class, 'horseDetail']); // Requires ?id=
// Alte Detailseiten-Route dauerhaft (301) auf /horse umleiten (#171): gedruckte
// QR-Codes und exportierte PDFs mit /hengst?id=... bleiben so für immer gültig.
// KEIN Übergangs-Redirect - er darf nie entfernt werden.
$router->redirect('/hengst', '/horse');
$router->get('/station', [App\Controllers\PublicController::class, 'stationDetail']); // Requires ?id=

// Read-only-JSON-API für Katalogdaten (#47, siehe docs/api.md). Zugriff nur
// mit gültigem API-Schlüssel im Authorization-Header (App\Security\ApiKey).
$router->get('/api/horses', [App\Controllers\ApiController::class, 'index']);
$router->get('/api/horses/show', [App\Controllers\ApiController::class, 'show']); // Requires ?ueln=

// Selfservice-Verwaltung eigener API-Schlüssel (nur für angemeldete Benutzer)
$router->get('/api-keys', [App\Controllers\ApiKeyController::class, 'index']);
$router->post('/api-keys/create', [App\Controllers\ApiKeyController::class, 'create']);
$router->post('/api-keys/revoke', [App\Controllers\ApiKeyController::class, 'revoke']);

// Compliance Routes
$router->get('/impressum', [App\Controllers\PublicController::class, 'impressum']);
$router->get('/datenschutz', [App\Controllers\PublicController::class, 'datenschutz']);
$router->get('/dsgvo', [App\Controllers\PublicController::class, 'dsgvoForm']);
$router->post('/dsgvo', [App\Controllers\PublicController::class, 'dsgvoSubmit']);

// Authentication Routes
$router->get('/login', [App\Controllers\AuthController::class, 'loginForm']);
$router->post('/login', [App\Controllers\AuthController::class, 'loginSubmit']);
$router->post('/logout', [App\Controllers\AuthController::class, 'logout']);
// EntraID-SSO (#42, nur aktiv wenn ENTRA_* konfiguriert ist)
$router->get('/auth/entra', [App\Controllers\EntraSsoController::class, 'redirect']);
$router->get('/auth/entra/callback', [App\Controllers\EntraSsoController::class, 'callback']);

// Selfservice-Registrierung (#83, nur aktiv wenn Systemeinstellung gesetzt)
$router->get('/register', [App\Controllers\RegistrationController::class, 'showForm']);
$router->post('/register', [App\Controllers\RegistrationController::class, 'submit']);
$router->get('/verify-email', [App\Controllers\RegistrationController::class, 'verify']);

$router->get('/forgot-password', [App\Controllers\AuthController::class, 'forgotPassword']);
$router->post('/forgot-password', [App\Controllers\AuthController::class, 'sendResetLink']);
$router->get('/reset-password', [App\Controllers\AuthController::class, 'resetPassword']);
$router->post('/reset-password', [App\Controllers\AuthController::class, 'updatePassword']);
$router->get('/force-password-change', [App\Controllers\AuthController::class, 'showForcePasswordChange']);
$router->post('/force-password-change', [App\Controllers\AuthController::class, 'processForcePasswordChange']);

// 2FA Routes
$router->get('/2fa/setup', [App\Controllers\AuthController::class, 'show2faSetup']);
$router->post('/2fa/enable', [App\Controllers\AuthController::class, 'enable2fa']);
$router->post('/2fa/reauth', [App\Controllers\AuthController::class, 'process2faReauth']);
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
// Manuelle Personensuche (#266): Rueckfallweg, wenn der Automatch nichts findet.
// Admin-only ueber den Konstruktor von GdprController, liefert JSON mit Trefferdeckel.
$router->get('/admin/gdpr/search-persons', [App\Controllers\GdprController::class, 'searchPersons']);
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
$router->post('/admin/users/revoke-api-keys', [App\Controllers\UserController::class, 'revokeApiKeys']);

// Admin Person Management Routes (Persons, Breeders, Owners)
$router->get('/admin/persons', [App\Controllers\PersonController::class, 'index']);
$router->get('/admin/persons/create', [App\Controllers\PersonController::class, 'create']);
$router->post('/admin/persons/store', [App\Controllers\PersonController::class, 'store']);
$router->get('/admin/persons/edit', [App\Controllers\PersonController::class, 'edit']);
$router->post('/admin/persons/update', [App\Controllers\PersonController::class, 'update']);
$router->post('/admin/persons/delete', [App\Controllers\PersonController::class, 'delete']);
$router->post('/admin/persons/publish', [App\Controllers\PersonController::class, 'bulkPublish']);

// Admin Breeding Station Routes (Gestüte / Deckstationen)
$router->get('/admin/breeding-stations', [App\Controllers\BreedingStationController::class, 'index']);
$router->get('/admin/breeding-stations/create', [App\Controllers\BreedingStationController::class, 'create']);
$router->post('/admin/breeding-stations/store', [App\Controllers\BreedingStationController::class, 'store']);
$router->get('/admin/breeding-stations/edit', [App\Controllers\BreedingStationController::class, 'edit']);
$router->post('/admin/breeding-stations/update', [App\Controllers\BreedingStationController::class, 'update']);
$router->post('/admin/breeding-stations/delete', [App\Controllers\BreedingStationController::class, 'delete']);
$router->post('/admin/breeding-stations/publish', [App\Controllers\BreedingStationController::class, 'bulkPublish']);

// Admin Horse CRUD & Merge Tool Routes
$router->get('/admin/horses', [App\Controllers\HorseController::class, 'index']);
$router->get('/admin/horses/create', [App\Controllers\HorseController::class, 'create']);
$router->post('/admin/horses/store', [App\Controllers\HorseController::class, 'store']);
$router->get('/admin/horses/edit', [App\Controllers\HorseController::class, 'edit']); // Requires ?id=
$router->post('/admin/horses/update', [App\Controllers\HorseController::class, 'update']);
$router->post('/admin/horses/delete', [App\Controllers\HorseController::class, 'delete']);
$router->post('/admin/horses/publish', [App\Controllers\HorseController::class, 'bulkPublish']);
$router->get('/admin/matches', [App\Controllers\HorseController::class, 'matches']);
$router->post('/admin/matches/link', [App\Controllers\HorseController::class, 'linkMatch']);

// Pferde-Bulk-Import (CSV, #49)
$router->get('/admin/import/horses', [App\Controllers\ImportController::class, 'showForm']);
$router->post('/admin/import/horses/preview', [App\Controllers\ImportController::class, 'preview']);
$router->post('/admin/import/horses/commit', [App\Controllers\ImportController::class, 'commit']);

// Audit Log Route
$router->get('/admin/logs', [App\Controllers\AdminController::class, 'logs']);

// Admin Plugin Management Routes (#56)
$router->get('/admin/plugins', [App\Controllers\PluginController::class, 'index']);
$router->post('/admin/plugins/toggle', [App\Controllers\PluginController::class, 'toggle']);

// Addon-Store: Installation aus registrierten GitHub-Repos (siehe docs/plugin-system-plan.md, Phase 3)
$router->get('/admin/plugins/store', [App\Controllers\AddonStoreController::class, 'index']);
$router->post('/admin/plugins/store/add-repo', [App\Controllers\AddonStoreController::class, 'addRepo']);
$router->post('/admin/plugins/store/remove-repo', [App\Controllers\AddonStoreController::class, 'removeRepo']);
$router->post('/admin/plugins/store/install', [App\Controllers\AddonStoreController::class, 'install']);

// Admin Gruppen-/Berechtigungsverwaltung (#66)
$router->get('/admin/groups', [App\Controllers\GroupController::class, 'index']);
$router->post('/admin/groups/create', [App\Controllers\GroupController::class, 'createGroup']);
$router->post('/admin/groups/delete', [App\Controllers\GroupController::class, 'deleteGroup']);
$router->post('/admin/groups/permissions', [App\Controllers\GroupController::class, 'updatePermissions']);
$router->post('/admin/groups/require-2fa', [App\Controllers\GroupController::class, 'updateRequire2fa']);
$router->post('/admin/groups/copy-permissions', [App\Controllers\GroupController::class, 'copyPermissions']);

// Admin Cron-/Scheduler-Verwaltung (#67)
$router->get('/admin/cron', [App\Controllers\AdminController::class, 'cronSettings']);
$router->post('/admin/cron/regenerate-secret', [App\Controllers\AdminController::class, 'regenerateCronSecret']);
$router->post('/admin/cron/run-now', [App\Controllers\AdminController::class, 'runCronNow']);

// Öffentlicher, durch ein Secret geschützter Cron-Auslöse-Endpunkt (#67) - siehe
// App\Controllers\CronController und App\Service\Scheduler. Bewusst ohne
// Admin-Login erreichbar, da System-Cron keine Session mitbringen kann.
$router->get('/cron/run', [App\Controllers\CronController::class, 'run']);
$router->post('/cron/run', [App\Controllers\CronController::class, 'run']);

// Admin Backup-Verwaltung (#59)
// Automatisches Update (#85, nur manuell und mit Pflicht-Backup)
$router->get('/admin/updates', [App\Controllers\UpdateController::class, 'index']);
$router->post('/admin/updates/run', [App\Controllers\UpdateController::class, 'run']);
$router->post('/admin/updates/channel', [App\Controllers\UpdateController::class, 'saveChannel']);
$router->post('/admin/updates/addon', [App\Controllers\UpdateController::class, 'updateAddon']);

$router->get('/admin/backups', [App\Controllers\AdminController::class, 'backupSettings']);
$router->post('/admin/backups', [App\Controllers\AdminController::class, 'updateBackupSettings']);
$router->post('/admin/backups/test', [App\Controllers\AdminController::class, 'testBackup']);

// Admin E-Mail-Digest-Verwaltung (#52)
$router->get('/admin/digest', [App\Controllers\AdminController::class, 'digestSettings']);
$router->post('/admin/digest', [App\Controllers\AdminController::class, 'updateDigestSettings']);
$router->post('/admin/digest/test', [App\Controllers\AdminController::class, 'testDigest']);

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
