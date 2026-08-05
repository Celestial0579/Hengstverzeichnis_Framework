# Architektur

## Grundprinzip

Das Framework ist ein selbstgebautes, minimalistisches MVC-ähnliches System
ohne externe Abhängigkeiten (kein Composer, kein Framework wie Laravel/Symfony).
Alles läuft über einen einzigen Front-Controller (`public/index.php`), einen
schlanken Router, Controller-Klassen und PHP-Views mit einem gemeinsamen Layout.

```
Request → public/index.php → Router::dispatch() → Controller::method() → render() → Views/layout.php
```

## Verzeichnisstruktur

```
config/             Konfigurationsdatei (config.php) + optional generierte db_config.php
database/           schema.sql (Erststand), migrate.php, seed.php, reset.php
plugins/             Lokal aktivierte Plugins (siehe unten, nicht versioniert außer .gitkeep)
public/             Docroot des Webservers (Apache DocumentRoot zeigt hierher)
  index.php          Front-Controller: Autoloader, Routing-Tabelle, Dispatch
  css/, js/          Statische Assets
  uploads/           Hochgeladene Pferdebilder (persistentes Docker-Volume)
src/
  Router.php          Routing + CSRF-Token-Hilfsmethoden
  Database.php         PDO-Singleton + automatisches Schema-Update
  Controllers/         Ein Controller pro fachlichem Bereich (siehe unten)
  Views/                Ein PHP-Template pro Seite + layout.php als Rahmen
  Security/             Crypto, Totp, RateLimiter, ClientIp
  Service/               AuditLogger, Mailer
  Plugin/                 PluginManager, HookManager (Plugin-System, siehe unten)
  Helper/                 Markdown (einfacher Markdown→HTML-Parser für Freitext)
```

Es gibt bewusst **kein `src/Models`**-Verzeichnis mit ORM-Klassen: Die
Controller sprechen direkt per PDO/Prepared Statements mit der Datenbank.
Das ist ein bewusster Trade-off für Einfachheit, nicht ein unfertiger Zustand.

## Autoloading

`public/index.php` registriert einen simplen PSR-4-artigen Autoloader für den
Namespace `App\` → `src/`. Er wird **vor** `config/config.php` eingebunden,
weil `config.php` bereits `App\Security\ClientIp` benötigt (für die
HTTPS-/Proxy-Erkennung beim Setzen der Security-Header).

## Routing (`src/Router.php`)

- Sehr einfaches Array von `['method', 'path', 'callback']`-Tupeln, exakter
  String-Vergleich (kein Pattern-/Parameter-Matching wie `/horse/{id}`).
  IDs werden stattdessen über Query-Parameter übergeben (z. B. `/hengst?id=5`).
- Alle Routen werden zentral in `public/index.php` registriert.
- Kein Treffer → `PublicController::renderNotFound()` (404).
- `Router::generateCsrfToken()` / `Router::verifyCsrfToken()`: zentrale
  CSRF-Token-Erzeugung/-Prüfung (siehe [security.md](security.md)).

## Controller (`src/Controllers/`)

Alle Controller erben von `BaseController`, der folgende Querschnittsfunktionen
bereitstellt:

| Methode | Zweck |
|---|---|
| `__construct()` | Lädt globale Einstellungen (`settings`-Tabelle: Site-Name, Farben, Logo) |
| `render($view, $data)` | Rendert eine View aus `src/Views/` innerhalb von `layout.php` |
| `checkAuth()` | Session-Prüfung inkl. Anti-Session-Hijacking, Inaktivitäts-Timeout, ID-Rotation, Passwortänderungs-Zwang |
| `requireAdmin()` | Rollenprüfung (nur `role = admin`), sonst 403 |
| `renderForbidden()/renderNotFound()/renderServerError()` | Einheitliche Fehlerseiten (403/404/500), 403 wird zusätzlich im Audit-Log protokolliert |
| `isReservedUsername()` | Verhindert reservierte Benutzernamen wie `admin`, `root`, `system` |

Fachliche Controller (jeweils `__construct()` ruft i. d. R. `checkAuth()`
und ggf. `requireAdmin()` auf, außer bei öffentlichen Controllern):

| Controller | Zuständigkeit |
|---|---|
| `PublicController` | Startseite, öffentlicher Katalog, Pferdedetail inkl. Pedigree-Baum, Impressum, Datenschutz, DSGVO-Kontaktformular |
| `AuthController` | Login, Logout, Passwort vergessen/zurücksetzen, 2FA-Setup/-Verifikation, Backup-Codes, erzwungene Passwortänderung |
| `SetupController` | Ersteinrichtungs-Wizard (DB-Verbindung, Verbandsname, erster Admin-Account) |
| `AdminController` | Dashboard, Verbandseinstellungen, Systemeinstellungen, Mail-/SMTP-Einstellungen, System-Reset, Audit-Log-Ansicht |
| `HorseController` | Pferde-CRUD, Bild-Upload, automatische Blutlinien-Verknüpfung, Match-/Merge-Vorschlagswerkzeug |
| `PersonController` | CRUD für Personen (Züchter/Besitzer/Halter) |
| `BreedingStationController` | CRUD für Deckstationen/Gestüte |
| `UserController` | Admin-only Benutzerverwaltung (anlegen, bearbeiten, löschen, 2FA zurücksetzen) |
| `GdprController` | Verwaltung eingegangener DSGVO-Anfragen (Status, Anonymisierung, Löschung) |
| `TrashController` | Papierkorb: Wiederherstellen/endgültig Löschen von Soft-Deletes |

Details zu den einzelnen Features (Merge-Tool, Pedigree-Aufbau, GDPR-Workflow
etc.) siehe Inline-PHPDoc der jeweiligen Methoden – die Kommentare im Code
sind bewusst ausführlich gehalten.

## Views (`src/Views/`)

- Reine PHP-Templates (kein Templating-Engine wie Twig), `<?= htmlspecialchars(...) ?>`
  wird durchgängig zur XSS-Vermeidung genutzt.
- `layout.php` ist der gemeinsame Rahmen (Navigation, Branding aus
  `settings`-Tabelle, Footer) – wird von `BaseController::render()` immer
  um die eigentliche View-Datei herum eingebunden.
- Naming-Konvention: `admin_*` für Seiten im Admin-Bereich, `public_*` für
  öffentliche Seiten, `auth_*`/`2fa_*` für Login/2FA-Flows, `error_*` für
  Fehlerseiten.
- `Helper\Markdown::parse()` wird für Freitextfelder (z. B. Pferdebeschreibung)
  genutzt: escaped zuerst HTML komplett, parst dann eine kleine Teilmenge von
  Markdown (Headings, Bold/Italic, Links, Listen, Absätze) – kein XSS-Risiko,
  da roher HTML-Input nie durchgereicht wird.

## Datenbank-Zugriff (`src/Database.php`)

- Singleton-PDO-Verbindung, `ATTR_ERRMODE = EXCEPTION`, `EMULATE_PREPARES = false`
  (echte Prepared Statements).
- Verbindungsaufbau: TCP/Unix-Socket je nach `DB_HOST`, optionaler SSL/TLS-Modus,
  Fallback auf bekannte lokale Unix-Sockets falls TCP fehlschlägt.
- **Kein klassisches Migrationssystem** (kein `up()`/`down()` pro Version):
  `Database::ensureSchemaUpToDate()` wird bei jedem ersten Verbindungsaufbau pro
  Request ausgeführt und legt fehlende Tabellen/Spalten idempotent per
  `CREATE TABLE IF NOT EXISTS` / `ADD COLUMN` an. Das hält Deployments auf
  Shared-Hosting ohne SSH-Zugriff einfach, bedeutet aber: Schema-Änderungen im
  Code müssen **zusätzlich** in `database/schema.sql` (Ersteinrichtung) UND in
  `ensureSchemaUpToDate()` (Bestandsinstallationen) nachgezogen werden.

## Request-Flow im Detail

1. `public/index.php` registriert Autoloader, lädt `config/config.php`
   (DB-Konstanten, Security-Header, Session-Start).
2. Prüft `SetupController::needsSetup()` — falls keine DB-Config und kein
   Admin-Account existiert, Redirect auf `/setup`.
3. Router-Instanz wird mit allen Routen befüllt (siehe `public/index.php`
   für die vollständige Liste – Setup, Public, Auth, 2FA, Admin, GDPR,
   Trash, User, Person, Breeding-Station, Horse, Audit-Log).
4. `Router::dispatch()` matcht Pfad + Methode, instanziiert den Controller,
   ruft die Methode auf.
5. Controller lädt ggf. Daten aus der DB, ruft `$this->render($view, $data)`.
6. `layout.php` bindet den View-Content ein und liefert vollständiges HTML aus.

## Sicherheits-Header & Session (config/config.php)

Wird zentral beim Bootstrap gesetzt (nicht pro Controller) – siehe
[security.md](security.md) für Details zu CSP, Session-Cookie-Konfiguration
und Trusted-Proxy-Handling.

## Plugin-System (`src/Plugin/`, #56)

Erlaubt Zusatzfunktionalität, ohne Kern-Dateien zu ändern – siehe
[plugin-development.md](plugin-development.md) für die vollständige
Entwickler-Referenz (Manifest-Format, Hooks, Routen-Konvention,
Sicherheitsgrenzen) und [plugin-system-plan.md](plugin-system-plan.md) für
die zugrundeliegenden Architekturentscheidungen.

- `App\Plugin\PluginManager` (Singleton, gebootet in `public/index.php` vor
  der Routen-Registrierung): scannt `plugins/*/plugin.json`, validiert
  Manifeste, prüft Kompatibilität gegen `CORE_VERSION` und lädt nur zuvor
  über `/admin/plugins` aktivierte Plugins (Tabelle `plugins`, per
  `ensureSchemaUpToDate()`-Pattern wie der Rest des Schemas).
- `App\Plugin\HookManager`: Action-/Filter-Registry mit try/catch-Isolation
  pro Hook-Aufruf – ein fehlerhaftes Plugin bricht nie den gesamten Request
  ab. Zugriff aus Controllern über `BaseController::hooks()`.
- Definierte Erweiterungspunkte (Phase 1): `horse.before_save`/
  `horse.after_save` (`HorseController`), `horse.detail_sections`
  (`PublicController::horseDetail`), `admin.dashboard_tiles`
  (`AdminController::dashboard`).
- Zusätzliche Plugin-Routen (optionale `routes()`-Methode je Plugin) werden
  zwingend unter `/plugin/<slug>/...` registriert – der Präfix wird vom
  `PluginManager` selbst vorangestellt, ein Plugin kann daher nie eine
  Kern-Route überschreiben.
- `plugins/` ist bewusst nicht Teil des Kern-Repositories (nur
  `plugins/.gitkeep` versioniert) – Plugins werden separat gepflegt, siehe
  Referenz-/Beispielplugin unter `docs/examples/demo-plugin/`.
